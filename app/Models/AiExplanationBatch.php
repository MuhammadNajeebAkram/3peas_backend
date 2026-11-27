<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiExplanationBatch extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'ai_explanation_batches';

    protected $fillable = [
        'created_by',
        'ai_provider_id',
        'ai_model_id',
        'filters_snapshot',
        'target_language',
        'overwrite_existing',
        'status',
        'total_items',
        'pending_items',
        'processing_items',
        'generated_items',
        'needs_review_items',
        'failed_items',
        'skipped_items',
        'saved_items',
        'estimated_cost',
        'actual_cost',
        'actual_input_tokens',
        'actual_output_tokens',
        'actual_total_tokens',
        'cost_estimate_snapshot',
        'started_at',
        'completed_at',
        'cancelled_at',
        'error_summary',
    ];

    protected $casts = [
        'filters_snapshot' => 'array',
        'cost_estimate_snapshot' => 'array',
        'overwrite_existing' => 'boolean',
        'total_items' => 'integer',
        'pending_items' => 'integer',
        'processing_items' => 'integer',
        'generated_items' => 'integer',
        'needs_review_items' => 'integer',
        'failed_items' => 'integer',
        'skipped_items' => 'integer',
        'saved_items' => 'integer',
        'estimated_cost' => 'decimal:8',
        'actual_cost' => 'decimal:8',
        'actual_input_tokens' => 'integer',
        'actual_output_tokens' => 'integer',
        'actual_total_tokens' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AiExplanationBatchItem::class, 'batch_id');
    }
}
