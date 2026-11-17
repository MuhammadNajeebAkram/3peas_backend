<?php

namespace App\Http\Services;

use App\Models\TeacherSubscriptionFeature;
use App\Models\TeacherSubscriptionPlan;
use App\Models\TeacherSubscriptionPlanHistory;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TeacherSubscriptionPlanAdminService
{
    public function create(array $data, int $adminId): TeacherSubscriptionPlan
    {
        try {
            return DB::transaction(function () use ($data, $adminId) {
                $features = $data['features'] ?? [];
                $planData = $this->planFields($data);
                if (($planData['billing_interval'] ?? null) !== 'custom') $planData['duration_days'] = null;

                $plan = new TeacherSubscriptionPlan($planData);
                $plan->forceFill(['created_by' => $adminId, 'updated_by' => $adminId])->save();
                $this->writeFeatures($plan, $features, true);
                $plan->load('features');
                $this->record($plan, 'plan_created', null, $this->snapshot($plan), $data['reason'] ?? null, $adminId);
                return $plan;
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception) && isset($data['code'])) {
                throw ValidationException::withMessages(['code' => 'This teacher subscription plan code is already in use.']);
            }
            throw $exception;
        }
    }

    public function update(int $planId, array $data, int $adminId): TeacherSubscriptionPlan
    {
        return DB::transaction(function () use ($planId, $data, $adminId) {
            $plan = TeacherSubscriptionPlan::query()->lockForUpdate()->findOrFail($planId);
            $plan->load('features');
            $before = $this->snapshot($plan);
            $fields = $this->planFields($data);
            if (array_key_exists('billing_interval', $fields) && $fields['billing_interval'] !== 'custom') {
                $fields['duration_days'] = null;
            }
            $plan->forceFill($fields + ['updated_by' => $adminId])->save();

            if (array_key_exists('features', $data)) $this->writeFeatures($plan, $data['features'], false);
            $plan->load('features');
            $this->record($plan, 'plan_updated', $before, $this->snapshot($plan), $data['reason'] ?? null, $adminId);
            return $plan;
        });
    }

    public function setStatus(int $planId, bool $active, string $reason, int $adminId): TeacherSubscriptionPlan
    {
        return DB::transaction(function () use ($planId, $active, $reason, $adminId) {
            $plan = TeacherSubscriptionPlan::query()->lockForUpdate()->findOrFail($planId);
            if ((bool) $plan->is_active === $active) return $plan->load('features');
            if (!$active && $plan->is_default) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'code' => 'default_plan_must_be_replaced',
                    'message' => 'Select another active default plan before deactivating this plan.',
                ], 409));
            }
            $before = $this->snapshot($plan->load('features'));
            $plan->forceFill(['is_active' => $active, 'updated_by' => $adminId])->save();
            $plan->load('features');
            $this->record($plan, $active ? 'plan_activated' : 'plan_deactivated', $before, $this->snapshot($plan), $reason, $adminId);
            return $plan;
        });
    }

    public function setDefault(int $planId, string $reason, int $adminId): TeacherSubscriptionPlan
    {
        try {
            return DB::transaction(function () use ($planId, $reason, $adminId) {
                $target = TeacherSubscriptionPlan::query()->lockForUpdate()->findOrFail($planId);
                if (!$target->is_active) {
                    throw new HttpResponseException(response()->json([
                        'success' => false,
                        'code' => 'inactive_plan_cannot_be_default',
                        'message' => 'Only an active plan can be selected as the default.',
                    ], 409));
                }

                $defaults = TeacherSubscriptionPlan::query()->where('is_default', true)->where('is_active', true)
                    ->lockForUpdate()->orderBy('id')->get();
                if ($defaults->count() === 1 && $defaults->first()->id === $target->id) return $target->load('features');

                foreach ($defaults as $oldDefault) {
                    if ($oldDefault->id === $target->id) continue;
                    $oldDefault->load('features');
                    $before = $this->snapshot($oldDefault);
                    $oldDefault->forceFill(['is_default' => false, 'updated_by' => $adminId])->save();
                    $oldDefault->load('features');
                    $this->record($oldDefault, 'default_plan_replaced', $before, $this->snapshot($oldDefault), $reason, $adminId);
                }

                $target->load('features');
                $before = $this->snapshot($target);
                $target->forceFill(['is_default' => true, 'updated_by' => $adminId])->save();
                $target->load('features');
                $this->record($target, 'default_plan_set', $before, $this->snapshot($target), $reason, $adminId);
                return $target;
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'code' => 'default_plan_conflict',
                    'message' => 'Another default plan was selected concurrently. Retry the request.',
                ], 409));
            }
            throw $exception;
        }
    }

    private function writeFeatures(TeacherSubscriptionPlan $plan, array $input, bool $creating): void
    {
        foreach (TeacherSubscriptionPlan::FEATURE_KEYS as $featureKey) {
            $current = $plan->features->firstWhere('feature_key', $featureKey);
            $enabled = array_key_exists($featureKey, $input)
                ? filter_var($input[$featureKey], FILTER_VALIDATE_BOOLEAN)
                : ($creating ? false : (bool) $current?->is_enabled);

            TeacherSubscriptionFeature::query()->updateOrCreate(
                ['teacher_subscription_plan_id' => $plan->id, 'feature_key' => $featureKey],
                ['is_enabled' => $enabled]
            );
        }
    }

    private function planFields(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'code', 'name', 'description', 'price', 'currency', 'billing_interval', 'duration_days',
            'max_active_classes', 'is_free', 'is_active',
        ]));
    }

    private function snapshot(TeacherSubscriptionPlan $plan): array
    {
        $features = $plan->features->keyBy('feature_key');
        $featureMap = [];
        foreach (TeacherSubscriptionPlan::FEATURE_KEYS as $key) $featureMap[$key] = (bool) ($features->get($key)?->is_enabled ?? false);

        return [
            'id' => $plan->id,
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->description,
            'price' => $plan->price,
            'currency' => $plan->currency,
            'billing_interval' => $plan->billing_interval,
            'duration_days' => $plan->duration_days,
            'max_active_classes' => $plan->max_active_classes,
            'is_free' => (bool) $plan->is_free,
            'is_active' => (bool) $plan->is_active,
            'is_default' => (bool) $plan->is_default,
            'features' => $featureMap,
        ];
    }

    private function record(TeacherSubscriptionPlan $plan, string $event, ?array $before, ?array $after, ?string $reason, int $adminId): void
    {
        TeacherSubscriptionPlanHistory::create([
            'teacher_subscription_plan_id' => $plan->id,
            'admin_user_id' => $adminId,
            'event_type' => $event,
            'previous_values' => $before,
            'new_values' => $after,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return (string) ($exception->errorInfo[0] ?? '') === '23000';
    }
}
