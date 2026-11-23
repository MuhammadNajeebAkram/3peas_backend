<?php

namespace Tests\Feature\Freemium\Admin;

use App\Models\Freemium\FreemiumAuditLog;
use App\Models\User;
use Carbon\Carbon;

class AdminFreemiumAuditControllerTest extends AdminFreemiumTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminWithPermissions([
            'freemium.view',
        ]);
        $this->actingAs($this->admin, 'api');
    }

    public function test_audit_log_index_with_filters(): void
    {
        FreemiumAuditLog::create([
            'admin_id'    => $this->admin->id,
            'action'      => 'campaign.created',
            'entity_type' => 'freemium_campaign',
            'entity_id'   => 101,
            'created_at'  => Carbon::now()->subDays(2),
        ]);

        FreemiumAuditLog::create([
            'admin_id'    => $this->admin->id,
            'action'      => 'rule.created',
            'entity_type' => 'freemium_access_rule',
            'entity_id'   => 202,
            'created_at'  => Carbon::now(),
        ]);

        // Filter by action
        $resAction = $this->getJson('/api/admin/auth/freemium/audit-logs?action=campaign.created');
        $resAction->assertOk();
        $this->assertCount(1, $resAction->json('data'));
        $this->assertEquals('campaign.created', $resAction->json('data.0.action'));

        // Filter by entity_type
        $resEntity = $this->getJson('/api/admin/auth/freemium/audit-logs?entity_type=freemium_access_rule');
        $resEntity->assertOk();
        $this->assertCount(1, $resEntity->json('data'));
        $this->assertEquals('freemium_access_rule', $resEntity->json('data.0.entity_type'));
    }

    public function test_audit_log_show(): void
    {
        $log = FreemiumAuditLog::create([
            'admin_id'    => $this->admin->id,
            'action'      => 'quota.created',
            'entity_type' => 'freemium_exam_quota',
            'entity_id'   => 303,
            'new_values'  => ['max_tests' => 5],
            'created_at'  => Carbon::now(),
        ]);

        $res = $this->getJson("/api/admin/auth/freemium/audit-logs/{$log->id}");
        $res->assertOk();
        $this->assertEquals($log->id, $res->json('data.id'));
        $this->assertEquals('quota.created', $res->json('data.action'));
        $this->assertEquals(['max_tests' => 5], $res->json('data.new_values'));
    }

    public function test_audit_log_read_only_protection(): void
    {
        $log = FreemiumAuditLog::create([
            'admin_id'    => $this->admin->id,
            'action'      => 'grant.assigned',
            'entity_type' => 'freemium_student_grant',
            'entity_id'   => 404,
            'created_at'  => Carbon::now(),
        ]);

        // Mutating endpoints do not exist and return 405
        $this->postJson('/api/admin/auth/freemium/audit-logs', ['action' => 'fake'])->assertStatus(405);
        $this->putJson("/api/admin/auth/freemium/audit-logs/{$log->id}", ['action' => 'fake'])->assertStatus(405);
        $this->deleteJson("/api/admin/auth/freemium/audit-logs/{$log->id}")->assertStatus(405);
    }
}
