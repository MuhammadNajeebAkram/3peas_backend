<?php

namespace App\Jobs;

use App\Http\Services\BulkQuestionExplanationService;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessBulkExplanationItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Unlimited queue attempts so RateLimited middleware releases never exhaust
     * the failure retry budget. Bounded instead by retryUntil(), maxExceptions,
     * and AiExplanationBatchItem::$attempt_count for genuine execution attempts.
     */
    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout;

    public function __construct(public readonly int $batchItemId)
    {
        $this->timeout = max(30, (int) config('ai_bulk_explanations.job_timeout', 150));
        $this->afterCommit = true;

        $queueName = (string) config('ai_bulk_explanations.queue_name', 'ai-explanations');
        $this->onQueue($queueName);

        $configuredConnection = config('ai_bulk_explanations.queue_connection');
        if (is_string($configuredConnection) && $configuredConnection !== '') {
            $this->onConnection($configuredConnection);
        } elseif (config('queue.default') === 'database' && config('queue.connections.database_ai_explanations')) {
            $this->onConnection('database_ai_explanations');
        }
    }

    public function retryUntil(): DateTimeInterface
    {
        $minutes = max(10, (int) config('ai_bulk_explanations.job_retry_until_minutes', 120));

        return now()->addMinutes($minutes);
    }

    public function backoff(): array
    {
        return (array) config('ai_bulk_explanations.job_backoff', [15, 60]);
    }

    public function middleware(): array
    {
        return [
            new RateLimited('ai-bulk-explanations'),
        ];
    }

    public function handle(BulkQuestionExplanationService $service): void
    {
        $maxExecutionAttempts = max(1, (int) config('ai_bulk_explanations.max_execution_attempts', 3));
        $result = $service->processItem($this->batchItemId, null, $maxExecutionAttempts);

        if (($result['retryable'] ?? false) === true) {
            $delay = max(5, (int) ($result['retry_delay'] ?? 15));
            $this->release($delay);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ProcessBulkExplanationItemJob failed permanently.', [
            'batch_item_id' => $this->batchItemId,
            'error' => $exception->getMessage(),
        ]);

        app(BulkQuestionExplanationService::class)->markItemFailedOnUnhandledException(
            $this->batchItemId,
            'job_exception',
            'The background job failed or timed out unexpectedly.'
        );
    }
}
