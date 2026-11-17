<?php

namespace Tests\Feature;

use App\Http\Services\TeacherClassEnrollmentService;
use App\Models\TeacherClass;
use App\Models\TeacherClassEnrollment;
use App\Models\TeacherProfile;
use App\Models\TeacherSubscriptionFeature;
use App\Models\WebUser;
use App\Models\WebUserNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TeacherClassEnrollmentTest extends TeacherBackendTest
{
    private TeacherProfile $teacherProfile;
    private TeacherProfile $otherTeacherProfile;
    private WebUser $studentA;
    private WebUser $studentB;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['class_tbl', 'subject_tbl', 'curriculum_board_tbl', 'offered_classes', 'offered_programs', 'program_subjects', 'user_subscriptions'] as $table) {
            Schema::dropIfExists($table);
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->id();
                if ($table === 'class_tbl') {
                    $t->string('class_name');
                    $t->string('class_name_um')->nullable();
                    $t->boolean('activate')->default(true);
                } elseif ($table === 'subject_tbl') {
                    $t->string('subject_name');
                    $t->string('subject_name_um')->nullable();
                    $t->boolean('activate')->default(true);
                } elseif ($table === 'curriculum_board_tbl') {
                    $t->string('name');
                    $t->boolean('activate')->default(true);
                } elseif ($table === 'offered_classes') {
                    $t->unsignedBigInteger('class_id')->nullable();
                    $t->unsignedBigInteger('curriculum_board_id')->nullable()->default(1);
                    $t->decimal('Price', 10, 2)->default(1000);
                    $t->decimal('discount_price', 10, 2)->nullable();
                    $t->boolean('is_active')->default(true);
                    $t->boolean('is_free')->default(false);
                    $t->date('session_end')->nullable();
                    $t->date('session_start')->nullable();
                    $t->integer('display_order')->default(0);
                } elseif ($table === 'offered_programs') {
                    $t->unsignedBigInteger('offered_class_id');
                    $t->string('title');
                    $t->string('slug')->nullable();
                    $t->integer('display_order')->default(1);
                    $t->boolean('is_active')->default(true);
                } elseif ($table === 'program_subjects') {
                    $t->unsignedBigInteger('offered_program_id');
                    $t->unsignedBigInteger('subject_id');
                    $t->boolean('is_active')->default(true);
                } elseif ($table === 'user_subscriptions') {
                    $t->unsignedBigInteger('user_id');
                    $t->unsignedBigInteger('offered_program_id');
                    $t->string('status')->default('active');
                    $t->string('access_type')->default('paid');
                    $t->decimal('price_paid', 10, 2)->default(0);
                    $t->date('started_at')->nullable();
                    $t->date('expires_at')->nullable();
                    $t->date('approved_at')->nullable();
                    $t->unsignedBigInteger('approved_by')->nullable();
                }
                $t->timestamps();
            });
            if (in_array($table, ['class_tbl', 'subject_tbl', 'curriculum_board_tbl'])) {
                DB::table($table)->insert([
                    'id' => 1,
                    'activate' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ] + match ($table) {
                    'class_tbl' => ['class_name' => 'Grade 10'],
                    'subject_tbl' => ['subject_name' => 'Physics'],
                    default => ['name' => 'Punjab Board'],
                });
            }
        }

        DB::table('offered_classes')->insert([
            'id' => 1, 'class_id' => 1, 'curriculum_board_id' => 1, 'Price' => 1000, 'discount_price' => 800, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('offered_programs')->insert([
            'id' => 1, 'offered_class_id' => 1, 'title' => 'Grade 10 Science', 'slug' => 'grade-10-science', 'display_order' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('program_subjects')->insert([
            'id' => 1, 'offered_program_id' => 1, 'subject_id' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $subscriptions = app(\App\Http\Services\TeacherSubscriptionService::class);

        $this->teacherProfile = TeacherProfile::where('web_user_id', 2)->firstOrFail();
        $this->teacherProfile->forceFill(['status' => 'active'])->save();
        $subscriptions->assignDefaultFree($this->teacherProfile->id);

        $this->otherTeacherProfile = TeacherProfile::where('web_user_id', 3)->firstOrFail();
        $this->otherTeacherProfile->forceFill(['status' => 'active'])->save();
        $subscriptions->assignDefaultFree($this->otherTeacherProfile->id);

        $this->studentA = WebUser::findOrFail(1);
        $this->studentA->update(['email' => 'student.a@example.test', 'phone' => '03001112233']);

        $this->studentB = WebUser::create([
            'name' => 'Student Two',
            'email' => 'student.b@example.test',
            'phone' => '03009998877',
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
            DB::table('user_subscriptions')->insert([
                ['user_id' => $this->studentA->id, 'offered_program_id' => 1, 'status' => 'active', 'expires_at' => now()->addYear()->toDateString(), 'created_at' => now(), 'updated_at' => now()],
                ['user_id' => $this->studentB->id, 'offered_program_id' => 1, 'status' => 'active', 'expires_at' => now()->addYear()->toDateString(), 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    private function createClass(TeacherProfile $profile, array $overrides = []): TeacherClass
    {
        $class = new TeacherClass(array_merge([
            'class_id' => 1,
            'subject_id' => 1,
            'curriculum_board_id' => 1,
            'name' => 'Grade 10 Physics',
            'section' => 'A',
            'is_active' => true,
            'requires_approval' => true,
        ], $overrides));
        $class->teacher_profile_id = $profile->id;
        $class->class_code = TeacherClass::generateUniqueCode();
        $class->save();

        return $class;
    }

    public function test_student_can_join_auto_and_approval_classes_without_altering_class_code(): void
    {
        $autoClass = $this->createClass($this->teacherProfile, [
            'section' => 'Auto',
            'requires_approval' => false,
        ]);
        $approvalClass = $this->createClass($this->teacherProfile, [
            'section' => 'Appr',
            'requires_approval' => true,
        ]);
        $originalAutoCode = $autoClass->class_code;
        $originalApprovalCode = $approvalClass->class_code;

        $this->actingAs($this->studentA, 'web_api');

        // Whitespace and lowercase normalization should still resolve the exact code.
        $autoRes = $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => '  '.strtolower($autoClass->class_code).'  ',
        ])->assertCreated()
            ->assertJsonPath('outcome', 'enrolled')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.has_workspace_access', true);

        // Duplicate join is rejected cleanly.
        $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $autoClass->class_code,
        ])->assertStatus(409)->assertJsonPath('code', 'already_enrolled');

        $pendingRes = $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $approvalClass->class_code,
        ])->assertCreated()
            ->assertJsonPath('outcome', 'pending')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.has_workspace_access', false);

        // Duplicate pending join is rejected cleanly without duplicate notifications.
        $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $approvalClass->class_code,
        ])->assertStatus(409)->assertJsonPath('code', 'enrollment_already_pending');

        $this->assertSame($originalAutoCode, $autoClass->fresh()->class_code);
        $this->assertSame($originalApprovalCode, $approvalClass->fresh()->class_code);

        // Verify Student listing summary & workspace privilege isolation.
        $this->getJson('/web_api/auth/student/classes')
            ->assertOk()
            ->assertJsonPath('summary.active', 1)
            ->assertJsonPath('summary.pending', 1)
            ->assertJsonPath('summary.all', 2);

        // Enrollment in teacher class must NOT grant user_subscriptions self-study access.
        $this->assertDatabaseCount('user_subscriptions', 2);

        // Teacher notifications should contain exactly 2 notifications (1 enrolled, 1 requested).
        $this->actingAs($this->teacherProfile->webUser, 'web_api');
        $notifs = $this->getJson('/web_api/auth/notifications')->assertOk()
            ->assertJsonPath('unread_count', 2)
            ->json('data');
        $this->assertCount(2, $notifs);

        // Teacher classes index reflects real counts immediately without placeholders.
        $classesIndex = $this->getJson('/web_api/auth/teacher/classes')->assertOk()
            ->assertJsonPath('summary.total_students', 1)
            ->assertJsonPath('summary.pending_enrollment_requests', 1)
            ->json('data');
        $this->assertCount(2, $classesIndex);

        $this->assertNotNull($autoRes->json('data.id'));
        $this->assertNotNull($pendingRes->json('data.id'));
    }

    public function test_invalid_or_inactive_class_cannot_be_joined(): void
    {
        $inactiveClass = $this->createClass($this->teacherProfile, ['is_active' => false]);
        $activeClass = $this->createClass($this->otherTeacherProfile, ['section' => 'InactiveTeacher']);
        $this->otherTeacherProfile->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($this->studentA, 'web_api');

        $this->postJson('/web_api/auth/student/classes/join', ['class_code' => 'NOTEXIST'])
            ->assertUnprocessable();
        $this->postJson('/web_api/auth/student/classes/join', ['class_code' => $inactiveClass->class_code])
            ->assertUnprocessable();
        $this->postJson('/web_api/auth/student/classes/join', ['class_code' => $activeClass->class_code])
            ->assertUnprocessable();

        $this->assertDatabaseCount('teacher_class_enrollments', 0);
    }

    public function test_teacher_approve_reject_remove_and_student_leave_lifecycle_with_history_and_notifications(): void
    {
        $class = $this->createClass($this->teacherProfile, ['requires_approval' => true]);

        // Student A and Student B request to join.
        $this->actingAs($this->studentA, 'web_api');
        $enrollA = $this->postJson('/web_api/auth/student/classes/join', ['class_code' => $class->class_code])
            ->assertCreated()->json('data.id');

        $this->actingAs($this->studentB, 'web_api');
        $enrollB = $this->postJson('/web_api/auth/student/classes/join', ['class_code' => $class->class_code])
            ->assertCreated()->json('data.id');

        $this->actingAs($this->teacherProfile->webUser, 'web_api');

        // Reject requires reason.
        $this->postJson("/web_api/auth/teacher/classes/{$class->id}/enrollments/{$enrollB}/reject", [])
            ->assertUnprocessable();

        $this->postJson("/web_api/auth/teacher/classes/{$class->id}/enrollments/{$enrollB}/reject", [
            'reason' => 'Not assigned to Section A.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.status_reason', 'Not assigned to Section A.');

        // Teacher cannot approve an already rejected request.
        $this->postJson("/web_api/auth/teacher/classes/{$class->id}/enrollments/{$enrollB}/approve")
            ->assertStatus(409);

        // Approve Student A.
        $this->postJson("/web_api/auth/teacher/classes/{$class->id}/enrollments/{$enrollA}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        // Student B sees rejection notification and status reason.
        $this->actingAs($this->studentB, 'web_api');
        $this->getJson('/web_api/auth/notifications')->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.type', WebUserNotification::TYPE_ENROLLMENT_REJECTED);

        // Student A receives approval notification and marks it read.
        $this->actingAs($this->studentA, 'web_api');
        $notifId = $this->getJson('/web_api/auth/notifications')->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.type', WebUserNotification::TYPE_ENROLLMENT_APPROVED)
            ->json('data.0.id');
        $this->postJson("/web_api/auth/notifications/{$notifId}/read")->assertOk()
            ->assertJsonPath('unread_count', 0)
            ->assertJsonPath('data.is_read', true);

        // Teacher removes Student A (requires reason), preserves history, sends notification.
        $this->actingAs($this->teacherProfile->webUser, 'web_api');
        $this->postJson("/web_api/auth/teacher/classes/{$class->id}/enrollments/{$enrollA}/remove", [])
            ->assertUnprocessable();
        $this->postJson("/web_api/auth/teacher/classes/{$class->id}/enrollments/{$enrollA}/remove", [
            'reason' => 'Transferred to evening batch.',
        ])->assertOk()->assertJsonPath('data.status', 'removed');

        $this->assertDatabaseHas('teacher_class_enrollment_events', [
            'teacher_class_enrollment_id' => $enrollA,
            'event_type' => 'removed',
            'previous_status' => 'active',
            'new_status' => 'removed',
            'reason' => 'Transferred to evening batch.',
        ]);

        // Even if class has requires_approval=false, a removed student rejoining goes to pending (cannot auto-bypass removal).
        $class->update(['requires_approval' => false]);
        $this->actingAs($this->studentA, 'web_api');
        $this->getJson('/web_api/auth/notifications')->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.type', WebUserNotification::TYPE_ENROLLMENT_REMOVED);

        $this->postJson('/web_api/auth/student/classes/join', ['class_code' => $class->class_code])
            ->assertCreated()
            ->assertJsonPath('outcome', 'pending')
            ->assertJsonPath('data.status', 'pending');

        // Re-approve Student A, then Student A voluntarily leaves.
        $this->actingAs($this->teacherProfile->webUser, 'web_api');
        $this->postJson("/web_api/auth/teacher/classes/{$class->id}/enrollments/{$enrollA}/approve")->assertOk();

        $this->actingAs($this->studentA, 'web_api');
        $this->postJson("/web_api/auth/student/classes/{$enrollA}/leave", ['reason' => 'Changed subjects'])
            ->assertOk()
            ->assertJsonPath('data.status', 'left')
            ->assertJsonPath('data.has_workspace_access', false);

        // Record physically preserved and full history intact.
        $this->assertDatabaseHas('teacher_class_enrollments', ['id' => $enrollA, 'status' => 'left']);
        $this->actingAs($this->teacherProfile->webUser, 'web_api');
        $detailEvents = $this->getJson("/web_api/auth/teacher/classes/{$class->id}/enrollments/{$enrollA}")
            ->assertOk()->json('data.events');
        $this->assertGreaterThanOrEqual(5, count($detailEvents));

        // Teacher got notified when Student A left.
        $this->assertDatabaseHas('web_user_notifications', [
            'recipient_web_user_id' => $this->teacherProfile->web_user_id,
            'type' => WebUserNotification::TYPE_ENROLLMENT_LEFT,
        ]);
    }

    public function test_roster_search_filter_and_constant_query_count_without_n_plus_one(): void
    {
        $class1 = $this->createClass($this->teacherProfile, ['section' => 'A']);

        $this->actingAs($this->teacherProfile->webUser, 'web_api');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/web_api/auth/teacher/classes')->assertOk()
            ->assertJsonPath('summary.total_classes', 1);
        $queryCountForOneClass = count(DB::getQueryLog());
        DB::disableQueryLog();

        $class2 = $this->createClass($this->teacherProfile, ['section' => 'B']);
        $class3 = $this->createClass($this->teacherProfile, ['section' => 'C']);

        $this->actingAs($this->studentA, 'web_api');
        $this->postJson('/web_api/auth/student/classes/join', ['class_code' => $class1->class_code])->assertCreated();

        $this->actingAs($this->studentB, 'web_api');
        $this->postJson('/web_api/auth/student/classes/join', ['class_code' => $class1->class_code])->assertCreated();
        $this->postJson('/web_api/auth/student/classes/join', ['class_code' => $class2->class_code])->assertCreated();

        $this->actingAs($this->teacherProfile->webUser, 'web_api');

        // Search filter narrows down by student name/email.
        $filtered = $this->getJson("/web_api/auth/teacher/classes/{$class1->id}/students?status=pending&search=Two")
            ->assertOk()
            ->assertJsonPath('counts.pending', 2)
            ->json('data');
        $this->assertCount(1, $filtered);
        $this->assertSame('Student Two', $filtered[0]['student']['name']);

        // Ensure index query count does not grow with number of classes (strict zero N+1).
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/web_api/auth/teacher/classes')->assertOk()
            ->assertJsonPath('summary.pending_enrollment_requests', 3);
        $queryCountForThreeClasses = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($queryCountForOneClass, $queryCountForThreeClasses);
        $this->assertNotNull($class3->id);
    }

    public function test_cross_user_isolation_feature_gating_and_blocked_account_enforcement(): void
    {
        $classA = $this->createClass($this->teacherProfile, ['requires_approval' => true]);

        $this->actingAs($this->studentA, 'web_api');
        $enrollId = $this->postJson('/web_api/auth/student/classes/join', ['class_code' => $classA->class_code])
            ->assertCreated()->json('data.id');

        // Student B cannot view or leave Student A's enrollment or notifications or access teacher endpoints.
        $this->actingAs($this->studentB, 'web_api');
        $this->getJson("/web_api/auth/student/classes/{$enrollId}")->assertNotFound();
        $this->postJson("/web_api/auth/student/classes/{$enrollId}/leave")->assertNotFound();
        $this->getJson('/web_api/auth/teacher/classes/'.$classA->id.'/students')->assertForbidden();
        $this->postJson("/web_api/auth/teacher/classes/{$classA->id}/enrollments/{$enrollId}/approve")->assertForbidden();

        // Teacher B cannot access Teacher A's roster, approve, reject, remove, history, or notification.
        $this->actingAs($this->otherTeacherProfile->webUser, 'web_api');
        $this->getJson("/web_api/auth/teacher/classes/{$classA->id}/students")->assertNotFound();
        $this->postJson("/web_api/auth/teacher/classes/{$classA->id}/enrollments/{$enrollId}/approve")->assertNotFound();
        $this->postJson("/web_api/auth/teacher/classes/{$classA->id}/enrollments/{$enrollId}/reject", ['reason' => 'Unauthorized reject attempt'])->assertNotFound();
        $this->getJson("/web_api/auth/teacher/classes/{$classA->id}/enrollment-history")->assertNotFound();

        $teacherANotifId = WebUserNotification::where('recipient_web_user_id', $this->teacherProfile->web_user_id)->value('id');
        $this->postJson("/web_api/auth/notifications/{$teacherANotifId}/read")->assertNotFound();

        // Teacher without students.enroll feature receives 403 Forbidden.
        $this->actingAs($this->teacherProfile->webUser, 'web_api');
        $sub = app(\App\Http\Services\TeacherSubscriptionService::class)->getCurrentSubscription($this->teacherProfile->id);
        TeacherSubscriptionFeature::where('teacher_subscription_plan_id', $sub->teacher_subscription_plan_id)
            ->where('feature_key', 'students.enroll')
            ->update(['is_enabled' => false]);

        $this->getJson("/web_api/auth/teacher/classes/{$classA->id}/students")
            ->assertForbidden()
            ->assertJsonPath('code', 'teacher_feature_unavailable');
        $this->postJson("/web_api/auth/teacher/classes/{$classA->id}/enrollments/{$enrollId}/approve")
            ->assertForbidden()
            ->assertJsonPath('code', 'teacher_feature_unavailable');

        // Re-enable feature, then block student and verify student access is rejected.
        TeacherSubscriptionFeature::where('teacher_subscription_plan_id', $sub->teacher_subscription_plan_id)
            ->where('feature_key', 'students.enroll')
            ->update(['is_enabled' => true]);

        $this->studentA->update(['status' => 'blocked']);
        $this->actingAs($this->studentA->fresh(), 'web_api');
        $this->getJson('/web_api/auth/student/classes')->assertForbidden();
        $this->postJson('/web_api/auth/student/classes/join', ['class_code' => $classA->class_code])->assertForbidden();
    }

    public function test_true_concurrent_join_and_approve_under_mysql_innodb(): void
    {
        $prefix = 'conc7a_'.strtolower(Str::random(6)).'_';
        $mysqlConfig = array_merge(config('database.connections.mysql'), ['prefix' => $prefix]);
        config(['database.connections.conc7a_mysql' => $mysqlConfig]);
        DB::purge('conc7a_mysql');
        $schema = Schema::connection('conc7a_mysql');
        $db = DB::connection('conc7a_mysql');

        $tables = [
            'web_user_notifications',
            'teacher_class_enrollment_events',
            'teacher_class_enrollments',
            'teacher_classes',
            'user_subscriptions',
            'program_subjects',
            'offered_programs',
            'offered_classes',
            'teacher_subscription_features',
            'teacher_subscriptions',
            'teacher_subscription_plans',
            'teacher_profiles',
            'curriculum_board_tbl',
            'subject_tbl',
            'class_tbl',
            'web_users',
        ];

        $barrierDir = storage_path('framework/testing/'.$prefix.'barriers');
        if (!is_dir($barrierDir)) {
            mkdir($barrierDir, 0777, true);
        }

        try {
            $schema->create('web_users', function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('email')->nullable();
                $t->string('role');
                $t->string('status');
                $t->string('phone')->nullable();
                $t->string('avatar')->nullable();
                $t->softDeletes();
                $t->timestamps();
            });
            $schema->create('class_tbl', function (Blueprint $t) {
                $t->id();
                $t->string('class_name');
                $t->string('class_name_um')->nullable();
                $t->timestamps();
            });
            $schema->create('subject_tbl', function (Blueprint $t) {
                $t->id();
                $t->string('subject_name');
                $t->string('subject_name_um')->nullable();
                $t->timestamps();
            });
            $schema->create('curriculum_board_tbl', function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->timestamps();
            });
            $schema->create('offered_classes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('class_id');
                $t->unsignedBigInteger('curriculum_board_id');
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
            $schema->create('offered_programs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('offered_class_id');
                $t->string('title');
                $t->string('slug');
                $t->integer('display_order')->default(1);
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
            $schema->create('program_subjects', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('offered_program_id');
                $t->unsignedBigInteger('subject_id');
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
            $schema->create('user_subscriptions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('offered_program_id');
                $t->string('status')->default('active');
                $t->string('access_type')->default('paid');
                $t->decimal('price_paid', 10, 2)->default(0);
                $t->date('started_at')->nullable();
                $t->date('expires_at')->nullable();
                $t->date('approved_at')->nullable();
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->timestamps();
            });
            $schema->create('teacher_profiles', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('web_user_id');
                $t->string('teacher_code')->nullable();
                $t->string('status')->default('active');
                $t->timestamps();
            });
            $schema->create('teacher_subscription_plans', function (Blueprint $t) {
                $t->id();
                $t->string('code')->unique();
                $t->string('name');
                $t->integer('max_active_classes')->nullable();
                $t->boolean('is_active')->default(true);
                $t->boolean('is_default')->default(false);
                $t->timestamps();
            });
            $schema->create('teacher_subscription_features', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('teacher_subscription_plan_id');
                $t->string('feature_key');
                $t->boolean('is_enabled')->default(true);
                $t->timestamps();
            });
            $schema->create('teacher_subscriptions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('teacher_profile_id');
                $t->unsignedBigInteger('teacher_subscription_plan_id');
                $t->string('status')->default('active');
                $t->timestamp('starts_at')->nullable();
                $t->timestamp('expires_at')->nullable();
                $t->timestamps();
            });
            $schema->create('teacher_classes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('teacher_profile_id');
                $t->unsignedBigInteger('class_id');
                $t->unsignedBigInteger('subject_id');
                $t->unsignedBigInteger('curriculum_board_id');
                $t->string('name', 120);
                $t->string('section', 20)->default('');
                $t->string('class_code', 16)->unique();
                $t->boolean('is_active')->default(true);
                $t->boolean('requires_approval')->default(true);
                $t->timestamps();
            });
            $schema->create('teacher_class_enrollments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('teacher_class_id');
                $t->unsignedBigInteger('student_web_user_id');
                $t->string('status', 20);
                $t->string('joined_via_code', 16)->nullable();
                $t->timestamp('requested_at')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->timestamp('rejected_at')->nullable();
                $t->timestamp('removed_at')->nullable();
                $t->timestamp('left_at')->nullable();
                $t->unsignedBigInteger('reviewed_by_web_user_id')->nullable();
                $t->unsignedBigInteger('removed_by_web_user_id')->nullable();
                $t->string('status_reason', 1000)->nullable();
                $t->timestamps();
                $t->unique(['teacher_class_id', 'student_web_user_id'], 'uk_conc_class_student');
            });
            $schema->create('teacher_class_enrollment_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('teacher_class_enrollment_id');
                $t->unsignedBigInteger('teacher_class_id');
                $t->unsignedBigInteger('student_web_user_id');
                $t->string('event_type', 32);
                $t->string('previous_status', 20)->nullable();
                $t->string('new_status', 20);
                $t->unsignedBigInteger('actor_web_user_id')->nullable();
                $t->string('actor_role', 20);
                $t->string('reason', 1000)->nullable();
                $t->json('metadata')->nullable();
                $t->timestamp('created_at')->useCurrent();
            });
            $schema->create('web_user_notifications', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('recipient_web_user_id');
                $t->unsignedBigInteger('actor_web_user_id')->nullable();
                $t->unsignedBigInteger('teacher_class_id')->nullable();
                $t->unsignedBigInteger('teacher_class_enrollment_id')->nullable();
                $t->string('type', 64);
                $t->string('title', 255);
                $t->text('message');
                $t->string('action_url', 255)->nullable();
                $t->json('metadata')->nullable();
                $t->boolean('is_read')->default(false);
                $t->timestamp('read_at')->nullable();
                $t->timestamps();
            });

            $db->table('web_users')->insert([
                ['id' => 1, 'name' => 'Student Conc', 'role' => 'student', 'status' => 'active', 'phone' => '03001000001'],
                ['id' => 2, 'name' => 'Teacher Conc', 'role' => 'teacher', 'status' => 'active', 'phone' => '03001000002'],
                ['id' => 3, 'name' => 'Student Conc 2', 'role' => 'student', 'status' => 'active', 'phone' => '03001000003'],
            ]);
            $db->table('class_tbl')->insert(['id' => 1, 'class_name' => 'Grade 10']);
            $db->table('subject_tbl')->insert(['id' => 1, 'subject_name' => 'Physics']);
            $db->table('curriculum_board_tbl')->insert(['id' => 1, 'name' => 'Punjab Board']);
            $db->table('offered_classes')->insert(['id' => 1, 'class_id' => 1, 'curriculum_board_id' => 1, 'is_active' => 1]);
            $db->table('offered_programs')->insert(['id' => 1, 'offered_class_id' => 1, 'title' => 'Grade 10 Science', 'slug' => 'grade-10-science', 'display_order' => 1, 'is_active' => 1]);
            $db->table('program_subjects')->insert(['id' => 1, 'offered_program_id' => 1, 'subject_id' => 1, 'is_active' => 1]);
            $db->table('user_subscriptions')->insert([
                ['id' => 1, 'user_id' => 1, 'offered_program_id' => 1, 'status' => 'active', 'expires_at' => now()->addYear()->toDateString()],
                ['id' => 2, 'user_id' => 3, 'offered_program_id' => 1, 'status' => 'active', 'expires_at' => now()->addYear()->toDateString()],
            ]);
            $db->table('teacher_profiles')->insert(['id' => 1, 'web_user_id' => 2, 'teacher_code' => 'TCH-CONC01', 'status' => 'active']);
            $db->table('teacher_subscription_plans')->insert(['id' => 1, 'code' => 'free', 'name' => 'Free', 'is_active' => 1, 'is_default' => 1]);
            $db->table('teacher_subscription_features')->insert([
                ['teacher_subscription_plan_id' => 1, 'feature_key' => 'classes.manage', 'is_enabled' => 1],
                ['teacher_subscription_plan_id' => 1, 'feature_key' => 'students.enroll', 'is_enabled' => 1],
            ]);
            $db->table('teacher_subscriptions')->insert([
                'id' => 1, 'teacher_profile_id' => 1, 'teacher_subscription_plan_id' => 1,
                'status' => 'active', 'starts_at' => now()->subDay(), 'expires_at' => now()->addYear(),
            ]);
            $db->table('teacher_classes')->insert([
                [
                    'id' => 1, 'teacher_profile_id' => 1, 'class_id' => 1, 'subject_id' => 1, 'curriculum_board_id' => 1,
                    'name' => 'Concurrent Auto Class', 'section' => 'A', 'class_code' => 'CONCAUT2', 'is_active' => 1, 'requires_approval' => 0,
                ],
                [
                    'id' => 2, 'teacher_profile_id' => 1, 'class_id' => 1, 'subject_id' => 1, 'curriculum_board_id' => 1,
                    'name' => 'Concurrent Appr Class', 'section' => 'B', 'class_code' => 'CONCAPR3', 'is_active' => 1, 'requires_approval' => 1,
                ],
            ]);

            // 1. Two parallel workers submit simultaneous join requests for the same student + same class
            [$joinA, $joinB] = $this->runConcurrentEnrollmentWorkers($prefix, $barrierDir, 'join_same', [
                ['action' => 'join', 'student_id' => 1, 'class_code' => 'CONCAUT2'],
                ['action' => 'join', 'student_id' => 1, 'class_code' => 'CONCAUT2'],
            ]);

            $this->assertTrue($joinA['barrier_synced'] && $joinB['barrier_synced'], 'Both join workers must hit barrier simultaneously.');
            $joinStatuses = [$joinA['status'], $joinB['status']];
            sort($joinStatuses);
            $this->assertSame([201, 409], $joinStatuses, 'Concurrent duplicate joins failed: '.json_encode([$joinA, $joinB]));
            $this->assertSame(1, $db->table('teacher_class_enrollments')->where('teacher_class_id', 1)->where('student_web_user_id', 1)->count());
            $this->assertSame(1, $db->table('teacher_class_enrollment_events')->where('teacher_class_id', 1)->where('student_web_user_id', 1)->count());

            // 2. Seed one pending enrollment on class 2, then race approve vs reject
            $db->table('teacher_class_enrollments')->insert([
                'id' => 10, 'teacher_class_id' => 2, 'student_web_user_id' => 3,
                'status' => 'pending', 'joined_via_code' => 'CONCAPR3', 'requested_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            [$revA, $revB] = $this->runConcurrentEnrollmentWorkers($prefix, $barrierDir, 'approve_vs_reject', [
                ['action' => 'approve', 'teacher_id' => 1, 'class_id' => 2, 'enrollment_id' => 10],
                ['action' => 'reject', 'teacher_id' => 1, 'class_id' => 2, 'enrollment_id' => 10, 'reason' => 'Race reject'],
            ]);

            $this->assertTrue($revA['barrier_synced'] && $revB['barrier_synced'], 'Both review workers must hit barrier simultaneously.');
            $revStatuses = [$revA['status'], $revB['status']];
            sort($revStatuses);
            $this->assertSame([200, 409], $revStatuses, 'Concurrent approve vs reject failed: '.json_encode([$revA, $revB]));
            $this->assertSame(1, $db->table('teacher_class_enrollment_events')->where('teacher_class_enrollment_id', 10)->count());
            $this->assertSame(1, $db->table('web_user_notifications')->where('teacher_class_enrollment_id', 10)->count());
        } finally {
            foreach ($tables as $table) {
                $schema->dropIfExists($table);
            }
            foreach (glob($barrierDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($barrierDir);
            DB::purge('conc7a_mysql');
        }
    }

    private function runConcurrentEnrollmentWorkers(string $prefix, string $barrierDir, string $group, array $workers): array
    {
        $script = <<<'PHP'
$autoload = $argv[1];
$basePath = $argv[2];
$payload = json_decode(base64_decode($argv[3]), true);
require $autoload;
$app = require $basePath.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$mysql = array_merge(config('database.connections.mysql'), ['prefix' => $payload['prefix']]);
config(['database.default' => 'conc7a_worker', 'database.connections.conc7a_worker' => $mysql]);
Illuminate\Support\Facades\DB::purge('conc7a_worker');

$barrierSynced = false;
file_put_contents($payload['self_file'], '1');
$deadline = microtime(true) + 3.0;
while (microtime(true) < $deadline) {
    if (file_exists($payload['peer_file'])) {
        $barrierSynced = true;
        break;
    }
    usleep(10000);
}

$service = app(App\Http\Services\TeacherClassEnrollmentService::class);
try {
    if ($payload['action'] === 'join') {
        $student = App\Models\WebUser::findOrFail($payload['student_id']);
        $res = $service->joinByCode($student, $payload['class_code']);
        echo json_encode(['status' => 201, 'outcome' => $res['outcome'], 'barrier_synced' => $barrierSynced]);
    } elseif ($payload['action'] === 'approve') {
        $teacher = App\Models\TeacherProfile::findOrFail($payload['teacher_id']);
        $res = $service->approve($teacher, (int) $payload['class_id'], (int) $payload['enrollment_id']);
        echo json_encode(['status' => 200, 'new_status' => $res->status, 'barrier_synced' => $barrierSynced]);
    } elseif ($payload['action'] === 'reject') {
        $teacher = App\Models\TeacherProfile::findOrFail($payload['teacher_id']);
        $res = $service->reject($teacher, (int) $payload['class_id'], (int) $payload['enrollment_id'], $payload['reason']);
        echo json_encode(['status' => 200, 'new_status' => $res->status, 'barrier_synced' => $barrierSynced]);
    }
} catch (Illuminate\Http\Exceptions\HttpResponseException $e) {
    echo json_encode([
        'status' => $e->getResponse()->getStatusCode(),
        'body' => json_decode($e->getResponse()->getContent(), true),
        'barrier_synced' => $barrierSynced,
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'status' => 500,
        'error' => get_class($e).': '.$e->getMessage(),
        'barrier_synced' => $barrierSynced,
    ]);
}
PHP;

        $procs = [];
        foreach ($workers as $idx => $worker) {
            $peerIdx = $idx === 0 ? 1 : 0;
            $payload = array_merge($worker, [
                'prefix' => $prefix,
                'self_file' => $barrierDir."/{$group}_{$idx}.ready",
                'peer_file' => $barrierDir."/{$group}_{$peerIdx}.ready",
            ]);
            $cmd = [
                PHP_BINARY,
                '-r',
                $script,
                base_path('vendor/autoload.php'),
                base_path(),
                base64_encode(json_encode($payload)),
            ];
            $pipes = [];
            $proc = proc_open($cmd, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, base_path());
            fclose($pipes[0]);
            $procs[$idx] = ['proc' => $proc, 'pipes' => $pipes];
        }

        $results = [];
        foreach ($procs as $idx => $item) {
            $stdout = stream_get_contents($item['pipes'][1]);
            $stderr = stream_get_contents($item['pipes'][2]);
            fclose($item['pipes'][1]);
            fclose($item['pipes'][2]);
            proc_close($item['proc']);
            $decoded = json_decode(trim($stdout), true);
            $this->assertIsArray($decoded, "Worker {$idx} invalid output: {$stdout} STDERR: {$stderr}");
            $results[$idx] = $decoded;
        }

        return $results;
    }
}
