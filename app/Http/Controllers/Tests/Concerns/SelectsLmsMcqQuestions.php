<?php

namespace App\Http\Controllers\Tests\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

trait SelectsLmsMcqQuestions
{
    /**
     * Select MCQs for an LMS practice session / formal test.
     *
     * - "all" / "straight": $requestedCount is the number of MCQs.
     * - "scenario": $requestedCount is the number of scenarios. Every active MCQ
     *   attached to each selected scenario is returned, grouped by scenario and
     *   ordered by scenario_question_order.
     *
     * Returns null when not enough questions/scenarios are available.
     *
     * @return array{questions: Collection, scenario_group_ids: array<int>}|null
     */
    private function selectLmsMcqQuestions(array $unitIds, int $requestedCount, string $mcqFormat): ?array
    {
        if ($mcqFormat === 'scenario') {
            return $this->selectLmsScenarioMcqQuestions($unitIds, $requestedCount);
        }

        $questions = DB::table('exam_question_tbl as questions')
            ->join('book_unit_topic_tbl as topics', 'topics.id', '=', 'questions.topic_id')
            ->whereIn('topics.unit_id', $unitIds)
            ->where('questions.is_mcq', 1)
            ->where('questions.activate', 1)
            ->when($mcqFormat === 'straight', function ($query) {
                $query->whereNull('questions.scenario_group_id');
            })
            ->select('questions.id')
            ->distinct()
            ->inRandomOrder()
            ->limit($requestedCount)
            ->get();

        if ($questions->count() < $requestedCount) {
            return null;
        }

        return [
            'questions' => $questions->values(),
            'scenario_group_ids' => [],
        ];
    }

    private function selectLmsScenarioMcqQuestions(array $unitIds, int $scenarioCount): ?array
    {
        $scenarioGroupIds = DB::table('question_scenario_groups_tbl as scenario_groups')
            ->where('scenario_groups.activate', 1)
            ->whereExists(function ($query) use ($unitIds) {
                $query->selectRaw('1')
                    ->from('exam_question_tbl as questions')
                    ->join('book_unit_topic_tbl as topics', 'topics.id', '=', 'questions.topic_id')
                    ->whereColumn('questions.scenario_group_id', 'scenario_groups.id')
                    ->whereIn('topics.unit_id', $unitIds)
                    ->where('questions.is_mcq', 1)
                    ->where('questions.activate', 1);
            })
            ->inRandomOrder()
            ->limit($scenarioCount)
            ->pluck('scenario_groups.id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if (count($scenarioGroupIds) < $scenarioCount) {
            return null;
        }

        $groupPosition = array_flip($scenarioGroupIds);

        $questions = DB::table('exam_question_tbl as questions')
            ->join('book_unit_topic_tbl as topics', 'topics.id', '=', 'questions.topic_id')
            ->whereIn('questions.scenario_group_id', $scenarioGroupIds)
            ->whereIn('topics.unit_id', $unitIds)
            ->where('questions.is_mcq', 1)
            ->where('questions.activate', 1)
            ->select('questions.id', 'questions.scenario_group_id', 'questions.scenario_question_order')
            ->distinct()
            ->get()
            ->sort(function ($a, $b) use ($groupPosition) {
                return [
                    $groupPosition[(int) $a->scenario_group_id],
                    $a->scenario_question_order ?? PHP_INT_MAX,
                    (int) $a->id,
                ] <=> [
                    $groupPosition[(int) $b->scenario_group_id],
                    $b->scenario_question_order ?? PHP_INT_MAX,
                    (int) $b->id,
                ];
            })
            ->values();

        if ($questions->isEmpty()) {
            return null;
        }

        return [
            'questions' => $questions,
            'scenario_group_ids' => $scenarioGroupIds,
        ];
    }
}
