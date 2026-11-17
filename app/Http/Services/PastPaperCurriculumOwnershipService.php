<?php

namespace App\Http\Services;

use App\Models\CurriculumBoard;
use App\Models\PastPaperCurriculumAuditLog;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PastPaperCurriculumOwnershipService
{
    private const SOURCE_CONFIG = [
        PastPaperCurriculumAuditLog::SOURCE_STRUCTURED => [
            'table' => 'tst_past_papers',
            'label' => 'structured past paper',
            'has_updated_by' => true,
        ],
        PastPaperCurriculumAuditLog::SOURCE_LEGACY => [
            'table' => 'past_paper_tbl',
            'label' => 'legacy past paper',
            'has_updated_by' => false,
        ],
    ];

    public function assertValidSource(string $source): array
    {
        if (!isset(self::SOURCE_CONFIG[$source])) {
            $this->abortJson(400, 'invalid_past_paper_source', 'Unsupported past paper source.');
        }

        return self::SOURCE_CONFIG[$source];
    }

    public function resolveActiveCurriculumBoard(int $curriculumBoardId): CurriculumBoard
    {
        $board = CurriculumBoard::find($curriculumBoardId);

        if (!$board) {
            throw ValidationException::withMessages([
                'curriculum_board_id' => ['Selected curriculum board does not exist.'],
            ]);
        }

        if ((int) $board->activate !== 1) {
            throw ValidationException::withMessages([
                'curriculum_board_id' => ['Selected curriculum board is inactive and cannot be assigned.'],
            ]);
        }

        return $board;
    }

    public function getSummaryCounts(string $source): array
    {
        $config = $this->assertValidSource($source);
        $table = $config['table'];

        $row = DB::table($table)
            ->selectRaw('COUNT(*) as total_all')
            ->selectRaw('SUM(CASE WHEN curriculum_board_id IS NOT NULL THEN 1 ELSE 0 END) as assigned_count')
            ->selectRaw('SUM(CASE WHEN curriculum_board_id IS NULL THEN 1 ELSE 0 END) as unresolved_count')
            ->first();

        return [
            'total_all' => (int) ($row->total_all ?? 0),
            'assigned_count' => (int) ($row->assigned_count ?? 0),
            'unresolved_count' => (int) ($row->unresolved_count ?? 0),
        ];
    }

    public function assignSingle(
        string $source,
        int $paperId,
        int $curriculumBoardId,
        string $operation,
        bool $confirmReassignment,
        int $adminUserId,
        ?int $expectedCurrentCurriculumBoardId = null,
        bool $hasExpectedCurrent = false,
        ?string $note = null,
        ?string $ipAddress = null
    ): array {
        $config = $this->assertValidSource($source);
        $targetBoard = $this->resolveActiveCurriculumBoard($curriculumBoardId);
        $cleanNote = $this->normalizeNote($note);

        return DB::transaction(function () use (
            $config,
            $source,
            $paperId,
            $targetBoard,
            $operation,
            $confirmReassignment,
            $adminUserId,
            $expectedCurrentCurriculumBoardId,
            $hasExpectedCurrent,
            $cleanNote,
            $ipAddress
        ) {
            $row = DB::table($config['table'])
                ->where('id', $paperId)
                ->lockForUpdate()
                ->first();

            if (!$row) {
                $this->abortJson(404, 'past_paper_not_found', ucfirst($config['label']) . ' record not found.');
            }

            $currentId = $row->curriculum_board_id !== null ? (int) $row->curriculum_board_id : null;

            if ($hasExpectedCurrent && $currentId !== $expectedCurrentCurriculumBoardId) {
                $this->abortJson(
                    409,
                    'ownership_concurrent_modification',
                    'Curriculum ownership changed concurrently. Reload the record and try again.'
                );
            }

            if ($operation === 'assign') {
                if ($currentId !== null) {
                    $this->abortJson(
                        409,
                        'ownership_already_assigned',
                        'This past paper already has a curriculum board assigned. Use explicit reassignment to change it.'
                    );
                }
                $auditOperation = PastPaperCurriculumAuditLog::OPERATION_INITIAL;
            } elseif ($operation === 'reassign') {
                if ($currentId === null) {
                    $this->abortJson(
                        422,
                        'ownership_not_assigned',
                        'This past paper is currently unresolved. Use initial assignment instead of reassignment.'
                    );
                }
                if (!$confirmReassignment) {
                    $this->abortJson(
                        422,
                        'reassignment_confirmation_required',
                        'Explicit confirmation is required before reassigning an existing curriculum board.'
                    );
                }
                $auditOperation = PastPaperCurriculumAuditLog::OPERATION_REASSIGN;
            } else {
                $this->abortJson(422, 'invalid_ownership_operation', 'Unsupported ownership operation.');
            }

            if ($currentId === (int) $targetBoard->id) {
                $this->abortJson(
                    422,
                    'ownership_unchanged',
                    'The selected curriculum board is already assigned to this past paper.'
                );
            }

            $updateValues = [
                'curriculum_board_id' => (int) $targetBoard->id,
                'updated_at' => now(),
            ];
            if ($config['has_updated_by']) {
                $updateValues['updated_by'] = $adminUserId;
            }

            DB::table($config['table'])
                ->where('id', $paperId)
                ->update($updateValues);

            $audit = PastPaperCurriculumAuditLog::create([
                'paper_source' => $source,
                'paper_id' => $paperId,
                'operation_type' => $auditOperation,
                'previous_curriculum_board_id' => $currentId,
                'new_curriculum_board_id' => (int) $targetBoard->id,
                'admin_user_id' => $adminUserId,
                'note' => $cleanNote,
                'ip_address' => $ipAddress ? substr($ipAddress, 0, 45) : null,
                'created_at' => now(),
            ]);

            return [
                'paper_source' => $source,
                'paper_id' => $paperId,
                'operation_type' => $auditOperation,
                'previous_curriculum_board_id' => $currentId,
                'curriculum_board_id' => (int) $targetBoard->id,
                'curriculum_board_name' => $targetBoard->name,
                'audit_log_id' => (int) $audit->id,
            ];
        });
    }

    public function bulkAssign(
        string $source,
        array $paperIds,
        int $curriculumBoardId,
        string $operation,
        bool $confirmReassignment,
        int $adminUserId,
        ?string $note = null,
        ?string $ipAddress = null
    ): array {
        $this->assertValidSource($source);
        $this->resolveActiveCurriculumBoard($curriculumBoardId);

        if ($operation === 'reassign' && !$confirmReassignment) {
            $this->abortJson(
                422,
                'reassignment_confirmation_required',
                'Explicit confirmation is required before bulk reassigning curriculum ownership.'
            );
        }

        $uniqueIds = array_values(array_unique(array_map('intval', $paperIds)));
        $succeeded = [];
        $failed = [];

        foreach ($uniqueIds as $paperId) {
            try {
                $result = $this->assignSingle(
                    source: $source,
                    paperId: $paperId,
                    curriculumBoardId: $curriculumBoardId,
                    operation: $operation,
                    confirmReassignment: $confirmReassignment,
                    adminUserId: $adminUserId,
                    note: $note,
                    ipAddress: $ipAddress
                );
                $succeeded[] = $result;
            } catch (HttpResponseException $e) {
                $payload = json_decode((string) $e->getResponse()->getContent(), true) ?: [];
                $failed[] = [
                    'paper_id' => $paperId,
                    'status' => $e->getResponse()->getStatusCode(),
                    'code' => $payload['code'] ?? 'assignment_failed',
                    'message' => $payload['message'] ?? 'Unable to update curriculum ownership.',
                ];
            } catch (\Throwable $e) {
                $failed[] = [
                    'paper_id' => $paperId,
                    'status' => 500,
                    'code' => 'assignment_failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'total_requested' => count($uniqueIds),
            'succeeded_count' => count($succeeded),
            'failed_count' => count($failed),
            'succeeded' => $succeeded,
            'failed' => $failed,
        ];
    }

    public function recordInitialCreationAudit(
        string $source,
        int $paperId,
        int $curriculumBoardId,
        int $adminUserId,
        ?string $ipAddress = null
    ): PastPaperCurriculumAuditLog {
        $this->assertValidSource($source);

        return PastPaperCurriculumAuditLog::create([
            'paper_source' => $source,
            'paper_id' => $paperId,
            'operation_type' => PastPaperCurriculumAuditLog::OPERATION_INITIAL,
            'previous_curriculum_board_id' => null,
            'new_curriculum_board_id' => $curriculumBoardId,
            'admin_user_id' => $adminUserId,
            'note' => 'Assigned on record creation.',
            'ip_address' => $ipAddress ? substr($ipAddress, 0, 45) : null,
            'created_at' => now(),
        ]);
    }

    public function getAuditLogs(string $source, int $paperId, int $limit = 50): array
    {
        $this->assertValidSource($source);

        return DB::table('past_paper_curriculum_audit_logs as logs')
            ->leftJoin('curriculum_board_tbl as prev_cb', 'prev_cb.id', '=', 'logs.previous_curriculum_board_id')
            ->leftJoin('curriculum_board_tbl as new_cb', 'new_cb.id', '=', 'logs.new_curriculum_board_id')
            ->leftJoin('users as admins', 'admins.id', '=', 'logs.admin_user_id')
            ->where('logs.paper_source', $source)
            ->where('logs.paper_id', $paperId)
            ->orderByDesc('logs.id')
            ->limit(min(max($limit, 1), 200))
            ->get([
                'logs.id',
                'logs.paper_source',
                'logs.paper_id',
                'logs.operation_type',
                'logs.previous_curriculum_board_id',
                'prev_cb.name as previous_curriculum_board_name',
                'logs.new_curriculum_board_id',
                'new_cb.name as new_curriculum_board_name',
                'logs.admin_user_id',
                'admins.name as admin_user_name',
                'logs.note',
                'logs.created_at',
            ])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'paper_source' => $row->paper_source,
                'paper_id' => (int) $row->paper_id,
                'operation_type' => $row->operation_type,
                'previous_curriculum_board_id' => $row->previous_curriculum_board_id !== null ? (int) $row->previous_curriculum_board_id : null,
                'previous_curriculum_board_name' => $row->previous_curriculum_board_name,
                'new_curriculum_board_id' => (int) $row->new_curriculum_board_id,
                'new_curriculum_board_name' => $row->new_curriculum_board_name,
                'admin_user_id' => $row->admin_user_id !== null ? (int) $row->admin_user_id : null,
                'admin_user_name' => $row->admin_user_name,
                'note' => $row->note,
                'created_at' => $row->created_at,
            ])
            ->all();
    }

    private function normalizeNote(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $trimmed = trim($note);

        return $trimmed === '' ? null : substr($trimmed, 0, 500);
    }

    private function abortJson(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json([
            'success' => 0,
            'code' => $code,
            'message' => $message,
        ], $status));
    }
}
