<?php

namespace Tests\Feature;

use App\Http\Middleware\AttachJwtFromCookie;
use App\Http\Middleware\AuthenticateJwtCookieGuard;
use App\Http\Middleware\CheckPermission;
use App\Models\Book;
use App\Models\BookUnit;
use App\Models\BookUnitTopic;
use App\Models\CurriculumBoard;
use App\Models\ExamQuestion;
use App\Models\UserClass;
use App\Models\Permission;
use App\Models\QuestionReport;
use App\Models\QuestionReportAuditLog;
use App\Models\Role;
use App\Models\Subject;
use App\Models\User;
use App\Models\WebUser;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuestionReportingTest extends TestCase
{
    protected WebUser $student;
    protected WebUser $teacher;
    protected User $adminViewer;
    protected User $adminManager;
    protected User $unauthorizedAdmin;
    protected ExamQuestion $question;
    protected CurriculumBoard $curriculumBoard;
    protected UserClass $class;
    protected Subject $subject;
    protected BookUnit $unit;
    protected BookUnitTopic $topic;

    protected function setUp(): void
    {
        parent::setUp();

        app('router')->aliasMiddleware('permission', CheckPermission::class);
        $this->withoutMiddleware([
            AttachJwtFromCookie::class,
            AuthenticateJwtCookieGuard::class,
        ]);

        // Ensure permissions exist
        Permission::firstOrCreate(['name' => 'question-reports.view']);
        Permission::firstOrCreate(['name' => 'question-reports.manage']);

        // Create Admin users using project role_permissions pattern
        $this->adminViewer = $this->createAdminWithPermissions('qa_report_viewer_' . uniqid(), [
            'question-reports.view',
        ]);

        $this->adminManager = $this->createAdminWithPermissions('qa_report_manager_' . uniqid(), [
            'question-reports.view',
            'question-reports.manage',
        ]);

        $unauthRole = Role::create(['name' => 'unauth_role_' . uniqid()]);
        $this->unauthorizedAdmin = User::create([
            'name' => 'Unauthorized Admin',
            'email' => 'unauth.' . uniqid() . '@example.com',
            'password' => bcrypt('secret123'),
            'role_id' => $unauthRole->id,
        ]);

        // Create Web users
        $this->student = WebUser::create([
            'name' => 'Test Student Reporter',
            'email' => 'student.reporter.' . uniqid() . '@example.com',
            'phone' => '0300' . rand(1000000, 9999999),
            'password' => bcrypt('secret123'),
            'role' => 'student',
            'preferred_language' => 'en',
            'is_active' => true,
        ]);

        $this->teacher = WebUser::create([
            'name' => 'Test Teacher Reporter',
            'email' => 'teacher.reporter.' . uniqid() . '@example.com',
            'phone' => '0300' . rand(1000000, 9999999),
            'password' => bcrypt('secret123'),
            'role' => 'teacher',
            'preferred_language' => 'ur',
            'is_active' => true,
        ]);

        // Create Authoritative Curriculum Hierarchy
        $this->curriculumBoard = CurriculumBoard::firstOrCreate(['name' => 'Punjab Curriculum Board (PCTB)']);
        $this->class = UserClass::firstOrCreate(['class_name' => 'Class 9']);
        $this->subject = Subject::firstOrCreate(['subject_name' => 'Physics 9th'], ['icon_name' => 'physics.png', 'activate' => true]);

        $book = Book::create([
            'book_name' => 'Physics 9 Textbook ' . uniqid(),
            'curriculum_board_id' => $this->curriculumBoard->id,
            'class_id' => $this->class->id,
            'subject_id' => $this->subject->id,
        ]);

        $this->unit = BookUnit::create([
            'book_id' => $book->id,
            'unit_name' => 'Kinematics',
            'unit_no' => 2,
        ]);

        $this->topic = BookUnitTopic::create([
            'unit_id' => $this->unit->id,
            'topic_name' => 'Equations of Motion',
            'topic_no' => 1,
        ]);

        $this->question = new ExamQuestion();
        $this->question->question = '<p>What is the second equation of motion?</p>';
        $this->question->question_um = '<p>حرکت کی دوسری مساوات کیا ہے؟</p>';
        $this->question->topic_id = $this->topic->id;
        $this->question->question_type = 1; // MCQ
        $this->question->cognitive_domain = 1;
        $this->question->activate = true;
        $this->question->save();
    }

    private function createAdminWithPermissions(string $roleName, array $permissionNames): User
    {
        $role = Role::create(['name' => $roleName]);
        $user = User::create([
            'name' => ucfirst($roleName),
            'email' => $roleName . '.' . uniqid() . '@example.com',
            'password' => bcrypt('secret123'),
            'role_id' => $role->id,
        ]);

        foreach ($permissionNames as $name) {
            $perm = Permission::where('name', $name)->firstOrFail();
            DB::table('role_permissions')->insert([
                'role_id' => $role->id,
                'permission_id' => $perm->id,
            ]);
        }

        return $user;
    }

    public function test_student_can_submit_valid_question_report(): void
    {
        $this->actingAs($this->student, 'web_api');

        $response = $this->postJson('/web_api/auth/question-reports', [
            'question_id' => $this->question->id,
            'report_category' => 'missing_explanation',
            'description' => 'The step-by-step mathematical derivation is not shown.',
            'portal' => 'student',
            'page_context' => 'my_classes_study',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'question_id' => $this->question->id,
                    'report_category' => 'missing_explanation',
                    'status' => 'pending',
                ],
            ]);

        $this->assertDatabaseHas('question_reports', [
            'question_id' => $this->question->id,
            'reporter_web_user_id' => $this->student->id,
            'reporter_role' => 'student',
            'report_category' => 'missing_explanation',
            'curriculum_board_id' => $this->curriculumBoard->id,
            'class_id' => $this->class->id,
            'subject_id' => $this->subject->id,
            'book_unit_id' => $this->unit->id,
            'book_unit_topic_id' => $this->topic->id,
            'status' => 'pending',
        ]);
    }

    public function test_teacher_can_submit_valid_question_report(): void
    {
        $this->actingAs($this->teacher, 'web_api');

        $response = $this->postJson('/web_api/auth/question-reports', [
            'question_id' => $this->question->id,
            'report_category' => 'rendering_issue',
            'description' => 'LaTeX symbol for acceleration displays as broken text.',
            'portal' => 'teacher',
            'page_context' => 'teacher_test_builder',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'question_id' => $this->question->id,
                    'report_category' => 'rendering_issue',
                    'status' => 'pending',
                ],
            ]);

        $this->assertDatabaseHas('question_reports', [
            'question_id' => $this->question->id,
            'reporter_web_user_id' => $this->teacher->id,
            'reporter_role' => 'teacher',
            'portal' => 'teacher',
        ]);
    }

    public function test_all_12_categories_are_accepted_and_invalid_is_rejected(): void
    {
        $this->actingAs($this->student, 'web_api');

        // Invalid category rejected
        $badResponse = $this->postJson('/web_api/auth/question-reports', [
            'question_id' => $this->question->id,
            'report_category' => 'not_a_valid_category',
        ]);
        $badResponse->assertStatus(422)
            ->assertJsonValidationErrors(['report_category']);

        // Check each of the 12 categories accepts submission
        foreach (QuestionReport::CATEGORIES as $cat) {
            $ok = $this->postJson('/web_api/auth/question-reports', [
                'question_id' => $this->question->id,
                'report_category' => $cat,
                'description' => "Testing category {$cat}",
            ]);
            $ok->assertStatus(201);
        }
    }

    public function test_description_is_optional(): void
    {
        $this->actingAs($this->student, 'web_api');

        $response = $this->postJson('/web_api/auth/question-reports', [
            'question_id' => $this->question->id,
            'report_category' => 'incorrect_answer',
            // No description provided
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('question_reports', [
            'question_id' => $this->question->id,
            'report_category' => 'incorrect_answer',
            'description' => null,
        ]);
    }

    public function test_duplicate_submission_protection_for_same_user_and_question(): void
    {
        $this->actingAs($this->student, 'web_api');

        // First click
        $r1 = $this->postJson('/web_api/auth/question-reports', [
            'question_id' => $this->question->id,
            'report_category' => 'visibility_problem',
            'description' => 'Image is missing.',
        ]);
        $r1->assertStatus(201);
        $id1 = $r1->json('data.id');

        // Immediate second click (within 10s) returns existing report without creating a duplicate row
        $r2 = $this->postJson('/web_api/auth/question-reports', [
            'question_id' => $this->question->id,
            'report_category' => 'visibility_problem',
            'description' => 'Image is missing.',
        ]);
        $r2->assertStatus(201);
        $this->assertEquals($id1, $r2->json('data.id'));

        $count = QuestionReport::where('question_id', $this->question->id)
            ->where('reporter_web_user_id', $this->student->id)
            ->where('report_category', 'visibility_problem')
            ->count();

        $this->assertEquals(1, $count);
    }

    public function test_multiple_users_can_report_same_question_preserving_all_records(): void
    {
        // Student reports question
        $this->actingAs($this->student, 'web_api');
        $r1 = $this->postJson('/web_api/auth/question-reports', [
            'question_id' => $this->question->id,
            'report_category' => 'incorrect_question',
            'description' => 'The question is missing the initial velocity value.',
        ]);
        $r1->assertStatus(201);

        // Teacher reports same question with different category
        $this->actingAs($this->teacher, 'web_api');
        $r2 = $this->postJson('/web_api/auth/question-reports', [
            'question_id' => $this->question->id,
            'report_category' => 'missing_content',
            'description' => 'Graph diagram referenced in prompt is missing.',
        ]);
        $r2->assertStatus(201);

        // Verify both reports exist independently
        $this->assertNotEquals($r1->json('data.id'), $r2->json('data.id'));
        $this->assertEquals(2, QuestionReport::where('question_id', $this->question->id)->count());
    }

    public function test_user_can_view_own_reports_without_exposing_internal_admin_notes(): void
    {
        $report = QuestionReport::create([
            'question_id' => $this->question->id,
            'reporter_web_user_id' => $this->student->id,
            'reporter_role' => 'student',
            'report_category' => 'typographical_error',
            'description' => 'Spelling typo in option B',
            'status' => 'under_review',
        ]);

        QuestionReportAuditLog::create([
            'question_report_id' => $report->id,
            'admin_user_id' => $this->adminManager->id,
            'previous_status' => 'pending',
            'new_status' => 'under_review',
            'internal_note' => 'Confidential note: verified with textbook editor.',
            'action_type' => 'status_change',
        ]);

        $this->actingAs($this->student, 'web_api');

        $response = $this->getJson('/web_api/auth/question-reports/my-reports');
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertStringNotContainsString('Confidential note', json_encode($data));
    }

    public function test_admin_permissions_are_enforced_view_vs_manage(): void
    {
        $report = QuestionReport::create([
            'question_id' => $this->question->id,
            'reporter_web_user_id' => $this->student->id,
            'reporter_role' => 'student',
            'report_category' => 'duplicate_question',
            'status' => 'pending',
        ]);

        // Unauthorized admin rejected
        $this->actingAs($this->unauthorizedAdmin, 'api');
        $this->getJson('/api/admin/auth/question-reports')->assertStatus(403);
        $this->postJson("/api/admin/auth/question-reports/{$report->id}/status", [
            'status' => 'under_review',
        ])->assertStatus(403);

        // Viewer can view but cannot manage
        $this->actingAs($this->adminViewer, 'api');
        $this->getJson('/api/admin/auth/question-reports')->assertStatus(200);
        $this->getJson("/api/admin/auth/question-reports/{$report->id}")->assertStatus(200);
        $this->postJson("/api/admin/auth/question-reports/{$report->id}/status", [
            'status' => 'under_review',
        ])->assertStatus(403);

        // Manager can view and manage
        $this->actingAs($this->adminManager, 'api');
        $manageResp = $this->postJson("/api/admin/auth/question-reports/{$report->id}/status", [
            'status' => 'under_review',
            'internal_note' => 'Reviewing in physics syllabus committee.',
        ]);
        $manageResp->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'status' => 'under_review',
                ],
            ]);

        $this->assertDatabaseHas('question_reports', [
            'id' => $report->id,
            'status' => 'under_review',
        ]);

        $this->assertDatabaseHas('question_report_audit_logs', [
            'question_report_id' => $report->id,
            'admin_user_id' => $this->adminManager->id,
            'previous_status' => 'pending',
            'new_status' => 'under_review',
            'internal_note' => 'Reviewing in physics syllabus committee.',
        ]);
    }

    public function test_resolving_report_does_not_modify_underlying_question(): void
    {
        $originalQuestionText = $this->question->question;

        $report = QuestionReport::create([
            'question_id' => $this->question->id,
            'reporter_web_user_id' => $this->student->id,
            'reporter_role' => 'student',
            'report_category' => 'wrong_syllabus_chapter',
            'status' => 'pending',
        ]);

        $this->actingAs($this->adminManager, 'api');
        $response = $this->postJson("/api/admin/auth/question-reports/{$report->id}/status", [
            'status' => 'resolved',
            'internal_note' => 'Verified chapter alignment in board scheme.',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('question_reports', [
            'id' => $report->id,
            'status' => 'resolved',
        ]);

        // Underlying question must remain untouched
        $this->question->refresh();
        $this->assertEquals($originalQuestionText, $this->question->question);
    }

    public function test_admin_can_add_internal_note_without_changing_status(): void
    {
        $report = QuestionReport::create([
            'question_id' => $this->question->id,
            'reporter_web_user_id' => $this->teacher->id,
            'reporter_role' => 'teacher',
            'report_category' => 'other',
            'status' => 'pending',
        ]);

        $this->actingAs($this->adminManager, 'api');
        $response = $this->postJson("/api/admin/auth/question-reports/{$report->id}/notes", [
            'internal_note' => 'Internal observation: reported by senior teacher from Lahore.',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('question_report_audit_logs', [
            'question_report_id' => $report->id,
            'action_type' => 'note_added',
            'internal_note' => 'Internal observation: reported by senior teacher from Lahore.',
        ]);

        // Report status remains pending
        $report->refresh();
        $this->assertEquals('pending', $report->status);
    }

    public function test_question_bank_filter_returns_aggregated_reports_count(): void
    {
        Permission::firstOrCreate(['name' => 'questions.view']);
        $admin = $this->createAdminWithPermissions('qb_filter_admin_' . uniqid(), ['questions.view']);

        // Create a second question with no reports
        $cleanQuestion = new ExamQuestion();
        $cleanQuestion->question = '<p>Question with zero reports</p>';
        $cleanQuestion->topic_id = $this->topic->id;
        $cleanQuestion->question_type = 1;
        $cleanQuestion->activate = 1;
        $cleanQuestion->status = 'published';
        $cleanQuestion->save();

        // Create 2 reports against $this->question
        QuestionReport::create([
            'question_id' => $this->question->id,
            'reporter_web_user_id' => $this->student->id,
            'reporter_role' => 'student',
            'report_category' => 'incorrect_answer',
            'status' => 'pending',
        ]);

        QuestionReport::create([
            'question_id' => $this->question->id,
            'reporter_web_user_id' => $this->teacher->id,
            'reporter_role' => 'teacher',
            'report_category' => 'typographical_error',
            'status' => 'under_review',
        ]);

        $this->actingAs($admin, 'api');
        $response = $this->postJson('/api/admin/auth/question/filter', [
            'topic_id' => $this->topic->id,
            'per_page' => 10,
        ]);

        $response->assertStatus(200);
        $items = collect($response->json('questions.data'));

        $reportedItem = $items->firstWhere('id', $this->question->id);
        $cleanItem = $items->firstWhere('id', $cleanQuestion->id);

        $this->assertNotNull($reportedItem, 'Reported question must be in filter results');
        $this->assertEquals(2, $reportedItem['reports_count'], 'Reported question must have reports_count = 2');

        $this->assertNotNull($cleanItem, 'Clean question must be in filter results');
        $this->assertEquals(0, $cleanItem['reports_count'], 'Clean question must have reports_count = 0');
    }
}
