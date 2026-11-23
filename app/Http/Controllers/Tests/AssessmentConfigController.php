<?php

namespace App\Http\Controllers\Tests;

use App\Http\Controllers\Controller;
use App\Http\Services\Freemium\DTOs\UnifiedAccessDecision;
use App\Http\Services\Freemium\UnifiedEntitlementResolver;
use App\Models\WebUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Assessment Configuration Controller (Milestone A)
 *
 * Dynamically provides assessment configuration, authorized question types,
 * database presentation types, composition modes, and scenario availability
 * for the student's requested academic scope based on authoritative backend entitlements.
 */
class AssessmentConfigController extends Controller
{
    public function __construct(
        protected UnifiedEntitlementResolver $entitlementResolver
    ) {
    }

    /**
     * Retrieve dynamic assessment configuration for the requested academic scope.
     *
     * Supports book_id, unit_ids (or unit_id), and topic_ids (or topic_id).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getAssessmentConfiguration(Request $request): JsonResponse
    {
        $student = $request->user('web_api') ?: $request->user();

        if (!$student || $student->role !== 'student' || $student->status !== 'active') {
            return response()->json([
                'success' => 0,
                'error'   => 'Active student account required.',
                'code'    => 'UNAUTHENTICATED',
            ], 401);
        }

        // 1. Extract and sanitize scope parameters
        $bookId = $request->filled('book_id') ? (int) $request->input('book_id') : null;

        $unitIdsInput = $request->input('unit_ids');
        if (empty($unitIdsInput) && $request->filled('unit_id')) {
            $unitIdsInput = [$request->input('unit_id')];
        }
        if (!empty($unitIdsInput) && !is_array($unitIdsInput)) {
            $unitIdsInput = [$unitIdsInput];
        }
        $unitIds = !empty($unitIdsInput)
            ? array_values(array_unique(array_filter(array_map('intval', $unitIdsInput), fn($id) => $id > 0)))
            : [];
        sort($unitIds);

        $topicIdsInput = $request->input('topic_ids');
        if (empty($topicIdsInput) && $request->filled('topic_id')) {
            $topicIdsInput = [$request->input('topic_id')];
        }
        if (!empty($topicIdsInput) && !is_array($topicIdsInput)) {
            $topicIdsInput = [$topicIdsInput];
        }
        $topicIds = !empty($topicIdsInput)
            ? array_values(array_unique(array_filter(array_map('intval', $topicIdsInput), fn($id) => $id > 0)))
            : [];
        sort($topicIds);

        $subjectId = $request->filled('subject_id') ? (int) $request->input('subject_id') : null;
        $offeredProgramId = $request->filled('offered_program_id') ? (int) $request->input('offered_program_id') : null;
        $examType = $request->input('exam_type', 'practice');

        if (empty($bookId) && empty($unitIds) && empty($topicIds)) {
            return response()->json([
                'success' => 0,
                'error'   => 'Academic scope required: Please provide book_id, unit_ids, or topic_ids.',
                'code'    => 'SCOPE_REQUIRED',
            ], 422);
        }

        // 2. Validate DB-grounded hierarchy and detect cross-hierarchy tampering
        if (!empty($topicIds)) {
            $dbTopics = DB::table('book_unit_topic_tbl as topics')
                ->join('book_unit_tbl as units', 'units.id', '=', 'topics.unit_id')
                ->join('book_tbl as books', 'books.id', '=', 'units.book_id')
                ->join('subject_tbl as subjects', 'subjects.id', '=', 'books.subject_id')
                ->whereIn('topics.id', $topicIds)
                ->where('topics.activate', 1)
                ->where('units.activate', 1)
                ->where('books.activate', 1)
                ->select([
                    'topics.id as topic_id',
                    'units.id as unit_id',
                    'books.id as book_id',
                    'books.subject_id',
                    'books.class_id',
                    'books.curriculum_board_id',
                ])
                ->get()
                ->keyBy('topic_id');

            if ($dbTopics->count() !== count($topicIds)) {
                return response()->json([
                    'success' => 0,
                    'error'   => 'Hierarchy mismatch: One or more selected topics do not exist or are inactive.',
                    'code'    => 'HIERARCHY_MISMATCH',
                ], 422);
            }

            if (!empty($unitIds)) {
                foreach ($dbTopics as $t) {
                    if (!in_array((int) $t->unit_id, $unitIds, true)) {
                        return response()->json([
                            'success' => 0,
                            'error'   => "Hierarchy mismatch: Topic #{$t->topic_id} does not belong to the selected units.",
                            'code'    => 'HIERARCHY_MISMATCH',
                        ], 422);
                    }
                }
            } else {
                $unitIds = $dbTopics->pluck('unit_id')->unique()->values()->all();
            }

            if (!empty($bookId)) {
                foreach ($dbTopics as $t) {
                    if ((int) $t->book_id !== $bookId) {
                        return response()->json([
                            'success' => 0,
                            'error'   => "Hierarchy mismatch: Topic #{$t->topic_id} does not belong to the requested book.",
                            'code'    => 'HIERARCHY_MISMATCH',
                        ], 422);
                    }
                }
            } else {
                $bookId = (int) $dbTopics->first()->book_id;
            }

            if (empty($subjectId)) {
                $subjectId = (int) $dbTopics->first()->subject_id;
            }
        } elseif (!empty($unitIds)) {
            $dbUnits = DB::table('book_unit_tbl as units')
                ->join('book_tbl as books', 'books.id', '=', 'units.book_id')
                ->whereIn('units.id', $unitIds)
                ->where('units.activate', 1)
                ->where('books.activate', 1)
                ->select([
                    'units.id as unit_id',
                    'books.id as book_id',
                    'books.subject_id',
                    'books.class_id',
                    'books.curriculum_board_id',
                ])
                ->get()
                ->keyBy('unit_id');

            if ($dbUnits->count() !== count($unitIds)) {
                return response()->json([
                    'success' => 0,
                    'error'   => 'Hierarchy mismatch: One or more selected units do not exist or are inactive.',
                    'code'    => 'HIERARCHY_MISMATCH',
                ], 422);
            }

            if (!empty($bookId)) {
                foreach ($dbUnits as $u) {
                    if ((int) $u->book_id !== $bookId) {
                        return response()->json([
                            'success' => 0,
                            'error'   => "Hierarchy mismatch: Unit #{$u->unit_id} does not belong to the requested book.",
                            'code'    => 'HIERARCHY_MISMATCH',
                        ], 422);
                    }
                }
            } else {
                $bookId = (int) $dbUnits->first()->book_id;
            }

            if (empty($subjectId)) {
                $subjectId = (int) $dbUnits->first()->subject_id;
            }
        } elseif (!empty($bookId)) {
            $dbBook = DB::table('book_tbl as books')
                ->where('books.id', $bookId)
                ->where('books.activate', 1)
                ->select(['books.id', 'books.subject_id', 'books.class_id', 'books.curriculum_board_id'])
                ->first();

            if (!$dbBook) {
                return response()->json([
                    'success' => 0,
                    'error'   => 'Book not found or inactive.',
                    'code'    => 'NOT_FOUND',
                ], 404);
            }

            $subjectId = (int) $dbBook->subject_id;
            $unitIds = DB::table('book_unit_tbl')
                ->where('book_id', $bookId)
                ->where('activate', 1)
                ->pluck('id')
                ->map('intval')
                ->values()
                ->all();
        }

        // Validate offered_program_id if provided
        if ($offeredProgramId) {
            $programValid = DB::table('offered_programs as programs')
                ->join('offered_classes as classes', 'classes.id', '=', 'programs.offered_class_id')
                ->join('program_subjects as prog_subs', 'prog_subs.offered_program_id', '=', 'programs.id')
                ->where('programs.id', $offeredProgramId)
                ->where('programs.is_active', 1)
                ->where('prog_subs.subject_id', $subjectId)
                ->where('prog_subs.is_active', 1)
                ->exists();

            if (!$programValid) {
                return response()->json([
                    'success' => 0,
                    'error'   => 'Hierarchy mismatch: Program does not cover the requested subject/class/board.',
                    'code'    => 'HIERARCHY_MISMATCH',
                ], 422);
            }
        }

        // 3. Authoritative entitlement resolution
        $targetContext = [
            'subject_id' => $subjectId,
            'book_id'    => $bookId,
            'unit_ids'   => $unitIds,
        ];
        if (!empty($topicIds)) {
            $targetContext['topic_ids'] = $topicIds;
        }
        if ($offeredProgramId) {
            $targetContext['offered_program_id'] = $offeredProgramId;
        }

        $mcqDecision           = $this->entitlementResolver->resolve($student, $targetContext, 'perm_mcqs', $examType);
        $shortDecision         = $this->entitlementResolver->resolve($student, $targetContext, 'perm_short_questions', $examType);
        $longDecision          = $this->entitlementResolver->resolve($student, $targetContext, 'perm_long_questions', $examType);
        $exerciseDecision      = $this->entitlementResolver->resolve($student, $targetContext, 'perm_exercise_questions', $examType);
        $supplementaryDecision = $this->entitlementResolver->resolve($student, $targetContext, 'perm_supplementary_questions', $examType);

        // Fail-closed: If student is denied all question formats in the requested scope
        if ($mcqDecision->isDenied() && $shortDecision->isDenied() && $longDecision->isDenied()) {
            return response()->json([
                'success'       => 0,
                'error'         => $mcqDecision->getDenialReason() ?? 'An active subscription is required to access this resource.',
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

        // If both exercise and supplementary are explicitly denied, fail closed
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

        // 4. Query eligible questions in this scope
        $baseQuestionQuery = DB::table('exam_question_tbl as questions')
            ->join('book_unit_topic_tbl as topics', 'topics.id', '=', 'questions.topic_id')
            ->whereIn('topics.unit_id', $unitIds)
            ->where('questions.activate', 1);

        if (!empty($topicIds)) {
            $baseQuestionQuery->whereIn('questions.topic_id', $topicIds);
        }

        if (!$allowExercise && $allowSupplementary) {
            $baseQuestionQuery->where(function ($q) {
                $q->where('questions.exercise_question', 0)->orWhereNull('questions.exercise_question');
            });
        } elseif ($allowExercise && !$allowSupplementary) {
            $baseQuestionQuery->where('questions.exercise_question', 1);
        }

        // 5. Category A: Dynamic Question Types
        $activeQuestionTypes = DB::table('question_type_tbl')
            ->where('activate', 1)
            ->get();

        $availableQuestionTypes = [];

        foreach ($activeQuestionTypes as $type) {
            $mapping = $this->resolveQuestionTypeMapping($type);
            if ($mapping === null) {
                // Fail closed: unmapped, unknown, or malformed question type is never exposed
                continue;
            }

            $dimension = $mapping['dimension'];
            $code = $mapping['code'];
            $isMcq = $mapping['is_mcq'];

            $typeDecision = match ($dimension) {
                'perm_mcqs'           => $mcqDecision,
                'perm_long_questions' => $longDecision,
                'perm_short_questions'=> $shortDecision,
                default               => null,
            };

            // Only return question types that the student is authorized to access within the requested scope
            if ($typeDecision === null || $typeDecision->isDenied()) {
                continue;
            }

            $typeQuery = clone $baseQuestionQuery;
            if ($isMcq && (int) $type->id === 1) {
                $typeQuery->where(function ($q) use ($type) {
                    $q->where('questions.is_mcq', 1)->orWhere('questions.question_type', $type->id);
                });
            } else {
                $typeQuery->where('questions.question_type', $type->id);
            }
            $count = $typeQuery->count();

            $availableQuestionTypes[] = [
                'id'                  => (int) $type->id,
                'type_name'           => $type->type_name,
                'code'                => $code,
                'is_mcq'              => $isMcq,
                'is_entitled'         => true,
                'available_questions' => $count,
            ];
        }

        // 6. Category B: Dynamic MCQ Presentation Types
        $availablePresentationTypes = [];
        $straightMcqCount = 0;
        $qualifyingScenarioGroupsCount = 0;
        $eligibleScenarioQuestionsCount = 0;

        if ($mcqDecision->isAllowed()) {
            $dbPresentationTypes = DB::table('question_presentation_type_tbl')
                ->where('activate', 1)
                ->orderBy('sort_order', 'asc')
                ->get();

            // Calculate straight MCQs
            $straightQuery = clone $baseQuestionQuery;
            $straightQuery->where('questions.is_mcq', 1)
                ->whereNull('questions.scenario_group_id');
            $straightMcqCount = $straightQuery->count();

            // Calculate scenario MCQs and qualifying groups
            $scenarioQuery = clone $baseQuestionQuery;
            $scenarioQuery->join('question_scenario_groups_tbl as scenario_groups', 'scenario_groups.id', '=', 'questions.scenario_group_id')
                ->where('scenario_groups.activate', 1)
                ->where('questions.is_mcq', 1)
                ->whereNotNull('questions.scenario_group_id')
                ->select([
                    'questions.id',
                    'questions.scenario_group_id',
                    'scenario_groups.question_presentation_type_id',
                ]);

            $scenarioQuestions = $scenarioQuery->get();
            $groupedByScenario = $scenarioQuestions->groupBy('scenario_group_id');

            $qualifyingGroupIds = [];
            $qualifyingQuestionsCount = 0;
            foreach ($groupedByScenario as $sgId => $members) {
                // Rule of Four: Only groups with at least 4 eligible questions qualify
                if ($members->count() >= 4) {
                    $qualifyingGroupIds[] = (int) $sgId;
                    $qualifyingQuestionsCount += $members->count();
                }
            }
            $qualifyingScenarioGroupsCount = count($qualifyingGroupIds);
            $eligibleScenarioQuestionsCount = $qualifyingQuestionsCount;

            foreach ($dbPresentationTypes as $pt) {
                $allowsMultiple = (bool) ($pt->allows_multiple_mcqs ?? false);
                $isStraight = (!$allowsMultiple || strtolower($pt->code ?? '') === 'st' || strtolower($pt->type_name) === 'straight');

                if ($isStraight) {
                    if ($straightMcqCount > 0) {
                        $availablePresentationTypes[] = [
                            'id'                   => (int) $pt->id,
                            'type_name'            => $pt->type_name,
                            'code'                 => $pt->code ?? 'st',
                            'description'          => $pt->description,
                            'allows_multiple_mcqs' => false,
                            'sort_order'           => (int) $pt->sort_order,
                            'available_questions'  => $straightMcqCount,
                            'available_groups'     => null,
                        ];
                    }
                } else {
                    // Only expose if qualifying complete groups exist in requested scope
                    if ($qualifyingScenarioGroupsCount > 0) {
                        $availablePresentationTypes[] = [
                            'id'                   => (int) $pt->id,
                            'type_name'            => $pt->type_name,
                            'code'                 => $pt->code ?? 'stimulus',
                            'description'          => $pt->description,
                            'allows_multiple_mcqs' => true,
                            'sort_order'           => (int) $pt->sort_order,
                            'available_questions'  => $eligibleScenarioQuestionsCount,
                            'available_groups'     => $qualifyingScenarioGroupsCount,
                        ];
                    }
                }
            }
        }

        // 7. Category C: Assessment Composition Modes
        $assessmentCompositionModes = [
            [
                'mode'                => 'straight',
                'label'               => 'Straight MCQs',
                'description'         => 'Standalone multiple-choice questions without scenario context.',
                'is_available'        => ($straightMcqCount > 0),
                'available_questions' => $straightMcqCount,
                'available_groups'    => null,
            ],
            [
                'mode'                => 'scenario',
                'label'               => 'Scenario-Based MCQs',
                'description'         => 'Questions based on context passages, experiments, or diagrams selected in complete groups of four.',
                'is_available'        => ($qualifyingScenarioGroupsCount > 0),
                'available_questions' => $eligibleScenarioQuestionsCount,
                'available_groups'    => $qualifyingScenarioGroupsCount,
            ],
            [
                'mode'                => 'all',
                'label'               => 'Mixed MCQs',
                'description'         => 'Composite assessment combining standalone MCQs and complete scenario-based question groups.',
                'is_available'        => ($straightMcqCount > 0 || $qualifyingScenarioGroupsCount > 0),
                'supports_scenarios'  => ($qualifyingScenarioGroupsCount > 0),
            ],
        ];

        // 8. Category D: Scenario Percentage Configuration
        $scenarioPercentageConfig = [
            'min'                 => 0,
            'max'                 => 100,
            'step'                => 5,
            'default'             => ($qualifyingScenarioGroupsCount > 0) ? 30 : 0,
            'group_size'          => 4,
            'is_applicable'       => ($qualifyingScenarioGroupsCount > 0),
            'max_scenario_groups' => $qualifyingScenarioGroupsCount,
        ];

        // 9. Category E: Scope Availability & Permissions Summary
        $scopeAvailability = [
            'book_id'                        => $bookId,
            'unit_ids'                       => $unitIds,
            'topic_ids'                      => !empty($topicIds) ? $topicIds : null,
            'subject_id'                     => $subjectId,
            'total_eligible_questions'       => $straightMcqCount + $eligibleScenarioQuestionsCount,
            'straight_mcq_count'             => $straightMcqCount,
            'scenario_mcq_count'             => $eligibleScenarioQuestionsCount,
            'eligible_scenario_groups_count' => $qualifyingScenarioGroupsCount,
            'is_scenario_supported'          => ($qualifyingScenarioGroupsCount > 0),
            'permissions'                    => [
                'perm_mcqs'                    => $mcqDecision->isAllowed(),
                'perm_short_questions'         => $shortDecision->isAllowed(),
                'perm_long_questions'          => $longDecision->isAllowed(),
                'perm_exercise_questions'      => $allowExercise,
                'perm_supplementary_questions' => $allowSupplementary,
            ],
        ];

        return response()->json([
            'success' => 1,
            'data'    => [
                'question_types'               => $availableQuestionTypes,
                'mcq_presentation_types'       => $availablePresentationTypes,
                'assessment_composition_modes' => $assessmentCompositionModes,
                'scenario_percentage_config'   => $scenarioPercentageConfig,
                'scope_availability'           => $scopeAvailability,
            ],
        ], 200);
    }

    /**
     * Resolves the explicit question type mapping for entitlement and presentation.
     *
     * Fails closed: returns null if the question type is unmapped, unknown, or malformed.
     */
    protected function resolveQuestionTypeMapping(object $type): ?array
    {
        $explicitTypes = config('freemium.question_type_permissions.explicit_types', []);
        if (!is_array($explicitTypes) || empty($explicitTypes)) {
            return null;
        }

        $typeName = trim((string) ($type->type_name ?? ''));
        $lowerName = strtolower($typeName);
        $isMcq = (bool) ($type->is_mcq ?? false);

        // 1. If marked as is_mcq = 1 in schema, map to mcq
        if ($isMcq) {
            $mapping = $explicitTypes['mcqs'] ?? $explicitTypes['mcq'] ?? null;
            if (is_array($mapping) && !empty($mapping['dimension']) && !empty($mapping['code'])) {
                return [
                    'dimension' => $mapping['dimension'],
                    'code'      => $mapping['code'],
                    'is_mcq'    => true,
                ];
            }
            return null;
        }

        // 2. Exact match in explicit types configuration by normalized type_name
        if (isset($explicitTypes[$lowerName])) {
            $mapping = $explicitTypes[$lowerName];
            if (is_array($mapping) && !empty($mapping['dimension']) && !empty($mapping['code'])) {
                return [
                    'dimension' => $mapping['dimension'],
                    'code'      => $mapping['code'],
                    'is_mcq'    => (bool) ($mapping['is_mcq'] ?? false),
                ];
            }
        }

        // 3. Fail closed: unmapped, unrecognized, or malformed question type
        return null;
    }
}
