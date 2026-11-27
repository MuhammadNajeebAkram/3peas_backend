<?php

namespace App\Http\Services;

use App\Exceptions\AiGenerationException;
use App\Jobs\ProcessBulkExplanationItemJob;
use App\Models\AiExplanationBatch;
use App\Models\AiExplanationBatchItem;
use App\Models\AiRequest;
use App\Models\User;
use App\Support\ExplanationContentHelper;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BulkQuestionExplanationService
{
    public function __construct(
        private QuestionExplanationService $explanationService,
        private BulkQuestionExplanationQueryService $queryService,
    ) {}

    public function authorizeBatchAccess(User $user, AiExplanationBatch $batch): void
    {
        $user->loadMissing('role');
        $roleName = str_replace(['-', ' '], '_', strtolower(trim((string) $user->role?->name)));
        if ($roleName === 'super_admin') {
            return;
        }
        abort_unless((int) $batch->created_by === (int) $user->id, 403, 'You are not allowed to access this bulk explanation batch.');
    }

    /**
     * Snapshot eligible missing-explanation questions into a queued batch and dispatch one job per item.
     * Enforces overwrite_existing = false, active-batch exclusion, and drafts-only mode.
     */
    public function createBatch(User $user, array $input): AiExplanationBatch
    {
        if (! empty($input['overwrite_existing'])) {
            throw ValidationException::withMessages([
                'overwrite_existing' => 'Overwriting existing explanations is not allowed in bulk generation.',
            ]);
        }

        $language = (string) ($input['language'] ?? 'both');
        $defaultSize = (int) config('ai_bulk_explanations.default_batch_size', 25);
        $maxSize = (int) config('ai_bulk_explanations.max_batch_size', 100);
        $batchSize = isset($input['batch_size']) ? (int) $input['batch_size'] : $defaultSize;

        if ($batchSize < 1 || $batchSize > $maxSize) {
            throw ValidationException::withMessages([
                'batch_size' => "Batch size must be between 1 and {$maxSize}.",
            ]);
        }

        $model = $this->explanationService->model(
            isset($input['model_id']) ? (int) $input['model_id'] : null,
            isset($input['ai_provider_id']) ? (int) $input['ai_provider_id'] : null
        );
        $model->loadMissing('provider');

        $filters = $this->extractFilters($input);

        // Short creation lock prevents two simultaneous batch creations from selecting the same active questions.
        $createLock = Cache::lock('bulk-explanation:create-batch', 15);
        abort_unless($createLock->block(5), 409, 'Another bulk explanation batch is currently being created. Please retry.');

        $createdItemIds = [];
        try {
            $evaluation = $this->queryService->evaluate($user, $filters, $model, $language);
            $selected = array_slice($evaluation['eligible'], 0, $batchSize);

            if ($selected === []) {
                $message = ($evaluation['summary']['excluded_active_batch_count'] ?? 0) > 0
                    ? 'Matching questions are already in an active bulk explanation batch.'
                    : 'No eligible MCQs with missing explanations matched the selected filters and model.';
                throw ValidationException::withMessages(['filters' => $message]);
            }

            $costEstimate = $this->queryService->estimateCost($model, $selected, $language);
            $maxCost = config('ai_bulk_explanations.max_estimated_cost_usd');
            if ($maxCost !== null && $costEstimate['estimated_cost'] !== null && (float) $costEstimate['estimated_cost'] > (float) $maxCost) {
                throw ValidationException::withMessages([
                    'batch_size' => 'The estimated batch cost exceeds the configured maximum cost ceiling.',
                ]);
            }

            $batch = DB::transaction(function () use ($user, $model, $filters, $language, $selected, $costEstimate, &$createdItemIds) {
                $candidateIds = array_column($selected, 'question_id');
                $activeNow = $this->queryService->activeBatchQuestionIds($candidateIds);
                $filteredSelected = array_values(array_filter(
                    $selected,
                    fn (array $candidate) => ! isset($activeNow[$candidate['question_id']])
                ));

                if ($filteredSelected === []) {
                    throw ValidationException::withMessages([
                        'filters' => 'Matching questions were claimed by another active bulk explanation batch.',
                    ]);
                }

                $batch = AiExplanationBatch::create([
                    'created_by' => $user->id,
                    'ai_provider_id' => $model->ai_provider_id,
                    'ai_model_id' => $model->id,
                    'filters_snapshot' => $filters,
                    'target_language' => $language,
                    'overwrite_existing' => false,
                    'status' => AiExplanationBatch::STATUS_QUEUED,
                    'total_items' => count($filteredSelected),
                    'pending_items' => count($filteredSelected),
                    'processing_items' => 0,
                    'generated_items' => 0,
                    'needs_review_items' => 0,
                    'failed_items' => 0,
                    'skipped_items' => 0,
                    'saved_items' => 0,
                    'estimated_cost' => $costEstimate['estimated_cost'],
                    'actual_cost' => null,
                    'actual_input_tokens' => 0,
                    'actual_output_tokens' => 0,
                    'actual_total_tokens' => 0,
                    'cost_estimate_snapshot' => $costEstimate,
                ]);

                foreach ($filteredSelected as $candidate) {
                    $item = AiExplanationBatchItem::create([
                        'batch_id' => $batch->id,
                        'question_id' => $candidate['question_id'],
                        'effective_language' => $candidate['effective_language'],
                        'status' => AiExplanationBatchItem::STATUS_PENDING,
                        'needs_review' => false,
                        'attempt_count' => 0,
                        'dispatched_at' => null,
                        'processing_started_at' => null,
                    ]);
                    $createdItemIds[] = $item->id;
                }

                return $batch;
            });
        } finally {
            $createLock->release();
        }

        $this->dispatchBatchItems($createdItemIds);

        return $batch->fresh(['model.provider']);
    }

    /**
     * Dispatch pending batch item jobs and stamp dispatched_at per item.
     * If any dispatch throws, remaining items stay dispatched_at = null so reconcileBatch() can recover them.
     */
    public function dispatchBatchItems(array $itemIds): int
    {
        $dispatched = 0;
        foreach ($itemIds as $itemId) {
            try {
                ProcessBulkExplanationItemJob::dispatch((int) $itemId);
                AiExplanationBatchItem::where('id', (int) $itemId)
                    ->where('status', AiExplanationBatchItem::STATUS_PENDING)
                    ->update(['dispatched_at' => now()]);
                $dispatched++;
            } catch (\Throwable $e) {
                Log::warning('Failed to dispatch bulk explanation item job; item remains recoverable via reconciliation.', [
                    'batch_item_id' => (int) $itemId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $dispatched;
    }

    /**
     * Idempotently reconcile a batch:
     * 1) Recover stale processing items whose worker terminated/timed out without marking terminal status.
     * 2) Re-dispatch committed pending items that were never dispatched (dispatched_at IS NULL)
     *    or optionally stale-dispatched items when includeStaleDispatched is true.
     */
    public function reconcileBatch(AiExplanationBatch $batch, bool $includeStaleDispatched = false): array
    {
        $lock = Cache::lock('bulk-explanation:reconcile:'.$batch->id, 30);
        if (! $lock->get()) {
            return [
                'recovered_stale_processing' => 0,
                're_dispatched_pending' => 0,
                'batch' => $batch->fresh(),
            ];
        }

        try {
            $recoveredStale = $this->recoverStaleProcessingItems((int) $batch->id);
            $freshBatch = $batch->fresh();

            if ($freshBatch->status === AiExplanationBatch::STATUS_CANCELLED) {
                AiExplanationBatchItem::where('batch_id', $freshBatch->id)
                    ->where('status', AiExplanationBatchItem::STATUS_PENDING)
                    ->update([
                        'status' => AiExplanationBatchItem::STATUS_SKIPPED,
                        'error_code' => 'batch_cancelled',
                        'error_message' => 'Cancelled before processing started.',
                        'updated_at' => now(),
                    ]);
                $freshBatch = $this->recalculateBatchMetrics((int) $freshBatch->id);

                return [
                    'recovered_stale_processing' => $recoveredStale,
                    're_dispatched_pending' => 0,
                    'batch' => $freshBatch,
                ];
            }

            $stalePendingSeconds = max(60, (int) config('ai_bulk_explanations.stale_pending_dispatch_seconds', 900));
            $pendingIds = DB::transaction(function () use ($freshBatch, $includeStaleDispatched, $stalePendingSeconds) {
                $query = AiExplanationBatchItem::lockForUpdate()
                    ->where('batch_id', $freshBatch->id)
                    ->where('status', AiExplanationBatchItem::STATUS_PENDING)
                    ->where(function ($sub) use ($includeStaleDispatched, $stalePendingSeconds) {
                        $sub->whereNull('dispatched_at');
                        if ($includeStaleDispatched) {
                            $sub->orWhere('dispatched_at', '<=', now()->subSeconds($stalePendingSeconds));
                        }
                    });

                return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
            });

            $reDispatched = $pendingIds !== [] ? $this->dispatchBatchItems($pendingIds) : 0;
            $updatedBatch = $this->recalculateBatchMetrics((int) $freshBatch->id);

            return [
                'recovered_stale_processing' => $recoveredStale,
                're_dispatched_pending' => $reDispatched,
                'batch' => $updatedBatch,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Concurrency-safe recovery of items stuck in 'processing' beyond stale_processing_seconds
     * when the question-level cache lock is no longer held.
     * Never touches generated, saved, needs_review, or skipped items.
     */
    public function recoverStaleProcessingItems(int $batchId): int
    {
        $staleSeconds = max(120, (int) config('ai_bulk_explanations.stale_processing_seconds', 240));
        $cutoff = now()->subSeconds($staleSeconds);

        $candidates = AiExplanationBatchItem::where('batch_id', $batchId)
            ->where('status', AiExplanationBatchItem::STATUS_PROCESSING)
            ->where(function ($sub) use ($cutoff) {
                $sub->where('processing_started_at', '<=', $cutoff)
                    ->orWhere(function ($fallback) use ($cutoff) {
                        $fallback->whereNull('processing_started_at')
                            ->where('updated_at', '<=', $cutoff);
                    });
            })
            ->get(['id', 'question_id']);

        if ($candidates->isEmpty()) {
            return 0;
        }

        $recovered = 0;
        foreach ($candidates as $candidate) {
            // If the global question lock is still actively held, do NOT disturb the running worker.
            $probeLock = Cache::lock(QuestionExplanationService::questionLockKey((int) $candidate->question_id), 10);
            if (! $probeLock->get()) {
                continue;
            }

            try {
                $didRecover = DB::transaction(function () use ($candidate, $cutoff) {
                    $locked = AiExplanationBatchItem::lockForUpdate()->find($candidate->id);
                    if (! $locked || $locked->status !== AiExplanationBatchItem::STATUS_PROCESSING) {
                        return false;
                    }
                    $startedAt = $locked->processing_started_at ?? $locked->updated_at;
                    if ($startedAt && $startedAt->gt($cutoff)) {
                        return false;
                    }

                    $locked->update([
                        'status' => AiExplanationBatchItem::STATUS_FAILED,
                        'processing_started_at' => null,
                        'error_code' => 'stale_processing_timeout',
                        'error_message' => 'Processing timed out or the worker terminated unexpectedly. Use retry-failed to recover.',
                    ]);

                    return true;
                });

                if ($didRecover) {
                    $recovered++;
                }
            } finally {
                $probeLock->release();
            }
        }

        if ($recovered > 0) {
            $this->recalculateBatchMetrics($batchId);
        }

        return $recovered;
    }

    /**
     * Process a single queued batch item. Generates drafts ONLY; never writes to exam_question_tbl.
     * Genuine execution retries are bounded by item->attempt_count <= $maxAttempts (decoupled from queue RateLimited releases).
     *
     * @return array{status: string, retryable: bool, retry_delay?: int}
     */
    public function processItem(int $itemId, ?int $attemptOverride = null, ?int $maxAttempts = null): array
    {
        $maxAttempts = max(1, (int) ($maxAttempts ?? config('ai_bulk_explanations.max_execution_attempts', 3)));

        $claimed = DB::transaction(function () use ($itemId) {
            $item = AiExplanationBatchItem::lockForUpdate()->find($itemId);
            if (! $item) {
                return ['action' => 'missing'];
            }

            $batch = AiExplanationBatch::lockForUpdate()->find($item->batch_id);
            if (! $batch) {
                return ['action' => 'missing'];
            }

            if ($batch->status === AiExplanationBatch::STATUS_CANCELLED) {
                if ($item->status === AiExplanationBatchItem::STATUS_PENDING) {
                    $item->update([
                        'status' => AiExplanationBatchItem::STATUS_SKIPPED,
                        'processing_started_at' => null,
                        'error_code' => 'batch_cancelled',
                        'error_message' => 'Batch was cancelled before processing started.',
                    ]);
                }

                return ['action' => 'cancelled', 'batch_id' => $batch->id];
            }

            if ($item->status !== AiExplanationBatchItem::STATUS_PENDING) {
                return ['action' => 'duplicate', 'batch_id' => $batch->id, 'status' => $item->status];
            }

            $nextAttempt = $item->attempt_count + 1;
            $item->update([
                'status' => AiExplanationBatchItem::STATUS_PROCESSING,
                'attempt_count' => $nextAttempt,
                'processing_started_at' => now(),
                'error_code' => null,
                'error_message' => null,
            ]);

            if ($batch->status === AiExplanationBatch::STATUS_QUEUED) {
                $batch->status = AiExplanationBatch::STATUS_RUNNING;
                if ($batch->started_at === null) {
                    $batch->started_at = now();
                }
                $batch->save();
            }

            return [
                'action' => 'proceed',
                'item_id' => $item->id,
                'batch_id' => $batch->id,
                'question_id' => $item->question_id,
                'attempt' => $nextAttempt,
            ];
        });

        if ($claimed['action'] === 'missing') {
            return ['status' => 'missing', 'retryable' => false];
        }

        if ($claimed['action'] === 'cancelled') {
            $this->recalculateBatchMetrics((int) $claimed['batch_id']);

            return ['status' => AiExplanationBatchItem::STATUS_SKIPPED, 'retryable' => false];
        }

        if ($claimed['action'] === 'duplicate') {
            return ['status' => (string) $claimed['status'], 'retryable' => false];
        }

        $batchId = (int) $claimed['batch_id'];
        $questionId = (int) $claimed['question_id'];
        $currentAttempt = $attemptOverride ?? (int) $claimed['attempt'];
        $this->recalculateBatchMetrics($batchId);

        $lockSeconds = max(60, (int) config('ai_bulk_explanations.lock_seconds', QuestionExplanationService::QUESTION_LOCK_SECONDS));
        $lock = Cache::lock(QuestionExplanationService::questionLockKey($questionId), $lockSeconds);

        if (! $lock->get()) {
            return $this->handleRetryableOrFail(
                $itemId,
                $batchId,
                'question_locked',
                'Another explanation generation is currently running for this question.',
                $currentAttempt,
                $maxAttempts,
                20
            );
        }

        try {
            $item = AiExplanationBatchItem::findOrFail($itemId);
            $batch = AiExplanationBatch::findOrFail($batchId);
            $creator = User::with('role')->find($batch->created_by);

            if (! $creator) {
                $this->markItemTerminal($itemId, $batchId, AiExplanationBatchItem::STATUS_FAILED, 'creator_missing', 'Batch creator no longer exists.');

                return ['status' => AiExplanationBatchItem::STATUS_FAILED, 'retryable' => false];
            }

            try {
                $context = $this->explanationService->context($questionId);
            } catch (HttpException $e) {
                $this->markItemTerminal($itemId, $batchId, AiExplanationBatchItem::STATUS_SKIPPED, 'question_not_found', 'Question no longer exists.');

                return ['status' => AiExplanationBatchItem::STATUS_SKIPPED, 'retryable' => false];
            }

            if (! $this->explanationService->isUserAuthorized($creator, $context, 'questions.view', 'questions.bulk-generate-explanation')) {
                $this->markItemTerminal($itemId, $batchId, AiExplanationBatchItem::STATUS_FAILED, 'unauthorized_scope', 'Batch creator is no longer authorized for this question scope.');

                return ['status' => AiExplanationBatchItem::STATUS_FAILED, 'retryable' => false];
            }

            $model = $this->explanationService->model($batch->ai_model_id, $batch->ai_provider_id);
            $inspection = $this->explanationService->inspectContextEligibility($context, $model);
            if (! $inspection['eligible']) {
                $this->markItemTerminal($itemId, $batchId, AiExplanationBatchItem::STATUS_SKIPPED, (string) $inspection['reason_code'], (string) $inspection['reason']);

                return ['status' => AiExplanationBatchItem::STATUS_SKIPPED, 'retryable' => false];
            }

            $currentEffectiveLanguage = ExplanationContentHelper::effectiveLanguage(
                $item->effective_language,
                $context['question']['explanation'] ?? null,
                $context['question']['explanation_um'] ?? null
            );

            if ($currentEffectiveLanguage === null) {
                $this->markItemTerminal(
                    $itemId,
                    $batchId,
                    AiExplanationBatchItem::STATUS_SKIPPED,
                    'explanation_already_present',
                    'Requested explanation field(s) were filled before generation started.'
                );

                return ['status' => AiExplanationBatchItem::STATUS_SKIPPED, 'retryable' => false];
            }

            $record = $this->explanationService->generateForUser(
                (int) $creator->id,
                $context,
                $model,
                $currentEffectiveLanguage,
                'bulk',
                [
                    'bulk_batch_id' => $batch->id,
                    'bulk_batch_item_id' => $item->id,
                ]
            );

            $payload = is_array($record->response_payload) ? $record->response_payload : [];
            $needsReview = (bool) ($payload['needs_review'] ?? false);
            $reviewReason = isset($payload['review_reason']) ? (string) $payload['review_reason'] : null;
            $status = $needsReview ? AiExplanationBatchItem::STATUS_NEEDS_REVIEW : AiExplanationBatchItem::STATUS_GENERATED;

            $item->update([
                'effective_language' => $currentEffectiveLanguage,
                'status' => $status,
                'ai_request_id' => $record->id,
                'draft_explanation' => $payload['explanation'] ?? null,
                'draft_explanation_um' => $payload['explanation_um'] ?? null,
                'context_hash' => (string) data_get($record->metadata, 'context_hash'),
                'explanation_hashes' => data_get($record->metadata, 'explanation_hashes', []),
                'needs_review' => $needsReview,
                'review_reason' => $reviewReason,
                'processing_started_at' => null,
                'error_code' => null,
                'error_message' => null,
            ]);

            $this->recalculateBatchMetrics($batchId);

            return ['status' => $status, 'retryable' => false];
        } catch (ValidationException $e) {
            $firstMessage = collect($e->errors())->flatten()->first() ?? 'Validation failed for question explanation.';
            $this->markItemTerminal($itemId, $batchId, AiExplanationBatchItem::STATUS_FAILED, 'validation_error', (string) $firstMessage);

            return ['status' => AiExplanationBatchItem::STATUS_FAILED, 'retryable' => false];
        } catch (AiGenerationException $e) {
            $aiRecord = AiRequest::find($e->requestId);
            $errorCode = $aiRecord?->error_code ?? 'ai_generation_error';
            $httpStatus = $aiRecord?->http_status;

            // Only retry transient HTTP 429 or 5xx errors; never retry ambiguous timeouts (504/connection_error) or 4xx/invalid_output.
            $isRetryable = $httpStatus === 429 || ($httpStatus !== null && $httpStatus >= 500 && $httpStatus < 600);

            if ($isRetryable) {
                $backoff = (array) config('ai_bulk_explanations.job_backoff', [15, 60]);
                $delay = (int) ($backoff[min(max(0, $currentAttempt - 1), count($backoff) - 1)] ?? ($httpStatus === 429 ? 30 : 15));

                return $this->handleRetryableOrFail(
                    $itemId,
                    $batchId,
                    $errorCode,
                    $e->getMessage(),
                    $currentAttempt,
                    $maxAttempts,
                    $httpStatus === 429 ? max(30, $delay) : $delay,
                    $e->requestId
                );
            }

            $this->markItemTerminal($itemId, $batchId, AiExplanationBatchItem::STATUS_FAILED, $errorCode, $e->getMessage(), $e->requestId);

            return ['status' => AiExplanationBatchItem::STATUS_FAILED, 'retryable' => false];
        } catch (HttpException $e) {
            $this->markItemTerminal($itemId, $batchId, AiExplanationBatchItem::STATUS_FAILED, 'http_'.$e->getStatusCode(), $e->getMessage() ?: 'Request could not be processed.');

            return ['status' => AiExplanationBatchItem::STATUS_FAILED, 'retryable' => false];
        } finally {
            $lock->release();
        }
    }

    public function markItemFailedOnUnhandledException(int $itemId, string $errorCode, string $message): void
    {
        $item = AiExplanationBatchItem::find($itemId);
        if (! $item) {
            return;
        }
        if (in_array($item->status, [AiExplanationBatchItem::STATUS_GENERATED, AiExplanationBatchItem::STATUS_SAVED, AiExplanationBatchItem::STATUS_NEEDS_REVIEW], true)) {
            return;
        }

        $this->markItemTerminal($itemId, (int) $item->batch_id, AiExplanationBatchItem::STATUS_FAILED, $errorCode, $message);
    }

    /**
     * Save explicitly selected generated draft items to exam_question_tbl via the existing safe save workflow.
     * Atomic all-or-nothing rollback with structured conflict diagnostics (item_id + question_id).
     */
    public function applyDrafts(User $user, AiExplanationBatch $batch, array $itemIds): array
    {
        $this->authorizeBatchAccess($user, $batch);

        $uniqueIds = array_values(array_unique(array_map('intval', $itemIds)));
        if ($uniqueIds === []) {
            throw ValidationException::withMessages(['item_ids' => 'Select at least one generated draft item to save.']);
        }

        $items = AiExplanationBatchItem::where('batch_id', $batch->id)
            ->whereIn('id', $uniqueIds)
            ->orderBy('id')
            ->get();

        if ($items->count() !== count($uniqueIds)) {
            throw ValidationException::withMessages(['item_ids' => 'One or more selected items do not belong to this batch.']);
        }

        foreach ($items as $item) {
            if ($item->needs_review || $item->status === AiExplanationBatchItem::STATUS_NEEDS_REVIEW) {
                throw ValidationException::withMessages([
                    'item_ids' => "Item #{$item->id} (question #{$item->question_id}) is flagged for review and cannot be bulk-saved.",
                ]);
            }
            if ($item->status !== AiExplanationBatchItem::STATUS_GENERATED) {
                throw ValidationException::withMessages([
                    'item_ids' => "Item #{$item->id} is in '{$item->status}' status and cannot be saved.",
                ]);
            }
            if (! $item->ai_request_id) {
                throw ValidationException::withMessages([
                    'item_ids' => "Item #{$item->id} is missing its AI request reference.",
                ]);
            }
        }

        $savedResults = DB::transaction(function () use ($user, $items) {
            $results = [];

            foreach ($items as $item) {
                $lockedItem = AiExplanationBatchItem::lockForUpdate()->findOrFail($item->id);
                if ($lockedItem->status !== AiExplanationBatchItem::STATUS_GENERATED || $lockedItem->needs_review) {
                    throw new HttpResponseException(response()->json([
                        'success' => 0,
                        'message' => "Item #{$lockedItem->id} (question #{$lockedItem->question_id}) changed state before it could be saved.",
                        'conflict' => [
                            'item_id' => $lockedItem->id,
                            'question_id' => $lockedItem->question_id,
                            'effective_language' => $lockedItem->effective_language,
                            'reason' => "Item status is '{$lockedItem->status}'.",
                        ],
                    ], 409));
                }

                $explanations = [];
                if (in_array($lockedItem->effective_language, ['en', 'both'], true)) {
                    $explanations['explanation'] = $lockedItem->draft_explanation;
                }
                if (in_array($lockedItem->effective_language, ['ur', 'both'], true)) {
                    $explanations['explanation_um'] = $lockedItem->draft_explanation_um;
                }

                try {
                    $savedFields = $this->explanationService->saveApprovedDraft(
                        $user,
                        (int) $lockedItem->question_id,
                        (int) $lockedItem->ai_request_id,
                        (string) $lockedItem->effective_language,
                        $explanations,
                        true
                    );
                } catch (HttpException $e) {
                    if ($e->getStatusCode() === 409) {
                        throw new HttpResponseException(response()->json([
                            'success' => 0,
                            'message' => $e->getMessage(),
                            'conflict' => [
                                'item_id' => $lockedItem->id,
                                'question_id' => $lockedItem->question_id,
                                'effective_language' => $lockedItem->effective_language,
                                'reason' => $e->getMessage(),
                            ],
                        ], 409));
                    }
                    throw $e;
                }

                $lockedItem->update([
                    'status' => AiExplanationBatchItem::STATUS_SAVED,
                    'saved_by' => $user->id,
                    'saved_at' => now(),
                ]);

                $results[] = [
                    'item_id' => $lockedItem->id,
                    'question_id' => $lockedItem->question_id,
                    'effective_language' => $lockedItem->effective_language,
                    'saved_fields' => array_keys($savedFields),
                ];
            }

            return $results;
        });

        $updatedBatch = $this->recalculateBatchMetrics((int) $batch->id);

        return [
            'saved_count' => count($savedResults),
            'saved_items' => $savedResults,
            'batch' => $updatedBatch,
        ];
    }

    /**
     * Retry ONLY failed items in a batch (after recovering any stale processing items).
     * Never duplicates generated, saved, needs_review, or skipped items.
     */
    public function retryFailedItems(User $user, AiExplanationBatch $batch): AiExplanationBatch
    {
        $this->authorizeBatchAccess($user, $batch);

        $this->recoverStaleProcessingItems((int) $batch->id);

        $itemIdsToRetry = DB::transaction(function () use ($batch) {
            $lockedBatch = AiExplanationBatch::lockForUpdate()->findOrFail($batch->id);
            $failedItems = AiExplanationBatchItem::lockForUpdate()
                ->where('batch_id', $lockedBatch->id)
                ->where('status', AiExplanationBatchItem::STATUS_FAILED)
                ->get();

            if ($failedItems->isEmpty()) {
                throw ValidationException::withMessages(['batch' => 'There are no failed items to retry in this batch.']);
            }

            $ids = [];
            foreach ($failedItems as $item) {
                $item->update([
                    'status' => AiExplanationBatchItem::STATUS_PENDING,
                    'attempt_count' => 0,
                    'dispatched_at' => null,
                    'processing_started_at' => null,
                    'error_code' => null,
                    'error_message' => null,
                ]);
                $ids[] = $item->id;
            }

            $lockedBatch->update([
                'status' => AiExplanationBatch::STATUS_QUEUED,
                'completed_at' => null,
                'cancelled_at' => null,
                'error_summary' => null,
            ]);

            return $ids;
        });

        $updatedBatch = $this->recalculateBatchMetrics((int) $batch->id);
        $this->dispatchBatchItems($itemIdsToRetry);

        return $updatedBatch->fresh();
    }

    /**
     * Cancel remaining pending items without deleting or corrupting already generated/saved/needs_review drafts.
     */
    public function cancelBatch(User $user, AiExplanationBatch $batch): AiExplanationBatch
    {
        $this->authorizeBatchAccess($user, $batch);

        DB::transaction(function () use ($batch) {
            $lockedBatch = AiExplanationBatch::lockForUpdate()->findOrFail($batch->id);

            AiExplanationBatchItem::where('batch_id', $lockedBatch->id)
                ->where('status', AiExplanationBatchItem::STATUS_PENDING)
                ->update([
                    'status' => AiExplanationBatchItem::STATUS_SKIPPED,
                    'processing_started_at' => null,
                    'error_code' => 'batch_cancelled',
                    'error_message' => 'Cancelled before processing started.',
                    'updated_at' => now(),
                ]);

            if ($lockedBatch->status !== AiExplanationBatch::STATUS_CANCELLED) {
                $lockedBatch->status = AiExplanationBatch::STATUS_CANCELLED;
                $lockedBatch->cancelled_at = now();
                $lockedBatch->save();
            }
        });

        return $this->recalculateBatchMetrics((int) $batch->id);
    }

    /**
     * Recalculate item counters and aggregate actual cost/tokens across ALL AiRequest attempts
     * associated with this batch (including failed attempts that consumed tokens, deduplicated by AiRequest ID).
     */
    public function recalculateBatchMetrics(int $batchId): AiExplanationBatch
    {
        return DB::transaction(function () use ($batchId) {
            $batch = AiExplanationBatch::lockForUpdate()->findOrFail($batchId);

            $counts = AiExplanationBatchItem::where('batch_id', $batchId)
                ->select('status', DB::raw('COUNT(*) as aggregate'))
                ->groupBy('status')
                ->pluck('aggregate', 'status');

            $pending = (int) ($counts[AiExplanationBatchItem::STATUS_PENDING] ?? 0);
            $processing = (int) ($counts[AiExplanationBatchItem::STATUS_PROCESSING] ?? 0);
            $generated = (int) ($counts[AiExplanationBatchItem::STATUS_GENERATED] ?? 0);
            $needsReview = (int) ($counts[AiExplanationBatchItem::STATUS_NEEDS_REVIEW] ?? 0);
            $failed = (int) ($counts[AiExplanationBatchItem::STATUS_FAILED] ?? 0);
            $skipped = (int) ($counts[AiExplanationBatchItem::STATUS_SKIPPED] ?? 0);
            $saved = (int) ($counts[AiExplanationBatchItem::STATUS_SAVED] ?? 0);
            $total = $pending + $processing + $generated + $needsReview + $failed + $skipped + $saved;

            $questionIds = AiExplanationBatchItem::where('batch_id', $batchId)
                ->pluck('question_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            $actualCost = null;
            $inputTokens = 0;
            $outputTokens = 0;
            $totalTokens = 0;

            if ($questionIds !== []) {
                $requests = AiRequest::query()
                    ->where('purpose', 'question_explanation')
                    ->where('trigger_type', 'bulk')
                    ->where('subject_type', 'exam_question')
                    ->whereIn('subject_id', $questionIds)
                    ->when($batch->created_at, fn ($q) => $q->where('created_at', '>=', $batch->created_at->copy()->subMinute()))
                    ->get(['id', 'input_tokens', 'output_tokens', 'total_tokens', 'estimated_cost', 'metadata']);

                $seenIds = [];
                $costSum = 0.0;
                $pricedCount = 0;

                foreach ($requests as $req) {
                    if (isset($seenIds[$req->id])) {
                        continue;
                    }
                    if ((int) data_get($req->metadata, 'bulk_batch_id') !== $batchId) {
                        continue;
                    }
                    $seenIds[$req->id] = true;
                    $inputTokens += (int) ($req->input_tokens ?? 0);
                    $outputTokens += (int) ($req->output_tokens ?? 0);
                    $totalTokens += (int) ($req->total_tokens ?? 0);
                    if ($req->estimated_cost !== null) {
                        $costSum += (float) $req->estimated_cost;
                        $pricedCount++;
                    }
                }

                if ($pricedCount > 0) {
                    $actualCost = number_format($costSum, 8, '.', '');
                }
            }

            $status = $batch->status;
            $completedAt = $batch->completed_at;
            $errorSummary = $batch->error_summary;

            if ($status !== AiExplanationBatch::STATUS_CANCELLED) {
                if ($processing > 0 || ($pending > 0 && ($generated + $needsReview + $failed + $skipped + $saved) > 0)) {
                    $status = AiExplanationBatch::STATUS_RUNNING;
                    $completedAt = null;
                } elseif ($pending > 0) {
                    $status = AiExplanationBatch::STATUS_QUEUED;
                    $completedAt = null;
                } else {
                    $completedAt = $completedAt ?? now();
                    if ($total > 0 && $failed === $total) {
                        $status = AiExplanationBatch::STATUS_FAILED;
                    } else {
                        $status = AiExplanationBatch::STATUS_COMPLETED;
                    }
                    $errorSummary = $failed > 0 ? "{$failed} item(s) failed during generation." : null;
                }
            } elseif ($processing === 0 && $pending === 0 && $completedAt === null) {
                $completedAt = now();
            }

            $batch->update([
                'status' => $status,
                'total_items' => $total,
                'pending_items' => $pending,
                'processing_items' => $processing,
                'generated_items' => $generated,
                'needs_review_items' => $needsReview,
                'failed_items' => $failed,
                'skipped_items' => $skipped,
                'saved_items' => $saved,
                'actual_cost' => $actualCost,
                'actual_input_tokens' => $inputTokens,
                'actual_output_tokens' => $outputTokens,
                'actual_total_tokens' => $totalTokens,
                'completed_at' => $completedAt,
                'error_summary' => $errorSummary,
            ]);

            return $batch->fresh();
        });
    }

    private function handleRetryableOrFail(
        int $itemId,
        int $batchId,
        string $errorCode,
        string $message,
        int $attempt,
        int $maxAttempts,
        int $retryDelay,
        ?int $aiRequestId = null
    ): array {
        if ($attempt < $maxAttempts) {
            AiExplanationBatchItem::where('id', $itemId)->update([
                'status' => AiExplanationBatchItem::STATUS_PENDING,
                'processing_started_at' => null,
                'dispatched_at' => now(),
                'ai_request_id' => $aiRequestId,
                'error_code' => $errorCode,
                'error_message' => $message,
            ]);
            $this->recalculateBatchMetrics($batchId);

            return ['status' => AiExplanationBatchItem::STATUS_PENDING, 'retryable' => true, 'retry_delay' => $retryDelay];
        }

        $this->markItemTerminal($itemId, $batchId, AiExplanationBatchItem::STATUS_FAILED, $errorCode, $message, $aiRequestId);

        return ['status' => AiExplanationBatchItem::STATUS_FAILED, 'retryable' => false];
    }

    private function markItemTerminal(
        int $itemId,
        int $batchId,
        string $status,
        string $errorCode,
        string $message,
        ?int $aiRequestId = null
    ): void {
        $updates = [
            'status' => $status,
            'processing_started_at' => null,
            'error_code' => $errorCode,
            'error_message' => $message,
        ];
        if ($aiRequestId !== null) {
            $updates['ai_request_id'] = $aiRequestId;
        }

        AiExplanationBatchItem::where('id', $itemId)->update($updates);
        $this->recalculateBatchMetrics($batchId);
    }

    private function extractFilters(array $input): array
    {
        $allowed = [
            'curriculum_board_id',
            'class_id',
            'subject_id',
            'book_id',
            'unit_id',
            'topic_id',
            'question_presentation_type_id',
            'has_diagram',
            'activate',
            'status',
            'missing_filter',
            'question_ids',
        ];

        $filters = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $input) && $input[$key] !== null && $input[$key] !== '') {
                $filters[$key] = $input[$key];
            }
        }

        return $filters;
    }
}
