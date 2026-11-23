<?php

namespace App\Http\Controllers\Tests;

use App\Http\Controllers\Controller;
use App\Http\Services\Freemium\DTOs\UnifiedAccessDecision;
use App\Http\Services\Freemium\FreemiumAccessRuleEvaluator;
use App\Http\Services\Freemium\UnifiedEntitlementResolver;
use App\Http\Services\StudentClassAccessService;
use App\Models\ExamQuestion;
use App\Models\QuestionPresentationType;
use App\Models\QuestionScenarioGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Question Explorer API Controller (Milestone B2)
 *
 * Provides authoritative, paginated question discovery for the Student LMS Question Explorer.
 *
 * Enforces:
 *   - JWT-authenticated active student access
 *   - Strict single-unit validation (fail closed on multi-unit, conflicting, or invalid input)
 *   - Academic hierarchy and topic scoping (fail closed on cross-unit or mismatched topics)
 *   - Dynamic question-type discovery and entitlement verification (A-C1 explicit mapping)
 *   - Dynamic MCQ presentation-type filtering
 *   - Atomic Scenario Grouping under the mandatory Rule of Four
 *   - Exercise and supplementary independent permission enforcement
 *   - Strict answer, explanation, and topic content protection
 *   - Group-aware pagination preventing scenario splits across page boundaries
 *   - Zero N+1 query execution
 */
class QuestionExplorerController extends Controller
{
    public function __construct(
        protected UnifiedEntitlementResolver $entitlementResolver,
        protected FreemiumAccessRuleEvaluator $ruleEvaluator,
        protected StudentClassAccessService $paidAccessService
    ) {
    }

    /**
     * Browse authorized questions within selected academic topics.
     *
     * Route: GET /web_api/auth/tests/questions
     * Alias: GET /web_api/auth/question-explorer/questions
     */
    public function getQuestions(Request $request): JsonResponse
    {
        $student = $request->user();
        if (!$student || $student->role !== 'student' || $student->status !== 'active') {
            return response()->json([
                'success' => 0,
                'message' => 'Active student account required.',
                'code'    => 'UNAUTHENTICATED',
            ], 401);
        }

        // 1. Strict Unit-ID Validation (reusing Milestone B1-C1 invariants)
        $unitId = null;
        if ($request->has('unit_id')) {
            $parsedUnitId = $this->validateStrictPositiveInt($request->input('unit_id'));
            if ($parsedUnitId === null) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Valid positive integer unit_id is required.',
                    'code'    => 'INVALID_UNIT',
                ], 422);
            }
            $unitId = $parsedUnitId;
        }

        if ($request->has('unit_ids')) {
            $rawUids = $request->input('unit_ids');
            if (!is_array($rawUids)) {
                if (is_string($rawUids)) {
                    $rawUids = explode(',', $rawUids);
                } elseif (is_int($rawUids)) {
                    $rawUids = [$rawUids];
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
                $parsedVal = $this->validateStrictPositiveInt($val);
                if ($parsedVal === null) {
                    return response()->json([
                        'success' => 0,
                        'message' => 'unit_ids must contain only valid positive integers.',
                        'code'    => 'INVALID_UNIT',
                    ], 422);
                }
                $sanitizedUids[] = $parsedVal;
            }

            $uniqueUids = array_values(array_unique($sanitizedUids));

            if (count($uniqueUids) > 1) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Multiple unit IDs are not supported. Question Explorer must be requested per single unit.',
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

        // 2. Resolve Unit and Parent Hierarchy
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

        // Validate client-supplied parent hierarchy parameters (fail closed on mismatch)
        if ($request->has('book_id')) {
            $parsedBookId = $this->validateStrictPositiveInt($request->input('book_id'));
            if ($parsedBookId === null || $parsedBookId !== (int) $unitRow->book_id) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Hierarchy mismatch: Unit does not belong to the requested book.',
                    'code'    => 'HIERARCHY_MISMATCH',
                ], 422);
            }
        }
        if ($request->has('subject_id')) {
            $parsedSubjectId = $this->validateStrictPositiveInt($request->input('subject_id'));
            if ($parsedSubjectId === null || $parsedSubjectId !== (int) $unitRow->subject_id) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Hierarchy mismatch: Unit does not belong to the requested subject.',
                    'code'    => 'HIERARCHY_MISMATCH',
                ], 422);
            }
        }
        if ($request->has('class_id')) {
            $parsedClassId = $this->validateStrictPositiveInt($request->input('class_id'));
            if ($parsedClassId === null || $parsedClassId !== (int) $unitRow->class_id) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Hierarchy mismatch: Unit does not belong to the requested class.',
                    'code'    => 'HIERARCHY_MISMATCH',
                ], 422);
            }
        }
        if ($request->has('curriculum_board_id')) {
            $parsedBoardId = $this->validateStrictPositiveInt($request->input('curriculum_board_id'));
            if ($parsedBoardId === null || $parsedBoardId !== (int) $unitRow->curriculum_board_id) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Hierarchy mismatch: Unit does not belong to the requested curriculum board.',
                    'code'    => 'HIERARCHY_MISMATCH',
                ], 422);
            }
        }

        // 3. Load Active Topics for Unit
        $allTopicsInUnit = DB::table('book_unit_topic_tbl')
            ->where('unit_id', $unitRow->unit_id)
            ->where('activate', 1)
            ->orderBy('topic_no', 'asc')
            ->get();

        $allTopicIds = $allTopicsInUnit->pluck('id')->all();

        // Topic selection handling (All, Single, Multiple)
        $selectedTopics = $allTopicsInUnit;
        $selectionMode = 'all';

        if ($request->filled('topic_ids') && $request->input('topic_ids') !== 'all') {
            $rawTopicIds = $request->input('topic_ids');
            if (is_string($rawTopicIds)) {
                $rawTopicIds = explode(',', $rawTopicIds);
            } elseif (!is_array($rawTopicIds)) {
                $rawTopicIds = [$rawTopicIds];
            }

            if (empty($rawTopicIds)) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Invalid topic IDs supplied.',
                    'code'    => 'INVALID_TOPIC',
                ], 422);
            }

            $requestedTopicIds = [];
            foreach ($rawTopicIds as $tVal) {
                $parsedTid = $this->validateStrictPositiveInt($tVal);
                if ($parsedTid === null) {
                    return response()->json([
                        'success' => 0,
                        'message' => 'topic_ids must contain only valid positive integers.',
                        'code'    => 'INVALID_TOPIC',
                    ], 422);
                }
                $requestedTopicIds[] = $parsedTid;
            }

            $requestedTopicIds = array_values(array_unique($requestedTopicIds));

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

        // 4. Authoritative Entitlement Resolution
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

        $mcqDecision           = $this->entitlementResolver->resolve($student, $scopeContext, 'perm_mcqs');
        $shortDecision         = $this->entitlementResolver->resolve($student, $scopeContext, 'perm_short_questions');
        $longDecision          = $this->entitlementResolver->resolve($student, $scopeContext, 'perm_long_questions');
        $exerciseDecision      = $this->entitlementResolver->resolve($student, $scopeContext, 'perm_exercise_questions');
        $supplementaryDecision = $this->entitlementResolver->resolve($student, $scopeContext, 'perm_supplementary_questions');
        $answersDecision       = $this->entitlementResolver->resolve($student, $scopeContext, 'perm_answers');
        $explanationsDecision  = $this->entitlementResolver->resolve($student, $scopeContext, 'perm_explanations');
        $topicContentsDecision = $this->entitlementResolver->resolve($student, $scopeContext, 'perm_topic_contents');

        // Fail closed if student is denied all question formats in the requested scope
        if ($mcqDecision->isDenied() && $shortDecision->isDenied() && $longDecision->isDenied()) {
            return response()->json([
                'success'       => 0,
                'error'         => $mcqDecision->getDenialReason() ?: 'An active subscription is required to access questions.',
                'code'          => 'ACCESS_DENIED',
                'denial_reason' => $mcqDecision->getDenialReason(),
                'diagnostic'    => $mcqDecision->getDiagnosticMessage(),
                'upgrade_slug'  => $mcqDecision->getUpgradeProgramSlug(),
            ], 403);
        }

        // Fail closed if both exercise and supplementary questions are restricted
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

        // 5. Dynamic Question-Type Mapping (A-C1 Explicit Whitelist Mapping)
        $explicitTypes = config('freemium.question_type_permissions.explicit_types', []);
        $activeQuestionTypes = DB::table('question_type_tbl')
            ->where('activate', 1)
            ->get();

        $mappedTypes = []; // Keyed by question_type ID
        foreach ($activeQuestionTypes as $qt) {
            $isMcq = (bool) ($qt->is_mcq ?? false);
            if ($isMcq) {
                $mappedTypes[(int) $qt->id] = [
                    'id'          => (int) $qt->id,
                    'type_name'   => $qt->type_name,
                    'code'        => 'mcq',
                    'dimension'   => 'perm_mcqs',
                    'is_mcq'      => true,
                    'is_entitled' => $mcqDecision->isAllowed(),
                ];
                continue;
            }

            $normName = strtolower(trim((string) ($qt->type_name ?? '')));
            $mapping = $explicitTypes[$normName] ?? null;
            if (is_array($mapping) && !empty($mapping['dimension'])) {
                $dim = $mapping['dimension'];
                $isEntitled = match ($dim) {
                    'perm_short_questions' => $shortDecision->isAllowed(),
                    'perm_long_questions'  => $longDecision->isAllowed(),
                    default                => false,
                };

                $mappedTypes[(int) $qt->id] = [
                    'id'          => (int) $qt->id,
                    'type_name'   => $qt->type_name,
                    'code'        => $mapping['code'] ?? 'other',
                    'dimension'   => $dim,
                    'is_mcq'      => false,
                    'is_entitled' => $isEntitled,
                ];
            }
        }

        // Validate client question_type_ids filter if present
        $selectedTypeIds = [];
        if ($request->filled('question_type_ids')) {
            $rawTypeIds = $request->input('question_type_ids');
            if (is_string($rawTypeIds)) {
                $rawTypeIds = explode(',', $rawTypeIds);
            }
            $requestedTypeIds = array_values(array_unique(array_filter(
                array_map('intval', (array) $rawTypeIds),
                fn($id) => $id > 0
            )));

            if (empty($requestedTypeIds)) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Invalid question_type_ids supplied.',
                    'code'    => 'INVALID_QUESTION_TYPE',
                ], 422);
            }

            foreach ($requestedTypeIds as $reqTypeId) {
                if (!isset($mappedTypes[$reqTypeId])) {
                    return response()->json([
                        'success' => 0,
                        'message' => "Question type ID {$reqTypeId} is inactive, unknown, or unmapped.",
                        'code'    => 'INVALID_QUESTION_TYPE',
                    ], 422);
                }

                if (!$mappedTypes[$reqTypeId]['is_entitled']) {
                    return response()->json([
                        'success'       => 0,
                        'error'         => "Access to question type '{$mappedTypes[$reqTypeId]['type_name']}' is restricted under your current subscription or campaign.",
                        'code'          => 'ACCESS_DENIED',
                        'denial_reason' => "Permission denied for {$mappedTypes[$reqTypeId]['dimension']}",
                    ], 403);
                }
            }
            $selectedTypeIds = $requestedTypeIds;
        } else {
            // Default to all active mapped types that the student is authorized to access
            foreach ($mappedTypes as $tId => $tData) {
                if ($tData['is_entitled']) {
                    $selectedTypeIds[] = $tId;
                }
            }

            if (empty($selectedTypeIds)) {
                return response()->json([
                    'success' => 0,
                    'message' => 'No authorized question types available in this scope.',
                    'code'    => 'ACCESS_DENIED',
                ], 403);
            }
        }

        // 6. Dynamic MCQ Presentation-Type Handling
        $activePresTypes = DB::table('question_presentation_type_tbl')
            ->where('activate', 1)
            ->orderBy('sort_order', 'asc')
            ->get();

        $straightPresTypeIds = [];
        $scenarioPresTypeIds = [];
        foreach ($activePresTypes as $pt) {
            $allowsMulti = (bool) ($pt->allows_multiple_mcqs ?? false);
            $isStraight = (!$allowsMulti || strtolower($pt->code ?? '') === 'st' || strtolower($pt->type_name) === 'straight');
            if ($isStraight) {
                $straightPresTypeIds[] = (int) $pt->id;
            } else {
                $scenarioPresTypeIds[] = (int) $pt->id;
            }
        }

        $allowsMcqs = false;
        foreach ($selectedTypeIds as $tId) {
            if ($mappedTypes[$tId]['is_mcq']) {
                $allowsMcqs = true;
                break;
            }
        }

        $includeStraightMcqs = $allowsMcqs;
        $includeScenarioMcqs = $allowsMcqs;
        $selectedPresTypeIds = [];

        if ($request->filled('presentation_type_ids')) {
            $rawPresIds = $request->input('presentation_type_ids');
            if (is_string($rawPresIds)) {
                $rawPresIds = explode(',', $rawPresIds);
            }
            $requestedPresIds = array_values(array_unique(array_filter(
                array_map('intval', (array) $rawPresIds),
                fn($id) => $id > 0
            )));

            if (empty($requestedPresIds)) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Invalid presentation_type_ids supplied.',
                    'code'    => 'INVALID_PRESENTATION_TYPE',
                ], 422);
            }

            $allActivePresIds = $activePresTypes->pluck('id')->map('intval')->all();
            $invalidPresIds = array_diff($requestedPresIds, $allActivePresIds);
            if (!empty($invalidPresIds)) {
                return response()->json([
                    'success' => 0,
                    'message' => 'One or more requested presentation type IDs are invalid or inactive.',
                    'code'    => 'INVALID_PRESENTATION_TYPE',
                ], 422);
            }

            if (!$mcqDecision->isAllowed()) {
                return response()->json([
                    'success'       => 0,
                    'error'         => 'Access to MCQs and MCQ presentation types is restricted under your current subscription or campaign.',
                    'code'          => 'ACCESS_DENIED',
                    'denial_reason' => $mcqDecision->getDenialReason(),
                ], 403);
            }

            $selectedPresTypeIds = $requestedPresIds;
            $hasStraightPres = !empty(array_intersect($requestedPresIds, $straightPresTypeIds));
            $hasScenarioPres = !empty(array_intersect($requestedPresIds, $scenarioPresTypeIds));

            $includeStraightMcqs = $allowsMcqs && $hasStraightPres;
            $includeScenarioMcqs = $allowsMcqs && $hasScenarioPres;
        }

        // 7. Exercise & Supplementary Filters
        $requestedExercise = $request->has('exercise') ? $request->boolean('exercise') : true;
        $requestedSupplementary = $request->has('supplementary') ? $request->boolean('supplementary') : true;

        if (!$requestedExercise && !$requestedSupplementary) {
            return response()->json([
                'success' => 0,
                'message' => 'At least one of exercise or supplementary questions must be selected.',
                'code'    => 'INVALID_FILTER',
            ], 422);
        }

        if ($requestedExercise && !$allowExercise) {
            return response()->json([
                'success' => 0,
                'message' => 'Exercise questions are restricted under your current subscription or campaign.',
                'code'    => 'ACCESS_DENIED',
            ], 403);
        }

        if ($requestedSupplementary && !$allowSupplementary) {
            return response()->json([
                'success' => 0,
                'message' => 'Supplementary questions are restricted under your current subscription or campaign.',
                'code'    => 'ACCESS_DENIED',
            ], 403);
        }

        $search = $request->filled('search') ? trim((string) $request->input('search')) : null;
        $targetTopicIds = $selectedTopics->pluck('id')->all();

        // 8. Bounded Database-Level Item Queries (Zero Full-Table PHP Materialization)
        // 8a. Straight Questions (individual questions with scenario_group_id IS NULL)
        $straightItemsQuery = DB::table('exam_question_tbl as q')
            ->select([
                DB::raw("'straight' as item_type"),
                'q.id as item_id',
                'q.topic_id',
                DB::raw('0 as type_order'),
            ])
            ->whereIn('q.topic_id', $targetTopicIds)
            ->where('q.activate', 1)
            ->whereNull('q.scenario_group_id');

        // Apply question type filtering
        $straightItemsQuery->where(function ($sub) use ($selectedTypeIds, $mappedTypes, $includeStraightMcqs) {
            $hasClause = false;
            foreach ($selectedTypeIds as $tId) {
                $typeInfo = $mappedTypes[$tId];
                if ($typeInfo['is_mcq']) {
                    if ($includeStraightMcqs) {
                        if ((int) $tId === 1) {
                            $sub->orWhere('q.is_mcq', 1)->orWhere('q.question_type', 1);
                        } else {
                            $sub->orWhere('q.question_type', $tId);
                        }
                        $hasClause = true;
                    }
                } else {
                    $sub->orWhere('q.question_type', $tId);
                    $hasClause = true;
                }
            }
            if (!$hasClause) {
                $sub->whereRaw('0 = 1');
            }
        });

        // Apply exercise/supplementary filtering
        if ($requestedExercise && !$requestedSupplementary) {
            $straightItemsQuery->where('q.exercise_question', 1);
        } elseif (!$requestedExercise && $requestedSupplementary) {
            $straightItemsQuery->where(function ($sub) {
                $sub->where('q.exercise_question', 0)->orWhereNull('q.exercise_question');
            });
        }

        // Apply search filtering
        if ($search !== null && $search !== '') {
            $straightItemsQuery->where(function ($sub) use ($search) {
                $sub->where('q.question', 'like', "%{$search}%")
                    ->orWhere('q.question_um', 'like', "%{$search}%");
            });
        }

        // 8b. Scenario Groups (Mandatory Rule of Four: >= 4 eligible questions qualify)
        $scenarioItemsQuery = null;
        if ($includeScenarioMcqs && $mcqDecision->isAllowed()) {
            $scenarioItemsQuery = DB::table('question_scenario_groups_tbl as sg')
                ->join('exam_question_tbl as q', function ($join) use ($requestedExercise, $requestedSupplementary, $selectedTypeIds) {
                    $join->on('q.scenario_group_id', '=', 'sg.id')
                        ->where('q.activate', '=', 1)
                        ->where('q.is_mcq', '=', 1);
                    if ($requestedExercise && !$requestedSupplementary) {
                        $join->where('q.exercise_question', '=', 1);
                    } elseif (!$requestedExercise && $requestedSupplementary) {
                        $join->where(function ($sub) {
                            $sub->where('q.exercise_question', 0)->orWhereNull('q.exercise_question');
                        });
                    }
                    $join->where(function ($sub) use ($selectedTypeIds) {
                        $hasClause = false;
                        foreach ($selectedTypeIds as $tId) {
                            if ((int) $tId === 1) {
                                $sub->orWhere('q.is_mcq', 1)->orWhere('q.question_type', 1);
                                $hasClause = true;
                            } else {
                                $sub->orWhere('q.question_type', $tId);
                                $hasClause = true;
                            }
                        }
                        if (!$hasClause) {
                            $sub->whereRaw('0 = 1');
                        }
                    });
                })
                ->select([
                    DB::raw("'scenario' as item_type"),
                    'sg.id as item_id',
                    'sg.topic_id',
                    DB::raw('1 as type_order'),
                ])
                ->whereIn('sg.topic_id', $targetTopicIds)
                ->where('sg.activate', 1);

            if (!empty($selectedPresTypeIds)) {
                $applicableScenarioPresIds = array_intersect($selectedPresTypeIds, $scenarioPresTypeIds);
                if (!empty($applicableScenarioPresIds)) {
                    $scenarioItemsQuery->whereIn('sg.question_presentation_type_id', $applicableScenarioPresIds);
                } else {
                    $scenarioItemsQuery->whereRaw('0 = 1');
                }
            }

            if ($search !== null && $search !== '') {
                $scenarioItemsQuery->where(function ($sub) use ($search, $requestedExercise, $requestedSupplementary) {
                    $sub->where('sg.title', 'like', "%{$search}%")
                        ->orWhere('sg.scenario_text', 'like', "%{$search}%")
                        ->orWhere('sg.scenario_text_um', 'like', "%{$search}%")
                        ->orWhereExists(function ($exQuery) use ($search, $requestedExercise, $requestedSupplementary) {
                            $exQuery->select(DB::raw(1))
                                ->from('exam_question_tbl as sq')
                                ->whereColumn('sq.scenario_group_id', 'sg.id')
                                ->where('sq.activate', 1)
                                ->where('sq.is_mcq', 1);

                            if ($requestedExercise && !$requestedSupplementary) {
                                $exQuery->where('sq.exercise_question', 1);
                            } elseif (!$requestedExercise && $requestedSupplementary) {
                                $exQuery->where(function ($exSub) {
                                    $exSub->where('sq.exercise_question', 0)->orWhereNull('sq.exercise_question');
                                });
                            }

                            $exQuery->where(function ($qSub) use ($search) {
                                $qSub->where('sq.question', 'like', "%{$search}%")
                                    ->orWhere('sq.question_um', 'like', "%{$search}%");
                            });
                        });
                });
            }

            // Group by scenario group and enforce Rule of Four qualification (>= 4 questions)
            $scenarioItemsQuery->groupBy('sg.id', 'sg.topic_id')
                ->havingRaw('COUNT(q.id) >= 4');
        }

        // 9. Exact Bounded Item & Question Counts
        $straightCount = $straightItemsQuery->count();
        $scenarioCount = $scenarioItemsQuery ? DB::query()->fromSub($scenarioItemsQuery, 'sc_count')->count() : 0;

        $totalItems = $straightCount + $scenarioCount;
        $totalQuestions = $straightCount + ($scenarioCount * 4);

        $perPage = min(max($request->integer('per_page', 20), 1), 100);
        $page = max($request->integer('page', 1), 1);
        $lastPage = $totalItems > 0 ? (int) ceil($totalItems / $perPage) : 1;

        // 10. Database-Backed Bounded Pagination (Only $perPage rows materialized)
        if ($scenarioItemsQuery !== null) {
            $unionQuery = $straightItemsQuery->unionAll($scenarioItemsQuery);
            $pagedRows = DB::query()->fromSub($unionQuery, 'paged_items')
                ->orderBy('topic_id', 'asc')
                ->orderBy('type_order', 'asc')
                ->orderBy('item_id', 'asc')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get();
        } else {
            $pagedRows = $straightItemsQuery
                ->orderBy('topic_id', 'asc')
                ->orderBy('item_id', 'asc')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get();
        }

        $straightIds = [];
        $scenarioGroupIds = [];
        foreach ($pagedRows as $row) {
            if ($row->item_type === 'straight') {
                $straightIds[] = (int) $row->item_id;
            } elseif ($row->item_type === 'scenario') {
                $scenarioGroupIds[] = (int) $row->item_id;
            }
        }

        // 11. Resolve Scenario Group Questions for Paged Scenario Groups (First 4 ordered questions)
        $scenarioGroups = collect([]);
        $scenarioGroupQuestionIds = [];

        if (!empty($scenarioGroupIds)) {
            $scenarioGroups = DB::table('question_scenario_groups_tbl')
                ->whereIn('id', $scenarioGroupIds)
                ->get()
                ->keyBy('id');

            $groupQuestionsQuery = DB::table('exam_question_tbl as q')
                ->whereIn('q.scenario_group_id', $scenarioGroupIds)
                ->where('q.activate', 1)
                ->where('q.is_mcq', 1);

            $groupQuestionsQuery->where(function ($sub) use ($selectedTypeIds) {
                $hasClause = false;
                foreach ($selectedTypeIds as $tId) {
                    if ((int) $tId === 1) {
                        $sub->orWhere('q.is_mcq', 1)->orWhere('q.question_type', 1);
                        $hasClause = true;
                    } else {
                        $sub->orWhere('q.question_type', $tId);
                        $hasClause = true;
                    }
                }
                if (!$hasClause) {
                    $sub->whereRaw('0 = 1');
                }
            });

            if ($requestedExercise && !$requestedSupplementary) {
                $groupQuestionsQuery->where('q.exercise_question', 1);
            } elseif (!$requestedExercise && $requestedSupplementary) {
                $groupQuestionsQuery->where(function ($sub) {
                    $sub->where('q.exercise_question', 0)->orWhereNull('q.exercise_question');
                });
            }

            $allScenarioQuestions = $groupQuestionsQuery
                ->select(['q.id', 'q.scenario_group_id', 'q.scenario_question_order'])
                ->orderBy('q.scenario_question_order', 'asc')
                ->orderBy('q.id', 'asc')
                ->get()
                ->groupBy('scenario_group_id');

            foreach ($scenarioGroupIds as $sgId) {
                $gQuestions = $allScenarioQuestions->get($sgId, collect([]));
                if ($gQuestions->count() >= 4) {
                    $scenarioGroupQuestionIds[$sgId] = $gQuestions->take(4)->pluck('id')->map('intval')->all();
                }
            }
        }

        // 12. Batch-Load Questions & Relationships for Current Page (Zero N+1, memory bounded strictly to O(perPage))
        $allPageQuestionIds = $straightIds;
        foreach ($scenarioGroupQuestionIds as $sgQIds) {
            foreach ($sgQIds as $qId) {
                $allPageQuestionIds[] = $qId;
            }
        }

        $loadedQuestions = collect([]);
        if (!empty($allPageQuestionIds)) {
            $canAnswers = $answersDecision->isAllowed() && $request->boolean('include_answers');

            $qQuery = ExamQuestion::with(['questionType', 'scenarioGroup']);
            if ($canAnswers) {
                $qQuery->with(['answerOptions', 'answers']);
            } else {
                $qQuery->with(['answerOptions']);
            }

            $loadedQuestions = $qQuery->whereIn('id', $allPageQuestionIds)->get()->keyBy('id');
        }

        // 13. Format and Protect Content
        $canAnswers = $answersDecision->isAllowed() && $request->boolean('include_answers');
        $canExplanations = $explanationsDecision->isAllowed() && $request->boolean('include_explanations');
        $canTopicContents = $topicContentsDecision->isAllowed();

        $formatQuestion = function (ExamQuestion $q) use ($canAnswers, $canExplanations, $canTopicContents, $mappedTypes) {
            $isMcq = (bool) ($q->is_mcq || ($q->question_type === 1));

            // Options handling (MCQs)
            $options = [];
            if ($isMcq && $q->relationLoaded('answerOptions')) {
                foreach ($q->answerOptions as $opt) {
                    $optData = [
                        'id'             => (int) $opt->id,
                        'option'         => $opt->option,
                        'option_um'      => $opt->option_um,
                        'option_lang'    => (int) ($opt->option_lang ?? 0),
                        'option_um_lang' => (int) ($opt->option_um_lang ?? 1),
                    ];
                    // Correctness flag ONLY if explicitly permitted and requested
                    if ($canAnswers) {
                        $optData['is_answer'] = (bool) ($opt->is_answer ?? false);
                    }
                    $options[] = $optData;
                }
            }

            // Answers handling (Non-MCQs)
            $answers = [];
            if (!$isMcq && $canAnswers && $q->relationLoaded('answers')) {
                foreach ($q->answers as $ans) {
                    $answers[] = [
                        'id'             => (int) $ans->id,
                        'answer'         => $ans->answer,
                        'answer_um'      => $ans->answer_um,
                        'answer_lang'    => (int) ($ans->answer_lang ?? 0),
                        'answer_um_lang' => (int) ($ans->answer_um_lang ?? 1),
                    ];
                }
            }

            $typeInfo = $mappedTypes[(int) $q->question_type] ?? [
                'id'        => (int) $q->question_type,
                'type_name' => $q->questionType->type_name ?? 'Unknown',
                'code'      => $isMcq ? 'mcq' : 'other',
            ];

            return [
                'id'                => (int) $q->id,
                'question'          => $q->question,
                'question_um'       => $q->question_um,
                'topic_id'          => (int) $q->topic_id,
                'question_type'     => $typeInfo,
                'is_mcq'            => $isMcq,
                'exercise_question' => (bool) ($q->exercise_question ?? false),
                'marks'             => (float) ($q->marks ?? 1.0),
                'difficulty'        => (int) ($q->difficulty ?? 3),
                'cognitive_domain'  => (int) ($q->cognitive_domain ?? 1),
                'options'           => $options,
                'answers'           => $answers,
                'explanation'       => $canExplanations ? $q->explanation : null,
                'explanation_um'    => $canExplanations ? $q->explanation_um : null,
                'explanation_video_url' => $canExplanations ? $q->explanation_video_url : null,
                'topic_content'     => $canTopicContents ? $q->topic_content : null,
                'scenario_group_id' => $q->scenario_group_id ? (int) $q->scenario_group_id : null,
                'scenario_question_order' => (int) ($q->scenario_question_order ?? 0),
            ];
        };

        $pageStraight = [];
        $pageScenarios = [];
        $pageItems = [];

        foreach ($pagedRows as $row) {
            if ($row->item_type === 'straight') {
                $qModel = $loadedQuestions->get((int) $row->item_id);
                if ($qModel) {
                    $formattedQ = $formatQuestion($qModel);
                    $pageStraight[] = $formattedQ;
                    $pageItems[] = [
                        'type'     => 'straight',
                        'question' => $formattedQ,
                    ];
                }
            } elseif ($row->item_type === 'scenario') {
                $sgId = (int) $row->item_id;
                $sg = $scenarioGroups->get($sgId);
                $qIds = $scenarioGroupQuestionIds[$sgId] ?? [];

                if ($sg && count($qIds) === 4) {
                    $groupQuestions = [];
                    foreach ($qIds as $qId) {
                        $qModel = $loadedQuestions->get($qId);
                        if ($qModel) {
                            $groupQuestions[] = $formatQuestion($qModel);
                        }
                    }

                    // Verify Rule of Four integrity at formatting time (exactly 4 questions)
                    if (count($groupQuestions) === 4) {
                        $formattedSg = [
                            'id'                            => (int) $sg->id,
                            'title'                         => $sg->title,
                            'scenario_text'                 => $sg->scenario_text,
                            'scenario_text_um'              => $sg->scenario_text_um,
                            'scenario_image'                => $sg->scenario_image,
                            'topic_id'                      => (int) $sg->topic_id,
                            'unit_id'                       => (int) $sg->unit_id,
                            'book_id'                       => (int) $sg->book_id,
                            'question_presentation_type_id' => (int) $sg->question_presentation_type_id,
                            'questions'                     => $groupQuestions,
                        ];
                        $pageScenarios[] = $formattedSg;
                        $pageItems[] = [
                            'type'           => 'scenario',
                            'scenario_group' => $formattedSg,
                        ];
                    }
                }
            }
        }

        return response()->json([
            'success' => 1,
            'scope'   => [
                'curriculum_board_id' => (int) $unitRow->curriculum_board_id,
                'class_id'            => (int) $unitRow->class_id,
                'subject_id'          => (int) $unitRow->subject_id,
                'book_id'             => (int) $unitRow->book_id,
                'unit_id'             => (int) $unitRow->unit_id,
                'unit_name'           => $unitRow->unit_name,
                'unit_no'             => (int) $unitRow->unit_no,
                'selection_mode'      => $selectionMode,
                'topic_ids'           => $selectedTopics->pluck('id')->map('intval')->all(),
            ],
            'filters' => [
                'question_type_ids'     => $selectedTypeIds,
                'presentation_type_ids' => $selectedPresTypeIds,
                'exercise'              => $requestedExercise,
                'supplementary'         => $requestedSupplementary,
                'search'                => $search,
            ],
            'permissions' => [
                'perm_mcqs'               => $mcqDecision->isAllowed(),
                'perm_short_questions'    => $shortDecision->isAllowed(),
                'perm_long_questions'     => $longDecision->isAllowed(),
                'perm_exercise_questions' => $allowExercise,
                'perm_supplementary_questions' => $allowSupplementary,
                'can_view_answers'        => $answersDecision->isAllowed() && $request->boolean('include_answers'),
                'can_view_explanations'   => $explanationsDecision->isAllowed() && $request->boolean('include_explanations'),
                'can_view_topic_contents' => $topicContentsDecision->isAllowed(),
            ],
            'data' => [
                'straight_questions' => $pageStraight,
                'scenario_groups'    => $pageScenarios,
                'items'              => $pageItems,
            ],
            'pagination' => [
                'current_page'    => $page,
                'per_page'        => $perPage,
                'total_items'     => $totalItems,
                'total_questions' => $totalQuestions,
                'last_page'       => $lastPage,
                'has_more'        => $page < $lastPage,
            ],
        ], 200);
    }

    /**
     * Strictly validate that a value is a positive integer.
     * Rejects floats/decimals (e.g. 263.5, "263.5"), scientific notation ("263e1"),
     * zero, negative numbers, booleans, arrays, objects, and empty/null values.
     *
     * @param mixed $val
     * @return int|null Valid positive integer, or null if invalid
     */
    protected function validateStrictPositiveInt(mixed $val): ?int
    {
        if (is_array($val) || is_bool($val) || is_object($val) || $val === null) {
            return null;
        }

        if (is_int($val)) {
            return $val > 0 ? $val : null;
        }

        if (is_float($val)) {
            return null;
        }

        if (is_string($val)) {
            $val = trim($val);
            if (!preg_match('/^[1-9][0-9]*$/', $val)) {
                return null;
            }
        }

        $filtered = filter_var($val, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $filtered !== false ? (int) $filtered : null;
    }
}
