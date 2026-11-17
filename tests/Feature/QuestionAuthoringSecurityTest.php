<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Models\WebUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QuestionAuthoringSecurityTest extends TestCase
{
    private User $admin;
    private User $adminNoRole;
    private User $superAdmin;
    private WebUser $student;
    private WebUser $teacher;
    private int $testQuestionId;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'question_security_test',
            'database.connections.question_security_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('question_security_test');

        $this->createTestTables();
        $this->seedTestCurriculum();
        $this->setupUsers();
    }

    private function createTestTables(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('role_id');
            $t->unsignedBigInteger('permission_id');
        });

        Schema::create('role_permission_scopes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('role_id');
            $t->unsignedBigInteger('permission_id');
            $t->string('scope_type', 50);
            $t->unsignedBigInteger('scope_id');
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->unsignedBigInteger('role_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('web_users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->string('role')->default('student');
            $t->string('status')->default('active');
            $t->string('phone')->nullable();
            $t->string('last_token')->nullable();
            $t->unsignedBigInteger('study_session_id')->nullable();
            $t->timestamp('email_verified_at')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('teacher_profiles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('web_user_id');
            $t->string('teacher_code')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
        });

        Schema::create('curriculum_board_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });

        Schema::create('class_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('class_name');
        });

        Schema::create('subject_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('subject_name');
        });

        Schema::create('book_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('book_name');
            $t->unsignedBigInteger('class_id')->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->unsignedBigInteger('curriculum_board_id')->nullable();
        });

        Schema::create('book_unit_tbl', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('book_id');
            $t->string('unit_name');
        });

        Schema::create('book_unit_topic_tbl', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('unit_id');
            $t->string('topic_name');
            $t->string('topic_name_um')->nullable();
        });

        Schema::create('question_type_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('type_name');
        });

        Schema::create('question_presentation_type_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('type_name');
            $t->string('code')->nullable();
            $t->boolean('allows_multiple_mcqs')->default(false);
            $t->boolean('activate')->default(true);
            $t->integer('sort_order')->default(1);
        });

        Schema::create('question_scenario_groups_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('title')->nullable();
            $t->text('scenario_text')->nullable();
            $t->text('scenario_text_um')->nullable();
            $t->string('scenario_image')->nullable();
            $t->unsignedBigInteger('question_presentation_type_id')->nullable();
            $t->unsignedBigInteger('topic_id')->nullable();
            $t->unsignedBigInteger('unit_id')->nullable();
            $t->unsignedBigInteger('book_id')->nullable();
            $t->boolean('activate')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });

        Schema::create('exam_question_tbl', function (Blueprint $t) {
            $t->id();
            $t->text('question')->nullable();
            $t->text('question_um')->nullable();
            $t->string('question_lang')->nullable();
            $t->string('question_um_lang')->nullable();
            $t->unsignedBigInteger('topic_id')->nullable();
            $t->unsignedBigInteger('unit_id')->nullable();
            $t->unsignedBigInteger('book_id')->nullable();
            $t->unsignedBigInteger('question_type')->nullable();
            $t->unsignedBigInteger('cognitive_domain')->nullable();
            $t->unsignedBigInteger('topic_content')->nullable();
            $t->unsignedBigInteger('question_presentation_type_id')->nullable();
            $t->unsignedBigInteger('scenario_group_id')->nullable();
            $t->integer('scenario_question_order')->nullable();
            $t->integer('difficulty')->default(1);
            $t->decimal('marks', 8, 2)->default(1.00);
            $t->string('status')->default('draft');
            $t->boolean('activate')->default(true);
            $t->boolean('is_mcq')->default(true);
            $t->boolean('has_diagram')->default(false);
            $t->boolean('exercise_question')->default(false);
            $t->boolean('is_alp_question')->default(false);
            $t->string('source')->nullable();
            $t->text('explanation')->nullable();
            $t->text('explanation_um')->nullable();
            $t->string('explanation_video_url')->nullable();
            $t->unsignedBigInteger('ai_provider_id')->nullable();
            $t->unsignedBigInteger('ai_model_id')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });

        Schema::create('exam_question_options_tbl', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('question_id');
            $t->text('option')->nullable();
            $t->text('option_um')->nullable();
            $t->boolean('is_answer')->default(false);
            $t->string('option_lang')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });

        Schema::create('exam_answer_tbl', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('question_id');
            $t->text('answer')->nullable();
            $t->text('answer_um')->nullable();
            $t->string('answer_lang')->nullable();
            $t->string('answer_um_lang')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });

        Schema::create('exam_question_board_tbl', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('question_id');
            $t->unsignedBigInteger('board_id');
            $t->unsignedBigInteger('session_id')->nullable();
            $t->unsignedBigInteger('group_id')->nullable();
            $t->string('year')->nullable();
            $t->boolean('activate')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });

        Schema::create('board_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('board_name')->nullable();
        });

        Schema::create('exam_session_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('session_name')->nullable();
        });

        Schema::create('study_group_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
        });

        Schema::create('admin_activity_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('admin_user_id');
            $t->string('action')->nullable();
            $t->string('module')->nullable();
            $t->string('reference_type')->nullable();
            $t->unsignedBigInteger('reference_id')->nullable();
            $t->string('ip_address')->nullable();
            $t->text('user_agent')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    private function seedTestCurriculum(): void
    {
        DB::table('curriculum_board_tbl')->insert(['id' => 1, 'name' => 'Punjab Curriculum Board']);
        DB::table('class_tbl')->insert(['id' => 1, 'class_name' => 'Grade 10']);
        DB::table('subject_tbl')->insert(['id' => 1, 'subject_name' => 'Physics']);
        DB::table('book_tbl')->insert(['id' => 1, 'book_name' => 'Physics 10 PTB', 'class_id' => 1, 'subject_id' => 1, 'curriculum_board_id' => 1]);
        DB::table('book_unit_tbl')->insert(['id' => 1, 'book_id' => 1, 'unit_name' => 'Unit 10: Simple Harmonic Motion']);
        DB::table('book_unit_topic_tbl')->insert(['id' => 1, 'unit_id' => 1, 'topic_name' => '10.1 Simple Pendulum']);
        DB::table('question_type_tbl')->insert(['id' => 1, 'type_name' => 'MCQ']);
        DB::table('question_presentation_type_tbl')->insert([
            'id' => 1,
            'type_name' => 'Straight',
            'code' => 'st',
            'allows_multiple_mcqs' => 0,
            'activate' => 1,
            'sort_order' => 1,
        ]);

        $this->testQuestionId = DB::table('exam_question_tbl')->insertGetId([
            'question' => 'What is the time period formula of a simple pendulum?',
            'question_um' => 'سادہ پینڈولم کے ٹائم پیریڈ کا فارمولا کیا ہے؟',
            'topic_id' => 1,
            'unit_id' => 1,
            'book_id' => 1,
            'question_type' => 1,
            'activate' => 1,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function setupUsers(): void
    {
        // 1. Authoring Admin with explicit question permissions
        $adminRole = Role::create(['name' => 'question_author']);
        $permissions = ['questions.create', 'questions.update', 'questions.activate', 'questions.view'];
        foreach ($permissions as $pName) {
            $perm = Permission::firstOrCreate(['name' => $pName]);
            DB::table('role_permissions')->insert(['role_id' => $adminRole->id, 'permission_id' => $perm->id]);
        }
        $this->admin = User::create([
            'name' => 'Question Author Admin',
            'email' => 'admin.author@example.com',
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        // 2. Super Admin
        $superRole = Role::create(['name' => 'super_admin']);
        $this->superAdmin = User::create([
            'name' => 'Super Administrator',
            'email' => 'super.admin@example.com',
            'role_id' => $superRole->id,
            'is_active' => true,
        ]);

        // 3. Admin with NO role ID
        $this->adminNoRole = User::create([
            'name' => 'Admin Without Role',
            'email' => 'no.role@example.com',
            'role_id' => null,
            'is_active' => true,
        ]);

        // 4. Student (WebUser)
        $this->student = WebUser::create([
            'name' => 'LMS Student User',
            'email' => 'student.audit@example.com',
            'role' => 'student',
            'status' => 'active',
            'password' => Hash::make('StudentSecret123!'),
            'email_verified_at' => now(),
        ]);

        // 5. Teacher (WebUser + TeacherProfile)
        $this->teacher = WebUser::create([
            'name' => 'LMS Teacher User',
            'email' => 'teacher.audit@example.com',
            'role' => 'teacher',
            'status' => 'active',
            'password' => Hash::make('TeacherSecret123!'),
            'email_verified_at' => now(),
        ]);
        TeacherProfile::create([
            'web_user_id' => $this->teacher->id,
            'status' => 'active',
        ]);
    }

    private function asAdmin(User $user)
    {
        $token = auth('api')->login($user);
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    private function questionPayload(array $overrides = []): array
    {
        return array_merge([
            'question' => 'A sound wave travels through air at 340 m/s. What is its frequency if wavelength is 0.5m?',
            'question_um' => 'آواز کی لہر ہوا میں 340 میٹر فی سیکنڈ کی رفتار سے سفر کرتی ہے۔',
            'topic_id' => 1,
            'unit_id' => 1,
            'book_id' => 1,
            'question_type' => 1,
            'question_presentation_type_id' => 1,
            'difficulty' => 2,
            'marks' => 1,
            'options' => [
                ['text' => '680 Hz', 'text_um' => '680 ہرٹز', 'is_correct' => 1],
                ['text' => '170 Hz', 'text_um' => '170 ہرٹز', 'is_correct' => 0],
                ['text' => '340 Hz', 'text_um' => '340 ہرٹز', 'is_correct' => 0],
                ['text' => '510 Hz', 'text_um' => '510 ہرٹز', 'is_correct' => 0],
            ],
        ], $overrides);
    }

    /**
     * Requirement: Administrator is ALLOWED for Create, Update, Activate, Delete.
     */
    public function test_administrator_allowed_for_all_authoring_operations(): void
    {
        // 1. Create Question
        $createRes = $this->asAdmin($this->admin)
            ->postJson('/api/admin/auth/question/save', $this->questionPayload());
        $createRes->assertOk();
        $this->assertDatabaseHas('exam_question_tbl', ['question' => 'A sound wave travels through air at 340 m/s. What is its frequency if wavelength is 0.5m?']);

        // 2. Update Question
        $updateRes = $this->asAdmin($this->admin)
            ->postJson('/api/admin/auth/question/update', [
                'id' => $this->testQuestionId,
                'question' => 'Updated: What is the time period formula of a simple pendulum in seconds?',
                'topic_id' => 1,
                'unit_id' => 1,
                'book_id' => 1,
            ]);
        $updateRes->assertOk();
        $this->assertDatabaseHas('exam_question_tbl', [
            'id' => $this->testQuestionId,
            'question' => 'Updated: What is the time period formula of a simple pendulum in seconds?',
        ]);

        // 3. Activate/Deactivate Question
        $activateRes = $this->asAdmin($this->admin)
            ->postJson('/api/admin/auth/question/activate', [
                'id' => $this->testQuestionId,
                'activate' => 0,
            ]);
        $activateRes->assertOk();
        $this->assertDatabaseHas('exam_question_tbl', [
            'id' => $this->testQuestionId,
            'activate' => 0,
        ]);

        // 4. Delete Question (Admin)
        $deleteRes = $this->asAdmin($this->admin)
            ->deleteJson("/api/admin/auth/question/delete/{$this->testQuestionId}");
        $deleteRes->assertOk();
        $this->assertDatabaseHas('exam_question_tbl', [
            'id' => $this->testQuestionId,
            'activate' => 0,
        ]);
    }

    /**
     * Requirement: Student is strictly DENIED for Create, Update, Activate, Delete.
     */
    public function test_student_strictly_denied_for_all_authoring_operations(): void
    {
        // 1. Create Question
        $this->actingAs($this->student, 'web_api')
            ->postJson('/api/admin/auth/question/save', $this->questionPayload())
            ->assertStatus(401);

        // 2. Update Question
        $this->actingAs($this->student, 'web_api')
            ->postJson('/api/admin/auth/question/update', [
                'id' => $this->testQuestionId,
                'question' => 'Hacked by student',
            ])
            ->assertStatus(401);

        // 3. Activate Question
        $this->actingAs($this->student, 'web_api')
            ->postJson('/api/admin/auth/question/activate', [
                'id' => $this->testQuestionId,
                'activate' => 0,
            ])
            ->assertStatus(401);

        // 4. Delete Question
        $this->actingAs($this->student, 'web_api')
            ->deleteJson("/api/admin/auth/question/delete/{$this->testQuestionId}")
            ->assertStatus(401);
    }

    /**
     * Requirement: Teacher is strictly DENIED for Create, Update, Activate, Delete.
     */
    public function test_teacher_strictly_denied_for_all_authoring_operations(): void
    {
        // 1. Create Question
        $this->actingAs($this->teacher, 'web_api')
            ->postJson('/api/admin/auth/question/save', $this->questionPayload())
            ->assertStatus(401);

        // 2. Update Question
        $this->actingAs($this->teacher, 'web_api')
            ->postJson('/api/admin/auth/question/update', [
                'id' => $this->testQuestionId,
                'question' => 'Hacked by teacher',
            ])
            ->assertStatus(401);

        // 3. Activate Question
        $this->actingAs($this->teacher, 'web_api')
            ->postJson('/api/admin/auth/question/activate', [
                'id' => $this->testQuestionId,
                'activate' => 0,
            ])
            ->assertStatus(401);

        // 4. Delete Question
        $this->actingAs($this->teacher, 'web_api')
            ->deleteJson("/api/admin/auth/question/delete/{$this->testQuestionId}")
            ->assertStatus(401);
    }

    /**
     * Requirement: Unauthenticated caller is strictly DENIED for Create, Update, Activate, Delete.
     */
    public function test_unauthenticated_caller_strictly_denied_for_all_operations(): void
    {
        // 1. Create Question
        $this->postJson('/api/admin/auth/question/save', $this->questionPayload())
            ->assertStatus(401);

        // 2. Update Question
        $this->postJson('/api/admin/auth/question/update', [
            'id' => $this->testQuestionId,
            'question' => 'Unauthenticated modification',
        ])->assertStatus(401);

        // 3. Activate Question
        $this->postJson('/api/admin/auth/question/activate', [
            'id' => $this->testQuestionId,
            'activate' => 0,
        ])->assertStatus(401);

        // 4. Delete Question
        $this->deleteJson("/api/admin/auth/question/delete/{$this->testQuestionId}")
            ->assertStatus(401);
    }

    /**
     * Requirement: Missing role ID on User must NEVER grant permission (returns 403 Forbidden).
     */
    public function test_missing_role_id_never_grants_authoring_permission(): void
    {
        $this->asAdmin($this->adminNoRole)
            ->postJson('/api/admin/auth/question/save', $this->questionPayload())
            ->assertStatus(403);

        $this->asAdmin($this->adminNoRole)
            ->postJson('/api/admin/auth/question/update', [
                'id' => $this->testQuestionId,
                'question' => 'Unauthorized update',
                'topic_id' => 1,
            ])
            ->assertStatus(403);

        $this->asAdmin($this->adminNoRole)
            ->postJson('/api/admin/auth/question/activate', [
                'id' => $this->testQuestionId,
                'activate' => 0,
            ])
            ->assertStatus(403);

        $this->asAdmin($this->adminNoRole)
            ->deleteJson("/api/admin/auth/question/delete/{$this->testQuestionId}")
            ->assertStatus(403);
    }

    /**
     * Requirement: Forged or client-supplied role claims by student/teacher are rejected.
     */
    public function test_forged_role_claims_are_rejected(): void
    {
        $forgedPayload = array_merge($this->questionPayload(), [
            'role' => 'admin',
            'role_id' => 1,
            'is_admin' => true,
            'role_name' => 'super_admin',
        ]);

        $this->actingAs($this->student, 'web_api')
            ->postJson('/api/admin/auth/question/save', $forgedPayload)
            ->assertStatus(401);

        $this->actingAs($this->teacher, 'web_api')
            ->postJson('/api/admin/auth/question/save', $forgedPayload)
            ->assertStatus(401);
    }

    /**
     * Requirement: Direct endpoint access to removed /web_api authoring routes returns 404.
     */
    public function test_direct_access_to_removed_web_api_routes_returns_404(): void
    {
        // Even authenticated students calling removed routes must receive 404 Not Found
        $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/save_question', $this->questionPayload())
            ->assertStatus(404);

        $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/update_question', ['id' => $this->testQuestionId])
            ->assertStatus(404);

        $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/activate_question', ['id' => $this->testQuestionId, 'activate' => 0])
            ->assertStatus(404);

        $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/save_test', ['question' => 'Direct injection test'])
            ->assertStatus(404);

        $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/save_question_scenario_group', ['scenario_text' => 'Injected scenario'])
            ->assertStatus(404);
    }

    /**
     * Requirement: Alternative routes (e.g. legacy /api/save_question) are protected by admin permission.
     */
    public function test_alternative_routes_are_denied_for_students_and_unauthenticated(): void
    {
        // 1. Student hitting legacy route receives 401 or 403
        $this->actingAs($this->student, 'web_api')
            ->postJson('/api/save_question', $this->questionPayload())
            ->assertStatus(401);

        // 2. Unauthenticated hitting legacy route receives 401
        $this->postJson('/api/save_question', $this->questionPayload())
            ->assertStatus(401);

        // 3. Admin WITH permission calling legacy route is allowed
        $res = $this->asAdmin($this->admin)
            ->postJson('/api/save_question', $this->questionPayload(['question' => 'Created via legacy admin route']));
        $res->assertOk();
    }

    /**
     * Requirement: Super Administrator retains full authoring capability without explicit scopes.
     */
    public function test_super_admin_has_full_authoring_access(): void
    {
        $res = $this->asAdmin($this->superAdmin)
            ->postJson('/api/admin/auth/question/save', $this->questionPayload(['question' => 'Super Admin Created Question']));
        $res->assertOk();
        $this->assertDatabaseHas('exam_question_tbl', ['question' => 'Super Admin Created Question']);
    }

    /**
     * Requirement: CheckPermission middleware directly rejects WebUser, unauthenticated callers,
     * and users without permission while allowing authentic admins with permission.
     */
    public function test_check_permission_middleware_behavior(): void
    {
        $middleware = new \App\Http\Middleware\CheckPermission();

        // 1. Unauthenticated request -> 401
        $unauthRequest = \Illuminate\Http\Request::create('/api/admin/test', 'POST');
        $response = $middleware->handle($unauthRequest, fn () => response()->json(['ok' => true]), 'questions.create');
        $this->assertEquals(401, $response->getStatusCode());

        // 2. WebUser (student/teacher) authenticated request -> 403 Forbidden
        $webUserRequest = \Illuminate\Http\Request::create('/api/admin/test', 'POST');
        $webUserRequest->setUserResolver(fn () => $this->teacher);
        $response = $middleware->handle($webUserRequest, fn () => response()->json(['ok' => true]), 'questions.create');
        $this->assertEquals(403, $response->getStatusCode());

        // 3. Admin User without role -> 403 Forbidden
        $adminNoRoleRequest = \Illuminate\Http\Request::create('/api/admin/test', 'POST');
        $adminNoRoleRequest->setUserResolver(fn () => $this->adminNoRole);
        $response = $middleware->handle($adminNoRoleRequest, fn () => response()->json(['ok' => true]), 'questions.create');
        $this->assertEquals(403, $response->getStatusCode());

        // 4. Admin User with role lacking the required permission -> 403 Forbidden
        $unauthorizedAdmin = User::create([
            'name' => 'Restricted Admin',
            'email' => 'restricted@admin.com',
            'role_id' => Role::create(['name' => 'guest_editor'])->id,
            'is_active' => true,
        ]);
        $unauthAdminRequest = \Illuminate\Http\Request::create('/api/admin/test', 'POST');
        $unauthAdminRequest->setUserResolver(fn () => $unauthorizedAdmin);
        $response = $middleware->handle($unauthAdminRequest, fn () => response()->json(['ok' => true]), 'questions.create');
        $this->assertEquals(403, $response->getStatusCode());

        // 5. Admin User with role containing the required permission -> 200 OK
        $authAdminRequest = \Illuminate\Http\Request::create('/api/admin/test', 'POST');
        $authAdminRequest->setUserResolver(fn () => $this->admin);
        $response = $middleware->handle($authAdminRequest, fn () => response()->json(['ok' => true]), 'questions.create');
        $this->assertEquals(200, $response->getStatusCode());

        // 6. Super Admin bypasses specific permission check -> 200 OK
        $superAdminRequest = \Illuminate\Http\Request::create('/api/admin/test', 'POST');
        $superAdminRequest->setUserResolver(fn () => $this->superAdmin);
        $response = $middleware->handle($superAdminRequest, fn () => response()->json(['ok' => true]), 'any.arbitrary.permission');
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Requirement: LmsPermissionsSeeder uses syncWithoutDetaching and preserves permissions
     * seeded by other seeders (e.g. QuestionReportPermissionsSeeder, TeacherSubscriptionAdminPermissionsSeeder).
     */
    public function test_lms_permissions_seeder_preserves_external_permissions(): void
    {
        $superAdminRole = Role::firstOrCreate(['name' => 'super_admin']);
        $externalPerm1 = Permission::firstOrCreate(['name' => 'question_reports.view']);
        $externalPerm2 = Permission::firstOrCreate(['name' => 'teacher_subscriptions.manage']);
        $superAdminRole->permissions()->syncWithoutDetaching([$externalPerm1->id, $externalPerm2->id]);

        $this->assertTrue($superAdminRole->fresh()->permissions->contains('name', 'question_reports.view'));
        $this->assertTrue($superAdminRole->fresh()->permissions->contains('name', 'teacher_subscriptions.manage'));

        // Run LmsPermissionsSeeder
        $seeder = new \Database\Seeders\LmsPermissionsSeeder();
        $seeder->run();

        // Verify that the external permissions were preserved and not detached
        $freshPermissions = $superAdminRole->fresh()->permissions;
        $this->assertTrue($freshPermissions->contains('name', 'question_reports.view'), 'Question report permission was lost!');
        $this->assertTrue($freshPermissions->contains('name', 'teacher_subscriptions.manage'), 'Teacher subscription permission was lost!');
        $this->assertTrue($freshPermissions->contains('name', 'questions.create'), 'Core LMS permission was not attached!');
    }

    /**
     * Requirement: Decoupling verification - Question authoring operations operate cleanly
     * when ai_providers, ai_models, slo_topic_tbl, and question_reports are absent.
     */
    public function test_authoring_operations_work_seamlessly_without_ai_or_slo_tables(): void
    {
        $this->assertFalse(Schema::hasTable('ai_providers'));
        $this->assertFalse(Schema::hasTable('ai_models'));
        $this->assertFalse(Schema::hasTable('slo_topic_tbl'));
        $this->assertFalse(Schema::hasTable('question_reports'));

        // 1. getQuestionDataById works cleanly
        $res = $this->asAdmin($this->admin)
            ->postJson('/api/admin/auth/question/detail', ['id' => $this->testQuestionId]);
        $res->assertOk();
        $res->assertJsonPath('success', true);
        $res->assertJsonPath('question_data.0.ai_provider_name', null);

        // 2. getAllQuestions / questionFilterResponse works cleanly
        $res = $this->asAdmin($this->admin)
            ->postJson('/api/admin/auth/question/filter', []);
        $res->assertOk();

        // 3. Scenario group detail works cleanly
        $scenarioGroupId = DB::table('question_scenario_groups_tbl')->insertGetId([
            'title' => 'Test Scenario Group',
            'scenario_text' => 'Passage text',
            'question_presentation_type_id' => 1,
            'topic_id' => 1,
            'unit_id' => 1,
            'book_id' => 1,
            'activate' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $res = $this->asAdmin($this->admin)
            ->postJson('/api/admin/auth/question/scenario-groups/detail', ['id' => $scenarioGroupId]);
        $res->assertOk();
        $res->assertJsonPath('success', 1);

        // 4. Update question works cleanly
        $res = $this->asAdmin($this->admin)
            ->postJson('/api/admin/auth/question/update', [
                'id' => $this->testQuestionId,
                'question' => 'Updated Decoupled Question Text',
                'topic_id' => 1,
                'unit_id' => 1,
                'book_id' => 1,
                'question_type' => 1,
                'is_mcq' => 1,
                'question_presentation_type_id' => 1,
                'marks' => 1.0,
                'difficulty' => 2,
            ]);
        $res->assertOk();
        $this->assertDatabaseHas('exam_question_tbl', [
            'id' => $this->testQuestionId,
            'question' => 'Updated Decoupled Question Text',
        ]);
    }
}
