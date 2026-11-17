<?php

namespace App\Http\Controllers;

use App\Models\SloTopic;
use App\Models\SloDomain;
use App\Models\UserClass;
use App\Models\BookUnit;
use App\Models\BookUnitTopic;
use App\Models\Book;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SloTopicController extends Controller
{
    /**
     * Get list of SLO Topics for Admin with rich filtering and relationships.
     */
    public function getTopicsForAdmin(Request $request)
    {
        try {
            $query = SloTopic::with([
                'sloDomain' => function ($q) {
                    $q->select('id', 'curriculum_board_id', 'subject_id', 'domain_code', 'domain_name')
                      ->with([
                          'curriculumBoard:id,name',
                          'subject:id,subject_name',
                      ]);
                },
                'class:id,class_name',
                'units:id,unit_name,unit_no,book_id',
                'topics:id,topic_name,topic_no',
            ])->withCount('questions');

            if ($request->filled('curriculum_board_id')) {
                $boardId = $request->curriculum_board_id;
                $query->whereHas('sloDomain', function ($q) use ($boardId) {
                    $q->where('curriculum_board_id', $boardId);
                });
            }

            if ($request->filled('subject_id')) {
                $subjectId = $request->subject_id;
                $query->whereHas('sloDomain', function ($q) use ($subjectId) {
                    $q->where('subject_id', $subjectId);
                });
            }

            if ($request->filled('slo_domain_id')) {
                $query->where('slo_domain_id', $request->slo_domain_id);
            }

            if ($request->filled('class_id')) {
                $query->where('class_id', $request->class_id);
            }

            if ($request->filled('unit_id')) {
                $unitId = $request->unit_id;
                $query->whereHas('units', function ($q) use ($unitId) {
                    $q->where('unit_id', $unitId);
                });
            }

            if ($request->filled('assessment_type')) {
                $query->where('assessment_type', $request->assessment_type);
            }

            if ($request->filled('cognitive_level')) {
                $query->where('cognitive_level', $request->cognitive_level);
            }

            if ($request->filled('is_practical')) {
                $query->where('is_practical', $request->boolean('is_practical'));
            }

            if ($request->filled('activate')) {
                $query->where('activate', (bool) $request->activate);
            }

            if ($request->filled('search')) {
                $term = trim($request->search);
                $query->where(function ($q) use ($term) {
                    $q->where('slo_code', 'like', "%{$term}%")
                      ->orWhere('slo_title', 'like', "%{$term}%")
                      ->orWhere('slo_statement', 'like', "%{$term}%");
                });
            }

            $topics = $query->orderBy('class_id')
                ->orderBy('slo_domain_id')
                ->orderBy('slo_code')
                ->get()
                ->map(function ($t) {
                    return [
                        'id' => $t->id,
                        'slo_domain_id' => $t->slo_domain_id,
                        'domain_code' => $t->sloDomain?->domain_code,
                        'domain_name' => $t->sloDomain?->domain_name,
                        'curriculum_board_id' => $t->sloDomain?->curriculum_board_id,
                        'board_name' => $t->sloDomain?->curriculumBoard?->name,
                        'subject_id' => $t->sloDomain?->subject_id,
                        'subject_name' => $t->sloDomain?->subject?->subject_name,
                        'class_id' => $t->class_id,
                        'class_name' => $t->class?->class_name,
                        'slo_code' => $t->slo_code,
                        'slo_title' => $t->slo_title,
                        'slo_statement' => $t->slo_statement,
                        'assessment_type' => $t->assessment_type,
                        'cognitive_level' => $t->cognitive_level,
                        'is_practical' => (bool) $t->is_practical,
                        'total_periods' => $t->total_periods,
                        'remarks' => $t->remarks,
                        'activate' => (bool) $t->activate,
                        'questions_count' => $t->questions_count,
                        'units' => $t->units->map(fn ($u) => [
                            'id' => $u->id,
                            'unit_name' => $u->unit_name,
                            'unit_no' => $u->unit_no,
                            'book_id' => $u->book_id,
                        ]),
                        'unit_ids' => $t->units->pluck('id')->all(),
                        'topics' => $t->topics->map(fn ($top) => [
                            'id' => $top->id,
                            'topic_name' => $top->topic_name,
                            'topic_no' => $top->topic_no,
                        ]),
                        'topic_ids' => $t->topics->pluck('id')->all(),
                        'created_at' => $t->created_at?->toIso8601String(),
                        'updated_at' => $t->updated_at?->toIso8601String(),
                    ];
                });

            return response()->json([
                'success' => 1,
                'topics' => $topics,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fast dropdown lookup for active SLO Topics.
     */
    public function getActiveTopics(Request $request)
    {
        try {
            $query = SloTopic::where('activate', 1);

            if ($request->filled('slo_domain_id')) {
                $query->where('slo_domain_id', $request->slo_domain_id);
            }

            if ($request->filled('class_id')) {
                $query->where('class_id', $request->class_id);
            }

            if ($request->filled('unit_id')) {
                $unitId = $request->unit_id;
                $query->whereHas('units', function ($q) use ($unitId) {
                    $q->where('unit_id', $unitId);
                });
            }

            $topics = $query->orderBy('slo_code')
                ->select([
                    'id',
                    'slo_domain_id',
                    'class_id',
                    'slo_code',
                    'slo_title',
                    'slo_statement',
                    'assessment_type',
                    'cognitive_level',
                    'is_practical',
                ])
                ->get();

            return response()->json([
                'success' => 1,
                'topics' => $topics,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get active SLO Topics mapped to a specific book unit.
     */
    public function getSloTopicsByUnit($unit_id)
    {
        try {
            $topics = SloTopic::where('activate', 1)
                ->whereHas('units', function ($q) use ($unit_id) {
                    $q->where('unit_id', $unit_id);
                })
                ->orderBy('slo_code')
                ->select([
                    'id',
                    'slo_domain_id',
                    'class_id',
                    'slo_code',
                    'slo_title',
                    'slo_statement',
                    'assessment_type',
                    'cognitive_level',
                ])
                ->get();

            return response()->json([
                'success' => 1,
                'topics' => $topics,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Save a new SLO Topic and sync unit/topic relationships.
     */
    public function saveTopicForAdmin(Request $request)
    {
        $request->validate([
            'slo_domain_id' => 'required|integer|exists:slo_domain_tbl,id',
            'class_id' => 'required|integer|exists:class_tbl,id',
            'slo_code' => 'required|string|max:50',
            'slo_title' => 'nullable|string|max:255',
            'slo_statement' => 'required|string',
            'assessment_type' => 'required|string|in:summative,formative,summative_pba,formative_pba',
            'cognitive_level' => 'required|string|in:K,U,A,P',
            'is_practical' => 'nullable|boolean',
            'total_periods' => 'nullable|integer|min:0',
            'remarks' => 'nullable|string',
            'activate' => 'nullable|boolean',
            'unit_ids' => 'nullable|array',
            'unit_ids.*' => 'integer|exists:book_unit_tbl,id',
            'topic_ids' => 'nullable|array',
            'topic_ids.*' => 'integer|exists:book_unit_topic_tbl,id',
        ]);

        try {
            DB::beginTransaction();

            $code = trim($request->slo_code);
            $duplicate = SloTopic::where('slo_domain_id', $request->slo_domain_id)
                ->where('class_id', $request->class_id)
                ->where('slo_code', $code)
                ->exists();

            if ($duplicate) {
                DB::rollBack();
                return response()->json([
                    'success' => 2,
                    'message' => 'SLO Code already exists for this Domain and Class.',
                ]);
            }

            $userId = $request->user()?->id ?? auth('sanctum')->id();

            $sloTopic = SloTopic::create([
                'slo_domain_id' => $request->slo_domain_id,
                'class_id' => $request->class_id,
                'slo_code' => $code,
                'slo_title' => $request->filled('slo_title') ? trim($request->slo_title) : null,
                'slo_statement' => trim($request->slo_statement),
                'assessment_type' => $request->assessment_type,
                'cognitive_level' => $request->cognitive_level,
                'is_practical' => $request->boolean('is_practical', false),
                'total_periods' => $request->filled('total_periods') ? (int) $request->total_periods : null,
                'remarks' => $request->remarks,
                'activate' => $request->boolean('activate', true),
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            if ($request->has('unit_ids')) {
                $sloTopic->units()->sync($request->unit_ids ?? []);
            }

            if ($request->has('topic_ids')) {
                $sloTopic->topics()->sync($request->topic_ids ?? []);
            }

            DB::commit();

            return response()->json([
                'success' => 1,
                'message' => 'SLO Topic saved successfully.',
                'topic' => $sloTopic,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update an existing SLO Topic and sync unit/topic relationships.
     */
    public function updateTopicForAdmin(Request $request, $id)
    {
        $request->validate([
            'slo_domain_id' => 'required|integer|exists:slo_domain_tbl,id',
            'class_id' => 'required|integer|exists:class_tbl,id',
            'slo_code' => 'required|string|max:50',
            'slo_title' => 'nullable|string|max:255',
            'slo_statement' => 'required|string',
            'assessment_type' => 'required|string|in:summative,formative,summative_pba,formative_pba',
            'cognitive_level' => 'required|string|in:K,U,A,P',
            'is_practical' => 'nullable|boolean',
            'total_periods' => 'nullable|integer|min:0',
            'remarks' => 'nullable|string',
            'activate' => 'nullable|boolean',
            'unit_ids' => 'nullable|array',
            'unit_ids.*' => 'integer|exists:book_unit_tbl,id',
            'topic_ids' => 'nullable|array',
            'topic_ids.*' => 'integer|exists:book_unit_topic_tbl,id',
        ]);

        try {
            DB::beginTransaction();

            $sloTopic = SloTopic::findOrFail($id);

            $code = trim($request->slo_code);
            $duplicate = SloTopic::where('slo_domain_id', $request->slo_domain_id)
                ->where('class_id', $request->class_id)
                ->where('slo_code', $code)
                ->where('id', '!=', $id)
                ->exists();

            if ($duplicate) {
                DB::rollBack();
                return response()->json([
                    'success' => 2,
                    'message' => 'SLO Code already exists for this Domain and Class.',
                ]);
            }

            $userId = $request->user()?->id ?? auth('sanctum')->id();

            $sloTopic->slo_domain_id = $request->slo_domain_id;
            $sloTopic->class_id = $request->class_id;
            $sloTopic->slo_code = $code;
            $sloTopic->slo_title = $request->filled('slo_title') ? trim($request->slo_title) : null;
            $sloTopic->slo_statement = trim($request->slo_statement);
            $sloTopic->assessment_type = $request->assessment_type;
            $sloTopic->cognitive_level = $request->cognitive_level;
            $sloTopic->is_practical = $request->boolean('is_practical');
            $sloTopic->total_periods = $request->filled('total_periods') ? (int) $request->total_periods : null;
            $sloTopic->remarks = $request->remarks;
            if ($request->has('activate')) {
                $sloTopic->activate = $request->boolean('activate');
            }
            $sloTopic->updated_by = $userId;
            $sloTopic->save();

            if ($request->has('unit_ids')) {
                $sloTopic->units()->sync($request->unit_ids ?? []);
            }

            if ($request->has('topic_ids')) {
                $sloTopic->topics()->sync($request->topic_ids ?? []);
            }

            DB::commit();

            return response()->json([
                'success' => 1,
                'message' => 'SLO Topic updated successfully.',
                'topic' => $sloTopic,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Activate or deactivate an SLO Topic.
     */
    public function activateTopicForAdmin(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|exists:slo_topic_tbl,id',
            'activate' => 'required|boolean',
        ]);

        try {
            $topic = SloTopic::findOrFail($request->id);
            $topic->activate = $request->boolean('activate');
            $topic->updated_by = $request->user()?->id ?? auth('sanctum')->id();
            $topic->save();

            return response()->json([
                'success' => 1,
                'message' => 'SLO Topic status updated successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete an SLO Topic.
     */
    public function deleteTopicForAdmin(Request $request, $id)
    {
        try {
            DB::beginTransaction();

            $topic = SloTopic::findOrFail($id);

            if ($topic->questions()->count() > 0) {
                DB::rollBack();
                return response()->json([
                    'success' => 0,
                    'error' => 'Cannot delete SLO Topic: questions are linked to this SLO. Please reassign or delete linked questions first.',
                ], 422);
            }

            // Detach many-to-many pivots
            $topic->units()->detach();
            $topic->topics()->detach();
            $topic->delete();

            DB::commit();

            return response()->json([
                'success' => 1,
                'message' => 'SLO Topic deleted successfully.',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk import SLO Topics from structured JSON array.
     */
    public function bulkImportTopicsForAdmin(Request $request)
    {
        $request->validate([
            'slos' => 'required|array|min:1',
            'curriculum_board_id' => 'nullable|integer|exists:curriculum_board_tbl,id',
            'subject_id' => 'nullable|integer|exists:subject_tbl,id',
            'slo_domain_id' => 'nullable|integer|exists:slo_domain_tbl,id',
            'class_id' => 'nullable|integer|exists:class_tbl,id',
            'unit_id' => 'nullable|integer|exists:book_unit_tbl,id',
            'update_existing' => 'nullable|boolean',
        ]);

        $userId = $request->user()?->id ?? auth('sanctum')->id();
        $defaultBoardId = $request->curriculum_board_id;
        $defaultSubjectId = $request->subject_id;
        $defaultDomainId = $request->slo_domain_id;
        $defaultClassId = $request->class_id;
        $defaultUnitId = $request->unit_id;
        $updateExisting = $request->boolean('update_existing', true);

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($request->slos as $index => $item) {
                $idxLabel = "Item #" . ($index + 1);

                // --- 1. Resolve SLO Domain ---
                $domainId = $item['slo_domain_id'] ?? $defaultDomainId;
                if (!$domainId) {
                    $domainCode = trim($item['domain_code'] ?? $item['domain'] ?? '');
                    $domainName = trim($item['domain_name'] ?? '');

                    $dQuery = SloDomain::query();
                    if ($defaultBoardId) {
                        $dQuery->where('curriculum_board_id', $defaultBoardId);
                    }
                    if ($defaultSubjectId) {
                        $dQuery->where('subject_id', $defaultSubjectId);
                    }

                    if ($domainCode) {
                        $domainId = (clone $dQuery)->where('domain_code', $domainCode)->value('id')
                            ?? (clone $dQuery)->where('domain_code', 'like', "%{$domainCode}%")->value('id');
                    }

                    if (!$domainId && $domainName) {
                        $domainId = (clone $dQuery)->where('domain_name', $domainName)->value('id')
                            ?? (clone $dQuery)->where('domain_name', 'like', "%{$domainName}%")->value('id');
                    }
                }

                // --- 2. Resolve Class ---
                $classId = $item['class_id'] ?? $defaultClassId;
                if (!$classId) {
                    $cVal = trim($item['class_name'] ?? $item['class'] ?? $item['grade'] ?? '');
                    if ($cVal) {
                        $classId = UserClass::where('class_name', $cVal)
                            ->orWhere('class_name', 'like', "%{$cVal}%")
                            ->orWhere('slug', strtolower($cVal))
                            ->value('id');
                    }
                }

                if (!$domainId) {
                    $errors[] = "$idxLabel: Missing or unresolvable SLO Domain (specify 'domain_code' or select target Domain).";
                    $skipped++;
                    continue;
                }

                if (!$classId) {
                    $errors[] = "$idxLabel: Missing or unresolvable Class (specify 'class_name' or select target Class).";
                    $skipped++;
                    continue;
                }

                $code = trim($item['slo_code'] ?? '');
                if (!$code) {
                    $errors[] = "$idxLabel: Missing required 'slo_code'.";
                    $skipped++;
                    continue;
                }

                $statement = trim($item['slo_statement'] ?? $item['statement'] ?? '');
                if (!$statement) {
                    $errors[] = "$idxLabel ($code): Missing required 'slo_statement'.";
                    $skipped++;
                    continue;
                }

                // Normalize assessment type
                $rawAssess = strtolower(trim($item['assessment_type'] ?? 'summative'));
                $assessType = match ($rawAssess) {
                    'formative' => 'formative',
                    'summative_pba', 'pba', 'practical summative' => 'summative_pba',
                    'formative_pba', 'practical formative' => 'formative_pba',
                    default => 'summative',
                };

                // Normalize cognitive level
                $rawCog = strtoupper(trim($item['cognitive_level'] ?? 'U'));
                $cogLevel = match (substr($rawCog, 0, 1)) {
                    'K' => 'K',
                    'A' => 'A',
                    'P' => 'P',
                    default => 'U',
                };

                $isPractical = isset($item['is_practical'])
                    ? filter_var($item['is_practical'], FILTER_VALIDATE_BOOLEAN)
                    : ($cogLevel === 'P' || str_contains($rawAssess, 'pba'));

                $totalPeriods = !empty($item['total_periods']) && is_numeric($item['total_periods'])
                    ? (int) $item['total_periods']
                    : null;

                $sloTitle = !empty($item['slo_title'])
                    ? trim($item['slo_title'])
                    : (!empty($item['title']) ? trim($item['title']) : null);
                $remarks = !empty($item['remarks']) ? trim($item['remarks']) : null;

                // Check for existing SLO Topic
                $existing = SloTopic::where('slo_domain_id', $domainId)
                    ->where('class_id', $classId)
                    ->where('slo_code', $code)
                    ->first();

                if ($existing) {
                    if (!$updateExisting) {
                        $skipped++;
                        continue;
                    }

                    $existing->slo_title = $sloTitle ?? $existing->slo_title;
                    $existing->slo_statement = $statement;
                    $existing->assessment_type = $assessType;
                    $existing->cognitive_level = $cogLevel;
                    $existing->is_practical = $isPractical;
                    $existing->total_periods = $totalPeriods ?? $existing->total_periods;
                    if ($remarks) {
                        $existing->remarks = $remarks;
                    }
                    $existing->updated_by = $userId;
                    $existing->save();

                    $sloTopic = $existing;
                    $updated++;
                } else {
                    $sloTopic = SloTopic::create([
                        'slo_domain_id' => $domainId,
                        'class_id' => $classId,
                        'slo_code' => $code,
                        'slo_title' => $sloTitle,
                        'slo_statement' => $statement,
                        'assessment_type' => $assessType,
                        'cognitive_level' => $cogLevel,
                        'is_practical' => $isPractical,
                        'total_periods' => $totalPeriods,
                        'remarks' => $remarks,
                        'activate' => 1,
                        'created_by' => $userId,
                        'updated_by' => $userId,
                    ]);
                    $imported++;
                }

                // --- 3. Handle Unit Linking (IDs, numbers, or names) ---
                $unitIds = [];
                if (!empty($item['unit_ids']) && is_array($item['unit_ids'])) {
                    $unitIds = array_filter(array_map('intval', $item['unit_ids']));
                } elseif (!empty($item['unit_id'])) {
                    $unitIds[] = (int) $item['unit_id'];
                }

                // Collect unit numbers (e.g. unit_numbers: [1, 2] or unit_no: 1 or units: [1, 2])
                $unitNumbers = [];
                if (!empty($item['unit_numbers']) && is_array($item['unit_numbers'])) {
                    $unitNumbers = array_filter(array_map('intval', $item['unit_numbers']));
                } elseif (!empty($item['unit_numbers']) && is_numeric($item['unit_numbers'])) {
                    $unitNumbers[] = (int) $item['unit_numbers'];
                } elseif (!empty($item['units']) && is_array($item['units'])) {
                    $unitNumbers = array_filter(array_map('intval', array_filter($item['units'], 'is_numeric')));
                } elseif (isset($item['unit_no']) && is_numeric($item['unit_no'])) {
                    $unitNumbers[] = (int) $item['unit_no'];
                }

                // Collect unit names (e.g. unit_names: ["Cell Structure", "Genetics"])
                $unitNames = [];
                if (!empty($item['unit_names']) && is_array($item['unit_names'])) {
                    $unitNames = array_filter(array_map('trim', $item['unit_names']));
                } elseif (!empty($item['unit_name'])) {
                    $unitNames[] = trim($item['unit_name']);
                }

                // Resolve unit numbers or unit names to unit IDs (auto-create unit if it does not exist)
                if (!empty($unitNumbers) || !empty($unitNames)) {
                    $domainObj = SloDomain::find($domainId);
                    $targetSubjectId = $domainObj?->subject_id ?? $defaultSubjectId;
                    $targetBoardId = $domainObj?->curriculum_board_id ?? $defaultBoardId;

                    $bookQuery = Book::query();
                    if ($classId) {
                        $bookQuery->where('class_id', $classId);
                    }
                    if ($targetSubjectId) {
                        $bookQuery->where('subject_id', $targetSubjectId);
                    }
                    if ($targetBoardId) {
                        $bookQuery->where('curriculum_board_id', $targetBoardId);
                    }
                    $targetBook = $bookQuery->first();

                    // If target book does not exist yet, auto-create it
                    if (!$targetBook && $targetSubjectId && $classId) {
                        $subjName = Subject::find($targetSubjectId)?->subject_name ?? 'Subject';
                        $clsName = UserClass::find($classId)?->class_name ?? 'Class';
                        $targetBook = Book::firstOrCreate(
                            [
                                'curriculum_board_id' => $targetBoardId,
                                'class_id' => $classId,
                                'subject_id' => $targetSubjectId,
                            ],
                            [
                                'book_name' => "{$subjName} ({$clsName})",
                                'activate' => 1,
                            ]
                        );
                    }

                    if ($targetBook) {
                        $bookId = $targetBook->id;

                        // Check each unit number, auto-create unit if missing
                        if (!empty($unitNumbers)) {
                            foreach ($unitNumbers as $uIdx => $uNo) {
                                $existingU = BookUnit::where('book_id', $bookId)
                                    ->where('unit_no', $uNo)
                                    ->first();

                                if (!$existingU && isset($unitNames[$uIdx])) {
                                    $existingU = BookUnit::where('book_id', $bookId)
                                        ->where('unit_name', $unitNames[$uIdx])
                                        ->first();
                                }

                                if (!$existingU) {
                                    $uName = $unitNames[$uIdx] ?? ("Unit " . $uNo);
                                    $existingU = BookUnit::create([
                                        'book_id' => $bookId,
                                        'unit_no' => $uNo,
                                        'unit_name' => $uName,
                                        'is_alp' => 1,
                                        'activate' => 1,
                                    ]);
                                }

                                if ($existingU) {
                                    $unitIds[] = $existingU->id;
                                }
                            }
                        }

                        // Check each unit name, auto-create unit if missing
                        if (!empty($unitNames)) {
                            foreach ($unitNames as $uName) {
                                $existingU = BookUnit::where('book_id', $bookId)
                                    ->where('unit_name', $uName)
                                    ->first();

                                if (!$existingU) {
                                    $maxNo = BookUnit::where('book_id', $bookId)->max('unit_no');
                                    $nextNo = ($maxNo ? $maxNo : 0) + 1;
                                    $existingU = BookUnit::create([
                                        'book_id' => $bookId,
                                        'unit_no' => $nextNo,
                                        'unit_name' => $uName,
                                        'is_alp' => 1,
                                        'activate' => 1,
                                    ]);
                                }

                                if ($existingU) {
                                    $unitIds[] = $existingU->id;
                                }
                            }
                        }
                    }

                    $unitIds = array_values(array_unique($unitIds));
                }

                // Default unit fallback if nothing specified
                if (empty($unitIds) && $defaultUnitId) {
                    $unitIds[] = (int) $defaultUnitId;
                }

                if (!empty($unitIds)) {
                    $sloTopic->units()->syncWithoutDetaching($unitIds);
                }

                // --- 4. Handle Topic Linking (IDs or names) ---
                $topicIds = [];
                if (!empty($item['topic_ids']) && is_array($item['topic_ids'])) {
                    $topicIds = array_filter(array_map('intval', $item['topic_ids']));
                } elseif (!empty($item['topic_id'])) {
                    $topicIds[] = (int) $item['topic_id'];
                }

                if (!empty($item['topic_names']) && is_array($item['topic_names'])) {
                    $tNames = array_filter(array_map('trim', $item['topic_names']));
                    if (!empty($tNames)) {
                        $resolvedTIds = BookUnitTopic::where(function ($q) use ($tNames) {
                            foreach ($tNames as $tn) {
                                $q->orWhere('topic_name', 'like', "%{$tn}%");
                            }
                        })->pluck('id')->toArray();
                        $topicIds = array_unique(array_merge($topicIds, $resolvedTIds));
                    }
                }

                if (!empty($topicIds)) {
                    $sloTopic->topics()->syncWithoutDetaching($topicIds);
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
                'error' => 'Failed to import SLO topics: ' . $e->getMessage(),
            ], 500);
        }
    }
}
