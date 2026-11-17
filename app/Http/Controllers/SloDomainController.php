<?php

namespace App\Http\Controllers;

use App\Models\SloDomain;
use App\Models\CurriculumBoard;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SloDomainController extends Controller
{
    /**
     * Get list of SLO Domains for Admin Panel with optional board/subject/search filters.
     */
    public function getDomainsForAdmin(Request $request)
    {
        try {
            $query = SloDomain::with([
                'curriculumBoard:id,name',
                'subject:id,subject_name',
            ])->withCount('sloTopics');

            if ($request->filled('curriculum_board_id')) {
                $query->where('curriculum_board_id', $request->curriculum_board_id);
            }

            if ($request->filled('subject_id')) {
                $query->where('subject_id', $request->subject_id);
            }

            if ($request->filled('activate')) {
                $query->where('activate', (bool) $request->activate);
            }

            if ($request->filled('search')) {
                $term = trim($request->search);
                $query->where(function ($q) use ($term) {
                    $q->where('domain_code', 'like', "%{$term}%")
                      ->orWhere('domain_name', 'like', "%{$term}%")
                      ->orWhere('standard_statement', 'like', "%{$term}%");
                });
            }

            $domains = $query->orderBy('curriculum_board_id')
                ->orderBy('subject_id')
                ->orderBy('domain_code')
                ->get()
                ->map(function ($d) {
                    return [
                        'id' => $d->id,
                        'curriculum_board_id' => $d->curriculum_board_id,
                        'board_name' => $d->curriculumBoard?->name,
                        'subject_id' => $d->subject_id,
                        'subject_name' => $d->subject?->subject_name,
                        'domain_code' => $d->domain_code,
                        'domain_name' => $d->domain_name,
                        'standard_statement' => $d->standard_statement,
                        'total_periods' => $d->total_periods,
                        'activate' => (bool) $d->activate,
                        'slo_topics_count' => $d->slo_topics_count,
                        'created_at' => $d->created_at?->toIso8601String(),
                        'updated_at' => $d->updated_at?->toIso8601String(),
                    ];
                });

            return response()->json([
                'success' => 1,
                'domains' => $domains,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fast dropdown lookup for active SLO Domains by board and subject.
     */
    public function getActiveDomains(Request $request)
    {
        try {
            $query = SloDomain::where('activate', 1);

            if ($request->filled('curriculum_board_id')) {
                $query->where('curriculum_board_id', $request->curriculum_board_id);
            }

            if ($request->filled('subject_id')) {
                $query->where('subject_id', $request->subject_id);
            }

            $domains = $query->orderBy('domain_code')
                ->select(['id', 'curriculum_board_id', 'subject_id', 'domain_code', 'domain_name', 'standard_statement'])
                ->get();

            return response()->json([
                'success' => 1,
                'domains' => $domains,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Save a new SLO Domain.
     */
    public function saveDomainForAdmin(Request $request)
    {
        $request->validate([
            'curriculum_board_id' => 'required|integer|exists:curriculum_board_tbl,id',
            'subject_id' => 'required|integer|exists:subject_tbl,id',
            'domain_code' => 'required|string|max:50',
            'domain_name' => 'required|string|max:255',
            'standard_statement' => 'nullable|string',
            'total_periods' => 'nullable|integer|min:0',
            'activate' => 'nullable|boolean',
        ]);

        try {
            $code = trim($request->domain_code);
            $duplicate = SloDomain::where('curriculum_board_id', $request->curriculum_board_id)
                ->where('subject_id', $request->subject_id)
                ->where('domain_code', $code)
                ->exists();

            if ($duplicate) {
                return response()->json([
                    'success' => 2,
                    'message' => 'Domain code already exists for this Board and Subject.',
                ]);
            }

            $userId = $request->user()?->id ?? auth('sanctum')->id();

            $domain = SloDomain::create([
                'curriculum_board_id' => $request->curriculum_board_id,
                'subject_id' => $request->subject_id,
                'domain_code' => $code,
                'domain_name' => trim($request->domain_name),
                'standard_statement' => $request->standard_statement,
                'total_periods' => $request->filled('total_periods') ? (int) $request->total_periods : null,
                'activate' => $request->boolean('activate', true),
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            return response()->json([
                'success' => 1,
                'message' => 'SLO Domain saved successfully.',
                'domain' => $domain,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update an existing SLO Domain.
     */
    public function updateDomainForAdmin(Request $request, $id)
    {
        $request->validate([
            'curriculum_board_id' => 'required|integer|exists:curriculum_board_tbl,id',
            'subject_id' => 'required|integer|exists:subject_tbl,id',
            'domain_code' => 'required|string|max:50',
            'domain_name' => 'required|string|max:255',
            'standard_statement' => 'nullable|string',
            'total_periods' => 'nullable|integer|min:0',
            'activate' => 'nullable|boolean',
        ]);

        try {
            $domain = SloDomain::findOrFail($id);

            $code = trim($request->domain_code);
            $duplicate = SloDomain::where('curriculum_board_id', $request->curriculum_board_id)
                ->where('subject_id', $request->subject_id)
                ->where('domain_code', $code)
                ->where('id', '!=', $id)
                ->exists();

            if ($duplicate) {
                return response()->json([
                    'success' => 2,
                    'message' => 'Domain code already exists for this Board and Subject.',
                ]);
            }

            $userId = $request->user()?->id ?? auth('sanctum')->id();

            $domain->curriculum_board_id = $request->curriculum_board_id;
            $domain->subject_id = $request->subject_id;
            $domain->domain_code = $code;
            $domain->domain_name = trim($request->domain_name);
            $domain->standard_statement = $request->standard_statement;
            $domain->total_periods = $request->filled('total_periods') ? (int) $request->total_periods : null;
            if ($request->has('activate')) {
                $domain->activate = $request->boolean('activate');
            }
            $domain->updated_by = $userId;
            $domain->save();

            return response()->json([
                'success' => 1,
                'message' => 'SLO Domain updated successfully.',
                'domain' => $domain,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Activate or deactivate an SLO Domain.
     */
    public function activateDomainForAdmin(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|exists:slo_domain_tbl,id',
            'activate' => 'required|boolean',
        ]);

        try {
            $domain = SloDomain::findOrFail($request->id);
            $domain->activate = $request->boolean('activate');
            $domain->updated_by = $request->user()?->id ?? auth('sanctum')->id();
            $domain->save();

            return response()->json([
                'success' => 1,
                'message' => 'SLO Domain status updated successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete an SLO Domain.
     */
    public function deleteDomainForAdmin(Request $request, $id)
    {
        try {
            $domain = SloDomain::findOrFail($id);

            if ($domain->sloTopics()->count() > 0) {
                return response()->json([
                    'success' => 0,
                    'error' => 'Cannot delete domain: it has linked SLO topics. Please delete or reassign them first.',
                ], 422);
            }

            $domain->delete();

            return response()->json([
                'success' => 1,
                'message' => 'SLO Domain deleted successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk import SLO Domains from structured JSON array.
     */
    public function bulkImportDomainsForAdmin(Request $request)
    {
        $request->validate([
            'domains' => 'required|array|min:1',
            'curriculum_board_id' => 'nullable|integer|exists:curriculum_board_tbl,id',
            'subject_id' => 'nullable|integer|exists:subject_tbl,id',
            'update_existing' => 'nullable|boolean',
        ]);

        $userId = $request->user()?->id ?? auth('sanctum')->id();
        $defaultBoardId = $request->curriculum_board_id;
        $defaultSubjectId = $request->subject_id;
        $updateExisting = $request->boolean('update_existing', true);

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($request->domains as $index => $item) {
                $idxLabel = "Domain #" . ($index + 1);

                $boardId = $item['curriculum_board_id'] ?? $defaultBoardId;
                if (!$boardId) {
                    $bName = trim($item['curriculum_board'] ?? $item['board_name'] ?? $item['name'] ?? '');
                    if ($bName) {
                        $boardId = CurriculumBoard::where('name', $bName)->value('id');
                    }
                }

                $subjectId = $item['subject_id'] ?? $defaultSubjectId;
                if (!$subjectId && !empty($item['subject_name'])) {
                    $subjectId = Subject::where('subject_name', trim($item['subject_name']))->value('id');
                }

                if (!$boardId) {
                    $errors[] = "$idxLabel: Missing or unresolvable Curriculum Board.";
                    $skipped++;
                    continue;
                }

                if (!$subjectId) {
                    $errors[] = "$idxLabel: Missing or unresolvable Subject.";
                    $skipped++;
                    continue;
                }

                $code = trim($item['domain_code'] ?? $item['code'] ?? '');
                if (!$code) {
                    $errors[] = "$idxLabel: Missing required 'domain_code'.";
                    $skipped++;
                    continue;
                }

                $name = trim($item['domain_name'] ?? $item['name'] ?? $item['title'] ?? '');
                if (!$name) {
                    $errors[] = "$idxLabel ($code): Missing required 'domain_name'.";
                    $skipped++;
                    continue;
                }

                $statement = !empty($item['standard_statement'])
                    ? trim($item['standard_statement'])
                    : (!empty($item['statement']) ? trim($item['statement']) : null);

                $totalPeriods = !empty($item['total_periods']) && is_numeric($item['total_periods'])
                    ? (int) $item['total_periods']
                    : null;

                $activate = isset($item['activate'])
                    ? filter_var($item['activate'], FILTER_VALIDATE_BOOLEAN)
                    : true;

                $existing = SloDomain::where('curriculum_board_id', $boardId)
                    ->where('subject_id', $subjectId)
                    ->where('domain_code', $code)
                    ->first();

                if ($existing) {
                    if (!$updateExisting) {
                        $skipped++;
                        continue;
                    }

                    $existing->domain_name = $name;
                    if ($statement !== null) {
                        $existing->standard_statement = $statement;
                    }
                    if ($totalPeriods !== null) {
                        $existing->total_periods = $totalPeriods;
                    }
                    $existing->activate = $activate;
                    $existing->updated_by = $userId;
                    $existing->save();

                    $updated++;
                } else {
                    SloDomain::create([
                        'curriculum_board_id' => $boardId,
                        'subject_id' => $subjectId,
                        'domain_code' => $code,
                        'domain_name' => $name,
                        'standard_statement' => $statement,
                        'total_periods' => $totalPeriods,
                        'activate' => $activate,
                        'created_by' => $userId,
                        'updated_by' => $userId,
                    ]);

                    $imported++;
                }
            }

            DB::commit();

            return response()->json([
                'success' => 1,
                'message' => "Import complete. $imported created, $updated updated, $skipped skipped.",
                'total_imported' => $imported,
                'total_updated' => $updated,
                'total_skipped' => $skipped,
                'errors' => $errors,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => 0,
                'error' => 'Failed to import SLO domains: ' . $e->getMessage(),
            ], 500);
        }
    }
}
