<?php

namespace App\Http\Controllers\Tests;

use App\Http\Controllers\Controller;
use App\Http\Services\Freemium\DTOs\UnifiedAccessDecision;
use App\Http\Services\Freemium\FreemiumAccessRuleEvaluator;
use App\Http\Services\Freemium\UnifiedEntitlementResolver;
use App\Http\Services\StudentClassAccessService;
use App\Models\Book;
use App\Models\BookUnit;
use App\Models\BookUnitTopic;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\OfferedProgram;
use App\Models\UserSubscription;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Academic Hierarchy & Topic Discovery Controller (Milestone B1)
 *
 * Provides authoritative APIs for students to explore the academic hierarchy
 * (Board -> Class -> Subject -> Book -> Unit/Chapter -> Topic) and discover authorized
 * topics and their question availability for Question Explorer.
 */
class AcademicHierarchyController extends Controller
{
    public function __construct(
        protected UnifiedEntitlementResolver $entitlementResolver,
        protected FreemiumAccessRuleEvaluator $ruleEvaluator,
        protected StudentClassAccessService $paidAccessService
    ) {
    }

    /**
     * Retrieve authorized academic hierarchy elements relevant to the student's scope.
     *
     * Supports hierarchical drill-down:
     *   - No params / board_id: returns authorized boards and classes
     *   - class_id (+ board_id): returns authorized subjects
     *   - subject_id (+ class_id, board_id): returns authorized books
     *   - book_id: returns book details and units/chapters with entitlement status
     *
     * Strictly fails closed if the requested parent scope is unauthorized or mismatched.
     */
    public function getAcademicHierarchy(Request $request): JsonResponse
    {
        $student = $request->user();
        if (!$student || $student->role !== 'student' || $student->status !== 'active') {
            return response()->json([
                'success' => 0,
                'message' => 'Active student account required.',
                'code'    => 'UNAUTHENTICATED',
            ], 401);
        }

        $boardId = $request->integer('curriculum_board_id') ?: ($request->integer('board_id') ?: null);
        $classId = $request->integer('class_id') ?: null;
        $subjectId = $request->integer('subject_id') ?: null;
        $bookId = $request->integer('book_id') ?: null;
        $onlyAuthorized = $request->boolean('only_authorized', false);

        // Validate client-supplied parameters for internal cross-hierarchy consistency
        if ($bookId) {
            $book = DB::table('book_tbl')->where('id', $bookId)->where('activate', 1)->first();
            if (!$book) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Requested book does not exist or is inactive.',
                    'code'    => 'BOOK_NOT_FOUND',
                ], 404);
            }

            if ($subjectId && (int) $book->subject_id !== $subjectId) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Hierarchy mismatch: Book does not belong to requested subject.',
                    'code'    => 'HIERARCHY_MISMATCH',
                ], 422);
            }
            if ($classId && (int) $book->class_id !== $classId) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Hierarchy mismatch: Book does not belong to requested class.',
                    'code'    => 'HIERARCHY_MISMATCH',
                ], 422);
            }
            if ($boardId && (int) $book->curriculum_board_id !== $boardId) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Hierarchy mismatch: Book does not belong to requested board.',
                    'code'    => 'HIERARCHY_MISMATCH',
                ], 422);
            }

            // Verify student entitlement for this book's scope
            $bookContext = [
                'book_id'             => (int) $book->id,
                'subject_id'          => (int) $book->subject_id,
                'class_id'            => (int) $book->class_id,
                'curriculum_board_id' => (int) $book->curriculum_board_id,
            ];

            // Check if student has access to this book or at least one chapter in it
            $bookDecision = $this->entitlementResolver->resolve($student, $bookContext, 'perm_mcqs');

            // If completely denied at book level, check if any chapter under this book is authorized via freemium rule
            $hasAuthorizedChapter = false;
            $allUnits = DB::table('book_unit_tbl')
                ->where('book_id', $book->id)
                ->where('activate', 1)
                ->orderBy('unit_no', 'asc')
                ->get();

            $unitsData = [];
            foreach ($allUnits as $u) {
                $unitContext = array_merge($bookContext, [
                    'chapter_id' => (int) $u->id,
                    'unit_ids'   => [(int) $u->id],
                ]);
                $uDecision = $this->entitlementResolver->resolve($student, $unitContext, 'perm_mcqs');
                $isEntitled = $uDecision->isAllowed();
                if ($isEntitled) {
                    $hasAuthorizedChapter = true;
                }

                if (!$onlyAuthorized || $isEntitled) {
                    $unitsData[] = [
                        'id'          => (int) $u->id,
                        'unit_name'   => $u->unit_name,
                        'unit_no'     => (int) $u->unit_no,
                        'book_id'     => (int) $u->book_id,
                        'is_entitled' => $isEntitled,
                    ];
                }
            }

            if ($bookDecision->isDenied() && !$hasAuthorizedChapter) {
                return response()->json([
                    'success'       => 0,
                    'error'         => $bookDecision->getDenialReason() ?: 'Access denied to this book.',
                    'code'          => 'ACCESS_DENIED',
                    'denial_reason' => $bookDecision->getDenialReason(),
                    'upgrade_slug'  => $bookDecision->getUpgradeProgramSlug(),
                ], 403);
            }

            return response()->json([
                'success' => 1,
                'data'    => [
                    'book' => [
                        'id'                  => (int) $book->id,
                        'book_name'           => $book->book_name,
                        'class_id'            => (int) $book->class_id,
                        'curriculum_board_id' => (int) $book->curriculum_board_id,
                        'subject_id'          => (int) $book->subject_id,
                    ],
                    'units' => $unitsData,
                ],
            ], 200);
        }

        // Drill-down resolution across student's authorized scope
        $authorizedScope = $this->resolveAuthorizedScope($student);

        // Filter and return hierarchy elements
        $boardsQuery = DB::table('curriculum_board_tbl')->where('activate', 1);
        $classesQuery = DB::table('class_tbl')->where('activate', 1);
        $subjectsQuery = DB::table('subject_tbl')->where('activate', 1);
        $booksQuery = DB::table('book_tbl')->where('activate', 1);

        if (!$authorizedScope['is_global']) {
            if (!empty($authorizedScope['board_ids'])) {
                $boardsQuery->whereIn('id', $authorizedScope['board_ids']);
            } else {
                $boardsQuery->whereRaw('0 = 1');
            }

            if (!empty($authorizedScope['class_ids'])) {
                $classesQuery->whereIn('id', $authorizedScope['class_ids']);
            } else {
                $classesQuery->whereRaw('0 = 1');
            }

            if (!empty($authorizedScope['subject_ids'])) {
                $subjectsQuery->whereIn('id', $authorizedScope['subject_ids']);
            } else {
                $subjectsQuery->whereRaw('0 = 1');
            }

            if (!empty($authorizedScope['book_ids'])) {
                $booksQuery->whereIn('id', $authorizedScope['book_ids']);
            } else {
                $booksQuery->whereRaw('0 = 1');
            }
        }

        if ($boardId) {
            $booksQuery->where('curriculum_board_id', $boardId);
        }
        if ($classId) {
            $booksQuery->where('class_id', $classId);
        }
        if ($subjectId) {
            $booksQuery->where('subject_id', $subjectId);
        }

        // If specific parent filters are present, narrow subjects and classes appropriately
        if ($classId && $boardId) {
            $books = $booksQuery->get();
            $allowedSubjectIds = $books->pluck('subject_id')->unique()->all();
            $subjects = DB::table('subject_tbl')
                ->where('activate', 1)
                ->whereIn('id', $allowedSubjectIds)
                ->select('id', 'subject_name', 'icon_name')
                ->get();

            return response()->json([
                'success' => 1,
                'data'    => [
                    'curriculum_board_id' => $boardId,
                    'class_id'            => $classId,
                    'subjects'            => $subjects,
                    'books'               => $books->map(fn($b) => [
                        'id'                  => (int) $b->id,
                        'book_name'           => $b->book_name,
                        'class_id'            => (int) $b->class_id,
                        'curriculum_board_id' => (int) $b->curriculum_board_id,
                        'subject_id'          => (int) $b->subject_id,
                    ])->values(),
                ],
            ], 200);
        }

        return response()->json([
            'success' => 1,
            'data'    => [
                'boards'   => $boardsQuery->select('id', 'name')->get(),
                'classes'  => $classesQuery->select('id', 'class_name', 'slug')->get(),
                'subjects' => $subjectsQuery->select('id', 'subject_name', 'icon_name')->get(),
                'books'    => $booksQuery->select('id', 'book_name', 'class_id', 'curriculum_board_id', 'subject_id')->get(),
            ],
        ], 200);
    }

    /**
     * Retrieve authorized topics and question availability for a selected unit.
     *
     * Supports:
     *   - Complete authorized topic listing
     *   - Topic selection filtering (topic_ids[])
     *   - Full academic hierarchy validation (fail-closed on cross-unit/mismatched topics)
     *   - Safe question availability metadata (straight MCQs, scenario MCQs via Rule of Four, short/long counts)
     *   - Zero question content, answers, or explanations leakage
     */
    public function getTopics(Request $request): JsonResponse
    {
        $student = $request->user();
        if (!$student || $student->role !== 'student' || $student->status !== 'active') {
            return response()->json([
                'success' => 0,
                'message' => 'Active student account required.',
                'code'    => 'UNAUTHENTICATED',
            ], 401);
        }

        // Explicit Unit-ID Validation (Milestone B1-C1 — Fix FINDING-B1-02)
        $unitId = null;
        if ($request->has('unit_id')) {
            $rawUnitId = $request->input('unit_id');
            if (!is_numeric($rawUnitId) || (int) $rawUnitId <= 0) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Valid positive integer unit_id is required.',
                    'code'    => 'INVALID_UNIT',
                ], 422);
            }
            $unitId = (int) $rawUnitId;
        }

        if ($request->has('unit_ids')) {
            $rawUids = $request->input('unit_ids');
            if (!is_array($rawUids)) {
                if (is_numeric($rawUids)) {
                    $rawUids = [$rawUids];
                } elseif (is_string($rawUids)) {
                    $rawUids = explode(',', $rawUids);
                } else {
                    return response()->json([
                        'success' => 0,
                        'message' => 'Invalid unit_ids format supplied.',
                        'code'    => 'INVALID_UNIT',
                    ], 422);
                }
            }

            if (empty($rawUids)) {
                return response()->json([
                    'success' => 0,
                    'message' => 'The unit_ids array cannot be empty.',
                    'code'    => 'INVALID_UNIT',
                ], 422);
            }

            $sanitizedUids = [];
            foreach ($rawUids as $val) {
                if (!is_numeric($val) || (int) $val <= 0) {
                    return response()->json([
                        'success' => 0,
                        'message' => 'unit_ids must contain only valid positive integers.',
                        'code'    => 'INVALID_UNIT',
                    ], 422);
                }
                $sanitizedUids[] = (int) $val;
            }

            $uniqueUids = array_values(array_unique($sanitizedUids));

            if (count($uniqueUids) > 1) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Multiple unit IDs are not supported. Topic discovery must be requested per single unit.',
                    'code'    => 'MULTIPLE_UNITS_NOT_SUPPORTED',
                ], 422);
            }

            if ($unitId !== null && $unitId !== $uniqueUids[0]) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Conflicting unit_id and unit_ids supplied.',
                    'code'    => 'CONFLICTING_UNIT_SELECTION',
                ], 422);
            }

            $unitId = $uniqueUids[0];
        }

        if (!$unitId || $unitId <= 0) {
            return response()->json([
                'success' => 0,
                'message' => 'Valid unit_id is required.',
                'code'    => 'INVALID_UNIT',
            ], 422);
        }

        // 1. Resolve and validate unit with parent hierarchy
        $unitRow = DB::table('book_unit_tbl as units')
            ->join('book_tbl as books', 'books.id', '=', 'units.book_id')
            ->join('subject_tbl as subjects', 'subjects.id', '=', 'books.subject_id')
            ->where('units.id', $unitId)
            ->where('units.activate', 1)
            ->where('books.activate', 1)
            ->select([
                'units.id as unit_id',
                'units.unit_name',
                'units.unit_no',
                'books.id as book_id',
                'books.book_name',
                'books.class_id',
                'books.curriculum_board_id',
                'subjects.id as subject_id',
                'subjects.subject_name',
            ])
            ->first();

        if (!$unitRow) {
            return response()->json([
                'success' => 0,
                'message' => 'Unit not found or inactive.',
                'code'    => 'UNIT_NOT_FOUND',
            ], 404);
        }

        // 2. Validate client-supplied academic hierarchy parameters (fail closed on mismatch)
        if ($request->filled('book_id') && (int) $request->input('book_id') !== (int) $unitRow->book_id) {
            return response()->json([
                'success' => 0,
                'message' => 'Hierarchy mismatch: Unit does not belong to the requested book.',
                'code'    => 'HIERARCHY_MISMATCH',
            ], 422);
        }

        if ($request->filled('subject_id') && (int) $request->input('subject_id') !== (int) $unitRow->subject_id) {
            return response()->json([
                'success' => 0,
                'message' => 'Hierarchy mismatch: Unit does not belong to the requested subject.',
                'code'    => 'HIERARCHY_MISMATCH',
            ], 422);
        }

        if ($request->filled('class_id') && (int) $request->input('class_id') !== (int) $unitRow->class_id) {
            return response()->json([
                'success' => 0,
                'message' => 'Hierarchy mismatch: Unit does not belong to the requested class.',
                'code'    => 'HIERARCHY_MISMATCH',
            ], 422);
        }

        if ($request->filled('curriculum_board_id') && (int) $request->input('curriculum_board_id') !== (int) $unitRow->curriculum_board_id) {
            return response()->json([
                'success' => 0,
                'message' => 'Hierarchy mismatch: Unit does not belong to the requested curriculum board.',
                'code'    => 'HIERARCHY_MISMATCH',
            ], 422);
        }

        // 3. Load all active topics for this unit
        $allTopicsInUnit = DB::table('book_unit_topic_tbl')
            ->where('unit_id', $unitRow->unit_id)
            ->where('activate', 1)
            ->orderBy('topic_no', 'asc')
            ->get();

        $allTopicIds = $allTopicsInUnit->pluck('id')->all();

        // 4. Topic selection handling (All, Single, Multiple)
        $selectedTopics = $allTopicsInUnit;
        $selectionMode = 'all';

        if ($request->filled('topic_ids') && $request->input('topic_ids') !== 'all') {
            $rawTopicIds = $request->input('topic_ids');
            if (is_string($rawTopicIds)) {
                $rawTopicIds = explode(',', $rawTopicIds);
            }
            $requestedTopicIds = array_values(array_unique(array_filter(
                array_map('intval', (array) $rawTopicIds),
                fn($id) => $id > 0
            )));

            if (empty($requestedTopicIds)) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Invalid topic IDs supplied.',
                    'code'    => 'INVALID_TOPIC',
                ], 422);
            }

            // Strict Validation: Every submitted topic ID must belong to this unit and be active
            $mismatchedTopicIds = array_diff($requestedTopicIds, $allTopicIds);
            if (!empty($mismatchedTopicIds)) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Hierarchy mismatch: One or more requested topic IDs do not belong to the selected unit or are inactive.',
                    'code'    => 'HIERARCHY_MISMATCH',
                    'invalid_topic_ids' => array_values($mismatchedTopicIds),
                ], 422);
            }

            $selectedTopics = $allTopicsInUnit->whereIn('id', $requestedTopicIds)->values();
            $selectionMode = count($requestedTopicIds) === 1 ? 'single' : 'multiple';
        }

        // 5. Entitlement Resolution
        $scopeContext = [
            'chapter_id'          => (int) $unitRow->unit_id,
            'unit_ids'            => [(int) $unitRow->unit_id],
            'book_id'             => (int) $unitRow->book_id,
            'subject_id'          => (int) $unitRow->subject_id,
            'class_id'            => (int) $unitRow->class_id,
            'curriculum_board_id' => (int) $unitRow->curriculum_board_id,
        ];

        if ($selectionMode !== 'all') {
            $selectedIds = $selectedTopics->pluck('id')->all();
            if (count($selectedIds) > 1) {
                $scopeContext['topic_ids'] = $selectedIds;
            } elseif (count($selectedIds) === 1) {
                $scopeContext['topic_id'] = $selectedIds[0];
            }
        }

        $mcqDecision = $this->entitlementResolver->resolve($student, $scopeContext, 'perm_mcqs');
        $shortDecision = $this->entitlementResolver->resolve($student, $scopeContext, 'perm_short_questions');
        $longDecision = $this->entitlementResolver->resolve($student, $scopeContext, 'perm_long_questions');
        $exerciseDecision = $this->entitlementResolver->resolve($student, $scopeContext, 'perm_exercise_questions');
        $supplementaryDecision = $this->entitlementResolver->resolve($student, $scopeContext, 'perm_supplementary_questions');

        // Fail closed if student is denied all question formats in the requested scope
        if ($mcqDecision->isDenied() && $shortDecision->isDenied() && $longDecision->isDenied()) {
            return response()->json([
                'success'       => 0,
                'error'         => $mcqDecision->getDenialReason() ?: 'An active subscription is required to access this resource.',
                'code'          => 'ACCESS_DENIED',
                'denial_reason' => $mcqDecision->getDenialReason(),
                'diagnostic'    => $mcqDecision->getDiagnosticMessage(),
                'upgrade_slug'  => $mcqDecision->getUpgradeProgramSlug(),
            ], 403);
        }

        $isPaid = $mcqDecision->isPaid() || $shortDecision->isPaid() || $longDecision->isPaid();
        $denyExercise = !$isPaid
            && $exerciseDecision->isDenied()
            && $exerciseDecision->getOrigin() === UnifiedAccessDecision::ORIGIN_FREEMIUM_RULE;
        $denySupplementary = !$isPaid
            && $supplementaryDecision->isDenied()
            && $supplementaryDecision->getOrigin() === UnifiedAccessDecision::ORIGIN_FREEMIUM_RULE;

        if ($denyExercise && $denySupplementary) {
            return response()->json([
                'success'       => 0,
                'error'         => 'Access denied: Both exercise and supplementary questions are restricted under your current subscription or campaign.',
                'code'          => 'ACCESS_DENIED',
                'denial_reason' => 'Both exercise and supplementary questions are denied.',
                'diagnostic'    => 'perm_exercise_questions and perm_supplementary_questions are both denied.',
                'upgrade_slug'  => $exerciseDecision->getUpgradeProgramSlug() ?? $mcqDecision->getUpgradeProgramSlug(),
            ], 403);
        }

        $allowExercise = $isPaid || !$denyExercise;
        $allowSupplementary = $isPaid || !$denySupplementary;

        // 6. Calculate Question Availability Counts (Grouped Aggregate Queries — No N+1)
        $targetTopicIds = $selectedTopics->pluck('id')->all();

        // 6a. Straight MCQs, Short Questions, Long Questions
        // Dynamic Question-Type Mapping (Milestone B1-C1 — Fix FINDING-B1-01)
        $explicitTypes = config('freemium.question_type_permissions.explicit_types', []);
        $activeQuestionTypes = DB::table('question_type_tbl')
            ->where('activate', 1)
            ->get();

        $shortTypeIds = [];
        $longTypeIds = [];

        foreach ($activeQuestionTypes as $qt) {
            if ((bool) ($qt->is_mcq ?? false)) {
                continue;
            }
            $normName = strtolower(trim((string) ($qt->type_name ?? '')));
            $mapping = $explicitTypes[$normName] ?? null;
            if (is_array($mapping) && !empty($mapping['dimension'])) {
                if ($mapping['dimension'] === 'perm_short_questions') {
                    $shortTypeIds[] = (int) $qt->id;
                } elseif ($mapping['dimension'] === 'perm_long_questions') {
                    $longTypeIds[] = (int) $qt->id;
                }
            }
        }

        $shortIdClause = !empty($shortTypeIds)
            ? 'q.question_type IN (' . implode(',', $shortTypeIds) . ')'
            : '1 = 0';

        $longIdClause = !empty($longTypeIds)
            ? 'q.question_type IN (' . implode(',', $longTypeIds) . ')'
            : '1 = 0';

        $countsQuery = DB::table('exam_question_tbl as q')
            ->whereIn('q.topic_id', $targetTopicIds)
            ->where('q.activate', 1);

        if (!$allowExercise && $allowSupplementary) {
            $countsQuery->where(function ($q) {
                $q->where('q.exercise_question', 0)->orWhereNull('q.exercise_question');
            });
        } elseif ($allowExercise && !$allowSupplementary) {
            $countsQuery->where('q.exercise_question', 1);
        }

        $formatCounts = $countsQuery
            ->select([
                'q.topic_id',
                DB::raw("SUM(CASE WHEN (q.is_mcq = 1 OR q.question_type = 1) AND q.scenario_group_id IS NULL THEN 1 ELSE 0 END) as straight_mcq_count"),
                DB::raw("SUM(CASE WHEN {$shortIdClause} THEN 1 ELSE 0 END) as short_question_count"),
                DB::raw("SUM(CASE WHEN {$longIdClause} THEN 1 ELSE 0 END) as long_question_count"),
            ])
            ->groupBy('q.topic_id')
            ->get()
            ->keyBy('topic_id');

        // 6b. Scenario MCQs & Qualifying Scenario Groups (Rule of Four strictly applied)
        $scenarioQuery = DB::table('exam_question_tbl as q')
            ->join('question_scenario_groups_tbl as sg', 'sg.id', '=', 'q.scenario_group_id')
            ->whereIn('q.topic_id', $targetTopicIds)
            ->where('q.activate', 1)
            ->where('sg.activate', 1)
            ->where('q.is_mcq', 1)
            ->whereNotNull('q.scenario_group_id');

        if (!$allowExercise && $allowSupplementary) {
            $scenarioQuery->where(function ($q) {
                $q->where('q.exercise_question', 0)->orWhereNull('q.exercise_question');
            });
        } elseif ($allowExercise && !$allowSupplementary) {
            $scenarioQuery->where('q.exercise_question', 1);
        }

        $scenarioQuestions = $scenarioQuery
            ->select([
                'q.id',
                'q.topic_id',
                'q.scenario_group_id',
            ])
            ->get();

        $qualifyingScenarioByTopic = [];
        foreach ($scenarioQuestions->groupBy('topic_id') as $tId => $tQuestions) {
            $groups = $tQuestions->groupBy('scenario_group_id');
            $qualifyingGroups = 0;
            $eligibleScenarioQuestions = 0;
            foreach ($groups as $sgId => $members) {
                // Rule of Four: Only groups with at least 4 eligible questions qualify
                if ($members->count() >= 4) {
                    $qualifyingGroups++;
                    $eligibleScenarioQuestions += $members->count();
                }
            }
            $qualifyingScenarioByTopic[$tId] = [
                'eligible_groups_count' => $qualifyingGroups,
                'scenario_mcq_count'    => $eligibleScenarioQuestions,
            ];
        }

        // 7. Format Topic Output (Never leak restricted counts or question content)
        $topicsData = [];
        foreach ($selectedTopics as $top) {
            $tId = (int) $top->id;
            $fmt = $formatCounts->get($tId);
            $scen = $qualifyingScenarioByTopic[$tId] ?? ['eligible_groups_count' => 0, 'scenario_mcq_count' => 0];

            $straightCount = (int) ($fmt->straight_mcq_count ?? 0);
            $scenarioCount = (int) $scen['scenario_mcq_count'];
            $scenarioGroupsCount = (int) $scen['eligible_groups_count'];
            $shortCount = (int) ($fmt->short_question_count ?? 0);
            $longCount = (int) ($fmt->long_question_count ?? 0);

            $availability = [
                'total_eligible_mcqs'      => $mcqDecision->isAllowed() ? ($straightCount + $scenarioCount) : null,
                'straight_mcqs'            => $mcqDecision->isAllowed() ? $straightCount : null,
                'scenario_mcqs'            => $mcqDecision->isAllowed() ? $scenarioCount : null,
                'eligible_scenario_groups' => $mcqDecision->isAllowed() ? $scenarioGroupsCount : null,
                'short_questions'          => $shortDecision->isAllowed() ? $shortCount : null,
                'long_questions'           => $longDecision->isAllowed() ? $longCount : null,
            ];

            $topicsData[] = [
                'id'           => $tId,
                'topic_name'   => $top->topic_name,
                'topic_no'     => (int) ($top->topic_no ?? 0),
                'is_active'    => (bool) ($top->activate ?? false),
                'availability' => $availability,
                'permissions'  => [
                    'perm_mcqs'            => $mcqDecision->isAllowed(),
                    'perm_short_questions' => $shortDecision->isAllowed(),
                    'perm_long_questions'  => $longDecision->isAllowed(),
                ],
            ];
        }

        return response()->json([
            'success' => 1,
            'data'    => [
                'unit' => [
                    'id'                  => (int) $unitRow->unit_id,
                    'unit_name'           => $unitRow->unit_name,
                    'unit_no'             => (int) $unitRow->unit_no,
                    'book_id'             => (int) $unitRow->book_id,
                    'book_name'           => $unitRow->book_name,
                    'subject_id'          => (int) $unitRow->subject_id,
                    'subject_name'        => $unitRow->subject_name,
                    'class_id'            => (int) $unitRow->class_id,
                    'curriculum_board_id' => (int) $unitRow->curriculum_board_id,
                ],
                'topics' => $topicsData,
                'selection' => [
                    'mode'                  => $selectionMode,
                    'total_topics_in_unit'  => count($allTopicIds),
                    'selected_topics_count' => count($topicsData),
                ],
                'permissions' => [
                    'perm_mcqs'                    => $mcqDecision->isAllowed(),
                    'perm_short_questions'         => $shortDecision->isAllowed(),
                    'perm_long_questions'          => $longDecision->isAllowed(),
                    'perm_exercise_questions'      => $allowExercise,
                    'perm_supplementary_questions' => $allowSupplementary,
                ],
            ],
        ], 200);
    }

    /**
     * Resolves the student's authorized scope across subscriptions, campaigns, and legacy rules.
     */
    protected function resolveAuthorizedScope(WebUser $student): array
    {
        $now = Carbon::now('UTC');
        $boardIds = [];
        $classIds = [];
        $subjectIds = [];
        $bookIds = [];
        $chapterIds = [];
        $isGlobal = false;

        // 1. Paid subscriptions
        $activeProgramIds = $this->paidAccessService->getActiveProgramIdsForStudent((int) $student->id);
        if (!empty($activeProgramIds)) {
            $programs = OfferedProgram::with(['offeredClass', 'programSubjects'])
                ->whereIn('id', $activeProgramIds)
                ->where('is_active', true)
                ->get();

            foreach ($programs as $prog) {
                if ($prog->offeredClass) {
                    $boardIds[] = (int) $prog->offeredClass->curriculum_board_id;
                    $classIds[] = (int) $prog->offeredClass->class_id;
                }
                foreach ($prog->programSubjects as $ps) {
                    if ($ps->is_active) {
                        $subjectIds[] = (int) $ps->subject_id;
                    }
                }
            }
        }

        // 2. Freemium campaigns & rules
        $participating = $this->ruleEvaluator->resolveParticipatingCampaigns($student, $now);
        $campaigns = $participating['participating'] ?? [];

        foreach ($campaigns as $camp) {
            $rules = DB::table('freemium_access_rules')
                ->where('campaign_id', $camp->id)
                ->where('target_status', 'active')
                ->where('perm_mcqs', 'allow')
                ->get();

            foreach ($rules as $r) {
                if ($r->target_scope === 'global') {
                    $isGlobal = true;
                }
                if ($r->curriculum_board_id) {
                    $boardIds[] = (int) $r->curriculum_board_id;
                }
                if ($r->class_id) {
                    $classIds[] = (int) $r->class_id;
                }
                if ($r->subject_id) {
                    $subjectIds[] = (int) $r->subject_id;
                }
                if ($r->book_id) {
                    $bookIds[] = (int) $r->book_id;
                }
                if ($r->chapter_id) {
                    $chapterIds[] = (int) $r->chapter_id;
                }
            }
        }

        // 3. Resolve candidate books if not explicitly global
        if (!$isGlobal) {
            $bookQuery = DB::table('book_tbl')->where('activate', 1);
            $hasCondition = false;
            if (!empty($bookIds)) {
                $bookQuery->whereIn('id', array_unique($bookIds));
                $hasCondition = true;
            }
            if (!empty($boardIds) && !empty($classIds) && !empty($subjectIds)) {
                $bookQuery->orWhere(function ($q) use ($boardIds, $classIds, $subjectIds) {
                    $q->whereIn('curriculum_board_id', array_unique($boardIds))
                        ->whereIn('class_id', array_unique($classIds))
                        ->whereIn('subject_id', array_unique($subjectIds));
                });
                $hasCondition = true;
            }
            if ($hasCondition) {
                $discoveredBooks = $bookQuery->get();
                foreach ($discoveredBooks as $db) {
                    $bookIds[] = (int) $db->id;
                    $boardIds[] = (int) $db->curriculum_board_id;
                    $classIds[] = (int) $db->class_id;
                    $subjectIds[] = (int) $db->subject_id;
                }
            }
        }

        return [
            'is_global'   => $isGlobal,
            'board_ids'   => array_values(array_unique(array_filter($boardIds))),
            'class_ids'   => array_values(array_unique(array_filter($classIds))),
            'subject_ids' => array_values(array_unique(array_filter($subjectIds))),
            'book_ids'    => array_values(array_unique(array_filter($bookIds))),
            'chapter_ids' => array_values(array_unique(array_filter($chapterIds))),
        ];
    }
}
