<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\TeacherClass;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherClassAssignmentEvaluation;
use App\Models\TeacherClassAssignmentSubmission;
use App\Models\TeacherClassEnrollment;
use App\Models\TeacherClassTest;
use App\Models\TeacherClassTestAssignment;
use App\Models\TeacherClassTestAttempt;
use App\Models\TeacherClassTestRecipient;
use App\Models\TeacherProfile;
use App\Models\TeacherSubscriptionFeature;
use App\Models\User;
use App\Models\WebUser;
use App\Models\WebUserNotification;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TeacherClassPhase7GTest extends TeacherBackendTest
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
    private WebUser $rejectedStudent;
    private WebUser $removedStudent;
    private WebUser $leftStudent;
    private WebUser $outsiderStudent;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'class_tbl',
            'subject_tbl',
            'curriculum_board_tbl',
            'study_session_tbl',
            'book_tbl',
            'book_unit_tbl',
            'book_unit_topic_tbl',
            'admin_activity_logs',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('class_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('class_name');
            $t->boolean('activate')->default(true);
            $t->timestamps();
        });
        Schema::create('subject_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('subject_name');
            $t->boolean('activate')->default(true);
            $t->timestamps();
        });
        Schema::create('curriculum_board_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->boolean('activate')->default(true);
            $t->timestamps();
        });
        Schema::create('study_session_tbl', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('curriculum_board_id');
            $t->unsignedBigInteger('class_id');
            $t->string('name', 50);
            $t->date('start_date');
            $t->date('end_date');
            $t->boolean('is_default')->default(false);
            $t->boolean('activate')->default(true);
            $t->timestamps();
        });
        Schema::create('book_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('book_name');
            $t->unsignedBigInteger('curriculum_board_id');
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('subject_id');
            $t->boolean('activate')->default(true);
            $t->timestamps();
        });
        Schema::create('book_unit_tbl', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('book_id');
            $t->integer('unit_no')->default(1);
            $t->string('unit_name');
            $t->boolean('activate')->default(true);
            $t->timestamps();
        });
        Schema::create('book_unit_topic_tbl', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('unit_id');
            $t->string('topic_no', 30)->nullable();
            $t->string('topic_name');
            $t->boolean('activate')->default(true);
            $t->timestamps();
        });
        Schema::create('admin_activity_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('admin_user_id');
            $t->string('action');
            $t->string('module');
            $t->string('reference_type')->nullable();
            $t->unsignedBigInteger('reference_id')->nullable();
            $t->string('ip_address')->nullable();
            $t->text('user_agent')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        DB::table('class_tbl')->insert(['id' => 1, 'class_name' => 'Grade 10', 'activate' => 1]);
        DB::table('subject_tbl')->insert([
            ['id' => 1, 'subject_name' => 'Physics', 'activate' => 1],
            ['id' => 2, 'subject_name' => 'Chemistry', 'activate' => 1],
        ]);
        DB::table('curriculum_board_tbl')->insert(['id' => 1, 'name' => 'Punjab', 'activate' => 1]);
        DB::table('study_session_tbl')->insert([
            [
                'id' => 1,
                'curriculum_board_id' => 1,
                'class_id' => 1,
                'name' => '2025-26',
                'start_date' => '2025-04-01',
                'end_date' => '2026-03-31',
                'is_default' => 0,
                'activate' => 1,
            ],
            [
                'id' => 2,
                'curriculum_board_id' => 1,
                'class_id' => 1,
                'name' => '2026-27',
                'start_date' => '2026-04-01',
                'end_date' => '2027-03-31',
                'is_default' => 1,
                'activate' => 1,
            ],
        ]);
        DB::table('book_tbl')->insert([
            'id' => 1,
            'book_name' => 'Physics 10',
            'curriculum_board_id' => 1,
            'class_id' => 1,
            'subject_id' => 1,
            'activate' => 1,
        ]);
        DB::table('book_unit_tbl')->insert([
            ['id' => 10, 'book_id' => 1, 'unit_no' => 1, 'unit_name' => 'Unit 10 Optics', 'activate' => 1],
            ['id' => 11, 'book_id' => 1, 'unit_no' => 2, 'unit_name' => 'Unit 11 Electrostatics', 'activate' => 1],
        ]);
        DB::table('book_unit_topic_tbl')->insert([
            ['id' => 100, 'unit_id' => 10, 'topic_no' => '10.1', 'topic_name' => 'Refraction', 'activate' => 1],
            ['id' => 101, 'unit_id' => 11, 'topic_no' => '11.1', 'topic_name' => 'Coulomb Law', 'activate' => 1],
        ]);

        (require database_path('migrations/2026_11_11_000004_create_teacher_class_tests_and_questions_tables.php'))->up();
        (require database_path('migrations/2026_11_11_000005_create_phase7d_test_delivery_and_attempts_tables.php'))->up();
        (require database_path('migrations/2026_11_11_000007_create_phase7f_tables.php'))->up();
        (require database_path('migrations/2026_11_12_000001_create_phase7g_gradebook_and_evaluations_tables.php'))->up();

        $subscriptions = app(\App\Http\Services\TeacherSubscriptionService::class);

        $this->teacher = TeacherProfile::where('web_user_id', 2)->firstOrFail();
        $this->teacher->forceFill(['status' => 'active'])->save();
        $this->teacherUser = WebUser::findOrFail(2);
        $subscriptions->assignDefaultFree($this->teacher->id);

        // Ensure Free plan / teacher feature set enables reports.basic for default Gradebook tests
        $activeSub = $subscriptions->getCurrentSubscription($this->teacher->id);
        if ($activeSub) {
            TeacherSubscriptionFeature::updateOrCreate(
                ['teacher_subscription_plan_id' => $activeSub->teacher_subscription_plan_id, 'feature_key' => 'reports.basic'],
                ['is_enabled' => true]
            );
        }

        $this->otherTeacher = TeacherProfile::where('web_user_id', 3)->firstOrFail();
        $this->otherTeacher->forceFill(['status' => 'active'])->save();
        $this->otherTeacherUser = WebUser::findOrFail(3);
        $subscriptions->assignDefaultFree($this->otherTeacher->id);

        $this->class = $this->makeClass($this->teacher, 'Physics Section A', 'A');
        $this->otherClass = $this->makeClass($this->otherTeacher, 'Other Teacher Class', 'B');

        $this->studentA = WebUser::findOrFail(1);
        $this->studentB = WebUser::create([
            'name' => 'Student Beta',
            'email' => 'p7g_beta@example.test',
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->pendingStudent = WebUser::create([
            'name' => 'Pending Student',
            'email' => 'p7g_pending@example.test',
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->rejectedStudent = WebUser::create([
            'name' => 'Rejected Student',
            'email' => 'p7g_rejected@example.test',
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->removedStudent = WebUser::create([
            'name' => 'Removed Student',
            'email' => 'p7g_removed@example.test',
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->leftStudent = WebUser::create([
            'name' => 'Left Student',
            'email' => 'p7g_left@example.test',
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->outsiderStudent = WebUser::create([
            'name' => 'Outsider Student',
            'email' => 'p7g_outsider@example.test',
            'role' => 'student',
            'status' => 'active',
        ]);

        $now = Carbon::now('Asia/Karachi');
        foreach ([
            [$this->studentA->id, TeacherClassEnrollment::STATUS_ACTIVE],
            [$this->studentB->id, TeacherClassEnrollment::STATUS_ACTIVE],
            [$this->pendingStudent->id, TeacherClassEnrollment::STATUS_PENDING],
            [$this->rejectedStudent->id, TeacherClassEnrollment::STATUS_REJECTED],
            [$this->removedStudent->id, TeacherClassEnrollment::STATUS_REMOVED],
            [$this->leftStudent->id, TeacherClassEnrollment::STATUS_LEFT],
        ] as [$studentId, $status]) {
            TeacherClassEnrollment::forceCreate([
                'teacher_class_id' => $this->class->id,
                'student_web_user_id' => $studentId,
                'status' => $status,
                'requested_at' => $now->copy()->subDays(10),
                'approved_at' => $status === TeacherClassEnrollment::STATUS_ACTIVE ? $now->copy()->subDays(9) : null,
            ]);
        }

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
            DB::table('user_subscriptions')->insert([
                ['user_id' => $this->studentA->id, 'offered_program_id' => 1, 'status' => 'active', 'expires_at' => now()->addYear()->toDateString()],
                ['user_id' => $this->studentB->id, 'offered_program_id' => 1, 'status' => 'active', 'expires_at' => now()->addYear()->toDateString()],
                ['user_id' => $this->outsiderStudent->id, 'offered_program_id' => 1, 'status' => 'active', 'expires_at' => now()->addYear()->toDateString()],
                ['user_id' => $this->removedStudent->id, 'offered_program_id' => 1, 'status' => 'active', 'expires_at' => now()->addYear()->toDateString()],
                ['user_id' => $this->leftStudent->id, 'offered_program_id' => 1, 'status' => 'active', 'expires_at' => now()->addYear()->toDateString()],
            ]);
        }
    }

    private function makeClass(TeacherProfile $teacher, string $name, string $section): TeacherClass
    {
        return TeacherClass::forceCreate([
            'teacher_profile_id' => $teacher->id,
            'curriculum_board_id' => 1,
            'class_id' => 1,
            'subject_id' => 1,
            'section' => $section,
            'name' => $name,
            'class_code' => 'C7G' . strtoupper(substr(md5($name . uniqid('', true)), 0, 5)),
            'is_active' => true,
            'requires_approval' => true,
        ]);
    }

    private function seedAssignmentWithSubmission(
        WebUser $student,
        float $maxMarks = 20.0,
        string $status = TeacherClassAssignment::STATUS_PUBLISHED,
        ?Carbon $dueAt = null,
        bool $submitted = true,
        ?int $bookUnitId = 10,
        ?Carbon $publishedAt = null
    ): array {
        $now = Carbon::now('Asia/Karachi');
        $assignment = TeacherClassAssignment::create([
            'teacher_class_id' => $this->class->id,
            'teacher_profile_id' => $this->teacher->id,
            'created_by_web_user_id' => $this->teacherUser->id,
            'book_unit_id' => $bookUnitId,
            'book_unit_topic_id' => $bookUnitId === 10 ? 100 : null,
            'title' => 'Ray Optics Problem Set ' . uniqid(),
            'instructions' => 'Solve all questions clearly.',
            'max_marks' => $maxMarks,
            'assignment_mode' => TeacherClassAssignment::MODE_ENTIRE_CLASS,
            'status' => $status,
            'late_policy' => TeacherClassAssignment::LATE_ALLOW,
            'allow_resubmission' => true,
            'published_at' => $publishedAt ?? $now->copy()->subDays(2),
            'due_at' => $dueAt ?? $now->copy()->addDays(2),
        ]);

        $submission = null;
        if ($submitted) {
            $submission = TeacherClassAssignmentSubmission::create([
                'assignment_id' => $assignment->id,
                'teacher_class_id' => $this->class->id,
                'student_web_user_id' => $student->id,
                'status' => TeacherClassAssignmentSubmission::STATUS_SUBMITTED,
                'submission_version' => 1,
                'text_response' => 'Student derivation attached.',
                'submitted_at' => $now->copy()->subHour(),
            ]);
        }

        return [$assignment, $submission];
    }

    private function seedMcqTestAssignment(
        string $title = 'Wave Optics Quiz',
        string $visibility = \App\Models\TeacherClassTest::VISIBILITY_AFTER_SUBMISSION,
        ?Carbon $startsAt = null,
        ?Carbon $endsAt = null,
        int $maxAttempts = 3
    ): TeacherClassTestAssignment {
        $now = Carbon::now('Asia/Karachi');
        $test = TeacherClassTest::forceCreate([
            'teacher_class_id' => $this->class->id,
            'teacher_profile_id' => $this->teacher->id,
            'curriculum_board_id' => 1,
            'class_id' => 1,
            'subject_id' => 1,
            'title' => $title,
            'status' => 'published',
            'total_questions' => 10,
            'marks_per_question' => 1.00,
            'total_marks' => 10.00,
            'selection_method' => 'manual',
        ]);

        $ta = TeacherClassTestAssignment::forceCreate([
            'teacher_class_test_id' => $test->id,
            'teacher_class_id' => $this->class->id,
            'teacher_profile_id' => $this->teacher->id,
            'assigned_by_web_user_id' => $this->teacherUser->id,
            'assigned_at' => $now->copy()->subDay(),
            'title_snapshot' => $title,
            'assignment_mode' => TeacherClassTestAssignment::MODE_ENTIRE_CLASS,
            'status' => TeacherClassTestAssignment::STATUS_ACTIVE,
            'starts_at' => $startsAt ?? $now->copy()->subDays(1),
            'ends_at' => $endsAt ?? $now->copy()->addDays(2),
            'duration_minutes' => 20,
            'max_attempts' => $maxAttempts,
            'result_visibility' => $visibility,
        ]);

        foreach ([$this->studentA, $this->studentB] as $student) {
            TeacherClassTestRecipient::forceCreate([
                'teacher_class_test_assignment_id' => $ta->id,
                'teacher_class_test_id' => $test->id,
                'student_web_user_id' => $student->id,
                'teacher_class_id' => $this->class->id,
                'assigned_at' => $now->copy()->subDay(),
                'assignment_status' => TeacherClassTestRecipient::STATUS_ASSIGNED,
            ]);
        }

        return $ta;
    }

    private function addFinalizedAttempt(
        TeacherClassTestAssignment $ta,
        WebUser $student,
        int $attemptNumber,
        float $obtained,
        float $max = 10.0,
        ?Carbon $submittedAt = null
    ): TeacherClassTestAttempt {
        $recipient = TeacherClassTestRecipient::where('teacher_class_test_assignment_id', $ta->id)
            ->where('student_web_user_id', $student->id)
            ->firstOrFail();

        $pct = $max > 0 ? round(($obtained / $max) * 100, 2) : 0.0;
        $ts = $submittedAt ?? Carbon::now('Asia/Karachi')->subMinutes(5 - $attemptNumber);

        return TeacherClassTestAttempt::forceCreate([
            'teacher_class_test_assignment_id' => $ta->id,
            'teacher_class_test_recipient_id' => $recipient->id,
            'teacher_class_test_id' => $ta->teacher_class_test_id,
            'teacher_class_id' => $this->class->id,
            'student_web_user_id' => $student->id,
            'attempt_number' => $attemptNumber,
            'status' => TeacherClassTestAttempt::STATUS_SUBMITTED,
            'started_at' => $ts->copy()->subMinutes(10),
            'deadline_at' => $ts->copy()->addMinutes(10),
            'submitted_at' => $ts,
            'finalized_at' => $ts,
            'total_questions' => (int) $max,
            'answered_questions' => (int) $max,
            'correct_count' => (int) $obtained,
            'incorrect_count' => (int) ($max - $obtained),
            'unanswered_count' => 0,
            'max_marks' => $max,
            'obtained_marks' => $obtained,
            'percentage' => $pct,
            'submission_reason' => TeacherClassTestAttempt::REASON_MANUAL,
        ]);
    }

    public function test_assignment_evaluation_draft_privacy_validation_release_reopen_and_correction_history(): void
    {
        [$assignment] = $this->seedAssignmentWithSubmission($this->studentA, 20.0);

        $this->actingAs($this->teacherUser, 'web_api');

        // Validation: marks above max_marks rejected
        $this->postJson(
            "/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assignment->id}/submissions/{$this->studentA->id}/evaluate",
            ['action' => 'draft', 'obtained_marks' => 25, 'feedback_text' => 'Too high']
        )->assertUnprocessable();

        // Save valid draft
        $this->postJson(
            "/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assignment->id}/submissions/{$this->studentA->id}/evaluate",
            ['action' => 'draft', 'obtained_marks' => 15.5, 'feedback_text' => 'Good start, check step 3']
        )->assertOk()
            ->assertJsonPath('evaluation.status', 'draft')
            ->assertJsonPath('evaluation.obtained_marks', 15.5)
            ->assertJsonPath('evaluation.feedback_text', 'Good start, check step 3');

        // Updating draft marks without feedback_text preserves existing draft feedback
        $this->postJson(
            "/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assignment->id}/submissions/{$this->studentA->id}/evaluate",
            ['action' => 'draft', 'obtained_marks' => 16.0]
        )->assertOk()
            ->assertJsonPath('evaluation.obtained_marks', 16)
            ->assertJsonPath('evaluation.feedback_text', 'Good start, check step 3');

        // Draft privacy check: Student MUST NOT see draft marks or feedback anywhere
        $this->actingAs($this->studentA, 'web_api');
        $this->getJson("/web_api/auth/student/assignments/{$assignment->id}")
            ->assertOk()
            ->assertJsonPath('evaluation', null);

        $studentPerfDraft = $this->getJson('/web_api/auth/student/performance')->assertOk()->json();
        $this->assertNull($studentPerfDraft['summary']['overall_percentage']);
        $this->assertSame(0, $studentPerfDraft['summary']['evaluated_assignments_count']);
        $this->assertSame('pending_evaluation', $studentPerfDraft['assessments'][0]['result_status']);
        $this->assertNull($studentPerfDraft['assessments'][0]['obtained_marks']);
        $this->assertNull($studentPerfDraft['assessments'][0]['feedback_text']);

        // Release from draft without resending marks uses saved draft marks + sends notification
        $this->actingAs($this->teacherUser, 'web_api');
        $this->postJson(
            "/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assignment->id}/submissions/{$this->studentA->id}/evaluate",
            ['action' => 'release']
        )->assertOk()
            ->assertJsonPath('evaluation.status', 'released')
            ->assertJsonPath('evaluation.obtained_marks', 16);

        $releaseNotifications = WebUserNotification::where('recipient_web_user_id', $this->studentA->id)
            ->where('type', WebUserNotification::TYPE_ASSIGNMENT_EVALUATION_RELEASED)
            ->get();
        $this->assertCount(1, $releaseNotifications);
        $this->assertStringNotContainsString('Good start', (string) $releaseNotifications[0]->message);

        // Released conflict on direct release or draft revert
        $this->postJson(
            "/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assignment->id}/submissions/{$this->studentA->id}/evaluate",
            ['action' => 'release', 'obtained_marks' => 17]
        )->assertStatus(409);

        // Student sees released evaluation across assignment detail, student performance, and assessment detail
        $this->actingAs($this->studentA, 'web_api');
        $this->getJson("/web_api/auth/student/assignments/{$assignment->id}")
            ->assertOk()
            ->assertJsonPath('evaluation.status', 'released')
            ->assertJsonPath('evaluation.obtained_marks', 16)
            ->assertJsonPath('evaluation.percentage', 80)
            ->assertJsonPath('evaluation.feedback_text', 'Good start, check step 3');

        $this->getJson('/web_api/auth/student/performance')
            ->assertOk()
            ->assertJsonPath('summary.overall_percentage', 80)
            ->assertJsonPath('summary.evaluated_assignments_count', 1);

        $this->getJson("/web_api/auth/student/performance/assessments/{$assignment->id}?type=assignment")
            ->assertOk()
            ->assertJsonPath('evaluation.obtained_marks', 16);

        // Reopen requires reason >= 2 chars
        $this->actingAs($this->teacherUser, 'web_api');
        $this->postJson(
            "/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assignment->id}/submissions/{$this->studentA->id}/evaluate",
            ['action' => 'reopen', 'reason' => 'x']
        )->assertUnprocessable();

        $this->postJson(
            "/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assignment->id}/submissions/{$this->studentA->id}/evaluate",
            ['action' => 'reopen', 'reason' => 'Re-evaluating diagram marks']
        )->assertOk()
            ->assertJsonPath('evaluation.status', 'reopened');

        // While reopened, student again cannot view marks
        $this->actingAs($this->studentA, 'web_api');
        $this->getJson("/web_api/auth/student/assignments/{$assignment->id}")
            ->assertOk()
            ->assertJsonPath('evaluation', null);

        // Correct with reason restores release & appends history + notification
        $this->actingAs($this->teacherUser, 'web_api');
        $corrected = $this->postJson(
            "/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assignment->id}/submissions/{$this->studentA->id}/evaluate",
            [
                'action' => 'correct',
                'obtained_marks' => 18.5,
                'feedback_text' => 'Updated after diagram review',
                'reason' => 'Awarded partial derivation credit',
            ]
        )->assertOk()
            ->assertJsonPath('evaluation.status', 'released')
            ->assertJsonPath('evaluation.obtained_marks', 18.5)
            ->json('history');

        $actions = array_column($corrected, 'action');
        $this->assertContains('corrected', $actions);
        $this->assertContains('reopened', $actions);
        $this->assertContains('released', $actions);
        $this->assertContains('draft_saved', $actions);

        $this->assertSame(
            1,
            WebUserNotification::where('recipient_web_user_id', $this->studentA->id)
                ->where('type', WebUserNotification::TYPE_ASSIGNMENT_EVALUATION_CORRECTED)
                ->count()
        );
    }

    public function test_finalize_zero_for_past_due_or_closed_unsubmitted_assignments_only(): void
    {
        $now = Carbon::now('Asia/Karachi');
        [$futureAssign] = $this->seedAssignmentWithSubmission($this->studentB, 10.0, TeacherClassAssignment::STATUS_PUBLISHED, $now->copy()->addDay(), false);
        [$submittedAssign] = $this->seedAssignmentWithSubmission($this->studentB, 10.0, TeacherClassAssignment::STATUS_PUBLISHED, $now->copy()->subDay(), true);
        [$pastDueUnsubmitted] = $this->seedAssignmentWithSubmission($this->studentB, 10.0, TeacherClassAssignment::STATUS_PUBLISHED, $now->copy()->subDay(), false);

        $this->actingAs($this->teacherUser, 'web_api');

        $this->postJson(
            "/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$futureAssign->id}/submissions/{$this->studentB->id}/evaluate",
            ['action' => 'finalize_zero', 'reason' => 'Trying early']
        )->assertUnprocessable();

        $this->postJson(
            "/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$submittedAssign->id}/submissions/{$this->studentB->id}/evaluate",
            ['action' => 'finalize_zero', 'reason' => 'Student already submitted']
        )->assertUnprocessable();

        $this->postJson(
            "/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$pastDueUnsubmitted->id}/submissions/{$this->studentB->id}/evaluate",
            ['action' => 'finalize_zero', 'reason' => 'Past due deadline without submission']
        )->assertOk()
            ->assertJsonPath('evaluation.status', 'released')
            ->assertJsonPath('evaluation.evaluation_type', 'finalized_zero')
            ->assertJsonPath('evaluation.obtained_marks', 0);

        $this->actingAs($this->studentB, 'web_api');
        $this->getJson('/web_api/auth/student/performance')
            ->assertOk()
            ->assertJsonPath('summary.overall_percentage', 0)
            ->assertJsonPath('summary.evaluated_assignments_count', 1);
    }

    public function test_gradebook_retake_policies_weighting_exclusions_study_session_and_missing_exclusion(): void
    {
        $now = Carbon::now('Asia/Karachi');

        // Assignment in 2026-27 session: 16 / 20 (80%) for studentA, missing (unsubmitted) for studentB
        [$assignment2026] = $this->seedAssignmentWithSubmission($this->studentA, 20.0, TeacherClassAssignment::STATUS_PUBLISHED, $now->copy()->subHours(5), true, 10, Carbon::parse('2026-06-15 10:00:00', 'Asia/Karachi'));
        TeacherClassAssignmentEvaluation::create([
            'assignment_id' => $assignment2026->id,
            'teacher_class_id' => $this->class->id,
            'student_web_user_id' => $this->studentA->id,
            'evaluated_by_web_user_id' => $this->teacherUser->id,
            'status' => 'released',
            'evaluation_type' => 'graded',
            'obtained_marks' => 16.0,
            'max_marks_snapshot' => 20.0,
            'released_at' => $now,
        ]);

        // Historical Assignment in 2025-26 session (outside 2026-27)
        [$assignment2025] = $this->seedAssignmentWithSubmission($this->studentA, 10.0, TeacherClassAssignment::STATUS_CLOSED, Carbon::parse('2025-11-15 10:00:00', 'Asia/Karachi'), true, 11, Carbon::parse('2025-11-10 10:00:00', 'Asia/Karachi'));
        TeacherClassAssignmentEvaluation::create([
            'assignment_id' => $assignment2025->id,
            'teacher_class_id' => $this->class->id,
            'student_web_user_id' => $this->studentA->id,
            'evaluated_by_web_user_id' => $this->teacherUser->id,
            'status' => 'released',
            'evaluation_type' => 'graded',
            'obtained_marks' => 10.0,
            'max_marks_snapshot' => 10.0,
            'released_at' => $now,
        ]);

        // MCQ Test in Unit 10 (2026-27): StudentA attempts 6/10 then 8/10 then 4/10 (submitted in order)
        // Highest=8, Latest=4, Average=6.00
        $ta = $this->seedMcqTestAssignment(
            'Optics Test',
            \App\Models\TeacherClassTest::VISIBILITY_AFTER_SUBMISSION,
            Carbon::parse('2026-06-20 09:00:00', 'Asia/Karachi'),
            Carbon::parse('2026-06-25 09:00:00', 'Asia/Karachi'),
            3
        );
        $this->addFinalizedAttempt($ta, $this->studentA, 1, 6, 10, Carbon::parse('2026-06-21 10:00:00', 'Asia/Karachi'));
        $this->addFinalizedAttempt($ta, $this->studentA, 2, 8, 10, Carbon::parse('2026-06-21 11:00:00', 'Asia/Karachi'));
        $this->addFinalizedAttempt($ta, $this->studentA, 3, 4, 10, Carbon::parse('2026-06-21 12:00:00', 'Asia/Karachi'));

        $this->actingAs($this->teacherUser, 'web_api');

        // Default unweighted + highest:
        // StudentA: (16 + 10 + 8) / (20 + 10 + 10) = 34 / 40 = 85%
        // StudentB has 0 released/submitted -> overall_percentage MUST BE null (missing never treated as 0)
        $gb = $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/gradebook")->assertOk()->json();
        $rows = collect($gb['rows'])->keyBy(fn ($r) => $r['student']['id']);
        $this->assertEquals(85.0, $rows[$this->studentA->id]['overall_percentage']);
        $this->assertNull($rows[$this->studentB->id]['overall_percentage']);

        // Study session 2 (2026-27: 2026-04-01..2027-03-31) excludes the 2025-11 assignment -> (16 + 8) / (20 + 10) = 24 / 30 = 80%
        $gb2026 = $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/gradebook?study_session_id=2")->assertOk()->json();
        $rows2026 = collect($gb2026['rows'])->keyBy(fn ($r) => $r['student']['id']);
        $this->assertEquals(80.0, $rows2026[$this->studentA->id]['overall_percentage']);

        // Weighting validation failure when weights do not sum to 100
        $this->putJson("/web_api/auth/teacher/classes/{$this->class->id}/gradebook/config", [
            'retake_score_policy' => 'latest',
            'weighting_enabled' => true,
            'study_session_id' => 2,
            'categories' => [
                ['category_key' => 'mcq_test', 'label' => 'MCQ Tests', 'weight_percent' => 30, 'is_included' => true],
                ['category_key' => 'assignment', 'label' => 'Assignments', 'weight_percent' => 50, 'is_included' => true],
            ],
        ])->assertUnprocessable();

        // Update retake policy -> average (6/10 = 60%) & weighting_enabled -> 40% MCQ + 60% Assignment = 100%
        // In session 2 (2026-27): MCQ avg = 60% (weight 40), Assignment = 16/20 = 80% (weight 60) => 0.4*60 + 0.6*80 = 24 + 48 = 72%
        $this->putJson("/web_api/auth/teacher/classes/{$this->class->id}/gradebook/config", [
            'retake_score_policy' => 'average',
            'weighting_enabled' => true,
            'study_session_id' => 2,
            'categories' => [
                ['category_key' => 'mcq_test', 'label' => 'MCQ Tests', 'weight_percent' => 40, 'is_included' => true],
                ['category_key' => 'assignment', 'label' => 'Assignments', 'weight_percent' => 60, 'is_included' => true],
            ],
        ])->assertOk();

        $gbWeighted = $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/gradebook")->assertOk()->json();
        $rowsW = collect($gbWeighted['rows'])->keyBy(fn ($r) => $r['student']['id']);
        $this->assertEquals(72.0, $rowsW[$this->studentA->id]['overall_percentage']);
        // With study_session_id=2 saved in config: (6 + 16) / (10 + 20) = 22/30 = 73.33%
        $this->assertEquals(73.33, $rowsW[$this->studentA->id]['raw_percentage']);

        // Exclude MCQ test from gradebook -> weighted & raw both fall back to remaining included assignment category (80%)
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/gradebook/exclusions", [
            'assessment_type' => 'mcq_test',
            'source_id' => $ta->id,
            'is_excluded' => true,
        ])->assertOk();

        $gbAfterEx = $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/gradebook")->assertOk()->json();
        $rowsEx = collect($gbAfterEx['rows'])->keyBy(fn ($r) => $r['student']['id']);
        $this->assertEquals(80.0, $rowsEx[$this->studentA->id]['overall_percentage']);
    }

    public function test_unreleased_mcq_results_never_leak_marks_into_student_or_class_gradebook(): void
    {
        $ta = $this->seedMcqTestAssignment('Hidden Result Quiz', \App\Models\TeacherClassTest::VISIBILITY_MANUAL_RELEASE);
        $this->addFinalizedAttempt($ta, $this->studentA, 1, 9, 10);

        $this->actingAs($this->studentA, 'web_api');
        $perf = $this->getJson('/web_api/auth/student/performance')->assertOk()->json();
        $this->assertNull($perf['summary']['overall_percentage']);
        $this->assertSame('unreleased', $perf['assessments'][0]['result_status']);
        $this->assertNull($perf['assessments'][0]['obtained_marks']);

        $this->getJson("/web_api/auth/student/performance/assessments/{$ta->id}?type=mcq_test")
            ->assertOk()
            ->assertJsonPath('assessment.obtained_marks', null)
            ->assertJsonPath('attempts.0.obtained_marks', null);
    }

    public function test_student_performance_peer_isolation_and_historical_access(): void
    {
        [$historicalAssign] = $this->seedAssignmentWithSubmission($this->removedStudent, 10.0);
        TeacherClassAssignmentEvaluation::create([
            'assignment_id' => $historicalAssign->id,
            'teacher_class_id' => $this->class->id,
            'student_web_user_id' => $this->removedStudent->id,
            'evaluated_by_web_user_id' => $this->teacherUser->id,
            'status' => 'released',
            'evaluation_type' => 'graded',
            'obtained_marks' => 7.0,
            'max_marks_snapshot' => 10.0,
            'released_at' => Carbon::now('Asia/Karachi'),
        ]);

        [$newUnattemptedAssign] = $this->seedAssignmentWithSubmission($this->studentA, 10.0, TeacherClassAssignment::STATUS_PUBLISHED, null, false);

        // Removed student can still see their historical evaluated item, NOT the unattempted new assignment
        $this->actingAs($this->removedStudent, 'web_api');
        $removedPerf = $this->getJson('/web_api/auth/student/performance')->assertOk()->json();
        $this->assertCount(1, $removedPerf['assessments']);
        $this->assertEquals(70.0, $removedPerf['summary']['overall_percentage']);

        $this->getJson("/web_api/auth/student/performance/assessments/{$historicalAssign->id}?type=assignment")->assertOk();
        $this->getJson("/web_api/auth/student/performance/assessments/{$newUnattemptedAssign->id}?type=assignment")->assertForbidden();

        // Pending / rejected / outsider cannot access class assessments
        foreach ([$this->pendingStudent, $this->rejectedStudent, $this->outsiderStudent] as $blocked) {
            $this->actingAs($blocked, 'web_api');
            $p = $this->getJson('/web_api/auth/student/performance')->assertOk()->json();
            $this->assertCount(0, $p['assessments']);
            $this->getJson("/web_api/auth/student/performance/assessments/{$historicalAssign->id}?type=assignment")->assertForbidden();
        }
    }

    public function test_teacher_class_performance_analytics_and_feature_gates(): void
    {
        [$assign] = $this->seedAssignmentWithSubmission($this->studentA, 20.0);
        TeacherClassAssignmentEvaluation::create([
            'assignment_id' => $assign->id,
            'teacher_class_id' => $this->class->id,
            'student_web_user_id' => $this->studentA->id,
            'evaluated_by_web_user_id' => $this->teacherUser->id,
            'status' => 'released',
            'evaluation_type' => 'graded',
            'obtained_marks' => 8.0, // 40% -> needs attention (<50%)
            'max_marks_snapshot' => 20.0,
            'released_at' => Carbon::now('Asia/Karachi'),
        ]);

        // Unrelated teacher (even with active reports.basic) cannot access another teacher's class gradebook, performance, or evaluate endpoint
        $otherSub = app(\App\Http\Services\TeacherSubscriptionService::class)->getCurrentSubscription($this->otherTeacher->id);
        TeacherSubscriptionFeature::updateOrCreate(
            ['teacher_subscription_plan_id' => $otherSub->teacher_subscription_plan_id, 'feature_key' => 'reports.basic'],
            ['is_enabled' => true]
        );
        $this->actingAs($this->otherTeacherUser, 'web_api');
        $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/gradebook")->assertNotFound();
        $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/performance")->assertNotFound();
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assign->id}/submissions/{$this->studentA->id}/evaluate", [
            'action' => 'draft',
            'obtained_marks' => 5,
        ])->assertForbidden();

        // Class owner gets accurate analytics & students_with_missing detection
        $this->actingAs($this->teacherUser, 'web_api');
        $perf = $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/performance")->assertOk()->json();
        $this->assertSame(2, $perf['overview']['active_students_count']); // studentA + studentB only
        $this->assertEquals(40.0, $perf['overview']['class_average_percentage']);
        $this->assertSame($this->studentB->id, $perf['students_with_missing'][0]['student_id']);

        // Disable reports.basic -> teacher receives 403 on gradebook & performance
        $activeSub = app(\App\Http\Services\TeacherSubscriptionService::class)->getCurrentSubscription($this->teacher->id);
        TeacherSubscriptionFeature::where('teacher_subscription_plan_id', $activeSub->teacher_subscription_plan_id)
            ->where('feature_key', 'reports.basic')
            ->update(['is_enabled' => false]);

        $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/gradebook")->assertForbidden();
        $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/performance")->assertForbidden();
    }

    public function test_admin_academic_reports_read_only_and_permission_enforced(): void
    {
        [$assign] = $this->seedAssignmentWithSubmission($this->studentA, 20.0);
        TeacherClassAssignmentEvaluation::create([
            'assignment_id' => $assign->id,
            'teacher_class_id' => $this->class->id,
            'student_web_user_id' => $this->studentA->id,
            'evaluated_by_web_user_id' => $this->teacherUser->id,
            'status' => 'released',
            'evaluation_type' => 'graded',
            'obtained_marks' => 18.0,
            'max_marks_snapshot' => 20.0,
            'released_at' => Carbon::now('Asia/Karachi'),
        ]);

        // Unauthenticated (JWT cookie middleware stripped in TeacherBackendTest -> permission middleware denies with 403)
        $this->getJson('/api/admin/auth/academic-reports/overview')->assertForbidden();

        // Admin without academic-reports.view -> 403
        $limitedRole = Role::create(['name' => 'limited_admin']);
        $limitedAdmin = User::create(['name' => 'NoReports Admin', 'role_id' => $limitedRole->id]);
        $this->actingAs($limitedAdmin, 'api');
        $this->getJson('/api/admin/auth/academic-reports/overview')->assertForbidden();
        $this->getJson("/api/admin/auth/academic-reports/classes/{$this->class->id}")->assertForbidden();

        // Admin with academic-reports.view -> 200
        $reportRole = Role::create(['name' => 'academic_viewer']);
        $perm = Permission::where('name', 'academic-reports.view')->firstOrFail();
        DB::table('role_permissions')->insert(['role_id' => $reportRole->id, 'permission_id' => $perm->id]);
        $viewerAdmin = User::create(['name' => 'Reports Viewer', 'role_id' => $reportRole->id]);

        $this->actingAs($viewerAdmin, 'api');
        $this->getJson('/api/admin/auth/academic-reports/overview')
            ->assertOk()
            ->assertJsonPath('overview.released_assignment_evaluations_count', 1);

        $this->getJson('/api/admin/auth/academic-reports/classes')
            ->assertOk()
            ->assertJsonPath('pagination.total', 2);

        $this->getJson("/api/admin/auth/academic-reports/classes/{$this->class->id}")
            ->assertOk()
            ->assertJsonPath('overview.class_average_percentage', 90);

        $this->getJson("/api/admin/auth/academic-reports/students/{$this->studentA->id}")
            ->assertOk()
            ->assertJsonPath('summary.overall_percentage', 90);
    }

    public function test_audit_exact_retake_unweighted_weighted_and_six_surface_consistency(): void
    {
        $now = Carbon::now('Asia/Karachi');

        // Assessment A (MCQ): 3 finalized attempts 9/10, 7/10, 8/10 + 1 unsubmitted in_progress attempt (must be ignored)
        $ta = $this->seedMcqTestAssignment(
            'Deterministic Retake MCQ',
            TeacherClassTest::VISIBILITY_AFTER_SUBMISSION,
            $now->copy()->subHours(5),
            $now->copy()->addHours(5),
            5
        );
        $this->addFinalizedAttempt($ta, $this->studentA, 1, 9, 10, $now->copy()->subMinutes(40));
        $this->addFinalizedAttempt($ta, $this->studentA, 2, 7, 10, $now->copy()->subMinutes(30));
        $this->addFinalizedAttempt($ta, $this->studentA, 3, 8, 10, $now->copy()->subMinutes(20));

        $recipient = TeacherClassTestRecipient::where('teacher_class_test_assignment_id', $ta->id)
            ->where('student_web_user_id', $this->studentA->id)
            ->firstOrFail();
        TeacherClassTestAttempt::forceCreate([
            'teacher_class_test_assignment_id' => $ta->id,
            'teacher_class_test_recipient_id' => $recipient->id,
            'teacher_class_test_id' => $ta->teacher_class_test_id,
            'teacher_class_id' => $this->class->id,
            'student_web_user_id' => $this->studentA->id,
            'attempt_number' => 4,
            'status' => TeacherClassTestAttempt::STATUS_IN_PROGRESS,
            'started_at' => $now->copy()->subMinutes(2),
            'deadline_at' => $now->copy()->addMinutes(28),
            'total_questions' => 10,
            'max_marks' => 10,
            'obtained_marks' => 0,
            'percentage' => 0,
        ]);

        // Inactive/removed student attempt should NOT contaminate active class stats
        TeacherClassTestRecipient::forceCreate([
            'teacher_class_test_assignment_id' => $ta->id,
            'teacher_class_test_id' => $ta->teacher_class_test_id,
            'student_web_user_id' => $this->removedStudent->id,
            'teacher_class_id' => $this->class->id,
            'assigned_at' => $now->copy()->subDay(),
            'assignment_status' => TeacherClassTestRecipient::STATUS_ASSIGNED,
        ]);
        $this->addFinalizedAttempt($ta, $this->removedStudent, 1, 1, 10, $now->copy()->subMinutes(15));

        // Assessment B (Assignment): 18/20 released for studentA
        [$assignB] = $this->seedAssignmentWithSubmission($this->studentA, 20.0);
        TeacherClassAssignmentEvaluation::create([
            'assignment_id' => $assignB->id,
            'teacher_class_id' => $this->class->id,
            'student_web_user_id' => $this->studentA->id,
            'evaluated_by_web_user_id' => $this->teacherUser->id,
            'status' => 'released',
            'evaluation_type' => 'graded',
            'obtained_marks' => 18.0,
            'max_marks_snapshot' => 20.0,
            'released_at' => $now,
        ]);

        // Unrelated Pending assignment (/10) + Draft evaluation (/10) + Excluded assignment (0/10) must NOT alter totals
        $this->seedAssignmentWithSubmission($this->studentA, 10.0, TeacherClassAssignment::STATUS_PUBLISHED, $now->copy()->addDay(), false);
        [$draftAssign] = $this->seedAssignmentWithSubmission($this->studentA, 10.0);
        TeacherClassAssignmentEvaluation::create([
            'assignment_id' => $draftAssign->id,
            'teacher_class_id' => $this->class->id,
            'student_web_user_id' => $this->studentA->id,
            'evaluated_by_web_user_id' => $this->teacherUser->id,
            'status' => 'draft',
            'evaluation_type' => 'graded',
            'obtained_marks' => 2.0,
            'max_marks_snapshot' => 10.0,
        ]);
        [$exclAssign] = $this->seedAssignmentWithSubmission($this->studentA, 10.0);
        TeacherClassAssignmentEvaluation::create([
            'assignment_id' => $exclAssign->id,
            'teacher_class_id' => $this->class->id,
            'student_web_user_id' => $this->studentA->id,
            'evaluated_by_web_user_id' => $this->teacherUser->id,
            'status' => 'released',
            'evaluation_type' => 'graded',
            'obtained_marks' => 0.0,
            'max_marks_snapshot' => 10.0,
            'released_at' => $now,
        ]);

        $this->actingAs($this->teacherUser, 'web_api');
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/gradebook/exclusions", [
            'assessment_type' => 'assignment',
            'source_id' => $exclAssign->id,
            'is_excluded' => true,
        ])->assertOk();

        // 1. Default highest retake (9/10)
        $gbHighest = $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/gradebook")->assertOk()->json();
        $rowHigh = collect($gbHighest['rows'])->firstWhere('student.id', $this->studentA->id);
        $this->assertEquals(9.0, $rowHigh['cells']["mcq_test:{$ta->id}"]['obtained_marks']);

        // 2. Average retake (9,7,8 -> avg 8/10, ignoring in_progress 0) + unweighted => (8+18)/(10+20) = 26/30 = 86.67%
        $this->putJson("/web_api/auth/teacher/classes/{$this->class->id}/gradebook/config", [
            'retake_score_policy' => 'average',
            'weighting_enabled' => false,
            'categories' => [
                ['category_key' => 'mcq_test', 'label' => 'MCQ', 'weight_percent' => 40, 'is_included' => true],
                ['category_key' => 'assignment', 'label' => 'Assignments', 'weight_percent' => 60, 'is_included' => true],
            ],
        ])->assertOk();

        $gbAvg = $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/gradebook")->assertOk()->json();
        $rowAvg = collect($gbAvg['rows'])->firstWhere('student.id', $this->studentA->id);
        $this->assertEquals(8.0, $rowAvg['cells']["mcq_test:{$ta->id}"]['obtained_marks']);
        $this->assertEquals(26.0, $rowAvg['total_obtained_marks']);
        $this->assertEquals(30.0, $rowAvg['total_available_marks']);
        $this->assertEquals(86.67, $rowAvg['overall_percentage']);

        // 3. Switch to latest (8/10) + weighted (40% MCQ=80% + 60% Assignment=90% => 86.0%, raw stays 26/30 = 86.67%)
        $this->putJson("/web_api/auth/teacher/classes/{$this->class->id}/gradebook/config", [
            'retake_score_policy' => 'latest',
            'weighting_enabled' => true,
            'categories' => [
                ['category_key' => 'mcq_test', 'label' => 'MCQ', 'weight_percent' => 40, 'is_included' => true],
                ['category_key' => 'assignment', 'label' => 'Assignments', 'weight_percent' => 60, 'is_included' => true],
            ],
        ])->assertOk();

        // Cross-check ALL 6 surfaces agree on 86.0% weighted + 86.67% raw + 8/10 MCQ + 18/20 Assignment
        $gbW = $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/gradebook")->assertOk()->json();
        $rowW = collect($gbW['rows'])->firstWhere('student.id', $this->studentA->id);
        $this->assertEquals(26.0, $rowW['total_obtained_marks']);
        $this->assertEquals(30.0, $rowW['total_available_marks']);
        $this->assertEquals(86.67, $rowW['raw_percentage']);
        $this->assertEquals(86.0, $rowW['weighted_percentage']);
        $this->assertEquals(86.0, $rowW['overall_percentage']);

        $tPerf = $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/performance")->assertOk()->json();
        $this->assertEquals(86.0, $tPerf['overview']['class_average_percentage']);
        $this->assertSame(2, $tPerf['overview']['active_students_count']);

        $this->actingAs($this->studentA, 'web_api');
        $sPerfUnfiltered = $this->getJson('/web_api/auth/student/performance')->assertOk()->json();
        $this->assertEquals(86.0, $sPerfUnfiltered['summary']['overall_percentage']);
        $this->assertEquals(86.67, $sPerfUnfiltered['summary']['raw_percentage']);
        $this->assertSame('weighted', $sPerfUnfiltered['summary']['calculation_mode']);
        $this->assertEquals(26.0, $sPerfUnfiltered['summary']['raw_obtained_marks']);
        $this->assertEquals(30.0, $sPerfUnfiltered['summary']['raw_max_marks']);

        $sMcqDetail = $this->getJson("/web_api/auth/student/performance/assessments/{$ta->id}?type=mcq_test")->assertOk()->json();
        $this->assertEquals(8.0, $sMcqDetail['assessment']['obtained_marks']);
        $this->assertEquals(80.0, $sMcqDetail['assessment']['percentage']);
        $this->assertSame('latest', $sMcqDetail['assessment']['retake_score_policy']);

        $reportRole = Role::create(['name' => 'audit_viewer']);
        $perm = Permission::where('name', 'academic-reports.view')->firstOrFail();
        DB::table('role_permissions')->insert(['role_id' => $reportRole->id, 'permission_id' => $perm->id]);
        $admin = User::create(['name' => 'Audit Viewer', 'role_id' => $reportRole->id]);
        $this->actingAs($admin, 'api');

        $aClass = $this->getJson("/api/admin/auth/academic-reports/classes/{$this->class->id}")->assertOk()->json();
        $this->assertEquals(86.0, $aClass['overview']['class_average_percentage']);
        $aStudent = $this->getJson("/api/admin/auth/academic-reports/students/{$this->studentA->id}")->assertOk()->json();
        $this->assertEquals(86.0, $aStudent['summary']['overall_percentage']);
        $this->assertEquals(86.67, $aStudent['summary']['raw_percentage']);
    }

    public function test_audit_reopen_draft_correct_lifecycle_partial_categories_and_admin_filters(): void
    {
        [$assign] = $this->seedAssignmentWithSubmission($this->studentA, 20.0);

        $this->actingAs($this->teacherUser, 'web_api');
        $baseUrl = "/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assign->id}/submissions/{$this->studentA->id}/evaluate";

        $this->postJson($baseUrl, ['action' => 'draft', 'obtained_marks' => 14, 'feedback_text' => 'Initial draft'])->assertOk();
        $this->postJson($baseUrl, ['action' => 'release'])->assertOk()->assertJsonPath('evaluation.status', 'released');
        $this->postJson($baseUrl, ['action' => 'reopen', 'reason' => 'Re-check question 2'])->assertOk()->assertJsonPath('evaluation.status', 'reopened');

        // Saving an intermediate private draft while reopened preserves 'reopened' state & allows subsequent 'correct'
        $this->postJson($baseUrl, ['action' => 'draft', 'obtained_marks' => 17, 'feedback_text' => 'Private interim edit'])
            ->assertOk()
            ->assertJsonPath('evaluation.status', 'reopened');

        // Student still cannot see reopened evaluation
        $this->actingAs($this->studentA, 'web_api');
        $this->getJson("/web_api/auth/student/assignments/{$assign->id}")
            ->assertOk()
            ->assertJsonPath('evaluation', null);

        $this->actingAs($this->teacherUser, 'web_api');
        $corrected = $this->postJson($baseUrl, [
            'action' => 'correct',
            'obtained_marks' => 18,
            'feedback_text' => 'Verified final correction',
            'reason' => 'Confirmed full method marks',
        ])->assertOk()
            ->assertJsonPath('evaluation.status', 'released')
            ->assertJsonPath('evaluation.obtained_marks', 18)
            ->json('history');

        $this->assertCount(5, $corrected);

        // Partial category omission when weighting enabled does NOT invent 50% for omitted categories
        $cfgRes = $this->putJson("/web_api/auth/teacher/classes/{$this->class->id}/gradebook/config", [
            'retake_score_policy' => 'highest',
            'weighting_enabled' => true,
            'categories' => [
                ['category_key' => 'mcq_test', 'label' => 'MCQ Only', 'weight_percent' => 100, 'is_included' => true],
            ],
        ])->assertOk()->json('config');
        $assignCat = collect($cfgRes['categories'])->firstWhere('category_key', 'assignment');
        $this->assertFalse($assignCat['is_included']);
        $this->assertEquals(0.0, $assignCat['weight_percent']);

        // Admin filters: class_id, teacher_id, is_active
        $reportRole = Role::create(['name' => 'audit_filter_viewer']);
        $perm = Permission::where('name', 'academic-reports.view')->firstOrFail();
        DB::table('role_permissions')->insert(['role_id' => $reportRole->id, 'permission_id' => $perm->id]);
        $admin = User::create(['name' => 'Filter Viewer', 'role_id' => $reportRole->id]);
        $this->actingAs($admin, 'api');

        $this->getJson("/api/admin/auth/academic-reports/classes?class_id=1&teacher_id={$this->teacher->id}&is_active=1")
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('classes.0.id', $this->class->id);

        $this->getJson('/api/admin/auth/academic-reports/classes?is_active=0')
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);
    }
}
