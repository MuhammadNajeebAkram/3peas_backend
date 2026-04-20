<?php

namespace App\Http\Controllers\Stats;

use App\Http\Controllers\Controller;
use App\Models\StudentQuestionProgressSummary;
use App\Models\StudentUnitProgressSummary;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentUnitProgressSummaryController extends Controller
{
    private const WEAK_TOPIC_MIN_ATTEMPTS = 5;
    private const WEAK_TOPIC_THRESHOLD = 60;
    private const CRITICAL_TOPIC_THRESHOLD = 40;

    public function getStudentUnitMcqStatsForLms(Request $request)
    {
        $unitIds = $request->input('unit_ids');

        if (empty($unitIds) && $request->filled('unit_id')) {
            $unitIds = [$request->input('unit_id')];
            $request->merge(['unit_ids' => $unitIds]);
        }

        $validated = $request->validate([
            'offered_program_id' => ['required', 'integer', 'exists:offered_programs,id'],
            'unit_ids' => ['required', 'array', 'min:1'],
            'unit_ids.*' => ['integer', 'exists:book_unit_tbl,id'],
            'stats_mode' => ['required', 'string', 'in:practice,formal'],
        ]);

        try {
            $user = $request->user();
            $unitIds = array_values(array_unique($validated['unit_ids']));
            $isPracticeMode = $validated['stats_mode'] === 'practice';

            $attemptsExpression = $isPracticeMode
                ? '(practice_attempts + formal_attempts)'
                : 'formal_attempts';

            $correctExpression = $isPracticeMode
                ? '(practice_correct + formal_correct)'
                : 'formal_correct';

            $totalMcqsCount = DB::table('exam_question_tbl as questions')
                ->join('book_unit_topic_tbl as topics', 'topics.id', '=', 'questions.topic_id')
                ->whereIn('topics.unit_id', $unitIds)
                ->where('questions.is_mcq', 1)
                ->where('questions.activate', 1)
                ->distinct()
                ->count('questions.id');

            $studentQuestionSummaryQuery = StudentQuestionProgressSummary::query()
                ->where('user_id', $user->id)
                ->where('offered_program_id', $validated['offered_program_id'])
                ->whereIn('unit_id', $unitIds);

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
                    'unit_ids' => $unitIds,
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

    public function getStudentWeakTopicsForLms(Request $request)
    {
        $validated = $request->validate([
            'offered_program_id' => ['required', 'integer', 'exists:offered_programs,id'],
            'unit_id' => ['required', 'integer', 'exists:book_unit_tbl,id'],
        ]);

        try {
            $user = $request->user();

            $topicStats = DB::table('book_unit_topic_tbl as topics')
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
                ->where('topics.unit_id', $validated['unit_id'])
                ->where('topics.activate', 1)
                ->groupBy('topics.id', 'topics.topic_name', 'topics.topic_no')
                ->orderBy('topics.topic_no')
                ->orderBy('topics.id')
                ->selectRaw('
                    topics.id as topic_id,
                    topics.topic_name,
                    topics.topic_no,
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

            $weakTopics = $topicStats
                ->map(function ($topic) {
                    $totalAttempts = (int) $topic->total_attempts;
                    $correctAnswers = (int) $topic->correct_answers;
                    $wrongAnswers = (int) $topic->wrong_answers;
                    $attemptedQuestions = (int) $topic->attempted_questions;
                    $totalQuestions = (int) $topic->total_questions;

                    $accuracy = $this->calculateAccuracy($correctAnswers, $totalAttempts);
                    $coverage = $this->calculateAccuracy($attemptedQuestions, $totalQuestions);

                    if ($totalAttempts < self::WEAK_TOPIC_MIN_ATTEMPTS) {
                        $weaknessLevel = 'insufficient_data';
                    } elseif ($accuracy < self::CRITICAL_TOPIC_THRESHOLD) {
                        $weaknessLevel = 'critical';
                    } elseif ($accuracy < self::WEAK_TOPIC_THRESHOLD) {
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
                        'topic_id' => (int) $topic->topic_id,
                        'topic_name' => $topic->topic_name,
                        'topic_no' => $topic->topic_no,
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
                ->filter(function (array $topic) {
                    return in_array($topic['weakness_level'], ['critical', 'weak'], true);
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
                    'unit_id' => (int) $validated['unit_id'],
                    'weak_area_type' => 'topic',
                    'minimum_attempts_rule' => self::WEAK_TOPIC_MIN_ATTEMPTS,
                    'weak_topics' => $weakTopics,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getStudentUnitPerformanceOverviewForLms(Request $request)
    {
        $unitIds = $request->input('unit_ids');

        if (empty($unitIds) && $request->filled('unit_id')) {
            $unitIds = [$request->input('unit_id')];
            $request->merge(['unit_ids' => $unitIds]);
        }

        $validated = $request->validate([
            'offered_program_id' => ['required', 'integer', 'exists:offered_programs,id'],
            'unit_ids' => ['required', 'array', 'min:1'],
            'unit_ids.*' => ['integer', 'exists:book_unit_tbl,id'],
        ]);

        try {
            $user = $request->user();
            $unitIds = array_values(array_unique($validated['unit_ids']));

            $summary = StudentUnitProgressSummary::query()
                ->where('user_id', $user->id)
                ->where('offered_program_id', $validated['offered_program_id'])
                ->whereIn('unit_id', $unitIds)
                ->selectRaw('
                    COALESCE(SUM(total_questions), 0) as total_questions,
                    COALESCE(SUM(practice_attempted), 0) as practice_attempted,
                    COALESCE(SUM(practice_correct), 0) as practice_correct,
                    COALESCE(SUM(formal_attempted), 0) as formal_attempted,
                    COALESCE(SUM(formal_correct), 0) as formal_correct,
                    COALESCE(SUM(distinct_questions_seen), 0) as distinct_questions_seen
                ')
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

            $trend = $this->buildUnitSevenDayTrend(
                $user->id,
                (int) $validated['offered_program_id'],
                $unitIds
            );

            return response()->json([
                'success' => 1,
                'data' => [
                    'offered_program_id' => (int) $validated['offered_program_id'],
                    'unit_ids' => $unitIds,
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

    private function calculateAccuracy(int $correct, int $attempted): float
    {
        if ($attempted <= 0) {
            return 0;
        }

        return round(($correct / $attempted) * 100, 2);
    }

    private function buildUnitSevenDayTrend(int $userId, int $offeredProgramId, array $unitIds): array
    {
        $startDate = Carbon::today()->subDays(6);
        $endDate = Carbon::today();

        $practiceRows = DB::table('practice_session_questions as psq')
            ->join('practice_sessions as ps', 'ps.id', '=', 'psq.session_id')
            ->join('exam_question_tbl as questions', 'questions.id', '=', 'psq.question_id')
            ->join('book_unit_topic_tbl as topics', 'topics.id', '=', 'questions.topic_id')
            ->selectRaw('DATE(COALESCE(psq.practiced_at, psq.created_at)) as stat_date')
            ->selectRaw('COUNT(*) as attempted_count')
            ->selectRaw('SUM(CASE WHEN psq.is_correct = 1 THEN 1 ELSE 0 END) as correct_count')
            ->where('ps.user_id', $userId)
            ->where('ps.offered_program_id', $offeredProgramId)
            ->whereIn('topics.unit_id', $unitIds)
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
            ->selectRaw('DATE(COALESCE(ta.submitted_at, taq.created_at)) as stat_date')
            ->selectRaw('COUNT(*) as attempted_count')
            ->selectRaw('SUM(CASE WHEN taq.is_correct = 1 THEN 1 ELSE 0 END) as correct_count')
            ->where('ta.user_id', $userId)
            ->where('tests.offered_program_id', $offeredProgramId)
            ->whereIn('topics.unit_id', $unitIds)
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
}
