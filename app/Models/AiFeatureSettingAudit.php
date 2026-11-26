<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiFeatureSettingAudit extends Model
{
    public $timestamps = false;

    protected $table = 'ai_feature_setting_audits';

    protected $fillable = [
        'feature',
        'environment',
        'admin_user_id',
        'previous_provider',
        'new_provider',
        'previous_model',
        'new_model',
        'previous_is_enabled',
        'new_is_enabled',
        'previous_timeout',
        'new_timeout',
        'previous_max_output_tokens',
        'new_max_output_tokens',
        'created_at',
    ];

    protected $casts = [
        'admin_user_id' => 'integer',
        'previous_is_enabled' => 'boolean',
        'new_is_enabled' => 'boolean',
        'previous_timeout' => 'integer',
        'new_timeout' => 'integer',
        'previous_max_output_tokens' => 'integer',
        'new_max_output_tokens' => 'integer',
        'created_at' => 'datetime',
    ];

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
