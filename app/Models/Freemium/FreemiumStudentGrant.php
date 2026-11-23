<?php

namespace App\Models\Freemium;

use App\Models\User;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FreemiumStudentGrant extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'freemium_student_grants';

    protected $fillable = [
        'campaign_id',
        'user_id',
        'grant_type',
        'voucher_code',
        'granted_at',
        'expires_at',
        'status',
        'created_by',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    // ==========================================
    // Relationships
    // ==========================================

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(FreemiumCampaign::class, 'campaign_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(FreemiumQuotaConsumption::class, 'primary_grant_id');
    }

    public function consumptionEnvelopes(): HasMany
    {
        return $this->hasMany(FreemiumQuotaConsumptionEnvelope::class, 'grant_id');
    }

    // ==========================================
    // Query Scopes
    // ==========================================

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeValidAt(Builder $query, $time = null): Builder
    {
        if ($time instanceof Carbon) {
            $timeTs = $time->getTimestamp();
        } elseif (is_numeric($time)) {
            $timeTs = (int) $time;
        } elseif (is_string($time) && trim($time) !== '') {
            $hasTimezone = preg_match('/(Z|[+-]\d{2}(?::?\d{2})?)$/i', trim($time));
            $timeTs = $hasTimezone
                ? Carbon::parse($time)->getTimestamp()
                : Carbon::parse($time, config('app.timezone', 'Asia/Karachi'))->getTimestamp();
        } else {
            $timeTs = Carbon::now('UTC')->getTimestamp();
        }

        $isSqlite = $query->getConnection()->getDriverName() === 'sqlite';
        $fromUnix = $isSqlite ? "datetime(?, 'unixepoch')" : 'FROM_UNIXTIME(?)';

        return $query->where('status', 'active')
            ->whereRaw("granted_at <= {$fromUnix}", [$timeTs])
            ->where(function ($q) use ($timeTs, $fromUnix) {
                $q->whereNull('expires_at')
                  ->orWhereRaw("expires_at > {$fromUnix}", [$timeTs]);
            });
    }
}
