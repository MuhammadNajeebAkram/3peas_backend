<?php

namespace App\Http\Controllers\Stats;

use App\Http\Controllers\Controller;
use App\Models\StudentQuestionProgressSummary;
use App\Models\StudentSubjectProgressSummary;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentSubjectProgressSummaryController extends Controller
{
    private const WEAK_UNIT_MIN_ATTEMPTS = 10;
    private const WEAK_UNIT_THRESHOLD = 60;
    private const CRITICAL_UNIT_THRESHOLD = 40;
    private const PREPARATION_PRACTICE_WEIGHT = 0.25;
    private const PREPARATION_FORMAL_WEIGHT = 0.30;
    private const PREPARATION_COVERAGE_WEIGHT = 0.25;
    private const PREPARATION_MASTERY_WEIGHT = 0.10;
    private const PREPARATION_RECENCY_WEIGHT = 0.10;

    public function getStudentSubjectMcqStatsForLms(Request $request)
    {
        $validated = $request->validate([
            'offered_program_id' => ['required', 'integer', 'exists:offered_programs,id'],
            'subject_id' => ['required', 'integer', 'exists:subject_tbl,id'],
            'stats_mode' => ['required', 'string', 'in:practice,formal'],
        ]);

        try {
            $user = $request->user();
            $isPracticeMode = $validated['stats_mode'] === 'practice';

            $attemptsExpression = $isPracticeMode
                ? '(practice_attempts + formal_attempts)'
                : 'formal_attempts';

            $correctExpression = $isPracticeMode
                ? '(practice_correct + formal_correct)'
                : 'formal_correct';

            $totalMcqsCount = DB::table('exam_question_tbl as questions')
                ->join('book_unit_topic_tbl as topics', 'topics.id', '=', 'questions.topic_id')
                ->join('book_unit_tbl as units', 'units.id', '=', 'topics.unit_id')
                ->join('book_tbl as books', 'books.id', '=', 'units.book_id')
                ->where('books.subject_id', $validated['subject_id'])
                ->where('questions.is_mcq', 1)
                ->where('questions.activate', 1)
                ->distinct()
                ->count('questions.id');

            $studentQuestionSummaryQuery = StudentQuestionProgressSummary::query()
                ->where('user_id', $user->id)
                ->where('offered_program_id', $validated['offered_program_id'])
                ->where('subject_id', $validated['subject_id']);

            $attemptedQuestions = (clone $studentQuestionSummaryQuery)
                ->whereRaw("{$attemptsExpression} > 0")
                ->count();

            $correctQuestions = (clone $studentQuestionSummaryQuery)
                ->whereRaw("{$correctExpression} > 0")
                ->count();

            $wrongQuestions = (clone $studentQuestionSummaryQuery)
                ->whereRaw("{$attemptsExpression} > 0")
                ->whereRaw("{$correctExpression} = 0")
                ->count();

            $notAttemptedQuestions = max($totalMcqsCount - $attemptedQuestions, 0);

            $accuracyPercentage = $attemptedQuestions > 0
                ? round(($correctQuestions / $attemptedQuestions) * 100, 2)
                : 0;

            return response()->json([
                'success' => 1,
                'data' => [
                    'offered_program_id' => (int) $validated['offered_program_id'],
                    'subject_id' => (int) $validated['subject_id'],
                    'stats_mode' => $validated['stats_mode'],
                    'total_mcqs_count' => $totalMcqsCount,
                    'attempted_questions' => $attemptedQuestions,
                    'correct_questions' => $correctQuestions,
                    'wrong_questions' => $wrongQuestions,
                    'not_attempted_questions' => $notAttemptedQuestions,
                    'accuracy_percentage' => $accuracyPercentage,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getStudentWeakUnitsForLms(Request $request)
    {
        $validated = $request->validate([
            'offered_program_id' => ['required', 'integer', 'exists:offered_programs,id'],
            'subject_id' => ['required', 'integer', 'exists:subject_tbl,id'],
        ]);

        try {
            $user = $request->user();

            $unitStats = DB::table('book_unit_tbl as units')
                ->join('book_tbl as books', 'books.id', '=', 'units.book_id')
                ->leftJoin('book_unit_topic_tbl as topics', 'topics.unit_id', '=', 'units.id')
                ->leftJoin('exam_question_tbl as questions', function ($join) {
                    $join->on('questions.topic_id', '=', 'topics.id')
                        ->where('questions.is_mcq', 1)
                        ->where('questions.activate', 1);
                })
                ->leftJoin('student_question_progress_summaries as sqps', function ($join) use ($user, $validated) {
                    $join->on('sqps.question_id', '=', 'questions.id')
                        ->where('sqps.user_id', '=', $user->id)
                        ->where('sqps.offered_program_id', '=', $validated['offered_program_id']);
                })
                ->where('books.subject_id', $validated['subject_id'])
                ->where('units.activate', 1)
                ->groupBy('units.id', 'units.unit_name', 'units.unit_no')
                ->orderBy('units.unit_no')
                ->orderBy('units.id')
                ->selectRaw('
                    units.id as unit_id,
                    units.unit_name,
                    units.unit_no,
                    COUNT(DISTINCT questions.id) as total_questions,
                    COUNT(DISTINCT CASE
                        WHEN COALESCE(sqps.practice_attempts, 0) + COALESCE(sqps.formal_attempts, 0) > 0
                        THEN questions.id
                    END) as attempted_questions,
                    COALESCE(SUM(COALESCE(sqps.practice_attempts, 0) + COALESCE(sqps.formal_attempts, 0)), 0) as total_attempts,
                    COALESCE(SUM(COALESCE(sqps.practice_correct, 0) + COALESCE(sqps.formal_correct, 0)), 0) as correct_answers,
                    COALESCE(SUM(COALESCE(sqps.practice_wrong, 0) + COALESCE(sqps.formal_wrong, 0)), 0) as wrong_answers
                ')
                ->get();

            $weakUnits = $unitStats
                ->map(function ($unit) {
                    $totalAttempts = (int) $unit->total_attempts;
                    $correctAnswers = (int) $unit->correct_answers;
                    $wrongAnswers = (int) $unit->wrong_answers;
                    $attemptedQuestions = (int) $unit->attempted_questions;
                    $totalQuestions = (int) $unit->total_questions;

                    $accuracy = $this->calculateAccuracy($correctAnswers, $totalAttempts);
                    $coverage = $this->calculateAccuracy($attemptedQuestions, $totalQuestions);

                    if ($totalAttempts < self::WEAK_UNIT_MIN_ATTEMPTS) {
                        $weaknessLevel = 'insufficient_data';
                    } elseif ($accuracy < self::CRITICAL_UNIT_THRESHOLD) {
                        $weaknessLevel = 'critical';
                    } elseif ($accuracy < self::WEAK_UNIT_THRESHOLD) {
                        $weaknessLevel = 'weak';
                    } else {
                        $weaknessLevel = 'stable';
                    }

                    $weaknessScore = round(
                        max(0, 100 - $accuracy) * 0.7
                        + ($totalAttempts > 0 ? ($wrongAnswers / $totalAttempts) * 100 : 0) * 0.2
                        + max(0, 100 - $coverage) * 0.1,
                        2
                    );

                    return [
                        'unit_id' => (int) $unit->unit_id,
                        'unit_name' => $unit->unit_name,
                        'unit_no' => $unit->unit_no,
                        'total_questions' => $totalQuestions,
                        'attempted_questions' => $attemptedQuestions,
                        'total_attempts' => $totalAttempts,
                        'correct_answers' => $correctAnswers,
                        'wrong_answers' => $wrongAnswers,
                        'accuracy_percentage' => $accuracy,
                        'coverage_percentage' => $coverage,
                        'weakness_level' => $weaknessLevel,
                        'weakness_score' => $weaknessScore,
                    ];
                })
                ->filter(function (array $unit) {
                    return in_array($unit['weakness_level'], ['critical', 'weak'], true);
                })
                ->sort(function (array $first, array $second) {
                    if ($first['weakness_score'] === $second['weakness_score']) {
                        if ($first['accuracy_percentage'] === $second['accuracy_percentage']) {
                            return $second['total_attempts'] <=> $first['total_attempts'];
                        }

                        return $first['accuracy_percentage'] <=> $second['accuracy_percentage'];
                    }

                    return $second['weakness_score'] <=> $first['weakness_score'];
                })
                ->values();

            return response()->json([
                'success' => 1,
                'data' => [
                    'offered_program_id' => (int) $validated['offered_program_id'],
                    'subject_id' => (int) $validated['subject_id'],
                    'weak_area_type' => 'unit',
                    'minimum_attempts_rule' => self::WEAK_UNIT_MIN_ATTEMPTS,
                    'weak_units' => $weakUnits,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getStudentSubjectPerformanceOverviewForLms(Request $request)
    {
        $validated = $request->validate([
            'offered_program_id' => ['required', 'integer', 'exists:offered_programs,id'],
            'subject_id' => ['required', 'integer', 'exists:subject_tbl,id'],
        ]);

        try {
            $user = $request->user();

            $summary = StudentSubjectProgressSummary::query()
                ->where('user_id', $user->id)
                ->where('offered_program_id', $validated['offered_program_id'])
                ->where('subject_id', $validated['subject_id'])
                ->first();

            $practiceAttempted = (int) ($summary->practice_attempted ?? 0);
            $practiceCorrect = (int) ($summary->practice_correct ?? 0);
            $formalAttempted = (int) ($summary->formal_attempted ?? 0);
            $formalCorrect = (int) ($summary->formal_correct ?? 0);
            $totalQuestions = (int) ($summary->total_questions ?? 0);
            $distinctQuestionsSeen = (int) ($summary->distinct_questions_seen ?? 0);

            $practiceAccuracy = $this->calculateAccuracy($practiceCorrect, $practiceAttempted);
            $testAccuracy = $this->calculateAccuracy($formalCorrect, $formalAttempted);

            $totalAttempted = $practiceAttempted + $formalAttempted;
            $totalCorrect = $practiceCorrect + $formalCorrect;
            $overallAccuracy = $this->calculateAccuracy($totalCorrect, $totalAttempted);
            $coveragePercentage = $this->calculateAccuracy($distinctQuestionsSeen, $totalQuestions);

            $trend = $this->buildSubjectSevenDayTrend(
                $user->id,
                (int) $validated['offered_program_id'],
                (int) $validated['subject_id']
            );

            return response()->json([
                'success' => 1,
                'data' => [
                    'offered_program_id' => (int) $validated['offered_program_id'],
                    'subject_id' => (int) $validated['subject_id'],
                    'overall_accuracy' => $overallAccuracy,
                    'practice_accuracy' => $practiceAccuracy,
                    'test_accuracy' => $testAccuracy,
                    'coverage_percentage' => $coveragePercentage,
                    'coverage_text' => "{$distinctQuestionsSeen} of {$totalQuestions} questions attempted",
                    'coverage' => [
                        'attempted_questions' => $distinctQuestionsSeen,
                        'total_questions' => $totalQuestions,
                    ],
                    'seven_day_trend_percentage' => $trend['percentage'],
                    'seven_day_trend' => $trend['points'],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getSubjectPreparationScoresForLms(Request $request)
    {
        $validated = $request->validate([
            'offered_program_id' => ['required', 'integer', 'exists:offered_programs,id'],
        ]);

        try {
            $user = $request->user();

            $programSubjects = DB::table('program_subjects as ps')
                ->join('subject_tbl as subjects', 'subjects.id', '=', 'ps.subject_id')
                ->leftJoin('student_subject_progress_summaries as ssps', function ($join) use ($user, $validated) {
                    $join->on('ssps.subject_id', '=', 'ps.subject_id')
                        ->where('ssps.user_id', '=', $user->id)
                        ->where('ssps.offered_program_id', '=', $validated['offered_program_id']);
                })
                ->where('ps.offered_program_id', $validated['offered_program_id'])
                ->where('ps.is_active', true)
                ->orderBy('ps.display_order')
                ->orderBy('subjects.subject_name')
                ->select(
                    'subjects.id as subject_id',
                    'subjects.subject_name',
                    'ssps.total_questions',
                    'ssps.practice_attempted',
                    'ssps.practice_correct',
                    'ssps.practice_wrong',
                    'ssps.formal_attempted',
                    'ssps.formal_correct',
                    'ssps.formal_wrong',
                    'ssps.distinct_questions_seen',
                    'ssps.last_practiced_at',
                    'ssps.last_tested_at'
                )
                ->get();

            $masteryCounts = StudentQuestionProgressSummary::query()
                ->select('subject_id')
                ->selectRaw('COUNT(CASE WHEN is_mastered = 1 THEN 1 END) as mastered_questions')
                ->where('user_id', $user->id)
                ->where('offered_program_id', $validated['offered_program_id'])
                ->groupBy('subject_id')
                ->get()
                ->keyBy('subject_id');

            $subjects = $programSubjects->map(function ($subject) use ($masteryCounts) {
                $practiceAttempted = (int) ($subject->practice_attempted ?? 0);
                $practiceCorrect = (int) ($subject->practice_correct ?? 0);
                $formalAttempted = (int) ($subject->formal_attempted ?? 0);
                $formalCorrect = (int) ($subject->formal_correct ?? 0);
                $totalQuestions = (int) ($subject->total_questions ?? 0);
                $distinctQuestionsSeen = (int) ($subject->distinct_questions_seen ?? 0);
                $masteredQuestions = (int) ($masteryCounts[$subject->subject_id]->mastered_questions ?? 0);

                $practiceAccuracy = $this->calculateAccuracy($practiceCorrect, $practiceAttempted);
                $formalAccuracy = $this->calculateAccuracy($formalCorrect, $formalAttempted);
                $coveragePercentage = $this->calculateAccuracy($distinctQuestionsSeen, $totalQuestions);
                $masteryRate = $this->calculateAccuracy($masteredQuestions, $distinctQuestionsSeen);
                $recencyScore = $this->calculateRecencyScore(
                    $subject->last_practiced_at,
                    $subject->last_tested_at
                );
                $preparationScore = $this->calculatePreparationScore(
                    $practiceAccuracy,
                    $formalAccuracy,
                    $coveragePercentage,
                    $masteryRate,
                    $recencyScore
                );

                return [
                    'subject_id' => (int) $subject->subject_id,
                    'subject_name' => $subject->subject_name,
                    'preparation_score' => $preparationScore,
                    'readiness_level' => $this->getReadinessLevel($preparationScore),
                    'practice_accuracy' => $practiceAccuracy,
                    'formal_accuracy' => $formalAccuracy,
                    'coverage_percentage' => $coveragePercentage,
                    'mastery_rate' => $masteryRate,
                    'recency_score' => $recencyScore,
                    'distinct_questions_seen' => $distinctQuestionsSeen,
                    'total_questions' => $totalQuestions,
                    'mastered_questions' => $masteredQuestions,
                ];
            })->values();

            return response()->json([
                'success' => 1,
                'data' => [
                    'offered_program_id' => (int) $validated['offered_program_id'],
                    'score_name' => 'Preparation Score',
                    'formula' => [
                        'practice_accuracy_weight' => self::PREPARATION_PRACTICE_WEIGHT,
                        'formal_accuracy_weight' => self::PREPARATION_FORMAL_WEIGHT,
                        'coverage_weight' => self::PREPARATION_COVERAGE_WEIGHT,
                        'mastery_rate_weight' => self::PREPARATION_MASTERY_WEIGHT,
                        'recency_score_weight' => self::PREPARATION_RECENCY_WEIGHT,
                    ],
                    'subjects' => $subjects,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function calculateAccuracy(int $correct, int $attempted): float
    {
        if ($attempted <= 0) {
            return 0;
        }

        return round(($correct / $attempted) * 100, 2);
    }

    private function buildSubjectSevenDayTrend(int $userId, int $offeredProgramId, int $subjectId): array
    {
        $startDate = Carbon::today()->subDays(6);
        $endDate = Carbon::today();

        $practiceRows = DB::table('practice_session_questions as psq')
            ->join('practice_sessions as ps', 'ps.id', '=', 'psq.session_id')
            ->join('exam_question_tbl as questions', 'questions.id', '=', 'psq.question_id')
            ->join('book_unit_topic_tbl as topics', 'topics.id', '=', 'questions.topic_id')
            ->join('book_unit_tbl as units', 'units.id', '=', 'topics.unit_id')
            ->join('book_tbl as books', 'books.id', '=', 'units.book_id')
            ->selectRaw('DATE(COALESCE(psq.practiced_at, psq.created_at)) as stat_date')
            ->selectRaw('COUNT(*) as attempted_count')
            ->selectRaw('SUM(CASE WHEN psq.is_correct = 1 THEN 1 ELSE 0 END) as correct_count')
            ->where('ps.user_id', $userId)
            ->where('ps.offered_program_id', $offeredProgramId)
            ->where('books.subject_id', $subjectId)
            ->where('psq.is_attempted', true)
            ->whereBetween(DB::raw('DATE(COALESCE(psq.practiced_at, psq.created_at))'), [
                $startDate->toDateString(),
                $endDate->toDateString(),
            ])
            ->groupBy('stat_date')
            ->get()
            ->keyBy('stat_date');

        $formalRows = DB::table('test_attempt_questions as taq')
            ->join('test_attempts as ta', 'ta.id', '=', 'taq.attempt_id')
            ->join('tests as tests', 'tests.id', '=', 'ta.test_id')
            ->join('exam_question_tbl as questions', 'questions.id', '=', 'taq.question_id')
            ->join('book_unit_topic_tbl as topics', 'topics.id', '=', 'questions.topic_id')
            ->join('book_unit_tbl as units', 'units.id', '=', 'topics.unit_id')
            ->join('book_tbl as books', 'books.id', '=', 'units.book_id')
            ->selectRaw('DATE(COALESCE(ta.submitted_at, taq.created_at)) as stat_date')
            ->selectRaw('COUNT(*) as attempted_count')
            ->selectRaw('SUM(CASE WHEN taq.is_correct = 1 THEN 1 ELSE 0 END) as correct_count')
            ->where('ta.user_id', $userId)
            ->where('tests.offered_program_id', $offeredProgramId)
            ->where('books.subject_id', $subjectId)
            ->where('taq.is_attempted', true)
            ->whereBetween(DB::raw('DATE(COALESCE(ta.submitted_at, taq.created_at))'), [
                $startDate->toDateString(),
                $endDate->toDateString(),
            ])
            ->groupBy('stat_date')
            ->get()
            ->keyBy('stat_date');

        return $this->mergeSevenDayTrendRows($practiceRows, $formalRows, $startDate, $endDate);
    }

    private function mergeSevenDayTrendRows($practiceRows, $formalRows, Carbon $startDate, Carbon $endDate): array
    {
        $points = [];
        $totalAttempted = 0;
        $totalCorrect = 0;

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $dateKey = $date->toDateString();
            $practiceAttempted = (int) ($practiceRows[$dateKey]->attempted_count ?? 0);
            $practiceCorrect = (int) ($practiceRows[$dateKey]->correct_count ?? 0);
            $formalAttempted = (int) ($formalRows[$dateKey]->attempted_count ?? 0);
            $formalCorrect = (int) ($formalRows[$dateKey]->correct_count ?? 0);

            $combinedAttempted = $practiceAttempted + $formalAttempted;
            $combinedCorrect = $practiceCorrect + $formalCorrect;

            $totalAttempted += $combinedAttempted;
            $totalCorrect += $combinedCorrect;

            $points[] = [
                'date' => $dateKey,
                'accuracy' => $this->calculateAccuracy($combinedCorrect, $combinedAttempted),
                'attempted_questions' => $combinedAttempted,
                'correct_questions' => $combinedCorrect,
            ];
        }

        return [
            'percentage' => $this->calculateAccuracy($totalCorrect, $totalAttempted),
            'points' => $points,
        ];
    }

    private function calculateRecencyScore($lastPracticedAt, $lastTestedAt): float
    {
        $lastActivityAt = collect([$lastPracticedAt, $lastTestedAt])
            ->filter()
            ->map(fn ($value) => Carbon::parse($value))
            ->sortDesc()
            ->first();

        if (!$lastActivityAt) {
            return 0;
        }

        $daysSinceLastActivity = Carbon::today()->diffInDays($lastActivityAt);

        if ($daysSinceLastActivity <= 7) {
            return 100;
        }

        if ($daysSinceLastActivity <= 14) {
            return 70;
        }

        if ($daysSinceLastActivity <= 30) {
            return 40;
        }

        return 10;
    }

    private function calculatePreparationScore(
        float $practiceAccuracy,
        float $formalAccuracy,
        float $coveragePercentage,
        float $masteryRate,
        float $recencyScore
    ): float {
        return round(
            ($practiceAccuracy * self::PREPARATION_PRACTICE_WEIGHT)
            + ($formalAccuracy * self::PREPARATION_FORMAL_WEIGHT)
            + ($coveragePercentage * self::PREPARATION_COVERAGE_WEIGHT)
            + ($masteryRate * self::PREPARATION_MASTERY_WEIGHT)
            + ($recencyScore * self::PREPARATION_RECENCY_WEIGHT),
            2
        );
    }

    private function getReadinessLevel(float $preparationScore): string
    {
        if ($preparationScore >= 80) {
            return 'strong';
        }

        if ($preparationScore >= 60) {
            return 'improving';
        }

        if ($preparationScore >= 40) {
            return 'needs_work';
        }

        return 'early_stage';
    }
}
