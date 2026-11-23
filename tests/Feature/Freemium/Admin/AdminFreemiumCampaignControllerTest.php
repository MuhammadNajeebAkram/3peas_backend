<?php

namespace Tests\Feature\Freemium\Admin;

use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumAuditLog;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumExamQuota;
use App\Models\Freemium\FreemiumQuotaConsumption;
use App\Models\Freemium\FreemiumStudentGrant;
use App\Models\User;
use Carbon\Carbon;

class AdminFreemiumCampaignControllerTest extends AdminFreemiumTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminWithPermissions([
            'freemium.view',
            'freemium.create',
            'freemium.update',
            'freemium.activate',
            'freemium.delete',
        ]);
        $this->actingAs($this->admin, 'api');
    }

    public function test_campaign_index_with_pagination_and_filters(): void
    {
        $now = Carbon::now();

        $c1 = FreemiumCampaign::create([
            'name'     => 'Campaign Alpha',
            'status'   => 'draft',
            'priority' => 10,
            'start_at' => $now,
            'end_at'   => $now->copy()->addDays(10),
        ]);

        $c2 = FreemiumCampaign::create([
            'name'     => 'Campaign Beta',
            'status'   => 'active',
            'priority' => 20,
            'start_at' => $now,
            'end_at'   => $now->copy()->addDays(20),
        ]);

        // Filter by status=active
        $res = $this->getJson('/api/admin/auth/freemium/campaigns?status=active');
        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertEquals($c2->id, $res->json('data.0.id'));

        // Search by name
        $resSearch = $this->getJson('/api/admin/auth/freemium/campaigns?search=Alpha');
        $resSearch->assertOk();
        $this->assertCount(1, $resSearch->json('data'));
        $this->assertEquals($c1->id, $resSearch->json('data.0.id'));

        // Verify relation counts exist
        $this->assertArrayHasKey('access_rules_count', $res->json('data.0'));
        $this->assertArrayHasKey('exam_quotas_count', $res->json('data.0'));
        $this->assertArrayHasKey('student_grants_count', $res->json('data.0'));
    }

    public function test_campaign_store_validation_and_creation(): void
    {
        $now = Carbon::now();

        // 1. Rejects invalid interval: end_at before start_at
        $badIntervalRes = $this->postJson('/api/admin/auth/freemium/campaigns', [
            'name'     => 'Bad Interval Campaign',
            'start_at' => $now->copy()->addDays(10)->toIso8601String(),
            'end_at'   => $now->toIso8601String(),
        ]);
        $badIntervalRes->assertStatus(422);

        // 2. Successful campaign creation
        $validRes = $this->postJson('/api/admin/auth/freemium/campaigns', [
            'name'                 => 'New Spring Campaign',
            'description'          => 'Test description',
            'status'               => 'draft',
            'priority'             => 50,
            'is_stackable'         => true,
            'start_at'             => $now->toIso8601String(),
            'end_at'               => $now->copy()->addDays(30)->toIso8601String(),
            'eligibility_criteria' => ['boards' => [1, 2]],
        ]);

        $validRes->assertCreated();
        $campaignId = $validRes->json('data.id');
        $this->assertDatabaseHas('freemium_campaigns', [
            'id'           => $campaignId,
            'name'         => 'New Spring Campaign',
            'status'       => 'draft',
            'is_stackable' => 1,
            'created_by'   => $this->admin->id,
        ]);

        // Audit log created
        $this->assertDatabaseHas('freemium_audit_logs', [
            'entity_type' => 'freemium_campaign',
            'entity_id'   => $campaignId,
            'action'      => 'campaign.created',
            'admin_id'    => $this->admin->id,
        ]);
    }

    public function test_campaign_show(): void
    {
        $now = Carbon::now();
        $campaign = FreemiumCampaign::create([
            'name'     => 'Detail View Campaign',
            'status'   => 'draft',
            'priority' => 10,
            'start_at' => $now,
            'end_at'   => $now->copy()->addDays(10),
        ]);

        FreemiumExamQuota::create([
            'campaign_id' => $campaign->id,
            'exam_type'   => 'practice',
            'window_type' => 'daily',
            'max_tests'   => 5,
        ]);

        $res = $this->getJson("/api/admin/auth/freemium/campaigns/{$campaign->id}");
        $res->assertOk();
        $res->assertJsonPath('data.id', $campaign->id);
        $this->assertNotEmpty($res->json('data.exam_quotas'));
    }

    public function test_campaign_update_metadata(): void
    {
        $now = Carbon::now();
        $campaign = FreemiumCampaign::create([
            'name'     => 'Initial Campaign Name',
            'status'   => 'draft',
            'priority' => 50,
            'start_at' => $now,
            'end_at'   => $now->copy()->addDays(15),
        ]);

        // Prohibited status mutation via standard update
        $badUpdate = $this->putJson("/api/admin/auth/freemium/campaigns/{$campaign->id}", [
            'name'   => 'Updated Name',
            'status' => 'active',
        ]);
        $badUpdate->assertStatus(422);

        // Valid metadata update
        $goodUpdate = $this->putJson("/api/admin/auth/freemium/campaigns/{$campaign->id}", [
            'name'        => 'Updated Campaign Name',
            'description' => 'Updated Description',
            'priority'    => 25,
        ]);
        $goodUpdate->assertOk();
        $this->assertEquals('Updated Campaign Name', $goodUpdate->json('data.name'));
        $this->assertEquals(25, $goodUpdate->json('data.priority'));

        $this->assertDatabaseHas('freemium_audit_logs', [
            'entity_type' => 'freemium_campaign',
            'entity_id'   => $campaign->id,
            'action'      => 'campaign.updated',
        ]);
    }

    public function test_campaign_status_transition_workflow(): void
    {
        $now = Carbon::now();
        $campaign = FreemiumCampaign::create([
            'name'     => 'Workflow Campaign',
            'status'   => 'draft',
            'priority' => 10,
            'start_at' => $now,
            'end_at'   => $now->copy()->addDays(30),
        ]);

        // 1. Invalid transition: draft -> paused
        $invalidRes = $this->patchJson("/api/admin/auth/freemium/campaigns/{$campaign->id}/status", [
            'status' => 'paused',
        ]);
        $invalidRes->assertStatus(422);
        $this->assertEquals('INVALID_CAMPAIGN_TRANSITION', $invalidRes->json('error_code'));

        // 2. Valid transition: draft -> active
        $activeRes = $this->patchJson("/api/admin/auth/freemium/campaigns/{$campaign->id}/status", [
            'status' => 'active',
        ]);
        $activeRes->assertOk();
        $this->assertEquals('active', $activeRes->json('data.status'));

        // 3. Valid transition: active -> paused
        $pausedRes = $this->patchJson("/api/admin/auth/freemium/campaigns/{$campaign->id}/status", [
            'status' => 'paused',
        ]);
        $pausedRes->assertOk();
        $this->assertEquals('paused', $pausedRes->json('data.status'));

        // 4. Valid transition: paused -> active
        $reActiveRes = $this->patchJson("/api/admin/auth/freemium/campaigns/{$campaign->id}/status", [
            'status' => 'active',
        ]);
        $reActiveRes->assertOk();
        $this->assertEquals('active', $reActiveRes->json('data.status'));

        // 5. Valid transition: active -> archived
        $archivedRes = $this->patchJson("/api/admin/auth/freemium/campaigns/{$campaign->id}/status", [
            'status' => 'archived',
        ]);
        $archivedRes->assertOk();
        $this->assertEquals('archived', $archivedRes->json('data.status'));

        // 6. Archived is terminal: archived -> active is rejected
        $reviveRes = $this->patchJson("/api/admin/auth/freemium/campaigns/{$campaign->id}/status", [
            'status' => 'active',
        ]);
        $reviveRes->assertStatus(422);
    }

    public function test_campaign_deletion_restricted_when_grants_exist(): void
    {
        $now = Carbon::now();
        $campaign = FreemiumCampaign::create([
            'name'     => 'Campaign with Grants',
            'status'   => 'draft',
            'priority' => 10,
            'start_at' => $now,
            'end_at'   => $now->copy()->addDays(10),
        ]);

        $student = $this->createStudent();
        FreemiumStudentGrant::create([
            'campaign_id' => $campaign->id,
            'user_id'     => $student->id,
            'status'      => 'active',
        ]);

        $deleteRes = $this->deleteJson("/api/admin/auth/freemium/campaigns/{$campaign->id}");
        $deleteRes->assertStatus(409);
        $this->assertEquals('CAMPAIGN_DELETION_RESTRICTED', $deleteRes->json('error_code'));
    }

    public function test_campaign_deletion_restricted_when_consumptions_exist(): void
    {
        $now = Carbon::now();
        $campaign = FreemiumCampaign::create([
            'name'     => 'Campaign with Consumptions',
            'status'   => 'active',
            'priority' => 10,
            'start_at' => $now,
            'end_at'   => $now->copy()->addDays(10),
        ]);

        $quota = FreemiumExamQuota::create([
            'campaign_id' => $campaign->id,
            'exam_type'   => 'practice',
            'window_type' => 'daily',
            'max_tests'   => 5,
        ]);

        $student = $this->createStudent();
        $test = $this->createAcademicTest();
        FreemiumQuotaConsumption::create([
            'user_id'             => $student->id,
            'exam_type'           => 'formal_test',
            'event_type'          => 'attempt_start',
            'primary_campaign_id' => $campaign->id,
            'primary_quota_id'    => $quota->id,
            'test_id'             => $test->id,
            'attempt_number'      => 1,
            'decision_snapshot'   => ['granted' => true],
            'consumed_at'         => $now,
        ]);

        $deleteRes = $this->deleteJson("/api/admin/auth/freemium/campaigns/{$campaign->id}");
        $deleteRes->assertStatus(409);
        $this->assertEquals('CAMPAIGN_DELETION_RESTRICTED', $deleteRes->json('error_code'));
    }

    public function test_campaign_deletion_successful_when_clean(): void
    {
        $now = Carbon::now();
        $campaign = FreemiumCampaign::create([
            'name'     => 'Clean Campaign To Delete',
            'status'   => 'draft',
            'priority' => 10,
            'start_at' => $now,
            'end_at'   => $now->copy()->addDays(10),
        ]);

        // Add a child rule and quota to verify cascading purge
        FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'target_scope'             => 'global',
            'academic_target_snapshot' => ['scope' => 'global'],
        ]);

        FreemiumExamQuota::create([
            'campaign_id' => $campaign->id,
            'exam_type'   => 'practice',
            'window_type' => 'daily',
            'max_tests'   => 5,
        ]);

        $deleteRes = $this->deleteJson("/api/admin/auth/freemium/campaigns/{$campaign->id}");
        $deleteRes->assertOk();

        $this->assertDatabaseMissing('freemium_campaigns', ['id' => $campaign->id]);
        $this->assertDatabaseMissing('freemium_access_rules', ['campaign_id' => $campaign->id]);
        $this->assertDatabaseMissing('freemium_exam_quotas', ['campaign_id' => $campaign->id]);

        $this->assertDatabaseHas('freemium_audit_logs', [
            'entity_type' => 'freemium_campaign',
            'entity_id'   => $campaign->id,
            'action'      => 'campaign.deleted',
        ]);
    }
}
