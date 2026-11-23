<?php

namespace App\Http\Services\Freemium;

use App\Http\Services\Freemium\Exceptions\CampaignDeletionRestrictedException;
use App\Http\Services\Freemium\Exceptions\InvalidCampaignTransitionException;
use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumExamQuota;
use App\Models\Freemium\FreemiumQuotaConsumption;
use App\Models\Freemium\FreemiumQuotaConsumptionEnvelope;
use App\Models\Freemium\FreemiumStudentGrant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FreemiumCampaignLifecycleService
{
    /**
     * Allowed status transitions graph.
     */
    protected const ALLOWED_TRANSITIONS = [
        'draft'    => ['active', 'archived'],
        'active'   => ['paused', 'archived'],
        'paused'   => ['active', 'archived'],
        'archived' => [], // Terminal status
    ];

    public function __construct(
        protected FreemiumAuditService $auditService
    ) {
    }

    /**
     * Create a new Freemium campaign.
     *
     * @param array $data Validated campaign parameters
     * @param User|null $actor Admin actor
     * @param Carbon|null $now Time authority
     */
    public function createCampaign(
        array $data,
        ?User $actor = null,
        ?Carbon $now = null
    ): FreemiumCampaign {
        $now = $now ? $now->copy()->setTimezone('UTC') : Carbon::now('UTC');

        // Normalize incoming timestamps to UTC
        $startAt = $this->normalizeToUtc($data['start_at'] ?? null);
        $endAt = array_key_exists('end_at', $data) ? $this->normalizeToUtc($data['end_at']) : null;

        if ($startAt === null) {
            throw new InvalidArgumentException("start_at is required.");
        }

        // Validate date interval: start_at < end_at (when end_at is provided)
        if ($endAt !== null && $startAt->timestamp >= $endAt->timestamp) {
            throw new InvalidArgumentException(
                "Invalid campaign date interval: start_at ({$startAt->toIso8601String()}) must be strictly earlier than end_at ({$endAt->toIso8601String()})."
            );
        }

        $status = $data['status'] ?? 'draft';
        if (!in_array($status, ['draft', 'active', 'paused', 'archived'], true)) {
            throw new InvalidArgumentException("Invalid campaign status: {$status}");
        }

        return DB::transaction(function () use ($data, $startAt, $endAt, $status, $actor, $now) {
            $campaign = FreemiumCampaign::create([
                'name'                 => $data['name'],
                'description'          => $data['description'] ?? null,
                'status'               => $status,
                'is_stackable'         => (bool) ($data['is_stackable'] ?? false),
                'priority'             => (int) ($data['priority'] ?? 100),
                'start_at'             => $startAt,
                'end_at'               => $endAt,
                'eligibility_criteria' => $data['eligibility_criteria'] ?? null,
                'created_by'           => $actor?->id,
                'updated_by'           => $actor?->id,
            ]);

            $this->auditService->log(
                $actor,
                'campaign.created',
                'freemium_campaign',
                $campaign->id,
                null,
                $campaign->toArray(),
                null,
                null,
                $now
            );

            return $campaign;
        });
    }

    /**
     * Update an existing campaign with transition and interval validation.
     */
    public function updateCampaign(
        FreemiumCampaign $campaign,
        array $data,
        ?User $actor = null,
        ?Carbon $now = null
    ): FreemiumCampaign {
        $now = $now ? $now->copy()->setTimezone('UTC') : Carbon::now('UTC');

        // Check date interval if either boundary is being updated
        $newStartAt = array_key_exists('start_at', $data)
            ? $this->normalizeToUtc($data['start_at'])
            : $campaign->start_at;

        $newEndAt = array_key_exists('end_at', $data)
            ? $this->normalizeToUtc($data['end_at'])
            : $campaign->end_at;

        if ($newStartAt !== null && $newEndAt !== null && $newStartAt->timestamp >= $newEndAt->timestamp) {
            throw new InvalidArgumentException(
                "Invalid campaign date interval: start_at ({$newStartAt->toIso8601String()}) must be strictly earlier than end_at ({$newEndAt->toIso8601String()})."
            );
        }

        // Validate status transition
        if (isset($data['status']) && $data['status'] !== $campaign->status) {
            $currentStatus = $campaign->status;
            $newStatus = $data['status'];

            $allowed = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];
            if (!in_array($newStatus, $allowed, true)) {
                throw new InvalidCampaignTransitionException(
                    "Invalid campaign status transition from '{$currentStatus}' to '{$newStatus}'."
                );
            }
        }

        return DB::transaction(function () use ($campaign, $data, $newStartAt, $newEndAt, $actor, $now) {
            $oldValues = $campaign->only(array_keys($data));

            $updatePayload = $data;
            if (array_key_exists('start_at', $data)) {
                $updatePayload['start_at'] = $newStartAt;
            } else {
                unset($updatePayload['start_at']);
            }
            if (array_key_exists('end_at', $data)) {
                $updatePayload['end_at'] = $newEndAt;
            } else {
                unset($updatePayload['end_at']);
            }
            if ($actor) {
                $updatePayload['updated_by'] = $actor->id;
            }

            $campaign->update($updatePayload);

            $this->auditService->log(
                $actor,
                'campaign.updated',
                'freemium_campaign',
                $campaign->id,
                $oldValues,
                $campaign->only(array_keys($data)),
                null,
                null,
                $now
            );

            return $campaign->fresh();
        });
    }

    /**
     * Safely delete a campaign or reject deletion if historical records exist.
     *
     * @throws CampaignDeletionRestrictedException
     */
    public function deleteCampaign(FreemiumCampaign $campaign, ?User $actor = null): bool
    {
        // 1. Check for historical quota consumptions referencing this campaign
        $hasConsumptions = FreemiumQuotaConsumption::where('primary_campaign_id', $campaign->id)->exists();
        if ($hasConsumptions) {
            throw new CampaignDeletionRestrictedException(
                "Campaign (ID: {$campaign->id}) cannot be deleted: historical quota consumption records exist. Campaign must be archived instead."
            );
        }

        // 2. Check for consumption envelopes referencing this campaign
        $hasEnvelopes = FreemiumQuotaConsumptionEnvelope::where('campaign_id', $campaign->id)->exists();
        if ($hasEnvelopes) {
            throw new CampaignDeletionRestrictedException(
                "Campaign (ID: {$campaign->id}) cannot be deleted: historical consumption envelopes exist. Campaign must be archived instead."
            );
        }

        // 3. Check for student grants issued under this campaign
        $hasGrants = FreemiumStudentGrant::where('campaign_id', $campaign->id)->exists();
        if ($hasGrants) {
            throw new CampaignDeletionRestrictedException(
                "Campaign (ID: {$campaign->id}) cannot be deleted: student grants exist for this campaign. Campaign must be archived instead."
            );
        }

        // 4. Safe deletion: purge child rules & quotas, then delete campaign
        return DB::transaction(function () use ($campaign, $actor) {
            $snapshot = $campaign->toArray();

            FreemiumAccessRule::where('campaign_id', $campaign->id)->delete();
            FreemiumExamQuota::where('campaign_id', $campaign->id)->forceDelete();
            $campaign->forceDelete();

            $this->auditService->log(
                $actor,
                'campaign.deleted',
                'freemium_campaign',
                $campaign->id,
                $snapshot,
                null
            );

            return true;
        });
    }

    /**
     * Check if a campaign is currently valid under half-open interval [start_at, end_at).
     */
    public function isCurrentlyActive(FreemiumCampaign $campaign, ?Carbon $now = null): bool
    {
        $nowTs = $now ? $now->copy()->setTimezone('UTC')->timestamp : Carbon::now('UTC')->timestamp;

        return $campaign->status === 'active'
            && $campaign->start_at !== null
            && $campaign->start_at->timestamp <= $nowTs
            && ($campaign->end_at === null || $nowTs < $campaign->end_at->timestamp);
    }

    /**
     * Normalize incoming timestamp to UTC Carbon instance.
     *
     * 1. If null or empty string, returns null.
     * 2. If already Carbon instance, clones and converts to UTC.
     * 3. If string with explicit timezone indicator (Z or +/-HH:mm), parses directly and converts to UTC.
     * 4. If string is timezone-naive, interprets explicitly using config('app.timezone') and converts to UTC.
     */
    public function normalizeToUtc(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->copy()->setTimezone('UTC');
        }

        $appTz = config('app.timezone', 'Asia/Karachi');

        $hasTimezone = is_string($value) && preg_match('/(Z|[+-]\d{2}(?::?\d{2})?)$/i', trim($value));

        if ($hasTimezone) {
            return Carbon::parse($value)->setTimezone('UTC');
        }

        return Carbon::parse($value, $appTz)->setTimezone('UTC');
    }
}
