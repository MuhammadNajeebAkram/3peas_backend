<?php

namespace Tests\Feature\Freemium;

use App\Http\Services\Freemium\Exceptions\CampaignDeletionRestrictedException;
use App\Http\Services\Freemium\Exceptions\InvalidCampaignTransitionException;
use App\Http\Services\Freemium\FreemiumAuditService;
use App\Http\Services\Freemium\FreemiumCampaignLifecycleService;
use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumAuditLog;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumExamQuota;
use App\Models\Freemium\FreemiumQuotaConsumption;
use App\Models\Freemium\FreemiumQuotaConsumptionEnvelope;
use App\Models\Freemium\FreemiumStudentGrant;
use App\Models\Test as AcademicTest;
use App\Models\User;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class FreemiumCampaignLifecycleServiceTest extends TestCase
{
    private FreemiumCampaignLifecycleService $lifecycleService;
    private FreemiumAuditService $auditService;
    private User $admin;
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auditService = new FreemiumAuditService();
        $this->lifecycleService = new FreemiumCampaignLifecycleService($this->auditService);
        $this->now = Carbon::parse('2026-11-18 10:00:00', 'UTC');

        $adminId = DB::table('users')->value('id');
        $this->admin = $adminId ? User::find($adminId) : User::create([
            'name'     => 'Lifecycle Admin',
            'email'    => 'admin_life_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
        ]);
    }

    protected function tearDown(): void
    {
        FreemiumAuditLog::where('admin_id', $this->admin->id)->delete();
        FreemiumQuotaConsumptionEnvelope::query()->delete();
        FreemiumQuotaConsumption::query()->delete();
        FreemiumStudentGrant::query()->forceDelete();
        FreemiumAccessRule::query()->delete();
        FreemiumExamQuota::query()->forceDelete();
        FreemiumCampaign::query()->forceDelete();

        parent::tearDown();
    }

    /**
     * Test valid campaign creation with audit log.
     */
    public function test_campaign_creation_with_valid_interval(): void
    {
        $campaign = $this->lifecycleService->createCampaign([
            'name'         => 'New Lifecycle Campaign',
            'status'       => 'draft',
            'priority'     => 40,
            'is_stackable' => true,
            'start_at'     => $this->now->toIso8601String(),
            'end_at'       => $this->now->copy()->addDays(30)->toIso8601String(),
        ], $this->admin, $this->now);

        $this->assertInstanceOf(FreemiumCampaign::class, $campaign);
        $this->assertEquals('draft', $campaign->status);
        $this->assertEquals(40, $campaign->priority);

        // Verify audit log
        $this->assertDatabaseHas('freemium_audit_logs', [
            'action'      => 'campaign.created',
            'entity_type' => 'freemium_campaign',
            'entity_id'   => $campaign->id,
            'admin_id'    => $this->admin->id,
        ]);
    }

    /**
     * Test invalid date interval (start_at >= end_at) throws InvalidArgumentException.
     */
    public function test_campaign_creation_rejects_invalid_interval(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid campaign date interval");

        $this->lifecycleService->createCampaign([
            'name'     => 'Invalid Date Campaign',
            'start_at' => $this->now->toIso8601String(),
            'end_at'   => $this->now->copy()->subDay()->toIso8601String(), // End is before start!
        ], $this->admin, $this->now);
    }

    /**
     * Test valid status transitions:
     * draft -> active -> paused -> active -> archived.
     */
    public function test_valid_status_transitions(): void
    {
        $campaign = $this->lifecycleService->createCampaign([
            'name'     => 'Transition Campaign',
            'status'   => 'draft',
            'start_at' => $this->now->toIso8601String(),
            'end_at'   => $this->now->copy()->addDays(10)->toIso8601String(),
        ], $this->admin, $this->now);

        // 1. draft -> active
        $updated = $this->lifecycleService->updateCampaign($campaign, ['status' => 'active'], $this->admin, $this->now);
        $this->assertEquals('active', $updated->status);

        // 2. active -> paused
        $paused = $this->lifecycleService->updateCampaign($updated, ['status' => 'paused'], $this->admin, $this->now);
        $this->assertEquals('paused', $paused->status);

        // 3. paused -> active
        $resumed = $this->lifecycleService->updateCampaign($paused, ['status' => 'active'], $this->admin, $this->now);
        $this->assertEquals('active', $resumed->status);

        // 4. active -> archived
        $archived = $this->lifecycleService->updateCampaign($resumed, ['status' => 'archived'], $this->admin, $this->now);
        $this->assertEquals('archived', $archived->status);
    }

    /**
     * Test invalid status transitions throw InvalidCampaignTransitionException.
     */
    public function test_invalid_status_transitions_rejected(): void
    {
        // 1. draft -> paused is invalid
        $campaign = $this->lifecycleService->createCampaign([
            'name'     => 'Invalid Transition Campaign',
            'status'   => 'draft',
            'start_at' => $this->now->toIso8601String(),
            'end_at'   => $this->now->copy()->addDays(10)->toIso8601String(),
        ], $this->admin, $this->now);

        $this->expectException(InvalidCampaignTransitionException::class);
        $this->expectExceptionMessage("Invalid campaign status transition from 'draft' to 'paused'.");

        $this->lifecycleService->updateCampaign($campaign, ['status' => 'paused'], $this->admin, $this->now);
    }

    /**
     * Test terminal status archived cannot transition back to active.
     */
    public function test_archived_status_is_terminal(): void
    {
        $campaign = $this->lifecycleService->createCampaign([
            'name'     => 'Archived Terminal Campaign',
            'status'   => 'active',
            'start_at' => $this->now->toIso8601String(),
            'end_at'   => $this->now->copy()->addDays(10)->toIso8601String(),
        ], $this->admin, $this->now);

        $archived = $this->lifecycleService->updateCampaign($campaign, ['status' => 'archived'], $this->admin, $this->now);

        $this->expectException(InvalidCampaignTransitionException::class);
        $this->expectExceptionMessage("Invalid campaign status transition from 'archived' to 'active'.");

        $this->lifecycleService->updateCampaign($archived, ['status' => 'active'], $this->admin, $this->now);
    }

    /**
     * Test historical grant protection: Campaign with student grants cannot be deleted.
     */
    public function test_campaign_with_student_grants_cannot_be_deleted(): void
    {
        $campaign = $this->lifecycleService->createCampaign([
            'name'     => 'Grant Protected Campaign',
            'status'   => 'active',
            'start_at' => $this->now->toIso8601String(),
            'end_at'   => $this->now->copy()->addDays(10)->toIso8601String(),
        ], $this->admin, $this->now);

        $studentId = DB::table('web_users')->insertGetId([
            'name'       => 'Grant Protection Student',
            'email'      => 'grant_protect_' . uniqid() . '@example.com',
            'password'   => bcrypt('secret'),
            'role'       => 'student',
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        FreemiumStudentGrant::create([
            'campaign_id'  => $campaign->id,
            'user_id'      => $studentId,
            'grant_type'   => 'direct',
            'granted_at'   => $this->now,
            'status'       => 'active',
            'created_by'   => $this->admin->id,
        ]);

        $this->expectException(CampaignDeletionRestrictedException::class);
        $this->expectExceptionMessage("student grants exist for this campaign");

        try {
            $this->lifecycleService->deleteCampaign($campaign, $this->admin);
        } finally {
            FreemiumStudentGrant::where('user_id', $studentId)->forceDelete();
            DB::table('web_users')->where('id', $studentId)->delete();
        }
    }

    /**
     * Test historical quota consumption protection: Campaign with consumptions cannot be deleted.
     */
    public function test_campaign_with_consumptions_cannot_be_deleted(): void
    {
        $campaign = $this->lifecycleService->createCampaign([
            'name'     => 'Consumption Protected Campaign',
            'status'   => 'active',
            'start_at' => $this->now->toIso8601String(),
            'end_at'   => $this->now->copy()->addDays(10)->toIso8601String(),
        ], $this->admin, $this->now);

        $quota = FreemiumExamQuota::create([
            'campaign_id' => $campaign->id,
            'exam_type'   => 'formal_test',
            'window_type' => 'daily',
            'max_tests'   => 5,
        ]);

        $studentId = DB::table('web_users')->insertGetId([
            'name'       => 'Consumption Protection Student',
            'email'      => 'consumption_protect_' . uniqid() . '@example.com',
            'password'   => bcrypt('secret'),
            'role'       => 'student',
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $test = AcademicTest::create([
            'title'      => 'Protection Formal Test',
            'test_mode'  => 'test',
            'scope_type' => 'chapter',
        ]);

        FreemiumQuotaConsumption::create([
            'user_id'             => $studentId,
            'exam_type'           => 'formal_test',
            'event_type'          => 'attempt_start',
            'primary_campaign_id' => $campaign->id,
            'primary_quota_id'    => $quota->id,
            'test_id'             => $test->id,
            'attempt_number'      => 1,
            'decision_snapshot'   => ['granted' => true],
            'consumed_at'         => $this->now,
        ]);

        $this->expectException(CampaignDeletionRestrictedException::class);
        $this->expectExceptionMessage("historical quota consumption records exist");

        try {
            $this->lifecycleService->deleteCampaign($campaign, $this->admin);
        } finally {
            FreemiumQuotaConsumption::where('user_id', $studentId)->delete();
            DB::table('web_users')->where('id', $studentId)->delete();
        }
    }

    /**
     * Test safe deletion of clean campaign with child rules and quotas.
     */
    public function test_safe_deletion_of_clean_campaign(): void
    {
        $campaign = $this->lifecycleService->createCampaign([
            'name'     => 'Clean Campaign To Delete',
            'status'   => 'draft',
            'start_at' => $this->now->toIso8601String(),
            'end_at'   => $this->now->copy()->addDays(10)->toIso8601String(),
        ], $this->admin, $this->now);

        // Add child rule
        FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'target_scope'             => 'global',
            'target_status'            => 'active',
            'perm_mcqs'                => 'allow',
            'academic_target_snapshot' => ['scope' => 'global'],
        ]);

        // Add child quota
        FreemiumExamQuota::create([
            'campaign_id' => $campaign->id,
            'exam_type'   => 'formal_test',
            'window_type' => 'daily',
            'max_tests'   => 5,
        ]);

        $deleted = $this->lifecycleService->deleteCampaign($campaign, $this->admin);

        $this->assertTrue($deleted);
        $this->assertDatabaseMissing('freemium_campaigns', ['id' => $campaign->id]);
        $this->assertDatabaseMissing('freemium_access_rules', ['campaign_id' => $campaign->id]);
        $this->assertDatabaseMissing('freemium_exam_quotas', ['campaign_id' => $campaign->id]);

        // Verify audit log recorded
        $this->assertDatabaseHas('freemium_audit_logs', [
            'action'      => 'campaign.deleted',
            'entity_type' => 'freemium_campaign',
            'entity_id'   => $campaign->id,
        ]);
    }

    /**
     * Test isCurrentlyActive half-open interval.
     */
    public function test_is_currently_active_check(): void
    {
        $campaign = $this->lifecycleService->createCampaign([
            'name'     => 'Active Check Campaign',
            'status'   => 'active',
            'start_at' => $this->now->toIso8601String(),
            'end_at'   => $this->now->copy()->addDays(5)->toIso8601String(),
        ], $this->admin, $this->now);

        $this->assertTrue($this->lifecycleService->isCurrentlyActive($campaign, $this->now));
        $this->assertTrue($this->lifecycleService->isCurrentlyActive($campaign, $this->now->copy()->addDays(2)));
        $this->assertFalse($this->lifecycleService->isCurrentlyActive($campaign, $this->now->copy()->addDays(5))); // At end_at
        $this->assertFalse($this->lifecycleService->isCurrentlyActive($campaign, $this->now->copy()->subMinute())); // Before start_at
    }
}
