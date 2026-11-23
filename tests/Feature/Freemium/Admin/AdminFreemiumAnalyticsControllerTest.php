<?php

namespace Tests\Feature\Freemium\Admin;

use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumExamQuota;
use App\Models\Freemium\FreemiumQuotaConsumption;
use App\Models\Freemium\FreemiumStudentGrant;
use App\Models\User;
use Carbon\Carbon;

class AdminFreemiumAnalyticsControllerTest extends AdminFreemiumTestCase
{
    public function test_analytics_summary_unauthorized_without_permission(): void
    {
        $viewerOnly = $this->createAdminWithPermissions(['freemium.view']);
        $this->actingAs($viewerOnly, 'api');

        $this->getJson('/api/admin/auth/freemium/analytics/summary')->assertForbidden();
    }

    public function test_analytics_summary_authorized_aggregates_metrics(): void
    {
        $analyticsAdmin = $this->createAdminWithPermissions(['freemium.analytics']);
        $this->actingAs($analyticsAdmin, 'api');

        $now = Carbon::now();

        // 1. Create campaigns with different statuses
        FreemiumCampaign::create([
            'name'     => 'Analytics Draft',
            'status'   => 'draft',
            'priority' => 10,
            'start_at' => $now,
            'end_at'   => $now->copy()->addDays(10),
        ]);

        $activeCampaign = FreemiumCampaign::create([
            'name'     => 'Analytics Active',
            'status'   => 'active',
            'priority' => 20,
            'start_at' => $now,
            'end_at'   => $now->copy()->addDays(20),
        ]);

        FreemiumCampaign::create([
            'name'     => 'Analytics Paused',
            'status'   => 'paused',
            'priority' => 30,
            'start_at' => $now,
            'end_at'   => $now->copy()->addDays(15),
        ]);

        // 2. Create grants
        $student1 = $this->createStudent();
        $student2 = $this->createStudent();

        FreemiumStudentGrant::create([
            'campaign_id' => $activeCampaign->id,
            'user_id'     => $student1->id,
            'status'      => 'active',
        ]);

        FreemiumStudentGrant::create([
            'campaign_id' => $activeCampaign->id,
            'user_id'     => $student2->id,
            'status'      => 'revoked',
        ]);

        // 3. Create quota & consumption
        $quota = FreemiumExamQuota::create([
            'campaign_id' => $activeCampaign->id,
            'exam_type'   => 'formal_test',
            'window_type' => 'daily',
            'max_tests'   => 5,
        ]);

        $academicTest = $this->createAcademicTest();

        FreemiumQuotaConsumption::create([
            'user_id'             => $student1->id,
            'exam_type'           => 'formal_test',
            'event_type'          => 'attempt_start',
            'primary_campaign_id' => $activeCampaign->id,
            'primary_quota_id'    => $quota->id,
            'test_id'             => $academicTest->id,
            'attempt_number'      => 1,
            'decision_snapshot'   => ['granted' => true],
            'consumed_at'         => $now,
        ]);

        // 4. Request summary
        $res = $this->getJson('/api/admin/auth/freemium/analytics/summary');
        $res->assertOk();

        $data = $res->json('data');

        $this->assertEquals(1, $data['campaigns']['draft']);
        $this->assertEquals(1, $data['campaigns']['active']);
        $this->assertEquals(1, $data['campaigns']['paused']);
        $this->assertEquals(3, $data['campaigns']['total']);

        $this->assertEquals(1, $data['grants']['active']);
        $this->assertEquals(2, $data['grants']['total']);

        $this->assertEquals(1, $data['consumptions']['total_events']);
        $this->assertEquals(1, $data['consumptions']['active_users_last_30d']);
        $this->assertNotNull($data['generated_at']);
    }
}
