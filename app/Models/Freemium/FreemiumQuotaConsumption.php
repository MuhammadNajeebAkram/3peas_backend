<?php

namespace App\Models\Freemium;

use App\Models\PracticeSession;
use App\Models\Test;
use App\Models\TestAttempt;
use App\Models\WebUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class FreemiumQuotaConsumption extends Model
{
    use HasFactory;

    protected $table = 'freemium_quota_consumptions';

    /**
     * Disable standard Eloquent timestamps (table has consumed_at, no updated_at).
     */
    public $timestamps = false;

    /**
     * Immutable append-only ledger columns.
     * Stored generated columns (entity_type, entity_id) are explicitly omitted from fillable.
     */
    protected $fillable = [
        'user_id',
        'exam_type',
        'event_type',
        'primary_campaign_id',
        'primary_quota_id',
        'primary_grant_id',
        'test_id',
        'test_attempt_id',
        'practice_session_id',
        'attempt_number',
        'idempotency_key',
        'request_fingerprint',
        'decision_snapshot',
        'consumed_at',
    ];

    protected $casts = [
        'attempt_number'    => 'integer',
        'decision_snapshot' => 'array',
        'consumed_at'       => 'datetime',
        'entity_id'         => 'integer',
    ];

    /**
     * Enforce strict append-only immutability.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('FreemiumQuotaConsumption is an append-only immutable ledger. Updates are prohibited.');
        });

        static::deleting(function () {
            throw new RuntimeException('FreemiumQuotaConsumption is an append-only immutable ledger. Deletions are prohibited.');
        });
    }

    // ==========================================
    // Relationships
    // ==========================================

    public function student(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'user_id');
    }

    public function primaryCampaign(): BelongsTo
    {
        return $this->belongsTo(FreemiumCampaign::class, 'primary_campaign_id');
    }

    public function primaryQuota(): BelongsTo
    {
        return $this->belongsTo(FreemiumExamQuota::class, 'primary_quota_id');
    }

    public function primaryGrant(): BelongsTo
    {
        return $this->belongsTo(FreemiumStudentGrant::class, 'primary_grant_id');
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(Test::class, 'test_id');
    }

    public function testAttempt(): BelongsTo
    {
        return $this->belongsTo(TestAttempt::class, 'test_attempt_id');
    }

    public function practiceSession(): BelongsTo
    {
        return $this->belongsTo(PracticeSession::class, 'practice_session_id');
    }

    public function envelopes(): HasMany
    {
        return $this->hasMany(FreemiumQuotaConsumptionEnvelope::class, 'consumption_id');
    }
}
