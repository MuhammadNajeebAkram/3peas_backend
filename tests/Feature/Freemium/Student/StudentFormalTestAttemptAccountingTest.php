<?php

namespace Tests\Feature\Freemium\Student;

use App\Models\ExamQuestion;
use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumExamQuota;
use App\Models\Freemium\FreemiumQuotaConsumption;
use App\Models\Freemium\FreemiumQuotaCounter;
use App\Models\Test;
use App\Models\TestAttempt;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\WebUser;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudentFormalTestAttemptAccountingTest extends TestCase
{
    protected WebUser $student1;
    protected WebUser $student2;
    protected User $admin;

    protected int $programId = 994;
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
            'name'     => 'Admin Test User',
            'email'    => 'admin_attempt_' . uniqid() . '@example.com',
            'password' => bcrypt('Secret123'),
        ]);

        $this->student1 = WebUser::create([
            'name'     => 'Student Attempt One',
            'email'    => 'student_attempt_1_' . uniqid() . '@example.com',
            'password' => bcrypt('Secret123'),
            'role'     => 'student',
            'status'   => 'active',
        ]);

        $this->student2 = WebUser::create([
            'name'     => 'Student Attempt Two',
            'email'    => 'student_attempt_2_' . uniqid() . '@example.com',
            'password' => bcrypt('Secret123'),
            'role'     => 'student',
            'status'   => 'active',
        ]);

        // Ensure program_subjects link exists with is_free = 0
        DB::table('program_subjects')->updateOrInsert(
            ['offered_program_id' => $this->programId, 'subject_id' => $this->subjectId],
            ['is_free' => 0, 'updated_at' => now()]
        );

        // Ensure topic exists
        $this->topicId = (int) (DB::table('book_unit_topic_tbl')->value('id') ?? 1);

        // Create a test question with options
        $this->questionId = (int) DB::table('exam_question_tbl')->insertGetId([
            'question'      => 'Test Question for Formal Assessment ' . uniqid(),
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
            'option'      => 'Correct Option A',
            'is_answer'   => 1,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->wrongOptionId = (int) DB::table('exam_question_options_tbl')->insertGetId([
            'question_id' => $this->questionId,
            'option'      => 'Wrong Option B',
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

    protected function createOfficialTest(array $attributes = []): Test
    {
        $test = Test::create(array_merge([
            'offered_program_id' => $this->programId,
            'subject_id'         => $this->subjectId,
            'created_by'         => $this->admin->id,
            'title'              => 'Official Shared Test ' . uniqid(),
            'test_source'        => 'official',
            'test_mode'          => 'test',
            'is_published'       => 1,
            'total_questions'    => 1,
        ], $attributes));

        DB::table('test_questions')->insert([
            'test_id'        => $test->id,
            'question_id'    => $this->questionId,
            'question_order' => 1,
            'marks'          => 1.0,
        ]);

        DB::table('test_scopes')->insert([
            'test_id'    => $test->id,
            'unit_id'    => $this->unitId,
        ]);

        return $test;
    }

    protected function createStudentCustomTest(WebUser $owner, array $attributes = []): Test
    {
        $test = Test::create(array_merge([
            'offered_program_id' => $this->programId,
            'subject_id'         => $this->subjectId,
            'created_by'         => $owner->id,
            'title'              => 'Custom Student Test ' . uniqid(),
            'test_source'        => 'system',
            'test_mode'          => 'test',
            'is_published'       => 0,
            'total_questions'    => 1,
        ], $attributes));

        DB::table('test_questions')->insert([
            'test_id'        => $test->id,
            'question_id'    => $this->questionId,
            'question_order' => 1,
            'marks'          => 1.0,
        ]);

        DB::table('test_scopes')->insert([
            'test_id'    => $test->id,
            'unit_id'    => $this->unitId,
        ]);

        return $test;
    }

    protected function createCampaign(bool $allowRetakes, int $maxAttempts): array
    {
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'Attempt Test Campaign ' . uniqid(),
            'status'       => 'active',
            'priority'     => 1,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonth(),
            'is_stackable' => true,
        ]);

        $quota = FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'formal_test',
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

    /**
     * 1. Two students can attempt same official test without collision.
     */
    public function test_two_students_can_attempt_same_official_test_without_collision(): void
    {
        $this->createCampaign(allowRetakes: true, maxAttempts: 3);
        $officialTest = $this->createOfficialTest();

        // Student 1 starts attempt 1
        $res1 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $officialTest->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);

        $res1->assertStatus(200)->assertJson(['success' => 1]);
        $attemptId1 = $res1->json('data.attempt_id');
        $this->assertNotNull($attemptId1);

        // Student 2 starts attempt 1 for the exact same official test
        $res2 = $this->actingAs($this->student2, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $officialTest->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);

        $res2->assertStatus(200)->assertJson(['success' => 1]);
        $attemptId2 = $res2->json('data.attempt_id');
        $this->assertNotNull($attemptId2);
        $this->assertNotEquals($attemptId1, $attemptId2);

        // Verify database: two distinct attempts
        $this->assertSame(2, TestAttempt::where('test_id', $officialTest->id)->count());

        // Verify database: two distinct quota consumption records
        $consumptions = FreemiumQuotaConsumption::where('test_id', $officialTest->id)->get();
        $this->assertCount(2, $consumptions);

        $c1 = $consumptions->where('user_id', $this->student1->id)->first();
        $this->assertNotNull($c1);
        $this->assertSame(1, $c1->attempt_number);
        $this->assertSame('attempt_start', $c1->event_type);

        $c2 = $consumptions->where('user_id', $this->student2->id)->first();
        $this->assertNotNull($c2);
        $this->assertSame(1, $c2->attempt_number);
        $this->assertSame('attempt_start', $c2->event_type);
    }

    /**
     * 2. Student can take initial attempt and permitted retake.
     */
    public function test_student_can_take_initial_attempt_and_permitted_retake(): void
    {
        $this->createCampaign(allowRetakes: true, maxAttempts: 2);
        $test = $this->createOfficialTest();

        // Attempt 1: Progress & Submit
        $res1 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $test->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);
        $res1->assertStatus(200);
        $attemptId1 = $res1->json('data.attempt_id');

        $sub1 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/submit-formal-test', [
                'test_id'    => $test->id,
                'attempt_id' => $attemptId1,
            ]);
        $sub1->assertStatus(200)->assertJson(['data' => ['status' => 'submitted']]);

        // Attempt 2 (Retake): Progress & Submit
        $res2 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $test->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->wrongOptionId,
            ]);
        $res2->assertStatus(200);
        $attemptId2 = $res2->json('data.attempt_id');
        $this->assertNotEquals($attemptId1, $attemptId2);

        $sub2 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/submit-formal-test', [
                'test_id'    => $test->id,
                'attempt_id' => $attemptId2,
            ]);
        $sub2->assertStatus(200)->assertJson(['data' => ['status' => 'submitted']]);

        // Verify 2 attempts in test_attempts
        $this->assertSame(2, TestAttempt::where('test_id', $test->id)->where('user_id', $this->student1->id)->count());

        // Verify ledger entries
        $records = FreemiumQuotaConsumption::where('test_id', $test->id)
            ->where('user_id', $this->student1->id)
            ->orderBy('attempt_number')
            ->get();

        $this->assertCount(2, $records);
        $this->assertSame(1, $records[0]->attempt_number);
        $this->assertSame('attempt_start', $records[0]->event_type);
        $this->assertSame(2, $records[1]->attempt_number);
        $this->assertSame('retake_start', $records[1]->event_type);
    }

    /**
     * 3. Retake blocked when retakes disabled.
     */
    public function test_retake_blocked_when_retakes_disabled(): void
    {
        $this->createCampaign(allowRetakes: false, maxAttempts: 1);
        $test = $this->createOfficialTest();

        // Attempt 1: Start and Submit
        $res1 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $test->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);
        $res1->assertStatus(200);
        $attemptId1 = $res1->json('data.attempt_id');

        $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/submit-formal-test', [
                'test_id'    => $test->id,
                'attempt_id' => $attemptId1,
            ])
            ->assertStatus(200);

        // Attempt 2 (Retake) should be rejected
        $res2 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $test->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);

        $res2->assertStatus(403)
            ->assertJson([
                'success'    => 0,
                'error_code' => 'QUOTA_EXHAUSTED',
            ]);

        // Zero additional attempts or ledger rows created
        $this->assertSame(1, TestAttempt::where('test_id', $test->id)->where('user_id', $this->student1->id)->count());
        $this->assertSame(1, FreemiumQuotaConsumption::where('test_id', $test->id)->where('user_id', $this->student1->id)->count());
    }

    /**
     * 4. Retake blocked when max attempts cap reached.
     */
    public function test_retake_blocked_when_max_attempts_cap_reached(): void
    {
        $this->createCampaign(allowRetakes: true, maxAttempts: 2);
        $test = $this->createOfficialTest();

        // Attempt 1
        $res1 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'     => $test->id,
                'question_id' => $this->questionId,
            ]);
        $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/submit-formal-test', [
                'test_id'    => $test->id,
                'attempt_id' => $res1->json('data.attempt_id'),
            ]);

        // Attempt 2
        $res2 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'     => $test->id,
                'question_id' => $this->questionId,
            ]);
        $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/submit-formal-test', [
                'test_id'    => $test->id,
                'attempt_id' => $res2->json('data.attempt_id'),
            ]);

        // Attempt 3 (Exceeds max_attempts_per_test = 2)
        $res3 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'     => $test->id,
                'question_id' => $this->questionId,
            ]);

        $res3->assertStatus(403)
            ->assertJson([
                'success'    => 0,
                'error_code' => 'QUOTA_EXHAUSTED',
            ]);

        $this->assertSame(2, TestAttempt::where('test_id', $test->id)->where('user_id', $this->student1->id)->count());
        $this->assertSame(2, FreemiumQuotaConsumption::where('test_id', $test->id)->where('user_id', $this->student1->id)->count());
    }

    /**
     * 5. Abandoned attempt burns attempt allowance.
     */
    public function test_abandoned_attempt_burns_attempt_allowance(): void
    {
        $this->createCampaign(allowRetakes: false, maxAttempts: 1);
        $test = $this->createOfficialTest();

        // Student starts attempt 1 but never submits it (status remains in_progress)
        $res1 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'     => $test->id,
                'question_id' => $this->questionId,
            ]);
        $res1->assertStatus(200);

        // Eligibility check shows 0 remaining attempts and retakes not permitted
        $elig = $this->actingAs($this->student1, 'web_api')
            ->getJson("/web_api/auth/freemium/tests/{$test->id}/eligibility")
            ->assertStatus(200);

        $this->assertFalse($elig->json('data.can_attempt'));
        $this->assertSame(0, $elig->json('data.remaining_attempts'));
        $this->assertSame('Retakes are not permitted for this test.', $elig->json('data.denial_reason'));
    }

    /**
     * 6. In-progress attempt resumed without consuming extra attempt.
     */
    public function test_in_progress_attempt_resumed_without_consuming_extra_attempt(): void
    {
        $this->createCampaign(allowRetakes: true, maxAttempts: 3);
        $test = $this->createOfficialTest();

        // First progress save creates attempt 1
        $res1 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $test->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->wrongOptionId,
            ]);
        $res1->assertStatus(200);
        $attemptId1 = $res1->json('data.attempt_id');

        // Second progress save updates answer on same attempt
        $res2 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $test->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);
        $res2->assertStatus(200);
        $attemptId2 = $res2->json('data.attempt_id');

        // Third save with explicit attempt_id
        $res3 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $test->id,
                'attempt_id'         => $attemptId1,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);
        $res3->assertStatus(200);

        $this->assertSame($attemptId1, $attemptId2);
        $this->assertSame(1, TestAttempt::where('test_id', $test->id)->where('user_id', $this->student1->id)->count());
        $this->assertSame(1, FreemiumQuotaConsumption::where('test_id', $test->id)->where('user_id', $this->student1->id)->count());
    }

    /**
     * 7. Paid student creates attempts without freemium ledger consumption.
     */
    public function test_paid_student_creates_attempts_without_freemium_ledger_consumption(): void
    {
        // Student has active paid subscription for this program
        UserSubscription::create([
            'user_id'            => $this->student1->id,
            'offered_program_id' => $this->programId,
            'status'             => 'active',
            'expires_at'         => now()->addYear(),
        ]);

        $test = $this->createOfficialTest();

        // Attempt 1: Start and Submit
        $res1 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $test->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);
        $res1->assertStatus(200);
        $attemptId1 = $res1->json('data.attempt_id');

        $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/submit-formal-test', [
                'test_id'    => $test->id,
                'attempt_id' => $attemptId1,
            ])
            ->assertStatus(200);

        // Attempt 2 (Retake): Start and Submit
        $res2 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $test->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);
        $res2->assertStatus(200);
        $attemptId2 = $res2->json('data.attempt_id');

        $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/submit-formal-test', [
                'test_id'    => $test->id,
                'attempt_id' => $attemptId2,
            ])
            ->assertStatus(200);

        // Paid student gets 2 attempts in test_attempts
        $this->assertSame(2, TestAttempt::where('test_id', $test->id)->where('user_id', $this->student1->id)->count());

        // Zero rows in freemium_quota_consumptions
        $this->assertSame(0, FreemiumQuotaConsumption::where('user_id', $this->student1->id)->count());
    }

    /**
     * 8. Cross-student unowned test attempt rejected with 404.
     */
    public function test_cross_student_unowned_test_attempt_rejected_with_404(): void
    {
        $customTest = $this->createStudentCustomTest($this->student1);

        // Student 2 attempts to save progress on Student 1's private test
        $res = $this->actingAs($this->student2, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'     => $customTest->id,
                'question_id' => $this->questionId,
            ]);

        $res->assertStatus(404)
            ->assertJson([
                'success' => 0,
                'error'   => 'Test not found or access denied.',
            ]);

        $this->assertSame(0, TestAttempt::where('test_id', $customTest->id)->where('user_id', $this->student2->id)->count());
    }

    /**
     * 9. Cross-student unowned test submission rejected with 404.
     */
    public function test_cross_student_unowned_test_submission_rejected_with_404(): void
    {
        $customTest = $this->createStudentCustomTest($this->student1);

        // Student 1 starts attempt
        $res1 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'     => $customTest->id,
                'question_id' => $this->questionId,
            ]);
        $attemptId1 = $res1->json('data.attempt_id');

        // Student 2 attempts to submit Student 1's test / attempt
        $res2 = $this->actingAs($this->student2, 'web_api')
            ->postJson('/web_api/auth/tests/submit-formal-test', [
                'test_id'    => $customTest->id,
                'attempt_id' => $attemptId1,
            ]);

        $res2->assertStatus(404)
            ->assertJson([
                'success' => 0,
                'error'   => 'Test not found or access denied.',
            ]);
    }

    /**
     * 10. Repeated submission preserves score idempotently.
     */
    public function test_repeated_submission_preserves_score_idempotently(): void
    {
        $this->createCampaign(allowRetakes: true, maxAttempts: 2);
        $test = $this->createOfficialTest();

        $res1 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $test->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);
        $attemptId = $res1->json('data.attempt_id');

        // First submission
        $sub1 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/submit-formal-test', [
                'test_id'    => $test->id,
                'attempt_id' => $attemptId,
            ]);
        $sub1->assertStatus(200);
        $score1 = $sub1->json('data.score');
        $submittedAt1 = $sub1->json('data.submitted_at');

        // Second submission replay
        $sub2 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/submit-formal-test', [
                'test_id'    => $test->id,
                'attempt_id' => $attemptId,
            ]);
        $sub2->assertStatus(200);
        $this->assertEquals($score1, $sub2->json('data.score'));
        $this->assertEquals(
            \Carbon\Carbon::parse($submittedAt1)->timestamp,
            \Carbon\Carbon::parse($sub2->json('data.submitted_at'))->timestamp
        );

        // No duplicate attempt created
        $this->assertSame(1, TestAttempt::where('test_id', $test->id)->where('user_id', $this->student1->id)->count());
    }

    /**
     * 11. Autosave after submission returns 409 conflict.
     */
    public function test_autosave_after_submission_returns_409_conflict(): void
    {
        $this->createCampaign(allowRetakes: true, maxAttempts: 2);
        $test = $this->createOfficialTest();

        $res1 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $test->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]);
        $attemptId = $res1->json('data.attempt_id');

        // Submit attempt
        $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/submit-formal-test', [
                'test_id'    => $test->id,
                'attempt_id' => $attemptId,
            ])
            ->assertStatus(200);

        // Late autosave on submitted attempt
        $lateSave = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $test->id,
                'attempt_id'         => $attemptId,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->wrongOptionId,
            ]);

        $lateSave->assertStatus(409)
            ->assertJson([
                'success'    => 0,
                'error_code' => 'ATTEMPT_ALREADY_SUBMITTED',
            ]);
    }

    /**
     * 12. Generation quota is not consumed by starting or retaking test.
     */
    public function test_generation_quota_is_not_consumed_by_starting_or_retaking_test(): void
    {
        $this->createCampaign(allowRetakes: true, maxAttempts: 3);
        $test = $this->createOfficialTest();

        // Initial counter is 0 (or null)
        $initialCounter = (int) FreemiumQuotaCounter::where('user_id', $this->student1->id)
            ->where('exam_type', 'formal_test')
            ->value('counter');

        // Attempt 1 progress save
        $res1 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'     => $test->id,
                'question_id' => $this->questionId,
            ]);
        $res1->assertStatus(200);

        $counterAfterAttempt1 = (int) FreemiumQuotaCounter::where('user_id', $this->student1->id)
            ->where('exam_type', 'formal_test')
            ->value('counter');
        $this->assertSame($initialCounter, $counterAfterAttempt1);

        // Submit
        $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/submit-formal-test', [
                'test_id'    => $test->id,
                'attempt_id' => $res1->json('data.attempt_id'),
            ]);

        // Attempt 2 progress save (retake)
        $res2 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'     => $test->id,
                'question_id' => $this->questionId,
            ]);
        $res2->assertStatus(200);

        $counterAfterAttempt2 = (int) FreemiumQuotaCounter::where('user_id', $this->student1->id)
            ->where('exam_type', 'formal_test')
            ->value('counter');
        $this->assertSame($initialCounter, $counterAfterAttempt2);
    }

    /**
     * 13. Expired or out-of-scope paid access does not bypass freemium evaluation.
     */
    public function test_expired_or_out_of_scope_paid_access_does_not_bypass_freemium_evaluation(): void
    {
        // Expired subscription for this program
        UserSubscription::create([
            'user_id'            => $this->student1->id,
            'offered_program_id' => $this->programId,
            'expires_at'         => now()->subDay(),
        ]);

        // Campaign with retakes disabled
        $this->createCampaign(allowRetakes: false, maxAttempts: 1);
        $test = $this->createOfficialTest();

        // Attempt 1: succeeds
        $res1 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'     => $test->id,
                'question_id' => $this->questionId,
            ]);
        $res1->assertStatus(200);

        $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/submit-formal-test', [
                'test_id'    => $test->id,
                'attempt_id' => $res1->json('data.attempt_id'),
            ]);

        // Attempt 2 (retake): because subscription is expired, student falls back to freemium and is blocked
        $res2 = $this->actingAs($this->student1, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'     => $test->id,
                'question_id' => $this->questionId,
            ]);

        $res2->assertStatus(403)
            ->assertJson([
                'success'    => 0,
                'error_code' => 'QUOTA_EXHAUSTED',
            ]);
    }
}
