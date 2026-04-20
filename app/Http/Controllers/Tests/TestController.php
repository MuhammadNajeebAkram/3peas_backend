<?php

namespace App\Http\Controllers\Tests;

use App\Http\Controllers\Controller;
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
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TestController extends Controller
{
    private const MASTERED_MIN_ATTEMPTS = 3;
    private const MASTERED_MIN_ACCURACY = 80;

    public function generateSelectedUnitsFormalTestForLms(Request $request)
    {
        $unitIds = $request->input('unit_ids');

        if (empty($unitIds) && $request->filled('unit_id')) {
            $unitIds = [$request->input('unit_id')];
            $request->merge(['unit_ids' => $unitIds]);
        }

        $validated = $request->validate([
            'offered_program_id' => ['required', 'integer', 'exists:offered_programs,id'],
            'subject_id' => ['required', 'integer', 'exists:subject_tbl,id'],
            'unit_ids' => ['required', 'array', 'min:1'],
            'unit_ids.*' => ['integer', 'exists:book_unit_tbl,id'],
            'total_questions' => ['required', 'integer', 'min:1'],
            'time_limit_minutes' => ['nullable', 'integer', 'min:1'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'marks_per_question' => ['nullable', 'numeric', 'min:0'],
            'scope_type' => ['nullable', 'string', 'in:chapter,multiple_chapters,full_book'],
        ]);

        try {
            $unitIds = array_values(array_unique($validated['unit_ids']));
            $scopeType = $validated['scope_type']
                ?? (count($unitIds) === 1 ? 'chapter' : 'multiple_chapters');

            return $this->createFormalTestResponse(
                (int) $validated['offered_program_id'],
                (int) $validated['subject_id'],
                $unitIds,
                $scopeType,
                (int) $validated['total_questions'],
                $validated['time_limit_minutes'] ?? null,
                $validated['title'] ?? null,
                $validated['description'] ?? null,
                isset($validated['marks_per_question']) ? (float) $validated['marks_per_question'] : 1.0
            );
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function generateFullBookFormalTestForLms(Request $request)
    {
        $validated = $request->validate([
            'offered_program_id' => ['required', 'integer', 'exists:offered_programs,id'],
            'subject_id' => ['required', 'integer', 'exists:subject_tbl,id'],
            'book_id' => ['required', 'integer', 'exists:book_tbl,id'],
            'total_questions' => ['required', 'integer', 'min:1'],
            'time_limit_minutes' => ['nullable', 'integer', 'min:1'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'marks_per_question' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
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
                    'error' => 'No active units found for the selected book.',
                ], 422);
            }

            return $this->createFormalTestResponse(
                (int) $validated['offered_program_id'],
                (int) $validated['subject_id'],
                $unitIds,
                'full_book',
                (int) $validated['total_questions'],
                $validated['time_limit_minutes'] ?? null,
                $validated['title'] ?? null,
                $validated['description'] ?? null,
                isset($validated['marks_per_question']) ? (float) $validated['marks_per_question'] : 1.0,
                (int) $validated['book_id']
            );
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function createFormalTestResponse(
        int $offeredProgramId,
        int $subjectId,
        array $unitIds,
        string $scopeType,
        int $totalQuestions,
        ?int $timeLimitMinutes,
        ?string $title,
        ?string $description,
        float $marksPerQuestion,
        ?int $bookId = null
    ) {
        $questions = DB::table('exam_question_tbl as questions')
            ->join('book_unit_topic_tbl as topics', 'topics.id', '=', 'questions.topic_id')
            ->whereIn('topics.unit_id', $unitIds)
            ->where('questions.is_mcq', 1)
            ->where('questions.activate', 1)
            ->select('questions.id')
            ->distinct()
            ->inRandomOrder()
            ->limit($totalQuestions)
            ->get();

        if ($questions->count() < $totalQuestions) {
            return response()->json([
                'success' => 0,
                'error' => 'Not enough active MCQs available for the selected scope.',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $generatedTitle = $title ?: $this->buildGeneratedFormalTestTitle($scopeType, $subjectId, $unitIds, $bookId);

            $test = Test::create([
                'offered_program_id' => $offeredProgramId,
                'subject_id' => $subjectId,
                'created_by' => null,
                'title' => $generatedTitle,
                'description' => $description,
                'test_source' => 'system',
                'test_mode' => 'test',
                'scope_type' => $scopeType,
                'question_type' => 'mcq',
                'time_limit_minutes' => $timeLimitMinutes,
                'total_questions' => $totalQuestions,
                'is_published' => true,
                'published_at' => now(),
            ]);

            $timestamp = now();

            TestScope::insert(
                collect($unitIds)->map(function (int $unitId) use ($test, $timestamp) {
                    return [
                        'test_id' => $test->id,
                        'unit_id' => $unitId,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                })->all()
            );

            TestQuestion::insert(
                $questions->values()->map(function ($question, int $index) use ($test, $marksPerQuestion, $timestamp) {
                    return [
                        'test_id' => $test->id,
                        'question_id' => $question->id,
                        'question_order' => $index + 1,
                        'marks' => $marksPerQuestion,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                })->all()
            );

            DB::commit();

            $data = [
                'test_id' => $test->id,
                'offered_program_id' => $offeredProgramId,
                'subject_id' => $subjectId,
                'title' => $test->title,
                'description' => $test->description,
                'test_source' => $test->test_source,
                'test_mode' => $test->test_mode,
                'scope_type' => $test->scope_type,
                'question_type' => $test->question_type,
                'time_limit_minutes' => $test->time_limit_minutes,
                'total_questions' => $test->total_questions,
                'marks_per_question' => round($marksPerQuestion, 2),
                'unit_ids' => array_values($unitIds),
                'question_ids' => $questions->pluck('id')->values(),
                'is_published' => (bool) $test->is_published,
                'published_at' => optional($test->published_at)->toISOString(),
            ];

            if ($bookId !== null) {
                $data['book_id'] = $bookId;
            }

            return response()->json([
                'success' => 1,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
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

    public function saveFormalTestQuestionProgressForLms(Request $request)
    {
        $validated = $request->validate([
            'test_id' => ['required', 'integer', 'exists:tests,id'],
            'question_id' => ['required', 'integer', 'exists:exam_question_tbl,id'],
            'selected_option_id' => ['nullable', 'integer', 'exists:exam_question_options_tbl,id'],
            'time_spent_seconds' => ['nullable', 'integer', 'min:0'],
            'started_at' => ['nullable', 'date'],
        ]);

        try {
            $user = $request->user();
            $test = Test::findOrFail($validated['test_id']);

            $testQuestionRow = $this->getTestQuestionRow($test->id, (int) $validated['question_id']);

            if (!$testQuestionRow) {
                return response()->json([
                    'success' => 0,
                    'error' => 'Selected question does not belong to the test.',
                ], 422);
            }

            DB::beginTransaction();

            $attempt = TestAttempt::query()
                ->where('test_id', $test->id)
                ->where('user_id', $user->id)
                ->where('status', 'in_progress')
                ->latest('id')
                ->first();

            if (!$attempt) {
                $attempt = TestAttempt::create([
                    'test_id' => $test->id,
                    'user_id' => $user->id,
                    'attempted_questions' => 0,
                    'correct_answers' => 0,
                    'wrong_answers' => 0,
                    'not_attempted_questions' => $test->total_questions,
                    'score' => 0,
                    'accuracy' => 0,
                    'started_at' => isset($validated['started_at']) ? Carbon::parse($validated['started_at']) : now(),
                    'status' => 'in_progress',
                ]);
            }

            $selectedOptionId = $validated['selected_option_id'] ?? null;
            $isAttempted = !empty($selectedOptionId);
            $isCorrect = $isAttempted
                ? ((int) $selectedOptionId === (int) $testQuestionRow->correct_option_id)
                : null;
            $marks = (float) $testQuestionRow->marks;
            $obtainedMarks = $isCorrect === true ? $marks : 0.0;

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
                    'marks' => $marks,
                    'obtained_marks' => $obtainedMarks,
                    'time_spent_seconds' => $validated['time_spent_seconds'] ?? null,
                ]
            );

            $this->refreshTestAttemptProgress($attempt);

            DB::commit();

            $attempt->refresh();

            return response()->json([
                'success' => 1,
                'data' => [
                    'attempt_id' => $attempt->id,
                    'test_id' => $test->id,
                    'question_id' => (int) $testQuestionRow->question_id,
                    'is_attempted' => $isAttempted,
                    'is_correct' => $isCorrect,
                    'status' => $attempt->status,
                    'attempted_questions' => $attempt->attempted_questions,
                    'correct_answers' => $attempt->correct_answers,
                    'wrong_answers' => $attempt->wrong_answers,
                    'not_attempted_questions' => $attempt->not_attempted_questions,
                    'score' => (float) $attempt->score,
                    'accuracy' => (float) $attempt->accuracy,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getRecentAttemptsForLms(Request $request)
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
            'offered_program_id' => ['nullable', 'integer', 'exists:offered_programs,id'],
            'subject_id' => ['nullable', 'integer', 'exists:subject_tbl,id'],
        ]);

        try {
            $user = $request->user();
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
                    tests.test_mode,
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
                    attempts.submitted_at,
                    attempts.created_at
                ")
                ->get();

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

        try {
            $user = $request->user();

            $test = Test::query()
                ->with(['questions', 'scopes'])
                ->findOrFail($validated['test_id']);

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

            $attemptedQuestions = 0;
            $correctAnswers = 0;
            $wrongAnswers = 0;
            $score = 0.0;

            DB::beginTransaction();

            $attempt = TestAttempt::query()
                ->when(
                    !empty($validated['attempt_id']),
                    fn ($query) => $query->where('id', $validated['attempt_id']),
                    fn ($query) => $query->where('status', 'in_progress')->latest('id')
                )
                ->where('test_id', $test->id)
                ->where('user_id', $user->id)
                ->first();

            if (!$attempt) {
                $attempt = TestAttempt::create([
                    'test_id' => $test->id,
                    'user_id' => $user->id,
                    'attempted_questions' => 0,
                    'correct_answers' => 0,
                    'wrong_answers' => 0,
                    'not_attempted_questions' => $testQuestionRows->count(),
                    'score' => 0,
                    'accuracy' => 0,
                    'started_at' => $startedAt,
                    'status' => 'in_progress',
                ]);
            } elseif ($startedAt && !$attempt->started_at) {
                $attempt->started_at = $startedAt;
                $attempt->save();
            }

            $submittedAt = $submittedAt
                ?? $this->normalizeStandardDateTime($attempt->submitted_at ?? now());

            $perUnitQuestionIds = [];

            foreach ($testQuestionRows as $testQuestionRow) {
                $submittedQuestion = $submittedQuestionMap->get((int) $testQuestionRow->question_id);

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

            DB::commit();

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
                    'submitted_at' => $submittedAt->toISOString(),
                    'status' => $attempt->status,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
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

    private function normalizeStandardDateTime($value): Carbon
    {
        return $value instanceof Carbon
            ? $value->copy()->utc()
            : Carbon::parse($value)->utc();
    }
}
