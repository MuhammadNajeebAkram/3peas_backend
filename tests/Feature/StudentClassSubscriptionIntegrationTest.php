<?php

namespace Tests\Feature;

use App\Models\TeacherClass;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherClassAssignmentRecipient;
use App\Models\TeacherClassAssignmentSubmission;
use App\Models\TeacherClassAssignmentEvaluation;
use App\Models\TeacherClassEnrollment;
use App\Models\TeacherClassTest;
use App\Models\TeacherClassTestAssignment;
use App\Models\TeacherClassTestAttempt;
use App\Models\TeacherClassTestRecipient;
use App\Models\TeacherProfile;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudentClassSubscriptionIntegrationTest extends TestCase
{
    private WebUser $student;
    private WebUser $teacher;
    private TeacherProfile $teacherProfile;
    private TeacherClass $classBio;
    private TeacherClass $classMath;
    private TeacherClass $classShared;
    private TeacherClass $classApproval;

    protected function setUp(): void
    {
        parent::setUp();

        // STRICT SAFETY GATE: Verify that connection is MySQL and database is 3peas_db_subscription_test
        $connName = config('database.default');
        $dbName = config("database.connections.{$connName}.database");
        if ($dbName !== '3peas_db_subscription_test') {
            throw new \RuntimeException("CRITICAL SAFETY BLOCK: Active database is '{$dbName}', but must be '3peas_db_subscription_test'!");
        }

        $activeDb = DB::select("SELECT DATABASE() as db")[0]->db ?? '';
        if ($activeDb !== '3peas_db_subscription_test') {
            throw new \RuntimeException("CRITICAL SAFETY BLOCK: MySQL connection is active on '{$activeDb}', not '3peas_db_subscription_test'!");
        }

        // Disable cookie-based JWT middleware for web_api testing
        $this->withoutMiddleware([
            \App\Http\Middleware\AttachJwtFromCookie::class,
            \App\Http\Middleware\AuthenticateJwtCookieGuard::class,
        ]);

        $this->setUpSyntheticFixtures();
    }

    private function setUpSyntheticFixtures(): void
    {
        // 1. Synthetic Curriculum Fixtures
        DB::table('class_tbl')->updateOrInsert(['id' => 991], [
            'class_name' => 'Synthetic Grade 10',
            'activate' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curriculum_board_tbl')->updateOrInsert(['id' => 991], [
            'name' => 'Synthetic Punjab Board',
            'activate' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curriculum_board_tbl')->updateOrInsert(['id' => 992], [
            'name' => 'Synthetic Federal Board',
            'activate' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Subjects: 991 = Biology, 992 = Mathematics, 993 = Shared Chemistry
        DB::table('subject_tbl')->updateOrInsert(['id' => 991], [
            'subject_name' => 'Synthetic Biology',
            'icon_name' => 'bio.svg',
            'activate' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('subject_tbl')->updateOrInsert(['id' => 992], [
            'subject_name' => 'Synthetic Mathematics',
            'icon_name' => 'math.svg',
            'activate' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('subject_tbl')->updateOrInsert(['id' => 993], [
            'subject_name' => 'Synthetic Shared Chemistry',
            'icon_name' => 'chem.svg',
            'activate' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Synthetic Offered Classes
        DB::table('offered_classes')->updateOrInsert(['id' => 991], [
            'class_id' => 991,
            'curriculum_board_id' => 991,
            'Price' => 1000,
            'discount_price' => 800,
            'is_active' => 1,
        ]);
        DB::table('offered_classes')->updateOrInsert(['id' => 992], [
            'class_id' => 991,
            'curriculum_board_id' => 992,
            'Price' => 1200,
            'discount_price' => 1000,
            'is_active' => 1,
        ]);

        // 3. Synthetic Offered Programs
        // Program A (ID 991): Punjab Bio + Chem
        DB::table('offered_programs')->updateOrInsert(['id' => 991], [
            'offered_class_id' => 991,
            'title' => 'Synthetic Program A (Bio)',
            'slug' => 'synthetic-program-a-bio',
            'is_active' => 1,
        ]);
        // Program B (ID 992): Punjab Math
        DB::table('offered_programs')->updateOrInsert(['id' => 992], [
            'offered_class_id' => 991,
            'title' => 'Synthetic Program B (Math)',
            'slug' => 'synthetic-program-b-math',
            'is_active' => 1,
        ]);
        // Program C (ID 993): Punjab General Science covering Chem (Shared)
        DB::table('offered_programs')->updateOrInsert(['id' => 993], [
            'offered_class_id' => 991,
            'title' => 'Synthetic Program C (Chem Shared)',
            'slug' => 'synthetic-program-c-chem-shared',
            'is_active' => 1,
        ]);

        // 4. Program-Subject Mappings
        DB::table('program_subjects')->whereIn('offered_program_id', [991, 992, 993])->delete();
        DB::table('program_subjects')->insert([
            ['id' => 9911, 'offered_program_id' => 991, 'subject_id' => 991, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()], // Prog A -> Bio
            ['id' => 9912, 'offered_program_id' => 991, 'subject_id' => 993, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()], // Prog A -> Chem (Shared)
            ['id' => 9921, 'offered_program_id' => 992, 'subject_id' => 992, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()], // Prog B -> Math
            ['id' => 9931, 'offered_program_id' => 993, 'subject_id' => 993, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()], // Prog C -> Chem (Shared)
        ]);

        // 5. Synthetic Users
        $this->teacher = WebUser::updateOrCreate(
            ['email' => 'synthetic.teacher@example.test'],
            [
                'name' => 'Synthetic Teacher',
                'role' => 'teacher',
                'status' => 'active',
                'password' => bcrypt('secret123'),
            ]
        );

        $this->teacherProfile = TeacherProfile::firstOrCreate(
            ['web_user_id' => $this->teacher->id]
        );
        $this->teacherProfile->forceFill([
            'teacher_code' => 'TCH_SYN_01',
            'status' => 'active',
            'can_collect_student_subscriptions' => true,
        ])->save();

        $subscriptionService = app(\App\Http\Services\TeacherSubscriptionService::class);
        $subscriptionService->assignDefaultFree($this->teacherProfile->id);

        $this->student = WebUser::updateOrCreate(
            ['email' => 'synthetic.student@example.test'],
            [
                'name' => 'Synthetic Student',
                'role' => 'student',
                'status' => 'active',
                'password' => bcrypt('secret123'),
            ]
        );

        // Clear previous subscriptions and enrollments for test student
        DB::table('user_subscriptions')->where('user_id', $this->student->id)->delete();
        DB::table('subscription_payment_requests')->where('user_id', $this->student->id)->delete();
        DB::table('teacher_class_enrollments')->where('student_web_user_id', $this->student->id)->delete();

        // 6. Synthetic Classes
        DB::table('teacher_classes')->whereIn('class_code', ['SYNBIO01', 'SYNMTH01', 'SYNCHM01', 'SYNAPP01'])->delete();

        $this->classBio = TeacherClass::forceCreate([
            'class_code' => 'SYNBIO01',
            'teacher_profile_id' => $this->teacherProfile->id,
            'curriculum_board_id' => 991,
            'class_id' => 991,
            'subject_id' => 991, // Biology
            'name' => 'Synthetic Punjab Biology 10',
            'section' => 'A',
            'is_active' => true,
            'requires_approval' => false,
        ]);

        $this->classMath = TeacherClass::forceCreate([
            'class_code' => 'SYNMTH01',
            'teacher_profile_id' => $this->teacherProfile->id,
            'curriculum_board_id' => 991,
            'class_id' => 991,
            'subject_id' => 992, // Mathematics
            'name' => 'Synthetic Punjab Mathematics 10',
            'section' => 'B',
            'is_active' => true,
            'requires_approval' => false,
        ]);

        $this->classShared = TeacherClass::forceCreate([
            'class_code' => 'SYNCHM01',
            'teacher_profile_id' => $this->teacherProfile->id,
            'curriculum_board_id' => 991,
            'class_id' => 991,
            'subject_id' => 993, // Shared Chemistry
            'name' => 'Synthetic Punjab Chemistry (Shared)',
            'section' => 'C',
            'is_active' => true,
            'requires_approval' => false,
        ]);

        $this->classApproval = TeacherClass::forceCreate([
            'class_code' => 'SYNAPP01',
            'teacher_profile_id' => $this->teacherProfile->id,
            'curriculum_board_id' => 991,
            'class_id' => 991,
            'subject_id' => 991, // Biology requiring approval
            'name' => 'Synthetic Biology (Approval Required)',
            'section' => 'D',
            'is_active' => true,
            'requires_approval' => true,
        ]);
    }

    private function createSubscription(int $programId, string $status = 'active', ?string $expiresAt = null): int
    {
        return DB::table('user_subscriptions')->insertGetId([
            'user_id' => $this->student->id,
            'offered_program_id' => $programId,
            'status' => $status,
            'access_type' => 'paid',
            'price_paid' => 800,
            'started_at' => now()->subDays(5)->toDateString(),
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // =========================================================================
    // PART C: Integration Scenarios 1 - 9
    // =========================================================================

    /**
     * Scenario 1: Student with no subscription cannot join (HTTP 403, code: subscription_required).
     */
    public function test_scenario_1_no_subscription_denied(): void
    {
        $this->actingAs($this->student, 'web_api');

        $response = $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $this->classBio->class_code,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'subscription_required');

        $this->assertDatabaseMissing('teacher_class_enrollments', [
            'teacher_class_id' => $this->classBio->id,
            'student_web_user_id' => $this->student->id,
        ]);
    }

    /**
     * Scenario 2: Student with valid matching subscription joins successfully.
     */
    public function test_scenario_2_valid_matching_subscription_joins_successfully(): void
    {
        $this->createSubscription(991, 'active', now()->addYear()->toDateString());

        $this->actingAs($this->student, 'web_api');

        $response = $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $this->classBio->class_code,
        ]);

        $response->assertCreated()
            ->assertJsonPath('outcome', 'enrolled')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('teacher_class_enrollments', [
            'teacher_class_id' => $this->classBio->id,
            'student_web_user_id' => $this->student->id,
            'status' => 'active',
        ]);
    }

    /**
     * Scenario 3: Student subscribed to Program A cannot join Program B class (Wrong Program).
     */
    public function test_scenario_3_wrong_program_denied(): void
    {
        // Subscribed to Program A (Bio), attempts to join Mathematics class
        $this->createSubscription(991, 'active', now()->addYear()->toDateString());

        $this->actingAs($this->student, 'web_api');

        $response = $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $this->classMath->class_code,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'subscription_required');

        $this->assertDatabaseMissing('teacher_class_enrollments', [
            'teacher_class_id' => $this->classMath->id,
            'student_web_user_id' => $this->student->id,
        ]);
    }

    /**
     * Scenario 4: Expired subscription cannot join (HTTP 403).
     */
    public function test_scenario_4_expired_subscription_denied(): void
    {
        // Expired yesterday
        $this->createSubscription(991, 'active', now()->subDay()->toDateString());

        $this->actingAs($this->student, 'web_api');

        $response = $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $this->classBio->class_code,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'subscription_expired');

        $this->assertDatabaseMissing('teacher_class_enrollments', [
            'teacher_class_id' => $this->classBio->id,
            'student_web_user_id' => $this->student->id,
        ]);
    }

    /**
     * Scenario 5: Pending subscription payment request does not grant access.
     */
    public function test_scenario_5_pending_subscription_denied(): void
    {
        DB::table('subscription_payment_requests')->insert([
            'id' => 9991,
            'user_id' => $this->student->id,
            'offered_program_id' => 991,
            'status' => 'pending',
            'price' => 1000,
            'final_amount' => 800,
            'payment_method' => 'bank_deposit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->student, 'web_api');

        $response = $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $this->classBio->class_code,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'subscription_required');

        $this->assertDatabaseMissing('teacher_class_enrollments', [
            'teacher_class_id' => $this->classBio->id,
            'student_web_user_id' => $this->student->id,
        ]);
    }

    /**
     * Scenario 6: Shared subject covered by two programs grants access with only one active subscription.
     */
    public function test_scenario_6_shared_subject_single_program_subscription_grants_access(): void
    {
        // Subscribed only to Program C (covering Chemistry), classShared is Chemistry
        $this->createSubscription(993, 'active', now()->addYear()->toDateString());

        $this->actingAs($this->student, 'web_api');

        $response = $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $this->classShared->class_code,
        ]);

        $response->assertCreated()
            ->assertJsonPath('outcome', 'enrolled')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('teacher_class_enrollments', [
            'teacher_class_id' => $this->classShared->id,
            'student_web_user_id' => $this->student->id,
            'status' => 'active',
        ]);
    }

    /**
     * Scenario 7: Teacher cannot approve pending enrollment if student's subscription expired.
     */
    public function test_scenario_7_teacher_approval_recheck_blocks_approval_if_subscription_expired(): void
    {
        // 1. Student joins class requiring approval while having active subscription
        $subId = $this->createSubscription(991, 'active', now()->addDays(5)->toDateString());

        $this->actingAs($this->student, 'web_api');
        $joinRes = $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $this->classApproval->class_code,
        ]);
        $joinRes->assertCreated()->assertJsonPath('outcome', 'pending');
        $enrollmentId = $joinRes->json('data.id');

        // 2. Subscription expires before teacher reviews
        DB::table('user_subscriptions')->where('id', $subId)->update([
            'expires_at' => now()->subDay()->toDateString(),
        ]);

        // 3. Teacher attempts to approve
        $this->actingAs($this->teacher, 'web_api');
        $approveRes = $this->postJson("/web_api/auth/teacher/classes/{$this->classApproval->id}/enrollments/{$enrollmentId}/approve");

        $approveRes->assertStatus(422)
            ->assertJsonPath('code', 'subscription_expired');

        // Enrollment remains pending
        $this->assertDatabaseHas('teacher_class_enrollments', [
            'id' => $enrollmentId,
            'status' => 'pending',
        ]);
    }

    /**
     * Scenario 8: Historical finalized test review remains accessible, but starting new attempts is blocked.
     */
    public function test_scenario_8_historical_test_review_accessible_after_expiry(): void
    {
        $enrollment = TeacherClassEnrollment::create([
            'teacher_class_id' => $this->classBio->id,
            'student_web_user_id' => $this->student->id,
            'status' => 'active',
            'requested_at' => now()->subDays(10),
            'approved_at' => now()->subDays(10),
        ]);

        $test = TeacherClassTest::forceCreate([
            'teacher_class_id' => $this->classBio->id,
            'teacher_profile_id' => $this->teacherProfile->id,
            'curriculum_board_id' => 991,
            'class_id' => 991,
            'subject_id' => 991,
            'title' => 'Synthetic Final Exam',
            'status' => TeacherClassTest::STATUS_PUBLISHED,
            'total_questions' => 1,
            'marks_per_question' => 10,
            'total_marks' => 10,
            'duration_minutes' => 30,
            'max_attempts' => 1,
            'scheduled_start_at' => now()->subDays(5),
            'scheduled_end_at' => now()->subDays(4),
            'published_at' => now()->subDays(5),
            'result_visibility' => 'after_submission',
        ]);

        $assignment = TeacherClassTestAssignment::forceCreate([
            'teacher_class_test_id' => $test->id,
            'teacher_class_id' => $this->classBio->id,
            'teacher_profile_id' => $this->teacherProfile->id,
            'assignment_mode' => 'entire_class',
            'title_snapshot' => 'Synthetic Final Exam',
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->subDays(4),
            'duration_minutes' => 30,
            'max_attempts' => 1,
            'result_visibility' => 'after_submission',
            'status' => TeacherClassTestAssignment::STATUS_ACTIVE,
            'assigned_count' => 1,
            'assigned_by_web_user_id' => $this->teacher->id,
            'assigned_at' => now()->subDays(5),
        ]);

        $recipient = TeacherClassTestRecipient::forceCreate([
            'teacher_class_test_assignment_id' => $assignment->id,
            'teacher_class_test_id' => $test->id,
            'teacher_class_id' => $this->classBio->id,
            'student_web_user_id' => $this->student->id,
            'teacher_class_enrollment_id' => $enrollment->id,
            'assignment_status' => 'assigned',
            'assigned_at' => now()->subDays(5),
        ]);

        $attempt = TeacherClassTestAttempt::forceCreate([
            'teacher_class_test_assignment_id' => $assignment->id,
            'teacher_class_test_recipient_id' => $recipient->id,
            'teacher_class_test_id' => $test->id,
            'teacher_class_id' => $this->classBio->id,
            'student_web_user_id' => $this->student->id,
            'attempt_number' => 1,
            'status' => TeacherClassTestAttempt::STATUS_SUBMITTED,
            'started_at' => now()->subDays(5),
            'deadline_at' => now()->subDays(5)->addMinutes(30),
            'submitted_at' => now()->subDays(5)->addMinutes(20),
            'finalized_at' => now()->subDays(5)->addMinutes(20),
            'total_questions' => 1,
            'answered_questions' => 1,
            'obtained_marks' => 8.0,
            'max_marks' => 10.0,
        ]);

        // Student subscription has expired
        $this->createSubscription(991, 'active', now()->subDays(2)->toDateString());

        $this->actingAs($this->student, 'web_api');

        // Historical finalized review is accessible
        $reviewRes = $this->getJson("/web_api/auth/student/tests/{$assignment->id}/attempts/{$attempt->id}");
        $reviewRes->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        // Active participation (starting a new attempt) is blocked
        $startRes = $this->postJson("/web_api/auth/student/tests/{$assignment->id}/start");
        $startRes->assertStatus(403)
            ->assertJsonPath('code', 'subscription_expired');
    }

    /**
     * Scenario 9: Historical assignment submission remains accessible, but new submissions are blocked.
     */
    public function test_scenario_9_historical_assignment_accessible_after_expiry(): void
    {
        $enrollment = TeacherClassEnrollment::create([
            'teacher_class_id' => $this->classBio->id,
            'student_web_user_id' => $this->student->id,
            'status' => 'active',
            'requested_at' => now()->subDays(10),
            'approved_at' => now()->subDays(10),
        ]);

        $assignment = TeacherClassAssignment::forceCreate([
            'teacher_class_id' => $this->classBio->id,
            'teacher_profile_id' => $this->teacherProfile->id,
            'title' => 'Synthetic Biology Homework',
            'instructions' => 'Complete the cellular diagram',
            'status' => TeacherClassAssignment::STATUS_PUBLISHED,
            'max_marks' => 20,
            'due_at' => now()->subDays(2),
            'late_policy' => 'allow_late',
        ]);

        TeacherClassAssignmentRecipient::forceCreate([
            'assignment_id' => $assignment->id,
            'student_web_user_id' => $this->student->id,
            'assigned_at' => now()->subDays(5),
        ]);

        $submission = TeacherClassAssignmentSubmission::forceCreate([
            'assignment_id' => $assignment->id,
            'teacher_class_id' => $this->classBio->id,
            'student_web_user_id' => $this->student->id,
            'status' => 'submitted',
            'submission_version' => 1,
            'submitted_at' => now()->subDays(3),
            'text_response' => 'Completed homework submission content',
        ]);

        TeacherClassAssignmentEvaluation::forceCreate([
            'assignment_id' => $assignment->id,
            'teacher_class_id' => $this->classBio->id,
            'submission_id' => $submission->id,
            'student_web_user_id' => $this->student->id,
            'evaluated_by_web_user_id' => $this->teacher->id,
            'status' => 'released',
            'evaluation_type' => 'graded',
            'obtained_marks' => 18,
            'max_marks_snapshot' => 20,
            'feedback_text' => 'Good work on organelles',
            'released_at' => now()->subDays(2),
        ]);

        // Subscription is expired
        $this->createSubscription(991, 'active', now()->subDay()->toDateString());

        $this->actingAs($this->student, 'web_api');

        // Historical view remains accessible
        $viewRes = $this->getJson("/web_api/auth/student/assignments/{$assignment->id}");
        $viewRes->assertOk();

        // New submission attempt is blocked
        $submitRes = $this->postJson("/web_api/auth/student/assignments/{$assignment->id}/submit", [
            'submission_type' => 'text',
            'text_content' => 'Late retry submission',
        ]);
        $submitRes->assertStatus(403)
            ->assertJsonPath('code', 'subscription_expired');
    }

    // =========================================================================
    // PART D: Boundary Conditions
    // =========================================================================

    /**
     * Boundary 1: expires_at = null (non-expiring lifetime/session subscription) grants access.
     */
    public function test_boundary_expires_at_null_grants_access(): void
    {
        $this->createSubscription(991, 'active', null);

        $this->actingAs($this->student, 'web_api');

        $response = $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $this->classBio->class_code,
        ]);

        $response->assertCreated()
            ->assertJsonPath('outcome', 'enrolled');
    }

    /**
     * Boundary 2: Expiry date equal to today grants access (active through end of day).
     */
    public function test_boundary_expiry_date_equal_to_today_grants_access(): void
    {
        $this->createSubscription(991, 'active', Carbon::today()->toDateString());

        $this->actingAs($this->student, 'web_api');

        $response = $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $this->classBio->class_code,
        ]);

        $response->assertCreated()
            ->assertJsonPath('outcome', 'enrolled');
    }

    /**
     * Boundary 3: Expiry date before today denies access.
     */
    public function test_boundary_expiry_date_before_today_denies_access(): void
    {
        $this->createSubscription(991, 'active', Carbon::yesterday()->toDateString());

        $this->actingAs($this->student, 'web_api');

        $response = $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $this->classBio->class_code,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'subscription_expired');
    }

    /**
     * Boundary 4: Inactive offered class denies access even if student holds subscription.
     */
    public function test_boundary_inactive_offered_class_denies_access(): void
    {
        $this->createSubscription(991, 'active', now()->addYear()->toDateString());
        DB::table('offered_classes')->where('id', 991)->update(['is_active' => 0]);

        $this->actingAs($this->student, 'web_api');

        $response = $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $this->classBio->class_code,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'program_not_covered');
    }

    /**
     * Boundary 5: Inactive offered program denies access even if student holds subscription.
     */
    public function test_boundary_inactive_offered_program_denies_access(): void
    {
        $this->createSubscription(991, 'active', now()->addYear()->toDateString());
        DB::table('offered_programs')->where('id', 991)->update(['is_active' => 0]);

        $this->actingAs($this->student, 'web_api');

        $response = $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $this->classBio->class_code,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'program_not_covered');
    }

    /**
     * Boundary 6: Subject not mapped to subscribed program denies access.
     */
    public function test_boundary_subject_not_mapped_to_program_denies_access(): void
    {
        // Student is subscribed to Program B (Math), attempts to join Bio class
        $this->createSubscription(992, 'active', now()->addYear()->toDateString());

        $this->actingAs($this->student, 'web_api');

        $response = $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $this->classBio->class_code,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'subscription_required');
    }

    /**
     * Boundary 7: Multiple covering programs, only one active subscription grants access.
     */
    public function test_boundary_multiple_covering_programs_one_active_grants_access(): void
    {
        // Class Shared is covered by Program A (991) and Program C (993)
        // Student has expired subscription to Program A, but active subscription to Program C
        $this->createSubscription(991, 'active', now()->subMonth()->toDateString());
        $this->createSubscription(993, 'active', now()->addMonth()->toDateString());

        $this->actingAs($this->student, 'web_api');

        $response = $this->postJson('/web_api/auth/student/classes/join', [
            'class_code' => $this->classShared->class_code,
        ]);

        $response->assertCreated()
            ->assertJsonPath('outcome', 'enrolled');
    }
}
