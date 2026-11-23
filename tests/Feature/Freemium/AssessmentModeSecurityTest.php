<?php

namespace Tests\Feature\Freemium;

use App\Models\Book;
use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumExamQuota;
use App\Models\Freemium\FreemiumQuotaConsumption;
use App\Models\Freemium\FreemiumQuotaCounter;
use App\Models\PracticeSession;
use App\Models\Test;
use App\Models\TestAttempt;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 1E.3.A / Phase 4A Backend Route & Security Hardening Tests.
 *
 * Verifies server-side assessment mode authorization, route segregation,
 * dynamic vs. official mock accounting, idempotency, and synthetic ID handling.
 */
class AssessmentModeSecurityTest extends TestCase
{
    use DatabaseTransactions;

    protected WebUser $student;
    protected WebUser $paidStudent;
    protected User $admin;

    protected int $programId = 994;
    protected int $subjectId = 1;
    protected int $bookId = 1;
    protected int $unitId = 1;

    protected int $questionId;
    protected int $correctOptionId;

    protected function setUp(): void
    {
        parent::setUp();

        $activeDb = DB::selectOne("SELECT DATABASE() as db")->db ?? '';
        if ($activeDb !== '3peas_db_subscription_test' && $activeDb !== '3peas_db') {
            $this->markTestSkipped("Safety gate: test only runs against local databases.");
        }

        $this->withoutMiddleware([
            \App\Http\Middleware\AttachJwtFromCookie::class,
            \App\Http\Middleware\AuthenticateJwtCookieGuard::class,
        ]);

        $adminId = DB::table('users')->value('id');
        $this->admin = $adminId ? User::find($adminId) : User::create([
            'name'     => 'Admin Security User',
            'email'    => 'admin_sec_' . uniqid() . '@example.com',
            'password' => bcrypt('Secret123'),
        ]);

        $this->student = WebUser::create([
            'name'     => 'Security Student',
            'email'    => 'sec_student_' . uniqid() . '@example.com',
            'password' => bcrypt('Secret123'),
            'role'     => 'student',
            'status'   => 'active',
        ]);

        $this->paidStudent = WebUser::create([
            'name'     => 'Paid Security Student',
            'email'    => 'paid_student_' . uniqid() . '@example.com',
            'password' => bcrypt('Secret123'),
            'role'     => 'student',
            'status'   => 'active',
        ]);

        UserSubscription::create([
            'user_id'            => $this->paidStudent->id,
            'offered_program_id' => $this->programId,
            'status'             => 'active',
            'payment_status'     => 'paid',
            'activated_at'       => now()->subDays(2),
            'expires_at'         => now()->addDays(30),
        ]);

        // Ensure program_subjects link exists and is marked free for legacy fallback
        DB::table('program_subjects')->updateOrInsert(
            ['offered_program_id' => $this->programId, 'subject_id' => $this->subjectId],
            ['is_free' => 1, 'updated_at' => now()]
        );

        $topicId = (int) (DB::table('book_unit_topic_tbl')->where('unit_id', $this->unitId)->value('id') ?? 1);

        $this->questionId = (int) DB::table('exam_question_tbl')->insertGetId([
            'question'      => 'Security Test MCQ ' . uniqid(),
            'topic_id'      => $topicId,
            'question_type' => 1,
            'is_mcq'        => 1,
            'activate'      => 1,
            'marks'         => 1.00,
            'difficulty'    => 1,
            'status'        => 'published',
            'source'        => 'manual',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $this->correctOptionId = (int) DB::table('exam_question_options_tbl')->insertGetId([
            'question_id' => $this->questionId,
            'option'      => 'Option A (Correct)',
            'is_answer'   => 1,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Seed 1 active 4-question scenario group to satisfy format 'all' 30% scenario requirement
        $scenarioGroupId = (int) DB::table('question_scenario_groups_tbl')->insertGetId([
            'title'         => 'Security Test Scenario Group',
            'scenario_text' => 'Background context for assessment mode security tests.',
            'activate'      => 1,
        ]);

        for ($i = 1; $i <= 4; $i++) {
            $scenQId = (int) DB::table('exam_question_tbl')->insertGetId([
                'question'                => 'Security Scenario MCQ ' . $i . ' ' . uniqid(),
                'topic_id'                => $topicId,
                'question_type'           => 1,
                'is_mcq'                  => 1,
                'scenario_group_id'       => $scenarioGroupId,
                'scenario_question_order' => $i,
                'activate'                => 1,
                'marks'                   => 1.00,
                'difficulty'              => 1,
                'status'                  => 'published',
                'source'                  => 'manual',
                'created_at'              => now(),
                'updated_at'              => now(),
            ]);

            DB::table('exam_question_options_tbl')->insert([
                'question_id' => $scenQId,
                'option'      => 'Option A (Correct)',
                'is_answer'   => 1,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    protected function createMockCampaign(int $timeLimitMinutes = 30): array
    {
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'Mock Security Campaign ' . uniqid(),
            'status'       => 'active',
            'priority'     => 1,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonth(),
            'is_stackable' => true,
        ]);

        $quota = FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'mock',
            'window_type'           => 'weekly',
            'max_tests'             => 5,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 2,
            'time_limit_minutes'    => $timeLimitMinutes,
        ]);

        FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'target_scope'             => 'global',
            'target_status'            => 'active',
            'perm_mcqs'                => 'allow',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['scope' => 'global'],
        ]);

        return [$campaign, $quota];
    }

    /**
     * 1. Dynamic full-book mock generation succeeds for an authorized student.
     */
    public function test_01_dynamic_full_book_mock_generation_succeeds_for_authorized_student(): void
    {
        $this->createMockCampaign();

        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-full-book-mock-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'total_questions'    => 10,
            ], ['X-Idempotency-Key' => 'key_' . uniqid()]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => 1,
                'data'    => [
                    'test_mode'       => 'mock',
                    'test_source'     => 'system',
                    'scope_type'      => 'full_book',
                    'total_questions' => 10,
                ],
            ]);
    }

    /**
     * 2. Dynamic mock persists test_mode = mock and test_source = system.
     */
    public function test_02_dynamic_mock_persists_test_mode_mock_and_test_source_system(): void
    {
        $this->createMockCampaign();

        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-full-book-mock-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'total_questions'    => 10,
            ], ['X-Idempotency-Key' => 'key_' . uniqid()]);

        $response->assertStatus(200);
        $testId = $response->json('data.test_id');

        $test = Test::findOrFail($testId);
        $this->assertEquals('mock', $test->test_mode);
        $this->assertEquals('system', $test->test_source);
        $this->assertEquals('full_book', $test->scope_type);
    }

    /**
     * 3. Dynamic mock consumes mock quota, not formal quota.
     */
    public function test_03_dynamic_mock_consumes_mock_quota_not_formal_quota(): void
    {
        $this->createMockCampaign();

        $mockCounterBefore = FreemiumQuotaCounter::where('user_id', $this->student->id)
            ->where('exam_type', 'mock')
            ->value('counter') ?? 0;

        $formalCounterBefore = FreemiumQuotaCounter::where('user_id', $this->student->id)
            ->where('exam_type', 'formal_test')
            ->value('counter') ?? 0;

        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-full-book-mock-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'total_questions'    => 10,
            ], ['X-Idempotency-Key' => 'key_' . uniqid()]);

        $response->assertStatus(200);

        $mockCounterAfter = FreemiumQuotaCounter::where('user_id', $this->student->id)
            ->where('exam_type', 'mock')
            ->value('counter') ?? 0;

        $formalCounterAfter = FreemiumQuotaCounter::where('user_id', $this->student->id)
            ->where('exam_type', 'formal_test')
            ->value('counter') ?? 0;

        $this->assertEquals($mockCounterBefore + 1, $mockCounterAfter, "Mock counter should increment by 1");
        $this->assertEquals($formalCounterBefore, $formalCounterAfter, "Formal test counter must not change");

        $consumption = FreemiumQuotaConsumption::where('user_id', $this->student->id)
            ->where('test_id', $response->json('data.test_id'))
            ->first();

        $this->assertNotNull($consumption);
        $this->assertEquals('mock', $consumption->exam_type);
        $this->assertEquals('generation', $consumption->event_type);
    }

    /**
     * 4. Formal generation persists test_mode = test and consumes formal quota.
     */
    public function test_04_formal_generation_persists_test_mode_test_and_consumes_formal_quota(): void
    {
        $formalCounterBefore = FreemiumQuotaCounter::where('user_id', $this->student->id)
            ->where('exam_type', 'formal_test')
            ->value('counter') ?? 0;

        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-full-book-formal-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'total_questions'    => 10,
            ], ['X-Idempotency-Key' => 'key_' . uniqid()]);

        $response->assertStatus(200);
        $testId = $response->json('data.test_id');

        $test = Test::findOrFail($testId);
        $this->assertEquals('test', $test->test_mode);
        $this->assertEquals('system', $test->test_source);

        $formalCounterAfter = FreemiumQuotaCounter::where('user_id', $this->student->id)
            ->where('exam_type', 'formal_test')
            ->value('counter') ?? 0;

        $this->assertEquals($formalCounterBefore + 1, $formalCounterAfter);
    }

    /**
     * 5. Formal endpoint cannot be converted to mock by manipulating request fields.
     */
    public function test_05_formal_endpoint_cannot_be_converted_to_mock_by_manipulating_request_fields(): void
    {
        $mockCounterBefore = FreemiumQuotaCounter::where('user_id', $this->student->id)
            ->where('exam_type', 'mock')
            ->value('counter') ?? 0;

        // 1. Full-book formal endpoint with requested_mode='mock' is explicitly rejected (422) (F-02)
        $resFull = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-full-book-formal-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'total_questions'    => 10,
                'requested_mode'     => 'mock',
            ], ['X-Idempotency-Key' => 'key_' . uniqid()]);

        $resFull->assertStatus(422)
            ->assertJson([
                'success'    => 0,
                'error_code' => 'INVALID_ASSESSMENT_SCOPE',
            ]);

        // 2. Full-book formal endpoint with mode='mock' is also rejected (422)
        $resFullMode = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-full-book-formal-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'total_questions'    => 10,
                'mode'               => 'mock',
            ], ['X-Idempotency-Key' => 'key_' . uniqid()]);

        $resFullMode->assertStatus(422)
            ->assertJson([
                'success'    => 0,
                'error_code' => 'INVALID_ASSESSMENT_SCOPE',
            ]);

        // 3. Chapter-level formal endpoint with requested_mode='mock' is explicitly rejected (422)
        $resSelected = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-selected-units-formal-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 10,
                'requested_mode'     => 'mock',
            ], ['X-Idempotency-Key' => 'key_' . uniqid()]);

        $resSelected->assertStatus(422)
            ->assertJson([
                'success'    => 0,
                'error_code' => 'INVALID_ASSESSMENT_SCOPE',
            ]);

        $mockCounterAfter = FreemiumQuotaCounter::where('user_id', $this->student->id)
            ->where('exam_type', 'mock')
            ->value('counter') ?? 0;

        $this->assertEquals($mockCounterBefore, $mockCounterAfter, "Formal test must never consume mock quota");
    }

    /**
     * 6. Mock endpoint cannot create a formal assessment.
     */
    public function test_06_mock_endpoint_cannot_create_formal_assessment(): void
    {
        $this->createMockCampaign();

        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-full-book-mock-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'total_questions'    => 10,
                'requested_mode'     => 'formal_test',
                'mode'               => 'test',
            ], ['X-Idempotency-Key' => 'key_' . uniqid()]);

        $response->assertStatus(200);
        $testId = $response->json('data.test_id');

        $test = Test::findOrFail($testId);
        $this->assertEquals('mock', $test->test_mode, "Mock endpoint must strictly enforce test_mode = mock");
    }

    /**
     * 7. Chapter-level mock generation is rejected.
     */
    public function test_07_chapter_level_mock_generation_is_rejected(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-selected-units-formal-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 10,
                'mode'               => 'mock',
            ], ['X-Idempotency-Key' => 'key_' . uniqid()]);

        $response->assertStatus(422)
            ->assertJson([
                'success'    => 0,
                'error_code' => 'INVALID_ASSESSMENT_SCOPE',
            ]);
    }

    /**
     * 8. Incomplete full-book syllabus coverage is rejected.
     */
    public function test_08_incomplete_full_book_syllabus_coverage_is_rejected(): void
    {
        $this->createMockCampaign();

        // Ensure there are at least two active units for the book so passing a subset is incomplete
        DB::table('book_unit_tbl')->insertGetId([
            'book_id'    => $this->bookId,
            'unit_no'    => 99,
            'unit_name'  => 'Extra Unit ' . uniqid(),
            'activate'   => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-full-book-mock-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'unit_ids'           => [$this->unitId], // Incomplete: only 1 unit
                'total_questions'    => 10,
            ], ['X-Idempotency-Key' => 'key_' . uniqid()]);

        $response->assertStatus(422)
            ->assertJson([
                'success'    => 0,
                'error_code' => 'INVALID_ASSESSMENT_SCOPE',
            ]);
    }

    /**
     * 9. Invalid or out-of-range question counts fail safely.
     */
    public function test_09_invalid_or_out_of_range_question_counts_fail_safely(): void
    {
        $this->createMockCampaign();

        // Too few questions (< 10)
        $resLow = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-full-book-mock-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'total_questions'    => 5,
            ], ['X-Idempotency-Key' => 'key_' . uniqid()]);

        $resLow->assertStatus(422);

        // Too many questions (> 200)
        $resHigh = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-full-book-mock-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'total_questions'    => 250,
            ], ['X-Idempotency-Key' => 'key_' . uniqid()]);

        $resHigh->assertStatus(422);
    }

    /**
     * 10. Mock duration uses the approved backend configuration.
     */
    public function test_10_mock_duration_uses_approved_backend_configuration(): void
    {
        // Campaign specifies 45 minutes
        $this->createMockCampaign(timeLimitMinutes: 45);

        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-full-book-mock-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'total_questions'    => 10,
            ], ['X-Idempotency-Key' => 'key_' . uniqid()]);

        $response->assertStatus(200);
        $this->assertEquals(45, $response->json('data.time_limit_minutes'));
    }

    /**
     * 10a. Client-supplied mock duration is ignored. (F-01)
     */
    public function test_10a_client_supplied_mock_duration_is_ignored(): void
    {
        $this->createMockCampaign(timeLimitMinutes: 45);

        // Client attempts to supply arbitrary 999 minutes
        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-full-book-mock-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'total_questions'    => 10,
                'time_limit_minutes' => 999,
            ], ['X-Idempotency-Key' => 'key_' . uniqid()]);

        $response->assertStatus(200);
        $this->assertEquals(45, $response->json('data.time_limit_minutes'), 'Client-supplied duration must be completely ignored');
    }

    /**
     * 10b. Campaign duration takes precedence over system default. (F-01)
     */
    public function test_10b_campaign_duration_takes_precedence_over_system_default(): void
    {
        config(['freemium.mock_default_duration_minutes' => 60]);
        $this->createMockCampaign(timeLimitMinutes: 45);

        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-full-book-mock-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'total_questions'    => 10,
            ], ['X-Idempotency-Key' => 'key_' . uniqid()]);

        $response->assertStatus(200);
        $this->assertEquals(45, $response->json('data.time_limit_minutes'), 'Campaign duration (45) must take precedence over config default (60)');
    }

    /**
     * 10c. System default is used when campaign duration is absent. (F-01)
     */
    public function test_10c_system_default_is_used_when_campaign_duration_is_absent(): void
    {
        config(['freemium.mock_default_duration_minutes' => 75]);
        // Campaign with no quota time limit (null)
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'Null Duration Campaign ' . uniqid(),
            'status'       => 'active',
            'priority'     => 1,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonth(),
            'is_stackable' => true,
        ]);

        FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'mock',
            'window_type'           => 'weekly',
            'max_tests'             => 5,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 2,
            'time_limit_minutes'    => null, // unconfigured
        ]);

        FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'target_scope'             => 'global',
            'target_status'            => 'active',
            'perm_mcqs'                => 'allow',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['scope' => 'global'],
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-full-book-mock-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'total_questions'    => 10,
                'time_limit_minutes' => 999, // Should be ignored
            ], ['X-Idempotency-Key' => 'key_' . uniqid()]);

        $response->assertStatus(200);
        $this->assertEquals(75, $response->json('data.time_limit_minutes'), 'System default (75) must be used when campaign duration is null');
    }

    /**
     * 10d. Missing or invalid server duration returns 422 UNCONFIGURED_MOCK_DURATION. (F-01)
     */
    public function test_10d_missing_or_invalid_server_duration_returns_422_unconfigured_mock_duration(): void
    {
        config(['freemium.mock_default_duration_minutes' => null]);
        // Campaign with null duration
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'No Duration Campaign ' . uniqid(),
            'status'       => 'active',
            'priority'     => 1,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonth(),
            'is_stackable' => true,
        ]);

        FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'mock',
            'window_type'           => 'weekly',
            'max_tests'             => 5,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 2,
            'time_limit_minutes'    => null,
        ]);

        FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'target_scope'             => 'global',
            'target_status'            => 'active',
            'perm_mcqs'                => 'allow',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['scope' => 'global'],
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-full-book-mock-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'total_questions'    => 10,
                'time_limit_minutes' => 60, // Even if client provides it, server must fail closed
            ], ['X-Idempotency-Key' => 'key_' . uniqid()]);

        $response->assertStatus(422)
            ->assertJson([
                'success'    => 0,
                'error_code' => 'UNCONFIGURED_MOCK_DURATION',
            ]);

        // Also test with 0 or negative config
        config(['freemium.mock_default_duration_minutes' => 0]);
        $resZero = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-full-book-mock-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'total_questions'    => 10,
            ], ['X-Idempotency-Key' => 'key_' . uniqid()]);

        $resZero->assertStatus(422)
            ->assertJson([
                'success'    => 0,
                'error_code' => 'UNCONFIGURED_MOCK_DURATION',
            ]);
    }

    /**
     * 11. Missing offered_program_id works for an authorized Freemium resource.
     */
    public function test_11_missing_offered_program_id_works_for_authorized_freemium_resource(): void
    {
        $this->createMockCampaign();

        // Omit offered_program_id completely
        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-full-book-mock-test', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'total_questions' => 10,
            ], ['X-Idempotency-Key' => 'key_' . uniqid()]);

        $response->assertStatus(200);
        $this->assertNull($response->json('data.offered_program_id'));

        $test = Test::findOrFail($response->json('data.test_id'));
        $this->assertNull($test->offered_program_id);
    }

    /**
     * 12. Invalid, zero, negative, or nonexistent offered-program IDs are rejected.
     */
    public function test_12_invalid_zero_negative_or_nonexistent_program_ids_are_rejected(): void
    {
        $this->createMockCampaign();

        // Zero
        $resZero = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-full-book-mock-test', [
                'offered_program_id' => 0,
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'total_questions'    => 10,
            ], ['X-Idempotency-Key' => 'key_' . uniqid()]);
        $resZero->assertStatus(422);

        // Negative synthetic ID
        $resNeg = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-full-book-mock-test', [
                'offered_program_id' => -11,
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'total_questions'    => 10,
            ], ['X-Idempotency-Key' => 'key_' . uniqid()]);
        $resNeg->assertStatus(422);

        // Nonexistent ID
        $resNonExist = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-full-book-mock-test', [
                'offered_program_id' => 9999999,
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'total_questions'    => 10,
            ], ['X-Idempotency-Key' => 'key_' . uniqid()]);
        $resNonExist->assertStatus(422);
    }

    /**
     * 13. Academic resource scope validation remains enforced.
     */
    public function test_13_academic_resource_scope_validation_remains_enforced(): void
    {
        $this->createMockCampaign();

        // Nonexistent book
        $resBadBook = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-full-book-mock-test', [
                'subject_id'      => $this->subjectId,
                'book_id'         => 9999999,
                'total_questions' => 10,
            ], ['X-Idempotency-Key' => 'key_' . uniqid()]);
        $resBadBook->assertStatus(422);

        // Nonexistent subject
        $resBadSub = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-full-book-mock-test', [
                'subject_id'      => 9999999,
                'book_id'         => $this->bookId,
                'total_questions' => 10,
            ], ['X-Idempotency-Key' => 'key_' . uniqid()]);
        $resBadSub->assertStatus(422);
    }

    /**
     * 14. Practice generation remains bound to practice quota.
     */
    public function test_14_practice_generation_remains_bound_to_practice_quota(): void
    {
        $mockBefore = FreemiumQuotaCounter::where('user_id', $this->student->id)
            ->where('exam_type', 'mock')
            ->value('counter') ?? 0;

        $formalBefore = FreemiumQuotaCounter::where('user_id', $this->student->id)
            ->where('exam_type', 'formal_test')
            ->value('counter') ?? 0;

        $practiceBefore = FreemiumQuotaCounter::where('user_id', $this->student->id)
            ->where('exam_type', 'practice')
            ->value('counter') ?? 0;

        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-full-book-practice-session', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'total_questions'    => 10,
            ], ['X-Idempotency-Key' => 'key_' . uniqid()]);

        $response->assertStatus(200);
        $sessionId = $response->json('data.session_id');
        $this->assertNotNull($sessionId);

        $this->assertNotNull(PracticeSession::find($sessionId));

        $mockAfter = FreemiumQuotaCounter::where('user_id', $this->student->id)
            ->where('exam_type', 'mock')
            ->value('counter') ?? 0;

        $formalAfter = FreemiumQuotaCounter::where('user_id', $this->student->id)
            ->where('exam_type', 'formal_test')
            ->value('counter') ?? 0;

        $practiceAfter = FreemiumQuotaCounter::where('user_id', $this->student->id)
            ->where('exam_type', 'practice')
            ->value('counter') ?? 0;

        $this->assertEquals($mockBefore, $mockAfter, "Mock quota must not be touched");
        $this->assertEquals($formalBefore, $formalAfter, "Formal quota must not be touched");
        $this->assertEquals($practiceBefore + 1, $practiceAfter, "Practice quota must increment by 1");
    }

    /**
     * 15. Official shared mock attempts do not consume dynamic mock-generation quota.
     */
    public function test_15_official_shared_mock_attempts_do_not_consume_dynamic_mock_generation_quota(): void
    {
        $this->createMockCampaign();

        // Create official shared mock
        $officialMock = Test::create([
            'offered_program_id' => $this->programId,
            'subject_id'         => $this->subjectId,
            'created_by'         => $this->admin->id,
            'title'              => 'Board Official Mock Exam',
            'test_source'        => 'official',
            'test_mode'          => 'mock',
            'scope_type'         => 'full_book',
            'time_limit_minutes' => 60,
            'total_questions'    => 1,
            'is_published'       => true,
            'published_at'       => now(),
        ]);

        DB::table('test_questions')->insert([
            'test_id'        => $officialMock->id,
            'question_id'    => $this->questionId,
            'question_order' => 1,
            'marks'          => 1.0,
        ]);

        DB::table('test_scopes')->insert([
            'test_id' => $officialMock->id,
            'unit_id' => $this->unitId,
        ]);

        $mockGenCounterBefore = FreemiumQuotaCounter::where('user_id', $this->student->id)
            ->where('exam_type', 'mock')
            ->value('counter') ?? 0;

        // Student attempts question on official mock
        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $officialMock->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);

        $response->assertStatus(200);

        $mockGenCounterAfter = FreemiumQuotaCounter::where('user_id', $this->student->id)
            ->where('exam_type', 'mock')
            ->value('counter') ?? 0;

        $this->assertEquals($mockGenCounterBefore, $mockGenCounterAfter, "Official mock attempt must not consume dynamic generation quota");

        $consumption = FreemiumQuotaConsumption::where('user_id', $this->student->id)
            ->where('test_id', $officialMock->id)
            ->first();

        $this->assertNotNull($consumption);
        $this->assertEquals('attempt_start', $consumption->event_type);
        $this->assertEquals('mock', $consumption->exam_type);
    }

    /**
     * 16. Official mock attempt limits and retakes remain correct.
     */
    public function test_16_official_mock_attempt_limits_and_retakes_remain_correct(): void
    {
        // Campaign allows max 1 attempt, no retakes
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'Strict Mock Campaign ' . uniqid(),
            'status'       => 'active',
            'priority'     => 1,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonth(),
            'is_stackable' => true,
        ]);

        FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'mock',
            'window_type'           => 'weekly',
            'max_tests'             => 5,
            'allow_retakes'         => false,
            'max_attempts_per_test' => 1,
        ]);

        FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'target_scope'             => 'global',
            'target_status'            => 'active',
            'perm_mcqs'                => 'allow',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['scope' => 'global'],
        ]);

        $officialMock = Test::create([
            'offered_program_id' => $this->programId,
            'subject_id'         => $this->subjectId,
            'created_by'         => $this->admin->id,
            'title'              => 'Strict Official Mock Exam',
            'test_source'        => 'official',
            'test_mode'          => 'mock',
            'scope_type'         => 'full_book',
            'time_limit_minutes' => 60,
            'total_questions'    => 1,
            'is_published'       => true,
            'published_at'       => now(),
        ]);

        DB::table('test_questions')->insert([
            'test_id'        => $officialMock->id,
            'question_id'    => $this->questionId,
            'question_order' => 1,
            'marks'          => 1.0,
        ]);

        DB::table('test_scopes')->insert([
            'test_id' => $officialMock->id,
            'unit_id' => $this->unitId,
        ]);

        // First attempt succeeds
        $res1 = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $officialMock->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);
        $res1->assertStatus(200);

        // Submit first attempt
        $attemptId = $res1->json('data.attempt_id');
        $submitRes = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/submit-formal-test', [
                'test_id'    => $officialMock->id,
                'attempt_id' => $attemptId,
            ]);
        $submitRes->assertStatus(200);

        // Second attempt must fail with 403
        $res2 = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $officialMock->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);
        $res2->assertStatus(403);
    }

    /**
     * 17. Duplicate generation requests do not consume quota twice.
     */
    public function test_17_duplicate_generation_requests_do_not_consume_quota_twice(): void
    {
        $this->createMockCampaign();

        $key = 'idempotent_mock_' . uniqid();
        $payload = [
            'offered_program_id' => $this->programId,
            'subject_id'         => $this->subjectId,
            'book_id'            => $this->bookId,
            'total_questions'    => 10,
        ];

        // First request
        $res1 = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-full-book-mock-test', $payload, ['X-Idempotency-Key' => $key]);
        $res1->assertStatus(200);
        $testId1 = $res1->json('data.test_id');

        $counterAfterFirst = FreemiumQuotaCounter::where('user_id', $this->student->id)
            ->where('exam_type', 'mock')
            ->value('counter');

        // Retry with exact same key and payload
        $res2 = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-full-book-mock-test', $payload, ['X-Idempotency-Key' => $key]);
        $res2->assertStatus(200);
        $testId2 = $res2->json('data.test_id');

        $this->assertEquals($testId1, $testId2, "Idempotent retry must replay identical test_id");

        $counterAfterSecond = FreemiumQuotaCounter::where('user_id', $this->student->id)
            ->where('exam_type', 'mock')
            ->value('counter');

        $this->assertEquals($counterAfterFirst, $counterAfterSecond, "Quota counter must not increment on replay");
    }

    /**
     * 18. Existing paid formal-generation workflows continue to work.
     */
    public function test_18_existing_paid_formal_generation_workflows_continue_to_work(): void
    {
        $response = $this->actingAs($this->paidStudent, 'web_api')
            ->postJson('/web_api/auth/tests/generate-full-book-formal-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'total_questions'    => 10,
            ], ['X-Idempotency-Key' => 'key_' . uniqid()]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => 1,
                'data'    => [
                    'test_mode'   => 'test',
                    'test_source' => 'system',
                ],
            ]);

        // Paid student must NOT generate any freemium quota consumption records
        $consumptionCount = FreemiumQuotaConsumption::where('user_id', $this->paidStudent->id)->count();
        $this->assertEquals(0, $consumptionCount, "Paid student must bypass freemium quota consumption entirely");
    }
}
