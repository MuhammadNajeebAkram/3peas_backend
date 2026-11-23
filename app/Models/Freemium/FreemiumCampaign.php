<?php

namespace App\Models\Freemium;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FreemiumCampaign extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'freemium_campaigns';

    protected $fillable = [
        'name',
        'description',
        'status',
        'is_stackable',
        'priority',
        'start_at',
        'end_at',
        'eligibility_criteria',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_stackable'         => 'boolean',
        'priority'             => 'integer',
        'eligibility_criteria' => 'array',
    ];

    /**
     * Timestamp UTC normalization for start_at attribute.
     */
    protected function startAt(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value === null || $value === '') {
                    return null;
                }
                // Before B1.2 backfill, Campaign ID 2 stores naive Karachi time ('2026-11-21 14:49:00')
                if ($this->id === 2 && $value === '2026-11-21 14:49:00') {
                    return Carbon::parse($value, config('app.timezone', 'Asia/Karachi'))->setTimezone('UTC');
                }
                return Carbon::parse($value, 'UTC');
            },
            set: function ($value) {
                if ($value === null || $value === '') {
                    return null;
                }
                if ($value instanceof Carbon) {
                    return $value->copy()->setTimezone('UTC')->format('Y-m-d H:i:s');
                }
                $appTz = config('app.timezone', 'Asia/Karachi');
                $hasTimezone = is_string($value) && preg_match('/(Z|[+-]\d{2}(?::?\d{2})?)$/i', trim($value));
                if ($hasTimezone) {
                    return Carbon::parse($value)->setTimezone('UTC')->format('Y-m-d H:i:s');
                }
                return Carbon::parse($value, $appTz)->setTimezone('UTC')->format('Y-m-d H:i:s');
            }
        );
    }

    /**
     * Timestamp UTC normalization for end_at attribute.
     */
    protected function endAt(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value === null || $value === '') {
                    return null;
                }
                // Before B1.2 backfill, Campaign ID 2 stores naive Karachi time ('2027-12-31 14:49:00')
                if ($this->id === 2 && $value === '2027-12-31 14:49:00') {
                    return Carbon::parse($value, config('app.timezone', 'Asia/Karachi'))->setTimezone('UTC');
                }
                return Carbon::parse($value, 'UTC');
            },
            set: function ($value) {
                if ($value === null || $value === '') {
                    return null;
                }
                if ($value instanceof Carbon) {
                    return $value->copy()->setTimezone('UTC')->format('Y-m-d H:i:s');
                }
                $appTz = config('app.timezone', 'Asia/Karachi');
                $hasTimezone = is_string($value) && preg_match('/(Z|[+-]\d{2}(?::?\d{2})?)$/i', trim($value));
                if ($hasTimezone) {
                    return Carbon::parse($value)->setTimezone('UTC')->format('Y-m-d H:i:s');
                }
                return Carbon::parse($value, $appTz)->setTimezone('UTC')->format('Y-m-d H:i:s');
            }
        );
    }

    // ==========================================
    // Relationships
    // ==========================================

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function accessRules(): HasMany
    {
        return $this->hasMany(FreemiumAccessRule::class, 'campaign_id');
    }

    public function examQuotas(): HasMany
    {
        return $this->hasMany(FreemiumExamQuota::class, 'campaign_id');
    }

    public function studentGrants(): HasMany
    {
        return $this->hasMany(FreemiumStudentGrant::class, 'campaign_id');
    }

    public function quotaConsumptions(): HasMany
    {
        return $this->hasMany(FreemiumQuotaConsumption::class, 'primary_campaign_id');
    }

    public function consumptionEnvelopes(): HasMany
    {
        return $this->hasMany(FreemiumQuotaConsumptionEnvelope::class, 'campaign_id');
    }

    // ==========================================
    // Query Scopes
    // ==========================================

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeCurrentlyRunning(Builder $query, $now = null): Builder
    {
        return $this->scopeActiveAndValid($query, $now);
    }

    public function scopeActiveAndValid(Builder $query, $now = null): Builder
    {
        if ($now instanceof Carbon) {
            $nowTs = $now->getTimestamp();
        } elseif (is_numeric($now)) {
            $nowTs = (int) $now;
        } elseif (is_string($now) && trim($now) !== '') {
            $hasTimezone = preg_match('/(Z|[+-]\d{2}(?::?\d{2})?)$/i', trim($now));
            $nowTs = $hasTimezone
                ? Carbon::parse($now)->getTimestamp()
                : Carbon::parse($now, config('app.timezone', 'Asia/Karachi'))->getTimestamp();
        } else {
            $nowTs = Carbon::now('UTC')->getTimestamp();
        }

        $isSqlite = $query->getConnection()->getDriverName() === 'sqlite';
        $fromUnix = $isSqlite ? "datetime(?, 'unixepoch')" : 'FROM_UNIXTIME(?)';

        return $query->where('status', 'active')
            ->whereRaw("start_at <= {$fromUnix}", [$nowTs])
            ->where(function ($sub) use ($nowTs, $fromUnix) {
                $sub->whereNull('end_at')
                    ->orWhereRaw("end_at > {$fromUnix}", [$nowTs]);
            });
    }
}
