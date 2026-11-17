<?php

namespace Tests\Feature;

use App\Http\Services\TeacherSubjectResourceService;
use App\Models\TeacherClass;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherClassAssignmentSubmission;
use App\Models\TeacherClassAttendanceSession;
use App\Models\TeacherClassCalendarEvent;
use App\Models\TeacherClassEnrollment;
use App\Models\TeacherClassTest;
use App\Models\TeacherClassTestAssignment;
use App\Models\TeacherClassTestRecipient;
use App\Models\TeacherProfile;
use App\Models\TeacherSubscriptionFeature;
use App\Models\WebUser;
use App\Models\WebUserNotification;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class TeacherClassPhase7FTest extends TeacherBackendTest
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

        Storage::fake('local');

        foreach (['class_tbl', 'subject_tbl', 'curriculum_board_tbl', 'book_tbl', 'book_unit_tbl', 'book_unit_topic_tbl'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('class_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('class_name');
            $t->string('class_name_um')->nullable();
            $t->boolean('activate')->default(true);
            $t->timestamps();
        });
        Schema::create('subject_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('subject_name');
            $t->string('subject_name_um')->nullable();
            $t->boolean('activate')->default(true);
            $t->timestamps();
        });
        Schema::create('curriculum_board_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('name');
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
            $t->string('topic_name_um')->nullable();
            $t->boolean('activate')->default(true);
            $t->timestamps();
        });

        DB::table('class_tbl')->insert(['id' => 1, 'class_name' => 'Grade 10', 'activate' => 1]);
        DB::table('subject_tbl')->insert([
            ['id' => 1, 'subject_name' => 'Physics', 'activate' => 1],
            ['id' => 2, 'subject_name' => 'Chemistry', 'activate' => 1],
        ]);
        DB::table('curriculum_board_tbl')->insert(['id' => 1, 'name' => 'Punjab', 'activate' => 1]);
        DB::table('book_tbl')->insert([
            ['id' => 1, 'book_name' => 'Physics 10', 'curriculum_board_id' => 1, 'class_id' => 1, 'subject_id' => 1, 'activate' => 1],
            ['id' => 2, 'book_name' => 'Chemistry 10', 'curriculum_board_id' => 1, 'class_id' => 1, 'subject_id' => 2, 'activate' => 1],
        ]);
        DB::table('book_unit_tbl')->insert([
            ['id' => 10, 'book_id' => 1, 'unit_no' => 1, 'unit_name' => 'Simple Harmonic Motion', 'activate' => 1],
            ['id' => 20, 'book_id' => 2, 'unit_no' => 1, 'unit_name' => 'Chemical Equilibrium', 'activate' => 1],
        ]);
        DB::table('book_unit_topic_tbl')->insert([
            ['id' => 100, 'unit_id' => 10, 'topic_no' => '10.1', 'topic_name' => 'Pendulum Motion', 'activate' => 1],
            ['id' => 200, 'unit_id' => 20, 'topic_no' => '1.1', 'topic_name' => 'Reversible Reactions', 'activate' => 1],
        ]);

        (require database_path('migrations/2026_11_11_000004_create_teacher_class_tests_and_questions_tables.php'))->up();
        (require database_path('migrations/2026_11_11_000005_create_phase7d_test_delivery_and_attempts_tables.php'))->up();
        (require database_path('migrations/2026_11_11_000007_create_phase7f_tables.php'))->up();

        $subscriptions = app(\App\Http\Services\TeacherSubscriptionService::class);

        $this->teacher = TeacherProfile::where('web_user_id', 2)->firstOrFail();
        $this->teacher->forceFill(['status' => 'active'])->save();
        $this->teacherUser = WebUser::findOrFail(2);
        $subscriptions->assignDefaultFree($this->teacher->id);

        $this->otherTeacher = TeacherProfile::where('web_user_id', 3)->firstOrFail();
        $this->otherTeacher->forceFill(['status' => 'active'])->save();
        $this->otherTeacherUser = WebUser::findOrFail(3);
        $subscriptions->assignDefaultFree($this->otherTeacher->id);

        $this->class = $this->makeClass($this->teacher, 'Physics Cohort A', 'A');
        $this->otherClass = $this->makeClass($this->otherTeacher, 'Other Teacher Class', 'A');

        $this->studentA = WebUser::findOrFail(1);
        $this->studentB = WebUser::create([
            'name' => 'Student Two',
            'email' => 'p7f_student_b@example.test',
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->pendingStudent = WebUser::create([
            'name' => 'Pending Student',
            'email' => 'p7f_pending@example.test',
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->rejectedStudent = WebUser::create([
            'name' => 'Rejected Student',
            'email' => 'p7f_rejected@example.test',
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->removedStudent = WebUser::create([
            'name' => 'Removed Student',
            'email' => 'p7f_removed@example.test',
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->leftStudent = WebUser::create([
            'name' => 'Left Student',
            'email' => 'p7f_left@example.test',
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->outsiderStudent = WebUser::create([
            'name' => 'Outsider Student',
            'email' => 'p7f_outsider@example.test',
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->enroll($this->class, $this->studentA, TeacherClassEnrollment::STATUS_ACTIVE);
        $this->enroll($this->class, $this->studentB, TeacherClassEnrollment::STATUS_ACTIVE);
        $this->enroll($this->class, $this->pendingStudent, TeacherClassEnrollment::STATUS_PENDING);
        $this->enroll($this->class, $this->rejectedStudent, TeacherClassEnrollment::STATUS_REJECTED);
        $this->enroll($this->class, $this->removedStudent, TeacherClassEnrollment::STATUS_REMOVED);
        $this->enroll($this->class, $this->leftStudent, TeacherClassEnrollment::STATUS_LEFT);
        $this->enroll($this->otherClass, $this->outsiderStudent, TeacherClassEnrollment::STATUS_ACTIVE);

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_assignments_crud_scheduling_lifecycle_curriculum_scope_and_recipient_targeting(): void
    {
        $now = Carbon::create(2026, 11, 15, 10, 0, 0, 'Asia/Karachi');
        Carbon::setTestNow($now);

        $this->actingAs($this->teacherUser, 'web_api');

        // Reject unit from Chemistry (wrong subject for Physics class)
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments", [
            'title' => 'Wrong Unit Homework',
            'instructions' => 'Invalid curriculum unit.',
            'book_unit_id' => 20,
            'assignment_mode' => 'entire_class',
            'action' => 'draft',
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_curriculum_unit');

        // Reject mismatched topic under valid unit
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments", [
            'title' => 'Wrong Topic Homework',
            'instructions' => 'Invalid topic.',
            'book_unit_id' => 10,
            'book_unit_topic_id' => 200,
            'assignment_mode' => 'entire_class',
            'action' => 'draft',
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_curriculum_topic');

        // Reject ineligible pending/removed/outsider student in selected_students mode
        foreach ([$this->pendingStudent->id, $this->rejectedStudent->id, $this->removedStudent->id, $this->leftStudent->id, $this->outsiderStudent->id] as $badId) {
            $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments", [
                'title' => 'Target Test',
                'instructions' => 'Targeted',
                'assignment_mode' => 'selected_students',
                'student_ids' => [$badId],
                'action' => 'draft',
            ])->assertStatus(422)->assertJsonPath('code', 'ineligible_assignment_recipient');
        }

        // Create scheduled assignment targeting ONLY studentA with valid unit & topic
        $res = $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments", [
            'title' => 'Pendulum Lab Report',
            'instructions' => 'Measure oscillation period for 3 lengths.',
            'book_unit_id' => 10,
            'book_unit_topic_id' => 100,
            'max_marks' => 25,
            'assignment_mode' => 'selected_students',
            'student_ids' => [$this->studentA->id],
            'action' => 'schedule',
            'scheduled_publish_at' => $now->copy()->addHours(2)->format('Y-m-d H:i:s'),
            'due_at' => $now->copy()->addHours(12)->format('Y-m-d H:i:s'),
            'late_policy' => 'allow_late',
            'allow_resubmission' => true,
        ])->assertCreated()->json();

        $assignId = $res['assignment']['id'];
        $this->assertEquals('scheduled', $res['assignment']['status']);

        // Other teacher cannot access or mutate
        $this->actingAs($this->otherTeacherUser, 'web_api');
        $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments")->assertForbidden();
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assignId}/publish")->assertForbidden();

        // Scheduled assignment invisible to student before publish time
        $this->actingAs($this->studentA, 'web_api');
        $this->getJson('/web_api/auth/student/assignments')->assertOk()->assertJsonCount(0, 'assignments');
        $this->getJson("/web_api/auth/student/assignments/{$assignId}")->assertNotFound();

        // Advance clock past scheduled publish & run scheduler command twice (idempotent notifications)
        Carbon::setTestNow($now->copy()->addHours(3));
        Artisan::call('phase7f:process-scheduled');
        Artisan::call('phase7f:process-scheduled');

        $this->assertEquals('published', TeacherClassAssignment::findOrFail($assignId)->status);
        $this->assertEquals(
            1,
            WebUserNotification::where('recipient_web_user_id', $this->studentA->id)
                ->where('type', WebUserNotification::TYPE_ASSIGNMENT_PUBLISHED)
                ->count()
        );
        // Due reminder (within 24h) also dispatched once
        $this->assertEquals(
            1,
            WebUserNotification::where('recipient_web_user_id', $this->studentA->id)
                ->where('type', WebUserNotification::TYPE_ASSIGNMENT_DUE_REMINDER)
                ->count()
        );
        // Non-selected studentB received zero notifications & cannot open assignment
        $this->assertEquals(
            0,
            WebUserNotification::where('recipient_web_user_id', $this->studentB->id)->count()
        );

        $this->actingAs($this->studentB, 'web_api');
        $this->getJson("/web_api/auth/student/assignments/{$assignId}")->assertForbidden();

        // Teacher closes, cannot delete published/closed, can reopen and archive
        $this->actingAs($this->teacherUser, 'web_api');
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assignId}/close")
            ->assertOk()->assertJsonPath('assignment.status', 'closed');
        $this->deleteJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assignId}")
            ->assertStatus(422)->assertJsonPath('code', 'cannot_delete_published_assignment');
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assignId}/reopen")
            ->assertOk()->assertJsonPath('assignment.status', 'published');
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assignId}/archive")
            ->assertOk()->assertJsonPath('assignment.status', 'archived');
        $this->putJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assignId}", [
            'title' => 'Blocked Edit',
        ])->assertStatus(422)->assertJsonPath('code', 'assignment_archived');
    }

    public function test_student_submissions_draft_submit_resubmission_versions_late_policy_and_private_files(): void
    {
        $now = Carbon::create(2026, 11, 15, 9, 0, 0, 'Asia/Karachi');
        Carbon::setTestNow($now);
        $this->actingAs($this->teacherUser, 'web_api');

        $pdfFile = UploadedFile::fake()->create('worksheet.pdf', 120, 'application/pdf');
        $badPhpFile = UploadedFile::fake()->create('shell.php', 10, 'application/x-php');

        // Reject invalid attachment extension
        $this->post("/web_api/auth/teacher/classes/{$this->class->id}/assignments", [
            'title' => 'Bad Attachment',
            'instructions' => 'Should fail',
            'assignment_mode' => 'entire_class',
            'action' => 'publish',
            'attachments' => [$badPhpFile],
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('code', 'attachment_type_not_allowed');

        // Create published assignment with private PDF attachment (block_late disabled -> allow_late enabled)
        $created = $this->post("/web_api/auth/teacher/classes/{$this->class->id}/assignments", [
            'title' => 'Kinematics Problem Set',
            'instructions' => 'Solve questions 1-5 cleanly.',
            'assignment_mode' => 'entire_class',
            'action' => 'publish',
            'due_at' => $now->copy()->addHours(4)->format('Y-m-d H:i:s'),
            'late_policy' => 'allow_late',
            'allow_resubmission' => '1',
            'attachments' => [$pdfFile],
        ], ['Accept' => 'application/json'])->assertCreated()->json();

        $assignId = $created['assignment']['id'];
        $teacherAtt = $created['assignment']['attachments'][0];
        $this->assertArrayNotHasKey('storage_key', $teacherAtt);
        $this->assertArrayNotHasKey('storage_disk', $teacherAtt);

        // Unauthorized students cannot access or download teacher attachment
        foreach ([$this->pendingStudent, $this->rejectedStudent, $this->removedStudent, $this->leftStudent, $this->outsiderStudent] as $blockedStudent) {
            $this->actingAs($blockedStudent, 'web_api');
            $this->getJson("/web_api/auth/student/assignments/{$assignId}")->assertForbidden();
            $this->get("/web_api/auth/student/assignments/{$assignId}/attachments/{$teacherAtt['id']}/download")->assertForbidden();
        }

        // Student A downloads teacher attachment securely
        $this->actingAs($this->studentA, 'web_api');
        $dl = $this->get("/web_api/auth/student/assignments/{$assignId}/attachments/{$teacherAtt['id']}/download");
        $dl->assertOk();
        $this->assertStringContainsString('nosniff', strtolower((string) $dl->headers->get('X-Content-Type-Options')));
        $this->assertStringContainsString('no-store', strtolower((string) $dl->headers->get('Cache-Control')));

        // Reject >5 attachments or >10MB file
        $tooMany = [];
        for ($i = 0; $i < 6; $i++) {
            $tooMany[] = UploadedFile::fake()->create("page{$i}.txt", 10, 'text/plain');
        }
        $this->post("/web_api/auth/student/assignments/{$assignId}/draft", [
            'text_response' => 'Draft text',
            'attachments' => $tooMany,
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('code', 'attachment_limit_exceeded');

        // Student A saves draft first -> computed_status stays pending
        $draftRes = $this->post("/web_api/auth/student/assignments/{$assignId}/draft", [
            'text_response' => 'Draft work in progress',
            'attachments' => [UploadedFile::fake()->image('diagram.png', 100, 100)],
        ], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertEquals('pending', $draftRes['assignment']['computed_status']);
        $this->assertEquals('draft', $draftRes['submission']['status']);

        // Submit v1 cleanly on time
        $subV1 = $this->post("/web_api/auth/student/assignments/{$assignId}/submit", [
            'text_response' => 'Final v1 solution',
        ], ['Accept' => 'application/json'])->assertOk()->json();

        $this->assertEquals('submitted', $subV1['assignment']['computed_status']);
        $this->assertEquals(1, $subV1['submission']['submission_version']);
        $this->assertFalse($subV1['submission']['is_late']);
        $this->assertCount(1, $subV1['submission']['versions']);
        $attV1Id = $subV1['submission']['attachments'][0]['id'];
        $this->assertArrayNotHasKey('storage_key', $subV1['submission']['attachments'][0]);

        // Student B cannot download Student A's submission attachment
        $this->actingAs($this->studentB, 'web_api');
        $this->get("/web_api/auth/student/assignments/{$assignId}/submission-attachments/{$attV1Id}/download")->assertNotFound();

        // Advance past due date -> Student A resubmits v2 (late) & immutable v1 version snapshot stays intact!
        Carbon::setTestNow($now->copy()->addHours(5));
        $this->actingAs($this->studentA, 'web_api');
        $subV2 = $this->post("/web_api/auth/student/assignments/{$assignId}/submit", [
            'text_response' => 'Improved v2 solution',
            'attachments' => [UploadedFile::fake()->create('extra.pdf', 50, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertOk()->json();

        $this->assertEquals('submitted_late', $subV2['assignment']['computed_status']);
        $this->assertEquals(2, $subV2['submission']['submission_version']);
        $this->assertTrue($subV2['submission']['is_late']);
        $this->assertCount(2, $subV2['submission']['versions']);
        $versionsByNo = collect($subV2['submission']['versions'])->keyBy('version_number');
        $this->assertEquals('Final v1 solution', $versionsByNo->get(1)['text_response']);
        $this->assertEquals('Improved v2 solution', $versionsByNo->get(2)['text_response']);

        // Teacher received assignment_submitted notifications without body content leak
        $teacherNotifs = WebUserNotification::where('recipient_web_user_id', $this->teacherUser->id)
            ->where('type', WebUserNotification::TYPE_ASSIGNMENT_SUBMITTED)
            ->get();
        $this->assertCount(2, $teacherNotifs);
        $this->assertStringNotContainsString('Final v1 solution', $teacherNotifs->first()->message);

        // Create second assignment with block_late + allow_resubmission=false to verify strict blocking
        $this->actingAs($this->teacherUser, 'web_api');
        $strictAssign = $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments", [
            'title' => 'Strict Deadline Task',
            'instructions' => 'No late allowed.',
            'assignment_mode' => 'entire_class',
            'action' => 'publish',
            'due_at' => $now->copy()->addHours(6)->format('Y-m-d H:i:s'),
            'late_policy' => 'block_late',
            'allow_resubmission' => false,
        ])->assertCreated()->json('assignment');

        // Student A submits on time -> second submit blocked (resubmission_not_allowed)
        $this->actingAs($this->studentA, 'web_api');
        $this->postJson("/web_api/auth/student/assignments/{$strictAssign['id']}/submit", [
            'text_response' => 'First attempt',
        ])->assertOk();
        $this->postJson("/web_api/auth/student/assignments/{$strictAssign['id']}/submit", [
            'text_response' => 'Second attempt',
        ])->assertStatus(422)->assertJsonPath('code', 'resubmission_not_allowed');

        // Advance past strict due_at -> Student B shows overdue and is blocked from late submit
        Carbon::setTestNow($now->copy()->addHours(7));
        $this->actingAs($this->studentB, 'web_api');
        $this->getJson("/web_api/auth/student/assignments/{$strictAssign['id']}")
            ->assertOk()
            ->assertJsonPath('assignment.computed_status', 'overdue')
            ->assertJsonPath('assignment.can_submit', false);
        $this->postJson("/web_api/auth/student/assignments/{$strictAssign['id']}/submit", [
            'text_response' => 'Late try',
        ])->assertStatus(422)->assertJsonPath('code', 'assignment_late_blocked');

        // Teacher submission filters & private download check
        $this->actingAs($this->teacherUser, 'web_api');
        $lateList = $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assignId}/submissions?late_only=1")
            ->assertOk()->json();
        $this->assertCount(1, $lateList['submissions']);
        $this->assertEquals($this->studentA->id, $lateList['submissions'][0]['student']['id']);

        $this->get("/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assignId}/submission-attachments/{$attV1Id}/download")
            ->assertOk();
    }

    public function test_calendar_events_reminders_phase7d_integration_and_student_isolation(): void
    {
        $now = Carbon::create(2026, 11, 20, 10, 0, 0, 'Asia/Karachi');
        Carbon::setTestNow($now);
        $this->actingAs($this->teacherUser, 'web_api');

        $eventAttachment = UploadedFile::fake()->create('study_guide.pdf', 40, 'application/pdf');
        $publishedEvent = $this->post("/web_api/auth/teacher/classes/{$this->class->id}/calendar", [
            'title' => 'Revision Live Class',
            'description' => 'Wave optics review',
            'event_type' => 'revision_session',
            'starts_at' => $now->copy()->addMinutes(45)->format('Y-m-d H:i:s'),
            'ends_at' => $now->copy()->addMinutes(105)->format('Y-m-d H:i:s'),
            'location_or_link' => 'Room 204',
            'status' => 'published',
            'reminder_minutes_before' => 60,
            'attachment' => $eventAttachment,
        ], ['Accept' => 'application/json'])->assertCreated()->json('event');

        $draftEvent = $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/calendar", [
            'title' => 'Hidden Draft Event',
            'event_type' => 'lesson',
            'starts_at' => $now->copy()->addDays(2)->format('Y-m-d H:i:s'),
            'status' => 'draft',
        ])->assertCreated()->json('event');

        // Published assignment deadline
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments", [
            'title' => 'Optics Worksheet Due',
            'instructions' => 'Complete all questions.',
            'assignment_mode' => 'entire_class',
            'action' => 'publish',
            'due_at' => $now->copy()->addDays(3)->format('Y-m-d H:i:s'),
        ])->assertCreated();

        // Phase 7D Test targeted ONLY at studentA
        $test = TeacherClassTest::forceCreate([
            'teacher_class_id' => $this->class->id,
            'teacher_profile_id' => $this->teacher->id,
            'curriculum_board_id' => 1,
            'class_id' => 1,
            'subject_id' => 1,
            'title' => 'Phase 7D Midterm Quiz',
            'status' => 'published',
            'total_questions' => 1,
            'total_marks' => 1,
        ]);
        $testAssign = TeacherClassTestAssignment::forceCreate([
            'teacher_class_test_id' => $test->id,
            'teacher_class_id' => $this->class->id,
            'teacher_profile_id' => $this->teacher->id,
            'assigned_by_web_user_id' => $this->teacherUser->id,
            'assignment_mode' => TeacherClassTestAssignment::MODE_SELECTED_STUDENTS,
            'title_snapshot' => 'Phase 7D Midterm Quiz',
            'starts_at' => $now->copy()->addDays(4),
            'ends_at' => $now->copy()->addDays(4)->addHours(1),
            'duration_minutes' => 30,
            'max_attempts' => 1,
            'status' => TeacherClassTestAssignment::STATUS_SCHEDULED,
            'result_visibility' => 'after_submission',
            'assigned_at' => $now,
        ]);
        TeacherClassTestRecipient::forceCreate([
            'teacher_class_test_assignment_id' => $testAssign->id,
            'teacher_class_test_id' => $test->id,
            'teacher_class_id' => $this->class->id,
            'student_web_user_id' => $this->studentA->id,
            'assignment_status' => TeacherClassTestRecipient::STATUS_ASSIGNED,
            'assigned_at' => $now,
        ]);

        // Run reminder processor (event starts in 45 min with 60 min reminder window -> triggers once)
        Artisan::call('phase7f:process-scheduled');
        Artisan::call('phase7f:process-scheduled');
        $this->assertEquals(
            1,
            WebUserNotification::where('recipient_web_user_id', $this->studentA->id)
                ->where('type', WebUserNotification::TYPE_CALENDAR_REMINDER)
                ->count()
        );

        // Student A feed includes custom event + assignment deadline + Phase 7D test (NOT draft event)
        $from = $now->copy()->startOfMonth()->format('Y-m-d');
        $to = $now->copy()->endOfMonth()->format('Y-m-d');

        $this->actingAs($this->studentA, 'web_api');
        $feedA = $this->getJson("/web_api/auth/student/calendar?from={$from}&to={$to}")->assertOk()->json();
        $titlesA = array_column($feedA['items'], 'title');
        $this->assertContains('Revision Live Class', $titlesA);
        $this->assertContains('Optics Worksheet Due', $titlesA);
        $this->assertContains('Phase 7D Midterm Quiz', $titlesA);
        $this->assertNotContains('Hidden Draft Event', $titlesA);

        // Student A can download calendar event attachment
        $this->get("/web_api/auth/student/calendar/{$publishedEvent['source_id']}/attachment/download")->assertOk();

        // Student B sees event & assignment deadline, NOT Student A's selected Phase 7D test
        $this->actingAs($this->studentB, 'web_api');
        $feedB = $this->getJson("/web_api/auth/student/calendar?from={$from}&to={$to}")->assertOk()->json();
        $titlesB = array_column($feedB['items'], 'title');
        $this->assertContains('Revision Live Class', $titlesB);
        $this->assertNotContains('Phase 7D Midterm Quiz', $titlesB);

        // Pending & outsider students blocked from class calendar & attachment
        foreach ([$this->pendingStudent, $this->removedStudent, $this->outsiderStudent] as $blocked) {
            $this->actingAs($blocked, 'web_api');
            $this->getJson("/web_api/auth/student/calendar?class_id={$this->class->id}")->assertForbidden();
            $this->get("/web_api/auth/student/calendar/{$publishedEvent['source_id']}/attachment/download")->assertForbidden();
        }
    }

    public function test_attendance_sessions_bulk_finalize_reopen_audit_history_and_feature_guard(): void
    {
        $this->actingAs($this->teacherUser, 'web_api');

        // Reject creating session with ineligible student
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/attendance", [
            'session_date' => '2026-11-11',
            'records' => [
                ['student_web_user_id' => $this->pendingStudent->id, 'status' => 'present'],
            ],
        ])->assertStatus(422)->assertJsonPath('code', 'ineligible_attendance_student');

        // Create attendance session & prevent duplicate slot
        $sessionRes = $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/attendance", [
            'session_date' => '2026-11-11',
            'start_time' => '09:00',
            'end_time' => '09:45',
            'title' => 'Morning Lecture',
        ])->assertCreated()->json('session');
        $sessionId = $sessionRes['id'];

        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/attendance", [
            'session_date' => '2026-11-11',
            'start_time' => '09:00',
            'end_time' => '09:45',
        ])->assertStatus(422)->assertJsonPath('code', 'attendance_session_duplicate');

        // Bulk mark all active students present, then mark studentB late
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/attendance/{$sessionId}/mark", [
            'bulk_status' => 'present',
        ])->assertOk()->assertJsonPath('session.counts.present', 2);

        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/attendance/{$sessionId}/mark", [
            'records' => [
                ['student_web_user_id' => $this->studentB->id, 'status' => 'late', 'remarks' => 'Arrived 10 mins late'],
            ],
        ])->assertOk()->assertJsonPath('session.counts.late', 1);

        // Finalize session -> idempotent student notifications
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/attendance/{$sessionId}/finalize")
            ->assertOk()->assertJsonPath('session.status', 'finalized');
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/attendance/{$sessionId}/finalize")->assertOk();

        $this->assertEquals(
            1,
            WebUserNotification::where('recipient_web_user_id', $this->studentA->id)
                ->where('type', WebUserNotification::TYPE_ATTENDANCE_FINALIZED)
                ->count()
        );

        // Direct edit rejected while finalized
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/attendance/{$sessionId}/mark", [
            'records' => [
                ['student_web_user_id' => $this->studentA->id, 'status' => 'absent'],
            ],
        ])->assertStatus(422)->assertJsonPath('code', 'attendance_session_finalized');

        // Reopen with reason & correct studentA -> full audit trail recorded
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/attendance/{$sessionId}/reopen", [
            'reason' => 'Medical note provided',
        ])->assertOk()->assertJsonPath('session.status', 'open');

        $corrected = $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/attendance/{$sessionId}/mark", [
            'records' => [
                ['student_web_user_id' => $this->studentA->id, 'status' => 'excused', 'remarks' => 'Verified slip'],
            ],
            'reason' => 'Excused absence verified',
        ])->assertOk()->json();

        $this->assertEquals('excused', $corrected['roster'][0]['status']);
        $actions = array_column($corrected['audit_logs'], 'action');
        $this->assertContains('session_finalized', $actions);
        $this->assertContains('session_reopened', $actions);
        $this->assertContains('record_corrected', $actions);

        // Remove studentB from class -> studentB's historical attendance preserved for teacher, blocked from live view
        TeacherClassEnrollment::where('teacher_class_id', $this->class->id)
            ->where('student_web_user_id', $this->studentB->id)
            ->update(['status' => TeacherClassEnrollment::STATUS_REMOVED]);

        $historyB = $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/attendance/students/{$this->studentB->id}/history")
            ->assertOk()->json();
        $this->assertEquals(1, $historyB['summary']['late']);
        $this->assertEquals('removed', $historyB['student']['enrollment_status']);

        $this->actingAs($this->studentB, 'web_api');
        $this->getJson("/web_api/auth/student/attendance?class_id={$this->class->id}")->assertForbidden();

        // Student A views only own attendance
        $this->actingAs($this->studentA, 'web_api');
        $studentAtt = $this->getJson('/web_api/auth/student/attendance')->assertOk()->json();
        $this->assertEquals(1, $studentAtt['summary']['excused']);
        $this->assertCount(1, $studentAtt['records']);

        // Disabling subscription classes.manage blocks teacher Phase 7F endpoints
        $planId = DB::table('teacher_subscriptions')->where('teacher_profile_id', $this->teacher->id)->value('teacher_subscription_plan_id');
        TeacherSubscriptionFeature::where('teacher_subscription_plan_id', $planId)
            ->where('feature_key', 'classes.manage')
            ->update(['is_enabled' => false]);

        $this->actingAs($this->teacherUser, 'web_api');
        $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments")->assertForbidden();
        $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/calendar")->assertForbidden();
        $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/attendance")->assertForbidden();
    }

    public function test_scheduled_assignment_recipient_timing_enrollment_changes_and_idempotency(): void
    {
        $now = Carbon::create(2026, 11, 15, 10, 0, 0, 'Asia/Karachi');
        Carbon::setTestNow($now);

        $this->actingAs($this->teacherUser, 'web_api');

        // 1. Schedule an entire_class assignment (should NOT snapshot recipients before publish).
        $entireRes = $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments", [
            'title' => 'Entire Class Scheduled Homework',
            'instructions' => 'Complete chapter exercises.',
            'assignment_mode' => 'entire_class',
            'action' => 'schedule',
            'scheduled_publish_at' => $now->copy()->addHours(2)->format('Y-m-d H:i:s'),
            'due_at' => $now->copy()->addDays(3)->format('Y-m-d H:i:s'),
        ])->assertCreated()->json('assignment');
        $entireId = $entireRes['id'];
        $this->assertEquals(0, DB::table('teacher_class_assignment_recipients')->where('assignment_id', $entireId)->count());

        // 2. Schedule a selected_students assignment targeting studentA & studentB.
        $selectedRes = $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments", [
            'title' => 'Selected Group Task',
            'instructions' => 'Complete targeted lab.',
            'assignment_mode' => 'selected_students',
            'student_ids' => [$this->studentA->id, $this->studentB->id],
            'action' => 'schedule',
            'scheduled_publish_at' => $now->copy()->addHours(2)->format('Y-m-d H:i:s'),
            'due_at' => $now->copy()->addDays(3)->format('Y-m-d H:i:s'),
        ])->assertCreated()->json('assignment');
        $selectedId = $selectedRes['id'];

        // 3. StudentB leaves before publication, while studentC enrolls before publication.
        TeacherClassEnrollment::where('teacher_class_id', $this->class->id)
            ->where('student_web_user_id', $this->studentB->id)
            ->update(['status' => TeacherClassEnrollment::STATUS_LEFT]);

        $studentC = WebUser::create([
            'name' => 'Student Three',
            'email' => 'p7f_student_c@example.test',
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->enroll($this->class, $studentC, TeacherClassEnrollment::STATUS_ACTIVE);

        // 4. Advance clock to EXACT scheduled_publish_at boundary and run cron twice.
        Carbon::setTestNow($now->copy()->addHours(2));
        Artisan::call('phase7f:process-scheduled');
        Artisan::call('phase7f:process-scheduled');

        // Entire class snapshot includes studentA + studentC, excludes studentB!
        $entireRecipients = DB::table('teacher_class_assignment_recipients')
            ->where('assignment_id', $entireId)
            ->pluck('student_web_user_id')
            ->all();
        $this->assertContains($this->studentA->id, $entireRecipients);
        $this->assertContains($studentC->id, $entireRecipients);
        $this->assertNotContains($this->studentB->id, $entireRecipients);

        // Selected class snapshot revalidated & pruned studentB!
        $selectedRecipients = DB::table('teacher_class_assignment_recipients')
            ->where('assignment_id', $selectedId)
            ->pluck('student_web_user_id')
            ->all();
        $this->assertSame([$this->studentA->id], $selectedRecipients);

        // Even if studentB re-enrolls later, they cannot see selectedId published while they were away.
        TeacherClassEnrollment::where('teacher_class_id', $this->class->id)
            ->where('student_web_user_id', $this->studentB->id)
            ->update(['status' => TeacherClassEnrollment::STATUS_ACTIVE]);
        $this->actingAs($this->studentB, 'web_api');
        $this->getJson("/web_api/auth/student/assignments/{$selectedId}")->assertForbidden();

        // New studentD enrolls AFTER publish of entire_class assignment -> post-publish edit notifies studentD ONCE (no duplicates).
        $studentD = WebUser::create([
            'name' => 'Student Four',
            'email' => 'p7f_student_d@example.test',
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->enroll($this->class, $studentD, TeacherClassEnrollment::STATUS_ACTIVE);

        $this->actingAs($this->teacherUser, 'web_api');
        $this->putJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$entireId}", [
            'due_at' => $now->copy()->addDays(4)->format('Y-m-d H:i:s'),
        ])->assertOk();
        $this->putJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$entireId}", [
            'due_at' => $now->copy()->addDays(5)->format('Y-m-d H:i:s'),
        ])->assertOk();

        $this->assertEquals(
            1,
            WebUserNotification::where('recipient_web_user_id', $studentD->id)
                ->where('type', WebUserNotification::TYPE_ASSIGNMENT_PUBLISHED)
                ->where('action_url', "/lms/student/assignments/{$entireId}")
                ->count()
        );
        $this->assertEquals(
            1,
            WebUserNotification::where('recipient_web_user_id', $this->studentA->id)
                ->where('type', WebUserNotification::TYPE_ASSIGNMENT_PUBLISHED)
                ->where('action_url', "/lms/student/assignments/{$entireId}")
                ->count()
        );

        // 5. Draft selected_students -> student leaves before manual publishNow -> 422 ineligible_assignment_recipient.
        $draftSel = $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments", [
            'title' => 'Draft Selected',
            'instructions' => 'Draft instructions.',
            'assignment_mode' => 'selected_students',
            'student_ids' => [$studentC->id],
            'action' => 'draft',
        ])->assertCreated()->json('assignment');
        TeacherClassEnrollment::where('teacher_class_id', $this->class->id)
            ->where('student_web_user_id', $studentC->id)
            ->update(['status' => TeacherClassEnrollment::STATUS_REMOVED]);
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$draftSel['id']}/publish")
            ->assertStatus(422)
            ->assertJsonPath('code', 'ineligible_assignment_recipient');
    }

    public function test_assignment_submission_boundaries_historical_context_and_inactive_attachments(): void
    {
        $now = Carbon::create(2026, 11, 16, 10, 0, 0, 'Asia/Karachi');
        $dueAt = $now->copy()->addHours(2);
        Carbon::setTestNow($now);

        $this->actingAs($this->teacherUser, 'web_api');
        $created = $this->post("/web_api/auth/teacher/classes/{$this->class->id}/assignments", [
            'title' => 'Boundary Task',
            'instructions' => 'Original immutable prompt.',
            'max_marks' => 10,
            'assignment_mode' => 'selected_students',
            'student_ids' => json_encode([$this->studentA->id, $this->studentB->id]),
            'action' => 'publish',
            'due_at' => $dueAt->format('Y-m-d H:i:s'),
            'late_policy' => 'block_late',
            'allow_resubmission' => '1',
            'attachments' => [UploadedFile::fake()->create('prompt.pdf', 50, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertCreated()->json('assignment');

        $assignId = $created['id'];
        $promptAttId = $created['attachments'][0]['id'];

        // Student A submits at EXACT deadline boundary -> on-time (is_late=false)
        Carbon::setTestNow($dueAt);
        $this->actingAs($this->studentA, 'web_api');
        $subA = $this->post("/web_api/auth/student/assignments/{$assignId}/submit", [
            'text_response' => 'Submitted exactly on deadline',
            'attachments' => [UploadedFile::fake()->create('v1_work.pdf', 40, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertOk()->json('submission');
        $this->assertFalse($subA['is_late']);
        $subAttV1Id = $subA['attachments'][0]['id'];

        // 1 second after block_late due_at -> saveDraft, deleteOwnSubmissionAttachment, and submit all blocked
        Carbon::setTestNow($dueAt->copy()->addSecond());
        $this->postJson("/web_api/auth/student/assignments/{$assignId}/draft", [
            'text_response' => 'Late draft rejected',
        ])->assertStatus(422)->assertJsonPath('code', 'assignment_late_blocked');
        $this->deleteJson("/web_api/auth/student/assignments/{$assignId}/attachments/{$subAttV1Id}")
            ->assertStatus(422)->assertJsonPath('code', 'assignment_late_blocked');

        $this->actingAs($this->studentB, 'web_api');
        $this->postJson("/web_api/auth/student/assignments/{$assignId}/submit", [
            'text_response' => 'Late B rejected',
        ])->assertStatus(422)->assertJsonPath('code', 'assignment_late_blocked');

        // Historical submission context protection: teacher CANNOT alter title, instructions, mode, delete prompt attachments, or remove submitted recipient
        $this->actingAs($this->teacherUser, 'web_api');
        $this->putJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assignId}", [
            'instructions' => 'Tampered instructions after submission',
        ])->assertStatus(422)->assertJsonPath('code', 'assignment_submissions_exist_immutable');
        $this->putJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assignId}", [
            'assignment_mode' => 'entire_class',
        ])->assertStatus(422)->assertJsonPath('code', 'assignment_submissions_exist_immutable');
        $this->deleteJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assignId}/attachments/{$promptAttId}")
            ->assertStatus(422)->assertJsonPath('code', 'assignment_submissions_exist_immutable');
        $this->putJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assignId}", [
            'student_ids' => [$this->studentB->id],
        ])->assertStatus(422)->assertJsonPath('code', 'cannot_remove_submitted_recipient');

        // Safe due_at extension DOES succeed cleanly when prompt fields remain unchanged
        $extendedDue = $dueAt->copy()->addDays(2);
        $this->putJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assignId}", [
            'title' => 'Boundary Task',
            'instructions' => 'Original immutable prompt.',
            'max_marks' => 10,
            'due_at' => $extendedDue->format('Y-m-d H:i:s'),
        ])->assertOk();

        // Deactivated submission attachment (after resubmission removes it) is preserved in v1 snapshot but blocked from download
        $this->actingAs($this->studentA, 'web_api');
        $resub = $this->post("/web_api/auth/student/assignments/{$assignId}/submit", [
            'text_response' => 'Resubmitted v2 removing v1 file',
            'remove_attachment_ids' => json_encode([$subAttV1Id]),
            'attachments' => [UploadedFile::fake()->create('v2_work.pdf', 30, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertOk()->json('submission');
        $this->assertCount(2, $resub['versions']);
        $this->assertCount(1, $resub['versions'][0]['attachments_snapshot']);

        // Deactivated attachment record must NOT be downloadable by student OR teacher
        $this->get("/web_api/auth/student/assignments/{$assignId}/submission-attachments/{$subAttV1Id}/download")
            ->assertNotFound();
        $this->actingAs($this->teacherUser, 'web_api');
        $this->get("/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assignId}/submission-attachments/{$subAttV1Id}/download")
            ->assertNotFound();

        // Close -> submit blocked -> reopen with past due_at rejected -> reopen with future due_at succeeds & notifies
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assignId}/close")->assertOk();
        $this->actingAs($this->studentB, 'web_api');
        $this->postJson("/web_api/auth/student/assignments/{$assignId}/submit", ['text_response' => 'While closed'])
            ->assertStatus(422)->assertJsonPath('code', 'assignment_closed');

        $this->actingAs($this->teacherUser, 'web_api');
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assignId}/reopen", [
            'due_at' => $now->copy()->subHour()->format('Y-m-d H:i:s'),
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_due_date');

        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/assignments/{$assignId}/reopen", [
            'due_at' => $extendedDue->copy()->addHour()->format('Y-m-d H:i:s'),
        ])->assertOk()->assertJsonPath('assignment.status', 'published');

        $this->assertEquals(
            1,
            WebUserNotification::where('recipient_web_user_id', $this->studentA->id)
                ->where('type', WebUserNotification::TYPE_ASSIGNMENT_REOPENED)
                ->count()
        );
    }

    public function test_calendar_reschedule_reminder_reset_cancellation_and_archived_class_isolation(): void
    {
        $now = Carbon::create(2026, 11, 21, 9, 0, 0, 'Asia/Karachi');
        Carbon::setTestNow($now);

        $this->actingAs($this->teacherUser, 'web_api');
        $event = $this->post("/web_api/auth/teacher/classes/{$this->class->id}/calendar", [
            'title' => 'Practical Workshop',
            'event_type' => 'lesson',
            'starts_at' => $now->copy()->addMinutes(30)->format('Y-m-d H:i:s'),
            'status' => 'published',
            'reminder_minutes_before' => 60,
            'attachment' => UploadedFile::fake()->create('slides.pdf', 30, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('event');

        $eventId = $event['source_id'];

        // Reminder triggers initially
        Artisan::call('phase7f:process-scheduled');
        $this->assertEquals(
            1,
            WebUserNotification::where('recipient_web_user_id', $this->studentA->id)
                ->where('type', WebUserNotification::TYPE_CALENDAR_REMINDER)
                ->count()
        );

        // Reschedule into future -> resets reminded_at -> triggers new reminder cleanly once at new time
        $newStart = $now->copy()->addDays(2);
        $this->putJson("/web_api/auth/teacher/classes/{$this->class->id}/calendar/{$eventId}", [
            'starts_at' => $newStart->format('Y-m-d H:i:s'),
        ])->assertOk();
        $this->assertNull(TeacherClassCalendarEvent::findOrFail($eventId)->reminded_at);

        Carbon::setTestNow($newStart->copy()->subMinutes(15));
        Artisan::call('phase7f:process-scheduled');
        Artisan::call('phase7f:process-scheduled');
        $this->assertEquals(
            2,
            WebUserNotification::where('recipient_web_user_id', $this->studentA->id)
                ->where('type', WebUserNotification::TYPE_CALENDAR_REMINDER)
                ->count()
        );

        // Cancel event -> student feed shows status=cancelled WITHOUT attachment download URL, and download returns 404
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/calendar/{$eventId}/cancel")
            ->assertOk()->assertJsonPath('event.status', 'cancelled');

        $this->actingAs($this->studentA, 'web_api');
        $feed = $this->getJson('/web_api/auth/student/calendar')->assertOk()->json('items');
        $cancelledItem = collect($feed)->firstWhere('uid', 'event-' . $eventId);
        $this->assertEquals('cancelled', $cancelledItem['status']);
        $this->assertNull($cancelledItem['attachment']);
        $this->get("/web_api/auth/student/calendar/{$eventId}/attachment/download")->assertNotFound();

        // Archived class events must NOT dispatch cron reminders
        $this->actingAs($this->teacherUser, 'web_api');
        $ev2 = $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/calendar", [
            'title' => 'Archived Class Event',
            'event_type' => 'other',
            'starts_at' => $newStart->copy()->addHours(3)->format('Y-m-d H:i:s'),
            'status' => 'published',
            'reminder_minutes_before' => 60,
        ])->assertCreated()->json('event');
        $this->class->update(['is_active' => false]);

        Carbon::setTestNow($newStart->copy()->addHours(2)->addMinutes(30));
        Artisan::call('phase7f:process-scheduled');
        $this->assertNull(TeacherClassCalendarEvent::findOrFail($ev2['source_id'])->reminded_at);
    }

    public function test_attendance_overlapping_slots_mandatory_reopen_reason_and_inactive_roster_exclusion(): void
    {
        $this->actingAs($this->teacherUser, 'web_api');

        // Reject end_time without start_time
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/attendance", [
            'session_date' => '2026-11-12',
            'end_time' => '10:00',
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_session_time');

        // Create bounded timed session 09:00-10:00
        $s1 = $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/attendance", [
            'session_date' => '2026-11-12',
            'start_time' => '09:00',
            'end_time' => '10:00',
        ])->assertCreated()->json('session');

        // Reject overlapping slots (full_day, partial 09:30-10:30, contained point 09:30)
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/attendance", [
            'session_date' => '2026-11-12',
        ])->assertStatus(422)->assertJsonPath('code', 'attendance_session_overlap');

        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/attendance", [
            'session_date' => '2026-11-12',
            'start_time' => '09:30',
            'end_time' => '10:30',
        ])->assertStatus(422)->assertJsonPath('code', 'attendance_session_overlap');

        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/attendance", [
            'session_date' => '2026-11-12',
            'start_time' => '09:30',
        ])->assertStatus(422)->assertJsonPath('code', 'attendance_session_overlap');

        // Allow adjacent non-overlapping session 10:00-11:00
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/attendance", [
            'session_date' => '2026-11-12',
            'start_time' => '10:00',
            'end_time' => '11:00',
        ])->assertCreated();

        // Mark and finalize s1, then verify reopen requires a non-empty reason
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/attendance/{$s1['id']}/mark", [
            'bulk_status' => 'present',
        ])->assertOk();
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/attendance/{$s1['id']}/finalize")->assertOk();

        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/attendance/{$s1['id']}/reopen", [])
            ->assertStatus(422);
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/attendance/{$s1['id']}/reopen", [
            'reason' => '   ',
        ])->assertStatus(422);
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/attendance/{$s1['id']}/reopen", [
            'reason' => 'Correction approved',
        ])->assertOk()->assertJsonPath('session.status', 'open');

        // Newly created session excludes newly removed studentB from active roster
        TeacherClassEnrollment::where('teacher_class_id', $this->class->id)
            ->where('student_web_user_id', $this->studentB->id)
            ->update(['status' => TeacherClassEnrollment::STATUS_REMOVED]);

        $s3 = $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/attendance", [
            'session_date' => '2026-11-13',
        ])->assertCreated()->json();
        $rosterIds = array_column($s3['roster'], 'student_web_user_id');
        $this->assertSame([$this->studentA->id], $rosterIds);
    }

    private function makeClass(TeacherProfile $teacher, string $name, string $section = 'A'): TeacherClass
    {
        return TeacherClass::forceCreate([
            'teacher_profile_id' => $teacher->id,
            'curriculum_board_id' => 1,
            'class_id' => 1,
            'subject_id' => 1,
            'section' => $section,
            'name' => $name,
            'class_code' => strtoupper(substr(md5($name . uniqid('', true)), 0, 8)),
            'is_active' => true,
            'requires_approval' => true,
        ]);
    }

    private function enroll(TeacherClass $class, WebUser $student, string $status): TeacherClassEnrollment
    {
        return TeacherClassEnrollment::forceCreate([
            'teacher_class_id' => $class->id,
            'student_web_user_id' => $student->id,
            'status' => $status,
            'approved_at' => $status === TeacherClassEnrollment::STATUS_ACTIVE ? now() : null,
        ]);
    }
}
