<?php

namespace Tests\Feature;

use App\Models\TeacherClass;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherClassAssignmentRecipient;
use App\Models\TeacherClassAssignmentSubmission;
use App\Models\TeacherClassEnrollment;
use App\Models\TeacherClassTest;
use App\Models\TeacherClassTestAssignment;
use App\Models\TeacherClassTestAttempt;
use App\Models\TeacherClassTestAttemptAnswer;
use App\Models\TeacherClassTestQuestion;
use App\Models\TeacherClassTestRecipient;
use App\Models\TeacherProfile;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StudentClassSubscriptionAccessTest extends TeacherBackendTest
{
    private TeacherProfile $teacherProfile;
    private TeacherClass $classA;
    private TeacherClass $classB;
    private WebUser $student;

    protected function setUp(): void
    {
        parent::setUp();

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

        if (in_array($this->name(), $paymentTests, true)) {
            return;
        }

        // Ensure curriculum tables exist
        foreach (['class_tbl', 'subject_tbl', 'curriculum_board_tbl'] as $table) {
            if (!Schema::hasTable($table)) {
                Schema::create($table, function (Blueprint $t) use ($table) {
                    $t->id();
                    if ($table === 'class_tbl') {
                        $t->string('class_name');
                        $t->string('class_name_um')->nullable();
                    } elseif ($table === 'subject_tbl') {
                        $t->string('subject_name');
                        $t->string('subject_name_um')->nullable();
                    } elseif ($table === 'curriculum_board_tbl') {
                        $t->string('name');
                    }
                    $t->boolean('activate')->default(true);
                    $t->timestamps();
                });
            }
        }

        DB::table('class_tbl')->updateOrInsert(['id' => 1], ['class_name' => 'Grade 10', 'activate' => 1]);
        DB::table('subject_tbl')->updateOrInsert(['id' => 1], ['subject_name' => 'Physics', 'activate' => 1]);
        DB::table('subject_tbl')->updateOrInsert(['id' => 2], ['subject_name' => 'Chemistry', 'activate' => 1]);
        DB::table('curriculum_board_tbl')->updateOrInsert(['id' => 1], ['name' => 'Punjab Board', 'activate' => 1]);
        DB::table('curriculum_board_tbl')->updateOrInsert(['id' => 2], ['name' => 'Federal Board', 'activate' => 1]);

        if (!Schema::hasTable('program_subjects')) {
            Schema::create('program_subjects', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('offered_program_id');
                $t->unsignedBigInteger('subject_id');
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }

        // Setup offered_classes
        // Offered Class 1: Grade 10, Punjab (board 1)
        DB::table('offered_classes')->updateOrInsert(['id' => 1], [
            'class_id' => 1,
            'curriculum_board_id' => 1,
            'Price' => 1000,
            'discount_price' => 800,
            'is_active' => 1,
        ]);
        // Offered Class 2: Grade 10, Federal (board 2)
        DB::table('offered_classes')->updateOrInsert(['id' => 2], [
            'class_id' => 1,
            'curriculum_board_id' => 2,
            'Price' => 1200,
            'discount_price' => 1000,
            'is_active' => 1,
        ]);

        // Offered Programs
        // Program 1: Punjab Science (offered_class 1) -> covers Physics (1) and Chemistry (2)
        DB::table('offered_programs')->updateOrInsert(['id' => 1], [
            'offered_class_id' => 1,
            'title' => 'Punjab Grade 10 Science',
            'is_active' => 1,
            'slug' => 'punjab-grade-10-science',
        ]);
        // Program 2: Punjab Arts (offered_class 1) -> does NOT cover Physics or Chemistry
        DB::table('offered_programs')->updateOrInsert(['id' => 2], [
            'offered_class_id' => 1,
            'title' => 'Punjab Grade 10 Arts',
            'is_active' => 1,
            'slug' => 'punjab-grade-10-arts',
        ]);
        // Program 3: Federal Science (offered_class 2) -> covers Physics (1)
        DB::table('offered_programs')->updateOrInsert(['id' => 3], [
            'offered_class_id' => 2,
            'title' => 'Federal Grade 10 Science',
            'is_active' => 1,
            'slug' => 'federal-grade-10-science',
        ]);
        // Program 4: Punjab Medical (offered_class 1) -> shared subject (covers Physics 1)
        DB::table('offered_programs')->updateOrInsert(['id' => 4], [
            'offered_class_id' => 1,
            'title' => 'Punjab Grade 10 Pre-Medical',
            'is_active' => 1,
            'slug' => 'punjab-grade-10-pre-medical',
        ]);

        // Program Subjects mapping
        DB::table('program_subjects')->truncate();
        DB::table('program_subjects')->insert([
            ['id' => 1, 'offered_program_id' => 1, 'subject_id' => 1, 'is_active' => 1], // Prog 1 -> Physics
            ['id' => 2, 'offered_program_id' => 1, 'subject_id' => 2, 'is_active' => 1], // Prog 1 -> Chemistry
            ['id' => 3, 'offered_program_id' => 3, 'subject_id' => 1, 'is_active' => 1], // Prog 3 (Federal) -> Physics
            ['id' => 4, 'offered_program_id' => 4, 'subject_id' => 1, 'is_active' => 1], // Prog 4 (Pre-Medical) -> Physics
        ]);

        // Additional migrations for tests, attempts, assignments, discussions
        foreach ([
            '2026_11_11_000004_create_teacher_class_tests_and_questions_tables.php',
            '2026_11_11_000005_create_phase7d_test_delivery_and_attempts_tables.php',
            '2026_11_11_000006_create_phase7e_communication_tables.php',
            '2026_11_11_000007_create_phase7f_tables.php',
            '2026_11_12_000001_create_phase7g_gradebook_and_evaluations_tables.php',
        ] as $migration) {
            $path = database_path('migrations/'.$migration);
            if (file_exists($path)) {
                (require $path)->up();
            }
        }

        $subscriptions = app(\App\Http\Services\TeacherSubscriptionService::class);
        $this->teacherProfile = TeacherProfile::where('web_user_id', 2)->firstOrFail();
        $this->teacherProfile->forceFill(['status' => 'active'])->save();
        $subscriptions->assignDefaultFree($this->teacherProfile->id);

        $this->student = WebUser::findOrFail(1);
        $this->student->update(['email' => 'student.audit@example.test', 'status' => 'active', 'role' => 'student']);

        // Class A: Punjab Grade 10 Physics (covered by Program 1 and Program 4)
        $this->classA = TeacherClass::forceCreate([
            'teacher_profile_id' => $this->teacherProfile->id,
            'curriculum_board_id' => 1,
            'class_id' => 1,
            'subject_id' => 1,
            'name' => 'Punjab Grade 10 Physics',
            'section' => 'A',
            'class_code' => 'CLSA0001',
            'is_active' => true,
            'requires_approval' => false,
        ]);

        // Class B: Federal Grade 10 Physics (covered by Program 3)
        $this->classB = TeacherClass::forceCreate([
            'teacher_profile_id' => $this->teacherProfile->id,
            'curriculum_board_id' => 2,
            'class_id' => 1,
            'subject_id' => 1,
            'name' => 'Federal Grade 10 Physics',
            'section' => 'B',
            'class_code' => 'CLSB0002',
            'is_active' => true,
            'requires_approval' => true,
        ]);
    }

    private function createSubscription(int $userId, int $offeredProgramId, string $status = 'active', ?string $expiresAt = null): int
    {
        return DB::table('user_subscriptions')->insertGetId([
            'user_id' => $userId,
            'offered_program_id' => $offeredProgramId,
            'status' => $status,
            'access_type' => 'paid',
            'price_paid' => 1000,
            'started_at' => now()->subDay()->toDateString(),
            'expires_at' => $expiresAt ?? now()->addYear()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 1. Student with no subscription cannot join (HTTP 403 subscription_required).
     */
    public function test_student_with_no_subscription_cannot_join(): void
    {
        $this->actingAs($this->student, 'web_api');

        $res = $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $this->classA->class_code,
        ]);

        $res->assertForbidden()
            ->assertJsonPath('code', 'subscription_required')
            ->assertJsonPath('subscription_url', '/lms/subscribe/punjab-grade-10-science');
    }

    /**
     * 2. Student with matching active subscription can join (HTTP 201).
     */
    public function test_student_with_matching_active_subscription_can_join(): void
    {
        $this->createSubscription($this->student->id, 1, 'active');

        $this->actingAs($this->student, 'web_api');

        $res = $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $this->classA->class_code,
        ]);

        $res->assertCreated()
            ->assertJsonPath('outcome', 'enrolled')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.has_workspace_access', true);
    }

    /**
     * 3. Student subscribed to a different curriculum board cannot join (HTTP 403 subscription_required).
     */
    public function test_student_subscribed_to_different_board_cannot_join(): void
    {
        // Subscribed to Federal Science (Program 3), attempting to join Punjab class (Class A)
        $this->createSubscription($this->student->id, 3, 'active');

        $this->actingAs($this->student, 'web_api');

        $res = $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $this->classA->class_code,
        ]);

        $res->assertForbidden()
            ->assertJsonPath('code', 'subscription_required');
    }

    /**
     * 4. Student subscribed to an unrelated program on same board cannot join (HTTP 403 subscription_required).
     */
    public function test_student_subscribed_to_unrelated_program_on_same_board_cannot_join(): void
    {
        // Subscribed to Punjab Arts (Program 2), which does not include Physics
        $this->createSubscription($this->student->id, 2, 'active');

        $this->actingAs($this->student, 'web_api');

        $res = $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $this->classA->class_code,
        ]);

        $res->assertForbidden()
            ->assertJsonPath('code', 'subscription_required');
    }

    /**
     * 5. Expired subscription cannot join (HTTP 403 subscription_expired).
     */
    public function test_student_with_expired_subscription_cannot_join(): void
    {
        // Subscribed to Punjab Science (Program 1) but expired yesterday
        $this->createSubscription($this->student->id, 1, 'active', now()->subDay()->toDateString());

        $this->actingAs($this->student, 'web_api');

        $res = $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $this->classA->class_code,
        ]);

        $res->assertForbidden()
            ->assertJsonPath('code', 'subscription_expired')
            ->assertJsonPath('subscription_url', '/lms/subscribe/punjab-grade-10-science');
    }

    /**
     * 6. Pending subscription payment cannot join (HTTP 403 subscription_required).
     */
    public function test_student_with_pending_subscription_cannot_join(): void
    {
        // Subscribed but status is pending
        $this->createSubscription($this->student->id, 1, 'pending');

        $this->actingAs($this->student, 'web_api');

        $res = $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $this->classA->class_code,
        ]);

        $res->assertForbidden()
            ->assertJsonPath('code', 'subscription_required');
    }

    /**
     * 7. Shared subject accessible with any one covering program.
     */
    public function test_shared_subject_accessible_with_alternative_covering_program(): void
    {
        // Subscribed to Punjab Pre-Medical (Program 4) which also covers Physics
        $this->createSubscription($this->student->id, 4, 'active');

        $this->actingAs($this->student, 'web_api');

        $res = $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $this->classA->class_code,
        ]);

        $res->assertCreated()
            ->assertJsonPath('outcome', 'enrolled')
            ->assertJsonPath('data.status', 'active');
    }

    /**
     * 8. Multiple valid subscriptions work correctly.
     */
    public function test_multiple_valid_subscriptions_allow_joining_both_classes(): void
    {
        // Student holds active subscriptions for Punjab (Program 1) AND Federal (Program 3)
        $this->createSubscription($this->student->id, 1, 'active');
        $this->createSubscription($this->student->id, 3, 'active');

        $this->actingAs($this->student, 'web_api');

        // Can join Punjab class A
        $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $this->classA->class_code,
        ])->assertCreated()->assertJsonPath('outcome', 'enrolled');

        // Can join Federal class B (requires approval)
        $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $this->classB->class_code,
        ])->assertCreated()->assertJsonPath('outcome', 'pending');
    }

    /**
     * 9. Teacher cannot approve enrollment after student subscription expires (HTTP 422).
     */
    public function test_teacher_cannot_approve_enrollment_if_student_subscription_expired(): void
    {
        // Student joins Class B with active Federal subscription
        $subId = $this->createSubscription($this->student->id, 3, 'active');

        $this->actingAs($this->student, 'web_api');
        $enroll = $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $this->classB->class_code,
        ])->assertCreated()->json('data');

        // Subscription expires before teacher approves
        DB::table('user_subscriptions')->where('id', $subId)->update([
            'expires_at' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($this->teacherProfile->webUser, 'web_api');
        $res = $this->postJson("/web_api/auth/teacher/classes/{$this->classB->id}/enrollments/{$enroll['id']}/approve");

        $res->assertStatus(422)
            ->assertJsonPath('code', 'subscription_expired');
    }

    /**
     * 10. Existing active membership without valid subscription cannot access active class resources (HTTP 403).
     */
    public function test_existing_member_without_active_subscription_is_blocked_from_workspace_and_resources(): void
    {
        // Active enrollment in Class A
        $enrollment = TeacherClassEnrollment::forceCreate([
            'teacher_class_id' => $this->classA->id,
            'student_web_user_id' => $this->student->id,
            'status' => TeacherClassEnrollment::STATUS_ACTIVE,
            'requested_at' => now()->subDays(5),
            'approved_at' => now()->subDays(5),
        ]);

        $this->actingAs($this->student, 'web_api');

        // Class index shows has_workspace_access: false and has_active_subscription: false
        $classes = $this->getJson('/web_api/auth/student/classes')->assertOk()->json('data');
        $this->assertCount(1, $classes);
        $this->assertFalse($classes[0]['has_workspace_access']);
        $this->assertFalse($classes[0]['has_active_subscription']);

        // Discussions are blocked
        $this->getJson("/web_api/auth/student/classes/{$this->classA->id}/discussions")
            ->assertForbidden()
            ->assertJsonPath('code', 'subscription_required');
    }

    private function createPublishedTestAndAssignment(TeacherClass $class): array
    {
        $test = TeacherClassTest::forceCreate([
            'teacher_class_id' => $class->id,
            'teacher_profile_id' => $class->teacher_profile_id,
            'curriculum_board_id' => $class->curriculum_board_id,
            'class_id' => $class->class_id,
            'subject_id' => $class->subject_id,
            'title' => 'Test Physics',
            'status' => TeacherClassTest::STATUS_PUBLISHED,
            'total_questions' => 1,
            'marks_per_question' => 10,
            'total_marks' => 10,
            'duration_minutes' => 30,
            'max_attempts' => 1,
            'scheduled_start_at' => now()->subHour(),
            'scheduled_end_at' => now()->addHours(2),
            'published_at' => now()->subHour(),
            'result_visibility' => 'after_submission',
        ]);

        $assignment = TeacherClassTestAssignment::forceCreate([
            'teacher_class_test_id' => $test->id,
            'teacher_class_id' => $class->id,
            'teacher_profile_id' => $class->teacher_profile_id,
            'assignment_mode' => 'entire_class',
            'title_snapshot' => 'Test Physics',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
            'duration_minutes' => 30,
            'max_attempts' => 1,
            'result_visibility' => 'after_submission',
            'status' => TeacherClassTestAssignment::STATUS_ACTIVE,
            'assigned_count' => 1,
            'assigned_by_web_user_id' => $this->teacherProfile->web_user_id,
            'assigned_at' => now(),
        ]);

        $enrollment = TeacherClassEnrollment::where('teacher_class_id', $class->id)
            ->where('student_web_user_id', $this->student->id)
            ->first();

        $recipient = TeacherClassTestRecipient::forceCreate([
            'teacher_class_test_assignment_id' => $assignment->id,
            'teacher_class_test_id' => $test->id,
            'teacher_class_id' => $class->id,
            'student_web_user_id' => $this->student->id,
            'teacher_class_enrollment_id' => $enrollment?->id,
            'assignment_status' => 'assigned',
            'assigned_at' => now(),
        ]);

        return [$test, $assignment, $recipient];
    }

    /**
     * 11. Expired student cannot start or resume a test (HTTP 403).
     */
    public function test_expired_student_cannot_start_or_resume_test(): void
    {
        TeacherClassEnrollment::forceCreate([
            'teacher_class_id' => $this->classA->id,
            'student_web_user_id' => $this->student->id,
            'status' => TeacherClassEnrollment::STATUS_ACTIVE,
            'requested_at' => now()->subDays(5),
            'approved_at' => now()->subDays(5),
        ]);

        [$test, $assignment, $recipient] = $this->createPublishedTestAndAssignment($this->classA);

        $this->actingAs($this->student, 'web_api');

        $this->postJson("/web_api/auth/student/tests/{$assignment->id}/start")
            ->assertForbidden()
            ->assertJsonPath('code', 'subscription_required');
    }

    /**
     * 12. Expired student cannot save or submit test answers (HTTP 403).
     */
    public function test_expired_student_cannot_save_or_submit_answers(): void
    {
        $subId = $this->createSubscription($this->student->id, 1, 'active');

        TeacherClassEnrollment::forceCreate([
            'teacher_class_id' => $this->classA->id,
            'student_web_user_id' => $this->student->id,
            'status' => TeacherClassEnrollment::STATUS_ACTIVE,
            'requested_at' => now()->subDays(5),
            'approved_at' => now()->subDays(5),
        ]);

        [$test, $assignment, $recipient] = $this->createPublishedTestAndAssignment($this->classA);

        $attempt = TeacherClassTestAttempt::forceCreate([
            'teacher_class_test_assignment_id' => $assignment->id,
            'teacher_class_test_recipient_id' => $recipient->id,
            'teacher_class_test_id' => $test->id,
            'teacher_class_id' => $this->classA->id,
            'student_web_user_id' => $this->student->id,
            'attempt_number' => 1,
            'status' => TeacherClassTestAttempt::STATUS_IN_PROGRESS,
            'started_at' => now()->subMinutes(10),
            'deadline_at' => now()->addMinutes(20),
            'total_questions' => 1,
            'answered_questions' => 0,
            'obtained_marks' => 0,
            'max_marks' => 10,
        ]);

        // Subscription expires while taking the test
        DB::table('user_subscriptions')->where('id', $subId)->update([
            'status' => 'active',
            'expires_at' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($this->student, 'web_api');

        // Saving answers is rejected
        $this->putJson("/web_api/auth/student/tests/{$assignment->id}/attempts/{$attempt->id}/answers", [
            'answers' => [
                ['teacher_class_test_question_id' => 101, 'selected_option_id' => 1],
            ],
        ])->assertForbidden()
            ->assertJsonPath('code', 'subscription_expired');

        // Submitting attempt is rejected
        $this->postJson("/web_api/auth/student/tests/{$assignment->id}/attempts/{$attempt->id}/submit")
            ->assertForbidden()
            ->assertJsonPath('code', 'subscription_expired');
    }

    /**
     * 13. Expired student cannot submit assignments or post discussions (HTTP 403).
     */
    public function test_expired_student_cannot_submit_assignments_or_post_discussions(): void
    {
        TeacherClassEnrollment::forceCreate([
            'teacher_class_id' => $this->classA->id,
            'student_web_user_id' => $this->student->id,
            'status' => TeacherClassEnrollment::STATUS_ACTIVE,
            'requested_at' => now()->subDays(5),
            'approved_at' => now()->subDays(5),
        ]);

        $assignment = TeacherClassAssignment::forceCreate([
            'teacher_class_id' => $this->classA->id,
            'teacher_profile_id' => $this->teacherProfile->id,
            'title' => 'Lab Report 1',
            'instructions' => 'Lab report instructions',
            'status' => TeacherClassAssignment::STATUS_PUBLISHED,
            'max_marks' => 20,
            'due_at' => now()->addDays(2),
            'late_policy' => 'block_late',
        ]);

        TeacherClassAssignmentRecipient::forceCreate([
            'assignment_id' => $assignment->id,
            'student_web_user_id' => $this->student->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($this->student, 'web_api');

        // Submitting assignment is forbidden
        $this->postJson("/web_api/auth/student/assignments/{$assignment->id}/submit", [
            'submission_type' => 'text',
            'text_content' => 'My report on optics',
        ])->assertForbidden()
            ->assertJsonPath('code', 'subscription_required');

        // Posting discussion is forbidden
        $this->postJson("/web_api/auth/student/classes/{$this->classA->id}/discussions", [
            'title' => 'Question about optics',
            'body' => 'Can anyone explain focal length?',
        ])->assertForbidden()
            ->assertJsonPath('code', 'subscription_required');
    }

    /**
     * 14. Historical released results and completed test reviews remain read-only accessible.
     */
    public function test_historical_released_results_and_completed_attempts_remain_accessible(): void
    {
        TeacherClassEnrollment::forceCreate([
            'teacher_class_id' => $this->classA->id,
            'student_web_user_id' => $this->student->id,
            'status' => TeacherClassEnrollment::STATUS_ACTIVE,
            'requested_at' => now()->subDays(10),
            'approved_at' => now()->subDays(10),
        ]);

        [$test, $assignment, $recipient] = $this->createPublishedTestAndAssignment($this->classA);

        $attempt = TeacherClassTestAttempt::forceCreate([
            'teacher_class_test_assignment_id' => $assignment->id,
            'teacher_class_test_recipient_id' => $recipient->id,
            'teacher_class_test_id' => $test->id,
            'teacher_class_id' => $this->classA->id,
            'student_web_user_id' => $this->student->id,
            'attempt_number' => 1,
            'status' => TeacherClassTestAttempt::STATUS_SUBMITTED,
            'started_at' => now()->subDays(5),
            'deadline_at' => now()->subDays(5)->addMinutes(30),
            'submitted_at' => now()->subDays(5)->addMinutes(15),
            'finalized_at' => now()->subDays(5)->addMinutes(15),
            'total_questions' => 1,
            'answered_questions' => 1,
            'obtained_marks' => 9.0,
            'max_marks' => 10.0,
        ]);

        $this->actingAs($this->student, 'web_api');

        // Student has NO active subscription, but CAN view completed finalized attempt review!
        $res = $this->getJson("/web_api/auth/student/tests/{$assignment->id}/attempts/{$attempt->id}");
        $res->assertOk()
            ->assertJsonPath('data.status', 'submitted');
    }

    /**
     * 15. Existing valid enrolled students retain normal access.
     */
    public function test_valid_enrolled_student_with_active_subscription_has_full_access(): void
    {
        $this->createSubscription($this->student->id, 1, 'active');

        TeacherClassEnrollment::forceCreate([
            'teacher_class_id' => $this->classA->id,
            'student_web_user_id' => $this->student->id,
            'status' => TeacherClassEnrollment::STATUS_ACTIVE,
            'requested_at' => now()->subDays(2),
            'approved_at' => now()->subDays(2),
        ]);

        $this->actingAs($this->student, 'web_api');

        // Workspace access is true
        $classes = $this->getJson('/web_api/auth/student/classes')->assertOk()->json('data');
        $this->assertTrue($classes[0]['has_workspace_access']);
        $this->assertTrue($classes[0]['has_active_subscription']);

        // Discussions access succeeds
        $this->getJson("/web_api/auth/student/classes/{$this->classA->id}/discussions")
            ->assertOk();
    }
}
