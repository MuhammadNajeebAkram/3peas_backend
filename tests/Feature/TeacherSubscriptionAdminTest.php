<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\TeacherProfile;
use App\Models\TeacherSubscription;
use App\Models\TeacherSubscriptionHistory;
use App\Models\TeacherSubscriptionPlan;
use App\Models\User;
use App\Models\WebUser;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TeacherSubscriptionAdminTest extends TeacherBackendTest
{
    protected function setUp(): void
    {
        parent::setUp();
        auth()->shouldUse('api');
        $this->seed(\Database\Seeders\TeacherSubscriptionAdminPermissionsSeeder::class);
        Schema::create('admin_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_user_id');
            $table->string('action');
            $table->string('module');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    private function adminWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'subscription_manager']);
        $user = User::create(['name' => 'Subscription Admin', 'role_id' => $role->id]);
        foreach ($permissions as $name) {
            $permission = Permission::where('name', $name)->firstOrFail();
            DB::table('role_permissions')->insert(['role_id' => $role->id, 'permission_id' => $permission->id]);
        }
        return $user;
    }

    private function fullAdmin(): User
    {
        return $this->adminWithPermissions(\Database\Seeders\TeacherSubscriptionAdminPermissionsSeeder::PERMISSIONS);
    }

    private function planPayload(array $replace = []): array
    {
        return array_replace([
            'code' => 'pro-monthly', 'name' => 'Pro Monthly', 'description' => 'Admin managed plan',
            'price' => 1200, 'currency' => 'PKR', 'billing_interval' => 'monthly', 'duration_days' => null,
            'max_active_classes' => 10, 'is_free' => false, 'reason' => 'Configured for acceptance tests',
            'features' => array_fill_keys(TeacherSubscriptionPlan::FEATURE_KEYS, true),
        ], $replace);
    }

    private function createPlan(array $replace = []): TeacherSubscriptionPlan
    {
        if (!auth('api')->check()) $this->actingAs($this->fullAdmin(), 'api');
        return TeacherSubscriptionPlan::find($this->postJson('/api/admin/auth/teacher-subscription-plans', $this->planPayload($replace))
            ->assertCreated()->json('data.id'));
    }

    private function assignPlan(int $teacherProfileId, int $planId, string $note = 'Manual test assignment'): int
    {
        return (int) $this->postJson('/api/admin/auth/teacher-subscriptions/'.$teacherProfileId.'/assign', [
            'plan_id' => $planId, 'admin_note' => $note,
        ])->assertCreated()->json('data.id');
    }

    public function test_admin_subscription_permissions_are_separate_and_enforced(): void
    {
        $this->getJson('/api/admin/auth/teacher-subscription-plans')->assertUnauthorized();

        $viewOnly = $this->adminWithPermissions(['teacher-subscription-plans.view']);
        $this->actingAs($viewOnly, 'api');
        $this->getJson('/api/admin/auth/teacher-subscription-plans')->assertOk();
        $this->postJson('/api/admin/auth/teacher-subscription-plans', $this->planPayload())->assertForbidden();
        $this->getJson('/api/admin/auth/teacher-subscriptions')->assertForbidden();
        $this->getJson('/api/admin/auth/teacher-subscriptions/1/history')->assertForbidden();

        $manage = $this->adminWithPermissions(['teacher-subscriptions.manage']);
        $this->actingAs($manage, 'api');
        $this->getJson('/api/admin/auth/teacher-subscriptions')->assertForbidden();
        $noManage = $this->adminWithPermissions([]);
        $this->actingAs($noManage, 'api');
        $this->postJson('/api/admin/auth/teacher-subscriptions/1/assign', [])->assertForbidden();
    }

    public function test_plan_creation_validation_update_and_immutable_code(): void
    {
        $admin = $this->fullAdmin();
        $this->actingAs($admin, 'api');
        $payload = $this->planPayload();
        $id = $this->postJson('/api/admin/auth/teacher-subscription-plans', $payload)->assertCreated()->json('data.id');
        $this->assertDatabaseCount('teacher_subscription_features', 16);
        $this->assertDatabaseHas('teacher_subscription_plan_history', ['teacher_subscription_plan_id' => $id, 'event_type' => 'plan_created']);

        $this->postJson('/api/admin/auth/teacher-subscription-plans', $payload)->assertUnprocessable();
        $this->postJson('/api/admin/auth/teacher-subscription-plans', $this->planPayload(['code' => 'negative', 'price' => -1]))->assertUnprocessable();
        $this->postJson('/api/admin/auth/teacher-subscription-plans', $this->planPayload(['code' => 'bad-interval', 'billing_interval' => 'weekly']))->assertUnprocessable();
        $this->postJson('/api/admin/auth/teacher-subscription-plans', $this->planPayload(['code' => 'free-bad', 'is_free' => true]))->assertUnprocessable();
        $this->postJson('/api/admin/auth/teacher-subscription-plans', $this->planPayload(['code' => 'paid-zero', 'price' => 0]))->assertUnprocessable();
        $this->postJson('/api/admin/auth/teacher-subscription-plans', $this->planPayload(['code' => 'feature-bad', 'features' => ['not.a.feature' => true]]))->assertUnprocessable();

        $this->putJson('/api/admin/auth/teacher-subscription-plans/'.$id, [
            'features' => ['classes.manage' => false], 'max_active_classes' => 7, 'reason' => 'Adjusting launch limits',
        ])->assertOk()->assertJsonPath('data.max_active_classes', 7);
        $this->assertDatabaseHas('teacher_subscription_features', [
            'teacher_subscription_plan_id' => $id, 'feature_key' => 'classes.manage', 'is_enabled' => false,
        ]);
        $this->putJson('/api/admin/auth/teacher-subscription-plans/'.$id, ['code' => 'changed'])->assertUnprocessable();
        $this->getJson('/api/admin/auth/teacher-subscription-plans?search=pro&is_active=1&is_free=0&sort_by=name')->assertOk()
            ->assertJsonPath('pagination.total', 1);
    }

    public function test_default_plan_is_unique_and_default_cannot_be_deactivated(): void
    {
        $admin = $this->fullAdmin();
        $this->actingAs($admin, 'api');
        $default = TeacherSubscriptionPlan::where('is_default', true)->firstOrFail();
        $otherId = $this->postJson('/api/admin/auth/teacher-subscription-plans', $this->planPayload(['code' => 'another']))
            ->assertCreated()->json('data.id');
        $this->patchJson('/api/admin/auth/teacher-subscription-plans/'.$default->id.'/status', [
            'is_active' => false, 'admin_note' => 'Must replace default first',
        ])->assertStatus(409);
        $this->patchJson('/api/admin/auth/teacher-subscription-plans/'.$otherId.'/default', ['admin_note' => 'New default'])->assertOk();
        $this->assertSame(1, TeacherSubscriptionPlan::where('is_default', true)->where('is_active', true)->count());
        $this->assertDatabaseHas('teacher_subscription_plan_history', ['teacher_subscription_plan_id' => $default->id, 'event_type' => 'default_plan_replaced']);
    }

    public function test_assignment_replacement_history_and_deactivated_plan_entitlements(): void
    {
        $admin = $this->fullAdmin();
        $this->actingAs($admin, 'api');
        $teacher = TeacherProfile::where('web_user_id', 2)->firstOrFail();
        $firstPlan = $this->createPlan(['code' => 'entry', 'name' => 'Entry', 'price' => 500]);
        $subId = $this->assignPlan($teacher->id, $firstPlan->id);
        $this->assertDatabaseHas('teacher_subscriptions', ['id' => $subId, 'assignment_type' => 'manual_admin', 'assigned_by' => $admin->id]);
        $this->assertDatabaseHas('teacher_subscription_history', ['teacher_subscription_id' => $subId, 'new_plan_id' => $firstPlan->id, 'assigned_by' => $admin->id]);

        $nextPlan = $this->createPlan(['code' => 'premium', 'name' => 'Premium', 'price' => 3000]);
        $nextSubId = $this->assignPlan($teacher->id, $nextPlan->id, 'Upgrade');
        $this->assertDatabaseHas('teacher_subscriptions', ['id' => $subId, 'status' => 'cancelled']);
        $this->assertDatabaseHas('teacher_subscription_history', [
            'teacher_subscription_id' => $nextSubId, 'previous_plan_id' => $firstPlan->id,
            'new_plan_id' => $nextPlan->id, 'event_type' => 'subscription_upgraded',
        ]);
        $this->postJson('/api/admin/auth/teacher-subscriptions/'.$teacher->id.'/assign', ['plan_id' => $nextPlan->id, 'admin_note' => 'duplicate'])->assertStatus(409);

        $this->patchJson('/api/admin/auth/teacher-subscription-plans/'.$nextPlan->id.'/status', ['is_active' => false, 'admin_note' => 'Retired'])->assertOk();
        $this->assertTrue(app(\App\Http\Services\TeacherSubscriptionService::class)->canUseFeature($teacher->id, 'classes.manage'));
        $otherTeacher = TeacherProfile::where('web_user_id', 3)->firstOrFail();
        $this->postJson('/api/admin/auth/teacher-subscriptions/'.$otherTeacher->id.'/assign', ['plan_id' => $nextPlan->id, 'admin_note' => 'Inactive plan'])->assertUnprocessable();
        $this->assertDatabaseMissing('teacher_subscriptions', ['teacher_profile_id' => $otherTeacher->id, 'teacher_subscription_plan_id' => $nextPlan->id]);
    }

    public function test_lifecycle_extensions_permissions_history_and_audit(): void
    {
        $admin = $this->fullAdmin();
        $this->actingAs($admin, 'api');
        $teacher = TeacherProfile::where('web_user_id', 2)->firstOrFail();
        $plan = $this->createPlan(['code' => 'custom-plan', 'billing_interval' => 'custom', 'duration_days' => 30]);
        $subscriptionId = $this->assignPlan($teacher->id, $plan->id);
        $oldExpiry = TeacherSubscription::findOrFail($subscriptionId)->expires_at;
        $this->postJson('/api/admin/auth/teacher-subscriptions/'.$subscriptionId.'/extend', ['additional_days' => 30, 'admin_note' => 'Extension'])->assertOk();
        $this->assertEquals($oldExpiry->addDays(30)->toDateTimeString(), TeacherSubscription::findOrFail($subscriptionId)->expires_at->toDateTimeString());
        $this->postJson('/api/admin/auth/teacher-subscriptions/'.$subscriptionId.'/suspend', ['admin_note' => 'Security review'])->assertOk();
        $this->assertFalse(app(\App\Http\Services\TeacherSubscriptionService::class)->canUseFeature($teacher->id, 'classes.manage'));
        $this->postJson('/api/admin/auth/teacher-subscriptions/'.$subscriptionId.'/reactivate', ['admin_note' => 'Review cleared'])->assertOk();
        $this->postJson('/api/admin/auth/teacher-subscriptions/'.$subscriptionId.'/cancel', ['admin_note' => 'Ended'])->assertOk();
        $this->assertFalse(app(\App\Http\Services\TeacherSubscriptionService::class)->canUseFeature($teacher->id, 'classes.manage'));
        $this->postJson('/api/admin/auth/teacher-subscriptions/'.$subscriptionId.'/reactivate', ['admin_note' => 'Invalid'])->assertStatus(409);

        $this->getJson('/api/admin/auth/teacher-subscriptions/'.$teacher->id)->assertOk()->assertJsonMissingPath('data.teacher.password');
        $this->getJson('/api/admin/auth/teacher-subscriptions/'.$teacher->id.'/history?per_page=2')->assertOk()
            ->assertJsonPath('pagination.total', 5);
        $this->assertDatabaseHas('admin_activity_logs', ['admin_user_id' => $admin->id, 'action' => 'assigned']);
        $this->assertDatabaseHas('teacher_subscription_history', ['teacher_subscription_id' => $subscriptionId, 'event_type' => 'subscription_extended']);
        $this->assertDatabaseHas('teacher_subscription_history', ['teacher_subscription_id' => $subscriptionId, 'event_type' => 'subscription_suspended']);
        $this->assertDatabaseHas('teacher_subscription_history', ['teacher_subscription_id' => $subscriptionId, 'event_type' => 'subscription_reactivated']);
        $this->assertDatabaseHas('teacher_subscription_history', ['teacher_subscription_id' => $subscriptionId, 'event_type' => 'subscription_cancelled']);

        $this->actingAs($this->adminWithPermissions(['teacher-subscriptions.view']), 'api');
        $this->getJson('/api/admin/auth/teacher-subscriptions')->assertOk();
        $this->getJson('/api/admin/auth/teacher-subscriptions/'.$teacher->id.'/history')->assertForbidden();
        $this->postJson('/api/admin/auth/teacher-subscriptions/'.$subscriptionId.'/suspend', ['admin_note' => 'No'])->assertForbidden();
    }

    public function test_expired_and_lifetime_subscriptions_cannot_be_extended_or_reactivated_when_expired(): void
    {
        $this->actingAs($this->fullAdmin(), 'api');
        $teacher = TeacherProfile::where('web_user_id', 2)->firstOrFail();
        $lifetime = $this->createPlan(['code' => 'lifetime', 'billing_interval' => 'lifetime', 'duration_days' => null]);
        $lifeId = $this->assignPlan($teacher->id, $lifetime->id);
        $this->postJson('/api/admin/auth/teacher-subscriptions/'.$lifeId.'/extend', ['additional_days' => 10, 'admin_note' => 'Invalid'])->assertStatus(409);

        $this->postJson('/api/admin/auth/teacher-subscriptions/'.$lifeId.'/suspend', ['admin_note' => 'Pause'])->assertOk();
        TeacherSubscription::whereKey($lifeId)->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/admin/auth/teacher-subscriptions/'.$lifeId.'/reactivate', ['admin_note' => 'Expired'])->assertStatus(409);
        $this->assertDatabaseHas('teacher_subscriptions', ['id' => $lifeId, 'status' => 'expired']);
    }

    public function test_history_is_read_only_and_referenced_plan_cannot_be_deleted(): void
    {
        $this->actingAs($this->fullAdmin(), 'api');
        $teacher = TeacherProfile::where('web_user_id', 2)->firstOrFail();
        $plan = $this->createPlan(['code' => 'referenced']);
        $this->assignPlan($teacher->id, $plan->id);
        $historyCount = TeacherSubscriptionHistory::count();
        $this->getJson('/api/admin/auth/teacher-subscriptions/'.$teacher->id.'/history')->assertOk();
        $this->assertSame($historyCount, TeacherSubscriptionHistory::count());
        try {
            $plan->delete();
            $this->fail('Referenced historical plan should be protected by foreign keys.');
        } catch (QueryException) {
            $this->assertDatabaseHas('teacher_subscription_plans', ['id' => $plan->id]);
        }
    }

    public function test_teacher_subscription_list_filters_and_id_validation(): void
    {
        $admin = $this->fullAdmin();
        $this->actingAs($admin, 'api');
        $teacher = TeacherProfile::where('web_user_id', 2)->firstOrFail();
        $plan = $this->createPlan(['code' => 'filter-plan']);
        $this->assignPlan($teacher->id, $plan->id);
        $this->getJson('/api/admin/auth/teacher-subscriptions?search=Teacher&plan_id='.$plan->id.'&status=active&assignment_type=manual_admin')->assertOk()
            ->assertJsonPath('pagination.total', 1);
        $this->getJson('/api/admin/auth/teacher-subscriptions?status=invalid')->assertUnprocessable();
        $this->getJson('/api/admin/auth/teacher-subscriptions/99999')->assertNotFound();
        $this->getJson('/api/admin/auth/teacher-subscriptions/99999/history')->assertNotFound();
    }
}
