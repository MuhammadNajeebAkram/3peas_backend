<?php

namespace Tests\Feature;

use App\Http\Services\TeacherSubscriptionService;
use App\Models\TeacherProfile;
use App\Models\TeacherSubscription;
use App\Models\TeacherSubscriptionFeature;
use App\Models\TeacherSubscriptionPlan;
use App\Models\User;
use App\Models\WebUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TeacherSubscriptionTest extends TeacherBackendTest
{
    public function test_free_plan_seed_is_idempotent_and_does_not_overwrite_admin_configuration(): void
    {
        $plan = TeacherSubscriptionPlan::where('code', 'free')->firstOrFail();
        $this->assertSame('Free Plan', $plan->name);
        $this->assertSame(3, $plan->max_active_classes);
        $this->assertCount(count(TeacherSubscriptionPlan::FEATURE_KEYS), $plan->features);
        $this->assertSame(1, TeacherSubscriptionPlan::where('is_default', true)->where('is_active', true)->count());

        $plan->update(['max_active_classes' => 7, 'name' => 'Configured Free']);
        $plan->features()->where('feature_key', 'tests.create')->update(['is_enabled' => false]);
        $this->seed(\Database\Seeders\TeacherSubscriptionPlanSeeder::class);
        $this->assertSame(1, TeacherSubscriptionPlan::where('code', 'free')->count());
        $this->assertSame(count(TeacherSubscriptionPlan::FEATURE_KEYS), TeacherSubscriptionFeature::where('teacher_subscription_plan_id', $plan->id)->count());
        $this->assertSame(7, $plan->fresh()->max_active_classes);
        $this->assertFalse($plan->fresh()->features()->where('feature_key', 'tests.create')->first()->is_enabled);
    }

    public function test_teacher_approval_grants_default_free_plan_and_reapproval_is_idempotent(): void
    {
        $teacher = TeacherProfile::where('web_user_id', 2)->firstOrFail();
        $teacher->forceFill(['status' => 'pending', 'approved_at' => null])->save();
        $this->actingAs(User::first(), 'api');
        $path = '/api/admin/auth/teachers/'.$teacher->id.'/status';
        $this->postJson($path, ['status' => 'active'])->assertOk();
        $subscription = TeacherSubscription::where('teacher_profile_id', $teacher->id)->firstOrFail();
        $this->assertSame('free', $subscription->plan->code);
        $this->assertSame('automatic_free', $subscription->assignment_type);
        $this->assertNull($subscription->expires_at);
        $this->assertDatabaseHas('teacher_subscription_history', [
            'teacher_subscription_id' => $subscription->id,
            'event_type' => 'free_plan_granted',
        ]);
        $this->postJson($path, ['status' => 'active'])->assertOk();
        $this->assertSame(1, TeacherSubscription::where('teacher_profile_id', $teacher->id)->count());
    }

    public function test_registration_does_not_assign_a_teacher_subscription(): void
    {
        $response = $this->postJson('/web_api/auth/teacher/register', [
            'name' => 'New Teacher', 'email' => 'subscription-new@example.test', 'phone' => '03009991111',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertOk();
        $profileId = TeacherProfile::where('web_user_id', $response->json('user.id'))->value('id');
        $this->assertDatabaseCount('teacher_subscriptions', 0);
        $this->assertDatabaseMissing('teacher_subscriptions', ['teacher_profile_id' => $profileId]);
    }

    public function test_backfill_is_repeatable_and_skips_profiles_with_existing_assignments(): void
    {
        $manual = app(TeacherSubscriptionService::class)->assignPlan(TeacherProfile::where('web_user_id', 2)->value('id'),
            TeacherSubscriptionPlan::where('code', 'free')->value('id'), [
                'assignment_type' => 'manual_admin', 'assigned_by' => User::first()->id, 'reason' => 'Manual assignment',
            ]);
        $this->assertSame('manual_admin', $manual->assignment_type);

        Artisan::call('teachers:subscriptions-backfill-free');
        $this->assertSame(2, TeacherSubscription::count());
        $this->assertSame(1, TeacherSubscription::where('teacher_profile_id', $manual->teacher_profile_id)->count());
        $this->assertDatabaseHas('teacher_subscription_history', ['event_type' => 'free_plan_backfilled']);
        $backfilled = TeacherSubscription::where('teacher_profile_id', '!=', $manual->teacher_profile_id)->firstOrFail();
        $this->assertSame('free', $backfilled->plan->code);
        $this->assertDatabaseHas('teacher_subscription_history', [
            'teacher_subscription_id' => $backfilled->id,
            'event_type' => 'free_plan_backfilled',
            'reason' => 'Backfilled for an existing approved teacher.',
        ]);

        Artisan::call('teachers:subscriptions-backfill-free');
        $this->assertSame(2, TeacherSubscription::count());
        $this->assertSame(1, $this->teacherSubscriptionHistoryCount('free_plan_backfilled'));
    }

    public function test_entitlement_lifecycle_expiry_and_teacher_account_suspension_preserve_records(): void
    {
        $service = app(TeacherSubscriptionService::class);
        $profile = TeacherProfile::where('web_user_id', 2)->firstOrFail();
        $plan = TeacherSubscriptionPlan::where('code', 'free')->firstOrFail();
        $subscription = $service->assignPlan($profile->id, $plan->id, [
            'assignment_type' => 'manual_admin', 'assigned_by' => User::first()->id,
        ]);
        $this->assertTrue($service->canUseFeature($profile->id, 'classes.manage'));

        $expiry = $subscription->expires_at;
        $historyBeforeTeacherSuspension = $this->teacherSubscriptionHistoryCountFor($subscription->id);
        $profile->forceFill(['status' => 'suspended'])->save();
        WebUser::whereKey($profile->web_user_id)->update(['status' => 'blocked']);
        $this->assertFalse($service->hasActiveSubscription($profile->id));
        $this->assertSame('active', $subscription->fresh()->status);
        $this->assertSame($historyBeforeTeacherSuspension, $this->teacherSubscriptionHistoryCountFor($subscription->id));
        $profile->forceFill(['status' => 'active'])->save();
        WebUser::whereKey($profile->web_user_id)->update(['status' => 'active']);
        $this->assertTrue($service->hasActiveSubscription($profile->id));
        $this->assertEquals($expiry, $subscription->fresh()->expires_at);

        $service->suspend($subscription->id, User::first()->id, 'Admin suspension');
        $this->assertFalse($service->hasActiveSubscription($profile->id));
        $this->assertFalse($service->canUseFeature($profile->id, 'classes.manage'));
        $reactivated = $service->reactivate($subscription->id, User::first()->id, 'Restored by admin');
        $this->assertSame('active', $reactivated->status);
        $this->assertEquals($expiry, $reactivated->expires_at);
        $service->cancel($subscription->id, User::first()->id, 'Cancelled');
        $this->assertFalse($service->hasActiveSubscription($profile->id));
    }

    public function test_expiry_command_marks_due_assignments_once(): void
    {
        $profileId = TeacherProfile::where('web_user_id', 2)->value('id');
        $plan = TeacherSubscriptionPlan::create([
            'code' => 'short-custom', 'name' => 'Short custom', 'price' => 0, 'currency' => 'PKR',
            'billing_interval' => 'custom', 'duration_days' => 1, 'max_active_classes' => 1,
            'is_free' => true, 'is_active' => true, 'is_default' => false,
        ]);
        $subscription = app(TeacherSubscriptionService::class)->assignPlan($profileId, $plan->id, [
            'starts_at' => now()->subDays(2), 'assignment_type' => 'manual_admin', 'assigned_by' => User::first()->id,
        ]);
        $this->assertSame(1, app(TeacherSubscriptionService::class)->expireDueSubscriptions());
        $this->assertSame('expired', $subscription->fresh()->status);
        $service = app(TeacherSubscriptionService::class);
        $this->assertFalse($service->hasActiveSubscription($profileId));
        $this->assertFalse($service->canUseFeature($profileId, 'classes.manage'));
        $this->assertSame(0, app(TeacherSubscriptionService::class)->expireDueSubscriptions());
        $this->assertSame(1, DB::table('teacher_subscription_history')->where('teacher_subscription_id', $subscription->id)->where('event_type', 'subscription_expired')->count());
    }

    public function test_subscription_read_apis_are_teacher_scoped_and_require_approval(): void
    {
        $profile = TeacherProfile::where('web_user_id', 2)->firstOrFail();
        app(TeacherSubscriptionService::class)->assignDefaultFree($profile);
        $this->actingAs(WebUser::find(2), 'web_api');
        $this->getJson('/web_api/auth/teacher/subscription')->assertOk()->assertJsonPath('subscription.plan.code', 'free');
        $features = $this->getJson('/web_api/auth/teacher/subscription/features')->assertOk()->json('features');
        $this->assertTrue($features['classes.manage']);
        $this->getJson('/web_api/auth/teacher/subscription/usage')->assertOk()->assertJsonPath('usage.max_active_classes', 3);

        $profile->forceFill(['status' => 'pending'])->save();
        $this->getJson('/web_api/auth/teacher/subscription')->assertForbidden();
    }

    private function teacherSubscriptionHistoryCount(string $event): int
    {
        return DB::table('teacher_subscription_history')->where('event_type', $event)->count();
    }

    private function teacherSubscriptionHistoryCountFor(int $subscriptionId): int
    {
        return DB::table('teacher_subscription_history')->where('teacher_subscription_id', $subscriptionId)->count();
    }
}
