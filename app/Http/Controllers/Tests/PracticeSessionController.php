<?php

namespace App\Http\Controllers\Tests;

use App\Http\Controllers\Controller;
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
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PracticeSessionController extends Controller
{
    private const MASTERED_MIN_ATTEMPTS = 3;
    private const MASTERED_MIN_ACCURACY = 80;

    public function generateSelectedUnitsPracticeSessionForLms(Request $request)
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
            'scope_type' => ['nullable', 'string', 'in:chapter,multiple_chapters,full_book'],
        ]);

        try {
            $user = $request->user();
            $unitIds = array_values(array_unique($validated['unit_ids']));
            $scopeType = $validated['scope_type']
                ?? (count($unitIds) === 1 ? 'chapter' : 'multiple_chapters');

            return $this->createPracticeSessionResponse(
                $user->id,
                (int) $validated['offered_program_id'],
                (int) $validated['subject_id'],
                $unitIds,
                $scopeType,
                (int) $validated['total_questions'],
                $validated['time_limit_minutes'] ?? null
            );
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function generateFullBookPracticeSessionForLms(Request $request)
    {
        $validated = $request->validate([
            'offered_program_id' => ['required', 'integer', 'exists:offered_programs,id'],
            'subject_id' => ['required', 'integer', 'exists:subject_tbl,id'],
            'book_id' => ['required', 'integer', 'exists:book_tbl,id'],
            'total_questions' => ['required', 'integer', 'min:1'],
            'time_limit_minutes' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $user = $request->user();

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

            return $this->createPracticeSessionResponse(
                $user->id,
                (int) $validated['offered_program_id'],
                (int) $validated['subject_id'],
                $unitIds,
                'full_book',
                (int) $validated['total_questions'],
                $validated['time_limit_minutes'] ?? null,
                (int) $validated['book_id']
            );
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function createPracticeSessionResponse(
        int $userId,
        int $offeredProgramId,
        int $subjectId,
        array $unitIds,
        string $scopeType,
        int $totalQuestions,
        ?int $timeLimitMinutes,
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
            $session = PracticeSession::create([
                'user_id' => $userId,
                'offered_program_id' => $offeredProgramId,
                'subject_id' => $subjectId,
                'scope_type' => $scopeType,
                'question_type' => 'mcq',
                'time_limit_minutes' => $timeLimitMinutes,
                'total_questions' => $totalQuestions,
                'attempted_questions' => 0,
                'correct_answers' => 0,
                'wrong_answers' => 0,
                'not_attempted_questions' => $totalQuestions,
                'score' => 0,
                'accuracy' => 0,
                'started_at' => now(),
                'status' => 'in_progress',
            ]);

            $timestamp = now();

            PracticeSessionScope::insert(
                collect($unitIds)->map(function (int $unitId) use ($session, $timestamp) {
                    return [
                        'session_id' => $session->id,
                        'unit_id' => $unitId,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                })->all()
            );

            PracticeSessionQuestion::insert(
                $questions->values()->map(function ($question, int $index) use ($session, $timestamp) {
                    return [
                        'session_id' => $session->id,
                        'question_id' => $question->id,
                        'question_order' => $index + 1,
                        'is_attempted' => false,
                        'is_correct' => null,
                        'answer_shown' => false,
                        'selected_option_id' => null,
                        'time_spent_seconds' => null,
                        'practiced_at' => null,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                })->all()
            );

            DB::commit();

            $data = [
                'session_id' => $session->id,
                'offered_program_id' => $offeredProgramId,
                'subject_id' => $subjectId,
                'scope_type' => $scopeType,
                'question_type' => 'mcq',
                'time_limit_minutes' => $session->time_limit_minutes,
                'total_questions' => $session->total_questions,
                'unit_ids' => array_values($unitIds),
                'question_ids' => $questions->pluck('id')->values(),
                'status' => $session->status,
                'started_at' => optional($session->started_at)->toISOString(),
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

    public function savePracticeSessionQuestionProgressForLms(Request $request)
    {
        $validated = $request->validate([
            'session_id' => ['required', 'integer', 'exists:practice_sessions,id'],
            'question_id' => ['required', 'integer', 'exists:exam_question_tbl,id'],
            'selected_option_id' => ['nullable', 'integer', 'exists:exam_question_options_tbl,id'],
            'time_spent_seconds' => ['nullable', 'integer', 'min:0'],
            'answer_shown' => ['nullable', 'boolean'],
            'practiced_at' => ['nullable', 'date'],
        ]);

        try {
            $user = $request->user();

            $session = PracticeSession::query()
                ->where('id', $validated['session_id'])
                ->where('user_id', $user->id)
                ->firstOrFail();

            $sessionQuestionRow = $this->getSessionQuestionRow($session->id, (int) $validated['question_id']);

            if (!$sessionQuestionRow) {
                return response()->json([
                    'success' => 0,
                    'error' => 'Selected question does not belong to the practice session.',
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
                    'is_attempted' => $isAttempted,
                    'is_correct' => $isCorrect,
                    'answer_shown' => (bool) ($validated['answer_shown'] ?? false),
                    'time_spent_seconds' => $validated['time_spent_seconds'] ?? null,
                    'practiced_at' => $isAttempted
                        ? (isset($validated['practiced_at'])
                            ? $this->normalizeStandardDateTime($validated['practiced_at'])
                            : $this->normalizeStandardDateTime(now()))
                        : null,
                ]);

            $this->refreshPracticeSessionProgress($session);
            $session->refresh();

            return response()->json([
                'success' => 1,
                'data' => [
                    'session_id' => $session->id,
                    'question_id' => (int) $sessionQuestionRow->question_id,
                    'is_attempted' => $isAttempted,
                    'is_correct' => $isCorrect,
                    'status' => $session->status,
                    'attempted_questions' => $session->attempted_questions,
                    'correct_answers' => $session->correct_answers,
                    'wrong_answers' => $session->wrong_answers,
                    'not_attempted_questions' => $session->not_attempted_questions,
                    'score' => (float) $session->score,
                    'accuracy' => (float) $session->accuracy,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function submitPracticeSessionForLms(Request $request)
    {
        $validated = $request->validate([
            'session_id' => ['required', 'integer', 'exists:practice_sessions,id'],
            'submitted_at' => ['nullable', 'date'],
            'questions' => ['nullable', 'array'],
            'questions.*.question_id' => ['required_with:questions', 'integer', 'exists:exam_question_tbl,id'],
            'questions.*.selected_option_id' => ['nullable', 'integer', 'exists:exam_question_options_tbl,id'],
            'questions.*.time_spent_seconds' => ['nullable', 'integer', 'min:0'],
            'questions.*.answer_shown' => ['nullable', 'boolean'],
        ]);

        try {
            $user = $request->user();

            $session = PracticeSession::query()
                ->with('scopes')
                ->where('id', $validated['session_id'])
                ->where('user_id', $user->id)
                ->firstOrFail();

            $submittedAt = isset($validated['submitted_at'])
                ? $this->normalizeStandardDateTime($validated['submitted_at'])
                : $this->normalizeStandardDateTime($session->submitted_at ?? now());

            $submittedQuestionMap = isset($validated['questions'])
                ? collect($validated['questions'])->keyBy(fn (array $question) => (int) $question['question_id'])
                : collect();

            $sessionQuestionRows = $this->getAllSessionQuestionRows($session->id);

            if ($sessionQuestionRows->isEmpty()) {
                return response()->json([
                    'success' => 0,
                    'error' => 'No questions are attached to the selected practice session.',
                ], 422);
            }

            $attemptedQuestions = 0;
            $correctAnswers = 0;
            $wrongAnswers = 0;
            $perUnitIds = [];

            DB::beginTransaction();

            foreach ($sessionQuestionRows as $sessionQuestionRow) {
                $submittedQuestion = $submittedQuestionMap->get((int) $sessionQuestionRow->question_id);

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
                            'is_attempted' => $isAttempted,
                            'is_correct' => $isCorrect,
                            'answer_shown' => (bool) ($submittedQuestion['answer_shown'] ?? false),
                            'time_spent_seconds' => $submittedQuestion['time_spent_seconds'] ?? null,
                            'practiced_at' => $isAttempted ? $submittedAt : null,
                            'updated_at' => now(),
                        ]);
                }

                $perUnitIds[(int) $sessionQuestionRow->unit_id] = true;
            }

            $storedSessionQuestions = PracticeSessionQuestion::query()
                ->where('session_id', $session->id)
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
                    $user->id,
                    (int) $sessionQuestionRow->question_id,
                    (int) $session->offered_program_id,
                    (int) $session->subject_id,
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

            $session->update([
                'attempted_questions' => $attemptedQuestions,
                'correct_answers' => $correctAnswers,
                'wrong_answers' => $wrongAnswers,
                'not_attempted_questions' => $notAttemptedQuestions,
                'score' => round($correctAnswers, 2),
                'accuracy' => $accuracy,
                'submitted_at' => $submittedAt,
                'status' => 'submitted',
            ]);

            $this->refreshStudentSubjectProgressSummaryForPractice(
                $user->id,
                (int) $session->offered_program_id,
                (int) $session->subject_id,
                $submittedAt
            );

            foreach (array_keys($perUnitIds) as $unitId) {
                $this->refreshStudentUnitProgressSummaryForPractice(
                    $user->id,
                    (int) $session->offered_program_id,
                    (int) $unitId,
                    $submittedAt
                );
            }

            $activityContext = $this->buildPracticeActivityContext(
                $session,
                array_keys($perUnitIds)
            );

            $this->recordStudentActivity(
                $user->id,
                'practice_completed',
                $this->buildPracticeActivityTitle($session->scope_type),
                $activityContext['description'],
                (int) $session->offered_program_id,
                (int) $session->subject_id,
                count($perUnitIds) === 1 ? (int) array_key_first($perUnitIds) : null,
                $session->id,
                'practice_session',
                [
                    'subject_name' => $activityContext['subject_name'],
                    'scope_label' => $activityContext['scope_label'],
                    'unit_labels' => $activityContext['unit_labels'],
                    'scope_type' => $session->scope_type,
                    'total_questions' => $sessionQuestionRows->count(),
                    'attempted_questions' => $attemptedQuestions,
                    'correct_answers' => $correctAnswers,
                    'wrong_answers' => $wrongAnswers,
                    'not_attempted_questions' => $notAttemptedQuestions,
                    'score' => round($correctAnswers, 2),
                    'accuracy' => $accuracy,
                ],
                $submittedAt
            );

            DB::commit();

            return response()->json([
                'success' => 1,
                'data' => [
                    'session_id' => $session->id,
                    'attempted_questions' => $attemptedQuestions,
                    'correct_answers' => $correctAnswers,
                    'wrong_answers' => $wrongAnswers,
                    'not_attempted_questions' => $notAttemptedQuestions,
                    'score' => round($correctAnswers, 2),
                    'accuracy' => $accuracy,
                    'submitted_at' => $submittedAt->toISOString(),
                    'status' => $session->status,
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

    private function normalizeStandardDateTime($value): Carbon
    {
        return $value instanceof Carbon
            ? $value->copy()->utc()
            : Carbon::parse($value)->utc();
    }
}
