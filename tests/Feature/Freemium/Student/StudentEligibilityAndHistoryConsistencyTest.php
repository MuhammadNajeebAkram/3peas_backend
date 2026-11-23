<?php

namespace Tests\Feature\Freemium\Student;

use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumExamQuota;
use App\Models\Freemium\FreemiumQuotaConsumption;
use App\Models\Freemium\FreemiumQuotaCounter;
use App\Models\PracticeSession;
use App\Models\Test;
use App\Models\TestAttempt;
use App\Models\TestAttemptQuestion;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stage 1E.2.D.D.3 — Eligibility and Attempt-History Consistency Feature Tests.
 *
 * Verifies consistency across student-facing assessment eligibility,
 * quota allowances, and attempt-history APIs.
 */
class StudentEligibilityAndHistoryConsistencyTest extends TestCase
{
    protected WebUser $student1;
    protected WebUser $student2;
    protected User $admin;

    protected int $programId = 1;
    protected int $otherProgramId = 2;
    protected int $subjectId = 1;
    protected int $unitId = 1;

    protected int $questionId;
    protected int $correctOptionId;
    protected int $wrongOptionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            \App\Http\Middleware\AttachJwtFromCookie::class,
            \App\Http\Middleware\AuthenticateJwtCookieGuard::class,
        ]);

        $adminId = DB::table('users')->value('id');
        $this->admin = $adminId ? User::find($adminId) : User::create([
            'name'     => 'Admin Consistency User',
            'email'    => 'admin_consist_' . uniqid() . '@example.com',
            'password' => bcrypt('Secret123'),
        ]);

        $this->student1 = WebUser::create([
            'name'     => 'Consistency Student One',
            'email'    => 'consist_student_1_' . uniqid() . '@example.com',
            'password' => bcrypt('Password123'),
            'role'     => 'student',
            'status'   => 'active',
        ]);

        $this->student2 = WebUser::create([
            'name'     => 'Consistency Student Two',
            'email'    => 'consist_student_2_' . uniqid() . '@example.com',
            'password' => bcrypt('Password123'),
            'role'     => 'student',
            'status'   => 'active',
        ]);

        $this->questionId = (int) DB::table('exam_question_tbl')->insertGetId([
            'question'      => 'Sample MCQ Question for Consistency ' . uniqid(),
            'topic_id'      => 1,
            'question_type' => 1,
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

        $this->wrongOptionId = (int) DB::table('exam_question_options_tbl')->insertGetId([
            'question_id' => $this->questionId,
            'option'      => 'Option B (Wrong)',
            'is_answer'   => 0,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('freemium_quota_consumption_envelopes')->delete();
        DB::table('freemium_quota_consumptions')->delete();
        DB::table('freemium_quota_counters')->delete();
        DB::table('freemium_exam_quotas')->delete();
        DB::table('freemium_access_rules')->delete();
        DB::table('freemium_student_grants')->delete();
        DB::table('freemium_campaigns')->delete();

        DB::table('test_attempt_questions')->delete();
        DB::table('test_attempts')->whereIn('user_id', [$this->student1->id, $this->student2->id])->delete();
        DB::table('practice_sessions')->whereIn('user_id', [$this->student1->id, $this->student2->id])->delete();
        DB::table('test_questions')->delete();
        DB::table('test_scopes')->delete();
        DB::table('tests')->whereIn('created_by', [$this->student1->id, $this->student2->id, $this->admin->id])->delete();

        DB::table('exam_question_options_tbl')->where('question_id', $this->questionId)->delete();
        DB::table('exam_question_tbl')->where('id', $this->questionId)->delete();

        DB::table('user_subscriptions')->whereIn('user_id', [$this->student1->id, $this->student2->id])->delete();
        DB::table('web_users')->whereIn('id', [$this->student1->id, $this->student2->id])->delete();

        parent::tearDown();
    }

    /**
     * 1. Eligibility agrees with actual attempt enforcement.
     */
    public function test_eligibility_agrees_with_actual_attempt_enforcement(): void
    {
        $this->createCampaign('formal_test', allowRetakes: true, maxAttempts: 2);
        $test = $this->createFormalTest(isOfficial: true);

        // Pre-check eligibility: must say can_attempt = true
        $elRes = $this->actingAs($this->student1, 'web_api')
            ->getJson("/web_api/auth/freemium/tests/{$test->id}/eligibility");
        $elRes->assertStatus(200);
        $this->assertTrue($elRes->json('data.can_attempt'));

        // Attempt actual progress save: must succeed (200 OK)
        $saveRes = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $test->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);
        $saveRes->assertStatus(200)->assertJson(['success' => 1]);
    }

    /**
     * 2. Eligibility correctly handles exhausted attempt allowances.
     */
    public function test_eligibility_correctly_handles_exhausted_attempt_allowances(): void
    {
        $this->createCampaign('formal_test', allowRetakes: true, maxAttempts: 1);
        $test = $this->createFormalTest(isOfficial: true);

        // Complete attempt 1 and submit
        $this->createCompletedAttempt($this->student1, $test, 1);

        // Eligibility must now indicate exhausted allowance
        $elRes = $this->actingAs($this->student1, 'web_api')
            ->getJson("/web_api/auth/freemium/tests/{$test->id}/eligibility");
        $elRes->assertStatus(200);
        $this->assertFalse($elRes->json('data.can_attempt'));
        $this->assertSame(0, $elRes->json('data.remaining_attempts'));
        $this->assertStringContainsString('Maximum permitted attempts', $elRes->json('data.denial_reason'));

        // Attempt endpoint must reject with 403 QUOTA_EXHAUSTED
        $saveRes = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $test->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);
        $saveRes->assertStatus(403)->assertJson(['error_code' => 'QUOTA_EXHAUSTED']);
    }

    /**
     * 3. Eligibility handles disabled retakes.
     */
    public function test_eligibility_handles_disabled_retakes(): void
    {
        $this->createCampaign('formal_test', allowRetakes: false, maxAttempts: 1);
        $test = $this->createFormalTest(isOfficial: true);

        $this->createCompletedAttempt($this->student1, $test, 1);

        $elRes = $this->actingAs($this->student1, 'web_api')
            ->getJson("/web_api/auth/freemium/tests/{$test->id}/eligibility");
        $elRes->assertStatus(200);
        $this->assertFalse($elRes->json('data.can_attempt'));
        $this->assertTrue($elRes->json('data.is_retake'));
        $this->assertSame('Retakes are not permitted for this test.', $elRes->json('data.denial_reason'));

        $saveRes = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $test->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);
        $saveRes->assertStatus(403)->assertJson(['error_code' => 'QUOTA_EXHAUSTED']);
    }

    /**
     * 4. Eligibility handles an active attempt without double counting.
     */
    public function test_eligibility_handles_an_active_attempt_without_double_counting(): void
    {
        $this->createCampaign('formal_test', allowRetakes: true, maxAttempts: 2);
        $test = $this->createFormalTest(isOfficial: true);

        // Start attempt 1 (status = in_progress)
        $startRes = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $test->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);
        $startRes->assertStatus(200);
        $attemptId = $startRes->json('data.attempt_id');

        // Check eligibility while attempt is in progress
        $elRes = $this->actingAs($this->student1, 'web_api')
            ->getJson("/web_api/auth/freemium/tests/{$test->id}/eligibility");
        $elRes->assertStatus(200);
        $data = $elRes->json('data');

        // Active attempt is detected and not double counted
        $this->assertTrue($data['can_attempt']); // 1 retake remaining
        $this->assertTrue($data['has_active_attempt']);
        $this->assertSame($attemptId, $data['active_attempt_id']);
        $this->assertSame(1, $data['remaining_attempts']); // 2 max - 1 used = 1 remaining
    }

    /**
     * 5. Paid students receive correct eligibility within scope.
     */
    public function test_paid_students_receive_correct_eligibility_within_scope(): void
    {
        $this->createPaidSubscription($this->student1, $this->programId, now()->addMonth());
        $test = $this->createFormalTest(isOfficial: true, programId: $this->programId);

        $elRes = $this->actingAs($this->student1, 'web_api')
            ->getJson("/web_api/auth/freemium/tests/{$test->id}/eligibility");
        $elRes->assertStatus(200);
        $data = $elRes->json('data');

        $this->assertTrue($data['can_attempt']);
        $this->assertTrue($data['is_paid']);
        $this->assertNull($data['max_attempts_per_test']);
        $this->assertNull($data['remaining_attempts']);
        $this->assertTrue($data['allow_retakes']);
    }

    /**
     * 6. Expired/out-of-scope paid access falls through correctly.
     */
    public function test_expired_or_out_of_scope_paid_access_falls_through_correctly(): void
    {
        // Paid for otherProgramId, but test is in programId
        $this->createPaidSubscription($this->student1, $this->otherProgramId, now()->addMonth());
        $test = $this->createFormalTest(isOfficial: true, programId: $this->programId);

        $elRes = $this->actingAs($this->student1, 'web_api')
            ->getJson("/web_api/auth/freemium/tests/{$test->id}/eligibility");
        $elRes->assertStatus(200);
        $data = $elRes->json('data');

        // Falls through: is_paid must be false
        $this->assertFalse($data['is_paid']);
    }

    /**
     * 7. Campaign quotas override legacy fallback.
     */
    public function test_campaign_quotas_override_legacy_fallback(): void
    {
        // Campaign with 5 tests and allow_retakes = true
        $this->createCampaign('formal_test', allowRetakes: true, maxAttempts: 5, maxTests: 7);

        $res = $this->actingAs($this->student1, 'web_api')
            ->getJson('/web_api/auth/freemium/quota-allowances?exam_type=formal_test');
        $res->assertStatus(200);

        $quotas = $res->json('data.quotas');
        $this->assertCount(1, $quotas);
        $formal = $quotas[0];
        $this->assertSame(7, $formal['max_tests']);
        $this->assertTrue($formal['allow_retakes']);
        $this->assertSame(5, $formal['max_attempts_per_test']);
    }

    /**
     * 8. Legacy-free quota values are consistent with actual enforcement.
     */
    public function test_legacy_free_quota_values_are_consistent_with_actual_enforcement(): void
    {
        // No campaign created: student is purely on legacy fallback
        $res = $this->actingAs($this->student1, 'web_api')
            ->getJson('/web_api/auth/freemium/quota-allowances');
        $res->assertStatus(200);

        $quotas = $res->json('data.quotas');
        $this->assertNotEmpty($quotas);

        $formal = collect($quotas)->firstWhere('exam_type', 'formal_test');
        $this->assertNotNull($formal);
        $this->assertSame(1, $formal['max_tests']); // Default legacy weekly tests = 1
        $this->assertSame(0, $formal['used_tests']);
        $this->assertSame(1, $formal['remaining_tests']);

        $mock = collect($quotas)->firstWhere('exam_type', 'mock');
        $this->assertNotNull($mock);
        $this->assertSame(1, $mock['max_tests']); // Default legacy weekly mocks = 1

        $practice = collect($quotas)->firstWhere('exam_type', 'practice');
        $this->assertNotNull($practice);
        $this->assertSame(2, $practice['max_tests']); // Default legacy weekly practice = 2
    }

    /**
     * 9. Formal generation quota is not confused with attempt quota.
     */
    public function test_formal_generation_quota_is_not_confused_with_attempt_quota(): void
    {
        $this->createCampaign('formal_test', allowRetakes: true, maxAttempts: 3, maxTests: 5);
        $test = $this->createFormalTest(isOfficial: true);

        // Attempting an existing official test records attempt_start, NOT generation!
        $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $test->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ])
            ->assertStatus(200);

        // Quota counter for generation should NOT be incremented for an attempt!
        $counter = FreemiumQuotaCounter::where('user_id', $this->student1->id)
            ->where('exam_type', 'formal_test')
            ->value('counter');
        $this->assertSame(0, (int)$counter);

        // Next attempt number on this test is 2, max_attempts is 3
        $elRes = $this->actingAs($this->student1, 'web_api')
            ->getJson("/web_api/auth/freemium/tests/{$test->id}/eligibility");
        $this->assertSame(2, $elRes->json('data.attempt_number'));
        $this->assertSame(3, $elRes->json('data.max_attempts_per_test'));
    }

    /**
     * 10. Mock quota remains independent from formal-test quota.
     */
    public function test_mock_quota_remains_independent_from_formal_test_quota(): void
    {
        $this->createCampaign('mock', allowRetakes: true, maxAttempts: 2, maxTests: 3);
        $this->createCampaign('formal_test', allowRetakes: true, maxAttempts: 2, maxTests: 4);

        $mockTest = $this->createMockTest(isOfficial: true);

        // Attempt mock test
        $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $mockTest->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ])
            ->assertStatus(200);

        // FreemiumQuotaConsumption must have mock, zero formal_test
        $this->assertSame(1, FreemiumQuotaConsumption::where('user_id', $this->student1->id)->where('exam_type', 'mock')->count());
        $this->assertSame(0, FreemiumQuotaConsumption::where('user_id', $this->student1->id)->where('exam_type', 'formal_test')->count());
    }

    /**
     * 11. Practice quota remains independent.
     */
    public function test_practice_quota_remains_independent(): void
    {
        $this->createCampaign('practice', allowRetakes: false, maxAttempts: 1, maxTests: 5);

        // Create a practice session entity
        $session = PracticeSession::create([
            'user_id'                 => $this->student1->id,
            'subject_id'              => $this->subjectId,
            'scope_type'              => 'chapter',
            'total_questions'         => 5,
            'attempted_questions'     => 0,
            'correct_answers'         => 0,
            'wrong_answers'           => 0,
            'not_attempted_questions' => 5,
            'score'                   => 0,
            'accuracy'                => 0,
            'status'                  => 'in_progress',
        ]);

        // Consume a practice session quota
        FreemiumQuotaConsumption::create([
            'user_id'             => $this->student1->id,
            'exam_type'           => 'practice',
            'event_type'          => 'practice_generation',
            'practice_session_id' => $session->id,
            'attempt_number'      => 0,
            'decision_snapshot'   => ['granted' => true],
            'consumed_at'         => now(),
        ]);

        $this->assertSame(1, FreemiumQuotaConsumption::where('user_id', $this->student1->id)->where('exam_type', 'practice')->count());
        $this->assertSame(0, FreemiumQuotaConsumption::where('user_id', $this->student1->id)->where('exam_type', 'formal_test')->count());
        $this->assertSame(0, FreemiumQuotaConsumption::where('user_id', $this->student1->id)->where('exam_type', 'mock')->count());
    }

    /**
     * 12. Recent-history attempt numbers match quota ledger records.
     */
    public function test_recent_history_attempt_numbers_match_quota_ledger_records(): void
    {
        $this->createCampaign('formal_test', allowRetakes: true, maxAttempts: 3);
        $test = $this->createFormalTest(isOfficial: true);

        // Create attempt 1 submitted
        $attempt1 = $this->createCompletedAttempt($this->student1, $test, 1);

        // Create attempt 2 submitted
        $attempt2 = $this->createCompletedAttempt($this->student1, $test, 2);

        $res = $this->actingAs($this->student1, 'web_api')
            ->getJson('/web_api/auth/tests/get-recent-attempts');
        $res->assertStatus(200);

        $items = $res->json('data');
        $this->assertCount(2, $items);

        $item2 = collect($items)->firstWhere('id', $attempt2->id);
        $this->assertNotNull($item2);
        $this->assertSame(2, $item2['attempt_number']);

        $item1 = collect($items)->firstWhere('id', $attempt1->id);
        $this->assertNotNull($item1);
        $this->assertSame(1, $item1['attempt_number']);
    }

    /**
     * 13. Shared-test histories remain isolated by student.
     */
    public function test_shared_test_histories_remain_isolated_by_student(): void
    {
        $this->createCampaign('formal_test', allowRetakes: true, maxAttempts: 3);
        $sharedTest = $this->createFormalTest(isOfficial: true);

        // Student 1 attempts shared test
        $this->createCompletedAttempt($this->student1, $sharedTest, 1);

        // Student 2 attempts shared test
        $this->createCompletedAttempt($this->student2, $sharedTest, 1);

        // Student 1 recent attempts should only contain Student 1's record
        $res1 = $this->actingAs($this->student1, 'web_api')
            ->getJson('/web_api/auth/tests/get-recent-attempts');
        $items1 = $res1->json('data');
        $this->assertCount(1, $items1);
        $this->assertSame($sharedTest->title, $items1[0]['title']);

        // Student 2 recent attempts should only contain Student 2's record
        $res2 = $this->actingAs($this->student2, 'web_api')
            ->getJson('/web_api/auth/tests/get-recent-attempts');
        $items2 = $res2->json('data');
        $this->assertCount(1, $items2);
        $this->assertSame($sharedTest->title, $items2[0]['title']);
    }

    /**
     * 14. Pakistan-local weekly boundary behavior is correct.
     */
    public function test_pakistan_local_weekly_boundary_behavior_is_correct(): void
    {
        $res = $this->actingAs($this->student1, 'web_api')
            ->getJson('/web_api/auth/freemium/quota-allowances');
        $res->assertStatus(200);

        $quotas = $res->json('data.quotas');
        $formal = collect($quotas)->firstWhere('exam_type', 'formal_test');
        $this->assertNotNull($formal);

        // Window key must match Pakistan weekly format PK:legacy:weekly:YYYY-WW
        $pkt = Carbon::now('Asia/Karachi');
        $expectedWindowKey = 'PK:legacy:weekly:' . $pkt->format('o-\WW');
        $this->assertSame($expectedWindowKey, $formal['window_key']);

        // Resets at must be next Monday 00:00:00 PKT in UTC
        $nextMondayUtc = $pkt->copy()->startOfWeek()->addWeek()->setTimezone('UTC')->toIso8601String();
        $this->assertSame($nextMondayUtc, $formal['resets_at']);
    }

    /**
     * 15. Existing response contracts remain compatible.
     */
    public function test_existing_response_contracts_remain_compatible(): void
    {
        $this->createCampaign('formal_test', allowRetakes: true, maxAttempts: 2);
        $test = $this->createFormalTest(isOfficial: true);

        // Check eligibility contract
        $elRes = $this->actingAs($this->student1, 'web_api')
            ->getJson("/web_api/auth/freemium/tests/{$test->id}/eligibility");
        $elRes->assertStatus(200)->assertJsonStructure([
            'success',
            'data' => [
                'test_id',
                'test_mode',
                'can_attempt',
                'is_retake',
                'attempt_number',
                'max_attempts_per_test',
                'remaining_attempts',
                'allow_retakes',
                'denial_reason',
            ],
        ]);

        // Check quota allowances contract
        $qRes = $this->actingAs($this->student1, 'web_api')
            ->getJson('/web_api/auth/freemium/quota-allowances');
        $qRes->assertStatus(200)->assertJsonStructure([
            'success',
            'data' => [
                'quotas' => [
                    '*' => [
                        'exam_type',
                        'window_type',
                        'window_key',
                        'is_unlimited',
                        'max_tests',
                        'used_tests',
                        'remaining_tests',
                        'allow_retakes',
                        'max_attempts_per_test',
                        'resets_at',
                    ],
                ],
            ],
        ]);
    }

    // --- Helper Methods ---

    protected function createFormalTest(bool $isOfficial = true, ?int $programId = null): Test
    {
        $test = Test::create([
            'offered_program_id' => $programId ?? $this->programId,
            'subject_id'         => $this->subjectId,
            'created_by'         => $isOfficial ? $this->admin->id : $this->student1->id,
            'title'              => 'Formal Test ' . uniqid(),
            'test_source'        => $isOfficial ? 'official' : 'system',
            'test_mode'          => 'test',
            'scope_type'         => 'chapter',
            'time_limit_minutes' => 30,
            'total_questions'    => 1,
            'is_published'       => $isOfficial ? 1 : 0,
            'published_at'       => $isOfficial ? now() : null,
        ]);

        DB::table('test_questions')->insert([
            'test_id'        => $test->id,
            'question_id'    => $this->questionId,
            'question_order' => 1,
            'marks'          => 1.0,
        ]);

        DB::table('test_scopes')->insert([
            'test_id' => $test->id,
            'unit_id' => $this->unitId,
        ]);

        return $test;
    }

    protected function createMockTest(bool $isOfficial = true): Test
    {
        $test = Test::create([
            'offered_program_id' => $this->programId,
            'subject_id'         => $this->subjectId,
            'created_by'         => $this->admin->id,
            'title'              => 'Mock Test ' . uniqid(),
            'test_source'        => $isOfficial ? 'official' : 'system',
            'test_mode'          => 'mock',
            'scope_type'         => 'full_book',
            'time_limit_minutes' => 60,
            'total_questions'    => 1,
            'is_published'       => $isOfficial ? 1 : 0,
            'published_at'       => $isOfficial ? now() : null,
        ]);

        DB::table('test_questions')->insert([
            'test_id'        => $test->id,
            'question_id'    => $this->questionId,
            'question_order' => 1,
            'marks'          => 1.0,
        ]);

        DB::table('test_scopes')->insert([
            'test_id' => $test->id,
            'unit_id' => $this->unitId,
        ]);

        return $test;
    }

    protected function createCompletedAttempt(WebUser $student, Test $test, int $attemptNumber): TestAttempt
    {
        $attempt = TestAttempt::create([
            'test_id'                 => $test->id,
            'user_id'                 => $student->id,
            'attempted_questions'     => 1,
            'correct_answers'         => 1,
            'wrong_answers'           => 0,
            'not_attempted_questions' => 0,
            'score'                   => 1,
            'accuracy'                => 100,
            'started_at'              => now()->subMinutes(10),
            'submitted_at'            => now(),
            'status'                  => 'submitted',
        ]);

        FreemiumQuotaConsumption::create([
            'user_id'             => $student->id,
            'exam_type'           => $test->test_mode === 'mock' ? 'mock' : 'formal_test',
            'event_type'          => $attemptNumber === 1 ? 'attempt_start' : 'retake_start',
            'test_id'             => $test->id,
            'test_attempt_id'     => $attempt->id,
            'attempt_number'      => $attemptNumber,
            'decision_snapshot'   => ['granted' => true],
            'consumed_at'         => now(),
        ]);

        return $attempt;
    }

    protected function createCampaign(
        string $examType = 'formal_test',
        bool $allowRetakes = true,
        int $maxAttempts = 2,
        int $maxTests = 10
    ): array {
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'Consistency Campaign ' . uniqid(),
            'status'       => 'active',
            'priority'     => 1,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonth(),
            'is_stackable' => true,
        ]);

        $quota = FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => $examType,
            'window_type'           => 'weekly',
            'max_tests'             => $maxTests,
            'allow_retakes'         => $allowRetakes,
            'max_attempts_per_test' => $maxAttempts,
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

    protected function createPaidSubscription(WebUser $student, int $programId, Carbon $expiresAt): UserSubscription
    {
        return UserSubscription::create([
            'user_id'            => $student->id,
            'offered_program_id' => $programId,
            'status'             => 'active',
            'payment_status'     => 'paid',
            'activated_at'       => now()->subDays(5),
            'expires_at'         => $expiresAt,
        ]);
    }
}
