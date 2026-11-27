<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiExplanationBatchItem extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_GENERATED = 'generated';
    public const STATUS_SAVED = 'saved';
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public const ACTIVE_STATES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_GENERATED,
        self::STATUS_NEEDS_REVIEW,
    ];

    protected $table = 'ai_explanation_batch_items';

    protected $fillable = [
        'batch_id',
        'question_id',
        'effective_language',
        'status',
        'ai_request_id',
        'draft_explanation',
        'draft_explanation_um',
        'context_hash',
        'explanation_hashes',
        'needs_review',
        'review_reason',
        'error_code',
        'error_message',
        'attempt_count',
        'dispatched_at',
        'processing_started_at',
        'saved_by',
        'saved_at',
    ];

    protected $casts = [
        'batch_id' => 'integer',
        'question_id' => 'integer',
        'ai_request_id' => 'integer',
        'explanation_hashes' => 'array',
        'needs_review' => 'boolean',
        'attempt_count' => 'integer',
        'dispatched_at' => 'datetime',
        'processing_started_at' => 'datetime',
        'saved_by' => 'integer',
        'saved_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AiExplanationBatch::class, 'batch_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ExamQuestion::class, 'question_id');
    }

    public function aiRequest(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class, 'ai_request_id');
    }

    public function saver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saved_by');
    }
}
