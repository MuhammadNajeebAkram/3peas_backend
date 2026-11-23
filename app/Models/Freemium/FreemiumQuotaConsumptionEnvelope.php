<?php

namespace App\Models\Freemium;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class FreemiumQuotaConsumptionEnvelope extends Model
{
    use HasFactory;

    protected $table = 'freemium_quota_consumption_envelopes';

    /**
     * Disable standard Eloquent timestamps (table has allocated_at, no updated_at).
     */
    public $timestamps = false;

    protected $fillable = [
        'consumption_id',
        'campaign_id',
        'quota_id',
        'grant_id',
        'window_type',
        'window_key',
        'counter_value_after_consumption',
        'allocated_at',
    ];

    protected $casts = [
        'counter_value_after_consumption' => 'integer',
        'allocated_at'                    => 'datetime',
    ];

    /**
     * Enforce strict append-only immutability.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('FreemiumQuotaConsumptionEnvelope is an append-only immutable ledger. Updates are prohibited.');
        });

        static::deleting(function () {
            throw new RuntimeException('FreemiumQuotaConsumptionEnvelope is an append-only immutable ledger. Deletions are prohibited.');
        });
    }

    // ==========================================
    // Relationships
    // ==========================================

    public function consumption(): BelongsTo
    {
        return $this->belongsTo(FreemiumQuotaConsumption::class, 'consumption_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(FreemiumCampaign::class, 'campaign_id');
    }

    public function quota(): BelongsTo
    {
        return $this->belongsTo(FreemiumExamQuota::class, 'quota_id');
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(FreemiumStudentGrant::class, 'grant_id');
    }
}
