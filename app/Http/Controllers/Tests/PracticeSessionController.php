<?php

namespace App\Http\Controllers\Tests;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tests\Concerns\SelectsLmsMcqQuestions;
use App\Http\Services\Freemium\AssessmentIdempotencyService;
use App\Http\Services\Freemium\Exceptions\IdempotencyConflictException;
use App\Http\Services\Freemium\Exceptions\QuotaLimitExceededException;
use App\Http\Services\Freemium\FreemiumQuotaConsumptionService;
use App\Http\Services\Freemium\UnifiedEntitlementResolver;
use App\Http\Services\Freemium\DTOs\UnifiedAccessDecision;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\PracticeSession;
use App\Models\PracticeSessionQuestion;
use App\Models\PracticeSessionScope;
use App\Models\QuestionOptionStatistic;
use App\Models\QuestionStatistic;
use App\Models\StudentActivity;
use App\Models\StudentQuestionProgressSummary;
use App\Models\StudentSubjectProgressSummary;
use App\Models\StudentUnitProgressSummary;
use App\Models\Subject;
use App\Models\WebUser;
use App\Models\ExamQuestion;
use App\Models\BookUnitTopic;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PracticeSessionController extends Controller
{
    use SelectsLmsMcqQuestions;

    private const MASTERED_MIN_ATTEMPTS = 3;
    private const MASTERED_MIN_ACCURACY = 80;

    public function __construct(
        protected ?UnifiedEntitlementResolver $entitlementResolver = null,
        protected ?FreemiumQuotaConsumptionService $quotaConsumptionService = null,
        protected ?AssessmentIdempotencyService $idempotencyService = null
    ) {
        $this->entitlementResolver = $entitlementResolver ?? app(UnifiedEntitlementResolver::class);
        $this->quotaConsumptionService = $quotaConsumptionService ?? app(FreemiumQuotaConsumptionService::class);
        $this->idempotencyService = $idempotencyService ?? app(AssessmentIdempotencyService::class);
    }

    public function generateSelectedUnitsPracticeSessionForLms(Request $request)
    {
        $student = $request->user();
        if (!$student || ($student->role ?? null) !== 'student') {
            return response()->json([
                'success' => 0,
                'error'   => 'Unauthorized. Active student session required.',
            ], 401);
        }

        $idempotencyKey = $request->header('X-Idempotency-Key') ?? $request->input('idempotency_key');
        if (!$idempotencyKey) {
            return response()->json([
                'success' => 0,
                'error'   => 'Missing required X-Idempotency-Key header.',
            ], 422);
        }

        try {
            $validatedKey = $this->idempotencyService->validateKey($idempotencyKey);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => 0,
                'error'   => $e->getMessage(),
            ], 422);
        }

        $unitIds = $request->input('unit_ids');
        if (empty($unitIds) && $request->filled('unit_id')) {
            $unitIds = [$request->input('unit_id')];
            $request->merge(['unit_ids' => $unitIds]);
        }

        $topicIdsInput = $request->input('topic_ids');
        if (empty($topicIdsInput) && $request->filled('topic_id')) {
            $topicIdsInput = [$request->input('topic_id')];
            $request->merge(['topic_ids' => $topicIdsInput]);
        }

        $questionIdsInput = $request->input('question_ids');
        if (empty($questionIdsInput) && $request->filled('question_id')) {
            $questionIdsInput = [$request->input('question_id')];
            $request->merge(['question_ids' => $questionIdsInput]);
        }

        if ($request->has('mcq_format')) {
            $format = strtolower(trim((string) $request->input('mcq_format')));
            if ($format === 'mcq') {
                $format = 'all';
            }
            $request->merge(['mcq_format' => $format]);
        }

        $scenarioPercentage = 30;
        if ($request->has('scenario_percentage')) {
            $sp = $request->input('scenario_percentage');
            if (!is_numeric($sp) || (int) $sp != $sp || (int) $sp < 0 || (int) $sp > 100 || ((int) $sp % 5 !== 0)) {
                return response()->json([
                    'success'    => 0,
                    'error'      => 'The scenario percentage must be an integer between 0 and 100 in increments of 5.',
                    'error_code' => 'INVALID_SCENARIO_PERCENTAGE',
                ], 422);
            }
            $scenarioPercentage = (int) $sp;
        }

        $validated = $request->validate([
            'offered_program_id' => ['nullable', 'integer', 'min:1', 'exists:offered_programs,id'],
            'subject_id'         => ['required', 'integer', 'exists:subject_tbl,id'],
            'unit_ids'           => ['required', 'array', 'min:1'],
            'unit_ids.*'         => ['integer', 'exists:book_unit_tbl,id'],
            'topic_ids'          => ['nullable', 'array', 'min:1'],
            'topic_ids.*'        => ['integer', 'exists:book_unit_topic_tbl,id'],
            'question_ids'       => ['nullable', 'array', 'min:1'],
            'question_ids.*'     => ['integer', 'exists:exam_question_tbl,id'],
            'total_questions'    => ['required', 'integer', 'min:1'],
            'mcq_format'         => ['nullable', 'string', 'in:all,straight,scenario'],
            'scenario_percentage'=> ['nullable', 'integer', 'min:0', 'max:100'],
            'time_limit_minutes' => ['nullable', 'integer', 'min:1'],
            'scope_type'         => ['nullable', 'string', 'in:chapter,multiple_chapters,full_book'],
        ]);

        $unitIds = array_values(array_unique($validated['unit_ids']));
        sort($unitIds);
        $scopeType = $validated['scope_type']
            ?? (count($unitIds) === 1 ? 'chapter' : 'multiple_chapters');

        $offeredProgramId = !empty($validated['offered_program_id']) ? (int) $validated['offered_program_id'] : null;

        $topicIds = null;
        if (!empty($validated['topic_ids'])) {
            $topicIds = array_values(array_unique(array_filter(
                array_map('intval', $validated['topic_ids']),
                fn($id) => $id > 0
            )));
            sort($topicIds);
        }

        $questionIds = null;
        if (!empty($validated['question_ids'])) {
            $rawQids = array_map('intval', $validated['question_ids']);
            if (count($rawQids) !== count(array_unique($rawQids))) {
                return response()->json([
                    'success'    => 0,
                    'error'      => 'Duplicate question IDs are not permitted.',
                    'error_code' => 'DUPLICATE_QUESTION_IDS',
                    'code'       => 'DUPLICATE_QUESTION_IDS',
                ], 422);
            }
            $questionIds = array_values($rawQids);
        }

        // Validate topic hierarchy if provided
        if (!empty($topicIds)) {
            $dbTopics = DB::table('book_unit_topic_tbl as topics')
                ->join('book_unit_tbl as units', 'units.id', '=', 'topics.unit_id')
                ->join('book_tbl as books', 'books.id', '=', 'units.book_id')
                ->whereIn('topics.id', $topicIds)
                ->where('topics.activate', 1)
                ->where('units.activate', 1)
                ->where('books.activate', 1)
                ->select([
                    'topics.id as topic_id',
                    'units.id as unit_id',
                    'books.subject_id',
                ])
                ->get()
                ->keyBy('topic_id');

            if ($dbTopics->count() !== count($topicIds)) {
                return response()->json([
                    'success' => 0,
                    'error'   => 'Hierarchy mismatch: One or more selected topics do not exist or are inactive.',
                ], 422);
            }

            foreach ($dbTopics as $t) {
                if (!in_array((int) $t->unit_id, $unitIds, true)) {
                    return response()->json([
                        'success' => 0,
                        'error'   => 'Hierarchy mismatch: Topic #' . $t->topic_id . ' does not belong to the selected units.',
                    ], 422);
                }
                if ((int) $t->subject_id !== (int) $validated['subject_id']) {
                    return response()->json([
                        'success' => 0,
                        'error'   => 'Hierarchy mismatch: Topic #' . $t->topic_id . ' does not belong to the selected subject.',
                    ], 422);
                }
            }
        }

        // Validate question hierarchy and integrity if provided
        if (!empty($questionIds)) {
            if ((int) $validated['total_questions'] !== count($questionIds)) {
                return response()->json([
                    'success'    => 0,
                    'error'      => 'The total question count (' . $validated['total_questions'] . ') does not match the number of selected questions (' . count($questionIds) . ').',
                    'error_code' => 'QUESTION_COUNT_MISMATCH',
                    'code'       => 'QUESTION_COUNT_MISMATCH',
                ], 422);
            }

            $dbQuestions = DB::table('exam_question_tbl as questions')
                ->join('book_unit_topic_tbl as topics', 'topics.id', '=', 'questions.topic_id')
                ->join('book_unit_tbl as units', 'units.id', '=', 'topics.unit_id')
                ->join('book_tbl as books', 'books.id', '=', 'units.book_id')
                ->whereIn('questions.id', $questionIds)
                ->where('questions.activate', 1)
                ->where('questions.is_mcq', 1)
                ->select([
                    'questions.id as question_id',
                    'questions.scenario_group_id',
                    'questions.scenario_question_order',
                    'questions.exercise_question',
                    'topics.id as topic_id',
                    'units.id as unit_id',
                    'books.subject_id',
                ])
                ->get()
                ->keyBy('question_id');

            if ($dbQuestions->count() !== count($questionIds)) {
                return response()->json([
                    'success'    => 0,
                    'error'      => 'Hierarchy mismatch: One or more selected questions do not exist, are inactive, or are not MCQs.',
                    'error_code' => 'INVALID_QUESTION_SELECTION',
                    'code'       => 'INVALID_QUESTION_SELECTION',
                ], 422);
            }

            $requestedFormat = $validated['mcq_format'] ?? 'all';
            foreach ($dbQuestions as $q) {
                if (!in_array((int) $q->unit_id, $unitIds, true)) {
                    return response()->json([
                        'success'    => 0,
                        'error'      => 'Hierarchy mismatch: Question #' . $q->question_id . ' does not belong to the selected units.',
                        'error_code' => 'HIERARCHY_MISMATCH',
                        'code'       => 'HIERARCHY_MISMATCH',
                    ], 422);
                }
                if ((int) $q->subject_id !== (int) $validated['subject_id']) {
                    return response()->json([
                        'success'    => 0,
                        'error'      => 'Hierarchy mismatch: Question #' . $q->question_id . ' does not belong to the selected subject.',
                        'error_code' => 'HIERARCHY_MISMATCH',
                        'code'       => 'HIERARCHY_MISMATCH',
                    ], 422);
                }
                if (!empty($topicIds) && !in_array((int) $q->topic_id, $topicIds, true)) {
                    return response()->json([
                        'success'    => 0,
                        'error'      => 'Hierarchy mismatch: Question #' . $q->question_id . ' does not belong to the selected topics.',
                        'error_code' => 'HIERARCHY_MISMATCH',
                        'code'       => 'HIERARCHY_MISMATCH',
                    ], 422);
                }
                // F-03: Validate format
                if ($requestedFormat === 'straight' && !is_null($q->scenario_group_id)) {
                    return response()->json([
                        'success'    => 0,
                        'error'      => 'Format mismatch: Question #' . $q->question_id . ' is a scenario-based question, but straight MCQ format was requested.',
                        'error_code' => 'FORMAT_MISMATCH',
                        'code'       => 'FORMAT_MISMATCH',
                    ], 422);
                }
                if ($requestedFormat === 'scenario' && is_null($q->scenario_group_id)) {
                    return response()->json([
                        'success'    => 0,
                        'error'      => 'Format mismatch: Question #' . $q->question_id . ' is a straight MCQ, but scenario-based format was requested.',
                        'error_code' => 'FORMAT_MISMATCH',
                        'code'       => 'FORMAT_MISMATCH',
                    ], 422);
                }
            }

            // Correction A: Enforce complete 4-question scenario groups with strict atomic integrity
            $scenarioGroupOccurrences = [];
            foreach ($questionIds as $pos => $qid) {
                $q = $dbQuestions->get($qid);
                if ($q && !is_null($q->scenario_group_id)) {
                    $sgId = (int) $q->scenario_group_id;
                    if (!isset($scenarioGroupOccurrences[$sgId])) {
                        $scenarioGroupOccurrences[$sgId] = [
                            'positions'    => [],
                            'question_ids' => [],
                        ];
                    }
                    $scenarioGroupOccurrences[$sgId]['positions'][] = $pos;
                    $scenarioGroupOccurrences[$sgId]['question_ids'][] = $qid;
                }
            }

            foreach ($scenarioGroupOccurrences as $sgId => $groupData) {
                $selectedCount = count($groupData['question_ids']);
                if ($selectedCount !== 4) {
                    return response()->json([
                        'success'    => 0,
                        'error'      => "Incomplete scenario group: Scenario group #{$sgId} requires exactly four questions, but {$selectedCount} were provided.",
                        'error_code' => 'INCOMPLETE_SCENARIO_GROUP',
                        'code'       => 'INCOMPLETE_SCENARIO_GROUP',
                    ], 422);
                }

                $sgRecord = DB::table('question_scenario_groups_tbl')
                    ->where('id', $sgId)
                    ->where('activate', 1)
                    ->first();

                if (!$sgRecord) {
                    return response()->json([
                        'success'    => 0,
                        'error'      => "Scenario group #{$sgId} does not exist or is inactive.",
                        'error_code' => 'INVALID_SCENARIO_GROUP',
                        'code'       => 'INVALID_SCENARIO_GROUP',
                    ], 422);
                }

                $eligibleGroupQuestions = DB::table('exam_question_tbl as q')
                    ->join('book_unit_topic_tbl as t', 't.id', '=', 'q.topic_id')
                    ->where('q.scenario_group_id', $sgId)
                    ->where('q.activate', 1)
                    ->where('q.is_mcq', 1)
                    ->whereIn('t.unit_id', $unitIds)
                    ->select([
                        'q.id',
                        'q.scenario_question_order',
                        'q.exercise_question',
                    ])
                    ->orderBy('q.scenario_question_order')
                    ->orderBy('q.id')
                    ->get();

                if ($eligibleGroupQuestions->count() < 4) {
                    return response()->json([
                        'success'    => 0,
                        'error'      => "Scenario group #{$sgId} is incomplete or has fewer than four active eligible questions.",
                        'error_code' => 'INCOMPLETE_SCENARIO_GROUP',
                        'code'       => 'INCOMPLETE_SCENARIO_GROUP',
                    ], 422);
                }

                $eligibleIds = $eligibleGroupQuestions->pluck('id')->all();
                $diff = array_diff($groupData['question_ids'], $eligibleIds);
                if (!empty($diff)) {
                    return response()->json([
                        'success'    => 0,
                        'error'      => "One or more requested questions for scenario group #{$sgId} are not eligible members of the group.",
                        'error_code' => 'INVALID_SCENARIO_GROUP_MEMBERS',
                        'code'       => 'INVALID_SCENARIO_GROUP_MEMBERS',
                    ], 422);
                }

                // Contiguity check: the 4 questions of the scenario group cannot be split or interleaved
                $positions = $groupData['positions'];
                if ($positions[3] - $positions[0] !== 3) {
                    return response()->json([
                        'success'    => 0,
                        'error'      => "Scenario group #{$sgId} questions must be contiguous and cannot be split or interleaved with other questions.",
                        'error_code' => 'SPLIT_SCENARIO_GROUP',
                        'code'       => 'SPLIT_SCENARIO_GROUP',
                    ], 422);
                }

                // Ordering check: questions must follow scenario_question_order
                $groupMemberOrder = $eligibleGroupQuestions->keyBy('id');
                for ($i = 0; $i < 3; $i++) {
                    $ordA = $groupMemberOrder[$groupData['question_ids'][$i]]->scenario_question_order ?? 0;
                    $ordB = $groupMemberOrder[$groupData['question_ids'][$i + 1]]->scenario_question_order ?? 0;
                    if ($ordA > $ordB) {
                        return response()->json([
                            'success'    => 0,
                            'error'      => "Scenario group #{$sgId} questions must follow the required scenario question order.",
                            'error_code' => 'INVALID_SCENARIO_QUESTION_ORDER',
                            'code'       => 'INVALID_SCENARIO_QUESTION_ORDER',
                        ], 422);
                    }
                }
            }
        }

        $targetContext = [
            'offered_program_id' => $offeredProgramId,
            'subject_id'         => (int) $validated['subject_id'],
            'unit_ids'           => $unitIds,
        ];
        if (!empty($topicIds)) {
            $targetContext['topic_ids'] = $topicIds;
        }
        if (!empty($questionIds)) {
            $targetContext['question_ids'] = $questionIds;
        }

        // Authoritative 4-tier entitlement decision
        $decision = $this->entitlementResolver->resolve(
            $student,
            $targetContext,
            'perm_mcqs',
            'practice'
        );

        if ($decision->isDenied()) {
            return response()->json([
                'success'       => 0,
                'error'         => $decision->getDenialReason() ?? 'An active subscription is required to access this resource.',
                'code'          => 'ACCESS_DENIED',
                'denial_reason' => $decision->getDenialReason(),
                'diagnostic'    => $decision->getDiagnosticMessage(),
                'upgrade_slug'  => $decision->getUpgradeProgramSlug(),
            ], 403);
        }

        // F-02: Also resolve perm_exercise_questions and perm_supplementary_questions at scope level
        $scopeContext = [
            'offered_program_id' => $offeredProgramId,
            'subject_id'         => (int) $validated['subject_id'],
            'unit_ids'           => $unitIds,
        ];
        $exerciseDecision = $this->entitlementResolver->resolve(
            $student,
            $scopeContext,
            'perm_exercise_questions',
            'practice'
        );
        $supplementaryDecision = $this->entitlementResolver->resolve(
            $student,
            $scopeContext,
            'perm_supplementary_questions',
            'practice'
        );

        $isPaid = $decision->isPaid();
        $denyExercise = !$isPaid && $exerciseDecision->isDenied() && $exerciseDecision->getOrigin() === UnifiedAccessDecision::ORIGIN_FREEMIUM_RULE;
        $denySupplementary = !$isPaid && $supplementaryDecision->isDenied() && $supplementaryDecision->getOrigin() === UnifiedAccessDecision::ORIGIN_FREEMIUM_RULE;

        $allowExercise = $isPaid || !$denyExercise;
        $allowSupplementary = $isPaid || !$denySupplementary;

        // If both exercise and supplementary questions are denied under explicit freemium rules, fail closed
        if ($denyExercise && $denySupplementary) {
            return response()->json([
                'success'       => 0,
                'error'         => 'Access denied: Both exercise and supplementary questions are restricted under your current subscription or campaign.',
                'code'          => 'ACCESS_DENIED',
                'denial_reason' => 'Both exercise and supplementary questions are denied.',
                'diagnostic'    => 'perm_exercise_questions and perm_supplementary_questions are both denied.',
                'upgrade_slug'  => $exerciseDecision->getUpgradeProgramSlug() ?? $decision->getUpgradeProgramSlug(),
            ], 403);
        }

        // F-02: For explicit question_ids, enforce that each question matches allowed exercise/supplementary permissions
        if (!empty($questionIds) && !$isPaid) {
            foreach ($dbQuestions as $q) {
                $isEx = ($q->exercise_question == 1);
                if ($isEx && $denyExercise) {
                    return response()->json([
                        'success'       => 0,
                        'error'         => "Access denied: Question #{$q->question_id} is an exercise question, which is not permitted under your current campaign.",
                        'code'          => 'ACCESS_DENIED',
                        'denial_reason' => 'Exercise questions denied.',
                    ], 403);
                }
                if (!$isEx && $denySupplementary) {
                    return response()->json([
                        'success'       => 0,
                        'error'         => "Access denied: Question #{$q->question_id} is a supplementary question, which is not permitted under your current campaign.",
                        'code'          => 'ACCESS_DENIED',
                        'denial_reason' => 'Supplementary questions denied.',
                    ], 403);
                }
            }
        }

        $operation = 'practice_session_generation';
        $fingerprintPayload = [
            'operation'          => $operation,
            'offered_program_id' => $offeredProgramId,
            'subject_id'         => (int) $validated['subject_id'],
            'unit_ids'           => $unitIds,
            'topic_ids'          => $topicIds,
            'question_ids'       => $questionIds,
            'total_questions'    => (int) $validated['total_questions'],
            'mcq_format'         => $validated['mcq_format'] ?? 'all',
            'scenario_percentage'=> $scenarioPercentage,
            'scope_type'         => $scopeType,
        ];

        try {
            $result = $this->idempotencyService->execute(
                $student,
                $operation,
                $validatedKey,
                $fingerprintPayload,
                function () use (
                    $student,
                    $decision,
                    $validated,
                    $unitIds,
                    $topicIds,
                    $questionIds,
                    $scopeType,
                    $offeredProgramId,
                    $validatedKey,
                    $fingerprintPayload,
                    $allowExercise,
                    $allowSupplementary,
                    $scenarioPercentage
                ) {
                    return DB::transaction(function () use (
                        $student,
                        $decision,
                        $validated,
                        $unitIds,
                        $topicIds,
                        $questionIds,
                        $scopeType,
                        $offeredProgramId,
                        $validatedKey,
                        $fingerprintPayload,
                        $allowExercise,
                        $allowSupplementary,
                        $scenarioPercentage
                    ) {
                        if (!$decision->isPaid()) {
                            // Pessimistic parent row lock to prevent concurrency bypass
                            WebUser::where('id', $student->id)->lockForUpdate()->first();
                        }

                        // Check if student already has an active (in_progress) session for this scope
                        // (Only resume for whole-unit sessions without specific custom topic/question selections)
                        if (empty($topicIds) && empty($questionIds)) {
                            $activeSession = $this->findActiveSessionForScope(
                                $student->id,
                                $offeredProgramId,
                                (int) $validated['subject_id'],
                                $scopeType,
                                $unitIds,
                                null,
                                (int) $validated['total_questions'],
                                $validated['mcq_format'] ?? 'all'
                            );

                            if ($activeSession !== null) {
                                $data = $this->buildActiveSessionResponse(
                                    $activeSession,
                                    $unitIds,
                                    $validated['mcq_format'] ?? 'all',
                                    null
                                );

                                return [
                                    'payload' => [
                                        'success' => 1,
                                        'data'    => $data,
                                    ],
                                    'status'  => 200,
                                ];
                            }

                            // If no compatible session found, auto-abandon any lingering in-progress session for this scope
                            $this->abandonIncompatibleSessionsForScope(
                                $student->id,
                                $offeredProgramId,
                                (int) $validated['subject_id'],
                                $scopeType,
                                $unitIds,
                                null
                            );
                        }

                        $record = $this->createPracticeSessionRecord(
                            $student->id,
                            $offeredProgramId,
                            (int) $validated['subject_id'],
                            $unitIds,
                            $scopeType,
                            (int) $validated['total_questions'],
                            $validated['time_limit_minutes'] ?? null,
                            null,
                            $validated['mcq_format'] ?? 'all',
                            $topicIds,
                            $questionIds,
                            $allowExercise,
                            $allowSupplementary,
                            $scenarioPercentage
                        );

                        $session = $record['session'];

                        // Freemium / Legacy Quota Consumption
                        if (!$decision->isPaid()) {
                            $this->quotaConsumptionService->consumeQuota(
                                $student,
                                'practice',
                                'practice_generation',
                                [
                                    'practice_session_id' => $session->id,
                                    'attempt_number'      => 0,
                                    'idempotency_key'     => $validatedKey,
                                    'request_payload'     => $fingerprintPayload,
                                    'is_legacy_fallback'  => $decision->isLegacyFree(),
                                    'primary_campaign'    => $decision->isFreemium() && $decision->getCampaignId() ? FreemiumCampaign::find($decision->getCampaignId()) : null,
                                ]
                            );
                        }

                        return [
                            'payload' => [
                                'success' => 1,
                                'data'    => $record['data'],
                            ],
                            'status'  => 200,
                        ];
                    });
                }
            );

            return response()->json($result['payload'], $result['status']);
        } catch (IdempotencyConflictException $e) {
            return response()->json([
                'success'    => 0,
                'error'      => $e->getMessage(),
                'error_code' => 'IDEMPOTENCY_CONFLICT',
                'code'       => 'IDEMPOTENCY_CONFLICT',
            ], 409);
        } catch (QuotaLimitExceededException $e) {
            return response()->json([
                'success'       => 0,
                'error'         => $e->getMessage(),
                'error_code'    => 'QUOTA_EXHAUSTED',
                'code'          => 'QUOTA_EXHAUSTED',
                'denial_reason' => $e->getMessage(),
            ], 403);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success'    => 0,
                'error'      => $e->getMessage(),
                'code'       => 'INSUFFICIENT_QUESTIONS',
                'error_code' => 'INSUFFICIENT_QUESTIONS',
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => 0,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function generateFullBookPracticeSessionForLms(Request $request)
    {
        $student = $request->user();
        if (!$student || ($student->role ?? null) !== 'student') {
            return response()->json([
                'success' => 0,
                'error'   => 'Unauthorized. Active student session required.',
            ], 401);
        }

        $idempotencyKey = $request->header('X-Idempotency-Key') ?? $request->input('idempotency_key');
        if (!$idempotencyKey) {
            return response()->json([
                'success' => 0,
                'error'   => 'Missing required X-Idempotency-Key header.',
            ], 422);
        }

        try {
            $validatedKey = $this->idempotencyService->validateKey($idempotencyKey);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => 0,
                'error'   => $e->getMessage(),
            ], 422);
        }

        if ($request->has('mcq_format')) {
            $format = strtolower(trim((string) $request->input('mcq_format')));
            if ($format === 'mcq') {
                $format = 'all';
            }
            $request->merge(['mcq_format' => $format]);
        }

        $scenarioPercentage = 30;
        if ($request->has('scenario_percentage')) {
            $sp = $request->input('scenario_percentage');
            if (!is_numeric($sp) || (int) $sp != $sp || (int) $sp < 0 || (int) $sp > 100 || ((int) $sp % 5 !== 0)) {
                return response()->json([
                    'success'    => 0,
                    'error'      => 'The scenario percentage must be an integer between 0 and 100 in increments of 5.',
                    'error_code' => 'INVALID_SCENARIO_PERCENTAGE',
                ], 422);
            }
            $scenarioPercentage = (int) $sp;
        }

        $validated = $request->validate([
            'offered_program_id' => ['nullable', 'integer', 'min:1', 'exists:offered_programs,id'],
            'subject_id'         => ['required', 'integer', 'exists:subject_tbl,id'],
            'book_id'            => ['required', 'integer', 'exists:book_tbl,id'],
            'total_questions'    => ['required', 'integer', 'min:1'],
            'mcq_format'         => ['nullable', 'string', 'in:all,straight,scenario'],
            'scenario_percentage'=> ['nullable', 'integer', 'min:0', 'max:100'],
            'time_limit_minutes' => ['nullable', 'integer', 'min:1'],
        ]);

        $unitIds = DB::table('book_unit_tbl as units')
            ->join('book_tbl as books', 'books.id', '=', 'units.book_id')
            ->where('units.book_id', $validated['book_id'])
            ->where('books.subject_id', $validated['subject_id'])
            ->where('units.activate', 1)
            ->pluck('units.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($unitIds)) {
            return response()->json([
                'success' => 0,
                'error'   => 'No active units found for the selected book.',
            ], 422);
        }
        sort($unitIds);

        $offeredProgramId = !empty($validated['offered_program_id']) ? (int) $validated['offered_program_id'] : null;

        $targetContext = [
            'offered_program_id' => $offeredProgramId,
            'subject_id'         => (int) $validated['subject_id'],
            'book_id'            => (int) $validated['book_id'],
            'unit_ids'           => $unitIds,
        ];

        // Authoritative 4-tier entitlement decision
        $decision = $this->entitlementResolver->resolve(
            $student,
            $targetContext,
            'perm_mcqs',
            'practice'
        );

        if ($decision->isDenied()) {
            return response()->json([
                'success'       => 0,
                'error'         => $decision->getDenialReason() ?? 'An active subscription is required to access this resource.',
                'code'          => 'ACCESS_DENIED',
                'denial_reason' => $decision->getDenialReason(),
                'diagnostic'    => $decision->getDiagnosticMessage(),
                'upgrade_slug'  => $decision->getUpgradeProgramSlug(),
            ], 403);
        }

        // F-02: Also resolve perm_exercise_questions and perm_supplementary_questions at scope level
        $scopeContext = [
            'offered_program_id' => $offeredProgramId,
            'subject_id'         => (int) $validated['subject_id'],
            'unit_ids'           => $unitIds,
        ];
        $exerciseDecision = $this->entitlementResolver->resolve(
            $student,
            $scopeContext,
            'perm_exercise_questions',
            'practice'
        );
        $supplementaryDecision = $this->entitlementResolver->resolve(
            $student,
            $scopeContext,
            'perm_supplementary_questions',
            'practice'
        );

        $isPaid = $decision->isPaid();
        $denyExercise = !$isPaid && $exerciseDecision->isDenied() && $exerciseDecision->getOrigin() === UnifiedAccessDecision::ORIGIN_FREEMIUM_RULE;
        $denySupplementary = !$isPaid && $supplementaryDecision->isDenied() && $supplementaryDecision->getOrigin() === UnifiedAccessDecision::ORIGIN_FREEMIUM_RULE;

        $allowExercise = $isPaid || !$denyExercise;
        $allowSupplementary = $isPaid || !$denySupplementary;

        if ($denyExercise && $denySupplementary) {
            return response()->json([
                'success'       => 0,
                'error'         => 'Access denied: Both exercise and supplementary questions are restricted under your current subscription or campaign.',
                'code'          => 'ACCESS_DENIED',
                'denial_reason' => 'Both exercise and supplementary questions are denied.',
                'diagnostic'    => 'perm_exercise_questions and perm_supplementary_questions are both denied.',
                'upgrade_slug'  => $exerciseDecision->getUpgradeProgramSlug() ?? $decision->getUpgradeProgramSlug(),
            ], 403);
        }

        $operation = 'practice_session_generation';
        $fingerprintPayload = [
            'operation'          => $operation,
            'offered_program_id' => $offeredProgramId,
            'subject_id'         => (int) $validated['subject_id'],
            'book_id'            => (int) $validated['book_id'],
            'unit_ids'           => $unitIds,
            'total_questions'    => (int) $validated['total_questions'],
            'mcq_format'         => $validated['mcq_format'] ?? 'all',
            'scenario_percentage'=> $scenarioPercentage,
            'scope_type'         => 'full_book',
        ];

        try {
            $result = $this->idempotencyService->execute(
                $student,
                $operation,
                $validatedKey,
                $fingerprintPayload,
                function () use (
                    $student,
                    $decision,
                    $validated,
                    $unitIds,
                    $offeredProgramId,
                    $validatedKey,
                    $fingerprintPayload,
                    $allowExercise,
                    $allowSupplementary,
                    $scenarioPercentage
                ) {
                    return DB::transaction(function () use (
                        $student,
                        $decision,
                        $validated,
                        $unitIds,
                        $offeredProgramId,
                        $validatedKey,
                        $fingerprintPayload,
                        $allowExercise,
                        $allowSupplementary,
                        $scenarioPercentage
                    ) {
                        if (!$decision->isPaid()) {
                            // Pessimistic parent row lock to prevent concurrency bypass
                            WebUser::where('id', $student->id)->lockForUpdate()->first();
                        }

                        // Check if student already has an active (in_progress) session for this scope
                        $activeSession = $this->findActiveSessionForScope(
                            $student->id,
                            $offeredProgramId,
                            (int) $validated['subject_id'],
                            'full_book',
                            $unitIds,
                            (int) $validated['book_id'],
                            (int) $validated['total_questions'],
                            $validated['mcq_format'] ?? 'all'
                        );

                        if ($activeSession !== null) {
                            $data = $this->buildActiveSessionResponse(
                                $activeSession,
                                $unitIds,
                                $validated['mcq_format'] ?? 'all',
                                (int) $validated['book_id']
                            );

                            return [
                                'payload' => [
                                    'success' => 1,
                                    'data'    => $data,
                                ],
                                'status'  => 200,
                            ];
                        }

                        // Auto-abandon incompatible lingering in-progress sessions for full book
                        $this->abandonIncompatibleSessionsForScope(
                            $student->id,
                            $offeredProgramId,
                            (int) $validated['subject_id'],
                            'full_book',
                            $unitIds,
                            (int) $validated['book_id']
                        );

                        $record = $this->createPracticeSessionRecord(
                            $student->id,
                            $offeredProgramId,
                            (int) $validated['subject_id'],
                            $unitIds,
                            'full_book',
                            (int) $validated['total_questions'],
                            $validated['time_limit_minutes'] ?? null,
                            (int) $validated['book_id'],
                            $validated['mcq_format'] ?? 'all',
                            null,
                            null,
                            $allowExercise,
                            $allowSupplementary,
                            $scenarioPercentage
                        );

                        $session = $record['session'];

                        // Freemium / Legacy Quota Consumption
                        if (!$decision->isPaid()) {
                            $this->quotaConsumptionService->consumeQuota(
                                $student,
                                'practice',
                                'practice_generation',
                                [
                                    'practice_session_id' => $session->id,
                                    'attempt_number'      => 0,
                                    'idempotency_key'     => $validatedKey,
                                    'request_payload'     => $fingerprintPayload,
                                    'is_legacy_fallback'  => $decision->isLegacyFree(),
                                    'primary_campaign'    => $decision->isFreemium() && $decision->getCampaignId() ? FreemiumCampaign::find($decision->getCampaignId()) : null,
                                ]
                            );
                        }

                        return [
                            'payload' => [
                                'success' => 1,
                                'data'    => $record['data'],
                            ],
                            'status'  => 200,
                        ];
                    });
                }
            );

            return response()->json($result['payload'], $result['status']);
        } catch (IdempotencyConflictException $e) {
            return response()->json([
                'success'    => 0,
                'error'      => $e->getMessage(),
                'error_code' => 'IDEMPOTENCY_CONFLICT',
                'code'       => 'IDEMPOTENCY_CONFLICT',
            ], 409);
        } catch (QuotaLimitExceededException $e) {
            return response()->json([
                'success'       => 0,
                'error'         => $e->getMessage(),
                'error_code'    => 'QUOTA_EXHAUSTED',
                'code'          => 'QUOTA_EXHAUSTED',
                'denial_reason' => $e->getMessage(),
            ], 403);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success'    => 0,
                'error'      => $e->getMessage(),
                'code'       => 'INSUFFICIENT_QUESTIONS',
                'error_code' => 'INSUFFICIENT_QUESTIONS',
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => 0,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Determine actual MCQ format of an existing session based on its questions.
     */
    protected function determineSessionMcqFormat(int $sessionId): string
    {
        $questionIds = PracticeSessionQuestion::where('session_id', $sessionId)
            ->pluck('question_id')
            ->all();

        if (empty($questionIds)) {
            return 'all';
        }

        $totalCount = count($questionIds);
        $scenarioCount = DB::table('exam_question_tbl')
            ->whereIn('id', $questionIds)
            ->whereNotNull('scenario_group_id')
            ->count();

        if ($scenarioCount === $totalCount) {
            return 'scenario';
        }

        if ($scenarioCount === 0) {
            return 'straight';
        }

        return 'all';
    }

    /**
     * Auto-abandon incompatible in-progress sessions for the given scope.
     */
    protected function abandonIncompatibleSessionsForScope(
        int $userId,
        ?int $offeredProgramId,
        int $subjectId,
        string $scopeType,
        array $unitIds,
        ?int $bookId = null
    ): void {
        sort($unitIds);
        $sortedRequestedUnitIds = array_values($unitIds);

        $candidatesQuery = PracticeSession::query()
            ->where('user_id', $userId)
            ->where('subject_id', $subjectId)
            ->where('scope_type', $scopeType)
            ->where('status', 'in_progress');

        if ($offeredProgramId !== null) {
            $candidatesQuery->where('offered_program_id', $offeredProgramId);
        } else {
            $candidatesQuery->whereNull('offered_program_id');
        }

        $candidates = $candidatesQuery->with('scopes')->get();

        foreach ($candidates as $candidate) {
            $candidateUnitIds = $candidate->scopes->pluck('unit_id')
                ->map(fn ($id) => (int) $id)
                ->sort()
                ->values()
                ->all();

            if ($candidateUnitIds === $sortedRequestedUnitIds) {
                $candidate->update(['status' => 'abandoned']);
            }
        }
    }

    /**
     * Find an existing active (in_progress) practice session for the student matching the requested scope.
     */
    protected function findActiveSessionForScope(
        int $userId,
        ?int $offeredProgramId,
        int $subjectId,
        string $scopeType,
        array $unitIds,
        ?int $bookId = null,
        ?int $requestedTotalQuestions = null,
        ?string $requestedMcqFormat = null
    ): ?PracticeSession {
        sort($unitIds);
        $sortedRequestedUnitIds = array_values($unitIds);

        $candidatesQuery = PracticeSession::query()
            ->where('user_id', $userId)
            ->where('subject_id', $subjectId)
            ->where('scope_type', $scopeType)
            ->where('status', 'in_progress');

        if ($offeredProgramId !== null) {
            $candidatesQuery->where('offered_program_id', $offeredProgramId);
        } else {
            $candidatesQuery->whereNull('offered_program_id');
        }

        $candidates = $candidatesQuery->with('scopes')
            ->orderBy('id', 'desc')
            ->get();

        foreach ($candidates as $candidate) {
            // Auto-abandon stale sessions older than 48 hours
            if ($candidate->created_at && $candidate->created_at->lt(now()->subHours(48))) {
                $candidate->update(['status' => 'abandoned']);
                continue;
            }

            $candidateUnitIds = $candidate->scopes->pluck('unit_id')
                ->map(fn ($id) => (int) $id)
                ->sort()
                ->values()
                ->all();

            if ($candidateUnitIds === $sortedRequestedUnitIds) {
                // If question count requested, validate compatibility
                if ($requestedTotalQuestions !== null && (int) $candidate->total_questions !== $requestedTotalQuestions) {
                    continue;
                }

                // If MCQ format requested, validate actual question format compatibility
                if ($requestedMcqFormat !== null && $requestedMcqFormat !== 'all') {
                    $candidateFormat = $this->determineSessionMcqFormat($candidate->id);
                    if ($candidateFormat !== $requestedMcqFormat) {
                        continue;
                    }
                }

                return $candidate;
            }
        }

        return null;
    }

    /**
     * Build standard response array from an existing active practice session.
     */
    protected function buildActiveSessionResponse(
        PracticeSession $session,
        array $unitIds,
        string $mcqFormat = 'all',
        ?int $bookId = null
    ): array {
        $sessionQuestions = PracticeSessionQuestion::query()
            ->where('session_id', $session->id)
            ->orderBy('question_order', 'asc')
            ->get();

        $questionIds = $sessionQuestions->pluck('question_id')->values()->all();

        $scenarioGroupIds = DB::table('exam_question_tbl')
            ->whereIn('id', $questionIds)
            ->whereNotNull('scenario_group_id')
            ->distinct()
            ->pluck('scenario_group_id')
            ->values()
            ->all();

        $actualFormat = $this->determineSessionMcqFormat($session->id);

        $data = [
            'mcq_format'          => $actualFormat,
            'session_id'          => $session->id,
            'offered_program_id'  => (int) $session->offered_program_id,
            'subject_id'          => (int) $session->subject_id,
            'scope_type'          => $session->scope_type,
            'question_type'       => $session->question_type ?? 'mcq',
            'time_limit_minutes'  => $session->time_limit_minutes,
            'total_questions'     => (int) $session->total_questions,
            'requested_questions' => (int) $session->total_questions,
            'total_scenarios'     => count($scenarioGroupIds),
            'scenario_group_ids'  => $scenarioGroupIds,
            'unit_ids'            => array_values($unitIds),
            'question_ids'        => $questionIds,
            'status'              => $session->status,
            'started_at'          => optional($session->started_at)->toISOString(),
        ];

        $scenarioBlocks = [];
        if (!empty($scenarioGroupIds)) {
            $scenarioGroups = DB::table('question_scenario_groups_tbl')
                ->whereIn('id', $scenarioGroupIds)
                ->get()
                ->keyBy('id');

            $qDetails = DB::table('exam_question_tbl')
                ->whereIn('id', $questionIds)
                ->select(['id', 'question', 'question_um', 'scenario_group_id', 'scenario_question_order'])
                ->get()
                ->keyBy('id');

            foreach ($scenarioGroupIds as $sgId) {
                $sg = $scenarioGroups->get($sgId);
                if (!$sg) continue;

                $groupQuestionList = [];
                $groupQids = [];
                foreach ($sessionQuestions as $sq) {
                    $qd = $qDetails->get($sq->question_id);
                    if ($qd && (int)$qd->scenario_group_id === (int)$sgId) {
                        $groupQids[] = (int) $qd->id;
                        $groupQuestionList[] = [
                            'id'                      => (int) $qd->id,
                            'question_order'          => (int) $sq->question_order,
                            'scenario_question_order' => $qd->scenario_question_order ?? (int) $sq->question_order,
                            'question'                => $qd->question,
                            'question_um'             => $qd->question_um,
                        ];
                    }
                }

                $scenarioBlocks[] = [
                    'scenario_group_id'  => (int) $sg->id,
                    'title'              => $sg->title,
                    'scenario_statement' => $sg->scenario_text,
                    'scenario_text'      => $sg->scenario_text,
                    'scenario_text_um'   => $sg->scenario_text_um,
                    'scenario_image'     => $sg->scenario_image,
                    'question_ids'       => $groupQids,
                    'questions'          => $groupQuestionList,
                ];
            }
        }
        $data['scenario_blocks'] = $scenarioBlocks;
        $data['scenarios'] = $scenarioBlocks;

        if ($bookId !== null) {
            $data['book_id'] = $bookId;
        }

        return $data;
    }

    /**
     * Create database records for a new practice session without committing externally.
     */
    public function createPracticeSessionRecord(
        int $userId,
        ?int $offeredProgramId,
        int $subjectId,
        array $unitIds,
        string $scopeType,
        int $totalQuestions,
        ?int $timeLimitMinutes,
        ?int $bookId = null,
        string $mcqFormat = 'all',
        ?array $topicIds = null,
        ?array $questionIds = null,
        bool $allowExercise = true,
        bool $allowSupplementary = true,
        int $scenarioPercentage = 30
    ): array {
        if (!empty($questionIds)) {
            // Direct selection of specified questions, preserving client-requested order
            $questionsCollection = ExamQuestion::whereIn('id', $questionIds)
                ->where('activate', 1)
                ->get()
                ->keyBy('id');

            $questions = collect($questionIds)
                ->map(fn($qid) => $questionsCollection->get($qid))
                ->filter();

            if ($questions->count() !== count($questionIds)) {
                throw new \InvalidArgumentException('One or more of the requested questions are inactive or could not be resolved.');
            }

            $scenarioGroupIds = $questions->pluck('scenario_group_id')->filter()->unique()->values()->all();
            $requestedQuestions = $totalQuestions;
            $totalQuestions = $questions->count();
        } else {
            $requestedQuestions = $totalQuestions;
            $selection = $this->selectLmsMcqQuestions(
                $unitIds,
                $requestedQuestions,
                $mcqFormat,
                $topicIds,
                $allowExercise,
                $allowSupplementary,
                $scenarioPercentage
            );

            if ($selection === null) {
                throw new \InvalidArgumentException(
                    'Not enough active questions available for the selected chapter and format. Try fewer questions.'
                );
            }

            $questions = $selection['questions'];
            $scenarioGroupIds = $selection['scenario_group_ids'];
            $totalQuestions = $questions->count();
        }

        $session = PracticeSession::create([
            'user_id'                 => $userId,
            'offered_program_id'      => $offeredProgramId,
            'subject_id'              => $subjectId,
            'scope_type'              => $scopeType,
            'question_type'           => 'mcq',
            'time_limit_minutes'      => $timeLimitMinutes,
            'total_questions'         => $totalQuestions,
            'attempted_questions'     => 0,
            'correct_answers'         => 0,
            'wrong_answers'           => 0,
            'not_attempted_questions' => $totalQuestions,
            'score'                   => 0,
            'accuracy'                => 0,
            'started_at'              => now(),
            'status'                  => 'in_progress',
        ]);

        $timestamp = now();

        PracticeSessionScope::insert(
            collect($unitIds)->map(function (int $unitId) use ($session, $timestamp) {
                return [
                    'session_id' => $session->id,
                    'unit_id'    => $unitId,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            })->all()
        );

        PracticeSessionQuestion::insert(
            $questions->values()->map(function ($question, int $index) use ($session, $timestamp) {
                return [
                    'session_id'         => $session->id,
                    'question_id'        => $question->id,
                    'question_order'     => $index + 1,
                    'is_attempted'       => false,
                    'is_correct'         => null,
                    'answer_shown'       => false,
                    'selected_option_id' => null,
                    'time_spent_seconds' => null,
                    'practiced_at'       => null,
                    'created_at'         => $timestamp,
                    'updated_at'         => $timestamp,
                ];
            })->all()
        );

        $scenarioBlocks = [];
        if (!empty($scenarioGroupIds)) {
            $scenarioGroups = DB::table('question_scenario_groups_tbl')
                ->whereIn('id', $scenarioGroupIds)
                ->get()
                ->keyBy('id');

            $qDetails = DB::table('exam_question_tbl')
                ->whereIn('id', $questions->pluck('id')->all())
                ->select(['id', 'question', 'question_um', 'scenario_group_id', 'scenario_question_order'])
                ->get()
                ->keyBy('id');

            foreach ($scenarioGroupIds as $sgId) {
                $sg = $scenarioGroups->get($sgId);
                if (!$sg) continue;

                $groupQuestionList = [];
                $groupQids = [];
                foreach ($questions->values() as $qIdx => $q) {
                    $qd = $qDetails->get($q->id);
                    if ($qd && (int)$qd->scenario_group_id === (int)$sgId) {
                        $groupQids[] = (int) $qd->id;
                        $groupQuestionList[] = [
                            'id'                      => (int) $qd->id,
                            'question_order'          => $qIdx + 1,
                            'scenario_question_order' => $qd->scenario_question_order ?? ($qIdx + 1),
                            'question'                => $qd->question,
                            'question_um'             => $qd->question_um,
                        ];
                    }
                }

                $scenarioBlocks[] = [
                    'scenario_group_id'  => (int) $sg->id,
                    'title'              => $sg->title,
                    'scenario_statement' => $sg->scenario_text,
                    'scenario_text'      => $sg->scenario_text,
                    'scenario_text_um'   => $sg->scenario_text_um,
                    'scenario_image'     => $sg->scenario_image,
                    'question_ids'       => $groupQids,
                    'questions'          => $groupQuestionList,
                ];
            }
        }

        $data = [
            'mcq_format'          => $mcqFormat,
            'scenario_percentage' => $scenarioPercentage,
            'session_id'          => $session->id,
            'offered_program_id'  => $offeredProgramId,
            'subject_id'          => $subjectId,
            'scope_type'          => $scopeType,
            'question_type'       => 'mcq',
            'time_limit_minutes'  => $session->time_limit_minutes,
            'total_questions'     => $session->total_questions,
            'requested_questions' => $requestedQuestions,
            'total_scenarios'     => count($scenarioGroupIds),
            'scenario_group_ids'  => $scenarioGroupIds,
            'scenario_blocks'     => $scenarioBlocks,
            'scenarios'           => $scenarioBlocks,
            'unit_ids'            => array_values($unitIds),
            'topic_ids'           => !empty($topicIds) ? array_values($topicIds) : null,
            'question_ids'        => $questions->pluck('id')->values()->all(),
            'status'              => $session->status,
            'started_at'          => optional($session->started_at)->toISOString(),
        ];

        if ($bookId !== null) {
            $data['book_id'] = $bookId;
        }

        return [
            'session' => $session,
            'data'    => $data,
        ];
    }

    private function createPracticeSessionResponse(
        int $userId,
        int $offeredProgramId,
        int $subjectId,
        array $unitIds,
        string $scopeType,
        int $totalQuestions,
        ?int $timeLimitMinutes,
        ?int $bookId = null,
        string $mcqFormat = 'all',
        int $scenarioPercentage = 30
    ) {
        try {
            DB::beginTransaction();
            $record = $this->createPracticeSessionRecord(
                $userId,
                $offeredProgramId,
                $subjectId,
                $unitIds,
                $scopeType,
                $totalQuestions,
                $timeLimitMinutes,
                $bookId,
                $mcqFormat,
                null,
                null,
                true,
                true,
                $scenarioPercentage
            );
            DB::commit();

            return response()->json([
                'success' => 1,
                'data'    => $record['data'],
            ]);
        } catch (\InvalidArgumentException $e) {
            DB::rollBack();
            return response()->json([
                'success' => 0,
                'error'   => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Milestone C6: Authoritative assessment session hydration for practice sessions.
     * Restores session metadata, ordered questions, student's saved answers, and authoritative timing.
     */
    public function getPracticeSessionForLms(Request $request, int $sessionId)
    {
        $student = $request->user();
        if (!$student || ($student->role ?? null) !== 'student') {
            return response()->json([
                'success' => 0,
                'error'   => 'Unauthorized. Active student session required.',
            ], 401);
        }

        $session = PracticeSession::query()
            ->where('id', $sessionId)
            ->where('user_id', $student->id)
            ->first();

        if (!$session) {
            return response()->json([
                'success' => 0,
                'error'   => 'Practice session not found or does not belong to the student.',
            ], 404);
        }

        $unitIds = PracticeSessionScope::where('session_id', $session->id)
            ->pluck('unit_id')
            ->map('intval')
            ->values()
            ->all();

        $sessionQuestions = DB::table('practice_session_questions as psq')
            ->join('exam_question_tbl as q', 'q.id', '=', 'psq.question_id')
            ->leftJoin('book_unit_topic_tbl as t', 't.id', '=', 'q.topic_id')
            ->where('psq.session_id', $session->id)
            ->select([
                'psq.id as session_question_id',
                'psq.question_id',
                'psq.question_order',
                'psq.selected_option_id',
                'psq.is_attempted',
                'psq.is_correct',
                'psq.answer_shown',
                'psq.time_spent_seconds',
                'psq.practiced_at',
                'q.question',
                'q.question_um',
                'q.explanation',
                'q.explanation_um',
                'q.scenario_group_id',
                'q.scenario_question_order',
                'q.topic_id',
                't.topic_name',
                't.topic_no',
                't.unit_id',
            ])
            ->orderBy('psq.question_order', 'asc')
            ->get();

        $questionIds = $sessionQuestions->pluck('question_id')->all();

        $optionsByQuestion = empty($questionIds)
            ? collect()
            : DB::table('exam_question_options_tbl')
                ->whereIn('question_id', $questionIds)
                ->orderBy('id', 'asc')
                ->get()
                ->groupBy('question_id');

        $scenarioGroupIds = $sessionQuestions
            ->pluck('scenario_group_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $scenariosById = empty($scenarioGroupIds)
            ? collect()
            : DB::table('question_scenario_groups_tbl')
                ->whereIn('id', $scenarioGroupIds)
                ->get()
                ->keyBy('id');

        // Timing calculations
        $timeLimitMinutes = $session->time_limit_minutes ? (int) $session->time_limit_minutes : null;
        $startedAtStr = optional($session->started_at ?? $session->created_at)->toISOString();
        $submittedAtStr = optional($session->submitted_at)->toISOString();
        $remainingSeconds = null;
        $isExpired = false;

        if ($timeLimitMinutes !== null && $timeLimitMinutes > 0) {
            $limitSeconds = $timeLimitMinutes * 60;
            $startTs = Carbon::parse($session->started_at ?? $session->created_at)->timestamp;
            $elapsedSeconds = max(0, Carbon::now('UTC')->timestamp - $startTs);
            $remainingSeconds = max(0, $limitSeconds - $elapsedSeconds);
            if ($session->status !== 'submitted' && $remainingSeconds === 0) {
                $isExpired = true;
            }
        }

        $effectiveStatus = ($session->status !== 'submitted' && $isExpired) ? 'expired' : $session->status;

        $isSubmitted = ($session->status === 'submitted');

        $formattedQuestions = $sessionQuestions->map(function ($sq) use ($optionsByQuestion, $scenariosById, $isSubmitted) {
            $opts = $optionsByQuestion->get($sq->question_id, collect());
            $scenarioRecord = $sq->scenario_group_id ? $scenariosById->get($sq->scenario_group_id) : null;
            $answerShown = (bool) $sq->answer_shown;
            $revealAnswer = $isSubmitted || $answerShown;

            return [
                'id'                      => (int) $sq->question_id,
                'session_question_id'     => (int) $sq->session_question_id,
                'question_order'          => (int) $sq->question_order,
                'question'                => $sq->question,
                'question_um'             => $sq->question_um,
                'explanation'             => $revealAnswer ? $sq->explanation : null,
                'explanation_um'          => $revealAnswer ? $sq->explanation_um : null,
                'topic_id'                => $sq->topic_id ? (int) $sq->topic_id : null,
                'topic_name'              => $sq->topic_name ?? '',
                'topic_no'                => $sq->topic_no ?? '',
                'unit_id'                 => $sq->unit_id ? (int) $sq->unit_id : null,
                'scenario'                => $scenarioRecord ? [
                    'id'               => (int) $scenarioRecord->id,
                    'title'            => $scenarioRecord->title,
                    'scenario_text'    => $scenarioRecord->scenario_text,
                    'scenario_text_um' => $scenarioRecord->scenario_text_um,
                    'scenario_image'   => $scenarioRecord->scenario_image,
                ] : null,
                'scenario_group_id'       => $sq->scenario_group_id ? (int) $sq->scenario_group_id : null,
                'scenario_question_order' => $sq->scenario_question_order ? (int) $sq->scenario_question_order : (int) $sq->question_order,
                'options'                 => $opts->map(function ($opt) use ($revealAnswer) {
                    return [
                        'id'        => (int) $opt->id,
                        'option'    => $opt->option,
                        'option_um' => $opt->option_um,
                        'is_answer' => $revealAnswer ? (bool) $opt->is_answer : null,
                    ];
                })->values()->all(),
                'saved_answer'            => [
                    'selected_option_id' => $sq->selected_option_id ? (int) $sq->selected_option_id : null,
                    'is_attempted'       => (bool) $sq->is_attempted,
                    'is_correct'         => $revealAnswer ? ($sq->is_correct !== null ? (bool) $sq->is_correct : null) : null,
                    'answer_shown'       => (bool) $sq->answer_shown,
                    'time_spent_seconds' => $sq->time_spent_seconds ? (int) $sq->time_spent_seconds : 0,
                    'practiced_at'       => $sq->practiced_at ? Carbon::parse($sq->practiced_at)->toISOString() : null,
                ],
            ];
        })->values()->all();

        return response()->json([
            'success' => 1,
            'data'    => [
                'session_id'          => (int) $session->id,
                'attempt_type'        => 'practice',
                'status'              => $effectiveStatus,
                'subject_id'          => (int) $session->subject_id,
                'offered_program_id'  => $session->offered_program_id ? (int) $session->offered_program_id : null,
                'scope_type'          => $session->scope_type,
                'unit_ids'            => $unitIds,
                'time_limit_minutes'  => $timeLimitMinutes,
                'total_questions'     => (int) $session->total_questions,
                'started_at'          => $startedAtStr,
                'submitted_at'        => $submittedAtStr,
                'remaining_seconds'   => $remainingSeconds,
                'is_expired'          => $isExpired,
                'live_progress'       => [
                    'attempted_questions'     => (int) $session->attempted_questions,
                    'correct_answers'         => (int) $session->correct_answers,
                    'wrong_answers'           => (int) $session->wrong_answers,
                    'not_attempted_questions' => (int) $session->not_attempted_questions,
                    'score'                   => (float) $session->score,
                    'accuracy'                => (float) $session->accuracy,
                ],
                'questions'           => $formattedQuestions,
            ],
        ]);
    }

    /**
     * Abandon an active in-progress practice session for LMS student.
     */
    public function abandonPracticeSessionForLms(Request $request, int $sessionId)
    {
        $student = $request->user();
        if (!$student || ($student->role ?? null) !== 'student') {
            return response()->json([
                'success' => 0,
                'error'   => 'Unauthorized. Active student session required.',
            ], 401);
        }

        $session = PracticeSession::find($sessionId);
        if (!$session) {
            return response()->json([
                'success' => 0,
                'error'   => 'Practice session not found.',
            ], 404);
        }

        if ((int) $session->user_id !== (int) $student->id) {
            return response()->json([
                'success' => 0,
                'error'   => 'Forbidden: You do not own this practice session.',
            ], 403);
        }

        if ($session->status === 'submitted') {
            return response()->json([
                'success' => 0,
                'error'   => 'Completed sessions cannot be abandoned.',
            ], 409);
        }

        if ($session->status === 'abandoned') {
            return response()->json([
                'success'    => 1,
                'message'    => 'Practice session is already abandoned.',
                'session_id' => $sessionId,
                'status'     => 'abandoned',
            ], 200);
        }

        $session->update(['status' => 'abandoned']);

        return response()->json([
            'success'    => 1,
            'message'    => 'Practice session abandoned.',
            'session_id' => $sessionId,
            'status'     => 'abandoned',
        ], 200);
    }

    public function savePracticeSessionQuestionProgressForLms(Request $request)
    {
        $student = $request->user();
        if (!$student || ($student->role ?? null) !== 'student') {
            return response()->json([
                'success' => 0,
                'error'   => 'Unauthorized. Active student session required.',
            ], 401);
        }

        $validated = $request->validate([
            'session_id'         => ['required', 'integer'],
            'question_id'        => ['required', 'integer', 'exists:exam_question_tbl,id'],
            'selected_option_id' => ['nullable', 'integer', 'exists:exam_question_options_tbl,id'],
            'time_spent_seconds' => ['nullable', 'integer', 'min:0'],
            'answer_shown'       => ['nullable', 'boolean'],
            'practiced_at'       => ['nullable', 'date'],
        ]);

        try {
            $session = PracticeSession::query()
                ->where('id', $validated['session_id'])
                ->where('user_id', $student->id)
                ->first();

            if (!$session) {
                return response()->json([
                    'success' => 0,
                    'error'   => 'Practice session not found or does not belong to the student.',
                ], 404);
            }

            if ($session->status === 'submitted') {
                return response()->json([
                    'success' => 0,
                    'error'   => 'Cannot update progress on an already submitted practice session.',
                ], 422);
            }

            if ($this->isSessionExpired($session)) {
                return response()->json([
                    'success'    => 0,
                    'error'      => 'Practice session has expired and can no longer be modified.',
                    'error_code' => 'SESSION_EXPIRED',
                ], 422);
            }

            $sessionQuestionRow = $this->getSessionQuestionRow($session->id, (int) $validated['question_id']);

            if (!$sessionQuestionRow) {
                return response()->json([
                    'success' => 0,
                    'error'   => 'Selected question does not belong to the practice session.',
                ], 422);
            }

            return DB::transaction(function () use ($session, $sessionQuestionRow, $validated) {
                $lockedSession = PracticeSession::where('id', $session->id)
                    ->lockForUpdate()
                    ->first();

                if ($lockedSession->status === 'submitted') {
                    return response()->json([
                        'success' => 0,
                        'error'   => 'Cannot update progress on an already submitted practice session.',
                    ], 422);
                }

                if ($this->isSessionExpired($lockedSession)) {
                    return response()->json([
                        'success'    => 0,
                        'error'      => 'Practice session has expired and can no longer be modified.',
                        'error_code' => 'SESSION_EXPIRED',
                    ], 422);
                }

                $selectedOptionId = $validated['selected_option_id'] ?? null;
                $isAttempted = !empty($selectedOptionId);
                $isCorrect = $isAttempted
                    ? ((int) $selectedOptionId === (int) $sessionQuestionRow->correct_option_id)
                    : null;

                PracticeSessionQuestion::query()
                    ->where('id', $sessionQuestionRow->session_question_id)
                    ->update([
                        'selected_option_id' => $selectedOptionId,
                        'is_attempted'       => $isAttempted,
                        'is_correct'         => $isCorrect,
                        'answer_shown'       => (bool) ($validated['answer_shown'] ?? false),
                        'time_spent_seconds' => $validated['time_spent_seconds'] ?? null,
                        'practiced_at'       => $isAttempted
                            ? (isset($validated['practiced_at'])
                                ? $this->normalizeStandardDateTime($validated['practiced_at'])
                                : $this->normalizeStandardDateTime(now()))
                            : null,
                    ]);

                $this->refreshPracticeSessionProgress($lockedSession);
                $lockedSession->refresh();

                return response()->json([
                    'success' => 1,
                    'data'    => [
                        'session_id'              => $lockedSession->id,
                        'question_id'             => (int) $sessionQuestionRow->question_id,
                        'is_attempted'            => $isAttempted,
                        'is_correct'              => $isCorrect,
                        'status'                  => $lockedSession->status,
                        'attempted_questions'     => (int) $lockedSession->attempted_questions,
                        'correct_answers'         => (int) $lockedSession->correct_answers,
                        'wrong_answers'           => (int) $lockedSession->wrong_answers,
                        'not_attempted_questions' => (int) $lockedSession->not_attempted_questions,
                        'score'                   => (float) $lockedSession->score,
                        'accuracy'                => (float) $lockedSession->accuracy,
                    ],
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function submitPracticeSessionForLms(Request $request)
    {
        $student = $request->user();
        if (!$student || ($student->role ?? null) !== 'student') {
            return response()->json([
                'success' => 0,
                'error'   => 'Unauthorized. Active student session required.',
            ], 401);
        }

        $validated = $request->validate([
            'session_id'                     => ['required', 'integer'],
            'submitted_at'                   => ['nullable', 'date'],
            'questions'                      => ['nullable', 'array'],
            'questions.*.question_id'        => ['required_with:questions', 'integer', 'exists:exam_question_tbl,id'],
            'questions.*.selected_option_id' => ['nullable', 'integer', 'exists:exam_question_options_tbl,id'],
            'questions.*.time_spent_seconds' => ['nullable', 'integer', 'min:0'],
            'questions.*.answer_shown'       => ['nullable', 'boolean'],
        ]);

        try {
            $session = PracticeSession::query()
                ->where('id', $validated['session_id'])
                ->where('user_id', $student->id)
                ->first();

            if (!$session) {
                return response()->json([
                    'success' => 0,
                    'error'   => 'Practice session not found or does not belong to the student.',
                ], 404);
            }

            // Repeated submission check: return idempotent replay
            if ($session->status === 'submitted') {
                return response()->json([
                    'success' => 1,
                    'data'    => [
                        'session_id'              => $session->id,
                        'attempted_questions'     => (int) $session->attempted_questions,
                        'correct_answers'         => (int) $session->correct_answers,
                        'wrong_answers'           => (int) $session->wrong_answers,
                        'not_attempted_questions' => (int) $session->not_attempted_questions,
                        'score'                   => (float) $session->score,
                        'accuracy'                => (float) $session->accuracy,
                        'submitted_at'            => optional($session->submitted_at)->toISOString(),
                        'status'                  => $session->status,
                    ],
                ], 200);
            }

            return DB::transaction(function () use ($student, $session, $validated) {
                $lockedSession = PracticeSession::where('id', $session->id)
                    ->lockForUpdate()
                    ->first();

                if ($lockedSession->status === 'submitted') {
                    return response()->json([
                        'success' => 1,
                        'data'    => [
                            'session_id'              => $lockedSession->id,
                            'attempted_questions'     => (int) $lockedSession->attempted_questions,
                            'correct_answers'         => (int) $lockedSession->correct_answers,
                            'wrong_answers'           => (int) $lockedSession->wrong_answers,
                            'not_attempted_questions' => (int) $lockedSession->not_attempted_questions,
                            'score'                   => (float) $lockedSession->score,
                            'accuracy'                => (float) $lockedSession->accuracy,
                            'submitted_at'            => optional($lockedSession->submitted_at)->toISOString(),
                            'status'                  => $lockedSession->status,
                        ],
                    ], 200);
                }

                $submittedAt = isset($validated['submitted_at'])
                    ? $this->normalizeStandardDateTime($validated['submitted_at'])
                    : $this->normalizeStandardDateTime($lockedSession->submitted_at ?? now());

                $submittedQuestionMap = isset($validated['questions'])
                    ? collect($validated['questions'])->keyBy(fn (array $question) => (int) $question['question_id'])
                    : collect();

                $sessionQuestionRows = $this->getAllSessionQuestionRows($lockedSession->id);

                if ($sessionQuestionRows->isEmpty()) {
                    return response()->json([
                        'success' => 0,
                        'error'   => 'No questions are attached to the selected practice session.',
                    ], 422);
                }

                $attemptedQuestions = 0;
                $correctAnswers = 0;
                $wrongAnswers = 0;
                $perUnitIds = [];

                $isSessionExpired = $this->isSessionExpired($lockedSession);

                foreach ($sessionQuestionRows as $sessionQuestionRow) {
                    $submittedQuestion = !$isSessionExpired
                        ? $submittedQuestionMap->get((int) $sessionQuestionRow->question_id)
                        : null;

                    if ($submittedQuestion) {
                        $selectedOptionId = $submittedQuestion['selected_option_id'] ?? null;
                        $isAttempted = !empty($selectedOptionId);
                        $isCorrect = $isAttempted
                            ? ((int) $selectedOptionId === (int) $sessionQuestionRow->correct_option_id)
                            : null;

                        PracticeSessionQuestion::query()
                            ->where('id', $sessionQuestionRow->session_question_id)
                            ->update([
                                'selected_option_id' => $selectedOptionId,
                                'is_attempted'       => $isAttempted,
                                'is_correct'         => $isCorrect,
                                'answer_shown'       => (bool) ($submittedQuestion['answer_shown'] ?? false),
                                'time_spent_seconds' => $submittedQuestion['time_spent_seconds'] ?? null,
                                'practiced_at'       => $isAttempted ? $submittedAt : null,
                                'updated_at'         => now(),
                            ]);
                    }

                    $perUnitIds[(int) $sessionQuestionRow->unit_id] = true;
                }

                $storedSessionQuestions = PracticeSessionQuestion::query()
                    ->where('session_id', $lockedSession->id)
                    ->get()
                    ->keyBy('question_id');

                foreach ($sessionQuestionRows as $sessionQuestionRow) {
                    $storedSessionQuestion = $storedSessionQuestions->get((int) $sessionQuestionRow->question_id);

                    if (!$storedSessionQuestion) {
                        continue;
                    }

                    $isAttempted = (bool) $storedSessionQuestion->is_attempted;
                    $isCorrect = $storedSessionQuestion->is_correct;
                    $selectedOptionId = $storedSessionQuestion->selected_option_id;
                    $answerShown = (bool) $storedSessionQuestion->answer_shown;

                    if ($isAttempted) {
                        $attemptedQuestions++;

                        if ($isCorrect) {
                            $correctAnswers++;
                        } else {
                            $wrongAnswers++;
                        }
                    }

                    $this->updateQuestionStatisticForPractice(
                        (int) $sessionQuestionRow->question_id,
                        $isAttempted,
                        $isCorrect,
                        $submittedAt
                    );

                    if ($selectedOptionId) {
                        $this->updateQuestionOptionStatisticForPractice(
                            (int) $sessionQuestionRow->question_id,
                            (int) $selectedOptionId,
                            $answerShown
                        );
                    }

                    $this->updateStudentQuestionProgressSummaryForPractice(
                        $student->id,
                        (int) $sessionQuestionRow->question_id,
                        (int) $lockedSession->offered_program_id,
                        (int) $lockedSession->subject_id,
                        (int) $sessionQuestionRow->unit_id,
                        $isAttempted,
                        $isCorrect,
                        $submittedAt
                    );
                }

                $notAttemptedQuestions = $sessionQuestionRows->count() - $attemptedQuestions;
                $accuracy = $attemptedQuestions > 0
                    ? round(($correctAnswers / $attemptedQuestions) * 100, 2)
                    : 0;

                $lockedSession->update([
                    'attempted_questions'     => $attemptedQuestions,
                    'correct_answers'         => $correctAnswers,
                    'wrong_answers'           => $wrongAnswers,
                    'not_attempted_questions' => $notAttemptedQuestions,
                    'score'                   => round($correctAnswers, 2),
                    'accuracy'                => $accuracy,
                    'submitted_at'            => $submittedAt,
                    'status'                  => 'submitted',
                ]);

                $this->refreshStudentSubjectProgressSummaryForPractice(
                    $student->id,
                    (int) $lockedSession->offered_program_id,
                    (int) $lockedSession->subject_id,
                    $submittedAt
                );

                foreach (array_keys($perUnitIds) as $unitId) {
                    $this->refreshStudentUnitProgressSummaryForPractice(
                        $student->id,
                        (int) $lockedSession->offered_program_id,
                        (int) $unitId,
                        $submittedAt
                    );
                }

                $activityContext = $this->buildPracticeActivityContext(
                    $lockedSession,
                    array_keys($perUnitIds)
                );

                $this->recordStudentActivity(
                    $student->id,
                    'practice_completed',
                    $this->buildPracticeActivityTitle($lockedSession->scope_type),
                    $activityContext['description'],
                    (int) $lockedSession->offered_program_id,
                    (int) $lockedSession->subject_id,
                    count($perUnitIds) === 1 ? (int) array_key_first($perUnitIds) : null,
                    $lockedSession->id,
                    'practice_session',
                    [
                        'subject_name'            => $activityContext['subject_name'],
                        'scope_label'             => $activityContext['scope_label'],
                        'unit_labels'             => $activityContext['unit_labels'],
                        'scope_type'              => $lockedSession->scope_type,
                        'total_questions'         => $sessionQuestionRows->count(),
                        'attempted_questions'     => $attemptedQuestions,
                        'correct_answers'         => $correctAnswers,
                        'wrong_answers'           => $wrongAnswers,
                        'not_attempted_questions' => $notAttemptedQuestions,
                        'score'                   => round($correctAnswers, 2),
                        'accuracy'                => $accuracy,
                    ],
                    $submittedAt
                );

                return response()->json([
                    'success' => 1,
                    'data'    => [
                        'session_id'              => $lockedSession->id,
                        'attempted_questions'     => $attemptedQuestions,
                        'correct_answers'         => $correctAnswers,
                        'wrong_answers'           => $wrongAnswers,
                        'not_attempted_questions' => $notAttemptedQuestions,
                        'score'                   => round($correctAnswers, 2),
                        'accuracy'                => $accuracy,
                        'submitted_at'            => $submittedAt->toISOString(),
                        'status'                  => $lockedSession->status,
                    ],
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    private function getAllSessionQuestionRows(int $sessionId)
    {
        return DB::table('practice_session_questions as psq')
            ->join('exam_question_tbl as questions', 'questions.id', '=', 'psq.question_id')
            ->join('book_unit_topic_tbl as topics', 'topics.id', '=', 'questions.topic_id')
            ->leftJoin('exam_question_options_tbl as options', function ($join) {
                $join->on('options.question_id', '=', 'questions.id')
                    ->where('options.is_answer', 1);
            })
            ->where('psq.session_id', $sessionId)
            ->select(
                'psq.id as session_question_id',
                'psq.question_id',
                'psq.question_order',
                'topics.unit_id',
                'questions.topic_id',
                'options.id as correct_option_id'
            )
            ->orderBy('psq.question_order')
            ->get();
    }

    private function getSessionQuestionRow(int $sessionId, int $questionId): ?object
    {
        return $this->getAllSessionQuestionRows($sessionId)
            ->firstWhere('question_id', $questionId);
    }

    private function refreshPracticeSessionProgress(PracticeSession $session): void
    {
        $sessionQuestions = PracticeSessionQuestion::query()
            ->where('session_id', $session->id)
            ->get();

        $attemptedQuestions = $sessionQuestions->where('is_attempted', true)->count();
        $correctAnswers = $sessionQuestions->where('is_correct', true)->count();
        $wrongAnswers = $sessionQuestions->where('is_attempted', true)->where('is_correct', false)->count();
        $notAttemptedQuestions = max($session->total_questions - $attemptedQuestions, 0);
        $score = round((float) $correctAnswers, 2);
        $accuracy = $attemptedQuestions > 0 ? round(($correctAnswers / $attemptedQuestions) * 100, 2) : 0;

        $session->update([
            'attempted_questions' => $attemptedQuestions,
            'correct_answers' => $correctAnswers,
            'wrong_answers' => $wrongAnswers,
            'not_attempted_questions' => $notAttemptedQuestions,
            'score' => $score,
            'accuracy' => $accuracy,
        ]);
    }

    private function updateQuestionStatisticForPractice(int $questionId, bool $isAttempted, ?bool $isCorrect, Carbon $submittedAt): void
    {
        $questionStatistic = QuestionStatistic::firstOrCreate(
            ['question_id' => $questionId],
            [
                'attempt_count' => 0,
                'correct_count' => 0,
                'wrong_count' => 0,
                'skip_count' => 0,
                'is_calibrated' => false,
            ]
        );

        if ($isAttempted) {
            $questionStatistic->attempt_count += 1;

            if ($isCorrect) {
                $questionStatistic->correct_count += 1;
            } else {
                $questionStatistic->wrong_count += 1;
            }
        } else {
            $questionStatistic->skip_count += 1;
        }

        $totalAnswered = $questionStatistic->correct_count + $questionStatistic->wrong_count;
        if ($totalAnswered > 0) {
            $questionStatistic->difficulty_index = round(($questionStatistic->correct_count / $totalAnswered) * 100, 2);
            $questionStatistic->computed_difficulty_band = $questionStatistic->difficulty_index >= 75
                ? 'easy'
                : ($questionStatistic->difficulty_index >= 40 ? 'medium' : 'hard');
        }

        $questionStatistic->last_calculated_at = $submittedAt;
        $questionStatistic->save();
    }

    private function updateQuestionOptionStatisticForPractice(int $questionId, int $optionId, bool $answerShown): void
    {
        $questionOptionStatistic = QuestionOptionStatistic::firstOrCreate(
            [
                'question_id' => $questionId,
                'option_id' => $optionId,
            ],
            [
                'selection_count' => 0,
                'practice_selection_count' => 0,
                'formal_selection_count' => 0,
                'answer_shown_selection_count' => 0,
            ]
        );

        $questionOptionStatistic->selection_count += 1;
        $questionOptionStatistic->practice_selection_count += 1;

        if ($answerShown) {
            $questionOptionStatistic->answer_shown_selection_count += 1;
        }

        $questionOptionStatistic->save();
    }

    private function updateStudentQuestionProgressSummaryForPractice(
        int $userId,
        int $questionId,
        int $offeredProgramId,
        int $subjectId,
        int $unitId,
        bool $isAttempted,
        ?bool $isCorrect,
        Carbon $submittedAt
    ): void {
        $summary = StudentQuestionProgressSummary::firstOrCreate(
            [
                'user_id' => $userId,
                'question_id' => $questionId,
            ],
            [
                'offered_program_id' => $offeredProgramId,
                'subject_id' => $subjectId,
                'unit_id' => $unitId,
                'practice_attempts' => 0,
                'practice_correct' => 0,
                'practice_wrong' => 0,
                'formal_attempts' => 0,
                'formal_correct' => 0,
                'formal_wrong' => 0,
                'is_mastered' => false,
            ]
        );

        $summary->offered_program_id = $offeredProgramId;
        $summary->subject_id = $subjectId;
        $summary->unit_id = $unitId;

        if ($isAttempted) {
            $summary->practice_attempts += 1;

            if ($isCorrect) {
                $summary->practice_correct += 1;
            } else {
                $summary->practice_wrong += 1;
            }
        }

        $summary->last_practiced_at = $submittedAt;

        $totalAttempts = $summary->practice_attempts + $summary->formal_attempts;
        $totalCorrect = $summary->practice_correct + $summary->formal_correct;
        $combinedAccuracy = $totalAttempts > 0 ? ($totalCorrect / $totalAttempts) * 100 : 0;

        $summary->is_mastered = $totalAttempts >= self::MASTERED_MIN_ATTEMPTS
            && $combinedAccuracy >= self::MASTERED_MIN_ACCURACY;

        $summary->save();
    }

    private function refreshStudentSubjectProgressSummaryForPractice(
        int $userId,
        int $offeredProgramId,
        int $subjectId,
        Carbon $submittedAt
    ): void {
        $aggregated = StudentQuestionProgressSummary::query()
            ->where('user_id', $userId)
            ->where('offered_program_id', $offeredProgramId)
            ->where('subject_id', $subjectId)
            ->selectRaw('
                COALESCE(SUM(practice_attempts), 0) as practice_attempted,
                COALESCE(SUM(practice_correct), 0) as practice_correct,
                COALESCE(SUM(practice_wrong), 0) as practice_wrong,
                COALESCE(SUM(formal_attempts), 0) as formal_attempted,
                COALESCE(SUM(formal_correct), 0) as formal_correct,
                COALESCE(SUM(formal_wrong), 0) as formal_wrong,
                COUNT(DISTINCT CASE
                    WHEN COALESCE(practice_attempts, 0) + COALESCE(formal_attempts, 0) > 0
                    THEN question_id
                END) as distinct_questions_seen
            ')
            ->first();

        StudentSubjectProgressSummary::updateOrCreate(
            [
                'user_id' => $userId,
                'offered_program_id' => $offeredProgramId,
                'subject_id' => $subjectId,
            ],
            [
                'total_questions' => $this->countSubjectMcqs($subjectId),
                'practice_attempted' => (int) ($aggregated->practice_attempted ?? 0),
                'practice_correct' => (int) ($aggregated->practice_correct ?? 0),
                'practice_wrong' => (int) ($aggregated->practice_wrong ?? 0),
                'formal_attempted' => (int) ($aggregated->formal_attempted ?? 0),
                'formal_correct' => (int) ($aggregated->formal_correct ?? 0),
                'formal_wrong' => (int) ($aggregated->formal_wrong ?? 0),
                'distinct_questions_seen' => (int) ($aggregated->distinct_questions_seen ?? 0),
                'practice_accuracy' => $this->calculatePercentage(
                    (int) ($aggregated->practice_correct ?? 0),
                    (int) ($aggregated->practice_attempted ?? 0)
                ),
                'formal_accuracy' => $this->calculatePercentage(
                    (int) ($aggregated->formal_correct ?? 0),
                    (int) ($aggregated->formal_attempted ?? 0)
                ),
                'last_practiced_at' => $submittedAt,
            ]
        );
    }

    private function refreshStudentUnitProgressSummaryForPractice(
        int $userId,
        int $offeredProgramId,
        int $unitId,
        Carbon $submittedAt
    ): void {
        $aggregated = StudentQuestionProgressSummary::query()
            ->where('user_id', $userId)
            ->where('offered_program_id', $offeredProgramId)
            ->where('unit_id', $unitId)
            ->selectRaw('
                COALESCE(SUM(practice_attempts), 0) as practice_attempted,
                COALESCE(SUM(practice_correct), 0) as practice_correct,
                COALESCE(SUM(practice_wrong), 0) as practice_wrong,
                COALESCE(SUM(formal_attempts), 0) as formal_attempted,
                COALESCE(SUM(formal_correct), 0) as formal_correct,
                COALESCE(SUM(formal_wrong), 0) as formal_wrong,
                COUNT(DISTINCT CASE
                    WHEN COALESCE(practice_attempts, 0) + COALESCE(formal_attempts, 0) > 0
                    THEN question_id
                END) as distinct_questions_seen
            ')
            ->first();

        StudentUnitProgressSummary::updateOrCreate(
            [
                'user_id' => $userId,
                'offered_program_id' => $offeredProgramId,
                'unit_id' => $unitId,
            ],
            [
                'total_questions' => $this->countUnitMcqs($unitId),
                'practice_attempted' => (int) ($aggregated->practice_attempted ?? 0),
                'practice_correct' => (int) ($aggregated->practice_correct ?? 0),
                'practice_wrong' => (int) ($aggregated->practice_wrong ?? 0),
                'formal_attempted' => (int) ($aggregated->formal_attempted ?? 0),
                'formal_correct' => (int) ($aggregated->formal_correct ?? 0),
                'formal_wrong' => (int) ($aggregated->formal_wrong ?? 0),
                'distinct_questions_seen' => (int) ($aggregated->distinct_questions_seen ?? 0),
                'practice_accuracy' => $this->calculatePercentage(
                    (int) ($aggregated->practice_correct ?? 0),
                    (int) ($aggregated->practice_attempted ?? 0)
                ),
                'formal_accuracy' => $this->calculatePercentage(
                    (int) ($aggregated->formal_correct ?? 0),
                    (int) ($aggregated->formal_attempted ?? 0)
                ),
                'last_practiced_at' => $submittedAt,
            ]
        );
    }

    private function countSubjectMcqs(int $subjectId): int
    {
        return DB::table('exam_question_tbl as questions')
            ->join('book_unit_topic_tbl as topics', 'topics.id', '=', 'questions.topic_id')
            ->join('book_unit_tbl as units', 'units.id', '=', 'topics.unit_id')
            ->join('book_tbl as books', 'books.id', '=', 'units.book_id')
            ->where('books.subject_id', $subjectId)
            ->where('questions.is_mcq', 1)
            ->where('questions.activate', 1)
            ->distinct()
            ->count('questions.id');
    }

    private function countUnitMcqs(int $unitId): int
    {
        return DB::table('exam_question_tbl as questions')
            ->join('book_unit_topic_tbl as topics', 'topics.id', '=', 'questions.topic_id')
            ->where('topics.unit_id', $unitId)
            ->where('questions.is_mcq', 1)
            ->where('questions.activate', 1)
            ->distinct()
            ->count('questions.id');
    }

    private function calculatePercentage(int $correct, int $attempted): float
    {
        if ($attempted <= 0) {
            return 0;
        }

        return round(($correct / $attempted) * 100, 2);
    }

    private function buildPracticeActivityTitle(?string $scopeType): string
    {
        return match ($scopeType) {
            'chapter' => 'Chapter Practice Completed',
            'multiple_chapters' => 'Selected Units Practice Completed',
            'full_book' => 'Full Book Practice Completed',
            default => 'Practice Session Completed',
        };
    }

    private function buildPracticeActivityContext(PracticeSession $session, array $unitIds): array
    {
        $subjectName = Subject::query()
            ->where('id', $session->subject_id)
            ->value('subject_name');

        $unitLabels = DB::table('book_unit_tbl')
            ->whereIn('id', $unitIds)
            ->orderBy('unit_no')
            ->get(['unit_no', 'unit_name'])
            ->map(function ($unit) {
                $prefix = $unit->unit_no !== null ? "Unit {$unit->unit_no}" : 'Unit';
                return $unit->unit_name ? "{$prefix} - {$unit->unit_name}" : $prefix;
            })
            ->values()
            ->all();

        $scopeLabel = match ($session->scope_type) {
            'chapter' => $unitLabels[0] ?? 'selected chapter',
            'multiple_chapters' => count($unitLabels) <= 3 && count($unitLabels) > 0
                ? implode(', ', $unitLabels)
                : count($unitIds) . ' chapters',
            'full_book' => 'full book',
            default => 'selected scope',
        };

        $descriptionParts = array_filter([
            'Completed a practice session',
            $subjectName ? 'for ' . $subjectName : null,
            $scopeLabel ? 'covering ' . $scopeLabel : null,
        ]);

        return [
            'subject_name' => $subjectName,
            'scope_label' => $scopeLabel,
            'unit_labels' => $unitLabels,
            'description' => rtrim(implode(' ', $descriptionParts), '.') . '.',
        ];
    }

    private function recordStudentActivity(
        int $userId,
        string $activityType,
        string $title,
        ?string $description,
        ?int $offeredProgramId,
        ?int $subjectId,
        ?int $unitId,
        ?int $referenceId,
        ?string $referenceType,
        array $meta,
        Carbon $activityAt
    ): void {
        $activity = StudentActivity::firstOrNew([
            'user_id' => $userId,
            'activity_type' => $activityType,
            'reference_id' => $referenceId,
            'reference_type' => $referenceType,
        ]);

        $activity->fill([
            'title' => $title,
            'description' => $description,
            'offered_program_id' => $offeredProgramId,
            'subject_id' => $subjectId,
            'unit_id' => $unitId,
            'meta' => $meta,
        ]);

        if (!$activity->exists || !$activity->activity_at) {
            $activity->activity_at = $this->normalizeStandardDateTime($activityAt);
        }

        $activity->save();
    }

    protected function isSessionExpired(?PracticeSession $session): bool
    {
        if (!$session) {
            return false;
        }

        if ($session->status === 'expired') {
            return true;
        }

        if ($session->status === 'submitted') {
            return false;
        }

        $timeLimitMinutes = $session->time_limit_minutes ? (int) $session->time_limit_minutes : null;
        if ($timeLimitMinutes !== null && $timeLimitMinutes > 0) {
            $limitSeconds = $timeLimitMinutes * 60;
            $startTs = Carbon::parse($session->started_at ?? $session->created_at)->timestamp;
            $elapsedSeconds = Carbon::now('UTC')->timestamp - $startTs;
            return $elapsedSeconds >= $limitSeconds;
        }

        return false;
    }

    private function normalizeStandardDateTime($value): Carbon
    {
        return $value instanceof Carbon
            ? $value->copy()->utc()
            : Carbon::parse($value)->utc();
    }
}
