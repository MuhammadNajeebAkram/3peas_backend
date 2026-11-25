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
    /**
     * Calculate target scenario question count (multiple of 4) and straight count
     * for format 'all' based on the requested scenario percentage with lower-multiple tie breaking.
     *
     * @param int $totalQuestions Total requested questions (Q)
     * @param int $scenarioPercentage Requested scenario percentage (P: 0 to 100, increments of 5, default 30)
     * @return array{scenario_questions: int, straight_questions: int, scenario_groups: int}
     *
     * @throws \InvalidArgumentException When percentage is invalid or 100% with total not divisible by 4
     */
    public static function calculateScenarioAndStraightCounts(int $totalQuestions, int $scenarioPercentage = 30): array
    {
        if ($scenarioPercentage < 0 || $scenarioPercentage > 100 || ($scenarioPercentage % 5 !== 0)) {
            throw new \InvalidArgumentException(
                'The scenario percentage must be an integer between 0 and 100 in increments of 5.'
            );
        }

        if ($scenarioPercentage === 100) {
            if ($totalQuestions % 4 !== 0) {
                throw new \InvalidArgumentException(
                    'When scenario percentage is 100%, total questions must be a multiple of 4.'
                );
            }
            return [
                'scenario_questions' => $totalQuestions,
                'straight_questions' => 0,
                'scenario_groups'    => (int) ($totalQuestions / 4),
            ];
        }

        if ($scenarioPercentage === 0 || $totalQuestions < 4) {
            return [
                'scenario_questions' => 0,
                'straight_questions' => $totalQuestions,
                'scenario_groups'    => 0,
            ];
        }

        $target = ($totalQuestions * $scenarioPercentage) / 100.0;
        $lower = (int) (floor($target / 4.0) * 4);
        $upper = $lower + 4;
        $midpoint = $lower + 2.0;

        // If target <= midpoint (including exact halfway tie), pick lower multiple; otherwise upper
        $scenarioCount = ($target <= $midpoint) ? $lower : $upper;

        if ($scenarioCount > $totalQuestions) {
            $scenarioCount = (int) (floor($totalQuestions / 4.0) * 4);
        }

        $straightCount = $totalQuestions - $scenarioCount;

        return [
            'scenario_questions' => $scenarioCount,
            'straight_questions' => $straightCount,
            'scenario_groups'    => (int) ($scenarioCount / 4),
        ];
    }

    /**
     * Select standalone straight MCQs (where scenario_group_id IS NULL).
     *
     * @param array<int> $unitIds
     * @param int $requestedCount
     * @param array<int>|null $topicIds
     * @param bool $allowExercise
     * @param bool $allowSupplementary
     * @return array{questions: Collection, scenario_group_ids: array<int>}|null
     */
    private function selectLmsStraightMcqQuestions(
        array $unitIds,
        int $requestedCount,
        ?array $topicIds = null,
        bool $allowExercise = true,
        bool $allowSupplementary = true
    ): ?array {
        if ($requestedCount <= 0) {
            return [
                'questions'          => collect(),
                'scenario_group_ids' => [],
            ];
        }

        if (!$allowExercise && !$allowSupplementary) {
            return null;
        }

        $query = DB::table('exam_question_tbl as questions')
            ->join('book_unit_topic_tbl as topics', 'topics.id', '=', 'questions.topic_id')
            ->whereIn('topics.unit_id', $unitIds)
            ->where('questions.is_mcq', 1)
            ->where('questions.activate', 1)
            ->whereNull('questions.scenario_group_id');

        if (!empty($topicIds)) {
            $query->whereIn('questions.topic_id', $topicIds);
        }

        if (!$allowExercise && $allowSupplementary) {
            $query->where(function ($q) {
                $q->where('questions.exercise_question', 0)->orWhereNull('questions.exercise_question');
            });
        } elseif ($allowExercise && !$allowSupplementary) {
            $query->where('questions.exercise_question', 1);
        }

        $questions = $query->select('questions.id')
            ->distinct()
            ->inRandomOrder()
            ->limit($requestedCount)
            ->get();

        if ($questions->count() < $requestedCount) {
            return null;
        }

        return [
            'questions'          => $questions->values(),
            'scenario_group_ids' => [],
        ];
    }

    /**
     * Unified MCQ selection:
     * - 'straight': Standalone MCQs only (scenario_group_id IS NULL)
     * - 'scenario': Complete 4-question scenario groups (scenario_group_id IS NOT NULL)
     * - 'all': Approximately 30% scenario questions (in complete 4-question groups) and remaining straight MCQs
     *
     * @param array<int> $unitIds
     * @param int $requestedCount
     * @param string $mcqFormat
     * @param array<int>|null $topicIds
     * @param bool $allowExercise
     * @param bool $allowSupplementary
     * @param int $scenarioPercentage
     * @return array{questions: Collection, scenario_group_ids: array<int>}
     *
     * @throws \InvalidArgumentException On insufficient questions or format violations
     */
    private function selectLmsMcqQuestions(
        array $unitIds,
        int $requestedCount,
        string $mcqFormat,
        ?array $topicIds = null,
        bool $allowExercise = true,
        bool $allowSupplementary = true,
        int $scenarioPercentage = 30
    ): ?array {
        if ($mcqFormat === 'scenario') {
            $selection = $this->selectLmsScenarioMcqQuestions(
                $unitIds,
                $requestedCount,
                $topicIds,
                $allowExercise,
                $allowSupplementary,
                true
            );
            if ($selection === null) {
                throw new \InvalidArgumentException(
                    'Not enough complete active scenario groups available for the selected chapter. Each scenario group requires at least 4 active questions. Try fewer questions.'
                );
            }
            return $selection;
        }

        if ($mcqFormat === 'straight') {
            $selection = $this->selectLmsStraightMcqQuestions(
                $unitIds,
                $requestedCount,
                $topicIds,
                $allowExercise,
                $allowSupplementary
            );
            if ($selection === null) {
                throw new \InvalidArgumentException(
                    'Not enough active straight MCQs available for the selected chapter. Try fewer questions.'
                );
            }
            return $selection;
        }

        // mcqFormat === 'all': configurable scenario percentage (in complete 4-question groups) + remaining straight MCQs
        $counts = self::calculateScenarioAndStraightCounts($requestedCount, $scenarioPercentage);
        $scenarioQs = $counts['scenario_questions'];
        $scenarioGroups = $counts['scenario_groups'];
        $straightQs = $counts['straight_questions'];

        $selectedScenarioQuestions = collect();
        $selectedScenarioGroupIds = [];

        if ($scenarioGroups > 0) {
            $scenarioSelection = $this->selectLmsScenarioMcqQuestions(
                $unitIds,
                $scenarioGroups,
                $topicIds,
                $allowExercise,
                $allowSupplementary
            );
            if ($scenarioSelection === null) {
                throw new \InvalidArgumentException(
                    "Not enough complete active scenario groups available for the selected chapter to fulfill the {$scenarioPercentage}% scenario requirement ({$scenarioQs} questions across {$scenarioGroups} groups required). Each scenario group requires at least 4 active questions. Try a lower scenario percentage or straight format."
                );
            }
            $selectedScenarioQuestions = $scenarioSelection['questions'];
            $selectedScenarioGroupIds = $scenarioSelection['scenario_group_ids'];
        }

        $selectedStraightQuestions = collect();
        if ($straightQs > 0) {
            $straightSelection = $this->selectLmsStraightMcqQuestions(
                $unitIds,
                $straightQs,
                $topicIds,
                $allowExercise,
                $allowSupplementary
            );
            if ($straightSelection === null) {
                throw new \InvalidArgumentException(
                    "Not enough active straight MCQs available for the selected chapter to fulfill the remaining {$straightQs} straight questions. Try fewer questions or a higher scenario percentage."
                );
            }
            $selectedStraightQuestions = $straightSelection['questions'];
        }

        $combinedQuestions = $selectedScenarioQuestions->concat($selectedStraightQuestions)->values();

        return [
            'questions'          => $combinedQuestions,
            'scenario_group_ids' => $selectedScenarioGroupIds,
        ];
    }

    /**
     * Find a combination of scenario group IDs whose question counts sum exactly to $target.
     *
     * @param array<int, int> $groupSizes Associative array: groupId => questionCount
     * @param int $target Target number of questions
     * @return array<int>|null Selected group IDs on success, null if impossible
     */
    protected function findScenarioGroupCombination(array $groupSizes, int $target): ?array
    {
        $keys = array_keys($groupSizes);
        shuffle($keys);

        $solution = null;

        $backtrack = function ($index, $currentSum, $selected) use (&$backtrack, &$solution, $keys, $groupSizes, $target) {
            if ($currentSum === $target) {
                $solution = $selected;
                return true;
            }
            if ($currentSum > $target || $index >= count($keys)) {
                return false;
            }

            $gid = $keys[$index];
            $cnt = $groupSizes[$gid];

            if ($currentSum + $cnt <= $target) {
                $selected[] = $gid;
                if ($backtrack($index + 1, $currentSum + $cnt, $selected)) {
                    return true;
                }
                array_pop($selected);
            }

            return $backtrack($index + 1, $currentSum, $selected);
        };

        $backtrack(0, 0, []);

        return $solution;
    }

    private function selectLmsScenarioMcqQuestions(
        array $unitIds,
        int $targetCount,
        ?array $topicIds = null,
        bool $allowExercise = true,
        bool $allowSupplementary = true,
        bool $isQuestionTarget = false
    ): ?array {
        if (!$allowExercise && !$allowSupplementary) {
            return null;
        }

        // 1. Fetch all active scenario questions within the requested units/topics that satisfy permissions
        $rawQuery = DB::table('exam_question_tbl as questions')
            ->join('book_unit_topic_tbl as topics', 'topics.id', '=', 'questions.topic_id')
            ->join('question_scenario_groups_tbl as scenario_groups', 'scenario_groups.id', '=', 'questions.scenario_group_id')
            ->where('scenario_groups.activate', 1)
            ->whereIn('topics.unit_id', $unitIds)
            ->where('questions.is_mcq', 1)
            ->where('questions.activate', 1);

        if (!empty($topicIds)) {
            $rawQuery->whereIn('questions.topic_id', $topicIds);
        }

        if (!$allowExercise && $allowSupplementary) {
            $rawQuery->where(function ($sub) {
                $sub->where('questions.exercise_question', 0)->orWhereNull('questions.exercise_question');
            });
        } elseif ($allowExercise && !$allowSupplementary) {
            $rawQuery->where('questions.exercise_question', 1);
        }

        $eligibleQuestions = $rawQuery->select([
            'questions.id',
            'questions.scenario_group_id',
            'questions.scenario_question_order',
        ])->distinct()->get();

        if ($eligibleQuestions->isEmpty()) {
            return null;
        }

        // 2. Group by scenario_group_id and filter ONLY to groups with at least 4 eligible questions
        $grouped = $eligibleQuestions->groupBy('scenario_group_id');
        $qualifyingGroupIds = [];
        $groupSizes = [];

        foreach ($grouped as $sgId => $groupMembers) {
            if ($groupMembers->count() >= 4) {
                $qualifyingGroupIds[] = (int) $sgId;
                $groupSizes[(int) $sgId] = $groupMembers->count();
            }
        }

        if (empty($qualifyingGroupIds)) {
            return null;
        }

        $selectedScenarioGroupIds = [];
        if ($isQuestionTarget) {
            // Target is total questions: find a subset of complete scenario groups whose total questions equal targetCount
            $combination = $this->findScenarioGroupCombination($groupSizes, $targetCount);
            if ($combination === null) {
                return null;
            }
            $selectedScenarioGroupIds = $combination;
        } else {
            // Target is number of groups
            if (count($qualifyingGroupIds) < $targetCount) {
                return null;
            }
            shuffle($qualifyingGroupIds);
            $selectedScenarioGroupIds = array_slice($qualifyingGroupIds, 0, $targetCount);
        }

        if (empty($selectedScenarioGroupIds)) {
            return null;
        }

        $groupPosition = array_flip($selectedScenarioGroupIds);

        // 4. For each selected scenario group, preserve ALL questions belonging to that group (no dropping 5th question)
        $selectedQuestions = collect();
        foreach ($selectedScenarioGroupIds as $sgId) {
            $members = $grouped->get($sgId);
            $selectedQuestions = $selectedQuestions->concat($members);
        }

        // 5. Stable order within group (by scenario_question_order, then id)
        $questions = $selectedQuestions
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

        return [
            'questions'          => $questions,
            'scenario_group_ids' => $selectedScenarioGroupIds,
        ];
    }
}
