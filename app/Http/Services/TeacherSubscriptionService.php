<?php

namespace App\Http\Services;

use App\Models\TeacherProfile;
use App\Models\TeacherSubscription;
use App\Models\TeacherSubscriptionHistory;
use App\Models\TeacherSubscriptionPlan;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TeacherSubscriptionService
{
    public function getCurrentSubscription(int $teacherProfileId): ?TeacherSubscription
    {
        $profile = TeacherProfile::with('webUser:id,role,status')->find($teacherProfileId);
        if (!$profile || $profile->status !== 'active' || $profile->webUser?->role !== 'teacher' || $profile->webUser?->status !== 'active') {
            return null;
        }

        $now = now();
        return TeacherSubscription::with(['plan.features'])
            ->where('teacher_profile_id', $teacherProfileId)
            ->where('status', 'active')
            ->where('starts_at', '<=', $now)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->first();
    }

    public function hasActiveSubscription(int $teacherProfileId): bool
    {
        return $this->getCurrentSubscription($teacherProfileId) !== null;
    }

    public function canUseFeature(int $teacherProfileId, string $featureKey): bool
    {
        if (!in_array($featureKey, TeacherSubscriptionPlan::FEATURE_KEYS, true)) return false;
        $subscription = $this->getCurrentSubscription($teacherProfileId);
        return (bool) $subscription?->plan?->features->firstWhere('feature_key', $featureKey)?->is_enabled;
    }

    public function requireFeature(int $teacherProfileId, string $featureKey): TeacherSubscription
    {
        if (!in_array($featureKey, TeacherSubscriptionPlan::FEATURE_KEYS, true)) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'code' => 'teacher_feature_unknown',
                'message' => 'This teacher feature is not configured.',
            ], 403));
        }
        $subscription = $this->getCurrentSubscription($teacherProfileId);
        if (!$subscription) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'code' => 'teacher_subscription_inactive',
                'message' => 'An active teacher subscription is required to use this feature.',
            ], 403));
        }
        if (!$subscription->plan?->features->firstWhere('feature_key', $featureKey)?->is_enabled) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'code' => 'teacher_feature_unavailable',
                'message' => 'This feature is not available on your current plan.',
                'data' => ['feature' => $featureKey],
            ], 403));
        }
        return $subscription;
    }

    public function getMaxActiveClasses(int $teacherProfileId): ?int
    {
        $subscription = $this->getCurrentSubscription($teacherProfileId);
        return $subscription ? $subscription->plan?->max_active_classes : 0;
    }

    public function getRemainingClassSlots(int $teacherProfileId): ?int
    {
        $subscription = $this->getCurrentSubscription($teacherProfileId);
        if (!$subscription) return 0;
        $limit = $subscription->plan?->max_active_classes;
        if ($limit === null) return null;
        $active = DB::table('teacher_classes')->where('teacher_profile_id', $teacherProfileId)->where('is_active', true)->count();
        return max(0, $limit - $active);
    }

    public function classUsage(int $teacherProfileId): array
    {
        $subscription = $this->getCurrentSubscription($teacherProfileId);
        $active = DB::table('teacher_classes')->where('teacher_profile_id', $teacherProfileId)->where('is_active', true)->count();
        $limit = $subscription?->plan?->max_active_classes;
        return [
            'current_active_classes' => $active,
            'max_active_classes' => $subscription ? $limit : 0,
            'remaining_classes' => $subscription && $limit === null ? null : max(0, (int) $limit - $active),
        ];
    }

    /** Must be called while the teacher profile row is locked in the same transaction as class creation/reactivation. */
    public function assertCanCreateClass(TeacherProfile $lockedProfile): void
    {
        $subscription = $this->requireFeature($lockedProfile->id, 'classes.manage');
        $limit = $subscription->plan?->max_active_classes;
        $active = DB::table('teacher_classes')->where('teacher_profile_id', $lockedProfile->id)->where('is_active', true)->count();
        if ($limit !== null && $active >= $limit) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'code' => 'teacher_class_limit_reached',
                'message' => 'You have reached the maximum number of active classes allowed by your current plan.',
                'data' => [
                    'current_active_classes' => $active,
                    'max_active_classes' => $limit,
                    'remaining_classes' => 0,
                ],
            ], 403));
        }
    }

    public function assignDefaultFree(TeacherProfile|int $teacher, ?int $adminId = null, string $eventType = 'free_plan_granted', ?string $reason = null): TeacherSubscription
    {
        $profileId = $teacher instanceof TeacherProfile ? $teacher->id : $teacher;
        return DB::transaction(function () use ($profileId, $adminId, $eventType, $reason) {
            $profile = TeacherProfile::lockForUpdate()->findOrFail($profileId);
            $active = TeacherSubscription::where('teacher_profile_id', $profileId)->where('status', 'active')->lockForUpdate()->first();
            if ($active && (!$active->expires_at || $active->expires_at->isFuture() || $active->starts_at->isFuture())) {
                return $active;
            }
            if ($active) $this->transition($active, 'expired', 'expired', $adminId, 'Expired before default plan assignment.');

            $defaults = TeacherSubscriptionPlan::where('is_active', true)->where('is_default', true)->lockForUpdate()->get();
            if ($defaults->count() !== 1) {
                throw new RuntimeException('Exactly one active default teacher subscription plan must exist before teacher approval.');
            }
            return $this->assignPlan($profileId, $defaults->first()->id, [
                'assignment_type' => 'automatic_free',
                'assigned_by' => $adminId,
                'event_type' => $eventType,
                'reason' => $reason ?? 'Automatically assigned when teacher approval was granted.',
                'replace_existing' => false,
            ]);
        });
    }

    public function assignPlan(int $teacherProfileId, int $planId, array $options = []): TeacherSubscription
    {
        return DB::transaction(function () use ($teacherProfileId, $planId, $options) {
            $profile = TeacherProfile::with('webUser')->lockForUpdate()->findOrFail($teacherProfileId);
            if (($options['assignment_type'] ?? 'manual_admin') === 'manual_admin'
                && ($profile->status !== 'active' || $profile->webUser?->role !== 'teacher' || $profile->webUser?->status !== 'active')) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'code' => 'teacher_not_eligible',
                    'message' => 'A manual plan assignment requires an active, approved teacher account.',
                ], 422));
            }
            $plan = TeacherSubscriptionPlan::where('is_active', true)->lockForUpdate()->findOrFail($planId);
            $now = CarbonImmutable::now();
            $startsAt = isset($options['starts_at']) ? CarbonImmutable::parse($options['starts_at']) : $now;
            $expiresAt = array_key_exists('expires_at', $options)
                ? ($options['expires_at'] ? CarbonImmutable::parse($options['expires_at']) : null)
                : $this->expiryFor($plan, $startsAt);
            if ($expiresAt && $expiresAt->lessThanOrEqualTo($startsAt)) {
                throw ValidationException::withMessages(['expires_at' => 'Expiry must be after the subscription start.']);
            }

            $existing = TeacherSubscription::where('teacher_profile_id', $teacherProfileId)->where('status', 'active')->lockForUpdate()->first();
            $previousPlanId = null;
            $previousExpiry = null;
            $replacementEvent = null;
            if ($existing) {
                if (($options['idempotent'] ?? false) && $existing->teacher_subscription_plan_id === $plan->id) return $existing;
                if (!($options['replace_existing'] ?? true)) {
                    throw new RuntimeException('The teacher already has an active subscription.');
                }
                $previousPlanId = $existing->teacher_subscription_plan_id;
                $previousExpiry = $existing->expires_at;
                $previousPrice = (float) $existing->plan?->price;
                $replacementEvent = $plan->price > $previousPrice ? 'subscription_upgraded'
                    : ($plan->price < $previousPrice ? 'subscription_downgraded' : 'subscription_replaced');
                $this->transition($existing, 'cancelled', 'subscription_replaced', $options['assigned_by'] ?? null,
                    $options['reason'] ?? 'Replaced by a new plan assignment.', ['replacement_plan_id' => $plan->id], $plan->id);
            }

            $subscription = TeacherSubscription::create([
                'teacher_profile_id' => $teacherProfileId,
                'teacher_subscription_plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'assignment_type' => $options['assignment_type'] ?? 'manual_admin',
                'assigned_by' => $options['assigned_by'] ?? null,
                'admin_note' => $options['admin_note'] ?? $options['reason'] ?? null,
            ]);
            $eventType = $previousPlanId
                ? ($options['replacement_event_type'] ?? $replacementEvent ?? 'subscription_replaced')
                : ($options['event_type'] ?? 'subscription_assigned');
            $this->history($subscription, $eventType, $previousPlanId, $plan->id,
                $previousPlanId ? 'active' : null, 'active', $previousExpiry, $subscription->expires_at,
                $options['assigned_by'] ?? null, $options['reason'] ?? null,
                array_merge($options['metadata'] ?? [], $previousPlanId ? ['replaced_plan_id' => $previousPlanId] : []));
            return $subscription->load('plan.features');
        });
    }

    public function changePlan(int $teacherProfileId, int $planId, array $options = []): TeacherSubscription
    {
        $options['replace_existing'] = true;
        $options['event_type'] = $options['event_type'] ?? 'subscription_upgraded';
        $options['assignment_type'] = $options['assignment_type'] ?? 'manual_admin';
        return $this->assignPlan($teacherProfileId, $planId, $options);
    }

    public function suspend(int $subscriptionId, ?int $adminId = null, ?string $reason = null): TeacherSubscription
    {
        return $this->setStatus($subscriptionId, 'suspended', 'subscription_suspended', $adminId, $reason);
    }

    public function cancel(int $subscriptionId, ?int $adminId = null, ?string $reason = null): TeacherSubscription
    {
        return $this->setStatus($subscriptionId, 'cancelled', 'subscription_cancelled', $adminId, $reason);
    }

    public function extend(int $subscriptionId, int $additionalDays, ?int $adminId = null, ?string $reason = null): TeacherSubscription
    {
        if ($additionalDays < 1) throw ValidationException::withMessages(['additional_days' => 'Extension must be at least one day.']);

        $result = DB::transaction(function () use ($subscriptionId, $additionalDays, $adminId, $reason) {
            $snapshot = TeacherSubscription::findOrFail($subscriptionId);
            TeacherProfile::lockForUpdate()->findOrFail($snapshot->teacher_profile_id);
            $subscription = TeacherSubscription::lockForUpdate()->findOrFail($subscriptionId);
            if ($subscription->status !== 'active') throw new RuntimeException('Only an active subscription can be extended.');
            if ($subscription->starts_at->isFuture()) throw new RuntimeException('A scheduled subscription cannot be extended before it starts.');
            if (!$subscription->expires_at) throw new RuntimeException('Lifetime subscriptions cannot be extended.');
            if ($subscription->expires_at->lessThanOrEqualTo(now())) {
                $this->transition($subscription, 'expired', 'subscription_expired', $adminId, 'Expired before extension.');
                return null;
            }

            $oldExpiry = $subscription->expires_at;
            $newExpiry = CarbonImmutable::instance($oldExpiry)->addDays($additionalDays);
            $subscription->expires_at = $newExpiry;
            $subscription->save();
            $this->history($subscription, 'subscription_extended', $subscription->teacher_subscription_plan_id,
                $subscription->teacher_subscription_plan_id, 'active', 'active', $oldExpiry, $newExpiry,
                $adminId, $reason, ['additional_days' => $additionalDays]);
            return $subscription->fresh(['plan.features']);
        });

        if (!$result) throw new RuntimeException('An expired subscription cannot be extended; reassign a plan instead.');
        return $result;
    }

    public function reactivate(int $subscriptionId, ?int $adminId = null, ?string $reason = null): TeacherSubscription
    {
        $result = DB::transaction(function () use ($subscriptionId, $adminId, $reason) {
            $snapshot = TeacherSubscription::findOrFail($subscriptionId);
            $profile = TeacherProfile::with('webUser')->lockForUpdate()->findOrFail($snapshot->teacher_profile_id);
            if ($profile->status !== 'active' || $profile->webUser?->role !== 'teacher' || $profile->webUser?->status !== 'active') {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'code' => 'teacher_not_eligible',
                    'message' => 'Subscription reactivation requires an active, approved teacher account.',
                ], 422));
            }
            $subscription = TeacherSubscription::lockForUpdate()->findOrFail($subscriptionId);
            if ($subscription->status !== 'suspended') throw new RuntimeException('Only a suspended subscription can be reactivated.');
            if ($subscription->expires_at && $subscription->expires_at->lessThanOrEqualTo(now())) {
                $this->transition($subscription, 'expired', 'subscription_expired', $adminId, $reason ?? 'Expired before reactivation.');
                return null;
            }
            $this->transition($subscription, 'active', 'subscription_reactivated', $adminId, $reason);
            return $subscription->fresh(['plan.features']);
        });
        if (!$result) throw new RuntimeException('An expired subscription cannot be reactivated.');
        return $result;
    }

    public function expireDueSubscriptions(int $chunkSize = 100): int
    {
        $expired = 0;
        TeacherSubscription::where('status', 'active')->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->orderBy('id')->chunkById($chunkSize, function ($rows) use (&$expired) {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($row, &$expired) {
                        $subscription = TeacherSubscription::lockForUpdate()->find($row->id);
                        if (!$subscription || $subscription->status !== 'active' || !$subscription->expires_at || $subscription->expires_at->isFuture()) return;
                        $this->transition($subscription, 'expired', 'subscription_expired', null, 'Expired at configured expiry time.');
                        $expired++;
                    });
                }
            });
        return $expired;
    }

    private function setStatus(int $subscriptionId, string $status, string $event, ?int $adminId, ?string $reason): TeacherSubscription
    {
        $result = DB::transaction(function () use ($subscriptionId, $status, $event, $adminId, $reason) {
            $snapshot = TeacherSubscription::findOrFail($subscriptionId);
            TeacherProfile::lockForUpdate()->findOrFail($snapshot->teacher_profile_id);
            $subscription = TeacherSubscription::lockForUpdate()->findOrFail($subscriptionId);
            if ($subscription->status !== 'active') throw new RuntimeException('Only an active subscription can change to this status.');
            if ($subscription->expires_at && $subscription->expires_at->lessThanOrEqualTo(now())) {
                $this->transition($subscription, 'expired', 'subscription_expired', $adminId, 'Expired before lifecycle change.');
                return null;
            }
            $this->transition($subscription, $status, $event, $adminId, $reason);
            return $subscription->fresh(['plan.features']);
        });
        if (!$result) throw new RuntimeException('An expired subscription cannot be changed; assign a new plan instead.');
        return $result;
    }

    private function transition(TeacherSubscription $subscription, string $newStatus, string $event, ?int $adminId, ?string $reason, array $metadata = [], ?int $newPlanId = null): void
    {
        $oldStatus = $subscription->status;
        $planId = $subscription->teacher_subscription_plan_id;
        $expiry = $subscription->expires_at;
        $subscription->status = $newStatus;
        $subscription->save();
        $this->history($subscription, $event, $planId, $newPlanId ?? $planId, $oldStatus, $newStatus, $expiry, $subscription->expires_at, $adminId, $reason, $metadata ?: null);
    }

    private function history(TeacherSubscription $subscription, string $event, ?int $oldPlan, ?int $newPlan, ?string $oldStatus, ?string $newStatus, $oldExpiry, $newExpiry, ?int $adminId, ?string $reason, ?array $metadata): void
    {
        TeacherSubscriptionHistory::create([
            'teacher_subscription_id' => $subscription->id,
            'teacher_profile_id' => $subscription->teacher_profile_id,
            'previous_plan_id' => $oldPlan,
            'new_plan_id' => $newPlan,
            'event_type' => $event,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'previous_expiry' => $oldExpiry,
            'new_expiry' => $newExpiry,
            'assigned_by' => $adminId,
            'reason' => $reason,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    private function expiryFor(TeacherSubscriptionPlan $plan, CarbonImmutable $startsAt): ?CarbonImmutable
    {
        return match ($plan->billing_interval) {
            'monthly' => $startsAt->addMonthNoOverflow(),
            'yearly' => $startsAt->addYearNoOverflow(),
            'custom' => $plan->duration_days ? $startsAt->addDays($plan->duration_days) : throw ValidationException::withMessages(['duration_days' => 'Custom plans require a duration.']),
            'lifetime' => null,
            default => throw ValidationException::withMessages(['billing_interval' => 'Unsupported plan duration.']),
        };
    }
}
