<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiRequest extends Model
{
    use SoftDeletes;

    public const STATUSES = ['pending', 'successful', 'failed', 'incomplete', 'refused', 'cancelled'];

    protected $fillable = [
        'operation_id', 'attempt_number', 'user_id', 'ai_model_id', 'purpose',
        'subject_type', 'subject_id', 'trigger_type', 'record_source', 'environment',
        'provider', 'requested_model', 'returned_model', 'language', 'prompt_version',
        'request_parameters', 'metadata', 'response_payload', 'status', 'response_status',
        'input_tokens', 'cached_input_tokens', 'output_tokens', 'reasoning_tokens',
        'total_tokens', 'estimated_cost', 'pricing_snapshot', 'provider_request_id',
        'provider_response_id', 'duration_ms', 'http_status', 'error_code',
        'error_message', 'completed_at',
    ];

    protected $casts = [
        'request_parameters' => 'array', 'metadata' => 'array',
        'response_payload' => 'array', 'pricing_snapshot' => 'array',
        'input_tokens' => 'integer', 'cached_input_tokens' => 'integer',
        'output_tokens' => 'integer', 'reasoning_tokens' => 'integer',
        'total_tokens' => 'integer', 'duration_ms' => 'integer',
        'estimated_cost' => 'decimal:8', 'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class)->withTrashed();
    }
}
