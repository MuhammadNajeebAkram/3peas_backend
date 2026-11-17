<?php

namespace Database\Seeders;

use App\Models\TeacherSubscriptionFeature;
use App\Models\TeacherSubscriptionPlan;
use Illuminate\Database\Seeder;

class TeacherSubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        TeacherSubscriptionPlan::withoutEvents(function () {
            $plan = TeacherSubscriptionPlan::firstOrCreate(['code' => 'free'], [
                'name' => 'Free Plan',
                'description' => 'Launch teacher portal plan.',
                'price' => 0,
                'currency' => 'PKR',
                'billing_interval' => 'lifetime',
                'duration_days' => null,
                'max_active_classes' => 3,
                'is_free' => true,
                'is_active' => true,
                'is_default' => true,
            ]);

            foreach (TeacherSubscriptionPlan::FEATURE_KEYS as $featureKey) {
                TeacherSubscriptionFeature::firstOrCreate([
                    'teacher_subscription_plan_id' => $plan->id,
                    'feature_key' => $featureKey,
                ], ['is_enabled' => true]);
            }
        });
    }
}
