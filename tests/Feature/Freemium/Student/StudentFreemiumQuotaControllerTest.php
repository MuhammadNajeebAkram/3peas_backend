<?php

namespace Tests\Feature\Freemium\Student;

use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumExamQuota;
use App\Models\Freemium\FreemiumQuotaConsumption;
use App\Models\Freemium\FreemiumQuotaCounter;
use App\Models\Test;
use App\Models\TestAttempt;
use App\Models\User;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudentFreemiumQuotaControllerTest extends TestCase
{
    private WebUser $student;
    private WebUser $otherStudent;
    private User $admin;
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = Carbon::parse('2026-11-18 12:00:00', 'UTC');

        // Admin fixture
        $adminId = DB::table('users')->value('id');
        $this->admin = $adminId ? User::find($adminId) : User::create([
            'name'     => 'Quota Test Admin',
            'email'    => 'admin_quota_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
        ]);

        // Student fixture
        $studentId = DB::table('web_users')->insertGetId([
            'name'       => 'Quota Student One',
            'email'      => 'student_quota_one_' . uniqid() . '@example.com',
            'password'   => bcrypt('secret'),
            'role'       => 'student',
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->student = WebUser::find($studentId);

        // Other student fixture (for isolation testing)
        $otherStudentId = DB::table('web_users')->insertGetId([
            'name'       => 'Quota Student Two',
            'email'      => 'student_quota_two_' . uniqid() . '@example.com',
            'password'   => bcrypt('secret'),
            'role'       => 'student',
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->otherStudent = WebUser::find($otherStudentId);
    }

    protected function tearDown(): void
    {
        DB::table('freemium_quota_consumption_envelopes')->delete();
        DB::table('freemium_quota_consumptions')->delete();
        DB::table('freemium_quota_counters')->delete();
        DB::table('freemium_exam_quotas')->delete();
        DB::table('freemium_student_grants')->delete();
        DB::table('freemium_campaigns')->delete();
        DB::table('test_attempts')->whereIn('user_id', [$this->student->id, $this->otherStudent->id])->delete();
        DB::table('tests')->whereIn('id', [444, 555, 666, 777, 888, 999])->delete();
        DB::table('web_users')->whereIn('id', [$this->student->id, $this->otherStudent->id])->delete();

        parent::tearDown();
    }

    private function studentHeaders(WebUser $student): array
    {
        $token = auth('web_api')->login($student);
        $student->update(['last_token' => hash('sha256', $token)]);
        return [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ];
    }

    /**
     * Unauthenticated request is rejected with 401.
     */
    public function test_unauthenticated_request_is_rejected_with_401(): void
    {
        $this->getJson('/web_api/auth/freemium/quota-allowances')->assertStatus(401);
        $this->getJson('/web_api/auth/freemium/tests/123/eligibility')->assertStatus(401);
    }

    /**
     * Non-student role is rejected with 403.
     */
    public function test_non_student_role_is_forbidden_with_403(): void
    {
        $this->student->update(['role' => 'teacher']);

        $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/quota-allowances')
            ->assertStatus(403)
            ->assertJson(['message' => 'Active student account required.']);
    }

    /**
     * Inactive student account is rejected with 403.
     */
    public function test_inactive_student_account_is_forbidden_with_403(): void
    {
        $this->student->update(['status' => 'blocked']);

        $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/quota-allowances')
            ->assertStatus(403)
            ->assertJson(['message' => 'Active student account required.']);
    }

    /**
     * Quota allowances endpoint returns active quotas, used counts, remaining balances, and window resets.
     */
    public function test_quota_allowances_returns_active_quotas_and_balances(): void
    {
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'STEM Campaign With Quotas',
            'status'       => 'active',
            'priority'     => 10,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonths(1),
            'is_stackable' => true,
        ]);

        // Practice quota: 5 daily
        FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'practice',
            'window_type'           => 'daily',
            'max_tests'             => 5,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 3,
        ]);

        // Formal test quota: 2 weekly
        FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'formal_test',
            'window_type'           => 'weekly',
            'max_tests'             => 2,
            'allow_retakes'         => false,
            'max_attempts_per_test' => 1,
        ]);

        // Pre-populate 1 used practice test counter
        $dailyKey = 'PK:' . now('Asia/Karachi')->format('Y-m-d');
        FreemiumQuotaCounter::create([
            'user_id'     => $this->student->id,
            'exam_type'   => 'practice',
            'window_type' => 'daily',
            'window_key'  => $dailyKey,
            'counter'     => 1,
        ]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/quota-allowances')
            ->assertStatus(200)
            ->assertJson(['success' => 1]);

        $quotas = $response->json('data.quotas');
        $this->assertCount(3, $quotas);

        $practice = collect($quotas)->firstWhere('exam_type', 'practice');
        $this->assertNotNull($practice);
        $this->assertSame('campaign', $practice['provider']);
        $this->assertSame(5, $practice['max_tests']);
        $this->assertSame(1, $practice['used_tests']);
        $this->assertSame(4, $practice['remaining_tests']);
        $this->assertTrue($practice['allow_retakes']);
        $this->assertSame(3, $practice['max_attempts_per_test']);
        $this->assertNotNull($practice['resets_at']);

        $formal = collect($quotas)->firstWhere('exam_type', 'formal_test');
        $this->assertNotNull($formal);
        $this->assertSame('campaign', $formal['provider']);
        $this->assertSame(2, $formal['max_tests']);
        $this->assertSame(0, $formal['used_tests']);
        $this->assertSame(2, $formal['remaining_tests']);
        $this->assertFalse($formal['allow_retakes']);

        // Stage 1E.2.D.D.6: Uncovered mock type is merged from legacy fallback
        $mock = collect($quotas)->firstWhere('exam_type', 'mock');
        $this->assertNotNull($mock);
        $this->assertSame('legacy_fallback', $mock['provider']);
        $this->assertSame(1, $mock['max_tests']);
        $this->assertSame(0, $mock['used_tests']);
        $this->assertSame(1, $mock['remaining_tests']);
        $this->assertFalse($mock['allow_retakes']);
    }

    /**
     * Quota allowances filtered by exam_type query parameter.
     */
    public function test_quota_allowances_filtered_by_exam_type(): void
    {
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'Filtered Quota Campaign',
            'status'       => 'active',
            'priority'     => 10,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonths(1),
            'is_stackable' => true,
        ]);

        FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'practice',
            'window_type'           => 'daily',
            'max_tests'             => 5,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 3,
        ]);

        FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'formal_test',
            'window_type'           => 'weekly',
            'max_tests'             => 2,
            'allow_retakes'         => false,
            'max_attempts_per_test' => 1,
        ]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/quota-allowances?exam_type=formal_test')
            ->assertStatus(200);

        $quotas = $response->json('data.quotas');
        $this->assertCount(1, $quotas);
        $this->assertSame('formal_test', $quotas[0]['exam_type']);
    }

    /**
     * Quota allowances invalid exam_type returns 422.
     */
    public function test_quota_allowances_invalid_exam_type_returns_422(): void
    {
        $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/quota-allowances?exam_type=invalid_type')
            ->assertStatus(422)
            ->assertJson(['success' => 0]);
    }

    /**
     * Read-only inspection: prove that querying allowances NEVER writes consumption rows or increments counters.
     */
    public function test_quota_allowances_is_strictly_read_only(): void
    {
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'Read Only Quota Campaign',
            'status'       => 'active',
            'priority'     => 10,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonths(1),
            'is_stackable' => true,
        ]);

        FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'practice',
            'window_type'           => 'daily',
            'max_tests'             => 10,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 3,
        ]);

        $initialConsumptions = FreemiumQuotaConsumption::count();
        $initialCounters = FreemiumQuotaCounter::count();

        // Call endpoint 5 times consecutively
        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders($this->studentHeaders($this->student))
                ->getJson('/web_api/auth/freemium/quota-allowances')
                ->assertStatus(200);
        }

        // Prove 0 rows were written
        $this->assertSame($initialConsumptions, FreemiumQuotaConsumption::count());
        $this->assertSame($initialCounters, FreemiumQuotaCounter::count());
    }

    /**
     * Test attempt eligibility for initial attempt.
     */
    public function test_test_eligibility_for_initial_attempt(): void
    {
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'Attempt Eligibility Campaign',
            'status'       => 'active',
            'priority'     => 10,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonths(1),
            'is_stackable' => true,
        ]);

        $quota = FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'formal_test',
            'window_type'           => 'weekly',
            'max_tests'             => 5,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 3,
        ]);

        // Student generated test 444
        $test = Test::firstOrCreate(
            ['id' => 444],
            [
                'created_by'    => $this->admin->id,
                'title'         => 'Unit Test 1',
                'test_source'   => 'system',
                'test_mode'     => 'test',
                'total_questions' => 10,
            ]
        );

        // Record generation consumption
        FreemiumQuotaConsumption::create([
            'user_id'             => $this->student->id,
            'exam_type'           => 'formal_test',
            'event_type'          => 'generation',
            'primary_campaign_id' => $campaign->id,
            'primary_quota_id'    => $quota->id,
            'test_id'             => $test->id,
            'attempt_number'      => 0,
            'decision_snapshot'   => ['granted' => true],
            'consumed_at'         => now(),
        ]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson("/web_api/auth/freemium/tests/{$test->id}/eligibility")
            ->assertStatus(200)
            ->assertJson(['success' => 1]);

        $data = $response->json('data');
        $this->assertTrue($data['can_attempt']);
        $this->assertFalse($data['is_retake']);
        $this->assertSame(1, $data['attempt_number']);
        $this->assertSame(2, $data['remaining_attempts']);
        $this->assertNull($data['denial_reason']);
    }

    /**
     * Test attempt eligibility for retake when permitted.
     */
    public function test_test_eligibility_for_permitted_retake(): void
    {
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'Retake Eligibility Campaign',
            'status'       => 'active',
            'priority'     => 10,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonths(1),
            'is_stackable' => true,
        ]);

        $quota = FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'formal_test',
            'window_type'           => 'weekly',
            'max_tests'             => 5,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 3,
        ]);

        $test = Test::firstOrCreate(
            ['id' => 555],
            [
                'created_by'      => $this->admin->id,
                'title'           => 'Unit Test 2',
                'test_source'     => 'system',
                'test_mode'       => 'test',
                'total_questions' => 10,
            ]
        );

        // Record attempt 1
        FreemiumQuotaConsumption::create([
            'user_id'             => $this->student->id,
            'exam_type'           => 'formal_test',
            'event_type'          => 'attempt_start',
            'primary_campaign_id' => $campaign->id,
            'primary_quota_id'    => $quota->id,
            'test_id'             => $test->id,
            'attempt_number'      => 1,
            'decision_snapshot'   => ['granted' => true],
            'consumed_at'         => now(),
        ]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson("/web_api/auth/freemium/tests/{$test->id}/eligibility")
            ->assertStatus(200);

        $data = $response->json('data');
        $this->assertTrue($data['can_attempt']);
        $this->assertTrue($data['is_retake']);
        $this->assertSame(2, $data['attempt_number']);
        $this->assertSame(2, $data['remaining_attempts']); // 3 max - 1 used = 2 remaining
    }

    /**
     * Security: Student cannot probe another student's test eligibility (returns 404).
     */
    public function test_test_eligibility_rejects_unowned_test_with_404(): void
    {
        // Unowned test ID
        $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/tests/9999999/eligibility')
            ->assertStatus(404)
            ->assertJson(['success' => 0, 'message' => 'Test not found or access denied.']);
    }

    /**
     * Security: Student cannot probe another student's generated test (returns 404).
     */
    public function test_test_eligibility_rejects_another_students_generated_test_with_404(): void
    {
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'Isolation Test Campaign',
            'status'       => 'active',
            'priority'     => 10,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonths(1),
            'is_stackable' => true,
        ]);

        $quota = FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'formal_test',
            'window_type'           => 'weekly',
            'max_tests'             => 5,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 3,
        ]);

        // Student One generates test 444
        $test = Test::firstOrCreate(
            ['id' => 444],
            [
                'created_by'      => $this->admin->id,
                'title'           => 'Student One Test',
                'test_source'     => 'system',
                'test_mode'       => 'test',
                'total_questions' => 10,
            ]
        );

        FreemiumQuotaConsumption::create([
            'user_id'             => $this->student->id,
            'exam_type'           => 'formal_test',
            'event_type'          => 'generation',
            'primary_campaign_id' => $campaign->id,
            'primary_quota_id'    => $quota->id,
            'test_id'             => $test->id,
            'attempt_number'      => 0,
            'decision_snapshot'   => ['granted' => true],
            'consumed_at'         => now(),
        ]);

        // Student Two tries to inspect Student One's test eligibility
        $response = $this->withHeaders($this->studentHeaders($this->otherStudent))
            ->getJson("/web_api/auth/freemium/tests/{$test->id}/eligibility")
            ->assertStatus(404)
            ->assertJson(['success' => 0, 'message' => 'Test not found or access denied.']);
    }

    /**
     * Ambiguous test without established ownership fails closed (returns 404).
     */
    public function test_test_eligibility_rejects_ambiguous_unowned_test_with_404(): void
    {
        // Test exists in tests table with test_source = system, but no consumption or attempt by anyone
        $test = Test::firstOrCreate(
            ['id' => 666],
            [
                'created_by'      => $this->admin->id,
                'title'           => 'Ambiguous Orphaned Test',
                'test_source'     => 'system',
                'test_mode'       => 'test',
                'total_questions' => 10,
                'is_published'    => true,
            ]
        );

        $this->withHeaders($this->studentHeaders($this->student))
            ->getJson("/web_api/auth/freemium/tests/{$test->id}/eligibility")
            ->assertStatus(404)
            ->assertJson(['success' => 0, 'message' => 'Test not found or access denied.']);
    }

    /**
     * Official published test is accessible for initial attempt without prior consumption.
     */
    public function test_test_eligibility_allows_official_published_test(): void
    {
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'Official Test Campaign',
            'status'       => 'active',
            'priority'     => 10,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonths(1),
            'is_stackable' => true,
        ]);

        FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'formal_test',
            'window_type'           => 'weekly',
            'max_tests'             => 5,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 3,
        ]);

        // Official published test
        $test = Test::firstOrCreate(
            ['id' => 777],
            [
                'created_by'      => $this->admin->id,
                'title'           => 'Official Board Mock 2026',
                'test_source'     => 'official',
                'test_mode'       => 'test',
                'total_questions' => 20,
                'is_published'    => true,
            ]
        );

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson("/web_api/auth/freemium/tests/{$test->id}/eligibility")
            ->assertStatus(200)
            ->assertJson(['success' => 1]);

        $data = $response->json('data');
        $this->assertTrue($data['can_attempt']);
        $this->assertFalse($data['is_retake']);
        $this->assertSame(1, $data['attempt_number']);
        $this->assertSame(2, $data['remaining_attempts']);
        $this->assertNull($data['denial_reason']);
    }

    /**
     * Retake blocked when allow_retakes is false.
     */
    public function test_test_eligibility_blocks_retake_when_allow_retakes_is_false(): void
    {
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'No Retake Campaign',
            'status'       => 'active',
            'priority'     => 10,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonths(1),
            'is_stackable' => true,
        ]);

        $quota = FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'formal_test',
            'window_type'           => 'weekly',
            'max_tests'             => 5,
            'allow_retakes'         => false,
            'max_attempts_per_test' => 1,
        ]);

        $test = Test::firstOrCreate(
            ['id' => 888],
            [
                'created_by'      => $this->admin->id,
                'title'           => 'Single Attempt Test',
                'test_source'     => 'system',
                'test_mode'       => 'test',
                'total_questions' => 10,
            ]
        );

        // Student One took attempt 1
        FreemiumQuotaConsumption::create([
            'user_id'             => $this->student->id,
            'exam_type'           => 'formal_test',
            'event_type'          => 'attempt_start',
            'primary_campaign_id' => $campaign->id,
            'primary_quota_id'    => $quota->id,
            'test_id'             => $test->id,
            'attempt_number'      => 1,
            'decision_snapshot'   => ['granted' => true],
            'consumed_at'         => now(),
        ]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson("/web_api/auth/freemium/tests/{$test->id}/eligibility")
            ->assertStatus(200);

        $data = $response->json('data');
        $this->assertFalse($data['can_attempt']);
        $this->assertTrue($data['is_retake']);
        $this->assertSame(0, $data['remaining_attempts']);
        $this->assertSame('Retakes are not permitted for this test.', $data['denial_reason']);
    }

    /**
     * Attempt blocked when max attempts reached.
     */
    public function test_test_eligibility_blocks_when_max_attempts_reached(): void
    {
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'Max Attempts Campaign',
            'status'       => 'active',
            'priority'     => 10,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonths(1),
            'is_stackable' => true,
        ]);

        $quota = FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'formal_test',
            'window_type'           => 'weekly',
            'max_tests'             => 5,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 2,
        ]);

        $test = Test::firstOrCreate(
            ['id' => 999],
            [
                'created_by'      => $this->admin->id,
                'title'           => 'Two Attempt Test',
                'test_source'     => 'system',
                'test_mode'       => 'test',
                'total_questions' => 10,
            ]
        );

        // Student One took attempt 1 and attempt 2
        FreemiumQuotaConsumption::create([
            'user_id'             => $this->student->id,
            'exam_type'           => 'formal_test',
            'event_type'          => 'attempt_start',
            'primary_campaign_id' => $campaign->id,
            'primary_quota_id'    => $quota->id,
            'test_id'             => $test->id,
            'attempt_number'      => 1,
            'decision_snapshot'   => ['granted' => true],
            'consumed_at'         => now()->subHour(),
        ]);

        FreemiumQuotaConsumption::create([
            'user_id'             => $this->student->id,
            'exam_type'           => 'formal_test',
            'event_type'          => 'retake_start',
            'primary_campaign_id' => $campaign->id,
            'primary_quota_id'    => $quota->id,
            'test_id'             => $test->id,
            'attempt_number'      => 2,
            'decision_snapshot'   => ['granted' => true],
            'consumed_at'         => now(),
        ]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson("/web_api/auth/freemium/tests/{$test->id}/eligibility")
            ->assertStatus(200);

        $data = $response->json('data');
        $this->assertFalse($data['can_attempt']);
        $this->assertTrue($data['is_retake']);
        $this->assertSame(0, $data['remaining_attempts']);
        $this->assertSame('Maximum permitted attempts (2) reached for this test.', $data['denial_reason']);
    }

    /**
     * Stage 1E.2.D.D.6: Unfiltered allowances merges campaign coverage with legacy fallback.
     */
    public function test_unfiltered_allowances_merges_campaign_coverage_with_legacy_fallback(): void
    {
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'Formal Only Campaign',
            'status'       => 'active',
            'priority'     => 15,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonths(1),
            'is_stackable' => true,
        ]);

        FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'formal_test',
            'window_type'           => 'weekly',
            'max_tests'             => 3,
            'allow_retakes'         => false,
            'max_attempts_per_test' => 1,
        ]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/quota-allowances')
            ->assertStatus(200)
            ->assertJson(['success' => 1]);

        $quotas = $response->json('data.quotas');
        $this->assertCount(3, $quotas);

        $formal = collect($quotas)->firstWhere('exam_type', 'formal_test');
        $this->assertNotNull($formal);
        $this->assertSame('campaign', $formal['provider']);
        $this->assertSame(3, $formal['max_tests']);

        $mock = collect($quotas)->firstWhere('exam_type', 'mock');
        $this->assertNotNull($mock);
        $this->assertSame('legacy_fallback', $mock['provider']);
        $this->assertSame(1, $mock['max_tests']);

        $practice = collect($quotas)->firstWhere('exam_type', 'practice');
        $this->assertNotNull($practice);
        $this->assertSame('legacy_fallback', $practice['provider']);
        $this->assertSame(2, $practice['max_tests']);
    }

    /**
     * Stage 1E.2.D.D.6: Campaign-covered types are not supplemented by legacy fallback (no duplicate exam_type).
     */
    public function test_campaign_covered_type_is_not_supplemented_by_legacy_fallback(): void
    {
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'All Three Campaign',
            'status'       => 'active',
            'priority'     => 10,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonths(1),
            'is_stackable' => true,
        ]);

        foreach (['formal_test', 'mock', 'practice'] as $type) {
            FreemiumExamQuota::create([
                'campaign_id'           => $campaign->id,
                'exam_type'             => $type,
                'window_type'           => 'weekly',
                'max_tests'             => 4,
                'allow_retakes'         => false,
                'max_attempts_per_test' => 1,
            ]);
        }

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/quota-allowances')
            ->assertStatus(200);

        $quotas = $response->json('data.quotas');
        $this->assertCount(3, $quotas);

        $types = array_column($quotas, 'exam_type');
        $this->assertSame(['formal_test', 'mock', 'practice'], $types);

        foreach ($quotas as $item) {
            $this->assertSame('campaign', $item['provider']);
            $this->assertSame(4, $item['max_tests']);
        }
    }

    /**
     * Stage 1E.2.D.D.6: Filtered query returns legacy fallback when campaign omits that type.
     */
    public function test_filtered_query_returns_legacy_fallback_when_campaign_omits_type(): void
    {
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'Practice Only Campaign',
            'status'       => 'active',
            'priority'     => 10,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonths(1),
            'is_stackable' => true,
        ]);

        FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'practice',
            'window_type'           => 'daily',
            'max_tests'             => 5,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 3,
        ]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/quota-allowances?exam_type=mock')
            ->assertStatus(200);

        $quotas = $response->json('data.quotas');
        $this->assertCount(1, $quotas);
        $this->assertSame('mock', $quotas[0]['exam_type']);
        $this->assertSame('legacy_fallback', $quotas[0]['provider']);
        $this->assertSame(1, $quotas[0]['max_tests']);
    }

    /**
     * Stage 1E.2.D.D.6: Allowances are deterministically ordered: formal_test -> mock -> practice.
     */
    public function test_deterministic_ordering_of_merged_allowances(): void
    {
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'Practice First Campaign',
            'status'       => 'active',
            'priority'     => 10,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonths(1),
            'is_stackable' => true,
        ]);

        FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'practice',
            'window_type'           => 'daily',
            'max_tests'             => 5,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 3,
        ]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/quota-allowances')
            ->assertStatus(200);

        $quotas = $response->json('data.quotas');
        $this->assertCount(3, $quotas);
        $this->assertSame('formal_test', $quotas[0]['exam_type']);
        $this->assertSame('legacy_fallback', $quotas[0]['provider']);
        $this->assertSame('mock', $quotas[1]['exam_type']);
        $this->assertSame('legacy_fallback', $quotas[1]['provider']);
        $this->assertSame('practice', $quotas[2]['exam_type']);
        $this->assertSame('campaign', $quotas[2]['provider']);
    }

    /**
     * Stage 1E.2.D.D.6: Zero-quota configuration is accurately represented as disabled.
     */
    public function test_zero_quota_configuration_is_represented_accurately(): void
    {
        config(['freemium.fallback_quotas.mock' => 0]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/quota-allowances?exam_type=mock')
            ->assertStatus(200);

        $quotas = $response->json('data.quotas');
        $this->assertCount(1, $quotas);
        $this->assertSame('mock', $quotas[0]['exam_type']);
        $this->assertSame(0, $quotas[0]['max_tests']);
        $this->assertSame(0, $quotas[0]['remaining_tests']);
        $this->assertSame('legacy_fallback', $quotas[0]['provider']);
    }

    /**
     * Stage 1E.2.D.D.6: Multiple overlapping campaigns preserve existing priority resolution rules.
     */
    public function test_multiple_overlapping_campaigns_preserve_existing_resolution_rules(): void
    {
        $campaignLow = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'Low Priority Campaign',
            'status'       => 'active',
            'priority'     => 50,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonths(1),
            'is_stackable' => true,
        ]);

        FreemiumExamQuota::create([
            'campaign_id'           => $campaignLow->id,
            'exam_type'             => 'formal_test',
            'window_type'           => 'weekly',
            'max_tests'             => 3,
            'allow_retakes'         => false,
            'max_attempts_per_test' => 1,
        ]);

        $campaignHigh = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'High Priority Campaign',
            'status'       => 'active',
            'priority'     => 10,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonths(1),
            'is_stackable' => true,
        ]);

        FreemiumExamQuota::create([
            'campaign_id'           => $campaignHigh->id,
            'exam_type'             => 'formal_test',
            'window_type'           => 'weekly',
            'max_tests'             => 7,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 2,
        ]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/quota-allowances')
            ->assertStatus(200);

        $quotas = $response->json('data.quotas');
        $this->assertCount(3, $quotas);

        $formal = collect($quotas)->firstWhere('exam_type', 'formal_test');
        $this->assertSame('campaign', $formal['provider']);
        $this->assertSame(7, $formal['max_tests']);
        $this->assertTrue($formal['allow_retakes']);
        $this->assertSame(2, $formal['max_attempts_per_test']);

        $mock = collect($quotas)->firstWhere('exam_type', 'mock');
        $this->assertSame('legacy_fallback', $mock['provider']);

        $practice = collect($quotas)->firstWhere('exam_type', 'practice');
        $this->assertSame('legacy_fallback', $practice['provider']);
    }

    /**
     * Stage 1E.2.D.D.6: Student with used quota counters accurately reflects used and remaining tests.
     */
    public function test_student_with_used_counters_reflects_accurate_used_and_remaining_tests(): void
    {
        $pkt = now('UTC')->setTimezone('Asia/Karachi');
        $windowKey = 'PK:legacy:weekly:' . $pkt->format('o-\WW');

        FreemiumQuotaCounter::create([
            'user_id'     => $this->student->id,
            'exam_type'   => 'mock',
            'window_type' => 'weekly',
            'window_key'  => $windowKey,
            'counter'     => 1,
        ]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/quota-allowances?exam_type=mock')
            ->assertStatus(200);

        $quotas = $response->json('data.quotas');
        $this->assertCount(1, $quotas);
        $this->assertSame('mock', $quotas[0]['exam_type']);
        $this->assertSame(1, $quotas[0]['max_tests']);
        $this->assertSame(1, $quotas[0]['used_tests']);
        $this->assertSame(0, $quotas[0]['remaining_tests']);
    }

    /**
     * Stage 1E.2.D.D.6: Paid student can retrieve standard freemium quota allowances without distortion.
     */
    public function test_paid_student_sees_freemium_allowances_without_distortion(): void
    {
        DB::table('user_subscriptions')->insert([
            'user_id'            => $this->student->id,
            'offered_program_id' => 99,
            'status'             => 'active',
            'started_at'         => now()->subDays(5)->toDateString(),
            'expires_at'         => now()->addMonths(1)->toDateString(),
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/quota-allowances')
            ->assertStatus(200)
            ->assertJson(['success' => 1]);

        $quotas = $response->json('data.quotas');
        $this->assertCount(3, $quotas);
        $types = array_column($quotas, 'exam_type');
        $this->assertSame(['formal_test', 'mock', 'practice'], $types);
    }
}
