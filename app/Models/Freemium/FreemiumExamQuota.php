<?php

namespace App\Models\Freemium;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FreemiumExamQuota extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'freemium_exam_quotas';

    protected $fillable = [
        'campaign_id',
        'exam_type',
        'window_type',
        'max_tests',
        'allow_retakes',
        'max_attempts_per_test',
        'time_limit_minutes',
    ];

    protected $casts = [
        'max_tests'             => 'integer',
        'allow_retakes'         => 'boolean',
        'max_attempts_per_test' => 'integer',
        'time_limit_minutes'    => 'integer',
    ];

    // ==========================================
    // Relationships
    // ==========================================

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(FreemiumCampaign::class, 'campaign_id');
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(FreemiumQuotaConsumption::class, 'primary_quota_id');
    }

    public function consumptionEnvelopes(): HasMany
    {
        return $this->hasMany(FreemiumQuotaConsumptionEnvelope::class, 'quota_id');
    }

    // ==========================================
    // Helpers
    // ==========================================

    public function isUnlimited(): bool
    {
        return $this->max_tests === null || $this->max_tests === -1;
    }
}
