<?php

namespace Tests\Feature\Freemium;

use App\Http\Services\Freemium\Exceptions\IdempotencyConflictException;
use App\Http\Services\Freemium\Exceptions\QuotaLimitExceededException;
use App\Http\Services\Freemium\FreemiumAccessRuleEvaluator;
use App\Http\Services\Freemium\FreemiumQuotaConsumptionService;
use App\Http\Services\Freemium\FreemiumQuotaWindow;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumExamQuota;
use App\Models\Freemium\FreemiumQuotaConsumption;
use App\Models\Freemium\FreemiumQuotaConsumptionEnvelope;
use App\Models\Freemium\FreemiumQuotaCounter;
use App\Models\Freemium\FreemiumStudentGrant;
use App\Models\PracticeSession;
use App\Models\Test;
use App\Models\User;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FreemiumQuotaConsumptionServiceTest extends TestCase
{
    private FreemiumQuotaConsumptionService $quotaService;
    private WebUser $student;
    private User $admin;
    private Test $formalTest;
    private PracticeSession $practiceSession;
    private FreemiumCampaign $campaign;
    private FreemiumExamQuota $dailyQuota;
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $evaluator = new FreemiumAccessRuleEvaluator();
        $this->quotaService = new FreemiumQuotaConsumptionService($evaluator);

        $this->now = Carbon::parse('2026-11-18 10:00:00', 'UTC'); // 15:00 PKT on Nov 18

        $adminId = DB::table('users')->value('id');
        $this->admin = $adminId ? User::find($adminId) : User::create([
            'name'     => 'Quota Test Admin',
            'email'    => 'admin_quota_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
        ]);

        $studentId = DB::table('web_users')->insertGetId([
            'name'       => 'Quota Test Student',
            'email'      => 'student_quota_' . uniqid() . '@example.com',
            'password'   => bcrypt('secret'),
            'role'       => 'student',
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->student = WebUser::find($studentId);

        $testId = DB::table('tests')->value('id');
        $this->formalTest = $testId ? Test::find($testId) : Test::create([
            'title'      => 'Quota Formal Test',
            'test_mode'  => 'test',
            'scope_type' => 'chapter',
        ]);

        $practiceId = DB::table('practice_sessions')->insertGetId([
            'user_id'    => $this->student->id,
            'scope_type' => 'chapter',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->practiceSession = PracticeSession::find($practiceId);

        $this->campaign = FreemiumCampaign::create([
            'name'         => 'Quota Verification Campaign ' . uniqid(),
            'status'       => 'active',
            'is_stackable' => true,
            'priority'     => 10,
            'start_at'     => $this->now->copy()->subDays(5),
            'end_at'       => $this->now->copy()->addDays(25),
            'created_by'   => $this->admin->id,
        ]);

        $this->dailyQuota = FreemiumExamQuota::create([
            'campaign_id'           => $this->campaign->id,
            'exam_type'             => 'formal_test',
            'window_type'           => 'daily',
            'max_tests'             => 2,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 2,
            'time_limit_minutes'    => 60,
        ]);
    }

    protected function tearDown(): void
    {
        FreemiumQuotaConsumptionEnvelope::query()->delete();
        FreemiumQuotaConsumption::query()->delete();
        FreemiumQuotaCounter::where('user_id', $this->student->id)->delete();
        FreemiumStudentGrant::query()->forceDelete();
        FreemiumExamQuota::query()->forceDelete();
        FreemiumCampaign::query()->forceDelete();

        DB::table('practice_sessions')->where('id', $this->practiceSession->id)->delete();
        DB::table('web_users')->where('id', $this->student->id)->delete();

        parent::tearDown();
    }

    private function createTestFixture(): Test
    {
        return Test::create([
            'title'      => 'Quota Formal Test ' . uniqid(),
            'test_mode'  => 'test',
            'scope_type' => 'chapter',
        ]);
    }

    /**
     * Test window key resolution across all approved quota window types.
     */
    public function test_window_key_resolution_formats(): void
    {
        // 2026-11-18 10:00:00 UTC is Wednesday 2026-11-18 15:00:00 PKT
        $keyDaily   = FreemiumQuotaWindow::resolveWindowKey('daily', $this->campaign->id, $this->now);
        $keyWeekly  = FreemiumQuotaWindow::resolveWindowKey('weekly', $this->campaign->id, $this->now);
        $keyMonthly = FreemiumQuotaWindow::resolveWindowKey('monthly', $this->campaign->id, $this->now);
        $keyAnnual  = FreemiumQuotaWindow::resolveWindowKey('annual', $this->campaign->id, $this->now);
        $keyCamp    = FreemiumQuotaWindow::resolveWindowKey('campaign', $this->campaign->id, $this->now);
        $keyLife    = FreemiumQuotaWindow::resolveWindowKey('lifetime', $this->campaign->id, $this->now);

        $this->assertEquals('PK:2026-11-18', $keyDaily);
        $this->assertEquals('PK:2026-W47', $keyWeekly);
        $this->assertEquals('PK:2026-11', $keyMonthly);
        $this->assertEquals('PK:2026', $keyAnnual);
        $this->assertEquals('campaign_' . $this->campaign->id, $keyCamp);
        $this->assertEquals('lifetime', $keyLife);
    }

    /**
     * Test calendar boundaries in Asia/Karachi converted to UTC range.
     */
    public function test_window_utc_boundaries(): void
    {
        // Daily window on Nov 18, 2026 PKT:
        // Local: 2026-11-18 00:00:00 PKT to 2026-11-19 00:00:00 PKT
        // UTC:   2026-11-17 19:00:00 UTC to 2026-11-18 19:00:00 UTC
        [$startUtc, $endUtc] = FreemiumQuotaWindow::resolveWindowUtcBounds('daily', $this->campaign, $this->now);

        $this->assertEquals('2026-11-17 19:00:00', $startUtc->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-18 19:00:00', $endUtc->format('Y-m-d H:i:s'));
    }

    /**
     * Test generation quota consumption and exhaustion (limit = 2).
     */
    public function test_generation_quota_consumption_and_exhaustion(): void
    {
        $test1 = $this->createTestFixture();
        $test2 = $this->createTestFixture();
        $test3 = $this->createTestFixture();

        // Attempt 1: succeeds (counter becomes 1)
        $c1 = $this->quotaService->consumeQuota($this->student, 'formal_test', 'generation', [
            'test_id' => $test1->id,
            'now'     => $this->now,
        ]);

        $this->assertInstanceOf(FreemiumQuotaConsumption::class, $c1);
        $this->assertEquals(0, $c1->attempt_number);
        $this->assertDatabaseHas('freemium_quota_counters', [
            'user_id'     => $this->student->id,
            'exam_type'   => 'formal_test',
            'window_type' => 'daily',
            'window_key'  => 'PK:2026-11-18',
            'counter'     => 1,
        ]);

        // Attempt 2: succeeds (counter becomes 2, limit reached)
        $c2 = $this->quotaService->consumeQuota($this->student, 'formal_test', 'generation', [
            'test_id' => $test2->id,
            'now'     => $this->now,
        ]);
        $this->assertEquals(0, $c2->attempt_number);
        $this->assertDatabaseHas('freemium_quota_counters', [
            'user_id'     => $this->student->id,
            'counter'     => 2,
        ]);

        // Attempt 3: Exhausted! Expect QuotaLimitExceededException
        $this->expectException(QuotaLimitExceededException::class);
        $this->expectExceptionMessage("Quota limit (2) reached for daily window 'PK:2026-11-18'");

        $this->quotaService->consumeQuota($this->student, 'formal_test', 'generation', [
            'test_id' => $test3->id,
            'now'     => $this->now,
        ]);
    }

    /**
     * Test unlimited quota (max_tests = null or -1).
     */
    public function test_unlimited_quota(): void
    {
        $this->dailyQuota->update(['max_tests' => -1]);

        for ($i = 0; $i < 5; $i++) {
            $t = $this->createTestFixture();
            $consumption = $this->quotaService->consumeQuota($this->student, 'formal_test', 'generation', [
                'test_id' => $t->id,
                'now'     => $this->now,
            ]);
            $this->assertNotNull($consumption->id);
        }

        $this->assertDatabaseHas('freemium_quota_counters', [
            'user_id' => $this->student->id,
            'counter' => 5,
        ]);
    }

    /**
     * Test initial attempt vs retake attempt semantics.
     * Retakes do NOT increment test-generation counters, but enforce max_attempts_per_test.
     */
    public function test_attempt_and_retake_semantics(): void
    {
        // 1. Initial attempt start (attempt 1)
        $cAttempt1 = $this->quotaService->consumeQuota($this->student, 'formal_test', 'attempt_start', [
            'test_id'        => $this->formalTest->id,
            'attempt_number' => 1,
            'now'            => $this->now,
        ]);
        $this->assertEquals(1, $cAttempt1->attempt_number);

        // 2. Retake start (attempt 2, allowed because max_attempts_per_test = 2 and allow_retakes = true)
        $cAttempt2 = $this->quotaService->consumeQuota($this->student, 'formal_test', 'retake_start', [
            'test_id'        => $this->formalTest->id,
            'attempt_number' => 2,
            'now'            => $this->now,
        ]);
        $this->assertEquals(2, $cAttempt2->attempt_number);

        // 3. Attempt 3: Exceeds max_attempts_per_test (2) -> QuotaLimitExceededException
        $this->expectException(QuotaLimitExceededException::class);
        $this->expectExceptionMessage("Maximum permitted attempts (2) exceeded for this test.");

        $this->quotaService->consumeQuota($this->student, 'formal_test', 'retake_start', [
            'test_id'        => $this->formalTest->id,
            'attempt_number' => 3,
            'now'            => $this->now,
        ]);
    }

    /**
     * Test allow_retakes = false blocks retake attempts (attempt_number > 1).
     */
    public function test_allow_retakes_false_blocks_retake(): void
    {
        $this->dailyQuota->update(['allow_retakes' => false]);

        $this->expectException(QuotaLimitExceededException::class);
        $this->expectExceptionMessage("Retakes are not permitted for this test quota.");

        $this->quotaService->consumeQuota($this->student, 'formal_test', 'retake_start', [
            'test_id'        => $this->formalTest->id,
            'attempt_number' => 2,
            'now'            => $this->now,
        ]);
    }

    /**
     * Test grant-bounded temporal envelope:
     * [ max(campaign.start_at, grant.granted_at), min(campaign.end_at, grant.expires_at) )
     */
    public function test_grant_bounded_temporal_envelope(): void
    {
        $grantIssuedAt = $this->now->copy()->addDay(); // Issued tomorrow
        $grantExpiresAt = $this->now->copy()->addDays(10);

        $grant = FreemiumStudentGrant::create([
            'campaign_id'  => $this->campaign->id,
            'user_id'      => $this->student->id,
            'grant_type'   => 'voucher',
            'voucher_code' => 'VOUCHER-BOUNDED',
            'granted_at'   => $grantIssuedAt,
            'expires_at'   => $grantExpiresAt,
            'status'       => 'active',
            'created_by'   => $this->admin->id,
        ]);

        // At $this->now (before grant issuance): Grant must NOT participate!
        $testBefore = $this->createTestFixture();
        $consumptionBefore = $this->quotaService->consumeQuota($this->student, 'formal_test', 'generation', [
            'test_id' => $testBefore->id,
            'now'     => $this->now,
        ]);

        // Verify envelope does not link the unissued grant
        $envelopeBefore = FreemiumQuotaConsumptionEnvelope::where('consumption_id', $consumptionBefore->id)->first();
        $this->assertNull($envelopeBefore->grant_id);

        // At $grantIssuedAt + 1 hour (inside grant interval): Grant participates!
        $testDuring = $this->createTestFixture();
        $consumptionDuring = $this->quotaService->consumeQuota($this->student, 'formal_test', 'generation', [
            'test_id' => $testDuring->id,
            'now'     => $grantIssuedAt->copy()->addHour(),
        ]);

        $envelopeDuring = FreemiumQuotaConsumptionEnvelope::where('consumption_id', $consumptionDuring->id)->first();
        $this->assertEquals($grant->id, $envelopeDuring->grant_id);
    }

    /**
     * Test multi-campaign consumption envelopes:
     * When multiple stackable campaigns participate, an envelope is written for each.
     */
    public function test_multiple_participating_campaign_envelopes(): void
    {
        // Create second stackable campaign with equal priority
        $campaign2 = FreemiumCampaign::create([
            'name'         => 'Stackable Partner Campaign ' . uniqid(),
            'status'       => 'active',
            'is_stackable' => true,
            'priority'     => 10,
            'start_at'     => $this->now->copy()->subDays(5),
            'end_at'       => $this->now->copy()->addDays(20),
            'created_by'   => $this->admin->id,
        ]);

        $quota2 = FreemiumExamQuota::create([
            'campaign_id'           => $campaign2->id,
            'exam_type'             => 'formal_test',
            'window_type'           => 'monthly',
            'max_tests'             => 10,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 2,
        ]);

        $t = $this->createTestFixture();
        $consumption = $this->quotaService->consumeQuota($this->student, 'formal_test', 'generation', [
            'test_id'                 => $t->id,
            'primary_campaign'        => $this->campaign,
            'participating_campaigns' => [$this->campaign, $campaign2],
            'now'                     => $this->now,
        ]);

        // Verify 2 normalized envelopes were written for this single consumption
        $envelopes = FreemiumQuotaConsumptionEnvelope::where('consumption_id', $consumption->id)->get();
        $this->assertCount(2, $envelopes);

        $campaignIds = $envelopes->pluck('campaign_id')->all();
        $this->assertContains($this->campaign->id, $campaignIds);
        $this->assertContains($campaign2->id, $campaignIds);

        // Clean up campaign 2
        FreemiumQuotaConsumptionEnvelope::where('campaign_id', $campaign2->id)->delete();
        $quota2->forceDelete();
        $campaign2->forceDelete();
    }

    /**
     * Test idempotency: Same key + same payload replays original consumption record.
     */
    public function test_idempotent_replay_with_same_payload(): void
    {
        $idempotencyKey = 'idem_replay_' . uniqid();
        $payload = [
            'student_id' => $this->student->id,
            'test_id'    => $this->formalTest->id,
            'timestamp'  => '2026-11-18 10:00:00',
        ];

        // First call
        $first = $this->quotaService->consumeQuota($this->student, 'formal_test', 'generation', [
            'test_id'         => $this->formalTest->id,
            'idempotency_key' => $idempotencyKey,
            'request_payload' => $payload,
            'now'             => $this->now,
        ]);

        // Second call with identical key and payload -> Returns original record without double increment
        $second = $this->quotaService->consumeQuota($this->student, 'formal_test', 'generation', [
            'test_id'         => $this->formalTest->id,
            'idempotency_key' => $idempotencyKey,
            'request_payload' => $payload,
            'now'             => $this->now,
        ]);

        $this->assertEquals($first->id, $second->id);

        // Verify counter was only incremented ONCE
        $this->assertDatabaseHas('freemium_quota_counters', [
            'user_id' => $this->student->id,
            'counter' => 1,
        ]);
    }

    /**
     * Test idempotency conflict: Same key with different payload throws IdempotencyConflictException.
     */
    public function test_idempotency_conflict_with_different_payload(): void
    {
        $idempotencyKey = 'idem_conflict_' . uniqid();

        $payloadA = ['action' => 'generate_exam', 'difficulty' => 'easy'];
        $payloadB = ['action' => 'generate_exam', 'difficulty' => 'hard'];

        $this->quotaService->consumeQuota($this->student, 'formal_test', 'generation', [
            'test_id'         => $this->formalTest->id,
            'idempotency_key' => $idempotencyKey,
            'request_payload' => $payloadA,
            'now'             => $this->now,
        ]);

        $this->expectException(IdempotencyConflictException::class);
        $this->expectExceptionMessage("Idempotency key '{$idempotencyKey}' reused with conflicting request payload.");

        $this->quotaService->consumeQuota($this->student, 'formal_test', 'generation', [
            'test_id'         => $this->formalTest->id,
            'idempotency_key' => $idempotencyKey,
            'request_payload' => $payloadB,
            'now'             => $this->now,
        ]);
    }
}
