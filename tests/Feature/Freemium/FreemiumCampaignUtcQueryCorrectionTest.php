<?php

namespace Tests\Feature\Freemium;

use App\Http\Services\Freemium\FreemiumAccessRuleEvaluator;
use App\Http\Services\Freemium\FreemiumAuditService;
use App\Http\Services\Freemium\FreemiumCampaignLifecycleService;
use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\User;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FreemiumCampaignUtcQueryCorrectionTest extends TestCase
{
    private FreemiumAccessRuleEvaluator $evaluator;
    private FreemiumCampaignLifecycleService $lifecycleService;
    private WebUser $student;
    private User $admin;
    private Carbon $nowUtc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new FreemiumAccessRuleEvaluator();
        $this->lifecycleService = new FreemiumCampaignLifecycleService(new FreemiumAuditService());
        $this->nowUtc = Carbon::parse('2026-11-21 12:00:00', 'UTC');

        $adminId = DB::table('users')->value('id');
        $this->admin = $adminId ? User::find($adminId) : User::first();

        // Ensure a test student exists
        $studentId = DB::table('web_users')->where('role', 'student')->value('id');
        if (!$studentId) {
            $studentId = DB::table('web_users')->insertGetId([
                'name'       => 'Query Test Student',
                'email'      => 'student_qtest_' . uniqid() . '@example.com',
                'password'   => bcrypt('secret'),
                'role'       => 'student',
                'status'     => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->student = WebUser::find($studentId);

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    /**
     * Helper to create a test campaign with exact MySQL TIMESTAMP seconds.
     */
    private function createTestCampaign(array $attributes = []): FreemiumCampaign
    {
        $start = $attributes['start_at'] ?? $this->nowUtc->copy()->subDays(2);
        $end = array_key_exists('end_at', $attributes) ? $attributes['end_at'] : $this->nowUtc->copy()->addDays(2);

        $campaign = FreemiumCampaign::create(array_merge([
            'name'         => 'Test Q ' . uniqid(),
            'status'       => 'active',
            'is_stackable' => true,
            'priority'     => 100,
            'start_at'     => $start,
            'end_at'       => $end,
            'created_by'   => $this->admin?->id,
            'updated_by'   => $this->admin?->id,
        ], $attributes));

        // Ensure MySQL TIMESTAMP column holds the exact intended Unix epoch seconds
        if ($start instanceof Carbon) {
            DB::table('freemium_campaigns')->where('id', $campaign->id)->update([
                'start_at' => DB::raw("FROM_UNIXTIME({$start->getTimestamp()})"),
            ]);
        }
        if ($end instanceof Carbon) {
            DB::table('freemium_campaigns')->where('id', $campaign->id)->update([
                'end_at' => DB::raw("FROM_UNIXTIME({$end->getTimestamp()})"),
            ]);
        }

        return $campaign->fresh();
    }

    /**
     * Helper to ensure Campaign #2 exists in the test session with its exact stored timestamps.
     */
    private function ensureCampaign2(): FreemiumCampaign
    {
        $c2 = FreemiumCampaign::find(2);
        if (!$c2) {
            // When running against test database, seed Campaign #2 with exact production timestamps
            DB::table('freemium_campaigns')->insert([
                'id'                   => 2,
                'name'                 => 'Free Federal Biology Class 9th',
                'status'               => 'active',
                'is_stackable'         => 0,
                'priority'             => 100,
                'start_at'             => DB::raw("FROM_UNIXTIME(1795254540)"),
                'end_at'               => DB::raw("FROM_UNIXTIME(1830246540)"),
                'eligibility_criteria' => null,
                'created_by'           => $this->admin?->id,
                'updated_by'           => $this->admin?->id,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
            $c2 = FreemiumCampaign::find(2);
        }

        return $c2;
    }

    /**
     * 1. Campaign starting in the future is inactive.
     */
    public function test_campaign_starting_in_future_is_inactive(): void
    {
        $futureStart = $this->nowUtc->copy()->addHours(3);
        $futureEnd = $this->nowUtc->copy()->addDays(5);

        $campaign = $this->createTestCampaign([
            'start_at' => $futureStart,
            'end_at'   => $futureEnd,
        ]);

        $running = FreemiumCampaign::currentlyRunning($this->nowUtc)->where('id', $campaign->id)->exists();
        $this->assertFalse($running, "Campaign starting in the future should not be currently running");

        $participating = $this->evaluator->resolveParticipatingCampaigns($this->student, $this->nowUtc);
        $participatingIds = collect($participating['participating'])->pluck('id')->all();
        $this->assertNotContains($campaign->id, $participatingIds);
    }

    /**
     * 2. Campaign that has started and has not expired is active.
     */
    public function test_campaign_started_and_not_expired_is_active(): void
    {
        $pastStart = $this->nowUtc->copy()->subHours(2);
        $futureEnd = $this->nowUtc->copy()->addHours(2);

        $campaign = $this->createTestCampaign([
            'start_at' => $pastStart,
            'end_at'   => $futureEnd,
        ]);

        $running = FreemiumCampaign::currentlyRunning($this->nowUtc)->where('id', $campaign->id)->exists();
        $this->assertTrue($running, "Started and unexpired campaign must be currently running");

        $participating = $this->evaluator->resolveParticipatingCampaigns($this->student, $this->nowUtc);
        $participatingIds = collect($participating['participating'])->pluck('id')->all();
        $this->assertContains($campaign->id, $participatingIds);
    }

    /**
     * 3. Expired campaign is inactive.
     */
    public function test_expired_campaign_is_inactive(): void
    {
        $pastStart = $this->nowUtc->copy()->subDays(5);
        $pastEnd = $this->nowUtc->copy()->subHours(1);

        $campaign = $this->createTestCampaign([
            'start_at' => $pastStart,
            'end_at'   => $pastEnd,
        ]);

        $running = FreemiumCampaign::currentlyRunning($this->nowUtc)->where('id', $campaign->id)->exists();
        $this->assertFalse($running, "Expired campaign must not be currently running");

        $participating = $this->evaluator->resolveParticipatingCampaigns($this->student, $this->nowUtc);
        $participatingIds = collect($participating['participating'])->pluck('id')->all();
        $this->assertNotContains($campaign->id, $participatingIds);
    }

    /**
     * 4. Null end_at campaign remains active after its start.
     */
    public function test_null_end_at_campaign_remains_active_after_start(): void
    {
        // 4a. Verify SQL query structure correctly includes nullable end_at condition
        $query = FreemiumCampaign::currentlyRunning($this->nowUtc);
        $sql = $query->toSql();
        $this->assertStringContainsString('start_at <= FROM_UNIXTIME(?)', $sql);
        $this->assertStringContainsString('`end_at` is null or end_at > FROM_UNIXTIME(?)', $sql);

        // 4b. Verify in-memory lifecycle evaluation accepts null end_at indefinitely
        $pastStart = $this->nowUtc->copy()->subDays(10);
        $campaign = new FreemiumCampaign([
            'name'     => 'Indefinite Campaign',
            'status'   => 'active',
            'start_at' => $pastStart,
            'end_at'   => null,
        ]);

        $isActiveNow = $this->lifecycleService->isCurrentlyActive($campaign, $this->nowUtc);
        $this->assertTrue($isActiveNow, "Campaign with null end_at must be currently active");

        $isActiveFarFuture = $this->lifecycleService->isCurrentlyActive($campaign, $this->nowUtc->copy()->addYears(10));
        $this->assertTrue($isActiveFarFuture, "Campaign with null end_at must remain active far into future");
    }

    /**
     * 5. Exact start boundary is inclusive.
     */
    public function test_exact_start_boundary_is_inclusive(): void
    {
        $exactStart = Carbon::parse('2026-11-25 10:00:00', 'UTC');
        $end = Carbon::parse('2026-11-26 10:00:00', 'UTC');

        $campaign = $this->createTestCampaign([
            'start_at' => $exactStart,
            'end_at'   => $end,
        ]);

        // 1 second before start: Inactive
        $beforeStart = $exactStart->copy()->subSecond();
        $this->assertFalse(
            FreemiumCampaign::currentlyRunning($beforeStart)->where('id', $campaign->id)->exists(),
            "1 second before start_at must be inactive"
        );

        // Exact start second: Active (inclusive boundary)
        $this->assertTrue(
            FreemiumCampaign::currentlyRunning($exactStart)->where('id', $campaign->id)->exists(),
            "Exact start_at second must be active (inclusive boundary)"
        );
    }

    /**
     * 6. Exact end boundary is exclusive.
     */
    public function test_exact_end_boundary_is_exclusive(): void
    {
        $start = Carbon::parse('2026-11-25 10:00:00', 'UTC');
        $exactEnd = Carbon::parse('2026-11-26 10:00:00', 'UTC');

        $campaign = $this->createTestCampaign([
            'start_at' => $start,
            'end_at'   => $exactEnd,
        ]);

        // 1 second before end: Active
        $beforeEnd = $exactEnd->copy()->subSecond();
        $this->assertTrue(
            FreemiumCampaign::currentlyRunning($beforeEnd)->where('id', $campaign->id)->exists(),
            "1 second before end_at must be active"
        );

        // Exact end second: Inactive (exclusive half-open interval)
        $this->assertFalse(
            FreemiumCampaign::currentlyRunning($exactEnd)->where('id', $campaign->id)->exists(),
            "Exact end_at second must be inactive (exclusive boundary)"
        );

        // 1 second after end: Inactive
        $afterEnd = $exactEnd->copy()->addSecond();
        $this->assertFalse(
            FreemiumCampaign::currentlyRunning($afterEnd)->where('id', $campaign->id)->exists(),
            "1 second after end_at must be inactive"
        );
    }

    /**
     * 7. Campaign #2 evaluates correctly using its existing stored Unix timestamps.
     */
    public function test_campaign_2_evaluates_correctly_using_existing_stored_unix_timestamps(): void
    {
        $c2 = $this->ensureCampaign2();
        $this->assertNotNull($c2, "Campaign #2 must exist in the database");

        // Read stored timestamps directly from MySQL without modification
        $raw = DB::select("SELECT UNIX_TIMESTAMP(start_at) as s_ts, UNIX_TIMESTAMP(end_at) as e_ts FROM freemium_campaigns WHERE id = 2")[0];
        $this->assertEquals(1795254540, (int) $raw->s_ts, "Campaign #2 start_at Unix timestamp must remain 1795254540");
        $this->assertEquals(1830246540, (int) $raw->e_ts, "Campaign #2 end_at Unix timestamp must remain 1830246540");

        // 1 second before start: 2026-11-21 09:48:59 UTC
        $beforeStart = Carbon::createFromTimestamp(1795254539, 'UTC');
        $this->assertFalse(
            FreemiumCampaign::currentlyRunning($beforeStart)->where('id', 2)->exists(),
            "Campaign #2 must not be active before 09:49:00 UTC"
        );

        // Exact start: 2026-11-21 09:49:00 UTC
        $exactStart = Carbon::createFromTimestamp(1795254540, 'UTC');
        $this->assertTrue(
            FreemiumCampaign::currentlyRunning($exactStart)->where('id', 2)->exists(),
            "Campaign #2 must be active at exact start instant 09:49:00 UTC"
        );

        // During validity: 2026-11-21 14:49:00 UTC
        $during = Carbon::parse('2026-11-21 14:49:00', 'UTC');
        $this->assertTrue(
            FreemiumCampaign::currentlyRunning($during)->where('id', 2)->exists(),
            "Campaign #2 must be active during its validity window"
        );

        // Exact end: 2027-12-31 09:49:00 UTC
        $exactEnd = Carbon::createFromTimestamp(1830246540, 'UTC');
        $this->assertFalse(
            FreemiumCampaign::currentlyRunning($exactEnd)->where('id', 2)->exists(),
            "Campaign #2 must be inactive at exact end instant (exclusive)"
        );
    }

    /**
     * 8. Carbon input in UTC and Asia/Karachi represents the same instant.
     */
    public function test_carbon_input_in_utc_and_asia_karachi_represent_same_instant(): void
    {
        $this->ensureCampaign2();

        // 2026-11-21 14:49:00 Asia/Karachi is mathematically identical to 2026-11-21 09:49:00 UTC
        $karachiTime = Carbon::parse('2026-11-21 14:49:00', 'Asia/Karachi');
        $utcTime     = Carbon::parse('2026-11-21 09:49:00', 'UTC');

        $this->assertEquals($karachiTime->getTimestamp(), $utcTime->getTimestamp());

        // Scope resolution with both Carbon objects must yield identical results
        $activeWithKarachi = FreemiumCampaign::currentlyRunning($karachiTime)->where('id', 2)->exists();
        $activeWithUtc     = FreemiumCampaign::currentlyRunning($utcTime)->where('id', 2)->exists();

        $this->assertTrue($activeWithKarachi, "Campaign #2 must be active when passed Asia/Karachi Carbon instance");
        $this->assertTrue($activeWithUtc, "Campaign #2 must be active when passed UTC Carbon instance");
        $this->assertEquals($activeWithKarachi, $activeWithUtc, "Both timezones representing same instant must yield same result");
    }

    /**
     * 9. Campaign priority and rule resolution remain unchanged.
     */
    public function test_campaign_priority_and_rule_resolution_remain_unchanged(): void
    {
        // Non-stackable campaign with high priority (lower number wins)
        $priorityHigh = $this->createTestCampaign([
            'name'         => 'Priority 10 Campaign',
            'is_stackable' => false,
            'priority'     => 10,
        ]);

        $priorityLow = $this->createTestCampaign([
            'name'         => 'Priority 50 Campaign',
            'is_stackable' => false,
            'priority'     => 50,
        ]);

        FreemiumAccessRule::create([
            'campaign_id'              => $priorityHigh->id,
            'target_scope'             => 'global',
            'target_status'            => 'active',
            'perm_mcqs'                => 'allow',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['scope' => 'global'],
        ]);

        FreemiumAccessRule::create([
            'campaign_id'              => $priorityLow->id,
            'target_scope'             => 'global',
            'target_status'            => 'active',
            'perm_mcqs'                => 'deny',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['scope' => 'global'],
        ]);

        $resolution = $this->evaluator->resolveParticipatingCampaigns($this->student, $this->nowUtc);
        $this->assertNotNull($resolution['primary']);
        $this->assertEquals($priorityHigh->id, $resolution['primary']->id, "Higher priority campaign (10 < 50) must win as primary");
    }

    /**
     * 10. Model scope and evaluator produce consistent results.
     */
    public function test_model_scope_and_evaluator_produce_consistent_results(): void
    {
        $campaign = $this->createTestCampaign();

        FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'target_scope'             => 'global',
            'target_status'            => 'active',
            'perm_mcqs'                => 'allow',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['scope' => 'global'],
        ]);

        $evalTime = $this->nowUtc;

        // Test with scope
        $scopeIds = FreemiumCampaign::currentlyRunning($evalTime)
            ->where('status', 'active')
            ->pluck('id')
            ->all();

        // Test with evaluator query directly
        $evaluatorResult = $this->evaluator->resolveParticipatingCampaigns($this->student, $evalTime);
        $evaluatorIds = collect($evaluatorResult['participating'])->pluck('id')->all();

        $this->assertNotEmpty($scopeIds, "Scope should find active campaign");
        $this->assertNotEmpty($evaluatorIds, "Evaluator should find active campaign");
        $this->assertContains($campaign->id, $scopeIds, "Created campaign must be in scope query results");
        $this->assertContains($campaign->id, $evaluatorIds, "Created campaign must be in evaluator query results");
    }
}
