<?php

namespace App\Http\Controllers\Tests;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tests\Concerns\SelectsLmsMcqQuestions;
use App\Models\QuestionOptionStatistic;
use App\Models\QuestionStatistic;
use App\Models\StudentActivity;
use App\Models\StudentQuestionProgressSummary;
use App\Models\StudentSubjectProgressSummary;
use App\Models\StudentUnitProgressSummary;
use App\Models\Subject;
use App\Models\Test;
use App\Models\TestAttempt;
use App\Models\TestAttemptQuestion;
use App\Models\TestQuestion;
use App\Models\TestScope;
use App\Models\WebUser;
use App\Models\Freemium\FreemiumQuotaConsumption;
use App\Http\Services\Freemium\AssessmentIdempotencyService;
use App\Http\Services\Freemium\Exceptions\IdempotencyConflictException;
use App\Http\Services\Freemium\Exceptions\QuotaLimitExceededException;
use App\Http\Services\Freemium\FreemiumQuotaConsumptionService;
use App\Http\Services\Freemium\UnifiedEntitlementResolver;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumExamQuota;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TestController extends Controller
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

    public function generateSelectedUnitsFormalTestForLms(Request $request)
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

        if ($request->input('requested_mode') === 'mock' || $request->input('mode') === 'mock') {
            return response()->json([
                'success'    => 0,
                'error'      => 'Mock examinations are not permitted for chapter or selected unit scope. Mock examinations require full-book comprehensive syllabus coverage.',
                'error_code' => 'INVALID_ASSESSMENT_SCOPE',
            ], 422);
        }

        $scenarioPercentage = 30;
        if ($request->has('scenario_percentage')) {
            $sp = $request->input('scenario_percentage');
            if (!is_numeric($sp) || (int) $sp != $sp || (int) $sp < 0 || (int) $sp > 100 || ((int) $sp % 5 !== 0)) {
                return response()->json([
                    'success' => 0,
                    'error'   => 'The scenario percentage must be an integer between 0 and 100 in increments of 5.',
                ], 422);
            }
            $scenarioPercentage = (int) $sp;
        }

        $validated = $request->validate([
            'offered_program_id' => ['nullable', 'integer', 'min:1', 'exists:offered_programs,id'],
            'subject_id'         => ['required', 'integer', 'exists:subject_tbl,id'],
            'unit_ids'           => ['required', 'array', 'min:1'],
            'unit_ids.*'         => ['integer', 'exists:book_unit_tbl,id'],
            'total_questions'    => ['required', 'integer', 'min:1'],
            'mcq_format'         => ['nullable', 'string', 'in:all,straight,scenario'],
            'scenario_percentage'=> ['nullable', 'integer', 'min:0', 'max:100'],
            'time_limit_minutes' => ['nullable', 'integer', 'min:1'],
            'title'              => ['nullable', 'string', 'max:255'],
            'description'        => ['nullable', 'string', 'max:255'],
            'marks_per_question' => ['nullable', 'numeric', 'min:0'],
            'scope_type'         => ['nullable', 'string', 'in:chapter,multiple_chapters,full_book'],
        ]);

        $unitIds = array_values(array_unique($validated['unit_ids']));
        $scopeType = $validated['scope_type']
            ?? (count($unitIds) === 1 ? 'chapter' : 'multiple_chapters');

        $offeredProgramId = !empty($validated['offered_program_id']) ? (int) $validated['offered_program_id'] : null;

        $targetContext = [
            'offered_program_id' => $offeredProgramId,
            'subject_id'         => (int) $validated['subject_id'],
            'unit_ids'           => $unitIds,
        ];

        // Authoritative 4-tier entitlement decision
        $decision = $this->entitlementResolver->resolve(
            $student,
            $targetContext,
            'perm_mcqs',
            'formal_test'
        );

        if ($decision->isDenied()) {
            return response()->json([
                'success'       => 0,
                'error'         => $decision->getDenialReason() ?? 'An active subscription is required to access this resource.',
                'denial_reason' => $decision->getDenialReason(),
                'diagnostic'    => $decision->getDiagnosticMessage(),
                'upgrade_slug'  => $decision->getUpgradeProgramSlug(),
            ], 403);
        }

        $operation = 'formal_test_generation';
        $fingerprintPayload = [
            'operation'          => $operation,
            'offered_program_id' => $offeredProgramId,
            'subject_id'         => (int) $validated['subject_id'],
            'unit_ids'           => $unitIds,
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
                    $scopeType,
                    $offeredProgramId,
                    $validatedKey,
                    $fingerprintPayload,
                    $scenarioPercentage
                ) {
                    return DB::transaction(function () use (
                        $student,
                        $decision,
                        $validated,
                        $unitIds,
                        $scopeType,
                        $offeredProgramId,
                        $validatedKey,
                        $fingerprintPayload,
                        $scenarioPercentage
                    ) {
                        if (!$decision->isPaid()) {
                            // Pessimistic parent row lock to prevent concurrency bypass
                            \App\Models\WebUser::where('id', $student->id)->lockForUpdate()->first();
                        }

                        $record = $this->generateFormalTestRecord(
                            $offeredProgramId,
                            (int) $validated['subject_id'],
                            $unitIds,
                            $scopeType,
                            (int) $validated['total_questions'],
                            $validated['time_limit_minutes'] ?? null,
                            $validated['title'] ?? null,
                            $validated['description'] ?? null,
                            isset($validated['marks_per_question']) ? (float) $validated['marks_per_question'] : 1.0,
                            null,
                            $validated['mcq_format'] ?? 'all',
                            $student->id,
                            $scenarioPercentage
                        );

                        $test = $record['test'];

                        // Freemium / Legacy Quota Consumption
                        if (!$decision->isPaid()) {
                            $this->quotaConsumptionService->consumeQuota(
                                $student,
                                'formal_test',
                                'generation',
                                [
                                    'test_id'            => $test->id,
                                    'attempt_number'     => 0,
                                    'idempotency_key'    => $validatedKey,
                                    'request_payload'    => $fingerprintPayload,
                                    'is_legacy_fallback' => $decision->isLegacyFree(),
                                    'primary_campaign'   => $decision->isFreemium() && $decision->getCampaignId() ? FreemiumCampaign::find($decision->getCampaignId()) : null,
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
            ], 409);
        } catch (QuotaLimitExceededException $e) {
            return response()->json([
                'success'       => 0,
                'error'         => $e->getMessage(),
                'error_code'    => 'QUOTA_EXHAUSTED',
                'denial_reason' => $e->getMessage(),
            ], 403);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => 0,
                'error'   => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => 0,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function generateFullBookFormalTestForLms(Request $request)
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

        if ($request->input('requested_mode') === 'mock' || $request->input('mode') === 'mock') {
            return response()->json([
                'success'    => 0,
                'error'      => 'Mock examinations cannot be generated via formal test routes. Use the dedicated mock examination endpoint.',
                'error_code' => 'INVALID_ASSESSMENT_SCOPE',
            ], 422);
        }

        $scenarioPercentage = 30;
        if ($request->has('scenario_percentage')) {
            $sp = $request->input('scenario_percentage');
            if (!is_numeric($sp) || (int) $sp != $sp || (int) $sp < 0 || (int) $sp > 100 || ((int) $sp % 5 !== 0)) {
                return response()->json([
                    'success' => 0,
                    'error'   => 'The scenario percentage must be an integer between 0 and 100 in increments of 5.',
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
            'title'              => ['nullable', 'string', 'max:255'],
            'description'        => ['nullable', 'string', 'max:255'],
            'marks_per_question' => ['nullable', 'numeric', 'min:0'],
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
            'formal_test'
        );

        if ($decision->isDenied()) {
            return response()->json([
                'success'       => 0,
                'error'         => $decision->getDenialReason() ?? 'An active subscription is required to access this resource.',
                'denial_reason' => $decision->getDenialReason(),
                'diagnostic'    => $decision->getDiagnosticMessage(),
                'upgrade_slug'  => $decision->getUpgradeProgramSlug(),
            ], 403);
        }

        $operation = 'formal_test_generation';
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
                        $scenarioPercentage
                    ) {
                        if (!$decision->isPaid()) {
                            // Pessimistic parent row lock to prevent concurrency bypass
                            \App\Models\WebUser::where('id', $student->id)->lockForUpdate()->first();
                        }

                        $record = $this->generateFormalTestRecord(
                            $offeredProgramId,
                            (int) $validated['subject_id'],
                            $unitIds,
                            'full_book',
                            (int) $validated['total_questions'],
                            $validated['time_limit_minutes'] ?? null,
                            $validated['title'] ?? null,
                            $validated['description'] ?? null,
                            isset($validated['marks_per_question']) ? (float) $validated['marks_per_question'] : 1.0,
                            (int) $validated['book_id'],
                            $validated['mcq_format'] ?? 'all',
                            $student->id,
                            $scenarioPercentage
                        );

                        $test = $record['test'];

                        // Freemium / Legacy Quota Consumption
                        if (!$decision->isPaid()) {
                            $this->quotaConsumptionService->consumeQuota(
                                $student,
                                'formal_test',
                                'generation',
                                [
                                    'test_id'            => $test->id,
                                    'attempt_number'     => 0,
                                    'idempotency_key'    => $validatedKey,
                                    'request_payload'    => $fingerprintPayload,
                                    'is_legacy_fallback' => $decision->isLegacyFree(),
                                    'primary_campaign'   => $decision->isFreemium() && $decision->getCampaignId() ? FreemiumCampaign::find($decision->getCampaignId()) : null,
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
            ], 409);
        } catch (QuotaLimitExceededException $e) {
            return response()->json([
                'success'       => 0,
                'error'         => $e->getMessage(),
                'error_code'    => 'QUOTA_EXHAUSTED',
                'denial_reason' => $e->getMessage(),
            ], 403);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => 0,
                'error'   => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => 0,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    private function generateFormalTestRecord(
        ?int $offeredProgramId,
        int $subjectId,
        array $unitIds,
        string $scopeType,
        int $totalQuestions,
        ?int $timeLimitMinutes,
        ?string $title,
        ?string $description,
        float $marksPerQuestion,
        ?int $bookId = null,
        string $mcqFormat = 'all',
        ?int $createdBy = null,
        int $scenarioPercentage = 30
    ): array {
        $requestedQuestions = $totalQuestions;
        $selection = $this->selectLmsMcqQuestions($unitIds, $requestedQuestions, $mcqFormat, null, true, true, $scenarioPercentage);

        if ($selection === null) {
            throw new \InvalidArgumentException(
                $mcqFormat === 'scenario'
                    ? 'Not enough active scenarios available for the selected chapter. Try fewer scenarios.'
                    : 'Not enough active MCQs available for the selected chapter and question type. Try fewer questions.'
            );
        }

        $questions = $selection['questions'];
        $scenarioGroupIds = $selection['scenario_group_ids'];
        $totalQuestions = $questions->count();

        $generatedTitle = $title ?: $this->buildGeneratedFormalTestTitle($scopeType, $subjectId, $unitIds, $bookId);

        $test = Test::create([
            'offered_program_id' => $offeredProgramId,
            'subject_id'         => $subjectId,
            'created_by'         => $createdBy,
            'title'              => $generatedTitle,
            'description'        => $description,
            'test_source'        => 'system',
            'test_mode'          => 'test',
            'scope_type'         => $scopeType,
            'question_type'      => 'mcq',
            'time_limit_minutes' => $timeLimitMinutes,
            'total_questions'    => $totalQuestions,
            'is_published'       => true,
            'published_at'       => now(),
        ]);

        $timestamp = now();

        TestScope::insert(
            collect($unitIds)->map(function (int $unitId) use ($test, $timestamp) {
                return [
                    'test_id'    => $test->id,
                    'unit_id'    => $unitId,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            })->all()
        );

        TestQuestion::insert(
            $questions->values()->map(function ($question, int $index) use ($test, $marksPerQuestion, $timestamp) {
                return [
                    'test_id'        => $test->id,
                    'question_id'    => $question->id,
                    'question_order' => $index + 1,
                    'marks'          => $marksPerQuestion,
                    'created_at'     => $timestamp,
                    'updated_at'     => $timestamp,
                ];
            })->all()
        );

        $data = [
            'mcq_format'          => $mcqFormat,
            'scenario_percentage' => $scenarioPercentage,
            'test_id'             => $test->id,
            'offered_program_id'  => $offeredProgramId,
            'subject_id'          => $subjectId,
            'title'               => $test->title,
            'description'         => $test->description,
            'test_source'         => $test->test_source,
            'test_mode'           => $test->test_mode,
            'scope_type'          => $test->scope_type,
            'question_type'       => $test->question_type,
            'time_limit_minutes'  => $test->time_limit_minutes,
            'total_questions'     => $test->total_questions,
            'requested_questions' => $requestedQuestions,
            'total_scenarios'     => count($scenarioGroupIds),
            'scenario_group_ids'  => $scenarioGroupIds,
            'marks_per_question'  => round($marksPerQuestion, 2),
            'unit_ids'            => array_values($unitIds),
            'question_ids'        => $questions->pluck('id')->values(),
            'is_published'        => (bool) $test->is_published,
            'published_at'        => optional($test->published_at)->toISOString(),
        ];

        if ($bookId !== null) {
            $data['book_id'] = $bookId;
        }

        return [
            'test' => $test,
            'data' => $data,
        ];
    }

    private function createFormalTestResponse(
        ?int $offeredProgramId,
        int $subjectId,
        array $unitIds,
        string $scopeType,
        int $totalQuestions,
        ?int $timeLimitMinutes,
        ?string $title,
        ?string $description,
        float $marksPerQuestion,
        ?int $bookId = null,
        string $mcqFormat = 'all',
        ?int $createdBy = null
    ) {
        try {
            $record = $this->generateFormalTestRecord(
                $offeredProgramId,
                $subjectId,
                $unitIds,
                $scopeType,
                $totalQuestions,
                $timeLimitMinutes,
                $title,
                $description,
                $marksPerQuestion,
                $bookId,
                $mcqFormat,
                $createdBy
            );

            return response()->json([
                'success' => 1,
                'data'    => $record['data'],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => 0,
                'error'   => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => 0,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    private function buildGeneratedFormalTestTitle(string $scopeType, int $subjectId, array $unitIds, ?int $bookId = null): string
    {
        $unitNumbers = DB::table('book_unit_tbl')
            ->whereIn('id', $unitIds)
            ->orderBy('unit_no')
            ->pluck('unit_no')
            ->filter(fn ($unitNo) => $unitNo !== null)
            ->map(fn ($unitNo) => (string) $unitNo)
            ->values()
            ->all();

        if ($scopeType === 'full_book' && $bookId !== null) {
            return "Generated Full Book Formal Test - Book {$bookId}";
        }

        if ($scopeType === 'chapter' && count($unitIds) === 1) {
            $unitLabel = $unitNumbers[0] ?? (string) $unitIds[0];

            return "Generated Chapter Formal Test - Unit {$unitLabel}";
        }

        if ($scopeType === 'multiple_chapters') {
            if (count($unitNumbers) > 0 && count($unitNumbers) <= 3) {
                return 'Generated Formal Test - Units ' . implode(', ', $unitNumbers);
            }

            return 'Generated Multi-Chapter Formal Test - ' . count($unitIds) . ' Chapters';
        }

        return "Generated Formal Test - Subject {$subjectId}";
    }

    /**
     * Generate a full-book mock examination strictly bound to 'mock' assessment mode and quota.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function generateFullBookMockTestForLms(Request $request)
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

        $validated = $request->validate([
            'offered_program_id' => ['nullable', 'integer', 'min:1', 'exists:offered_programs,id'],
            'subject_id'         => ['required', 'integer', 'exists:subject_tbl,id'],
            'book_id'            => ['required', 'integer', 'exists:book_tbl,id'],
            'unit_ids'           => ['nullable', 'array'],
            'unit_ids.*'         => ['integer', 'exists:book_unit_tbl,id'],
            'total_questions'    => ['nullable', 'integer', 'min:10', 'max:200'],
            'mcq_format'         => ['nullable', 'string', 'in:all,straight,scenario'],
            'scenario_percentage'=> ['nullable', 'integer', 'min:0', 'max:100'],
            'title'              => ['nullable', 'string', 'max:255'],
            'description'        => ['nullable', 'string', 'max:255'],
            'marks_per_question' => ['nullable', 'numeric', 'min:0'],
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
                'success'    => 0,
                'error'      => 'No active units found for the selected book.',
                'error_code' => 'INVALID_ASSESSMENT_SCOPE',
            ], 422);
        }

        $requestedUnits = $request->input('unit_ids');
        if (!empty($requestedUnits) && is_array($requestedUnits)) {
            $requestedUnitIds = collect($requestedUnits)->map(fn ($id) => (int) $id)->sort()->values()->all();
            $fullBookUnitIds = collect($unitIds)->sort()->values()->all();
            if ($requestedUnitIds !== $fullBookUnitIds) {
                return response()->json([
                    'success'    => 0,
                    'error'      => 'Mock examinations require complete full-book syllabus coverage. Partial chapter selection is not permitted.',
                    'error_code' => 'INVALID_ASSESSMENT_SCOPE',
                ], 422);
            }
        }

        $offeredProgramId = !empty($validated['offered_program_id']) ? (int) $validated['offered_program_id'] : null;

        $targetContext = [
            'offered_program_id' => $offeredProgramId,
            'subject_id'         => (int) $validated['subject_id'],
            'book_id'            => (int) $validated['book_id'],
            'unit_ids'           => $unitIds,
        ];

        // Authoritative 4-tier entitlement decision strictly for 'mock'
        $decision = $this->entitlementResolver->resolve(
            $student,
            $targetContext,
            'perm_mcqs',
            'mock'
        );

        if ($decision->isDenied()) {
            return response()->json([
                'success'       => 0,
                'error'         => $decision->getDenialReason() ?? 'An active subscription or mock allowance is required to access mock examinations.',
                'denial_reason' => $decision->getDenialReason(),
                'diagnostic'    => $decision->getDiagnosticMessage(),
                'upgrade_slug'  => $decision->getUpgradeProgramSlug(),
            ], 403);
        }

        $totalQuestions = (int) ($validated['total_questions'] ?? 25);
        $scenarioPercentage = (int) ($validated['scenario_percentage'] ?? 30);
        if ($scenarioPercentage % 5 !== 0) {
            return response()->json([
                'success'    => 0,
                'error'      => 'The scenario percentage must be a multiple of 5.',
                'error_code' => 'INVALID_SCENARIO_PERCENTAGE',
            ], 422);
        }

        // Server-authoritative duration resolution (F-01)
        // 1. Applicable campaign FreemiumExamQuota->time_limit_minutes
        $resolvedDuration = null;
        if ($decision->isFreemium() && $decision->getCampaignId()) {
            $quota = FreemiumExamQuota::where('campaign_id', $decision->getCampaignId())
                ->where('exam_type', 'mock')
                ->first();
            if ($quota && $quota->time_limit_minutes !== null && (int) $quota->time_limit_minutes > 0) {
                $resolvedDuration = (int) $quota->time_limit_minutes;
            }
        }

        // 2. Server-side configuration config('freemium.mock_default_duration_minutes')
        if ($resolvedDuration === null) {
            $configDefault = config('freemium.mock_default_duration_minutes');
            if ($configDefault !== null && is_numeric($configDefault) && (int) $configDefault > 0) {
                $resolvedDuration = (int) $configDefault;
            }
        }

        // If neither source provides a valid positive integer duration, fail closed with 422
        if ($resolvedDuration === null || $resolvedDuration <= 0) {
            return response()->json([
                'success'    => 0,
                'error'      => 'Mock examination duration is not configured on the server.',
                'error_code' => 'UNCONFIGURED_MOCK_DURATION',
            ], 422);
        }

        $timeLimitMinutes = $resolvedDuration;

        $operation = 'mock_test_generation';
        $fingerprintPayload = [
            'operation'          => $operation,
            'offered_program_id' => $offeredProgramId,
            'subject_id'         => (int) $validated['subject_id'],
            'book_id'            => (int) $validated['book_id'],
            'unit_ids'           => $unitIds,
            'total_questions'    => $totalQuestions,
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
                    $totalQuestions,
                    $scenarioPercentage,
                    $timeLimitMinutes,
                    $validatedKey,
                    $fingerprintPayload
                ) {
                    return DB::transaction(function () use (
                        $student,
                        $decision,
                        $validated,
                        $unitIds,
                        $offeredProgramId,
                        $totalQuestions,
                        $scenarioPercentage,
                        $timeLimitMinutes,
                        $validatedKey,
                        $fingerprintPayload
                    ) {
                        if (!$decision->isPaid()) {
                            // Pessimistic parent row lock to prevent concurrency bypass
                            \App\Models\WebUser::where('id', $student->id)->lockForUpdate()->first();
                        }

                        $record = $this->generateMockTestRecord(
                            $offeredProgramId,
                            (int) $validated['subject_id'],
                            $unitIds,
                            'full_book',
                            $totalQuestions,
                            $timeLimitMinutes,
                            $validated['title'] ?? null,
                            $validated['description'] ?? null,
                            isset($validated['marks_per_question']) ? (float) $validated['marks_per_question'] : 1.0,
                            (int) $validated['book_id'],
                            $validated['mcq_format'] ?? 'all',
                            $student->id,
                            $scenarioPercentage
                        );

                        $test = $record['test'];

                        // Freemium / Legacy Quota Consumption strictly for 'mock'
                        if (!$decision->isPaid()) {
                            $this->quotaConsumptionService->consumeQuota(
                                $student,
                                'mock',
                                'generation',
                                [
                                    'test_id'            => $test->id,
                                    'attempt_number'     => 0,
                                    'idempotency_key'    => $validatedKey,
                                    'request_payload'    => $fingerprintPayload,
                                    'is_legacy_fallback' => $decision->isLegacyFree(),
                                    'primary_campaign'   => $decision->isFreemium() && $decision->getCampaignId() ? FreemiumCampaign::find($decision->getCampaignId()) : null,
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
            ], 409);
        } catch (QuotaLimitExceededException $e) {
            return response()->json([
                'success'       => 0,
                'error'         => $e->getMessage(),
                'error_code'    => 'QUOTA_EXHAUSTED',
                'denial_reason' => $e->getMessage(),
            ], 403);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => 0,
                'error'   => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => 0,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    private function generateMockTestRecord(
        ?int $offeredProgramId,
        int $subjectId,
        array $unitIds,
        string $scopeType,
        int $totalQuestions,
        ?int $timeLimitMinutes,
        ?string $title,
        ?string $description,
        float $marksPerQuestion,
        ?int $bookId = null,
        string $mcqFormat = 'all',
        ?int $createdBy = null,
        int $scenarioPercentage = 30
    ): array {
        $requestedQuestions = $totalQuestions;
        $selection = $this->selectLmsMcqQuestions($unitIds, $requestedQuestions, $mcqFormat, null, true, true, $scenarioPercentage);

        if ($selection === null) {
            throw new \InvalidArgumentException(
                $mcqFormat === 'scenario'
                    ? 'Not enough active scenarios available for the selected chapter. Try fewer scenarios.'
                    : 'Not enough active MCQs available for the selected chapter and question type. Try fewer questions.'
            );
        }

        $questions = $selection['questions'];
        $scenarioGroupIds = $selection['scenario_group_ids'];
        $totalQuestions = $questions->count();

        $generatedTitle = $title ?: $this->buildGeneratedMockTestTitle($subjectId, $bookId);

        $test = Test::create([
            'offered_program_id' => $offeredProgramId,
            'subject_id'         => $subjectId,
            'created_by'         => $createdBy,
            'title'              => $generatedTitle,
            'description'        => $description,
            'test_source'        => 'system',
            'test_mode'          => 'mock',
            'scope_type'         => $scopeType,
            'question_type'      => 'mcq',
            'time_limit_minutes' => $timeLimitMinutes,
            'total_questions'    => $totalQuestions,
            'is_published'       => true,
            'published_at'       => now(),
        ]);

        $timestamp = now();

        TestScope::insert(
            collect($unitIds)->map(function (int $unitId) use ($test, $timestamp) {
                return [
                    'test_id'    => $test->id,
                    'unit_id'    => $unitId,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            })->all()
        );

        TestQuestion::insert(
            $questions->values()->map(function ($question, int $index) use ($test, $marksPerQuestion, $timestamp) {
                return [
                    'test_id'        => $test->id,
                    'question_id'    => $question->id,
                    'question_order' => $index + 1,
                    'marks'          => $marksPerQuestion,
                    'created_at'     => $timestamp,
                    'updated_at'     => $timestamp,
                ];
            })->all()
        );

        $data = [
            'mcq_format'          => $mcqFormat,
            'scenario_percentage' => $scenarioPercentage,
            'test_id'             => $test->id,
            'offered_program_id'  => $offeredProgramId,
            'subject_id'          => $subjectId,
            'title'               => $test->title,
            'description'         => $test->description,
            'test_source'         => $test->test_source,
            'test_mode'           => $test->test_mode,
            'scope_type'          => $test->scope_type,
            'question_type'       => $test->question_type,
            'time_limit_minutes'  => $test->time_limit_minutes,
            'total_questions'     => $test->total_questions,
            'requested_questions' => $requestedQuestions,
            'total_scenarios'     => count($scenarioGroupIds),
            'scenario_group_ids'  => $scenarioGroupIds,
            'marks_per_question'  => round($marksPerQuestion, 2),
            'unit_ids'            => array_values($unitIds),
            'question_ids'        => $questions->pluck('id')->values(),
            'is_published'        => (bool) $test->is_published,
        ];

        if ($bookId !== null) {
            $data['book_id'] = $bookId;
        }

        return [
            'test' => $test,
            'data' => $data,
        ];
    }

    private function buildGeneratedMockTestTitle(int $subjectId, ?int $bookId = null): string
    {
        if ($bookId !== null) {
            return "Generated Full Book Mock Examination - Book {$bookId}";
        }

        return "Generated Mock Examination - Subject {$subjectId}";
    }

    public function saveFormalTestQuestionProgressForLms(Request $request)
    {
        $validated = $request->validate([
            'test_id' => ['required', 'integer', 'exists:tests,id'],
            'question_id' => ['required', 'integer', 'exists:exam_question_tbl,id'],
            'selected_option_id' => ['nullable', 'integer', 'exists:exam_question_options_tbl,id'],
            'time_spent_seconds' => ['nullable', 'integer', 'min:0'],
            'started_at' => ['nullable', 'date'],
            'attempt_id' => ['nullable', 'integer', 'exists:test_attempts,id'],
        ]);

        $user = $request->user();
        if (!$user || $user->role !== 'student' || $user->status !== 'active') {
            return response()->json([
                'success' => 0,
                'error'   => 'Active student account required.',
            ], 403);
        }

        $test = Test::find($validated['test_id']);
        if (!$test) {
            return response()->json([
                'success' => 0,
                'error'   => 'Test not found or access denied.',
            ], 404);
        }

        // Section 4: Authoritative Ownership and Security Verification
        $isOfficialShared = ($test->test_source === 'official' && (bool) $test->is_published);
        $isStudentOwned = (
            (int) $test->created_by === (int) $user->id ||
            FreemiumQuotaConsumption::where('test_id', $test->id)->where('user_id', $user->id)->exists() ||
            TestAttempt::where('test_id', $test->id)->where('user_id', $user->id)->exists()
        );

        if (!$isOfficialShared && !$isStudentOwned) {
            return response()->json([
                'success' => 0,
                'error'   => 'Test not found or access denied.',
            ], 404);
        }

        $testQuestionRow = $this->getTestQuestionRow($test->id, (int) $validated['question_id']);
        if (!$testQuestionRow) {
            return response()->json([
                'success' => 0,
                'error'   => 'Selected question does not belong to the test.',
            ], 422);
        }

        // If explicit attempt_id supplied, verify ownership before proceeding
        if (!empty($validated['attempt_id'])) {
            $attemptCheck = TestAttempt::query()
                ->where('id', $validated['attempt_id'])
                ->where('test_id', $test->id)
                ->where('user_id', $user->id)
                ->first();

            if (!$attemptCheck) {
                return response()->json([
                    'success' => 0,
                    'error'   => 'Test attempt not found or access denied.',
                ], 404);
            }

            if ($attemptCheck->status === 'submitted') {
                return response()->json([
                    'success'    => 0,
                    'error'      => 'Test attempt has already been submitted.',
                    'error_code' => 'ATTEMPT_ALREADY_SUBMITTED',
                ], 409);
            }

            if ($this->isAttemptExpired($attemptCheck, $test)) {
                if ($attemptCheck->status !== 'expired') {
                    $attemptCheck->update(['status' => 'expired']);
                }
                return response()->json([
                    'success'    => 0,
                    'error'      => 'Assessment session has expired and can no longer be modified.',
                    'error_code' => 'ATTEMPT_EXPIRED',
                ], 422);
            }
        }

        // Section 3: Authoritative 4-Tier Entitlement Resolution
        $targetContext = [
            'offered_program_id' => $test->offered_program_id ? (int) $test->offered_program_id : null,
            'subject_id'         => $test->subject_id ? (int) $test->subject_id : null,
        ];
        $unitIds = $test->scopes()->pluck('unit_id')->filter()->values()->all();
        if (!empty($unitIds)) {
            $targetContext['unit_ids'] = $unitIds;
        }

        $decision = $this->entitlementResolver->resolve(
            $user,
            $targetContext,
            'perm_mcqs',
            $test->test_mode === 'mock' ? 'mock' : 'formal_test'
        );

        if ($decision->isDenied()) {
            return response()->json([
                'success'       => 0,
                'error'         => 'You do not have permission to attempt this assessment.',
                'error_code'    => 'FORBIDDEN',
                'denial_reason' => $decision->getDenialReason(),
            ], 403);
        }

        try {
            return DB::transaction(function () use (
                $user,
                $test,
                $validated,
                $decision,
                $testQuestionRow
            ) {
                // 1. Pessimistic lock on parent WebUser row to serialize attempt operations
                WebUser::query()->where('id', $user->id)->lockForUpdate()->first();

                // 2. Look up the student's active TestAttempt (user_id scoped to use user_id_test_id_index)
                $attempt = null;
                if (!empty($validated['attempt_id'])) {
                    $attempt = TestAttempt::query()
                        ->where('id', $validated['attempt_id'])
                        ->where('user_id', $user->id)
                        ->where('test_id', $test->id)
                        ->lockForUpdate()
                        ->first();
                } else {
                    $attempt = TestAttempt::query()
                        ->where('user_id', $user->id)
                        ->where('test_id', $test->id)
                        ->where('status', 'in_progress')
                        ->latest('id')
                        ->lockForUpdate()
                        ->first();
                }

                if ($attempt) {
                    if ($attempt->status === 'submitted') {
                        return response()->json([
                            'success'    => 0,
                            'error'      => 'Test attempt has already been submitted.',
                            'error_code' => 'ATTEMPT_ALREADY_SUBMITTED',
                        ], 409);
                    }

                    if ($this->isAttemptExpired($attempt, $test)) {
                        if ($attempt->status !== 'expired') {
                            $attempt->update(['status' => 'expired']);
                        }
                        return response()->json([
                            'success'    => 0,
                            'error'      => 'Assessment session has expired and can no longer be modified.',
                            'error_code' => 'ATTEMPT_EXPIRED',
                        ], 422);
                    }
                }

                // 3. Create attempt if none exists (initial attempt or retake)
                if (!$attempt) {
                    if ($decision->isPaid()) {
                        $completedAttemptsCount = TestAttempt::query()
                            ->where('test_id', $test->id)
                            ->where('user_id', $user->id)
                            ->count();
                        $attemptNumber = $completedAttemptsCount + 1;
                        $eventType = ($attemptNumber === 1) ? 'attempt_start' : 'retake_start';
                    } else {
                        $eligibility = $this->quotaConsumptionService->getTestAttemptEligibility($user, $test->id);

                        if (!$eligibility['can_attempt']) {
                            return response()->json([
                                'success'       => 0,
                                'error'         => $eligibility['denial_reason'] ?? 'Quota exceeded or retakes not permitted.',
                                'error_code'    => 'QUOTA_EXHAUSTED',
                                'denial_reason' => $eligibility['denial_reason'],
                            ], 403);
                        }

                        $attemptNumber = (int) $eligibility['attempt_number'];
                        $eventType = ($attemptNumber === 1) ? 'attempt_start' : 'retake_start';
                    }

                    $attempt = TestAttempt::create([
                        'test_id'                 => $test->id,
                        'user_id'                 => $user->id,
                        'attempted_questions'     => 0,
                        'correct_answers'         => 0,
                        'wrong_answers'           => 0,
                        'not_attempted_questions' => (int) ($test->total_questions ?: $this->getAllTestQuestionRows($test->id)->count()),
                        'score'                   => 0,
                        'accuracy'                => 0,
                        'started_at'              => isset($validated['started_at']) ? Carbon::parse($validated['started_at']) : now(),
                        'status'                  => 'in_progress',
                    ]);

                    // Atomically evaluate/record Freemium attempt or retake consumption
                    if (!$decision->isPaid()) {
                        $this->quotaConsumptionService->consumeAttemptQuota(
                            $user,
                            $test,
                            $attemptNumber,
                            $eventType,
                            [
                                'test_attempt_id'    => $attempt->id,
                                'is_legacy_fallback' => $decision->isLegacyFree(),
                                'primary_campaign'   => $decision->isFreemium() && $decision->getCampaignId() ? FreemiumCampaign::find($decision->getCampaignId()) : null,
                            ]
                        );
                    }
                }

                // 4. Save question progress
                $selectedOptionId = $validated['selected_option_id'] ?? null;
                $isAttempted = !empty($selectedOptionId);
                $isCorrect = $isAttempted
                    ? ((int) $selectedOptionId === (int) $testQuestionRow->correct_option_id)
                    : null;
                $marks = (float) $testQuestionRow->marks;
                $obtainedMarks = $isCorrect === true ? $marks : 0.0;

                TestAttemptQuestion::updateOrCreate(
                    [
                        'attempt_id'  => $attempt->id,
                        'question_id' => (int) $testQuestionRow->question_id,
                    ],
                    [
                        'question_order'     => (int) $testQuestionRow->question_order,
                        'selected_option_id' => $selectedOptionId,
                        'is_attempted'       => $isAttempted,
                        'is_correct'         => $isCorrect,
                        'marks'              => $marks,
                        'obtained_marks'     => $obtainedMarks,
                        'time_spent_seconds' => $validated['time_spent_seconds'] ?? null,
                    ]
                );

                $this->refreshTestAttemptProgress($attempt);

                $attempt->refresh();

                return response()->json([
                    'success' => 1,
                    'data'    => [
                        'attempt_id'              => $attempt->id,
                        'test_id'                 => $test->id,
                        'question_id'             => (int) $testQuestionRow->question_id,
                        'is_attempted'            => $isAttempted,
                        'status'                  => $attempt->status,
                        'attempted_questions'     => (int) $attempt->attempted_questions,
                        'not_attempted_questions' => (int) $attempt->not_attempted_questions,
                    ],
                ]);
            }, 3);
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'uk_test_user_attempt_event')) {
                return response()->json([
                    'success'    => 0,
                    'error'      => 'A concurrent attempt or retake is already active for this test.',
                    'error_code' => 'CONCURRENT_ATTEMPT_CONFLICT',
                ], 409);
            }

            if ($this->isDeadlockOrLockTimeout($e)) {
                return response()->json([
                    'success'    => 0,
                    'error'      => 'A temporary database concurrency conflict occurred. Please retry your request.',
                    'error_code' => 'CONCURRENT_ATTEMPT_CONFLICT',
                ], 409);
            }

            Log::error('Formal test question progress query exception', [
                'test_id'    => $test->id,
                'student_id' => $user->id,
                'attempt_id' => $validated['attempt_id'] ?? null,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => 0,
                'error'   => 'A database error occurred while processing test progress.',
            ], 500);
        } catch (QuotaLimitExceededException $e) {
            return response()->json([
                'success'       => 0,
                'error'         => $e->getMessage(),
                'error_code'    => 'QUOTA_EXHAUSTED',
                'denial_reason' => $e->getMessage(),
            ], 403);
        } catch (\Throwable $e) {
            if ($this->isDeadlockOrLockTimeout($e)) {
                return response()->json([
                    'success'    => 0,
                    'error'      => 'A temporary database concurrency conflict occurred. Please retry your request.',
                    'error_code' => 'CONCURRENT_ATTEMPT_CONFLICT',
                ], 409);
            }

            Log::error('Formal test question progress unexpected exception', [
                'test_id'    => $test->id,
                'student_id' => $user->id,
                'attempt_id' => $validated['attempt_id'] ?? null,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => 0,
                'error'   => 'An unexpected error occurred while processing test progress.',
            ], 500);
        }
    }

    /**
     * Milestone C6: Authoritative assessment session hydration for formal and mock tests.
     * Restores test metadata, ordered questions, student's saved answers from test_attempt_questions,
     * authoritative timing, and attempt status with examination security safeguards.
     */
    public function getFormalTestForLms(Request $request, int $testId)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'student' || $user->status !== 'active') {
            return response()->json([
                'success' => 0,
                'error'   => 'Active student account required.',
            ], 403);
        }

        $test = Test::with(['scopes'])->find($testId);
        if (!$test) {
            return response()->json([
                'success' => 0,
                'error'   => 'Test not found or access denied.',
            ], 404);
        }

        $isOfficialShared = ($test->test_source === 'official' && (bool) $test->is_published);
        $isStudentOwned = (
            (int) $test->created_by === (int) $user->id ||
            FreemiumQuotaConsumption::where('test_id', $test->id)->where('user_id', $user->id)->exists() ||
            TestAttempt::where('test_id', $test->id)->where('user_id', $user->id)->exists()
        );

        if (!$isOfficialShared && !$isStudentOwned) {
            return response()->json([
                'success' => 0,
                'error'   => 'Test not found or access denied.',
            ], 404);
        }

        // Section 3: Authoritative 4-Tier Entitlement Resolution
        $targetContext = [
            'offered_program_id' => $test->offered_program_id ? (int) $test->offered_program_id : null,
            'subject_id'         => $test->subject_id ? (int) $test->subject_id : null,
        ];
        $unitIds = $test->scopes->pluck('unit_id')->filter()->map('intval')->values()->all();
        if (!empty($unitIds)) {
            $targetContext['unit_ids'] = $unitIds;
        }

        $decision = $this->entitlementResolver->resolve(
            $user,
            $targetContext,
            'perm_mcqs',
            $test->test_mode === 'mock' ? 'mock' : 'formal_test'
        );

        if ($decision->isDenied()) {
            return response()->json([
                'success'       => 0,
                'error'         => 'You do not have permission to view or attempt this assessment.',
                'error_code'    => 'FORBIDDEN',
                'denial_reason' => $decision->getDenialReason(),
            ], 403);
        }

        $requestedAttemptId = $request->query('attempt_id');
        $attempt = TestAttempt::query()
            ->where('test_id', $test->id)
            ->where('user_id', $user->id)
            ->when(
                !empty($requestedAttemptId),
                fn ($q) => $q->where('id', (int) $requestedAttemptId),
                fn ($q) => $q->orderByRaw("CASE WHEN status = 'in_progress' THEN 0 ELSE 1 END")->latest('id')
            )
            ->first();

        $attemptNumber = 1;
        if ($attempt) {
            $attemptNumber = (int) (FreemiumQuotaConsumption::where('test_attempt_id', $attempt->id)->value('attempt_number')
                ?? TestAttempt::where('test_id', $test->id)->where('user_id', $user->id)->where('id', '<=', $attempt->id)->count()
                ?: 1);
        }

        $testQuestions = DB::table('test_questions as tq')
            ->join('exam_question_tbl as q', 'q.id', '=', 'tq.question_id')
            ->leftJoin('book_unit_topic_tbl as t', 't.id', '=', 'q.topic_id')
            ->where('tq.test_id', $test->id)
            ->select([
                'tq.id as test_question_id',
                'tq.question_id',
                'tq.question_order',
                'tq.marks',
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
            ->orderBy('tq.question_order', 'asc')
            ->get();

        $questionIds = $testQuestions->pluck('question_id')->all();

        $optionsByQuestion = empty($questionIds)
            ? collect()
            : DB::table('exam_question_options_tbl')
                ->whereIn('question_id', $questionIds)
                ->orderBy('id', 'asc')
                ->get()
                ->groupBy('question_id');

        $scenarioGroupIds = $testQuestions
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

        $savedAnswers = $attempt
            ? DB::table('test_attempt_questions')
                ->where('attempt_id', $attempt->id)
                ->get()
                ->keyBy('question_id')
            : collect();

        $status = $attempt ? $attempt->status : 'in_progress';
        $timeLimitMinutes = $test->time_limit_minutes ? (int) $test->time_limit_minutes : null;
        $startedAtStr = $attempt
            ? optional($attempt->started_at ?? $attempt->created_at)->toISOString()
            : null;
        $submittedAtStr = $attempt ? optional($attempt->submitted_at)->toISOString() : null;
        $remainingSeconds = null;
        $isExpired = false;

        if ($timeLimitMinutes !== null && $timeLimitMinutes > 0) {
            $limitSeconds = $timeLimitMinutes * 60;
            if ($attempt) {
                $startTs = Carbon::parse($attempt->started_at ?? $attempt->created_at)->timestamp;
                $elapsedSeconds = max(0, Carbon::now('UTC')->timestamp - $startTs);
                $remainingSeconds = max(0, $limitSeconds - $elapsedSeconds);
                if ($status !== 'submitted' && $remainingSeconds === 0) {
                    $isExpired = true;
                    $status = 'expired';
                }
            } else {
                $remainingSeconds = $limitSeconds;
                $isExpired = false;
            }
        }
        if ($status === 'submitted') {
            $remainingSeconds = 0;
        }

        $isSubmitted = ($status === 'submitted');

        $formattedQuestions = $testQuestions->map(function ($tq) use ($optionsByQuestion, $scenariosById, $savedAnswers, $isSubmitted) {
            $opts = $optionsByQuestion->get($tq->question_id, collect());
            $scenarioRecord = $tq->scenario_group_id ? $scenariosById->get($tq->scenario_group_id) : null;
            $saved = $savedAnswers->get($tq->question_id);

            return [
                'id'                      => (int) $tq->question_id,
                'test_question_id'        => (int) $tq->test_question_id,
                'question_order'          => (int) $tq->question_order,
                'marks'                   => (float) $tq->marks,
                'question'                => $tq->question,
                'question_um'             => $tq->question_um,
                'explanation'             => $isSubmitted ? $tq->explanation : null,
                'explanation_um'          => $isSubmitted ? $tq->explanation_um : null,
                'topic_id'                => $tq->topic_id ? (int) $tq->topic_id : null,
                'topic_name'              => $tq->topic_name ?? '',
                'topic_no'                => $tq->topic_no ?? '',
                'unit_id'                 => $tq->unit_id ? (int) $tq->unit_id : null,
                'scenario'                => $scenarioRecord ? [
                    'id'               => (int) $scenarioRecord->id,
                    'title'            => $scenarioRecord->title,
                    'scenario_text'    => $scenarioRecord->scenario_text,
                    'scenario_text_um' => $scenarioRecord->scenario_text_um,
                    'scenario_image'   => $scenarioRecord->scenario_image,
                ] : null,
                'scenario_group_id'       => $tq->scenario_group_id ? (int) $tq->scenario_group_id : null,
                'scenario_question_order' => $tq->scenario_question_order ? (int) $tq->scenario_question_order : (int) $tq->question_order,
                'options'                 => $opts->map(function ($opt) use ($isSubmitted) {
                    return [
                        'id'        => (int) $opt->id,
                        'option'    => $opt->option,
                        'option_um' => $opt->option_um,
                        'is_answer' => $isSubmitted ? (bool) $opt->is_answer : null,
                    ];
                })->values()->all(),
                'saved_answer'            => [
                    'selected_option_id' => $saved && $saved->selected_option_id ? (int) $saved->selected_option_id : null,
                    'is_attempted'       => $saved ? (bool) $saved->is_attempted : false,
                    'is_correct'         => $isSubmitted && $saved ? ($saved->is_correct !== null ? (bool) $saved->is_correct : null) : null,
                    'time_spent_seconds' => $saved && $saved->time_spent_seconds ? (int) $saved->time_spent_seconds : 0,
                ],
            ];
        })->values()->all();

        return response()->json([
            'success' => 1,
            'data'    => [
                'test_id'            => (int) $test->id,
                'attempt_id'         => $attempt ? (int) $attempt->id : null,
                'attempt_number'     => $attemptNumber,
                'attempt_type'       => $test->test_mode === 'mock' ? 'mock' : 'test',
                'test_mode'          => $test->test_mode ?? 'test',
                'status'             => $status,
                'title'              => $test->title,
                'subject_id'         => (int) $test->subject_id,
                'offered_program_id' => $test->offered_program_id ? (int) $test->offered_program_id : null,
                'scope_type'         => $test->scope_type,
                'unit_ids'           => $unitIds,
                'time_limit_minutes' => $timeLimitMinutes,
                'total_questions'    => (int) ($test->total_questions ?: count($formattedQuestions)),
                'started_at'         => $startedAtStr,
                'submitted_at'       => $submittedAtStr,
                'remaining_seconds'  => $remainingSeconds,
                'is_expired'         => $isExpired,
                'live_progress'      => [
                    'attempted_questions'     => $attempt ? (int) $attempt->attempted_questions : 0,
                    'correct_answers'         => $isSubmitted && $attempt ? (int) $attempt->correct_answers : null,
                    'wrong_answers'           => $isSubmitted && $attempt ? (int) $attempt->wrong_answers : null,
                    'not_attempted_questions' => $attempt ? (int) $attempt->not_attempted_questions : count($formattedQuestions),
                    'score'                   => $isSubmitted && $attempt ? (float) $attempt->score : null,
                    'accuracy'                => $isSubmitted && $attempt ? (float) $attempt->accuracy : null,
                ],
                'questions'          => $formattedQuestions,
            ],
        ]);
    }

    public function getRecentAttemptsForLms(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => 0,
                'message' => 'Unauthenticated.',
                'error'   => 'Unauthenticated.',
            ], 401);
        }

        if ($user->role !== 'student' || $user->status !== 'active') {
            return response()->json([
                'success' => 0,
                'message' => 'Active student account required.',
                'error'   => 'Active student account required.',
            ], 403);
        }

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
            'offered_program_id' => ['nullable', 'integer', 'exists:offered_programs,id'],
            'subject_id' => ['nullable', 'integer', 'exists:subject_tbl,id'],
        ]);

        try {
            $limit = (int) ($validated['limit'] ?? 10);

            $formalAttempts = DB::table('test_attempts as attempts')
                ->join('tests as tests', 'tests.id', '=', 'attempts.test_id')
                ->leftJoin('subject_tbl as subjects', 'subjects.id', '=', 'tests.subject_id')
                ->where('attempts.user_id', $user->id)
                ->where('attempts.status', 'submitted')
                ->when(
                    isset($validated['offered_program_id']),
                    fn ($query) => $query->where('tests.offered_program_id', $validated['offered_program_id'])
                )
                ->when(
                    isset($validated['subject_id']),
                    fn ($query) => $query->where('tests.subject_id', $validated['subject_id'])
                )
                ->selectRaw("
                    attempts.id as record_id,
                    'formal_test' as attempt_type,
                    tests.title as title,
                    tests.title as display_title,
                    tests.scope_type,
                    COALESCE(tests.test_mode, 'test') as test_mode,
                    tests.offered_program_id,
                    tests.subject_id,
                    subjects.subject_name,
                    tests.time_limit_minutes,
                    tests.total_questions,
                    attempts.attempted_questions,
                    attempts.correct_answers,
                    attempts.wrong_answers,
                    attempts.not_attempted_questions,
                    attempts.score,
                    attempts.accuracy,
                    COALESCE(
                        (SELECT fqc.attempt_number FROM freemium_quota_consumptions fqc WHERE fqc.test_attempt_id = attempts.id LIMIT 1),
                        (SELECT COUNT(*) FROM test_attempts t2 WHERE t2.test_id = attempts.test_id AND t2.user_id = attempts.user_id AND t2.id <= attempts.id),
                        1
                    ) as attempt_number,
                    attempts.submitted_at,
                    attempts.created_at
                ")
                ->get()
                ->map(function ($attempt) {
                    $attempt->display_title = $this->buildFormalOrMockAttemptTitle(
                        $attempt->title,
                        $attempt->test_mode
                    );

                    return $attempt;
                });

            $practiceAttempts = DB::table('practice_sessions as sessions')
                ->leftJoin('subject_tbl as subjects', 'subjects.id', '=', 'sessions.subject_id')
                ->where('sessions.user_id', $user->id)
                ->where('sessions.status', 'submitted')
                ->when(
                    isset($validated['offered_program_id']),
                    fn ($query) => $query->where('sessions.offered_program_id', $validated['offered_program_id'])
                )
                ->when(
                    isset($validated['subject_id']),
                    fn ($query) => $query->where('sessions.subject_id', $validated['subject_id'])
                )
                ->selectRaw("
                    sessions.id as record_id,
                    'practice_session' as attempt_type,
                    NULL as title,
                    sessions.scope_type,
                    'practice' as test_mode,
                    sessions.offered_program_id,
                    sessions.subject_id,
                    subjects.subject_name,
                    sessions.time_limit_minutes,
                    sessions.total_questions,
                    sessions.attempted_questions,
                    sessions.correct_answers,
                    sessions.wrong_answers,
                    sessions.not_attempted_questions,
                    sessions.score,
                    sessions.accuracy,
                    1 as attempt_number,
                    sessions.submitted_at,
                    sessions.created_at
                ")
                ->get()
                ->map(function ($attempt) {
                    $attempt->display_title = $this->buildPracticeAttemptTitle(
                        $attempt->scope_type,
                        $attempt->subject_name
                    );

                    return $attempt;
                });

            $recentAttempts = $formalAttempts
                ->concat($practiceAttempts)
                ->sortByDesc(function ($attempt) {
                    return Carbon::parse($attempt->submitted_at ?? $attempt->created_at)->timestamp;
                })
                ->take($limit)
                ->values()
                ->map(function ($attempt) {
                    return [
                        'id' => (int) $attempt->record_id,
                        'attempt_type' => $attempt->attempt_type,
                        'attempt_number' => (int) ($attempt->attempt_number ?? 1),
                        'title' => $attempt->title,
                        'display_title' => $attempt->display_title,
                        'scope_type' => $attempt->scope_type,
                        'test_mode' => $attempt->test_mode,
                        'offered_program_id' => $attempt->offered_program_id ? (int) $attempt->offered_program_id : null,
                        'subject_id' => $attempt->subject_id ? (int) $attempt->subject_id : null,
                        'subject_name' => $attempt->subject_name,
                        'time_limit_minutes' => $attempt->time_limit_minutes ? (int) $attempt->time_limit_minutes : null,
                        'total_questions' => (int) $attempt->total_questions,
                        'attempted_questions' => (int) $attempt->attempted_questions,
                        'correct_answers' => (int) $attempt->correct_answers,
                        'wrong_answers' => (int) $attempt->wrong_answers,
                        'not_attempted_questions' => (int) $attempt->not_attempted_questions,
                        'score' => (float) $attempt->score,
                        'accuracy' => (float) $attempt->accuracy,
                        'submitted_at' => $attempt->submitted_at
                            ? Carbon::parse($attempt->submitted_at)->toISOString()
                            : null,
                    ];
                });

            return response()->json([
                'success' => 1,
                'data' => $recentAttempts,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function submitFormalTestForLms(Request $request)
    {
        $validated = $request->validate([
            'test_id' => ['required', 'integer', 'exists:tests,id'],
            'attempt_id' => ['nullable', 'integer', 'exists:test_attempts,id'],
            'started_at' => ['nullable', 'date'],
            'submitted_at' => ['nullable', 'date'],
            'questions' => ['nullable', 'array'],
            'questions.*.question_id' => ['required_with:questions', 'integer', 'exists:exam_question_tbl,id'],
            'questions.*.selected_option_id' => ['nullable', 'integer', 'exists:exam_question_options_tbl,id'],
            'questions.*.time_spent_seconds' => ['nullable', 'integer', 'min:0'],
        ]);

        $user = $request->user();
        if (!$user || $user->role !== 'student' || $user->status !== 'active') {
            return response()->json([
                'success' => 0,
                'error'   => 'Active student account required.',
            ], 403);
        }

        $test = Test::query()
            ->with(['questions', 'scopes'])
            ->find($validated['test_id']);

        if (!$test) {
            return response()->json([
                'success' => 0,
                'error'   => 'Test not found or access denied.',
            ], 404);
        }

        // Section 4: Authoritative Ownership and Security Verification
        $isOfficialShared = ($test->test_source === 'official' && (bool) $test->is_published);
        $isStudentOwned = (
            (int) $test->created_by === (int) $user->id ||
            FreemiumQuotaConsumption::where('test_id', $test->id)->where('user_id', $user->id)->exists() ||
            TestAttempt::where('test_id', $test->id)->where('user_id', $user->id)->exists()
        );

        if (!$isOfficialShared && !$isStudentOwned) {
            return response()->json([
                'success' => 0,
                'error'   => 'Test not found or access denied.',
            ], 404);
        }

        if (!empty($validated['attempt_id'])) {
            $attemptCheck = TestAttempt::query()
                ->where('id', $validated['attempt_id'])
                ->where('test_id', $test->id)
                ->where('user_id', $user->id)
                ->first();

            if (!$attemptCheck) {
                return response()->json([
                    'success' => 0,
                    'error'   => 'Test attempt not found or access denied.',
                ], 404);
            }
        }

        try {
            $submittedAt = isset($validated['submitted_at'])
                ? $this->normalizeStandardDateTime($validated['submitted_at'])
                : null;

            $startedAt = isset($validated['started_at'])
                ? $this->normalizeStandardDateTime($validated['started_at'])
                : null;

            $submittedQuestionMap = isset($validated['questions'])
                ? collect($validated['questions'])->keyBy(fn (array $question) => (int) $question['question_id'])
                : collect();

            $testQuestionRows = $this->getAllTestQuestionRows($test->id);

            if ($testQuestionRows->isEmpty()) {
                return response()->json([
                    'success' => 0,
                    'error' => 'No questions are attached to the selected test.',
                ], 422);
            }

            return DB::transaction(function () use (
                $user,
                $test,
                $validated,
                $submittedQuestionMap,
                $testQuestionRows,
                $startedAt,
                $submittedAt
            ) {
                $attemptedQuestions = 0;
                $correctAnswers = 0;
                $wrongAnswers = 0;
                $score = 0.0;

                // 1. Lock student's parent WebUser row
                WebUser::query()->where('id', $user->id)->lockForUpdate()->first();

                // 2. Look up attempt (user_id scoped first to use user_id_test_id_index)
                $attempt = TestAttempt::query()
                    ->where('user_id', $user->id)
                    ->where('test_id', $test->id)
                    ->when(
                        !empty($validated['attempt_id']),
                        fn ($query) => $query->where('id', $validated['attempt_id']),
                        fn ($query) => $query->where('status', 'in_progress')->latest('id')
                    )
                    ->first();

                // Section 6: Idempotent submission replay if already submitted
                if ($attempt && $attempt->status === 'submitted') {
                    return response()->json([
                    'success' => 1,
                    'data'    => [
                        'attempt_id'              => $attempt->id,
                        'test_id'                 => $test->id,
                        'attempted_questions'     => $attempt->attempted_questions,
                        'correct_answers'         => $attempt->correct_answers,
                        'wrong_answers'           => $attempt->wrong_answers,
                        'not_attempted_questions' => $attempt->not_attempted_questions,
                        'score'                   => (float) $attempt->score,
                        'accuracy'                => (float) $attempt->accuracy,
                        'submitted_at'            => $attempt->submitted_at ? Carbon::parse($attempt->getRawOriginal('submitted_at') ?? $attempt->submitted_at, 'UTC')->toISOString() : null,
                        'status'                  => $attempt->status,
                    ],
                ]);
            }

            if (!$attempt) {
                // Resolve entitlement
                $targetContext = [
                    'offered_program_id' => $test->offered_program_id ? (int) $test->offered_program_id : null,
                    'subject_id'         => $test->subject_id ? (int) $test->subject_id : null,
                ];
                $unitIds = $test->scopes()->pluck('unit_id')->filter()->values()->all();
                if (!empty($unitIds)) {
                    $targetContext['unit_ids'] = $unitIds;
                }

                $decision = $this->entitlementResolver->resolve(
                    $user,
                    $targetContext,
                    'perm_mcqs',
                    $test->test_mode === 'mock' ? 'mock' : 'formal_test'
                );

                if ($decision->isDenied()) {
                    return response()->json([
                        'success'       => 0,
                        'error'         => 'You do not have permission to attempt this assessment.',
                        'error_code'    => 'FORBIDDEN',
                        'denial_reason' => $decision->getDenialReason(),
                    ], 403);
                }

                if ($decision->isPaid()) {
                    $completedAttemptsCount = TestAttempt::query()
                        ->where('test_id', $test->id)
                        ->where('user_id', $user->id)
                        ->count();
                    $attemptNumber = $completedAttemptsCount + 1;
                    $eventType = ($attemptNumber === 1) ? 'attempt_start' : 'retake_start';
                } else {
                    $eligibility = $this->quotaConsumptionService->getTestAttemptEligibility($user, $test->id);

                    if (!$eligibility['can_attempt']) {
                        return response()->json([
                            'success'       => 0,
                            'error'         => $eligibility['denial_reason'] ?? 'Quota exceeded or retakes not permitted.',
                            'error_code'    => 'QUOTA_EXHAUSTED',
                            'denial_reason' => $eligibility['denial_reason'],
                        ], 403);
                    }

                    $attemptNumber = (int) $eligibility['attempt_number'];
                    $eventType = ($attemptNumber === 1) ? 'attempt_start' : 'retake_start';
                }

                $attempt = TestAttempt::create([
                    'test_id'                 => $test->id,
                    'user_id'                 => $user->id,
                    'attempted_questions'     => 0,
                    'correct_answers'         => 0,
                    'wrong_answers'           => 0,
                    'not_attempted_questions' => $testQuestionRows->count(),
                    'score'                   => 0,
                    'accuracy'                => 0,
                    'started_at'              => $startedAt ?? now(),
                    'status'                  => 'in_progress',
                ]);

                if (!$decision->isPaid()) {
                    $this->quotaConsumptionService->consumeAttemptQuota(
                        $user,
                        $test,
                        $attemptNumber,
                        $eventType,
                        [
                            'test_attempt_id'    => $attempt->id,
                            'is_legacy_fallback' => $decision->isLegacyFree(),
                            'primary_campaign'   => $decision->isFreemium() && $decision->getCampaignId() ? FreemiumCampaign::find($decision->getCampaignId()) : null,
                        ]
                    );
                }
            } elseif ($startedAt && !$attempt->started_at) {
                $attempt->started_at = $startedAt;
                $attempt->save();
            }

            $submittedAt = $submittedAt
                ?? $this->normalizeStandardDateTime($attempt->submitted_at ?? now());

            $isAttemptExpired = $this->isAttemptExpired($attempt, $test);

            $perUnitQuestionIds = [];

            foreach ($testQuestionRows as $testQuestionRow) {
                $submittedQuestion = !$isAttemptExpired
                    ? $submittedQuestionMap->get((int) $testQuestionRow->question_id)
                    : null;

                if ($submittedQuestion) {
                    $selectedOptionId = $submittedQuestion['selected_option_id'] ?? null;
                    $isAttempted = !empty($selectedOptionId);
                    $isCorrect = $isAttempted
                        ? ((int) $selectedOptionId === (int) $testQuestionRow->correct_option_id)
                        : null;

                    TestAttemptQuestion::updateOrCreate(
                        [
                            'attempt_id' => $attempt->id,
                            'question_id' => (int) $testQuestionRow->question_id,
                        ],
                        [
                            'question_order' => (int) $testQuestionRow->question_order,
                            'selected_option_id' => $selectedOptionId,
                            'is_attempted' => $isAttempted,
                            'is_correct' => $isCorrect,
                            'marks' => (float) $testQuestionRow->marks,
                            'obtained_marks' => $isCorrect === true ? (float) $testQuestionRow->marks : 0.0,
                            'time_spent_seconds' => $submittedQuestion['time_spent_seconds'] ?? null,
                        ]
                    );
                }

                $perUnitQuestionIds[(int) $testQuestionRow->unit_id][] = (int) $testQuestionRow->question_id;
            }

            $storedAttemptQuestions = TestAttemptQuestion::query()
                ->where('attempt_id', $attempt->id)
                ->get()
                ->keyBy('question_id');

            foreach ($testQuestionRows as $testQuestionRow) {
                $attemptQuestion = $storedAttemptQuestions->get((int) $testQuestionRow->question_id);

                if (!$attemptQuestion) {
                    continue;
                }

                $isAttempted = (bool) $attemptQuestion->is_attempted;
                $isCorrect = $attemptQuestion->is_correct;
                $selectedOptionId = $attemptQuestion->selected_option_id;

                if ($isAttempted) {
                    $attemptedQuestions++;
                    if ($isCorrect) {
                        $correctAnswers++;
                        $score += (float) $attemptQuestion->obtained_marks;
                    } else {
                        $wrongAnswers++;
                    }
                }

                $this->updateQuestionStatistic(
                    (int) $testQuestionRow->question_id,
                    $isAttempted,
                    $isCorrect,
                    $submittedAt
                );

                if ($selectedOptionId) {
                    $this->updateQuestionOptionStatistic(
                        (int) $testQuestionRow->question_id,
                        (int) $selectedOptionId
                    );
                }

                $this->updateStudentQuestionProgressSummary(
                    $user->id,
                    (int) $testQuestionRow->question_id,
                    (int) $test->offered_program_id,
                    (int) $test->subject_id,
                    (int) $testQuestionRow->unit_id,
                    $isAttempted,
                    $isCorrect,
                    $submittedAt
                );
            }

            $notAttemptedQuestions = $testQuestionRows->count() - $attemptedQuestions;
            $accuracy = $attemptedQuestions > 0 ? round(($correctAnswers / $attemptedQuestions) * 100, 2) : 0;

            $attempt->update([
                'attempted_questions' => $attemptedQuestions,
                'correct_answers' => $correctAnswers,
                'wrong_answers' => $wrongAnswers,
                'not_attempted_questions' => $notAttemptedQuestions,
                'score' => round($score, 2),
                'accuracy' => $accuracy,
                'submitted_at' => $submittedAt,
                'status' => 'submitted',
            ]);

            $this->refreshStudentSubjectProgressSummary(
                $user->id,
                (int) $test->offered_program_id,
                (int) $test->subject_id,
                $submittedAt
            );

            foreach (array_keys($perUnitQuestionIds) as $unitId) {
                $this->refreshStudentUnitProgressSummary(
                    $user->id,
                    (int) $test->offered_program_id,
                    (int) $unitId,
                    $submittedAt
                );
            }

            $activityContext = $this->buildFormalActivityContext(
                $test,
                array_keys($perUnitQuestionIds)
            );

            $this->recordStudentActivity(
                $user->id,
                $test->test_mode === 'mock' ? 'mock_completed' : 'formal_test_completed',
                $test->title,
                $activityContext['description'],
                (int) $test->offered_program_id,
                (int) $test->subject_id,
                null,
                $attempt->id,
                'test_attempt',
                [
                    'subject_name' => $activityContext['subject_name'],
                    'scope_label' => $activityContext['scope_label'],
                    'unit_labels' => $activityContext['unit_labels'],
                    'test_id' => $test->id,
                    'test_mode' => $test->test_mode,
                    'scope_type' => $test->scope_type,
                    'total_questions' => $testQuestionRows->count(),
                    'attempted_questions' => $attemptedQuestions,
                    'correct_answers' => $correctAnswers,
                    'wrong_answers' => $wrongAnswers,
                    'not_attempted_questions' => $notAttemptedQuestions,
                    'score' => round($score, 2),
                    'accuracy' => $accuracy,
                ],
                $submittedAt
            );

                return response()->json([
                    'success' => 1,
                    'data' => [
                        'attempt_id' => $attempt->id,
                        'test_id' => $test->id,
                        'attempted_questions' => $attemptedQuestions,
                        'correct_answers' => $correctAnswers,
                        'wrong_answers' => $wrongAnswers,
                        'not_attempted_questions' => $notAttemptedQuestions,
                        'score' => round($score, 2),
                        'accuracy' => $accuracy,
                        'submitted_at' => $attempt->submitted_at ? Carbon::parse($attempt->getRawOriginal('submitted_at') ?? $attempt->submitted_at, 'UTC')->toISOString() : null,
                        'status' => $attempt->status,
                    ],
                ]);
            }, 3);
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'uk_test_user_attempt_event')) {
                return response()->json([
                    'success'    => 0,
                    'error'      => 'A concurrent attempt or retake is already active for this test.',
                    'error_code' => 'CONCURRENT_ATTEMPT_CONFLICT',
                ], 409);
            }

            if ($this->isDeadlockOrLockTimeout($e)) {
                return response()->json([
                    'success'    => 0,
                    'error'      => 'A temporary database concurrency conflict occurred. Please retry your request.',
                    'error_code' => 'CONCURRENT_ATTEMPT_CONFLICT',
                ], 409);
            }

            Log::error('Formal test submission query exception', [
                'test_id'    => $test->id,
                'student_id' => $user->id,
                'attempt_id' => $validated['attempt_id'] ?? null,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => 0,
                'error'   => 'A database error occurred while submitting the test.',
            ], 500);
        } catch (QuotaLimitExceededException $e) {
            return response()->json([
                'success'       => 0,
                'error'         => $e->getMessage(),
                'error_code'    => 'QUOTA_EXHAUSTED',
                'denial_reason' => $e->getMessage(),
            ], 403);
        } catch (\Throwable $e) {
            if ($this->isDeadlockOrLockTimeout($e)) {
                return response()->json([
                    'success'    => 0,
                    'error'      => 'A temporary database concurrency conflict occurred. Please retry your request.',
                    'error_code' => 'CONCURRENT_ATTEMPT_CONFLICT',
                ], 409);
            }

            Log::error('Formal test submission unexpected exception', [
                'test_id'    => $test->id,
                'student_id' => $user->id,
                'attempt_id' => $validated['attempt_id'] ?? null,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => 0,
                'error'   => 'An unexpected error occurred while submitting the test.',
            ], 500);
        }
    }

    private function getAllTestQuestionRows(int $testId): Collection
    {
        return DB::table('test_questions as tq')
            ->join('exam_question_tbl as questions', 'questions.id', '=', 'tq.question_id')
            ->join('book_unit_topic_tbl as topics', 'topics.id', '=', 'questions.topic_id')
            ->leftJoin('exam_question_options_tbl as options', function ($join) {
                $join->on('options.question_id', '=', 'questions.id')
                    ->where('options.is_answer', 1);
            })
            ->where('tq.test_id', $testId)
            ->select(
                'tq.question_id',
                'tq.question_order',
                'tq.marks',
                'topics.unit_id',
                'questions.topic_id',
                'options.id as correct_option_id'
            )
            ->orderBy('tq.question_order')
            ->get();
    }

    private function getTestQuestionRow(int $testId, int $questionId): ?object
    {
        return $this->getAllTestQuestionRows($testId)
            ->firstWhere('question_id', $questionId);
    }

    private function refreshTestAttemptProgress(TestAttempt $attempt): void
    {
        $test = Test::findOrFail($attempt->test_id);
        $attemptQuestions = TestAttemptQuestion::query()
            ->where('attempt_id', $attempt->id)
            ->get();

        $attemptedQuestions = $attemptQuestions->where('is_attempted', true)->count();
        $correctAnswers = $attemptQuestions->where('is_correct', true)->count();
        $wrongAnswers = $attemptQuestions->where('is_attempted', true)->where('is_correct', false)->count();
        $score = round((float) $attemptQuestions->sum('obtained_marks'), 2);
        $notAttemptedQuestions = max($test->total_questions - $attemptedQuestions, 0);
        $accuracy = $attemptedQuestions > 0 ? round(($correctAnswers / $attemptedQuestions) * 100, 2) : 0;

        $attempt->update([
            'attempted_questions' => $attemptedQuestions,
            'correct_answers' => $correctAnswers,
            'wrong_answers' => $wrongAnswers,
            'not_attempted_questions' => $notAttemptedQuestions,
            'score' => $score,
            'accuracy' => $accuracy,
        ]);
    }

    private function buildFormalActivityContext(Test $test, array $unitIds): array
    {
        $subjectName = Subject::query()
            ->where('id', $test->subject_id)
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

        $scopeLabel = match ($test->scope_type) {
            'chapter' => $unitLabels[0] ?? 'selected chapter',
            'multiple_chapters' => count($unitLabels) <= 3 && count($unitLabels) > 0
                ? implode(', ', $unitLabels)
                : count($unitIds) . ' chapters',
            'full_book' => 'full book',
            default => 'selected scope',
        };

        $testLabel = $test->test_mode === 'mock' ? 'mock test' : 'formal test';
        $descriptionParts = array_filter([
            'Completed a ' . $testLabel,
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

    private function buildPracticeAttemptTitle(?string $scopeType, ?string $subjectName): string
    {
        $prefix = $subjectName ? "{$subjectName} " : '';

        return match ($scopeType) {
            'chapter' => "{$prefix}Chapter Practice",
            'multiple_chapters' => "{$prefix}Selected Units Practice",
            'full_book' => "{$prefix}Full Book Practice",
            default => "{$prefix}Practice Session",
        };
    }

    private function buildFormalOrMockAttemptTitle(?string $title, string $testMode): string
    {
        $title = $title ? trim($title) : '';
        if ($testMode === 'mock') {
            if ($title === '') {
                return 'Mock Exam';
            }
            if (!preg_match('/^mock/i', $title)) {
                return "Mock Exam: {$title}";
            }
            return $title;
        }

        return $title !== '' ? $title : 'Formal Test';
    }

    private function updateQuestionStatistic(int $questionId, bool $isAttempted, ?bool $isCorrect, Carbon $submittedAt): void
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

    private function updateQuestionOptionStatistic(int $questionId, int $optionId): void
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
        $questionOptionStatistic->formal_selection_count += 1;
        $questionOptionStatistic->save();
    }

    private function updateStudentQuestionProgressSummary(
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
            $summary->formal_attempts += 1;

            if ($isCorrect) {
                $summary->formal_correct += 1;
            } else {
                $summary->formal_wrong += 1;
            }
        }

        $summary->last_tested_at = $submittedAt;

        $totalAttempts = $summary->practice_attempts + $summary->formal_attempts;
        $totalCorrect = $summary->practice_correct + $summary->formal_correct;
        $combinedAccuracy = $totalAttempts > 0 ? ($totalCorrect / $totalAttempts) * 100 : 0;

        $summary->is_mastered = $totalAttempts >= self::MASTERED_MIN_ATTEMPTS
            && $combinedAccuracy >= self::MASTERED_MIN_ACCURACY;

        $summary->save();
    }

    private function refreshStudentSubjectProgressSummary(
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

        $totalQuestions = $this->countSubjectMcqs($subjectId);
        $practiceAttempted = (int) ($aggregated->practice_attempted ?? 0);
        $practiceCorrect = (int) ($aggregated->practice_correct ?? 0);
        $practiceWrong = (int) ($aggregated->practice_wrong ?? 0);
        $formalAttempted = (int) ($aggregated->formal_attempted ?? 0);
        $formalCorrect = (int) ($aggregated->formal_correct ?? 0);
        $formalWrong = (int) ($aggregated->formal_wrong ?? 0);
        $distinctQuestionsSeen = (int) ($aggregated->distinct_questions_seen ?? 0);

        StudentSubjectProgressSummary::updateOrCreate(
            [
                'user_id' => $userId,
                'offered_program_id' => $offeredProgramId,
                'subject_id' => $subjectId,
            ],
            [
                'total_questions' => $totalQuestions,
                'practice_attempted' => $practiceAttempted,
                'practice_correct' => $practiceCorrect,
                'practice_wrong' => $practiceWrong,
                'formal_attempted' => $formalAttempted,
                'formal_correct' => $formalCorrect,
                'formal_wrong' => $formalWrong,
                'distinct_questions_seen' => $distinctQuestionsSeen,
                'practice_accuracy' => $this->calculatePercentage($practiceCorrect, $practiceAttempted),
                'formal_accuracy' => $this->calculatePercentage($formalCorrect, $formalAttempted),
                'last_tested_at' => $submittedAt,
            ]
        );
    }

    private function refreshStudentUnitProgressSummary(
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

        $totalQuestions = $this->countUnitMcqs($unitId);
        $practiceAttempted = (int) ($aggregated->practice_attempted ?? 0);
        $practiceCorrect = (int) ($aggregated->practice_correct ?? 0);
        $practiceWrong = (int) ($aggregated->practice_wrong ?? 0);
        $formalAttempted = (int) ($aggregated->formal_attempted ?? 0);
        $formalCorrect = (int) ($aggregated->formal_correct ?? 0);
        $formalWrong = (int) ($aggregated->formal_wrong ?? 0);
        $distinctQuestionsSeen = (int) ($aggregated->distinct_questions_seen ?? 0);

        StudentUnitProgressSummary::updateOrCreate(
            [
                'user_id' => $userId,
                'offered_program_id' => $offeredProgramId,
                'unit_id' => $unitId,
            ],
            [
                'total_questions' => $totalQuestions,
                'practice_attempted' => $practiceAttempted,
                'practice_correct' => $practiceCorrect,
                'practice_wrong' => $practiceWrong,
                'formal_attempted' => $formalAttempted,
                'formal_correct' => $formalCorrect,
                'formal_wrong' => $formalWrong,
                'distinct_questions_seen' => $distinctQuestionsSeen,
                'practice_accuracy' => $this->calculatePercentage($practiceCorrect, $practiceAttempted),
                'formal_accuracy' => $this->calculatePercentage($formalCorrect, $formalAttempted),
                'last_tested_at' => $submittedAt,
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

    /**
     * Determine if an exception represents a database deadlock or lock wait timeout.
     */
    protected function isDeadlockOrLockTimeout(\Throwable $e): bool
    {
        $message = $e->getMessage();

        if (
            str_contains($message, '1213') ||
            str_contains($message, '1205') ||
            str_contains($message, 'Deadlock') ||
            str_contains($message, 'Lock wait timeout') ||
            str_contains($message, '40001')
        ) {
            return true;
        }

        if ($e instanceof \Illuminate\Database\QueryException && $e->getPrevious()) {
            $prevMsg = $e->getPrevious()->getMessage();
            if (
                str_contains($prevMsg, '1213') ||
                str_contains($prevMsg, '1205') ||
                str_contains($prevMsg, 'Deadlock') ||
                str_contains($prevMsg, 'Lock wait timeout') ||
                str_contains($prevMsg, '40001')
            ) {
                return true;
            }
        }

        return false;
    }

    protected function isAttemptExpired(?TestAttempt $attempt, Test $test): bool
    {
        if (!$attempt) {
            return false;
        }

        if ($attempt->status === 'expired') {
            return true;
        }

        if ($attempt->status === 'submitted') {
            return false;
        }

        $timeLimitMinutes = $test->time_limit_minutes ? (int) $test->time_limit_minutes : null;
        if ($timeLimitMinutes !== null && $timeLimitMinutes > 0) {
            $limitSeconds = $timeLimitMinutes * 60;
            $startTs = Carbon::parse($attempt->started_at ?? $attempt->created_at)->timestamp;
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
