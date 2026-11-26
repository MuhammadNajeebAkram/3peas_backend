<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiFeatureSetting extends Model
{
    protected $table = 'ai_feature_settings';

    protected $fillable = [
        'feature',
        'environment',
        'provider',
        'model',
        'is_enabled',
        'timeout',
        'max_output_tokens',
        'updated_by',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'timeout' => 'integer',
        'max_output_tokens' => 'integer',
        'updated_by' => 'integer',
    ];

    public function updatedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
