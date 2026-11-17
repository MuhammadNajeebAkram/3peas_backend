<?php

namespace App\Http\Controllers;

use App\Http\Services\PastPaperCurriculumOwnershipService;
use App\Models\PastPaper;
use App\Models\PastPaperCurriculumAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class LegacyPastPaperController extends Controller
{
    public function __construct(
        private readonly PastPaperCurriculumOwnershipService $ownershipService = new PastPaperCurriculumOwnershipService()
    ) {}

    public function index(Request $request)
    {
        try {
            $query = PastPaper::query()
                ->leftJoin('curriculum_board_tbl as curriculum_boards', 'curriculum_boards.id', '=', 'past_paper_tbl.curriculum_board_id')
                ->leftJoin('board_tbl as boards', 'boards.id', '=', 'past_paper_tbl.board_id')
                ->leftJoin('subject_tbl as subjects', 'subjects.id', '=', 'past_paper_tbl.subject_id')
                ->leftJoin('class_tbl as classes', 'classes.id', '=', 'past_paper_tbl.class_id')
                ->leftJoin('exam_session_tbl as sessions', 'sessions.id', '=', 'past_paper_tbl.session_id')
                ->select([
                    'past_paper_tbl.id',
                    'past_paper_tbl.paper_name',
                    'past_paper_tbl.paper_slug',
                    'past_paper_tbl.paper_path',
                    'past_paper_tbl.class_id',
                    'past_paper_tbl.subject_id',
                    'past_paper_tbl.board_id',
                    'past_paper_tbl.curriculum_board_id',
                    'past_paper_tbl.year',
                    'past_paper_tbl.session_id',
                    'past_paper_tbl.group',
                    'past_paper_tbl.activate',
                    'past_paper_tbl.created_at',
                    'past_paper_tbl.updated_at',
                    'curriculum_boards.name as curriculum_board_name',
                    'boards.board_name',
                    'subjects.subject_name',
                    'classes.class_name',
                    'sessions.session_name',
                ])
                ->orderByDesc('past_paper_tbl.id');

            foreach (['curriculum_board_id', 'board_id', 'subject_id', 'class_id', 'session_id', 'year', 'group'] as $filter) {
                if ($request->filled($filter)) {
                    $query->where("past_paper_tbl.{$filter}", $request->input($filter));
                }
            }

            $ownershipStatus = $request->input('ownership_status');
            if ($ownershipStatus === 'assigned') {
                $query->whereNotNull('past_paper_tbl.curriculum_board_id');
            } elseif ($ownershipStatus === 'unresolved' || $request->boolean('unresolved_curriculum')) {
                $query->whereNull('past_paper_tbl.curriculum_board_id');
            }

            if ($request->filled('activate')) {
                $query->where('past_paper_tbl.activate', $request->boolean('activate') ? 1 : 0);
            }

            if ($request->filled('search')) {
                $search = trim((string) $request->search);
                $query->where(function ($builder) use ($search) {
                    $builder->where('past_paper_tbl.paper_name', 'like', "%{$search}%")
                        ->orWhere('past_paper_tbl.paper_slug', 'like', "%{$search}%");
                    if (ctype_digit($search)) {
                        $builder->orWhere('past_paper_tbl.id', (int) $search);
                    }
                });
            }

            $perPage = min(max((int) $request->input('per_page', 25), 1), 200);
            $pastPapers = $query->paginate($perPage);

            return response()->json([
                'success' => 1,
                'past_papers' => $pastPapers,
                'summary' => $this->ownershipService->getSummaryCounts(PastPaperCurriculumAuditLog::SOURCE_LEGACY),
            ]);
        } catch (\Throwable $e) {
            Log::error('LegacyPastPaperController::index failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve legacy past papers.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(int $id)
    {
        $paper = DB::table('past_paper_tbl as pp')
            ->leftJoin('curriculum_board_tbl as cb', 'cb.id', '=', 'pp.curriculum_board_id')
            ->leftJoin('board_tbl as eb', 'eb.id', '=', 'pp.board_id')
            ->leftJoin('subject_tbl as s', 's.id', '=', 'pp.subject_id')
            ->leftJoin('class_tbl as c', 'c.id', '=', 'pp.class_id')
            ->leftJoin('exam_session_tbl as es', 'es.id', '=', 'pp.session_id')
            ->where('pp.id', $id)
            ->select([
                'pp.*',
                'cb.name as curriculum_board_name',
                'eb.board_name',
                's.subject_name',
                'c.class_name',
                'es.session_name',
            ])
            ->first();

        if (!$paper) {
            return response()->json([
                'success' => 0,
                'code' => 'past_paper_not_found',
                'message' => 'Legacy past paper not found.',
            ], 404);
        }

        $siblingPagesCount = $paper->paper_slug
            ? (int) PastPaper::where('paper_slug', $paper->paper_slug)->count()
            : 1;

        return response()->json([
            'success' => 1,
            'past_paper' => [
                'id' => (int) $paper->id,
                'paper_name' => $paper->paper_name,
                'paper_slug' => $paper->paper_slug,
                'paper_path_filename' => $paper->paper_path ? basename((string) $paper->paper_path) : null,
                'class_id' => (int) $paper->class_id,
                'class_name' => $paper->class_name,
                'subject_id' => (int) $paper->subject_id,
                'subject_name' => $paper->subject_name,
                'board_id' => (int) $paper->board_id,
                'board_name' => $paper->board_name,
                'curriculum_board_id' => $paper->curriculum_board_id !== null ? (int) $paper->curriculum_board_id : null,
                'curriculum_board_name' => $paper->curriculum_board_name,
                'session_id' => (int) $paper->session_id,
                'session_name' => $paper->session_name,
                'group' => $paper->group !== null ? (int) $paper->group : null,
                'year' => $paper->year !== null ? (int) $paper->year : null,
                'activate' => (int) $paper->activate,
                'sibling_pages_count' => $siblingPagesCount,
                'created_at' => $paper->created_at,
                'updated_at' => $paper->updated_at,
            ],
            'audit_logs' => $this->ownershipService->getAuditLogs(PastPaperCurriculumAuditLog::SOURCE_LEGACY, $id),
        ]);
    }

    public function assignCurriculumBoard(Request $request, int $id)
    {
        $validated = $request->validate([
            'curriculum_board_id' => 'required|integer',
            'operation' => ['required', Rule::in(['assign', 'reassign'])],
            'confirm_reassignment' => 'sometimes|boolean',
            'expected_current_curriculum_board_id' => 'nullable|integer',
            'note' => 'nullable|string|max:500',
        ]);

        $adminId = (int) (Auth::guard('api')->id() ?? Auth::id() ?? 0);

        $result = $this->ownershipService->assignSingle(
            source: PastPaperCurriculumAuditLog::SOURCE_LEGACY,
            paperId: $id,
            curriculumBoardId: (int) $validated['curriculum_board_id'],
            operation: $validated['operation'],
            confirmReassignment: (bool) ($validated['confirm_reassignment'] ?? false),
            adminUserId: $adminId,
            expectedCurrentCurriculumBoardId: array_key_exists('expected_current_curriculum_board_id', $validated) && $validated['expected_current_curriculum_board_id'] !== null
                ? (int) $validated['expected_current_curriculum_board_id']
                : null,
            hasExpectedCurrent: $request->exists('expected_current_curriculum_board_id'),
            note: $validated['note'] ?? null,
            ipAddress: $request->ip()
        );

        return response()->json([
            'success' => 1,
            'message' => $result['operation_type'] === PastPaperCurriculumAuditLog::OPERATION_REASSIGN
                ? 'Legacy past paper curriculum board reassigned.'
                : 'Legacy past paper curriculum board assigned.',
            'assignment' => $result,
        ]);
    }

    public function bulkAssignCurriculumBoard(Request $request)
    {
        $validated = $request->validate([
            'paper_ids' => 'required|array|min:1|max:50',
            'paper_ids.*' => 'required|integer|min:1',
            'curriculum_board_id' => 'required|integer',
            'operation' => ['required', Rule::in(['assign', 'reassign'])],
            'confirm_reassignment' => 'sometimes|boolean',
            'note' => 'nullable|string|max:500',
        ]);

        $adminId = (int) (Auth::guard('api')->id() ?? Auth::id() ?? 0);

        $result = $this->ownershipService->bulkAssign(
            source: PastPaperCurriculumAuditLog::SOURCE_LEGACY,
            paperIds: $validated['paper_ids'],
            curriculumBoardId: (int) $validated['curriculum_board_id'],
            operation: $validated['operation'],
            confirmReassignment: (bool) ($validated['confirm_reassignment'] ?? false),
            adminUserId: $adminId,
            note: $validated['note'] ?? null,
            ipAddress: $request->ip()
        );

        return response()->json([
            'success' => 1,
            'message' => "Processed {$result['total_requested']} legacy past paper record(s): {$result['succeeded_count']} succeeded, {$result['failed_count']} failed.",
            'result' => $result,
        ]);
    }

    public function curriculumAudit(int $id)
    {
        if (!PastPaper::where('id', $id)->exists()) {
            return response()->json([
                'success' => 0,
                'code' => 'past_paper_not_found',
                'message' => 'Legacy past paper not found.',
            ], 404);
        }

        return response()->json([
            'success' => 1,
            'audit_logs' => $this->ownershipService->getAuditLogs(PastPaperCurriculumAuditLog::SOURCE_LEGACY, $id),
        ]);
    }
}
