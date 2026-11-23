<?php

namespace Tests\Feature\Freemium\Admin;

use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumExamQuota;
use App\Models\User;
use Carbon\Carbon;

class AdminFreemiumQuotaControllerTest extends AdminFreemiumTestCase
{
    private User $admin;
    private FreemiumCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminWithPermissions([
            'freemium.view',
            'freemium.create',
            'freemium.update',
            'freemium.delete',
        ]);
        $this->actingAs($this->admin, 'api');

        $this->campaign = FreemiumCampaign::create([
            'name'     => 'Quota Test Campaign',
            'status'   => 'draft',
            'priority' => 10,
            'start_at' => Carbon::now(),
            'end_at'   => Carbon::now()->addDays(30),
        ]);
    }

    public function test_quota_index_with_filters(): void
    {
        FreemiumExamQuota::create([
            'campaign_id' => $this->campaign->id,
            'exam_type'   => 'practice',
            'window_type' => 'daily',
            'max_tests'   => 5,
        ]);

        FreemiumExamQuota::create([
            'campaign_id' => $this->campaign->id,
            'exam_type'   => 'formal_test',
            'window_type' => 'weekly',
            'max_tests'   => 2,
        ]);

        $res = $this->getJson("/api/admin/auth/freemium/quotas?campaign_id={$this->campaign->id}&exam_type=practice");
        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertEquals('practice', $res->json('data.0.exam_type'));
    }

    public function test_quota_store_and_duplicate_rejection(): void
    {
        // 1. Create first quota definition
        $createRes = $this->postJson('/api/admin/auth/freemium/quotas', [
            'campaign_id'           => $this->campaign->id,
            'exam_type'             => 'practice',
            'window_type'           => 'daily',
            'max_tests'             => 10,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 3,
        ]);
        $createRes->assertCreated();
        $quotaId = $createRes->json('data.id');

        $this->assertDatabaseHas('freemium_exam_quotas', [
            'id'            => $quotaId,
            'campaign_id'   => $this->campaign->id,
            'exam_type'     => 'practice',
            'window_type'   => 'daily',
            'max_tests'     => 10,
            'allow_retakes' => 1,
        ]);

        $this->assertDatabaseHas('freemium_audit_logs', [
            'entity_type' => 'freemium_exam_quota',
            'entity_id'   => $quotaId,
            'action'      => 'quota.created',
            'admin_id'    => $this->admin->id,
        ]);

        // 2. Reject duplicate quota for same campaign, exam_type, and window_type
        $dupRes = $this->postJson('/api/admin/auth/freemium/quotas', [
            'campaign_id' => $this->campaign->id,
            'exam_type'   => 'practice',
            'window_type' => 'daily',
            'max_tests'   => 20,
        ]);
        $dupRes->assertStatus(422);
    }

    public function test_quota_unlimited_configuration(): void
    {
        // -1 represents unlimited
        $res = $this->postJson('/api/admin/auth/freemium/quotas', [
            'campaign_id'   => $this->campaign->id,
            'exam_type'     => 'formal_test',
            'window_type'   => 'campaign',
            'max_tests'     => -1,
            'allow_retakes' => false,
        ]);
        $res->assertCreated();
        $this->assertEquals(-1, $res->json('data.max_tests'));

        $this->assertDatabaseHas('freemium_exam_quotas', [
            'campaign_id' => $this->campaign->id,
            'exam_type'   => 'formal_test',
            'window_type' => 'campaign',
            'max_tests'   => -1,
        ]);
    }

    public function test_quota_show(): void
    {
        $quota = FreemiumExamQuota::create([
            'campaign_id' => $this->campaign->id,
            'exam_type'   => 'mock',
            'window_type' => 'annual',
            'max_tests'   => 1,
        ]);

        $res = $this->getJson("/api/admin/auth/freemium/quotas/{$quota->id}");
        $res->assertOk();
        $this->assertEquals($quota->id, $res->json('data.id'));
        $this->assertEquals('mock', $res->json('data.exam_type'));
    }

    public function test_quota_update(): void
    {
        $quota = FreemiumExamQuota::create([
            'campaign_id'           => $this->campaign->id,
            'exam_type'             => 'practice',
            'window_type'           => 'daily',
            'max_tests'             => 5,
            'allow_retakes'         => false,
            'max_attempts_per_test' => 1,
        ]);

        // Prohibited update on immutable structural fields
        $badUpdate = $this->putJson("/api/admin/auth/freemium/quotas/{$quota->id}", [
            'exam_type' => 'formal_test',
            'max_tests' => 8,
        ]);
        $badUpdate->assertStatus(422);

        // Valid update on limits and retake configuration
        $goodUpdate = $this->putJson("/api/admin/auth/freemium/quotas/{$quota->id}", [
            'max_tests'             => 12,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 2,
        ]);
        $goodUpdate->assertOk();
        $this->assertEquals(12, $goodUpdate->json('data.max_tests'));
        $this->assertTrue($goodUpdate->json('data.allow_retakes'));

        $this->assertDatabaseHas('freemium_audit_logs', [
            'entity_type' => 'freemium_exam_quota',
            'entity_id'   => $quota->id,
            'action'      => 'quota.updated',
        ]);
    }

    public function test_quota_destroy(): void
    {
        $quota = FreemiumExamQuota::create([
            'campaign_id' => $this->campaign->id,
            'exam_type'   => 'practice',
            'window_type' => 'daily',
            'max_tests'   => 5,
        ]);

        $deleteRes = $this->deleteJson("/api/admin/auth/freemium/quotas/{$quota->id}");
        $deleteRes->assertOk();

        $this->assertSoftDeleted('freemium_exam_quotas', ['id' => $quota->id]);

        $this->assertDatabaseHas('freemium_audit_logs', [
            'entity_type' => 'freemium_exam_quota',
            'entity_id'   => $quota->id,
            'action'      => 'quota.deleted',
        ]);
    }
}
