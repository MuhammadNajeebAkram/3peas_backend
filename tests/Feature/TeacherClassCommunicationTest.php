<?php

namespace Tests\Feature;

use App\Models\TeacherClass;
use App\Models\TeacherClassAnnouncement;
use App\Models\TeacherClassDiscussion;
use App\Models\TeacherClassDiscussionReply;
use App\Models\TeacherClassDiscussionReport;
use App\Models\TeacherClassEnrollment;
use App\Models\TeacherProfile;
use App\Models\TeacherStudentConversation;
use App\Models\TeacherSubscriptionFeature;
use App\Models\WebUser;
use App\Models\WebUserNotification;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TeacherClassCommunicationTest extends TeacherBackendTest
{
    private TeacherProfile $teacher;
    private TeacherProfile $otherTeacher;
    private TeacherClass $class;
    private TeacherClass $secondClass;
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

        foreach (['class_tbl', 'subject_tbl', 'curriculum_board_tbl'] as $table) {
            Schema::dropIfExists($table);
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->id();
                if ($table === 'class_tbl') {
                    $t->string('class_name');
                    $t->string('class_name_um')->nullable();
                }
                if ($table === 'subject_tbl') {
                    $t->string('subject_name');
                    $t->string('subject_name_um')->nullable();
                }
                if ($table === 'curriculum_board_tbl') {
                    $t->string('name');
                }
                $t->boolean('activate')->default(true);
                $t->timestamps();
            });
        }
        DB::table('class_tbl')->insert(['id' => 1, 'class_name' => 'Grade 10', 'activate' => 1]);
        DB::table('subject_tbl')->insert(['id' => 1, 'subject_name' => 'Physics', 'activate' => 1]);
        DB::table('curriculum_board_tbl')->insert(['id' => 1, 'name' => 'Punjab', 'activate' => 1]);

        (require database_path('migrations/2026_11_11_000006_create_phase7e_communication_tables.php'))->up();

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
        $this->secondClass = $this->makeClass($this->teacher, 'Physics Cohort B', 'B');
        $this->otherClass = $this->makeClass($this->otherTeacher, 'Other Teacher Class', 'A');

        $this->studentA = WebUser::findOrFail(1);
        $this->studentB = WebUser::create([
            'name' => 'Student Two',
            'email' => 'p7e_student_b@example.test',
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->pendingStudent = WebUser::create([
            'name' => 'Pending Student',
            'email' => 'p7e_pending@example.test',
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->rejectedStudent = WebUser::create([
            'name' => 'Rejected Student',
            'email' => 'p7e_rejected@example.test',
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->removedStudent = WebUser::create([
            'name' => 'Removed Student',
            'email' => 'p7e_removed@example.test',
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->leftStudent = WebUser::create([
            'name' => 'Left Student',
            'email' => 'p7e_left@example.test',
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->outsiderStudent = WebUser::create([
            'name' => 'Outsider Student',
            'email' => 'p7e_outsider@example.test',
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->enroll($this->class, $this->studentA, TeacherClassEnrollment::STATUS_ACTIVE);
        $this->enroll($this->class, $this->studentB, TeacherClassEnrollment::STATUS_ACTIVE);
        $this->enroll($this->secondClass, $this->studentA, TeacherClassEnrollment::STATUS_ACTIVE);
        $this->enroll($this->class, $this->pendingStudent, TeacherClassEnrollment::STATUS_PENDING);
        $this->enroll($this->class, $this->rejectedStudent, TeacherClassEnrollment::STATUS_REJECTED);
        $this->enroll($this->class, $this->removedStudent, TeacherClassEnrollment::STATUS_REMOVED);
        $this->enroll($this->class, $this->leftStudent, TeacherClassEnrollment::STATUS_LEFT);
        $this->enroll($this->otherClass, $this->outsiderStudent, TeacherClassEnrollment::STATUS_ACTIVE);

        \App\Models\TeacherSubscription::whereIn('teacher_profile_id', [$this->teacher->id, $this->otherTeacher->id])
            ->update(['starts_at' => now()->subMonths(6)]);

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
                ['user_id' => $this->studentA->id, 'offered_program_id' => 1, 'status' => 'active', 'started_at' => now()->subMonths(6)->toDateString(), 'expires_at' => now()->addYear()->toDateString()],
                ['user_id' => $this->studentB->id, 'offered_program_id' => 1, 'status' => 'active', 'started_at' => now()->subMonths(6)->toDateString(), 'expires_at' => now()->addYear()->toDateString()],
                ['user_id' => $this->outsiderStudent->id, 'offered_program_id' => 1, 'status' => 'active', 'started_at' => now()->subMonths(6)->toDateString(), 'expires_at' => now()->addYear()->toDateString()],
                ['user_id' => $this->removedStudent->id, 'offered_program_id' => 1, 'status' => 'active', 'started_at' => now()->subMonths(6)->toDateString(), 'expires_at' => now()->addYear()->toDateString()],
                ['user_id' => $this->leftStudent->id, 'offered_program_id' => 1, 'status' => 'active', 'started_at' => now()->subMonths(6)->toDateString(), 'expires_at' => now()->addYear()->toDateString()],
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_module_a_announcements_lifecycle_scheduling_read_tracking_and_access_control(): void
    {
        $this->actingAs($this->teacherUser, 'web_api');

        // 1. Create draft
        $draft = $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/announcements", [
            'title' => 'Midterm Syllabus Update',
            'body' => 'Chapters 1 to 3 are included.',
            'action' => 'draft',
            'is_pinned' => false,
        ])->assertCreated()->json('data');

        $this->assertEquals('draft', $draft['status']);

        // Pending/Active student cannot see drafts
        $this->actingAs($this->studentA, 'web_api');
        $this->getJson("/web_api/auth/student/announcements/{$draft['id']}")->assertNotFound();

        // Update draft & publish now
        $this->actingAs($this->teacherUser, 'web_api');
        $this->putJson("/web_api/auth/teacher/classes/{$this->class->id}/announcements/{$draft['id']}", [
            'title' => 'Midterm Syllabus Final',
            'body' => 'Chapters 1 to 4 are included.',
        ])->assertOk()->assertJsonPath('data.title', 'Midterm Syllabus Final');

        $pub = $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/announcements/{$draft['id']}/publish")
            ->assertOk()
            ->json('data');
        $this->assertEquals('published', $pub['status']);
        $this->assertEquals(2, $pub['active_students_count']);
        $this->assertEquals(0, $pub['reads_count']);

        // Notifications sent ONLY to studentA & studentB (not pending, removed, outsider)
        $this->assertEquals(1, WebUserNotification::where('recipient_web_user_id', $this->studentA->id)
            ->where('type', WebUserNotification::TYPE_CLASS_ANNOUNCEMENT)->count());
        $this->assertEquals(1, WebUserNotification::where('recipient_web_user_id', $this->studentB->id)
            ->where('type', WebUserNotification::TYPE_CLASS_ANNOUNCEMENT)->count());
        $this->assertEquals(0, WebUserNotification::where('recipient_web_user_id', $this->pendingStudent->id)->count());
        $this->assertEquals(0, WebUserNotification::where('recipient_web_user_id', $this->removedStudent->id)->count());
        $this->assertEquals(0, WebUserNotification::where('recipient_web_user_id', $this->outsiderStudent->id)->count());

        // Published announcement cannot be deleted via draft delete endpoint
        $this->deleteJson("/web_api/auth/teacher/classes/{$this->class->id}/announcements/{$draft['id']}")
            ->assertStatus(409);

        // 2. Scheduled announcement: hidden until due, published once by scheduler
        $scheduledTime = now()->addMinutes(20);
        $scheduled = $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/announcements", [
            'title' => 'Scheduled Lab Notice',
            'body' => 'Bring lab coats on Monday.',
            'action' => 'schedule',
            'scheduled_at' => $scheduledTime->toIso8601String(),
            'is_pinned' => true,
        ])->assertCreated()->json('data');

        $this->assertEquals('scheduled', $scheduled['status']);

        $this->actingAs($this->studentA, 'web_api');
        $listBeforeDue = $this->getJson('/web_api/auth/student/announcements')->assertOk()->json();
        $this->assertCount(1, $listBeforeDue['data']);
        $this->assertEquals(1, $listBeforeDue['unread_count']);

        // Advance past scheduled_at and invoke artisan command twice (must publish and notify once)
        Carbon::setTestNow($scheduledTime->copy()->addMinutes(5));
        Artisan::call('announcements:publish-scheduled');
        Artisan::call('announcements:publish-scheduled');

        $this->assertEquals(2, WebUserNotification::where('recipient_web_user_id', $this->studentA->id)
            ->where('type', WebUserNotification::TYPE_CLASS_ANNOUNCEMENT)->count());

        // Student reads & idempotently marks read; pinned announcement sorts first
        $listAfterDue = $this->getJson('/web_api/auth/student/announcements')->assertOk()->json();
        $this->assertCount(2, $listAfterDue['data']);
        $this->assertEquals($scheduled['id'], $listAfterDue['data'][0]['id']);
        $this->assertEquals(2, $listAfterDue['unread_count']);

        $this->postJson("/web_api/auth/student/announcements/{$scheduled['id']}/read")->assertOk()->assertJsonPath('data.is_read', true);
        $this->postJson("/web_api/auth/student/announcements/{$scheduled['id']}/read")->assertOk()->assertJsonPath('data.is_read', true);

        // Verify unique read count & teacher stats
        $this->actingAs($this->teacherUser, 'web_api');
        $detail = $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/announcements/{$scheduled['id']}")
            ->assertOk()
            ->json('data');
        $this->assertEquals(1, $detail['reads_count']);
        $this->assertEquals(2, $detail['active_students_count']);

        // Draft deletion succeeds
        $tempDraft = $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/announcements", [
            'title' => 'Temp Draft',
            'body' => 'Will be deleted.',
            'action' => 'draft',
        ])->assertCreated()->json('data');
        $this->deleteJson("/web_api/auth/teacher/classes/{$this->class->id}/announcements/{$tempDraft['id']}")
            ->assertOk();

        // Cross-teacher & non-active students blocked
        $this->actingAs($this->otherTeacherUser, 'web_api');
        $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/announcements")->assertForbidden();

        $this->actingAs($this->pendingStudent, 'web_api');
        $this->getJson("/web_api/auth/student/announcements/{$scheduled['id']}")->assertForbidden();
        $this->postJson("/web_api/auth/student/announcements/{$scheduled['id']}/read")->assertForbidden();

        $this->actingAs($this->removedStudent, 'web_api');
        $this->getJson("/web_api/auth/student/announcements/{$scheduled['id']}")->assertForbidden();

        // Subscription feature gate enforcement
        $this->actingAs($this->teacherUser, 'web_api');
        $planId = \App\Models\TeacherSubscription::where('teacher_profile_id', $this->teacher->id)
            ->value('teacher_subscription_plan_id');
        TeacherSubscriptionFeature::where('teacher_subscription_plan_id', $planId)
            ->where('feature_key', 'announcements.manage')
            ->update(['is_enabled' => false]);

        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/announcements", [
            'title' => 'Blocked',
            'body' => 'Should be rejected.',
            'action' => 'publish',
        ])->assertForbidden();
    }

    public function test_module_b_discussions_replies_closing_ownership_moderation_and_reporting(): void
    {
        // Active student starts discussion -> teacher notified
        $this->actingAs($this->studentA, 'web_api');
        $topic = $this->postJson("/web_api/auth/student/classes/{$this->class->id}/discussions", [
            'title' => 'Question on Newton Second Law',
            'body' => 'Does F=ma hold when mass varies?',
        ])->assertCreated()->json('data');

        $this->assertEquals(1, WebUserNotification::where('recipient_web_user_id', $this->teacherUser->id)
            ->where('type', WebUserNotification::TYPE_DISCUSSION_TOPIC)->count());

        // StudentB can reply, cannot edit or delete StudentA's topic
        $this->actingAs($this->studentB, 'web_api');
        $this->putJson("/web_api/auth/student/classes/{$this->class->id}/discussions/{$topic['id']}", [
            'title' => 'Hijacked Title',
        ])->assertForbidden();
        $this->deleteJson("/web_api/auth/student/classes/{$this->class->id}/discussions/{$topic['id']}")
            ->assertForbidden();

        $replyByB = $this->postJson("/web_api/auth/student/classes/{$this->class->id}/discussions/{$topic['id']}/replies", [
            'body' => 'Good question! I think momentum rate dP/dt is more general.',
        ])->assertCreated()->json('data');

        // StudentA (topic author) + Teacher both receive reply notifications
        $this->assertEquals(1, WebUserNotification::where('recipient_web_user_id', $this->studentA->id)
            ->where('type', WebUserNotification::TYPE_DISCUSSION_REPLY)->count());
        $this->assertEquals(1, WebUserNotification::where('recipient_web_user_id', $this->teacherUser->id)
            ->where('type', WebUserNotification::TYPE_DISCUSSION_REPLY)->count());

        // StudentA can edit their own open topic, cannot edit StudentB's reply
        $this->actingAs($this->studentA, 'web_api');
        $this->putJson("/web_api/auth/student/classes/{$this->class->id}/discussions/{$topic['id']}", [
            'body' => 'Does F=ma hold for variable mass systems like rockets?',
        ])->assertOk()->assertJsonPath('data.body', 'Does F=ma hold for variable mass systems like rockets?');

        $this->putJson("/web_api/auth/student/classes/{$this->class->id}/discussions/{$topic['id']}/replies/{$replyByB['id']}", [
            'body' => 'Tampered reply',
        ])->assertForbidden();

        // StudentA reports StudentB's reply -> teacher sees open report & notification
        $report = $this->postJson("/web_api/auth/student/classes/{$this->class->id}/discussions/{$topic['id']}/report", [
            'reply_id' => $replyByB['id'],
            'reason' => 'Needs clarification from instructor.',
        ])->assertCreated()->json('data');

        $this->assertEquals(1, WebUserNotification::where('recipient_web_user_id', $this->teacherUser->id)
            ->where('type', WebUserNotification::TYPE_DISCUSSION_REPORTED)->count());

        // Teacher replies, pins, closes topic -> closed topic blocks new replies & student edits
        $this->actingAs($this->teacherUser, 'web_api');
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/discussions/{$topic['id']}/pin", ['is_pinned' => true])
            ->assertOk()->assertJsonPath('data.is_pinned', true);

        $teacherReply = $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/discussions/{$topic['id']}/replies", [
            'body' => 'Always use F = dp/dt for variable mass.',
        ])->assertCreated()->json('data');

        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/discussions/{$topic['id']}/closed", ['closed' => true])
            ->assertOk()->assertJsonPath('data.status', 'closed');

        $this->actingAs($this->studentB, 'web_api');
        $this->postJson("/web_api/auth/student/classes/{$this->class->id}/discussions/{$topic['id']}/replies", [
            'body' => 'Should be rejected while closed.',
        ])->assertStatus(409);

        // Pending/removed/outsider blocked from class discussions
        $this->actingAs($this->pendingStudent, 'web_api');
        $this->getJson("/web_api/auth/student/classes/{$this->class->id}/discussions")->assertForbidden();
        $this->actingAs($this->outsiderStudent, 'web_api');
        $this->getJson("/web_api/auth/student/classes/{$this->class->id}/discussions")->assertForbidden();

        // Teacher moderates (hides) reported reply and checks audit row + auto-reviewed report
        $this->actingAs($this->teacherUser, 'web_api');
        $this->deleteJson("/web_api/auth/teacher/classes/{$this->class->id}/discussions/{$topic['id']}/replies/{$replyByB['id']}", [
            'mode' => 'hidden',
            'reason' => 'Off-topic phrasing.',
        ])->assertOk()->assertJsonPath('data.status', 'hidden');

        $this->assertDatabaseHas('teacher_class_discussion_replies', [
            'id' => $replyByB['id'],
            'status' => 'hidden',
            'moderated_by_web_user_id' => $this->teacherUser->id,
        ]);
        $this->assertDatabaseHas('teacher_class_discussion_reports', [
            'id' => $report['id'],
            'status' => 'reviewed',
        ]);

        // Hidden reply not visible to student thread view
        $this->actingAs($this->studentA, 'web_api');
        $threadForStudent = $this->getJson("/web_api/auth/student/classes/{$this->class->id}/discussions/{$topic['id']}")
            ->assertOk()
            ->json('replies');
        $this->assertCount(1, $threadForStudent);
        $this->assertEquals($teacherReply['id'], $threadForStudent[0]['id']);

        // Soft-delete preserves audit record
        $this->actingAs($this->teacherUser, 'web_api');
        $this->deleteJson("/web_api/auth/teacher/classes/{$this->class->id}/discussions/{$topic['id']}", [
            'mode' => 'deleted',
            'reason' => 'Archived thread',
        ])->assertOk();

        $this->assertDatabaseHas('teacher_class_discussions', [
            'id' => $topic['id'],
            'status' => 'deleted',
        ]);
    }

    public function test_module_c_private_messaging_strict_1to1_blocks_student_to_student_and_enforces_enrollment(): void
    {
        // Student-to-student messaging is strictly blocked
        $this->actingAs($this->studentA, 'web_api');
        $this->postJson('/web_api/auth/messages/conversations', [
            'recipient_web_user_id' => $this->studentB->id,
        ])->assertForbidden()->assertJsonPath('code', 'student_to_student_forbidden');

        // Outsider / pending / removed cannot message teacher
        $this->actingAs($this->pendingStudent, 'web_api');
        $this->postJson('/web_api/auth/messages/conversations', [
            'recipient_web_user_id' => $this->teacherUser->id,
        ])->assertForbidden()->assertJsonPath('code', 'enrollment_not_active');

        $this->actingAs($this->outsiderStudent, 'web_api');
        $this->postJson('/web_api/auth/messages/conversations', [
            'recipient_web_user_id' => $this->teacherUser->id,
        ])->assertForbidden()->assertJsonPath('code', 'enrollment_not_active');

        // Teacher lists active recipients (only studentA & studentB, NOT pending/removed/outsider)
        $this->actingAs($this->teacherUser, 'web_api');
        $recipients = $this->getJson('/web_api/auth/messages/recipients')->assertOk()->json('data');
        $recipientIds = array_column($recipients, 'user_id');
        sort($recipientIds);
        $expectedIds = [$this->studentA->id, $this->studentB->id];
        sort($expectedIds);
        $this->assertEquals($expectedIds, $recipientIds);

        // Teacher starts conversation referencing Cohort A
        $conv1 = $this->postJson('/web_api/auth/messages/conversations', [
            'recipient_web_user_id' => $this->studentA->id,
            'class_id' => $this->class->id,
            'initial_message' => 'Confidential quiz guidance for you.',
        ])->assertOk()->json('data');

        // StudentA starting conversation referencing Cohort B with same teacher returns SAME canonical conversation ID
        $this->actingAs($this->studentA, 'web_api');
        $conv2 = $this->postJson('/web_api/auth/messages/conversations', [
            'recipient_web_user_id' => $this->teacherUser->id,
            'class_id' => $this->secondClass->id,
        ])->assertOk()->json('data');

        $this->assertEquals($conv1['id'], $conv2['id']);
        $this->assertEquals(1, TeacherStudentConversation::where('teacher_profile_id', $this->teacher->id)
            ->where('student_web_user_id', $this->studentA->id)->count());

        // Privacy check: notification must NOT contain the private message body!
        $notif = WebUserNotification::where('recipient_web_user_id', $this->studentA->id)
            ->where('type', WebUserNotification::TYPE_PRIVATE_MESSAGE)
            ->firstOrFail();
        $this->assertStringNotContainsString('Confidential quiz guidance', $notif->message);
        $this->assertStringNotContainsString('Confidential quiz guidance', json_encode($notif->metadata));

        // StudentA sees unread count = 1, marks read, and replies
        $convsStudent = $this->getJson('/web_api/auth/messages/conversations')->assertOk()->json();
        $this->assertEquals(1, $convsStudent['total_unread_count']);

        $this->postJson("/web_api/auth/messages/conversations/{$conv1['id']}/read")
            ->assertOk()->assertJsonPath('marked_count', 1);

        $this->postJson("/web_api/auth/messages/conversations/{$conv1['id']}/messages", [
            'body' => 'Thank you teacher!',
        ])->assertCreated();

        // Cross-student and cross-teacher access to conversation is rejected (404)
        $this->actingAs($this->studentB, 'web_api');
        $this->getJson("/web_api/auth/messages/conversations/{$conv1['id']}/messages")->assertNotFound();
        $this->postJson("/web_api/auth/messages/conversations/{$conv1['id']}/messages", [
            'body' => 'Snooping message',
        ])->assertNotFound();

        $this->actingAs($this->otherTeacherUser, 'web_api');
        $this->getJson("/web_api/auth/messages/conversations/{$conv1['id']}/messages")->assertNotFound();

        // Removing studentA from ALL classes with teacher preserves read history (`can_send: false`) but blocks new messages
        TeacherClassEnrollment::where('student_web_user_id', $this->studentA->id)
            ->whereIn('teacher_class_id', [$this->class->id, $this->secondClass->id])
            ->update(['status' => TeacherClassEnrollment::STATUS_REMOVED]);

        $this->actingAs($this->studentA, 'web_api');
        $history = $this->getJson("/web_api/auth/messages/conversations/{$conv1['id']}/messages")
            ->assertOk()
            ->json();
        $this->assertCount(2, $history['data']);
        $this->assertFalse($history['conversation']['can_send']);

        $this->postJson("/web_api/auth/messages/conversations/{$conv1['id']}/messages", [
            'body' => 'Trying after removal',
        ])->assertForbidden()->assertJsonPath('code', 'enrollment_not_active');
    }

    public function test_authorization_and_privacy_isolation_for_teachers_students_and_notifications(): void
    {
        // Teacher A publishes an announcement and opens a discussion in $this->class
        $this->actingAs($this->teacherUser, 'web_api');
        $announcement = $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/announcements", [
            'title' => 'Class Privacy Check Notice',
            'body' => 'Only active enrolled students of class A.',
            'action' => 'publish',
        ])->assertCreated()->json('data');

        $topic = $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/discussions", [
            'title' => 'Class Privacy Topic',
            'body' => 'Discuss wave optics.',
        ])->assertCreated()->json('data');

        // Unauthorized teacher cannot access OR mutate another teacher's announcements or discussions
        $this->actingAs($this->otherTeacherUser, 'web_api');
        $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/announcements")->assertForbidden();
        $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/announcements/{$announcement['id']}")->assertForbidden();
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/announcements", [
            'title' => 'Spoof',
            'body' => 'Unauthorized write.',
            'action' => 'publish',
        ])->assertForbidden();
        $this->putJson("/web_api/auth/teacher/classes/{$this->class->id}/announcements/{$announcement['id']}", [
            'title' => 'Spoof Edit',
        ])->assertForbidden();
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/announcements/{$announcement['id']}/archive")
            ->assertForbidden();

        $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/discussions")->assertForbidden();
        $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/discussions/{$topic['id']}")->assertForbidden();
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/discussions", [
            'title' => 'Spoof Topic',
            'body' => 'Unauthorized topic.',
        ])->assertForbidden();
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/discussions/{$topic['id']}/closed", [
            'closed' => true,
        ])->assertForbidden();
        $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/discussions/reports")->assertForbidden();

        // Pending, rejected, removed, left, and outsider students cannot access new class announcements, discussions, or start PMs
        foreach ([$this->pendingStudent, $this->rejectedStudent, $this->removedStudent, $this->leftStudent, $this->outsiderStudent] as $blockedStudent) {
            $this->actingAs($blockedStudent, 'web_api');
            $this->getJson("/web_api/auth/student/announcements?class_id={$this->class->id}")->assertForbidden();
            $this->getJson("/web_api/auth/student/announcements/{$announcement['id']}")->assertForbidden();
            $this->postJson("/web_api/auth/student/announcements/{$announcement['id']}/read")->assertForbidden();

            $listIds = array_column($this->getJson('/web_api/auth/student/announcements')->assertOk()->json('data'), 'id');
            $this->assertNotContains($announcement['id'], $listIds);

            $this->getJson("/web_api/auth/student/classes/{$this->class->id}/discussions")->assertForbidden();
            $this->postJson("/web_api/auth/student/classes/{$this->class->id}/discussions", [
                'title' => 'Blocked',
                'body' => 'Cannot post.',
            ])->assertForbidden();
            $this->getJson("/web_api/auth/student/classes/{$this->class->id}/discussions/{$topic['id']}")->assertForbidden();
            $this->postJson("/web_api/auth/student/classes/{$this->class->id}/discussions/{$topic['id']}/replies", [
                'body' => 'Cannot reply.',
            ])->assertForbidden();

            $this->postJson('/web_api/auth/messages/conversations', [
                'recipient_web_user_id' => $this->teacherUser->id,
                'class_id' => $this->class->id,
                'initial_message' => 'Unauthorized chat',
            ])->assertForbidden()->assertJsonPath('code', 'enrollment_not_active');
        }

        // Teacher starts confidential PM with studentA (secret body must not leak in notifications or to non-participants)
        $secretBody = 'TOP-SECRET-EXAM-FEEDBACK-XYZ-991';
        $this->actingAs($this->teacherUser, 'web_api');
        $conv = $this->postJson('/web_api/auth/messages/conversations', [
            'recipient_web_user_id' => $this->studentA->id,
            'class_id' => $this->class->id,
            'initial_message' => $secretBody,
        ])->assertOk()->json('data');

        // Notification privacy: ONLY studentA gets PM notification, and body text never appears in title/message/metadata
        $this->assertEquals(1, WebUserNotification::where('recipient_web_user_id', $this->studentA->id)
            ->where('type', WebUserNotification::TYPE_PRIVATE_MESSAGE)->count());
        $pmNotif = WebUserNotification::where('recipient_web_user_id', $this->studentA->id)
            ->where('type', WebUserNotification::TYPE_PRIVATE_MESSAGE)
            ->firstOrFail();
        $this->assertStringNotContainsString($secretBody, $pmNotif->title);
        $this->assertStringNotContainsString($secretBody, $pmNotif->message);
        $this->assertStringNotContainsString($secretBody, json_encode($pmNotif->metadata));

        foreach ([$this->studentB, $this->otherTeacherUser, $this->pendingStudent, $this->rejectedStudent, $this->removedStudent, $this->leftStudent, $this->outsiderStudent] as $other) {
            $this->assertEquals(0, WebUserNotification::where('recipient_web_user_id', $other->id)
                ->where('type', WebUserNotification::TYPE_PRIVATE_MESSAGE)->count());
        }

        // Cross-student & cross-teacher conversation isolation (GET /conversations, GET /messages, POST /messages, POST /read)
        foreach ([$this->studentB, $this->otherTeacherUser] as $snooper) {
            $this->actingAs($snooper, 'web_api');
            $listedIds = array_column($this->getJson('/web_api/auth/messages/conversations')->assertOk()->json('data'), 'id');
            $this->assertNotContains($conv['id'], $listedIds);
            $this->getJson("/web_api/auth/messages/conversations/{$conv['id']}/messages")->assertNotFound();
            $this->postJson("/web_api/auth/messages/conversations/{$conv['id']}/messages", [
                'body' => 'Intrusion attempt',
            ])->assertNotFound();
            $this->postJson("/web_api/auth/messages/conversations/{$conv['id']}/read")->assertNotFound();
        }

        // Approved enrollment-removal/left policy: studentA transitions to `left` across all shared classes
        TeacherClassEnrollment::where('student_web_user_id', $this->studentA->id)
            ->update(['status' => TeacherClassEnrollment::STATUS_LEFT]);

        // Historical read + marking existing unread historical message as read succeed with can_send=false,
        // while both studentA AND teacher are blocked from sending new messages.
        $this->actingAs($this->studentA, 'web_api');
        $history = $this->getJson("/web_api/auth/messages/conversations/{$conv['id']}/messages")
            ->assertOk()
            ->json();
        $this->assertCount(1, $history['data']);
        $this->assertEquals($secretBody, $history['data'][0]['body']);
        $this->assertFalse($history['conversation']['can_send']);
        $this->postJson("/web_api/auth/messages/conversations/{$conv['id']}/read")
            ->assertOk()
            ->assertJsonPath('marked_count', 1);
        $this->postJson("/web_api/auth/messages/conversations/{$conv['id']}/messages", [
            'body' => 'New send after leaving',
        ])->assertForbidden()->assertJsonPath('code', 'enrollment_not_active');

        $this->actingAs($this->teacherUser, 'web_api');
        $this->postJson("/web_api/auth/messages/conversations/{$conv['id']}/messages", [
            'body' => 'Teacher cannot message left student',
        ])->assertForbidden()->assertJsonPath('code', 'enrollment_not_active');
    }

    public function test_scheduled_announcements_clock_non_premature_publication_idempotency_and_recipient_filtering(): void
    {
        $baseNow = Carbon::parse('2026-11-12 10:00:00');
        Carbon::setTestNow($baseNow);

        $this->actingAs($this->teacherUser, 'web_api');
        $scheduledTime = $baseNow->copy()->addMinutes(30); // 10:30:00

        $scheduled = $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/announcements", [
            'title' => 'Clock Controlled Notice',
            'body' => 'Should go live strictly at 10:30.',
            'action' => 'schedule',
            'scheduled_at' => $scheduledTime->toIso8601String(),
        ])->assertCreated()->json('data');

        $this->assertEquals('scheduled', $scheduled['status']);

        // 1. 1 second before due time (10:29:59): scheduler runs, MUST remain scheduled and notify nobody
        Carbon::setTestNow($scheduledTime->copy()->subSecond());
        Artisan::call('announcements:publish-scheduled');

        $rowBefore = TeacherClassAnnouncement::findOrFail($scheduled['id']);
        $this->assertEquals('scheduled', $rowBefore->status);
        $this->assertNull($rowBefore->published_at);
        $this->assertNull($rowBefore->notified_at);
        $this->assertEquals(0, WebUserNotification::where('type', WebUserNotification::TYPE_CLASS_ANNOUNCEMENT)->count());

        $this->actingAs($this->studentA, 'web_api');
        $this->assertCount(0, $this->getJson('/web_api/auth/student/announcements')->assertOk()->json('data'));

        // 2. Exactly at due time (10:30:00): repeated scheduler executions publish once and send single notification
        Carbon::setTestNow($scheduledTime);
        Artisan::call('announcements:publish-scheduled');
        Artisan::call('announcements:publish-scheduled');
        Artisan::call('announcements:publish-scheduled');

        $rowDue = TeacherClassAnnouncement::findOrFail($scheduled['id']);
        $this->assertEquals('published', $rowDue->status);
        $this->assertNotNull($rowDue->published_at);
        $this->assertNotNull($rowDue->notified_at);

        // Active students only receive 1 notification each; inactive/outsider/other teacher receive 0
        $this->assertEquals(1, WebUserNotification::where('recipient_web_user_id', $this->studentA->id)
            ->where('type', WebUserNotification::TYPE_CLASS_ANNOUNCEMENT)->count());
        $this->assertEquals(1, WebUserNotification::where('recipient_web_user_id', $this->studentB->id)
            ->where('type', WebUserNotification::TYPE_CLASS_ANNOUNCEMENT)->count());
        $this->assertEquals(2, WebUserNotification::where('type', WebUserNotification::TYPE_CLASS_ANNOUNCEMENT)->count());

        foreach ([$this->pendingStudent, $this->rejectedStudent, $this->removedStudent, $this->leftStudent, $this->outsiderStudent, $this->otherTeacherUser] as $nonRecipient) {
            $this->assertEquals(0, WebUserNotification::where('recipient_web_user_id', $nonRecipient->id)->count());
        }
    }

    public function test_discussion_moderation_reporting_closed_reply_blocking_and_pm_pagination_read_unread_lifecycle(): void
    {
        // --- Discussion reporting, resolve (dismissed & reviewed), close/reopen, moderation audit ---
        $this->actingAs($this->studentA, 'web_api');
        $topic = $this->postJson("/web_api/auth/student/classes/{$this->class->id}/discussions", [
            'title' => 'Thermodynamics Inquiry',
            'body' => 'Entropy in adiabatic expansion?',
        ])->assertCreated()->json('data');

        $this->actingAs($this->studentB, 'web_api');
        $reply = $this->postJson("/web_api/auth/student/classes/{$this->class->id}/discussions/{$topic['id']}/replies", [
            'body' => 'Reversible adiabatic has zero entropy change.',
        ])->assertCreated()->json('data');

        // StudentB reports topic; StudentA reports reply
        $topicReport = $this->postJson("/web_api/auth/student/classes/{$this->class->id}/discussions/{$topic['id']}/report", [
            'reason' => 'Duplicate topic check',
        ])->assertCreated()->json('data');

        $this->actingAs($this->studentA, 'web_api');
        $replyReport = $this->postJson("/web_api/auth/student/classes/{$this->class->id}/discussions/{$topic['id']}/report", [
            'reply_id' => $reply['id'],
            'reason' => 'Clarify reversible condition',
        ])->assertCreated()->json('data');

        // Teacher inspects open reports & resolves one as dismissed, hides reply via moderation
        $this->actingAs($this->teacherUser, 'web_api');
        $openReports = $this->getJson("/web_api/auth/teacher/classes/{$this->class->id}/discussions/reports?status=open")
            ->assertOk()->json('data');
        $this->assertCount(2, $openReports);

        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/discussions/reports/{$topicReport['id']}/resolve", [
            'decision' => 'dismissed',
        ])->assertOk()->assertJsonPath('data.status', 'dismissed');

        // Closing discussion blocks BOTH student AND teacher from posting new replies; reopening allows replies again
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/discussions/{$topic['id']}/closed", [
            'closed' => true,
        ])->assertOk()->assertJsonPath('data.status', 'closed');

        $this->actingAs($this->studentA, 'web_api');
        $this->postJson("/web_api/auth/student/classes/{$this->class->id}/discussions/{$topic['id']}/replies", [
            'body' => 'Reply while closed',
        ])->assertStatus(409);

        $this->actingAs($this->teacherUser, 'web_api');
        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/discussions/{$topic['id']}/replies", [
            'body' => 'Teacher reply while closed',
        ])->assertStatus(409);

        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/discussions/{$topic['id']}/closed", [
            'closed' => false,
        ])->assertOk()->assertJsonPath('data.status', 'open');

        $this->postJson("/web_api/auth/teacher/classes/{$this->class->id}/discussions/reports/{$replyReport['id']}/resolve", [
            'decision' => 'reviewed',
        ])->assertOk()->assertJsonPath('data.status', 'reviewed');

        $this->deleteJson("/web_api/auth/teacher/classes/{$this->class->id}/discussions/{$topic['id']}", [
            'mode' => 'hidden',
            'reason' => 'Moderation hide audit verification',
        ])->assertOk()->assertJsonPath('data.status', 'hidden');

        $this->assertDatabaseHas('teacher_class_discussions', [
            'id' => $topic['id'],
            'status' => 'hidden',
            'moderated_by_web_user_id' => $this->teacherUser->id,
            'moderation_reason' => 'Moderation hide audit verification',
        ]);
        $this->actingAs($this->studentA, 'web_api');
        $this->getJson("/web_api/auth/student/classes/{$this->class->id}/discussions/{$topic['id']}")->assertNotFound();

        // --- Private messaging bidirectional read/unread + pagination isolation across conversations ---
        $this->actingAs($this->teacherUser, 'web_api');
        $convA = $this->postJson('/web_api/auth/messages/conversations', [
            'recipient_web_user_id' => $this->studentA->id,
            'class_id' => $this->class->id,
            'initial_message' => 'ConvA Msg 1',
        ])->assertOk()->json('data');
        $this->postJson("/web_api/auth/messages/conversations/{$convA['id']}/messages", ['body' => 'ConvA Msg 2'])->assertCreated();

        // ConvB belongs to StudentB (3 messages) — must never leak into ConvA pages
        $convB = $this->postJson('/web_api/auth/messages/conversations', [
            'recipient_web_user_id' => $this->studentB->id,
            'class_id' => $this->class->id,
            'initial_message' => 'ConvB ISOLATED 1',
        ])->assertOk()->json('data');
        $this->postJson("/web_api/auth/messages/conversations/{$convB['id']}/messages", ['body' => 'ConvB ISOLATED 2'])->assertCreated();
        $this->postJson("/web_api/auth/messages/conversations/{$convB['id']}/messages", ['body' => 'ConvB ISOLATED 3'])->assertCreated();

        // StudentA sees unread=2, marks read, then sends 3 replies -> Teacher sees unread=3, marks read
        $this->actingAs($this->studentA, 'web_api');
        $this->getJson('/web_api/auth/messages/conversations')->assertOk()->assertJsonPath('total_unread_count', 2);
        $this->postJson("/web_api/auth/messages/conversations/{$convA['id']}/read")->assertOk()->assertJsonPath('marked_count', 2);
        $this->getJson('/web_api/auth/messages/conversations')->assertOk()->assertJsonPath('total_unread_count', 0);

        $this->postJson("/web_api/auth/messages/conversations/{$convA['id']}/messages", ['body' => 'ConvA Msg 3'])->assertCreated();
        $this->postJson("/web_api/auth/messages/conversations/{$convA['id']}/messages", ['body' => 'ConvA Msg 4'])->assertCreated();
        $this->postJson("/web_api/auth/messages/conversations/{$convA['id']}/messages", ['body' => 'ConvA Msg 5'])->assertCreated();

        $this->actingAs($this->teacherUser, 'web_api');
        $this->getJson('/web_api/auth/messages/conversations')->assertOk()->assertJsonPath('total_unread_count', 3);
        $this->postJson("/web_api/auth/messages/conversations/{$convA['id']}/read")->assertOk()->assertJsonPath('marked_count', 3);
        $this->getJson('/web_api/auth/messages/conversations')->assertOk()->assertJsonPath('total_unread_count', 0);

        $freshConvA = TeacherStudentConversation::findOrFail($convA['id']);
        $this->assertNotNull($freshConvA->teacher_last_read_at);
        $this->assertNotNull($freshConvA->student_last_read_at);

        // Pagination check on ConvA (`per_page=2`): latest page (Msg 4, Msg 5) and page 2 (Msg 2, Msg 3), strictly isolated from ConvB
        $page1 = $this->getJson("/web_api/auth/messages/conversations/{$convA['id']}/messages?per_page=2&page=1")
            ->assertOk()->json();
        $this->assertEquals(5, $page1['pagination']['total']);
        $this->assertEquals(['ConvA Msg 4', 'ConvA Msg 5'], array_column($page1['data'], 'body'));

        $page2 = $this->getJson("/web_api/auth/messages/conversations/{$convA['id']}/messages?per_page=2&page=2")
            ->assertOk()->json();
        $this->assertEquals(['ConvA Msg 2', 'ConvA Msg 3'], array_column($page2['data'], 'body'));

        $page3 = $this->getJson("/web_api/auth/messages/conversations/{$convA['id']}/messages?per_page=2&page=3")
            ->assertOk()->json();
        $this->assertEquals(['ConvA Msg 1'], array_column($page3['data'], 'body'));

        foreach (array_merge($page1['data'], $page2['data'], $page3['data']) as $row) {
            $this->assertEquals($convA['id'], $row['conversation_id']);
            $this->assertStringNotContainsString('ConvB ISOLATED', $row['body']);
        }
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
