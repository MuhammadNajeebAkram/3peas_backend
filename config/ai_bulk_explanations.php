<?php

return [
    'default_batch_size' => 25,
    'max_batch_size' => (int) env('AI_BULK_EXPLANATION_MAX_BATCH_SIZE', 100),
    'queue_connection' => env('AI_BULK_EXPLANATION_QUEUE_CONNECTION'),
    'queue_name' => env('AI_BULK_EXPLANATION_QUEUE', 'ai-explanations'),
    // Bulk AI calls run with 1 internal AiService attempt (<=100s worst-case).
    // Ordering: provider_max (100s) < job_timeout (150s) < lock_seconds (210s) < stale_processing (240s) < retry_after (270s).
    'job_timeout' => (int) env('AI_BULK_EXPLANATION_JOB_TIMEOUT', 150),
    'job_retry_until_minutes' => (int) env('AI_BULK_EXPLANATION_RETRY_UNTIL_MINUTES', 120),
    'max_execution_attempts' => (int) env('AI_BULK_EXPLANATION_MAX_ATTEMPTS', 3),
    'job_backoff' => [15, 60],
    'lock_seconds' => (int) env('AI_BULK_EXPLANATION_LOCK_SECONDS', 210),
    'stale_processing_seconds' => (int) env('AI_BULK_EXPLANATION_STALE_PROCESSING_SECONDS', 240),
    'stale_pending_dispatch_seconds' => (int) env('AI_BULK_EXPLANATION_STALE_PENDING_SECONDS', 900),
    'rate_limit_per_minute' => (int) env('AI_BULK_EXPLANATION_RATE_LIMIT_PER_MINUTE', 20),
    'max_estimated_cost_usd' => env('AI_BULK_EXPLANATION_MAX_COST_USD') !== null
        ? (float) env('AI_BULK_EXPLANATION_MAX_COST_USD')
        : null,
];
