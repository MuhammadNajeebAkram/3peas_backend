<?php

namespace Tests\Feature;

use App\Models\TeacherClass;
use App\Models\TeacherClassEnrollment;
use App\Models\TeacherClassTest;
use App\Models\TeacherClassTestAttempt;
use App\Models\TeacherProfile;
use App\Models\TeacherSubscriptionFeature;
use App\Models\WebUser;
use App\Models\WebUserNotification;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TeacherClassTestDeliveryAndExamTest extends TeacherBackendTest
{
    private TeacherProfile $teacher;
    private TeacherProfile $otherTeacher;
    private TeacherClass $class;
    private TeacherClass $otherClass;
    private WebUser $teacherUser;
    private WebUser $otherTeacherUser;
    private WebUser $studentA;
    private WebUser $studentB;
    private WebUser $pendingStudent;
    private WebUser $removedStudent;
    private WebUser $outsiderStudent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createResourceTables();
        (require database_path('migrations/2026_11_11_000004_create_teacher_class_tests_and_questions_tables.php'))->up();
        (require database_path('migrations/2026_11_11_000005_create_phase7d_test_delivery_and_attempts_tables.php'))->up();
        $this->seedMcqBankData();

        $subscriptions = app(\App\Http\Services\TeacherSubscriptionService::class);

        $this->teacher = TeacherProfile::where('web_user_id', 2)->firstOrFail();
        $this->teacher->forceFill(['status' => 'active'])->save();
        $this->teacherUser = WebUser::findOrFail(2);
        $subscriptions->assignDefaultFree($this->teacher->id);

        $this->otherTeacher = TeacherProfile::where('web_user_id', 3)->firstOrFail();
        $this->otherTeacher->forceFill(['status' => 'active'])->save();
        $this->otherTeacherUser = WebUser::findOrFail(3);
        $subscriptions->assignDefaultFree($this->otherTeacher->id);

        $this->class = $this->makeClass($this->teacher, 'Grade 10 Physics A');
        $this->otherClass = $this->makeClass($this->otherTeacher, 'Grade 10 Physics B');

        $this->studentA = WebUser::findOrFail(1);
        $this->studentB = WebUser::create([
            'name' => 'Student Two',
            'email' => 'p7d_student_b@example.test',
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->pendingStudent = WebUser::create([
            'name' => 'Pending Student',
            'email' => 'pending@example.test',
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->removedStudent = WebUser::create([
            'name' => 'Removed Student',
            'email' => 'removed@example.test',
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->outsiderStudent = WebUser::create([
            'name' => 'Outsider Student',
            'email' => 'outsider@example.test',
            'role' => 'student',
            'status' => 'active',
        ]);

        $paymentTests = [
            'test_server_price_and_idempotent_approval',
            'test_other_teacher_and_students_cannot_confirm_or_view_requests',
            'test_suspension_blocks_submission_and_approval',
            'test_rejected_request_cannot_activate_subscription',
            'test_phase_five_student_payment_uses_server_price_and_admin_review_activates_teacher_attribution',
            'test_phase_five_teacher_review_is_centralized_scoped_and_audited',
            'test_phase_five_admin_view_permission_cannot_review',
            'test_phase_five_admin_rejection_requires_reason_and_never_activates_subscription',
            'test_partial_settlements_reserve_balance_and_confirmation_is_idempotent',
            'test_rejection_releases_reservation_and_other_teacher_cannot_allocate',
            'test_bank_proof_is_private_and_uses_existing_bank_deposit_accounts',
            'test_suspended_teacher_can_settle_but_blocked_login_cannot',
            'test_revoked_collection_permission_keeps_history_and_remittance_but_blocks_actions',
            'test_submission_key_cannot_be_reused_with_different_amounts',
            'test_unconfirmed_collections_and_mismatched_accounts_cannot_be_settled',
            'test_phase5_cross_reviewer_conflict_renewal_and_input_normalization',
            'test_phase5_true_concurrent_approvals_across_parallel_connections_and_renewal',
        ];

        if (!in_array($this->name(), $paymentTests, true)) {
            // Active subscriptions covering $this->class curriculum
            DB::table('user_subscriptions')->insert([
                ['user_id' => $this->studentA->id, 'offered_program_id' => 1, 'status' => 'active', 'expires_at' => now()->addYear()->toDateString()],
                ['user_id' => $this->studentB->id, 'offered_program_id' => 1, 'status' => 'active', 'expires_at' => now()->addYear()->toDateString()],
                ['user_id' => $this->outsiderStudent->id, 'offered_program_id' => 1, 'status' => 'active', 'expires_at' => now()->addYear()->toDateString()],
                ['user_id' => $this->removedStudent->id, 'offered_program_id' => 1, 'status' => 'active', 'expires_at' => now()->addYear()->toDateString()],
            ]);
        }

        // Enrollments in $this->class
        $this->enroll($this->class, $this->studentA, TeacherClassEnrollment::STATUS_ACTIVE);
        $this->enroll($this->class, $this->studentB, TeacherClassEnrollment::STATUS_ACTIVE);
        $this->enroll($this->class, $this->pendingStudent, TeacherClassEnrollment::STATUS_PENDING);
        $this->enroll($this->class, $this->removedStudent, TeacherClassEnrollment::STATUS_REMOVED);

        // Outsider enrolled in $this->otherClass
        $this->enroll($this->otherClass, $this->outsiderStudent, TeacherClassEnrollment::STATUS_ACTIVE);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_teacher_publishes_scheduled_and_immediate_tests_and_enforces_immutability_and_ownership(): void
    {
        $draft = $this->createDraftTest($this->class, $this->teacherUser, 'Draft Publish Flow');
        $testId = (int) $draft['id'];

        // Cross-teacher cannot publish
        $this->actingAs($this->otherTeacherUser, 'web_api');
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testId}/publish", [
            'starts_at' => now()->addHour()->toIso8601String(),
            'ends_at' => now()->addHours(3)->toIso8601String(),
            'duration_minutes' => 30,
            'max_attempts' => 1,
            'result_visibility' => 'manual_release',
        ])->assertForbidden();

        // Invalid schedule rejected (end before start)
        $this->actingAs($this->teacherUser, 'web_api');
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testId}/publish", [
            'starts_at' => now()->addHours(3)->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(),
            'duration_minutes' => 30,
            'max_attempts' => 1,
            'result_visibility' => 'manual_release',
        ])->assertStatus(422);

        // Future start transitions draft -> scheduled & freezes editing/deleting
        $pubRes = $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testId}/publish", [
            'starts_at' => now()->addMinutes(30)->toIso8601String(),
            'ends_at' => now()->addHours(2)->toIso8601String(),
            'duration_minutes' => 45,
            'max_attempts' => 2,
            'result_visibility' => 'manual_release',
            'allow_answer_review' => true,
        ])->assertOk();

        $pubRes->assertJsonPath('data.status', 'scheduled');
        $pubRes->assertJsonPath('data.is_editable', false);
        $this->assertEquals(5.0, (float) $pubRes->json('data.total_marks'));

        // Cannot edit, delete, or re-publish once scheduled/published
        $this->putJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testId}", [
            'title' => 'Tampered Title',
            'selection_method' => 'manual',
            'questions' => [['question_id' => 101]],
        ])->assertStatus(409);

        $this->deleteJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testId}")
            ->assertStatus(409);

        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testId}/publish", [
            'starts_at' => now()->addMinute()->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(),
            'duration_minutes' => 30,
            'max_attempts' => 1,
            'result_visibility' => 'after_submission',
        ])->assertStatus(409);
    }

    public function test_teacher_assigns_entire_class_and_selected_students_rejecting_ineligible_and_duplicates(): void
    {
        $draftA = $this->createDraftTest($this->class, $this->teacherUser, 'Entire Class Quiz');
        $testAId = (int) $draftA['id'];

        // Cannot assign draft before publishing
        $this->actingAs($this->teacherUser, 'web_api');
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testAId}/assignments", [
            'assignment_mode' => 'entire_class',
            'starts_at' => now()->subMinute()->toIso8601String(),
            'ends_at' => now()->addHours(2)->toIso8601String(),
            'duration_minutes' => 20,
            'max_attempts' => 1,
            'result_visibility' => 'after_submission',
        ])->assertStatus(409);

        // Publish Test A
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testAId}/publish", [
            'starts_at' => now()->subMinute()->toIso8601String(),
            'ends_at' => now()->addHours(2)->toIso8601String(),
            'duration_minutes' => 20,
            'max_attempts' => 1,
            'result_visibility' => 'after_submission',
            'allow_answer_review' => true,
        ])->assertOk()->assertJsonPath('data.status', 'published');

        // Without tests.assign permission, teacher is blocked
        TeacherSubscriptionFeature::where('teacher_subscription_plan_id', 1)
            ->where('feature_key', 'tests.assign')
            ->update(['is_enabled' => false]);
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testAId}/assignments", [
            'assignment_mode' => 'entire_class',
        ])->assertStatus(403);
        TeacherSubscriptionFeature::where('teacher_subscription_plan_id', 1)
            ->where('feature_key', 'tests.assign')
            ->update(['is_enabled' => true]);

        // Assign Test A to entire class -> assigns ONLY studentA & studentB (not pending/removed/outsider)
        $assignRes = $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testAId}/assignments", [
            'assignment_mode' => 'entire_class',
        ])->assertCreated();

        $assignRes->assertJsonPath('data.assigned_count', 2);
        $assignmentAId = (int) $assignRes->json('data.id');

        // Duplicate entire_class assignment rejected
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testAId}/assignments", [
            'assignment_mode' => 'entire_class',
        ])->assertStatus(409);

        // Verify notifications created once for studentA and studentB only
        $this->assertSame(1, WebUserNotification::where('recipient_web_user_id', $this->studentA->id)
            ->where('type', WebUserNotification::TYPE_TEST_AVAILABLE)->count());
        $this->assertSame(1, WebUserNotification::where('recipient_web_user_id', $this->studentB->id)
            ->where('type', WebUserNotification::TYPE_TEST_AVAILABLE)->count());
        $this->assertSame(0, WebUserNotification::where('recipient_web_user_id', $this->pendingStudent->id)->count());
        $this->assertSame(0, WebUserNotification::where('recipient_web_user_id', $this->removedStudent->id)->count());

        // Publish Test B for selected_students testing
        $draftB = $this->createDraftTest($this->class, $this->teacherUser, 'Selected Student Quiz');
        $testBId = (int) $draftB['id'];
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testBId}/publish", [
            'starts_at' => now()->subMinute()->toIso8601String(),
            'ends_at' => now()->addHours(2)->toIso8601String(),
            'duration_minutes' => 15,
            'max_attempts' => 1,
            'result_visibility' => 'manual_release',
        ])->assertOk();

        // Reject pending / removed / outsider students
        foreach ([$this->pendingStudent->id, $this->removedStudent->id, $this->outsiderStudent->id] as $badId) {
            $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testBId}/assignments", [
                'assignment_mode' => 'selected_students',
                'student_ids' => [$badId],
            ])->assertStatus(422);
        }

        // Assign Test B ONLY to studentA
        $assignB = $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testBId}/assignments", [
            'assignment_mode' => 'selected_students',
            'student_ids' => [$this->studentA->id],
        ])->assertCreated();
        $assignmentBId = (int) $assignB->json('data.id');

        // Duplicate selected-student assignment for studentA on Test B is rejected
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testBId}/assignments", [
            'assignment_mode' => 'selected_students',
            'student_ids' => [$this->studentA->id],
        ])->assertStatus(409);

        // Student A sees BOTH Test A & Test B; Student B sees ONLY Test A; Pending/Removed/Outsider see neither!
        $this->actingAs($this->studentA, 'web_api');
        $aList = $this->getJson('/web_api/auth/student/tests')->assertOk()->json('data');
        $this->assertCount(2, $aList);

        $this->actingAs($this->studentB, 'web_api');
        $bList = $this->getJson('/web_api/auth/student/tests')->assertOk()->json('data');
        $this->assertCount(1, $bList);
        $this->assertSame($assignmentAId, $bList[0]['assignment_id']);
        $this->getJson("/web_api/auth/student/tests/{$assignmentBId}")->assertStatus(404);

        foreach ([$this->pendingStudent, $this->removedStudent, $this->outsiderStudent] as $u) {
            $this->actingAs($u, 'web_api');
            $this->assertCount(0, $this->getJson('/web_api/auth/student/tests')->assertOk()->json('data'));
            $this->getJson("/web_api/auth/student/tests/{$assignmentAId}")->assertStatus(404);
        }

        // Cross-teacher cannot access monitoring or release results
        $this->actingAs($this->otherTeacherUser, 'web_api');
        $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testAId}/assignments/{$assignmentAId}")
            ->assertForbidden();
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testAId}/assignments/{$assignmentAId}/release-results")
            ->assertForbidden();
    }

    public function test_online_examination_flow_prevents_key_leakage_enforces_stimulus_ordering_grading_and_visibility(): void
    {
        $baseNow = now()->addMinute()->startOfMinute();
        Carbon::setTestNow($baseNow);

        // Create test with 1 standalone (101) + 1 stimulus group (201, 202), negative_marks=0.50 per wrong
        $draft = $this->createDraftTest($this->class, $this->teacherUser, 'Physics Midterm Exam', [
            'marks_per_question' => 2,
            'negative_marks_per_wrong' => 0.5,
        ]);
        $testId = (int) $draft['id'];

        // Scheduled 10 mins in future
        $this->actingAs($this->teacherUser, 'web_api');
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testId}/publish", [
            'starts_at' => $baseNow->copy()->addMinutes(10)->toIso8601String(),
            'ends_at' => $baseNow->copy()->addMinutes(100)->toIso8601String(),
            'duration_minutes' => 30,
            'max_attempts' => 1,
            'result_visibility' => 'manual_release',
            'allow_answer_review' => true,
        ])->assertOk();

        $assignmentId = (int) $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testId}/assignments", [
            'assignment_mode' => 'selected_students',
            'student_ids' => [$this->studentA->id],
        ])->assertCreated()->json('data.id');

        // Student A cannot start before starts_at
        $this->actingAs($this->studentA, 'web_api');
        $detailBefore = $this->getJson("/web_api/auth/student/tests/{$assignmentId}")->assertOk();
        $detailBefore->assertJsonPath('data.availability_status', 'upcoming');
        $detailBefore->assertJsonPath('data.can_start', false);
        $this->postJson("/web_api/auth/student/tests/{$assignmentId}/start")
            ->assertStatus(422)
            ->assertJsonPath('code', 'test_not_started');

        // Advance time 15 minutes into availability window
        Carbon::setTestNow($baseNow->copy()->addMinutes(15));
        $startRes = $this->postJson("/web_api/auth/student/tests/{$assignmentId}/start")->assertOk();
        $attemptId = (int) $startRes->json('data.id');
        $this->assertSame(1, (int) $startRes->json('data.attempt_number'));
        $this->assertSame(1800, (int) $startRes->json('data.remaining_seconds'));

        // Verify All Formats presentation ordering (standalone first, stimulus group second) & ZERO answer leakage!
        $rawStartJson = $startRes->getContent();
        $this->assertStringNotContainsString('"is_answer"', $rawStartJson);
        $this->assertStringNotContainsString('"correct_option_id"', $rawStartJson);
        $this->assertStringNotContainsString('"explanation"', $rawStartJson);

        $standalone = $startRes->json('data.presentation.standalone_questions');
        $groups = $startRes->json('data.presentation.stimulus_groups');
        $this->assertCount(1, $standalone);
        $this->assertSame(101, $standalone[0]['question_id']);
        $this->assertCount(1, $groups);
        $this->assertSame([201, 202], array_column($groups[0]['questions'], 'question_id'));

        $tq101 = (int) $standalone[0]['teacher_class_test_question_id'];
        $tq201 = (int) $groups[0]['questions'][0]['teacher_class_test_question_id'];
        $opt101Correct = collect($standalone[0]['options'])->firstWhere('option', '<p>Standalone Correct</p>')['id'];
        $opt201Wrong = collect($groups[0]['questions'][0]['options'])->firstWhere('option', '<p>Stim 1 Wrong</p>')['id'];
        $foreignOpt = collect($standalone[0]['options'])->first()['id'];

        // Tamper check: submitting option ID from Q101 onto Q201 must fail!
        $this->putJson("/web_api/auth/student/tests/{$assignmentId}/attempts/{$attemptId}/answers", [
            'teacher_class_test_question_id' => $tq201,
            'selected_option_id' => $foreignOpt,
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_option_for_question');

        // Tamper check: question ID from outside attempt must fail!
        $this->putJson("/web_api/auth/student/tests/{$assignmentId}/attempts/{$attemptId}/answers", [
            'teacher_class_test_question_id' => 999999,
            'selected_option_id' => $opt101Correct,
        ])->assertStatus(422)->assertJsonPath('code', 'question_not_in_attempt');

        // Save valid answers: Q101 correct (+2.00), Q201 wrong (-0.50), Q202 unanswered (0.00) -> expected 1.50 / 6.00 (25%)
        $this->putJson("/web_api/auth/student/tests/{$assignmentId}/attempts/{$attemptId}/answers", [
            'answers' => [
                ['teacher_class_test_question_id' => $tq101, 'selected_option_id' => $opt101Correct],
                ['teacher_class_test_question_id' => $tq201, 'selected_option_id' => $opt201Wrong],
            ],
        ])->assertOk()->assertJsonPath('data.answered_questions', 2);

        // Advance 5 minutes and test safe resume/refresh (no duplicate attempt, timer decremented server-side!)
        Carbon::setTestNow($baseNow->copy()->addMinutes(20));
        $resumed = $this->postJson("/web_api/auth/student/tests/{$assignmentId}/start")->assertOk();
        $this->assertSame($attemptId, (int) $resumed->json('data.id'));
        $this->assertSame(1500, (int) $resumed->json('data.remaining_seconds'));
        $this->assertSame(2, (int) $resumed->json('data.answered_questions'));

        // Mutate question bank AFTER publication to verify historical snapshot isolation!
        DB::table('exam_question_options_tbl')->where('question_id', 101)->update(['is_answer' => 0]);

        // Submit manually (attempting to pass client marks must be ignored)
        $submitRes = $this->postJson("/web_api/auth/student/tests/{$assignmentId}/attempts/{$attemptId}/submit", [
            'obtained_marks' => 999,
        ])->assertOk();

        // Result visibility is manual_release -> marks and answers MUST stay hidden from student!
        $submitRes->assertJsonPath('data.status', 'submitted');
        $submitRes->assertJsonPath('data.result_released', false);
        $this->assertArrayNotHasKey('obtained_marks', $submitRes->json('data'));
        $this->assertArrayNotHasKey('percentage', $submitRes->json('data'));
        $this->assertStringNotContainsString('correct_option_id', $submitRes->getContent());

        // Idempotent second submit does not error or duplicate
        $this->postJson("/web_api/auth/student/tests/{$assignmentId}/attempts/{$attemptId}/submit")
            ->assertOk()
            ->assertJsonPath('already_finalized', true);
        $this->assertSame(1, TeacherClassTestAttempt::where('teacher_class_test_assignment_id', $assignmentId)->count());

        // Exceeding attempt limit (max_attempts = 1) fails cleanly
        $this->postJson("/web_api/auth/student/tests/{$assignmentId}/start")
            ->assertStatus(422)
            ->assertJsonPath('code', 'max_attempts_reached');

        // Teacher monitors & inspects graded attempt (Q101 graded +2 using frozen snapshot despite bank mutation!)
        $this->actingAs($this->teacherUser, 'web_api');
        $mon = $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testId}/assignments/{$assignmentId}")
            ->assertOk();
        $mon->assertJsonPath('summary.assigned_count', 1);
        $mon->assertJsonPath('summary.submitted_count', 1);
        $this->assertEquals(1.5, (float) $mon->json('recipients.0.latest_attempt.obtained_marks'));
        $this->assertEquals(25.0, (float) $mon->json('recipients.0.latest_attempt.percentage'));

        $tAttempt = $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testId}/assignments/{$assignmentId}/attempts/{$attemptId}")
            ->assertOk();
        $tAttempt->assertJsonPath('attempt.correct_count', 1);
        $tAttempt->assertJsonPath('attempt.incorrect_count', 1);
        $tAttempt->assertJsonPath('attempt.unanswered_count', 1);

        // Teacher releases results -> student now sees marks + full review + gets one notification
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testId}/assignments/{$assignmentId}/release-results")
            ->assertOk()
            ->assertJsonPath('data.results_released', true);

        $this->assertSame(1, WebUserNotification::where('recipient_web_user_id', $this->studentA->id)
            ->where('type', WebUserNotification::TYPE_TEST_RESULT_RELEASED)->count());

        $this->actingAs($this->studentA, 'web_api');
        $releasedRes = $this->getJson("/web_api/auth/student/tests/{$assignmentId}/attempts/{$attemptId}")->assertOk();
        $releasedRes->assertJsonPath('data.result_released', true);
        $releasedRes->assertJsonPath('data.can_review_answers', true);
        $this->assertEquals(1.5, (float) $releasedRes->json('data.obtained_marks'));
        $this->assertEquals(25.0, (float) $releasedRes->json('data.percentage'));
        $this->assertSame($opt101Correct, $releasedRes->json('data.presentation.standalone_questions.0.correct_option_id'));
    }

    public function test_server_deadline_auto_finalization_stricter_window_cap_after_close_visibility_and_left_student_history(): void
    {
        $baseNow = now()->copy()->addDay()->startOfHour();
        Carbon::setTestNow($baseNow);

        // Window closes in 10 minutes while duration is 60 minutes -> stricter deadline (10 mins) applies!
        $draft = $this->createDraftTest($this->class, $this->teacherUser, 'Deadline & After Close Test', [
            'marks_per_question' => 1,
            'negative_marks_per_wrong' => 0,
        ]);
        $testId = (int) $draft['id'];

        $this->actingAs($this->teacherUser, 'web_api');
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testId}/publish", [
            'starts_at' => $baseNow->copy()->subMinutes(5)->toIso8601String(),
            'ends_at' => $baseNow->copy()->addMinutes(10)->toIso8601String(),
            'duration_minutes' => 60,
            'max_attempts' => 2,
            'result_visibility' => 'after_close',
            'allow_answer_review' => false,
        ])->assertOk();

        $assignmentId = (int) $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testId}/assignments", [
            'assignment_mode' => 'entire_class',
        ])->assertCreated()->json('data.id');

        // Student A starts at 14:00 -> deadline must be capped at 14:10 (600 seconds), NOT 60 minutes!
        $this->actingAs($this->studentA, 'web_api');
        $startRes = $this->postJson("/web_api/auth/student/tests/{$assignmentId}/start")->assertOk();
        $attemptId = (int) $startRes->json('data.id');
        $this->assertSame(600, (int) $startRes->json('data.remaining_seconds'));

        $standalone = $startRes->json('data.presentation.standalone_questions');
        $tq101 = (int) $standalone[0]['teacher_class_test_question_id'];
        $opt101Correct = collect($standalone[0]['options'])->firstWhere('option', '<p>Standalone Correct</p>')['id'];
        $this->putJson("/web_api/auth/student/tests/{$assignmentId}/attempts/{$attemptId}/answers", [
            'teacher_class_test_question_id' => $tq101,
            'selected_option_id' => $opt101Correct,
        ])->assertOk();

        // Advance time past deadline -> saving further answer rejects 409 & auto-finalizes saved answers!
        Carbon::setTestNow($baseNow->copy()->addMinutes(12));
        $this->putJson("/web_api/auth/student/tests/{$assignmentId}/attempts/{$attemptId}/answers", [
            'teacher_class_test_question_id' => $tq101,
            'selected_option_id' => null,
        ])->assertStatus(409)->assertJsonPath('code', 'attempt_deadline_expired');

        // Run scheduler command too
        Artisan::call('tests:finalize-expired');

        // Because window closed & result_visibility = after_close, Student A now sees marks (NOT answer review since allow_answer_review=false)
        $afterClose = $this->getJson("/web_api/auth/student/tests/{$assignmentId}/attempts/{$attemptId}")->assertOk();
        $afterClose->assertJsonPath('data.status', 'auto_submitted');
        $afterClose->assertJsonPath('data.result_released', true);
        $afterClose->assertJsonPath('data.can_review_answers', false);
        $this->assertEquals(1.0, (float) $afterClose->json('data.obtained_marks'));
        $this->assertCount(0, $afterClose->json('data.questions'));

        // Student B never started before window closed -> cannot start now & marked expired
        $this->actingAs($this->studentB, 'web_api');
        $this->postJson("/web_api/auth/student/tests/{$assignmentId}/start")
            ->assertStatus(422)
            ->assertJsonPath('code', 'test_window_closed');

        // Student A leaves class -> historical record preserved, but cannot take new tests in the left class!
        $enrId = TeacherClassEnrollment::where('teacher_class_id', $this->class->id)
            ->where('student_web_user_id', $this->studentA->id)
            ->value('id');
        $this->actingAs($this->studentA, 'web_api');
        $this->postJson("/web_api/auth/student/classes/{$enrId}/leave", ['reason' => 'Done'])->assertOk();
        $aHistory = $this->getJson('/web_api/auth/student/tests')->assertOk()->json('data');
        $this->assertCount(1, $aHistory);
        $this->assertSame('left', $aHistory[0]['enrollment_status']);
        $this->assertSame(1, $this->getJson("/web_api/auth/student/tests/{$assignmentId}/attempts/{$attemptId}")->assertOk()->json('data.attempt_number'));
    }

    public function test_audit_regressions_rejects_option_tampering_premature_parent_close_and_unsafe_archive(): void
    {
        $baseNow = now()->copy()->addDay()->startOfHour();
        Carbon::setTestNow($baseNow);

        $draft = $this->createDraftTest($this->class, $this->teacherUser, 'Audit Security Test');
        $testId = (int) $draft['id'];

        $this->actingAs($this->teacherUser, 'web_api');
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testId}/publish", [
            'starts_at' => $baseNow->toIso8601String(),
            'ends_at' => $baseNow->copy()->addMinutes(30)->toIso8601String(),
            'duration_minutes' => 20,
            'max_attempts' => 1,
            'result_visibility' => 'manual_release',
            'allow_answer_review' => true,
        ])->assertOk();

        // Extended custom window beyond test's scheduled_end_at
        $assignRes = $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testId}/assignments", [
            'assignment_mode' => 'selected_students',
            'student_ids' => [$this->studentA->id],
            'starts_at' => $baseNow->toIso8601String(),
            'ends_at' => $baseNow->copy()->addHours(2)->toIso8601String(),
            'duration_minutes' => 30,
            'max_attempts' => 1,
            'result_visibility' => 'manual_release',
            'allow_answer_review' => true,
        ])->assertCreated();
        $assignmentId = (int) $assignRes->json('data.id');

        // Advance past test.scheduled_end_at (30m) while assignment is still open (2h)
        Carbon::setTestNow($baseNow->copy()->addMinutes(40));
        Artisan::call('tests:finalize-expired');

        // Parent test must remain published while open child assignment exists
        $this->assertSame('published', TeacherClassTest::findOrFail($testId)->status);

        // Even if test status were manually moved to closed, archiving while open assignments exist is blocked
        TeacherClassTest::whereKey($testId)->update(['status' => TeacherClassTest::STATUS_CLOSED]);
        $this->actingAs($this->teacherUser, 'web_api');
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testId}/archive")
            ->assertStatus(409)
            ->assertJsonPath('code', 'test_has_open_assignments');
        TeacherClassTest::whereKey($testId)->update(['status' => TeacherClassTest::STATUS_PUBLISHED]);

        // Student starts attempt and attempts option-ID / question-ID payload manipulation
        $this->actingAs($this->studentA, 'web_api');
        $startRes = $this->postJson("/web_api/auth/student/tests/{$assignmentId}/start")->assertOk();
        $attemptId = (int) $startRes->json('data.id');
        $q1 = $startRes->json('data.questions.0');
        $q2 = $startRes->json('data.questions.1');
        $tq1 = (int) $q1['teacher_class_test_question_id'];
        $optFromQ2 = (int) $q2['options'][0]['id'];

        $this->putJson("/web_api/auth/student/tests/{$assignmentId}/attempts/{$attemptId}/answers", [
            'teacher_class_test_question_id' => $tq1,
            'selected_option_id' => $optFromQ2,
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_option_for_question');

        $this->putJson("/web_api/auth/student/tests/{$assignmentId}/attempts/{$attemptId}/answers", [
            'teacher_class_test_question_id' => 99999999,
            'selected_option_id' => (int) $q1['options'][0]['id'],
        ])->assertStatus(422)->assertJsonPath('code', 'question_not_in_attempt');

        // Close assignment -> closeTest -> archive succeeds cleanly now
        $this->actingAs($this->teacherUser, 'web_api');
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testId}/assignments/{$assignmentId}/close")->assertOk();
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testId}/close")->assertOk();
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/tests/{$testId}/archive")
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');
    }

    private function createDraftTest(TeacherClass $class, WebUser $user, string $title, array $overrides = []): array
    {
        $this->actingAs($user, 'web_api');

        return $this->postJson("/web_api/auth/teacher/classes/{$class->id}/tests", array_merge([
            'title' => $title,
            'instructions' => 'Read every MCQ carefully.',
            'selection_method' => 'manual',
            'marks_per_question' => 1,
            'negative_marks_per_wrong' => 0,
            'language_mode' => 'bilingual',
            'questions' => [
                ['question_id' => 101],
                ['question_id' => 201, 'marks' => 2],
                ['question_id' => 202, 'marks' => 2],
            ],
        ], $overrides))->assertCreated()->json('data');
    }

    private function makeClass(TeacherProfile $profile, string $name): TeacherClass
    {
        $class = new TeacherClass([
            'curriculum_board_id' => 1,
            'class_id' => 1,
            'subject_id' => 1,
            'name' => $name,
            'section' => 'A',
            'is_active' => true,
            'requires_approval' => true,
        ]);
        $class->teacher_profile_id = $profile->id;
        $class->class_code = TeacherClass::generateUniqueCode();
        $class->save();

        return $class;
    }

    private function enroll(TeacherClass $class, WebUser $student, string $status): TeacherClassEnrollment
    {
        return TeacherClassEnrollment::create([
            'teacher_class_id' => $class->id,
            'student_web_user_id' => $student->id,
            'status' => $status,
            'joined_via_code' => $class->class_code,
            'requested_at' => now(),
            'approved_at' => $status === TeacherClassEnrollment::STATUS_ACTIVE ? now() : null,
            'removed_at' => $status === TeacherClassEnrollment::STATUS_REMOVED ? now() : null,
        ]);
    }

    private function createResourceTables(): void
    {
        $schemas = [
            'class_tbl' => function (Blueprint $t) {
                $t->id(); $t->string('class_name'); $t->string('class_name_um')->nullable(); $t->boolean('activate')->default(true); $t->timestamps();
            },
            'subject_tbl' => function (Blueprint $t) {
                $t->id(); $t->string('subject_name'); $t->string('subject_name_um')->nullable(); $t->boolean('activate')->default(true); $t->timestamps();
            },
            'curriculum_board_tbl' => function (Blueprint $t) {
                $t->id(); $t->string('name'); $t->boolean('activate')->default(true); $t->timestamps();
            },
            'offered_classes' => function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('class_id')->nullable();
                $t->unsignedBigInteger('curriculum_board_id')->nullable()->default(1);
                $t->decimal('Price', 10, 2)->default(1000);
                $t->decimal('discount_price', 10, 2)->nullable();
                $t->boolean('is_active')->default(true);
                $t->boolean('is_free')->default(false);
                $t->date('session_end')->nullable();
                $t->date('session_start')->nullable();
                $t->integer('display_order')->default(0);
                $t->timestamps();
            },
            'offered_programs' => function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('offered_class_id'); $t->string('title'); $t->string('slug')->nullable(); $t->integer('display_order')->default(1); $t->boolean('is_active')->default(true); $t->timestamps();
            },
            'program_subjects' => function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('offered_program_id'); $t->unsignedBigInteger('subject_id'); $t->boolean('is_active')->default(true); $t->timestamps();
            },
            'user_subscriptions' => function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('offered_program_id'); $t->string('status')->default('active'); $t->string('access_type')->default('paid'); $t->decimal('price_paid', 10, 2)->default(0); $t->date('started_at')->nullable(); $t->date('expires_at')->nullable(); $t->date('approved_at')->nullable(); $t->unsignedBigInteger('approved_by')->nullable(); $t->timestamps();
            },
            'book_tbl' => function (Blueprint $t) {
                $t->id(); $t->string('book_name'); $t->unsignedBigInteger('curriculum_board_id'); $t->unsignedBigInteger('class_id'); $t->unsignedBigInteger('subject_id'); $t->boolean('activate')->default(true); $t->timestamps();
            },
            'book_unit_tbl' => function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('book_id'); $t->string('unit_no')->nullable(); $t->string('unit_name'); $t->string('unit_name_um')->nullable(); $t->boolean('is_alp')->default(false); $t->boolean('activate')->default(true); $t->timestamps();
            },
            'book_unit_topic_tbl' => function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('unit_id'); $t->string('topic_no')->nullable(); $t->string('topic_name'); $t->string('topic_name_um')->nullable(); $t->string('slo_no')->nullable(); $t->boolean('activate')->default(true); $t->timestamps();
            },
            'question_scenario_groups_tbl' => function (Blueprint $t) {
                $t->id(); $t->string('title')->nullable(); $t->text('scenario_text')->nullable(); $t->text('scenario_text_um')->nullable(); $t->string('scenario_image')->nullable(); $t->boolean('activate')->default(true); $t->timestamps();
            },
            'cognitive_domain_tbl' => function (Blueprint $t) {
                $t->id(); $t->string('name'); $t->string('short');
            },
            'question_presentation_type_tbl' => function (Blueprint $t) {
                $t->id(); $t->string('type_name'); $t->string('code'); $t->boolean('allows_multiple_mcqs')->default(false); $t->boolean('activate')->default(true);
            },
            'board_tbl' => function (Blueprint $t) {
                $t->id(); $t->string('board_name'); $t->string('short_name')->nullable(); $t->boolean('activate')->default(true);
            },
            'exam_session_tbl' => function (Blueprint $t) {
                $t->id(); $t->string('session_name'); $t->boolean('activate')->default(true);
            },
            'study_group_tbl' => function (Blueprint $t) {
                $t->id(); $t->string('name'); $t->unsignedBigInteger('class_id')->nullable(); $t->unsignedBigInteger('curriculum_board_id')->nullable(); $t->boolean('activate')->default(true);
            },
            'slo_domain_tbl' => function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('curriculum_board_id'); $t->unsignedBigInteger('subject_id'); $t->string('domain_code'); $t->string('domain_name'); $t->boolean('activate')->default(true);
            },
            'slo_topic_tbl' => function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('slo_domain_id')->nullable(); $t->unsignedBigInteger('class_id')->nullable(); $t->string('slo_code'); $t->string('slo_title'); $t->string('slo_title_um')->nullable(); $t->string('cognitive_level')->nullable(); $t->boolean('activate')->default(true);
            },
            'unit_slo_topic_tbl' => function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('unit_id'); $t->unsignedBigInteger('slo_topic_id');
            },
            'topic_slo_topic_tbl' => function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('topic_id'); $t->unsignedBigInteger('slo_topic_id');
            },
            'question_slo_tbl' => function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('question_id'); $t->unsignedBigInteger('slo_topic_id');
            },
            'exam_question_board_tbl' => function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('question_id'); $t->unsignedBigInteger('board_id'); $t->unsignedBigInteger('session_id')->nullable(); $t->unsignedBigInteger('group_id')->nullable(); $t->integer('year')->nullable(); $t->boolean('activate')->default(true);
            },
            'exam_question_tbl' => function (Blueprint $t) {
                $t->id();
                $t->text('question')->nullable(); $t->text('question_um')->nullable();
                $t->integer('question_lang')->default(0); $t->integer('question_um_lang')->default(1);
                $t->unsignedBigInteger('topic_id'); $t->unsignedBigInteger('unit_id')->nullable(); $t->unsignedBigInteger('book_id')->nullable();
                $t->tinyInteger('difficulty')->default(1);
                $t->unsignedBigInteger('cognitive_domain')->nullable();
                $t->string('topic_content')->nullable();
                $t->boolean('is_alp_question')->default(false);
                $t->boolean('exercise_question')->default(false);
                $t->boolean('has_diagram')->default(false);
                $t->boolean('is_mcq')->default(true);
                $t->boolean('activate')->default(true);
                $t->unsignedBigInteger('question_presentation_type_id')->nullable();
                $t->unsignedBigInteger('scenario_group_id')->nullable();
                $t->integer('scenario_question_order')->nullable();
                $t->unsignedBigInteger('slo_topic_id')->nullable();
                $t->decimal('marks', 6, 2)->default(1);
                $t->text('explanation')->nullable(); $t->text('explanation_um')->nullable();
                $t->string('explanation_video_url')->nullable();
                $t->timestamps();
            },
            'exam_question_options_tbl' => function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('question_id');
                $t->text('option')->nullable();
                $t->text('option_um')->nullable();
                $t->integer('option_lang')->default(0);
                $t->integer('option_um_lang')->default(1);
                $t->boolean('is_answer')->default(false);
            },
        ];

        foreach ($schemas as $name => $fn) {
            Schema::dropIfExists($name);
            Schema::create($name, $fn);
        }
    }

    private function seedMcqBankData(): void
    {
        DB::table('class_tbl')->insert(['id' => 1, 'class_name' => 'Grade 10', 'activate' => 1]);
        DB::table('subject_tbl')->insert(['id' => 1, 'subject_name' => 'Physics', 'activate' => 1]);
        DB::table('curriculum_board_tbl')->insert(['id' => 1, 'name' => 'Punjab', 'activate' => 1]);
        DB::table('offered_classes')->insert(['id' => 1, 'class_id' => 1, 'curriculum_board_id' => 1, 'Price' => 1000, 'discount_price' => 800, 'is_active' => 1]);
        DB::table('offered_programs')->insert(['id' => 1, 'offered_class_id' => 1, 'title' => 'Grade 10 Science', 'slug' => 'grade-10-science', 'display_order' => 1, 'is_active' => 1]);
        DB::table('program_subjects')->insert(['id' => 1, 'offered_program_id' => 1, 'subject_id' => 1, 'is_active' => 1]);
        DB::table('book_tbl')->insert(['id' => 10, 'book_name' => 'Physics 10 PTB', 'curriculum_board_id' => 1, 'class_id' => 1, 'subject_id' => 1, 'activate' => 1]);
        DB::table('book_unit_tbl')->insert(['id' => 100, 'book_id' => 10, 'unit_no' => '1', 'unit_name' => 'Motion', 'activate' => 1]);
        DB::table('book_unit_topic_tbl')->insert(['id' => 1000, 'unit_id' => 100, 'topic_no' => '1.1', 'topic_name' => 'Kinematics', 'activate' => 1]);

        DB::table('question_presentation_type_tbl')->insert([
            ['id' => 1, 'type_name' => 'Straight', 'code' => 'st', 'allows_multiple_mcqs' => 0, 'activate' => 1],
            ['id' => 2, 'type_name' => 'Stimulus', 'code' => 'stimulus', 'allows_multiple_mcqs' => 1, 'activate' => 1],
        ]);

        DB::table('question_scenario_groups_tbl')->insert([
            'id' => 91,
            'title' => 'Pendulum Experiment',
            'scenario_text' => '<p>Shared pendulum scenario.</p>',
            'scenario_text_um' => '<p>مشترکہ پینڈولم عبارت۔</p>',
            'activate' => 1,
        ]);

        DB::table('exam_question_tbl')->insert([
            [
                'id' => 101, 'question' => '<p>Standalone Q101</p>', 'question_um' => '<p>اکیلا سوال ۱۰۱</p>',
                'topic_id' => 1000, 'unit_id' => 100, 'book_id' => 10, 'difficulty' => 1, 'is_mcq' => 1, 'activate' => 1,
                'question_presentation_type_id' => 1, 'scenario_group_id' => null, 'scenario_question_order' => null,
                'explanation' => 'Explanation 101', 'explanation_um' => 'وضاحت ۱۰۱',
            ],
            [
                'id' => 201, 'question' => '<p>Stimulus Q201</p>', 'question_um' => null,
                'topic_id' => 1000, 'unit_id' => 100, 'book_id' => 10, 'difficulty' => 1, 'is_mcq' => 1, 'activate' => 1,
                'question_presentation_type_id' => 2, 'scenario_group_id' => 91, 'scenario_question_order' => 1,
                'explanation' => 'Explanation 201', 'explanation_um' => null,
            ],
            [
                'id' => 202, 'question' => '<p>Stimulus Q202</p>', 'question_um' => null,
                'topic_id' => 1000, 'unit_id' => 100, 'book_id' => 10, 'difficulty' => 1, 'is_mcq' => 1, 'activate' => 1,
                'question_presentation_type_id' => 2, 'scenario_group_id' => 91, 'scenario_question_order' => 2,
                'explanation' => 'Explanation 202', 'explanation_um' => null,
            ],
        ]);

        DB::table('exam_question_options_tbl')->insert([
            ['question_id' => 101, 'option' => '<p>Standalone Correct</p>', 'is_answer' => 1],
            ['question_id' => 101, 'option' => '<p>Standalone Wrong</p>', 'is_answer' => 0],
            ['question_id' => 201, 'option' => '<p>Stim 1 Correct</p>', 'is_answer' => 1],
            ['question_id' => 201, 'option' => '<p>Stim 1 Wrong</p>', 'is_answer' => 0],
            ['question_id' => 202, 'option' => '<p>Stim 2 Correct</p>', 'is_answer' => 1],
            ['question_id' => 202, 'option' => '<p>Stim 2 Wrong</p>', 'is_answer' => 0],
        ]);
    }
}
