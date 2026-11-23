<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentIdempotencyRecord extends Model
{
    use HasFactory;

    protected $table = 'assessment_idempotency_records';

    protected $fillable = [
        'user_id',
        'operation',
        'idempotency_key',
        'request_fingerprint',
        'response_payload',
        'http_status',
        'status',
    ];

    protected $casts = [
        'response_payload' => 'array',
        'http_status'      => 'integer',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'user_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
