<?php

namespace App\Http\Services;

use App\Models\AiExplanationBatch;
use App\Models\AiExplanationBatchItem;
use App\Models\AiModel;
use App\Models\AiRequest;
use App\Models\User;
use App\Support\ExplanationContentHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BulkQuestionExplanationQueryService
{
    public function __construct(private QuestionExplanationService $explanationService) {}

    /**
     * Evaluate filters and return preview summary, cost estimate, and paginated preview items.
     * Strictly read-only: never modifies database records.
     */
    public function preview(User $user, array $filters, ?AiModel $model = null, int $page = 1, int $perPage = 25): array
    {
        $language = (string) ($filters['language'] ?? 'both');
        $evaluation = $this->evaluate($user, $filters, $model, $language);

        $eligibleItems = $evaluation['eligible'];
        $totalEligible = count($eligibleItems);
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $pageSlice = array_slice($eligibleItems, $offset, $perPage);

        $batchSize = min(
            (int) ($filters['batch_size'] ?? config('ai_bulk_explanations.default_batch_size', 25)),
            (int) config('ai_bulk_explanations.max_batch_size', 100)
        );
        $batchCandidates = array_slice($eligibleItems, 0, max(1, $batchSize));

        $previewRows = array_map(fn (array $item) => [
            'question_id' => $item['question_id'],
            'question_text' => $item['question_text'],
            'question_text_ur' => $item['question_text_ur'],
            'correct_answer_summary' => $item['correct_answer_summary'],
            'existing_explanation_status' => $item['existing_explanation_status'],
            'has_diagram_or_image' => $item['has_diagram_or_image'],
            'effective_language' => $item['effective_language'],
            'scenario_group_id' => $item['scenario_group_id'],
            'question_presentation_type_id' => $item['question_presentation_type_id'],
            'is_straight_mcq' => $item['is_straight_mcq'],
        ], $pageSlice);

        return [
            'summary' => [
                'total_matched_questions' => $evaluation['summary']['total_matched_questions'],
                'total_eligible_questions' => $totalEligible,
                'missing_en_count' => $evaluation['summary']['missing_en_count'],
                'missing_ur_count' => $evaluation['summary']['missing_ur_count'],
                'missing_both_count' => $evaluation['summary']['missing_both_count'],
                'missing_en_only_count' => $evaluation['summary']['missing_en_only_count'],
                'missing_ur_only_count' => $evaluation['summary']['missing_ur_only_count'],
                'with_diagrams_or_images_count' => $evaluation['summary']['with_diagrams_or_images_count'],
                'ineligible_count' => $evaluation['summary']['ineligible_count'],
                'invalid_mcq_count' => $evaluation['summary']['invalid_mcq_count'],
                'invalid_answer_structure_count' => $evaluation['summary']['invalid_answer_structure_count'],
                'other_ineligible_count' => $evaluation['summary']['other_ineligible_count'],
                'already_complete_count' => $evaluation['summary']['already_complete_count'],
                'excluded_active_batch_count' => $evaluation['summary']['excluded_active_batch_count'],
            ],
            'cost_estimate' => $model ? $this->estimateCost($model, $batchCandidates, $language) : null,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalEligible,
                'last_page' => max(1, (int) ceil($totalEligible / $perPage)),
            ],
            'items' => $previewRows,
        ];
    }

    /**
     * Evaluate all authorized questions matching filters and classify eligibility + missing explanations.
     */
    public function evaluate(User $user, array $filters, ?AiModel $model = null, string $language = 'both'): array
    {
        $rows = $this->baseQuery($filters)->orderBy('q.id')->get();
        if ($rows->isEmpty()) {
            return [
                'eligible' => [],
                'summary' => [
                    'total_matched_questions' => 0,
                    'missing_en_count' => 0,
                    'missing_ur_count' => 0,
                    'missing_both_count' => 0,
                    'missing_en_only_count' => 0,
                    'missing_ur_only_count' => 0,
                    'with_diagrams_or_images_count' => 0,
                    'ineligible_count' => 0,
                    'invalid_mcq_count' => 0,
                    'invalid_answer_structure_count' => 0,
                    'other_ineligible_count' => 0,
                    'already_complete_count' => 0,
                    'excluded_active_batch_count' => 0,
                ],
            ];
        }

        $questionIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $activeBatchQuestionIds = $this->activeBatchQuestionIds($questionIds);
        $optionsByQuestion = DB::table('exam_question_options_tbl')
            ->whereIn('question_id', $questionIds)
            ->orderBy('id')
            ->get(['id', 'question_id', 'option', 'option_um', 'is_answer'])
            ->groupBy('question_id');

        $missingFilter = $filters['missing_filter'] ?? null;
        $diagramFilter = array_key_exists('has_diagram', $filters) && $filters['has_diagram'] !== null && $filters['has_diagram'] !== ''
            ? (bool) (int) $filters['has_diagram']
            : null;

        $summary = [
            'total_matched_questions' => 0,
            'missing_en_count' => 0,
            'missing_ur_count' => 0,
            'missing_both_count' => 0,
            'missing_en_only_count' => 0,
            'missing_ur_only_count' => 0,
            'with_diagrams_or_images_count' => 0,
            'ineligible_count' => 0,
            'invalid_mcq_count' => 0,
            'invalid_answer_structure_count' => 0,
            'other_ineligible_count' => 0,
            'already_complete_count' => 0,
            'excluded_active_batch_count' => 0,
        ];

        $eligible = [];

        foreach ($rows as $row) {
            $question = (array) $row;
            $options = ($optionsByQuestion->get($question['id']) ?? collect())
                ->map(fn ($opt) => (array) $opt)
                ->all();
            $context = ['question' => $question, 'options' => $options];

            if (! $this->explanationService->isUserAuthorized($user, $context, 'questions.view', 'questions.bulk-generate-explanation')) {
                continue;
            }

            $inspection = $this->explanationService->inspectContextEligibility($context, $model);

            if ($diagramFilter !== null && $inspection['has_images'] !== $diagramFilter) {
                continue;
            }

            $summary['total_matched_questions']++;

            if (! $inspection['eligible']) {
                $summary['ineligible_count']++;
                if ($inspection['reason_code'] === 'not_mcq') {
                    $summary['invalid_mcq_count']++;
                } elseif ($inspection['reason_code'] === 'invalid_answer_structure') {
                    $summary['invalid_answer_structure_count']++;
                } else {
                    $summary['other_ineligible_count']++;
                }

                continue;
            }

            $missingStatus = ExplanationContentHelper::missingStatus(
                $question['explanation'] ?? null,
                $question['explanation_um'] ?? null
            );

            if ($missingFilter !== null && $missingFilter !== '') {
                $matchesMissing = match ($missingFilter) {
                    'missing_en' => in_array($missingStatus, ['missing_en', 'missing_both'], true),
                    'missing_ur' => in_array($missingStatus, ['missing_ur', 'missing_both'], true),
                    'missing_both' => $missingStatus === 'missing_both',
                    'missing_any' => $missingStatus !== 'complete',
                    default => true,
                };
                if (! $matchesMissing) {
                    continue;
                }
            }

            $effectiveLanguage = ExplanationContentHelper::effectiveLanguage(
                $language,
                $question['explanation'] ?? null,
                $question['explanation_um'] ?? null
            );

            if ($effectiveLanguage === null) {
                $summary['already_complete_count']++;

                continue;
            }

            if (isset($activeBatchQuestionIds[(int) $question['id']])) {
                $summary['excluded_active_batch_count']++;

                continue;
            }

            if ($missingStatus === 'missing_both') {
                $summary['missing_both_count']++;
                $summary['missing_en_count']++;
                $summary['missing_ur_count']++;
            } elseif ($missingStatus === 'missing_en') {
                $summary['missing_en_only_count']++;
                $summary['missing_en_count']++;
            } elseif ($missingStatus === 'missing_ur') {
                $summary['missing_ur_only_count']++;
                $summary['missing_ur_count']++;
            }

            if ($inspection['has_images']) {
                $summary['with_diagrams_or_images_count']++;
            }

            $presentationTypeId = isset($question['question_presentation_type_id']) && $question['question_presentation_type_id'] !== null
                ? (int) $question['question_presentation_type_id']
                : null;
            $presentationCode = strtolower(trim((string) ($question['question_presentation_type_code'] ?? '')));
            $isStraight = $presentationTypeId === null || in_array($presentationCode, ['straight', 'straight_mcq'], true);

            $eligible[] = [
                'question_id' => (int) $question['id'],
                'question_text' => ExplanationContentHelper::extractPlainPreview($question['question'] ?? null),
                'question_text_ur' => ExplanationContentHelper::extractPlainPreview($question['question_um'] ?? null),
                'correct_answer_summary' => ExplanationContentHelper::extractPlainPreview($inspection['correct_option_text'], 120),
                'existing_explanation_status' => $missingStatus,
                'has_diagram_or_image' => $inspection['has_images'],
                'image_count' => $inspection['image_count'],
                'content_bytes' => $inspection['content_bytes'],
                'effective_language' => $effectiveLanguage,
                'scenario_group_id' => isset($question['scenario_group_id']) ? (int) $question['scenario_group_id'] : null,
                'question_presentation_type_id' => $presentationTypeId,
                'is_straight_mcq' => $isStraight,
                'context' => $context,
            ];
        }

        return [
            'eligible' => $eligible,
            'summary' => $summary,
        ];
    }

    /**
     * Return a map [question_id => true] for questions currently participating in a non-cancelled active batch
     * (status in pending, processing, generated, needs_review).
     * Questions in cancelled batches, or items already saved/failed/skipped, are NOT excluded.
     */
    public function activeBatchQuestionIds(array $questionIds = []): array
    {
        if (! Schema::hasTable('ai_explanation_batch_items') || ! Schema::hasTable('ai_explanation_batches')) {
            return [];
        }

        $query = DB::table('ai_explanation_batch_items as items')
            ->join('ai_explanation_batches as batches', 'batches.id', '=', 'items.batch_id')
            ->where('batches.status', '!=', AiExplanationBatch::STATUS_CANCELLED)
            ->whereIn('items.status', AiExplanationBatchItem::ACTIVE_STATES);

        if ($questionIds !== []) {
            $query->whereIn('items.question_id', $questionIds);
        }

        return $query->pluck('items.question_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * Estimate batch cost from AiModel pricing, candidate token footprint, and historical telemetry.
     * Never invents pricing where AiModel pricing columns are missing.
     */
    public function estimateCost(AiModel $model, array $candidates, string $targetLanguage): array
    {
        $itemCount = count($candidates);
        $missingFieldCount = 0;
        $estimatedInputTokens = 0;
        $estimatedOutputTokens = 0;
        $imageCount = 0;

        foreach ($candidates as $candidate) {
            $effLang = $candidate['effective_language'] ?? $targetLanguage;
            $fieldsCount = $effLang === 'both' ? 2 : 1;
            $missingFieldCount += $fieldsCount;

            $contentBytes = (int) ($candidate['content_bytes'] ?? 600);
            $itemImages = (int) ($candidate['image_count'] ?? 0);
            $imageCount += $itemImages;

            $estimatedInputTokens += 320 + (int) ceil($contentBytes / 4) + ($itemImages * 450);
            $estimatedOutputTokens += $fieldsCount * 130;
        }

        $inputPrice = $model->input_price_per_million !== null ? (float) $model->input_price_per_million : null;
        $outputPrice = $model->output_price_per_million !== null ? (float) $model->output_price_per_million : null;
        $pricingAvailable = $inputPrice !== null && $outputPrice !== null;

        $estimatedCost = null;
        if ($pricingAvailable) {
            $rawCost = (($estimatedInputTokens / 1000000) * $inputPrice)
                + (($estimatedOutputTokens / 1000000) * $outputPrice);
            $estimatedCost = number_format($rawCost, 8, '.', '');
        }

        $historicalAvgCost = null;
        $historicalSampleSize = 0;
        if (Schema::hasTable('ai_requests')) {
            $recentCosts = AiRequest::query()
                ->where('purpose', 'question_explanation')
                ->where('ai_model_id', $model->id)
                ->where('status', 'successful')
                ->whereNotNull('estimated_cost')
                ->latest('id')
                ->limit(50)
                ->pluck('estimated_cost')
                ->map(fn ($v) => (float) $v)
                ->all();

            $historicalSampleSize = count($recentCosts);
            if ($historicalSampleSize > 0) {
                $historicalAvgCost = number_format(array_sum($recentCosts) / $historicalSampleSize, 8, '.', '');
            }
        }

        return [
            'is_estimate' => true,
            'currency' => $model->currency ?? 'USD',
            'pricing_available' => $pricingAvailable,
            'model_id' => $model->id,
            'model_key' => $model->model_key,
            'target_language' => $targetLanguage,
            'item_count' => $itemCount,
            'missing_field_count' => $missingFieldCount,
            'image_count' => $imageCount,
            'estimated_input_tokens' => $estimatedInputTokens,
            'estimated_output_tokens' => $estimatedOutputTokens,
            'estimated_total_tokens' => $estimatedInputTokens + $estimatedOutputTokens,
            'estimated_cost' => $estimatedCost,
            'historical_average_cost_per_question' => $historicalAvgCost,
            'historical_sample_size' => $historicalSampleSize,
            'pricing_snapshot' => $model->pricingSnapshot(),
        ];
    }

    private function baseQuery(array $filters)
    {
        $hasPresentationTable = Schema::hasTable('question_presentation_type_tbl');
        $hasPresentationCol = Schema::hasColumn('exam_question_tbl', 'question_presentation_type_id');
        $hasActivateCol = Schema::hasColumn('exam_question_tbl', 'activate');
        $hasStatusCol = Schema::hasColumn('exam_question_tbl', 'status');

        $query = DB::table('exam_question_tbl as q')
            ->leftJoin('book_unit_topic_tbl as topics', 'topics.id', '=', 'q.topic_id')
            ->leftJoin('book_unit_tbl as units', 'units.id', '=', DB::raw('COALESCE(q.unit_id, topics.unit_id)'))
            ->leftJoin('book_tbl as books', 'books.id', '=', DB::raw('COALESCE(q.book_id, units.book_id)'))
            ->leftJoin('subject_tbl as subjects', 'subjects.id', '=', 'books.subject_id')
            ->leftJoin('class_tbl as classes', 'classes.id', '=', 'books.class_id')
            ->leftJoin('question_scenario_groups_tbl as scenarios', 'scenarios.id', '=', 'q.scenario_group_id');

        $selects = [
            'q.*',
            'books.subject_id',
            'books.class_id',
            'books.curriculum_board_id',
            'subjects.subject_name',
            'classes.class_name',
            'topics.topic_name',
            DB::raw('COALESCE(q.unit_id, topics.unit_id) as resolved_unit_id'),
            DB::raw('COALESCE(q.book_id, units.book_id) as resolved_book_id'),
            'scenarios.scenario_text',
            'scenarios.scenario_text_um',
            'scenarios.scenario_image',
        ];

        if ($hasPresentationTable && $hasPresentationCol) {
            $query->leftJoin('question_presentation_type_tbl as presentation_types', 'presentation_types.id', '=', 'q.question_presentation_type_id');
            $selects[] = 'presentation_types.code as question_presentation_type_code';
            $selects[] = 'presentation_types.type_name as question_presentation_type_name';
        } else {
            $selects[] = DB::raw('NULL as question_presentation_type_code');
            $selects[] = DB::raw('NULL as question_presentation_type_name');
        }

        $query->select($selects);

        $applySimple = function (string $column, mixed $value) use ($query): void {
            if (is_array($value)) {
                $values = array_values(array_filter($value, fn ($item) => $item !== null && $item !== ''));
                if ($values !== []) {
                    $query->whereIn($column, $values);
                }

                return;
            }
            if ($value !== null && $value !== '') {
                $query->where($column, $value);
            }
        };

        $applySimple('books.curriculum_board_id', $filters['curriculum_board_id'] ?? null);
        $applySimple('books.class_id', $filters['class_id'] ?? null);
        $applySimple('books.subject_id', $filters['subject_id'] ?? null);
        $applySimple('q.topic_id', $filters['topic_id'] ?? null);

        if (! empty($filters['question_ids']) && is_array($filters['question_ids'])) {
            $ids = array_values(array_filter(array_map('intval', $filters['question_ids']), fn ($id) => $id > 0));
            if ($ids !== []) {
                $query->whereIn('q.id', $ids);
            }
        }

        if (isset($filters['book_id']) && $filters['book_id'] !== null && $filters['book_id'] !== '') {
            $bookIds = is_array($filters['book_id']) ? $filters['book_id'] : [$filters['book_id']];
            $bookIds = array_values(array_filter($bookIds, fn ($v) => $v !== null && $v !== ''));
            if ($bookIds !== []) {
                $query->where(function ($sub) use ($bookIds) {
                    $sub->whereIn('q.book_id', $bookIds)->orWhereIn('units.book_id', $bookIds);
                });
            }
        }

        if (isset($filters['unit_id']) && $filters['unit_id'] !== null && $filters['unit_id'] !== '') {
            $unitIds = is_array($filters['unit_id']) ? $filters['unit_id'] : [$filters['unit_id']];
            $unitIds = array_values(array_filter($unitIds, fn ($v) => $v !== null && $v !== ''));
            if ($unitIds !== []) {
                $query->where(function ($sub) use ($unitIds) {
                    $sub->whereIn('q.unit_id', $unitIds)->orWhereIn('topics.unit_id', $unitIds);
                });
            }
        }

        if ($hasActivateCol && array_key_exists('activate', $filters) && $filters['activate'] !== null && $filters['activate'] !== '') {
            $query->where('q.activate', (int) (bool) $filters['activate']);
        }

        if ($hasStatusCol && isset($filters['status']) && $filters['status'] !== null && $filters['status'] !== '') {
            $applySimple('q.status', $filters['status']);
        }

        if ($hasPresentationCol && array_key_exists('question_presentation_type_id', $filters)
            && $filters['question_presentation_type_id'] !== null && $filters['question_presentation_type_id'] !== '') {
            $rawPresentation = $filters['question_presentation_type_id'];
            $values = is_array($rawPresentation) ? $rawPresentation : [$rawPresentation];
            $includeNullStraight = false;
            $numericIds = [];

            foreach ($values as $val) {
                if ($val === 'straight' || $val === 'null' || $val === 0 || $val === '0') {
                    $includeNullStraight = true;
                } elseif (is_numeric($val)) {
                    $numericIds[] = (int) $val;
                }
            }

            if ($hasPresentationTable && $numericIds !== []) {
                $straightMatches = DB::table('question_presentation_type_tbl')
                    ->whereIn('id', $numericIds)
                    ->get(['id', 'code', 'type_name'])
                    ->contains(function ($row) {
                        $code = strtolower(trim((string) ($row->code ?? '')));
                        $name = strtolower(trim((string) ($row->type_name ?? '')));

                        return in_array($code, ['straight', 'straight_mcq'], true) || str_contains($name, 'straight');
                    });
                if ($straightMatches) {
                    $includeNullStraight = true;
                }
            }

            $query->where(function ($sub) use ($numericIds, $includeNullStraight) {
                if ($numericIds !== []) {
                    $sub->whereIn('q.question_presentation_type_id', $numericIds);
                }
                if ($includeNullStraight) {
                    $sub->orWhereNull('q.question_presentation_type_id');
                }
            });
        }

        return $query;
    }
}
