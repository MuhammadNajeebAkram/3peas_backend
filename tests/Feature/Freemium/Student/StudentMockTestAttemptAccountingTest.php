<?php

namespace Tests\Feature\Freemium\Student;

use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumExamQuota;
use App\Models\Freemium\FreemiumQuotaConsumption;
use App\Models\Test;
use App\Models\TestAttempt;
use App\Models\TestAttemptQuestion;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Stage 1E.2.D.D.2 — Mock-Test Attempt Accounting Feature & Concurrency Tests.
 *
 * Verifies that tests where `test_mode = 'mock'` are correctly accounted for
 * under `exam_type = 'mock'` without polluting formal tests or practice sessions.
 */
class StudentMockTestAttemptAccountingTest extends TestCase
{
    protected WebUser $student1;
    protected WebUser $student2;
    protected User $admin;

    protected int $programId = 994;
    protected int $otherProgramId = 995;
    protected int $subjectId = 1;
    protected int $unitId = 1;
    protected int $topicId = 1;

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
            'name'     => 'Admin Mock User',
            'email'    => 'admin_mock_' . uniqid() . '@example.com',
            'password' => bcrypt('Secret123'),
        ]);

        $this->student1 = WebUser::create([
            'name'     => 'Mock Student One',
            'email'    => 'mock_student_1_' . uniqid() . '@example.com',
            'password' => bcrypt('Secret123'),
            'role'     => 'student',
            'status'   => 'active',
        ]);

        $this->student2 = WebUser::create([
            'name'     => 'Mock Student Two',
            'email'    => 'mock_student_2_' . uniqid() . '@example.com',
            'password' => bcrypt('Secret123'),
            'role'     => 'student',
            'status'   => 'active',
        ]);

        DB::table('program_subjects')->updateOrInsert(
            ['offered_program_id' => $this->programId, 'subject_id' => $this->subjectId],
            ['is_free' => 0, 'updated_at' => now()]
        );

        $this->topicId = (int) (DB::table('book_unit_topic_tbl')->value('id') ?? 1);

        $this->questionId = (int) DB::table('exam_question_tbl')->insertGetId([
            'question'      => 'Mock Test MCQ ' . uniqid(),
            'topic_id'      => $this->topicId,
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
     * 1. Mock attempt uses the `mock` quota category.
     */
    public function test_mock_attempt_uses_mock_quota_category(): void
    {
        $this->createCampaign(examType: 'mock', allowRetakes: true, maxAttempts: 2);
        $mockTest = $this->createMockTest(isOfficial: true);

        $res = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $mockTest->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);

        $res->assertStatus(200)->assertJson(['success' => 1]);

        $consumption = FreemiumQuotaConsumption::where('test_id', $mockTest->id)
            ->where('user_id', $this->student1->id)
            ->first();

        $this->assertNotNull($consumption);
        $this->assertSame('mock', $consumption->exam_type);
        $this->assertSame('attempt_start', $consumption->event_type);
        $this->assertSame(1, $consumption->attempt_number);
    }

    /**
     * 2. Formal-test attempts continue using `formal_test`.
     */
    public function test_formal_test_attempts_continue_using_formal_test(): void
    {
        $this->createCampaign(examType: 'formal_test', allowRetakes: true, maxAttempts: 2);
        $formalTest = $this->createFormalTest(isOfficial: true);

        $res = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $formalTest->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);

        $res->assertStatus(200)->assertJson(['success' => 1]);

        $consumption = FreemiumQuotaConsumption::where('test_id', $formalTest->id)
            ->where('user_id', $this->student1->id)
            ->first();

        $this->assertNotNull($consumption);
        $this->assertSame('formal_test', $consumption->exam_type);
        $this->assertSame('attempt_start', $consumption->event_type);
        $this->assertSame(1, $consumption->attempt_number);
    }

    /**
     * 3. Paid mock-test access bypasses Freemium consumption only within valid scope.
     */
    public function test_paid_mock_test_access_bypasses_freemium_consumption_only_within_valid_scope(): void
    {
        $this->createPaidSubscription($this->student1, $this->programId, now()->addMonth());
        $mockTest = $this->createMockTest(isOfficial: true, programId: $this->programId);

        $res = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $mockTest->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);

        $res->assertStatus(200)->assertJson(['success' => 1]);
        $attemptId = $res->json('data.attempt_id');
        $this->assertNotNull($attemptId);

        // Verify attempt created in DB
        $this->assertSame(1, TestAttempt::where('id', $attemptId)->count());

        // Zero records in freemium_quota_consumptions
        $consumptionCount = FreemiumQuotaConsumption::where('test_id', $mockTest->id)
            ->where('user_id', $this->student1->id)
            ->count();
        $this->assertSame(0, $consumptionCount);
    }

    /**
     * 4. Expired/out-of-scope paid access falls through correctly.
     */
    public function test_expired_or_out_of_scope_paid_access_falls_through_correctly(): void
    {
        // Expired subscription for programId
        $this->createPaidSubscription($this->student1, $this->programId, now()->subDay());

        // Active Freemium campaign for mock tests
        $this->createCampaign(examType: 'mock', allowRetakes: true, maxAttempts: 2);
        $mockTest = $this->createMockTest(isOfficial: true, programId: $this->programId);

        $res = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $mockTest->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);

        $res->assertStatus(200)->assertJson(['success' => 1]);

        // Verified: Falls through to Freemium quota ledger!
        $consumption = FreemiumQuotaConsumption::where('test_id', $mockTest->id)
            ->where('user_id', $this->student1->id)
            ->first();

        $this->assertNotNull($consumption);
        $this->assertSame('mock', $consumption->exam_type);
        $this->assertSame('attempt_start', $consumption->event_type);
    }

    /**
     * 5. Initial mock attempt consumes one allowance.
     */
    public function test_initial_mock_attempt_consumes_one_allowance(): void
    {
        $this->createCampaign(examType: 'mock', allowRetakes: true, maxAttempts: 2);
        $mockTest = $this->createMockTest(isOfficial: true);

        $res = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $mockTest->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);

        $res->assertStatus(200);

        $consumptions = FreemiumQuotaConsumption::where('test_id', $mockTest->id)
            ->where('user_id', $this->student1->id)
            ->get();

        $this->assertCount(1, $consumptions);
        $this->assertSame(1, $consumptions[0]->attempt_number);
        $this->assertSame('attempt_start', $consumptions[0]->event_type);
        $this->assertSame('mock', $consumptions[0]->exam_type);
    }

    /**
     * 6. Retake consumes the correct additional allowance.
     */
    public function test_retake_consumes_the_correct_additional_allowance(): void
    {
        $this->createCampaign(examType: 'mock', allowRetakes: true, maxAttempts: 2);
        $mockTest = $this->createMockTest(isOfficial: true);

        // Attempt 1: Progress & Submit
        $res1 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $mockTest->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);
        $attemptId1 = $res1->json('data.attempt_id');

        $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/submit-formal-test', [
                'test_id'    => $mockTest->id,
                'attempt_id' => $attemptId1,
            ])
            ->assertStatus(200);

        // Attempt 2 (Retake)
        $res2 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $mockTest->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);

        $res2->assertStatus(200);
        $attemptId2 = $res2->json('data.attempt_id');
        $this->assertNotEquals($attemptId1, $attemptId2);

        $consumptions = FreemiumQuotaConsumption::where('test_id', $mockTest->id)
            ->where('user_id', $this->student1->id)
            ->orderBy('attempt_number')
            ->get();

        $this->assertCount(2, $consumptions);
        $this->assertSame(1, $consumptions[0]->attempt_number);
        $this->assertSame('attempt_start', $consumptions[0]->event_type);
        $this->assertSame('mock', $consumptions[0]->exam_type);

        $this->assertSame(2, $consumptions[1]->attempt_number);
        $this->assertSame('retake_start', $consumptions[1]->event_type);
        $this->assertSame('mock', $consumptions[1]->exam_type);
    }

    /**
     * 7. Disabled retakes are rejected.
     */
    public function test_disabled_retakes_are_rejected(): void
    {
        $this->createCampaign(examType: 'mock', allowRetakes: false, maxAttempts: 1);
        $mockTest = $this->createMockTest(isOfficial: true);

        // Attempt 1: Progress & Submit
        $res1 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $mockTest->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);
        $attemptId1 = $res1->json('data.attempt_id');

        $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/submit-formal-test', [
                'test_id'    => $mockTest->id,
                'attempt_id' => $attemptId1,
            ])
            ->assertStatus(200);

        // Attempt 2: Must be rejected with 403 QUOTA_EXHAUSTED
        $res2 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $mockTest->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);

        $res2->assertStatus(403);
        $res2->assertJson([
            'success'    => 0,
            'error_code' => 'QUOTA_EXHAUSTED',
        ]);
        $this->assertStringContainsString('Retakes are not permitted', $res2->json('error'));
    }

    /**
     * 8. Maximum attempt count is enforced inclusively.
     */
    public function test_maximum_attempt_count_is_enforced_inclusively(): void
    {
        $this->createCampaign(examType: 'mock', allowRetakes: true, maxAttempts: 2);
        $mockTest = $this->createMockTest(isOfficial: true);

        // Attempt 1: Progress & Submit
        $res1 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $mockTest->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);
        $att1 = $res1->json('data.attempt_id');
        $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/submit-formal-test', ['test_id' => $mockTest->id, 'attempt_id' => $att1]);

        // Attempt 2: Progress & Submit
        $res2 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $mockTest->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);
        $att2 = $res2->json('data.attempt_id');
        $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/submit-formal-test', ['test_id' => $mockTest->id, 'attempt_id' => $att2]);

        // Attempt 3: Should be blocked since max_attempts = 2
        $res3 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $mockTest->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);

        $res3->assertStatus(403);
        $res3->assertJson(['success' => 0, 'error_code' => 'QUOTA_EXHAUSTED']);
        $this->assertStringContainsString('Maximum permitted attempts (2) reached', $res3->json('error'));
    }

    /**
     * 9. Resuming an active mock attempt consumes nothing extra.
     */
    public function test_resuming_an_active_mock_attempt_consumes_nothing_extra(): void
    {
        $this->createCampaign(examType: 'mock', allowRetakes: false, maxAttempts: 1);
        $mockTest = $this->createMockTest(isOfficial: true);

        // Start Attempt 1
        $res1 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $mockTest->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);
        $attemptId1 = $res1->json('data.attempt_id');

        $this->assertSame(1, FreemiumQuotaConsumption::where('test_id', $mockTest->id)->count());

        // Resume active attempt
        $res2 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $mockTest->id,
                'attempt_id'         => $attemptId1,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->wrongOptionId,
            ]);

        $res2->assertStatus(200);
        $this->assertSame($attemptId1, $res2->json('data.attempt_id'));

        // Still exactly 1 consumption record
        $this->assertSame(1, FreemiumQuotaConsumption::where('test_id', $mockTest->id)->count());
    }

    /**
     * 10. Answer updates consume nothing extra.
     */
    public function test_answer_updates_consume_nothing_extra(): void
    {
        $this->createCampaign(examType: 'mock', allowRetakes: false, maxAttempts: 1);
        $mockTest = $this->createMockTest(isOfficial: true);

        // Initial answer selection
        $res1 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $mockTest->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->wrongOptionId,
            ]);
        $attemptId = $res1->json('data.attempt_id');

        // Answer update to correct option
        $res2 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $mockTest->id,
                'attempt_id'         => $attemptId,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);

        $res2->assertStatus(200);

        // Check updated answer in database
        $ans = TestAttemptQuestion::where('attempt_id', $attemptId)
            ->where('question_id', $this->questionId)
            ->first();
        $this->assertSame($this->correctOptionId, $ans->selected_option_id);
        $this->assertTrue((bool) $ans->is_correct);

        // Zero additional quota consumed
        $this->assertSame(1, FreemiumQuotaConsumption::where('test_id', $mockTest->id)->count());
    }

    /**
     * 11. Submission replay does not consume another allowance.
     */
    public function test_submission_replay_does_not_consume_another_allowance(): void
    {
        $this->createCampaign(examType: 'mock', allowRetakes: false, maxAttempts: 1);
        $mockTest = $this->createMockTest(isOfficial: true);

        $resStart = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $mockTest->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);
        $attemptId = $resStart->json('data.attempt_id');

        // Submit 1
        $resSubmit1 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/submit-formal-test', [
                'test_id'    => $mockTest->id,
                'attempt_id' => $attemptId,
            ]);
        $resSubmit1->assertStatus(200);

        $consumptionsBefore = FreemiumQuotaConsumption::where('test_id', $mockTest->id)->count();

        // Submit replay
        $resSubmit2 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/submit-formal-test', [
                'test_id'    => $mockTest->id,
                'attempt_id' => $attemptId,
            ]);
        $resSubmit2->assertStatus(200);
        $this->assertSame($resSubmit1->json('data.score'), $resSubmit2->json('data.score'));

        // Zero additional consumption records
        $consumptionsAfter = FreemiumQuotaConsumption::where('test_id', $mockTest->id)->count();
        $this->assertSame($consumptionsBefore, $consumptionsAfter);
    }

    /**
     * 12. Abandoned mock attempts follow approved policy.
     */
    public function test_abandoned_mock_attempts_follow_approved_policy(): void
    {
        $this->createCampaign(examType: 'mock', allowRetakes: false, maxAttempts: 1);
        $mockTest = $this->createMockTest(isOfficial: true);

        // Student starts attempt #1 and abandons it (leaves in_progress)
        $res1 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $mockTest->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);
        $attemptId1 = $res1->json('data.attempt_id');

        // Mark attempt as abandoned or let it expire
        TestAttempt::where('id', $attemptId1)->update(['status' => 'expired']);

        // Now student attempts to start a new attempt on the same test
        $res2 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $mockTest->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);

        // Retakes are disabled, so starting a second attempt must be denied with 403
        $res2->assertStatus(403);
        $res2->assertJson(['success' => 0, 'error_code' => 'QUOTA_EXHAUSTED']);
    }

    /**
     * 13. Shared official mock tests isolate different students.
     */
    public function test_shared_official_mock_tests_isolate_different_students(): void
    {
        $this->createCampaign(examType: 'mock', allowRetakes: true, maxAttempts: 2);
        $officialMock = $this->createMockTest(isOfficial: true);

        // Student 1 starts attempt 1
        $res1 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $officialMock->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);
        $res1->assertStatus(200);
        $att1 = $res1->json('data.attempt_id');

        // Student 2 starts attempt 1 for the exact same official mock
        $res2 = $this->actingAs($this->student2, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $officialMock->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->wrongOptionId,
            ]);
        $res2->assertStatus(200);
        $att2 = $res2->json('data.attempt_id');

        $this->assertNotEquals($att1, $att2);

        // Verify isolation in test_attempts table
        $this->assertSame(1, TestAttempt::where('id', $att1)->where('user_id', $this->student1->id)->count());
        $this->assertSame(1, TestAttempt::where('id', $att2)->where('user_id', $this->student2->id)->count());

        // Verify isolation in freemium_quota_consumptions
        $c1 = FreemiumQuotaConsumption::where('test_attempt_id', $att1)->first();
        $this->assertSame($this->student1->id, $c1->user_id);
        $this->assertSame('mock', $c1->exam_type);

        $c2 = FreemiumQuotaConsumption::where('test_attempt_id', $att2)->first();
        $this->assertSame($this->student2->id, $c2->user_id);
        $this->assertSame('mock', $c2->exam_type);
    }

    /**
     * 14. Unauthorized private mock-test access is rejected.
     */
    public function test_unauthorized_private_mock_test_access_is_rejected(): void
    {
        $this->createCampaign(examType: 'mock', allowRetakes: true, maxAttempts: 2);

        // Private mock test owned by student 1
        $privateMock = $this->createMockTest(isOfficial: false, ownerId: $this->student1->id);

        // Student 2 tries to access Student 1's private mock test
        $res = $this->actingAs($this->student2, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $privateMock->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);

        $res->assertStatus(404);
        $res->assertJson([
            'success' => 0,
            'error'   => 'Test not found or access denied.',
        ]);
    }

    /**
     * 15. Concurrent attempts cannot exceed the allowance.
     */
    public function test_concurrent_attempts_cannot_exceed_allowance(): void
    {
        $this->createCampaign(examType: 'mock', allowRetakes: false, maxAttempts: 1);
        $mockTest = $this->createMockTest(isOfficial: true);

        $studentId = $this->student1->id;
        $testId = $mockTest->id;
        $questionId = $this->questionId;
        $optionId = $this->correctOptionId;

        $workerScript = <<<'PHP'
<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$studentId = (int) $argv[1];
$testId = (int) $argv[2];
$questionId = (int) $argv[3];
$optionId = (int) $argv[4];

$student = App\Models\WebUser::find($studentId);
$request = Illuminate\Http\Request::create(
    '/web_api/auth/tests/save-formal-test-question-progress',
    'POST',
    [
        'test_id'            => $testId,
        'question_id'        => $questionId,
        'selected_option_id' => $optionId,
    ]
);
$request->setUserResolver(fn() => $student);

$controller = $app->make(App\Http\Controllers\Tests\TestController::class);
$response = $controller->saveFormalTestQuestionProgressForLms($request);

echo json_encode([
    'status' => $response->getStatusCode(),
    'data'   => json_decode($response->getContent(), true),
]);
PHP;

        $tempScriptPath = tempnam(sys_get_temp_dir(), 'mock_conc_') . '.php';
        file_put_contents($tempScriptPath, $workerScript);

        try {
            $cmd1 = [PHP_BINARY, $tempScriptPath, (string)$studentId, (string)$testId, (string)$questionId, (string)$optionId];
            $cmd2 = [PHP_BINARY, $tempScriptPath, (string)$studentId, (string)$testId, (string)$questionId, (string)$optionId];

            $p1 = new Process($cmd1, base_path());
            $p2 = new Process($cmd2, base_path());

            $p1->start();
            $p2->start();

            $p1->wait();
            $p2->wait();

            $out1 = json_decode($p1->getOutput(), true);
            $out2 = json_decode($p2->getOutput(), true);

            $this->assertNotNull($out1, "Worker 1 output was invalid: " . $p1->getOutput() . " Err: " . $p1->getErrorOutput());
            $this->assertNotNull($out2, "Worker 2 output was invalid: " . $p2->getOutput() . " Err: " . $p2->getErrorOutput());

            // In serialized locking, both workers succeed (one starts attempt, second attaches to in-progress attempt)
            // OR one succeeds and one reports conflict. In both cases, EXACTLY 1 attempt and 1 consumption record must exist!
            $attemptCount = TestAttempt::where('test_id', $testId)->where('user_id', $studentId)->count();
            $this->assertSame(1, $attemptCount, "Expected exactly 1 test attempt created in DB under concurrency");

            $consumptionCount = FreemiumQuotaConsumption::where('test_id', $testId)->where('user_id', $studentId)->count();
            $this->assertSame(1, $consumptionCount, "Expected exactly 1 freemium quota consumption created in DB under concurrency");
        } finally {
            if (file_exists($tempScriptPath)) {
                @unlink($tempScriptPath);
            }
        }
    }

    // --- Helper Methods ---

    protected function createMockTest(bool $isOfficial = true, ?int $ownerId = null, ?int $programId = null): Test
    {
        $test = Test::create([
            'offered_program_id' => $programId ?? $this->programId,
            'subject_id'         => $this->subjectId,
            'created_by'         => $ownerId ?? ($isOfficial ? null : $this->student1->id),
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

    protected function createFormalTest(bool $isOfficial = true, ?int $ownerId = null): Test
    {
        $test = Test::create([
            'offered_program_id' => $this->programId,
            'subject_id'         => $this->subjectId,
            'created_by'         => $ownerId ?? ($isOfficial ? null : $this->student1->id),
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

    protected function createCampaign(string $examType = 'mock', bool $allowRetakes = true, int $maxAttempts = 2): array
    {
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'Mock Campaign ' . uniqid(),
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
            'max_tests'             => 10,
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
