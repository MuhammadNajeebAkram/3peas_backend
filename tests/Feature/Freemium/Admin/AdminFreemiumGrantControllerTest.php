<?php

namespace Tests\Feature\Freemium\Admin;

use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumStudentGrant;
use App\Models\User;
use App\Models\WebUser;
use Carbon\Carbon;

class AdminFreemiumGrantControllerTest extends AdminFreemiumTestCase
{
    private User $admin;
    private FreemiumCampaign $campaign;
    private WebUser $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminWithPermissions([
            'freemium.view',
            'freemium.create',
            'freemium.update',
        ]);
        $this->actingAs($this->admin, 'api');

        $this->campaign = FreemiumCampaign::create([
            'name'     => 'Grants Test Campaign',
            'status'   => 'active',
            'priority' => 10,
            'start_at' => Carbon::now(),
            'end_at'   => Carbon::now()->addDays(30),
        ]);

        $this->student = $this->createStudent();
    }

    public function test_grant_index_with_filters(): void
    {
        $grant = FreemiumStudentGrant::create([
            'campaign_id' => $this->campaign->id,
            'user_id'     => $this->student->id,
            'status'      => 'active',
            'created_by'  => $this->admin->id,
        ]);

        $res = $this->getJson("/api/admin/auth/freemium/grants?campaign_id={$this->campaign->id}&user_id={$this->student->id}");
        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertEquals($grant->id, $res->json('data.0.id'));
        $this->assertEquals($this->student->id, $res->json('data.0.student.id'));
        $this->assertEquals($this->student->name, $res->json('data.0.student.name'));
    }

    public function test_grant_assign_validation_against_web_users(): void
    {
        // 1. Non-existent student user_id rejected
        $badUserRes = $this->postJson('/api/admin/auth/freemium/grants/assign', [
            'campaign_id' => $this->campaign->id,
            'user_id'     => 999999,
        ]);
        $badUserRes->assertStatus(422);
        $badUserRes->assertJsonValidationErrors(['user_id']);

        // 2. Non-existent campaign_id rejected
        $badCampaignRes = $this->postJson('/api/admin/auth/freemium/grants/assign', [
            'campaign_id' => 999999,
            'user_id'     => $this->student->id,
        ]);
        $badCampaignRes->assertStatus(422);
        $badCampaignRes->assertJsonValidationErrors(['campaign_id']);
    }

    public function test_grant_assign_success_and_audit(): void
    {
        $res = $this->postJson('/api/admin/auth/freemium/grants/assign', [
            'campaign_id'  => $this->campaign->id,
            'user_id'      => $this->student->id,
            'grant_type'   => 'pilot',
            'voucher_code' => 'PILOT-2026',
            'expires_at'   => Carbon::now()->addDays(14)->toIso8601String(),
        ]);
        $res->assertCreated();

        $grantId = $res->json('data.id');
        $this->assertDatabaseHas('freemium_student_grants', [
            'id'           => $grantId,
            'campaign_id'  => $this->campaign->id,
            'user_id'      => $this->student->id,
            'grant_type'   => 'pilot',
            'voucher_code' => 'PILOT-2026',
            'status'       => 'active',
            'created_by'   => $this->admin->id,
        ]);

        $this->assertDatabaseHas('freemium_audit_logs', [
            'entity_type' => 'freemium_student_grant',
            'entity_id'   => $grantId,
            'action'      => 'grant.assigned',
            'admin_id'    => $this->admin->id,
        ]);
    }

    public function test_grant_duplicate_active_rejection(): void
    {
        FreemiumStudentGrant::create([
            'campaign_id' => $this->campaign->id,
            'user_id'     => $this->student->id,
            'status'      => 'active',
        ]);

        $dupRes = $this->postJson('/api/admin/auth/freemium/grants/assign', [
            'campaign_id' => $this->campaign->id,
            'user_id'     => $this->student->id,
        ]);
        $dupRes->assertStatus(422);
    }

    public function test_grant_revoke_and_reactivation(): void
    {
        $grant = FreemiumStudentGrant::create([
            'campaign_id' => $this->campaign->id,
            'user_id'     => $this->student->id,
            'status'      => 'active',
            'created_by'  => $this->admin->id,
        ]);

        // 1. Revoke active grant
        $revokeRes = $this->postJson("/api/admin/auth/freemium/grants/{$grant->id}/revoke");
        $revokeRes->assertOk();
        $this->assertEquals('revoked', $revokeRes->json('data.status'));

        $this->assertDatabaseHas('freemium_audit_logs', [
            'entity_type' => 'freemium_student_grant',
            'entity_id'   => $grant->id,
            'action'      => 'grant.revoked',
        ]);

        // 2. Revoking an already revoked grant returns 422
        $doubleRevoke = $this->postJson("/api/admin/auth/freemium/grants/{$grant->id}/revoke");
        $doubleRevoke->assertStatus(422);

        // 3. Re-assigning to the same student reactivates the revoked grant
        $reactivateRes = $this->postJson('/api/admin/auth/freemium/grants/assign', [
            'campaign_id' => $this->campaign->id,
            'user_id'     => $this->student->id,
        ]);
        $reactivateRes->assertOk();
        $this->assertEquals('active', $reactivateRes->json('data.status'));
        $this->assertEquals($grant->id, $reactivateRes->json('data.id'));

        $this->assertDatabaseHas('freemium_audit_logs', [
            'entity_type' => 'freemium_student_grant',
            'entity_id'   => $grant->id,
            'action'      => 'grant.reassigned',
        ]);
    }
}
