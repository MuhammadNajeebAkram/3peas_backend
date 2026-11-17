<?php

namespace Tests\Feature;

use App\Http\Controllers\QuestionsController;
use App\Http\Services\TeacherMcqTestService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TeacherClass;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Models\WebUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TeacherMcqAutoSelectionTest extends TestCase
{
    private TeacherMcqTestService $service;
    private TeacherClass $teacherClass;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Isolate test connection to in-memory SQLite
        config([
            'database.default' => 'teacher_mcq_auto_test',
            'database.connections.teacher_mcq_auto_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('teacher_mcq_auto_test');

        // 2. Fail-closed safety check: ensure test ONLY runs against in-memory SQLite
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException(
                'Fail-closed safety check failed: TeacherMcqAutoSelectionTest must run ONLY against an isolated in-memory SQLite database!'
            );
        }

        $this->createTestTables();
        $this->seedTestFixtures();

        $this->service = app(TeacherMcqTestService::class);
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
            $t->unsignedBigInteger('role_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('web_users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('role');
            $t->string('status')->default('active');
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
            $t->boolean('activate')->default(true);
            $t->timestamps();
        });

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
            $t->integer('topic_no')->default(1);
            $t->string('topic_name');
            $t->string('topic_name_um')->nullable();
            $t->boolean('is_alp')->default(true);
            $t->boolean('activate')->default(true);
            $t->timestamps();
        });

        Schema::create('cognitive_domain_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('short')->nullable();
            $t->timestamps();
        });

        Schema::create('question_type_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('type_name');
            $t->timestamps();
        });

        Schema::create('question_presentation_type_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('type_name');
            $t->string('code')->nullable();
            $t->boolean('activate')->default(true);
            $t->boolean('allows_multiple_mcqs')->default(false);
            $t->timestamps();
        });

        Schema::create('question_scenario_groups_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('title')->nullable();
            $t->text('passage')->nullable();
            $t->text('passage_um')->nullable();
            $t->text('scenario_text')->nullable();
            $t->text('scenario_text_um')->nullable();
            $t->string('scenario_image')->nullable();
            $t->string('stimulus_type')->default('passage');
            $t->unsignedBigInteger('curriculum_board_id')->nullable();
            $t->unsignedBigInteger('class_id')->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->unsignedBigInteger('book_id')->nullable();
            $t->unsignedBigInteger('unit_id')->nullable();
            $t->unsignedBigInteger('topic_id')->nullable();
            $t->unsignedBigInteger('question_presentation_type_id')->nullable();
            $t->boolean('activate')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });

        Schema::create('exam_question_tbl', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('book_id')->nullable();
            $t->unsignedBigInteger('unit_id')->nullable();
            $t->unsignedBigInteger('topic_id');
            $t->text('question');
            $t->text('question_um')->nullable();
            $t->boolean('question_lang')->default(true);
            $t->boolean('question_um_lang')->default(false);
            $t->decimal('marks', 8, 2)->default(1.0);
            $t->integer('difficulty')->default(3);
            $t->unsignedBigInteger('cognitive_domain')->nullable();
            $t->integer('topic_content')->default(1);
            $t->unsignedBigInteger('question_type')->default(1);
            $t->boolean('is_mcq')->default(true);
            $t->boolean('is_alp_question')->default(true);
            $t->boolean('exercise_question')->default(false);
            $t->boolean('has_diagram')->default(false);
            $t->unsignedBigInteger('question_presentation_type_id')->nullable();
            $t->unsignedBigInteger('scenario_group_id')->nullable();
            $t->integer('scenario_question_order')->nullable();
            $t->unsignedBigInteger('slo_topic_id')->nullable();
            $t->text('explanation')->nullable();
            $t->text('explanation_um')->nullable();
            $t->string('explanation_video_url')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->unsignedBigInteger('ai_provider_id')->nullable();
            $t->unsignedBigInteger('ai_model_id')->nullable();
            $t->string('status')->default('published');
            $t->boolean('activate')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });

        Schema::create('slo_domain_tbl', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('curriculum_board_id')->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->boolean('activate')->default(true);
            $t->timestamps();
        });

        Schema::create('slo_topic_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('slo_code')->nullable();
            $t->string('slo_title')->nullable();
            $t->string('cognitive_level')->nullable();
            $t->unsignedBigInteger('slo_domain_id')->nullable();
            $t->unsignedBigInteger('class_id')->nullable();
            $t->boolean('activate')->default(true);
            $t->timestamps();
        });

        Schema::create('topic_slo_topic_tbl', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('topic_id');
            $t->unsignedBigInteger('slo_topic_id');
            $t->timestamps();
        });

        Schema::create('unit_slo_topic_tbl', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('unit_id');
            $t->unsignedBigInteger('slo_topic_id');
            $t->timestamps();
        });

        Schema::create('exam_question_options_tbl', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('question_id');
            $t->text('option');
            $t->text('option_um')->nullable();
            $t->boolean('is_answer')->default(false);
            $t->boolean('option_lang')->nullable()->default(true);
            $t->boolean('option_um_lang')->nullable()->default(false);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });

        Schema::create('board_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('board_name');
            $t->string('short_name')->nullable();
            $t->timestamps();
        });

        Schema::create('exam_session_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('session_name');
            $t->timestamps();
        });

        Schema::create('study_group_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('exam_question_board_tbl', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('question_id');
            $t->unsignedBigInteger('board_id');
            $t->unsignedBigInteger('session_id')->nullable();
            $t->unsignedBigInteger('group_id')->nullable();
            $t->integer('year')->nullable();
            $t->boolean('activate')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('teacher_classes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('teacher_profile_id');
            $t->unsignedBigInteger('curriculum_board_id');
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('subject_id');
            $t->string('name');
            $t->string('section')->nullable();
            $t->string('class_code');
            $t->boolean('is_active')->default(true);
            $t->boolean('requires_approval')->default(true);
            $t->timestamps();
        });
    }

    private function seedTestFixtures(): void
    {
        // 1. Roles & Permissions for Question Authoring Tests
        $superAdminRole = Role::create(['name' => 'super_admin']);
        $perm = Permission::create(['name' => 'questions.create']);
        DB::table('role_permissions')->insert([
            'role_id' => $superAdminRole->id,
            'permission_id' => $perm->id,
        ]);

        $this->adminUser = User::create([
            'name' => 'Admin User',
            'email' => 'admin@alfaraabi.com',
            'role_id' => $superAdminRole->id,
            'is_active' => true,
        ]);

        // 2. Curriculum chain: PTB (1), Grade 10 (1), Physics (1)
        DB::table('curriculum_board_tbl')->insert([
            'id' => 1,
            'name' => 'Punjab Curriculum and Textbook Board',
            'activate' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('class_tbl')->insert([
            'id' => 1,
            'class_name' => 'Grade 10',
            'activate' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('subject_tbl')->insert([
            'id' => 1,
            'subject_name' => 'Physics',
            'activate' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('book_tbl')->insert([
            'id' => 1,
            'book_name' => 'PTB Physics 10',
            'curriculum_board_id' => 1,
            'class_id' => 1,
            'subject_id' => 1,
            'activate' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('book_unit_tbl')->insert([
            'id' => 1,
            'book_id' => 1,
            'unit_no' => 1,
            'unit_name' => 'Geometrical Optics',
            'activate' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('book_unit_topic_tbl')->insert([
            'id' => 1,
            'unit_id' => 1,
            'topic_no' => 1,
            'topic_name' => 'Reflection of Light',
            'is_alp' => 1,
            'activate' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Question types & Presentation types
        DB::table('question_type_tbl')->insert([
            ['id' => 1, 'type_name' => 'MCQ', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'type_name' => 'Subjective', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('question_presentation_type_tbl')->insert([
            ['id' => 1, 'type_name' => 'Straight', 'code' => 'st', 'activate' => 1, 'allows_multiple_mcqs' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'type_name' => 'Stimulus', 'code' => 'stimulus', 'activate' => 1, 'allows_multiple_mcqs' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'type_name' => 'Inactive Type', 'code' => 'inactive', 'activate' => 0, 'allows_multiple_mcqs' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('cognitive_domain_tbl')->insert([
            ['id' => 1, 'name' => 'Knowledge', 'short' => 'K', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Understanding', 'short' => 'U', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // 4. Teacher profile & Teacher class
        $webUser = WebUser::create(['name' => 'Physics Teacher', 'email' => 'teacher@example.com', 'role' => 'teacher']);
        $profile = TeacherProfile::create(['web_user_id' => $webUser->id, 'status' => 'active']);
        $this->teacherClass = new TeacherClass([
            'curriculum_board_id' => 1,
            'class_id' => 1,
            'subject_id' => 1,
            'name' => '10th Physics Section A',
            'is_active' => true,
        ]);
        $this->teacherClass->teacher_profile_id = $profile->id;
        $this->teacherClass->class_code = 'PHYS10A';
        $this->teacherClass->save();

        // 5. Scenario Groups
        // Group 1: 6 MCQs (> 4 questions)
        DB::table('question_scenario_groups_tbl')->insert([
            'id' => 1,
            'title' => 'Optics Experiment Scenario',
            'passage' => '<p>A student observes reflection and refraction of light passing through glass prisms.</p>',
            'scenario_text' => '<p>A student observes reflection and refraction of light passing through glass prisms.</p>',
            'activate' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        for ($i = 1; $i <= 6; $i++) {
            $qid = 100 + $i;
            DB::table('exam_question_tbl')->insert([
                'id' => $qid,
                'book_id' => 1,
                'unit_id' => 1,
                'topic_id' => 1,
                'question' => "Optics question {$i} on angle of incidence",
                'is_mcq' => 1,
                'question_type' => 1,
                'question_presentation_type_id' => 2,
                'scenario_group_id' => 1,
                'scenario_question_order' => $i,
                'activate' => 1,
                'status' => 'published',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->seedOptionsForQuestion($qid);
        }

        // Group 2: Exactly 4 MCQs
        DB::table('question_scenario_groups_tbl')->insert([
            'id' => 2,
            'title' => 'Mechanics Pulley Scenario',
            'passage' => '<p>A system of movable pulleys lifting heavy loads in a workshop.</p>',
            'scenario_text' => '<p>A system of movable pulleys lifting heavy loads in a workshop.</p>',
            'activate' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        for ($i = 1; $i <= 4; $i++) {
            $qid = 200 + $i;
            DB::table('exam_question_tbl')->insert([
                'id' => $qid,
                'book_id' => 1,
                'unit_id' => 1,
                'topic_id' => 1,
                'question' => "Pulley question {$i} on mechanical advantage",
                'is_mcq' => 1,
                'question_type' => 1,
                'question_presentation_type_id' => 2,
                'scenario_group_id' => 2,
                'scenario_question_order' => $i,
                'activate' => 1,
                'status' => 'published',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->seedOptionsForQuestion($qid);
        }

        // Group 3: 2 MCQs (< 4 questions)
        DB::table('question_scenario_groups_tbl')->insert([
            'id' => 3,
            'title' => 'Thermal Expansion Scenario',
            'passage' => '<p>Bimetallic strip bending when heated due to differing coefficients of thermal expansion.</p>',
            'scenario_text' => '<p>Bimetallic strip bending when heated due to differing coefficients of thermal expansion.</p>',
            'activate' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        for ($i = 1; $i <= 2; $i++) {
            $qid = 300 + $i;
            DB::table('exam_question_tbl')->insert([
                'id' => $qid,
                'book_id' => 1,
                'unit_id' => 1,
                'topic_id' => 1,
                'question' => "Thermal question {$i} on bimetallic curvature",
                'is_mcq' => 1,
                'question_type' => 1,
                'question_presentation_type_id' => 2,
                'scenario_group_id' => 3,
                'scenario_question_order' => $i,
                'activate' => 1,
                'status' => 'published',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->seedOptionsForQuestion($qid);
        }

        // Group 4: Legacy Inferred record with NULL presentation type
        DB::table('question_scenario_groups_tbl')->insert([
            'id' => 4,
            'title' => 'Legacy Inferred Group',
            'passage' => '<p>Legacy group whose question has NULL presentation type.</p>',
            'scenario_text' => '<p>Legacy group whose question has NULL presentation type.</p>',
            'activate' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('exam_question_tbl')->insert([
            'id' => 401,
            'book_id' => 1,
            'unit_id' => 1,
            'topic_id' => 1,
            'question' => 'Legacy question with scenario group but NULL presentation type',
            'is_mcq' => 1,
            'question_type' => 1,
            'question_presentation_type_id' => null, // NULL
            'scenario_group_id' => 4,
            'scenario_question_order' => 1,
            'activate' => 1,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedOptionsForQuestion(401);

        // 6. Standalone Straight MCQs with NULL presentation type (Legacy records)
        for ($i = 1; $i <= 3; $i++) {
            $qid = 500 + $i;
            DB::table('exam_question_tbl')->insert([
                'id' => $qid,
                'book_id' => 1,
                'unit_id' => 1,
                'topic_id' => 1,
                'question' => "Legacy standalone MCQ {$i} with NULL presentation type",
                'is_mcq' => 1,
                'question_type' => 1,
                'question_presentation_type_id' => null, // NULL
                'scenario_group_id' => null,
                'activate' => 1,
                'status' => 'published',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->seedOptionsForQuestion($qid);
        }

        // 7. Standalone Straight MCQs with Explicit Straight presentation type (ID: 1)
        for ($i = 1; $i <= 3; $i++) {
            $qid = 600 + $i;
            DB::table('exam_question_tbl')->insert([
                'id' => $qid,
                'book_id' => 1,
                'unit_id' => 1,
                'topic_id' => 1,
                'question' => "Explicit straight MCQ {$i} with presentation type 1",
                'is_mcq' => 1,
                'question_type' => 1,
                'question_presentation_type_id' => 1,
                'scenario_group_id' => null,
                'activate' => 1,
                'status' => 'published',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->seedOptionsForQuestion($qid);
        }
    }

    private function seedOptionsForQuestion(int $questionId): void
    {
        DB::table('exam_question_options_tbl')->insert([
            [
                'question_id' => $questionId,
                'option' => '<p>Option A (Correct)</p>',
                'is_answer' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'question_id' => $questionId,
                'option' => '<p>Option B (Incorrect)</p>',
                'is_answer' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Regression Test 1:
     * Legacy records with question_presentation_type_id = NULL MUST be treated as Straight MCQs
     * and included in Straight MCQ auto-selection results.
     */
    public function test_01_legacy_null_presentation_type_records_in_straight_mcq_results(): void
    {
        $result = $this->service->autoSelect($this->teacherClass, [
            'question_count' => 6,
            'mcq_format' => '1',
        ]);

        $this->assertTrue($result['success']);
        $returnedIds = collect($result['data'])->pluck('id')->all();

        // Must include legacy NULL records (501..503 or 401)
        $hasNullPresRecord = DB::table('exam_question_tbl')
            ->whereIn('id', $returnedIds)
            ->whereNull('question_presentation_type_id')
            ->exists();

        $this->assertTrue($hasNullPresRecord, 'Straight MCQ results must include legacy records with NULL presentation type.');
        $this->assertEquals(6, $result['selection_summary']['standalone_count']);
        $this->assertEquals(0, $result['selection_summary']['stimulus_group_count']);
    }

    /**
     * Regression Test 2:
     * Explicit Straight MCQs (presentation_type_id = 1) must be included in Straight results.
     */
    public function test_02_explicit_straight_mcqs_in_straight_results(): void
    {
        $result = $this->service->autoSelect($this->teacherClass, [
            'question_count' => 3,
            'mcq_format' => '1',
            'exclude_question_ids' => [501, 502, 503, 401],
        ]);

        $this->assertTrue($result['success']);
        $returnedIds = collect($result['data'])->pluck('id')->all();

        $this->assertEquals([601, 602, 603], $returnedIds);
        foreach ($result['data'] as $q) {
            $this->assertNotNull($q['presentation_type']);
            $this->assertEquals(1, $q['presentation_type']['id']);
        }
    }

    /**
     * Regression Test 3:
     * NULL presentation type records must NEVER appear in Stimulus MCQ results,
     * even if they possess a scenario_group_id (legacy content-based inference prohibited).
     */
    public function test_03_null_presentation_type_not_in_stimulus_results(): void
    {
        $result = $this->service->autoSelect($this->teacherClass, [
            'question_count' => 4,
            'mcq_format' => '2',
        ]);

        $this->assertTrue($result['success']);
        $returnedIds = collect($result['data'])->pluck('id')->all();

        // Neither standalone NULL records (501..503) nor scenario NULL record (401) can appear
        $this->assertNotContains(401, $returnedIds, 'Legacy record with scenario_group_id but NULL presentation type must not be in stimulus results.');
        $this->assertNotContains(501, $returnedIds);
        $this->assertNotContains(502, $returnedIds);
        $this->assertNotContains(503, $returnedIds);

        foreach ($result['data'] as $q) {
            $this->assertNotNull($q['presentation_type']);
            $this->assertEquals(2, $q['presentation_type']['id']);
        }
    }

    /**
     * Regression Test 4:
     * Explicit Stimulus MCQs (presentation_type_id = 2) must be returned in Stimulus results.
     */
    public function test_04_explicit_stimulus_mcqs_in_stimulus_results(): void
    {
        $result = $this->service->autoSelect($this->teacherClass, [
            'question_count' => 4,
            'mcq_format' => '2',
            'search' => 'Mechanics',
        ]);

        $this->assertTrue($result['success']);
        $this->assertCount(4, $result['data']);

        foreach ($result['data'] as $q) {
            $this->assertNotNull($q['presentation_type']);
            $this->assertEquals(2, $q['presentation_type']['id']);
            $this->assertNotNull($q['scenario_group']);
            $this->assertEquals(2, $q['scenario_group']['id']);
        }
    }

    /**
     * Regression Test 5:
     * Selected Stimulus questions must belong to the same scenario group.
     * Verifies that when candidate groups exist, exactly one group is selected and
     * all four questions belong strictly and exclusively to that chosen group without mixing.
     */
    public function test_05_four_selected_stimulus_questions_belong_to_same_scenario_group(): void
    {
        $result = $this->service->autoSelect($this->teacherClass, [
            'question_count' => 4,
            'mcq_format' => '2',
            'search' => 'Optics',
        ]);

        $this->assertTrue($result['success']);
        $this->assertCount(4, $result['data'], 'Exactly four stimulus questions must be selected.');

        $scenarioGroupIds = collect($result['data'])->pluck('scenario_group.id')->unique()->values()->all();
        $this->assertCount(1, $scenarioGroupIds, 'Exactly one distinct scenario group must be represented.');
        $selectedGroupId = $scenarioGroupIds[0];
        $this->assertNotNull($selectedGroupId, 'Selected questions must have an active scenario group ID.');
        $this->assertContains($selectedGroupId, [1, 2], 'Selected group must be one of the eligible candidate groups matching the search.');

        foreach ($result['data'] as $q) {
            $this->assertNotNull($q['scenario_group'], 'Every question must carry its scenario group payload.');
            $this->assertEquals($selectedGroupId, $q['scenario_group']['id'], 'All questions must belong strictly to the same scenario group.');
            $this->assertNotNull($q['presentation_type'], 'Every question must carry presentation type metadata.');
            $this->assertEquals(2, $q['presentation_type']['id'], 'Every question must be an explicit Stimulus MCQ.');
        }
    }

    /**
     * Regression Test 6:
     * Questions from different scenario groups must never be mixed within a stimulus selection unit.
     */
    public function test_06_questions_from_different_scenario_groups_never_mixed(): void
    {
        $result = $this->service->autoSelect($this->teacherClass, [
            'question_count' => 4,
            'mcq_format' => '2',
        ]);

        $this->assertTrue($result['success']);
        // Each unit selected must only contain questions belonging strictly to its group
        $groupsInResult = collect($result['data'])->groupBy('scenario_group.id');
        foreach ($groupsInResult as $groupId => $questions) {
            $this->assertNotNull($groupId);
            $this->assertGreaterThan(0, $questions->count());
            foreach ($questions as $q) {
                $this->assertEquals($groupId, $q['scenario_group']['id']);
            }
        }
    }

    /**
     * Regression Test 7:
     * Scenario title and passage searches must match stimulus MCQs correctly.
     */
    public function test_07_scenario_title_and_passage_searches_work(): void
    {
        // 1. Search by title
        $titleResult = $this->service->autoSelect($this->teacherClass, [
            'question_count' => 4,
            'search' => 'Optics Experiment',
        ]);
        $this->assertTrue($titleResult['success']);
        $titleIds = collect($titleResult['data'])->pluck('scenario_group.id')->unique()->all();
        $this->assertEquals([1], $titleIds);

        // 2. Search by passage content
        $passageResult = $this->service->autoSelect($this->teacherClass, [
            'question_count' => 2,
            'search' => 'bimetallic strip',
        ]);
        $this->assertTrue($passageResult['success']);
        $passageIds = collect($passageResult['data'])->pluck('scenario_group.id')->unique()->all();
        $this->assertEquals([3], $passageIds);
    }

    /**
     * Regression Test 8:
     * Excluded IDs must never be returned in auto-selection results.
     */
    public function test_08_excluded_ids_not_returned(): void
    {
        $excluded = [601, 602, 501];
        $result = $this->service->autoSelect($this->teacherClass, [
            'question_count' => 2,
            'mcq_format' => '1',
            'exclude_question_ids' => $excluded,
        ]);

        $this->assertTrue($result['success']);
        $returnedIds = collect($result['data'])->pluck('id')->all();

        foreach ($excluded as $id) {
            $this->assertNotContains($id, $returnedIds, "Excluded question {$id} was unexpectedly returned.");
        }
    }

    /**
     * Regression Test 9:
     * Insufficient-pool errors must throw HttpResponseException returning HTTP 422 with useful message.
     */
    public function test_09_insufficient_pool_errors_return_422_with_useful_message(): void
    {
        try {
            $this->service->autoSelect($this->teacherClass, [
                'question_count' => 50, // Pool only has ~18 questions
                'mcq_format' => '1',
            ]);
            $this->fail('Expected HttpResponseException was not thrown for insufficient question pool.');
        } catch (HttpResponseException $e) {
            $response = $e->getResponse();
            $this->assertEquals(422, $response->getStatusCode());
            $data = json_decode($response->getContent(), true);
            $this->assertFalse($data['success']);
            $this->assertEquals('insufficient_matching_mcqs', $data['code']);
            $this->assertArrayHasKey('available_count', $data['data']);
            $this->assertArrayHasKey('requested_count', $data['data']);
        }
    }

    /**
     * Regression Test 10:
     * When a scenario group contains more than 4 MCQs (>4), exactly 4 are selected.
     */
    public function test_10_more_than_four_questions_in_group_produces_four(): void
    {
        // Group 1 has 6 questions (101..106)
        $result = $this->service->autoSelect($this->teacherClass, [
            'question_count' => 4,
            'search' => 'prisms',
        ]);

        $this->assertTrue($result['success']);
        $this->assertCount(4, $result['data']);

        $returnedOrders = collect($result['data'])->pluck('scenario_question_order')->all();
        $this->assertCount(4, array_unique($returnedOrders), 'Selected questions must be unique.');

        // Natural order must be strictly preserved
        $sortedOrders = $returnedOrders;
        sort($sortedOrders);
        $this->assertEquals($sortedOrders, $returnedOrders, 'Selected scenario questions must preserve natural ordering.');
    }

    /**
     * Regression Test 11:
     * When a scenario group contains exactly 4 MCQs, all 4 are retained.
     */
    public function test_11_exactly_four_questions_in_group_retains_four(): void
    {
        // Group 2 has exactly 4 questions (201..204)
        $result = $this->service->autoSelect($this->teacherClass, [
            'question_count' => 4,
            'search' => 'Pulley',
        ]);

        $this->assertTrue($result['success']);
        $this->assertCount(4, $result['data']);
        $returnedIds = collect($result['data'])->pluck('id')->all();
        $this->assertEquals([201, 202, 203, 204], $returnedIds);
    }

    /**
     * Regression Test 12:
     * When a scenario group contains fewer than 4 MCQs (<4), all available questions are retained.
     */
    public function test_12_less_than_four_questions_in_group_retains_available(): void
    {
        // Group 3 has 2 questions (301, 302)
        $result = $this->service->autoSelect($this->teacherClass, [
            'question_count' => 2,
            'search' => 'Thermal',
        ]);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['data']);
        $returnedIds = collect($result['data'])->pluck('id')->all();
        $this->assertEquals([301, 302], $returnedIds);
    }

    /**
     * Regression Test 13:
     * Creating a new MCQ without a presentation type is rejected by backend validation (compulsory enforcement).
     */
    public function test_13_creating_new_mcq_without_presentation_type_rejected_by_backend_validation(): void
    {
        $controller = new QuestionsController();
        $request = Request::create('/api/admin/auth/question/save', 'POST', [
            'topic_id' => 1,
            'unit_id' => 1,
            'book_id' => 1,
            'is_mcq' => 1,
            'question' => 'New MCQ without presentation type',
            'options' => [
                ['text' => 'Option 1', 'text_um' => '', 'is_correct' => 1],
                ['text' => 'Option 2', 'text_um' => '', 'is_correct' => 0],
            ],
            // question_presentation_type_id is intentionally omitted
        ]);
        $request->setUserResolver(fn () => $this->adminUser);

        $response = $controller->saveQuestion($request);
        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals(0, $data['success']);
        $this->assertStringContainsString('Question presentation type is required for MCQs', $data['message']);
    }

    /**
     * Regression Test 14:
     * Creating a new MCQ with a valid presentation type succeeds.
     */
    public function test_14_creating_new_mcq_with_valid_presentation_type_succeeds(): void
    {
        $controller = new QuestionsController();
        $request = Request::create('/api/admin/auth/question/save', 'POST', [
            'topic_id' => 1,
            'unit_id' => 1,
            'book_id' => 1,
            'is_mcq' => 1,
            'question_type' => 1,
            'option_lang' => 1,
            'question_presentation_type_id' => 1, // Valid Straight type
            'question' => 'Valid new straight MCQ statement',
            'options' => [
                ['text' => 'Alpha', 'text_um' => '', 'is_correct' => 1],
                ['text' => 'Beta', 'text_um' => '', 'is_correct' => 0],
            ],
        ]);
        $request->setUserResolver(fn () => $this->adminUser);

        $response = $controller->saveQuestion($request);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals(1, $data['success']);

        $this->assertDatabaseHas('exam_question_tbl', [
            'question' => 'Valid new straight MCQ statement',
            'question_presentation_type_id' => 1,
        ]);
    }

    /**
     * Regression Test 15:
     * Creating a new MCQ with invalid or inactive presentation type IDs is rejected.
     */
    public function test_15_invalid_and_inactive_presentation_type_ids_rejected(): void
    {
        $controller = new QuestionsController();

        // 1. Non-existent presentation type (ID 9999)
        $nonExistentReq = Request::create('/api/admin/auth/question/save', 'POST', [
            'topic_id' => 1,
            'unit_id' => 1,
            'book_id' => 1,
            'is_mcq' => 1,
            'question_presentation_type_id' => 9999,
            'question' => 'MCQ with non-existent presentation type',
            'options' => [
                ['text' => 'A', 'text_um' => '', 'is_correct' => 1],
                ['text' => 'B', 'text_um' => '', 'is_correct' => 0],
            ],
        ]);
        $nonExistentReq->setUserResolver(fn () => $this->adminUser);

        $res1 = $controller->saveQuestion($nonExistentReq);
        $this->assertEquals(422, $res1->getStatusCode());
        $data1 = json_decode($res1->getContent(), true);
        $this->assertStringContainsString('Selected question presentation type was not found', $data1['message']);

        // 2. Inactive presentation type (ID 3 has activate = 0)
        $inactiveReq = Request::create('/api/admin/auth/question/save', 'POST', [
            'topic_id' => 1,
            'unit_id' => 1,
            'book_id' => 1,
            'is_mcq' => 1,
            'question_presentation_type_id' => 3,
            'question' => 'MCQ with inactive presentation type',
            'options' => [
                ['text' => 'A', 'text_um' => '', 'is_correct' => 1],
                ['text' => 'B', 'text_um' => '', 'is_correct' => 0],
            ],
        ]);
        $inactiveReq->setUserResolver(fn () => $this->adminUser);

        $res2 = $controller->saveQuestion($inactiveReq);
        $this->assertEquals(422, $res2->getStatusCode());
        $data2 = json_decode($res2->getContent(), true);
        $this->assertStringContainsString('Selected question presentation type is inactive', $data2['message']);
    }

    /**
     * Regression Test 16:
     * Legacy saveTest() endpoint rejects MCQ creation without presentation type.
     */
    public function test_16_legacy_save_test_without_presentation_type_rejected_for_mcqs(): void
    {
        $controller = new QuestionsController();
        $request = Request::create('/api/save_test', 'POST', [
            'question' => 'Legacy saveTest MCQ without presentation type',
            'question_type' => 1,
            'topic_id' => 1,
        ]);
        $request->setUserResolver(fn () => $this->adminUser);

        $response = $controller->saveTest($request);
        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Question presentation type is required for MCQs', $data['message']);
    }

    /**
     * Regression Test 17:
     * Legacy saveTest() endpoint rejects invalid and inactive presentation type IDs.
     */
    public function test_17_legacy_save_test_with_invalid_or_inactive_presentation_type_rejected(): void
    {
        $controller = new QuestionsController();

        // 1. Non-existent presentation type (ID 9999)
        $nonExistentReq = Request::create('/api/save_test', 'POST', [
            'question' => 'Legacy saveTest with non-existent presentation type',
            'question_type' => 1,
            'topic_id' => 1,
            'question_presentation_type_id' => 9999,
        ]);
        $nonExistentReq->setUserResolver(fn () => $this->adminUser);

        $res1 = $controller->saveTest($nonExistentReq);
        $this->assertEquals(422, $res1->getStatusCode());
        $data1 = json_decode($res1->getContent(), true);
        $this->assertFalse($data1['success']);
        $this->assertStringContainsString('Selected question presentation type was not found', $data1['message']);

        // 2. Inactive presentation type (ID 3 has activate = 0)
        $inactiveReq = Request::create('/api/save_test', 'POST', [
            'question' => 'Legacy saveTest with inactive presentation type',
            'question_type' => 1,
            'topic_id' => 1,
            'question_presentation_type_id' => 3,
        ]);
        $inactiveReq->setUserResolver(fn () => $this->adminUser);

        $res2 = $controller->saveTest($inactiveReq);
        $this->assertEquals(422, $res2->getStatusCode());
        $data2 = json_decode($res2->getContent(), true);
        $this->assertFalse($data2['success']);
        $this->assertStringContainsString('Selected question presentation type is inactive', $data2['message']);
    }

    /**
     * Regression Test 18:
     * Legacy saveTest() endpoint succeeds when valid presentation type is provided for MCQ.
     */
    public function test_18_legacy_save_test_with_valid_presentation_type_succeeds(): void
    {
        $controller = new QuestionsController();
        $request = Request::create('/api/save_test', 'POST', [
            'question' => 'Legacy saveTest valid straight MCQ statement',
            'question_type' => 1,
            'topic_id' => 1,
            'question_presentation_type_id' => 1, // Valid Straight type
        ]);
        $request->setUserResolver(fn () => $this->adminUser);

        $response = $controller->saveTest($request);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);

        $this->assertDatabaseHas('exam_question_tbl', [
            'question' => 'Legacy saveTest valid straight MCQ statement',
            'question_presentation_type_id' => 1,
        ]);
    }

    /**
     * Regression Test 19:
     * Legacy saveTest() endpoint preserves behavior for non-MCQ question types without presentation type.
     */
    public function test_19_legacy_save_test_preserves_behavior_for_non_mcq_types(): void
    {
        $controller = new QuestionsController();
        $request = Request::create('/api/save_test', 'POST', [
            'question' => 'Legacy saveTest subjective question statement',
            'question_type' => 2, // Non-MCQ / Subjective
            'topic_id' => 1,
            // question_presentation_type_id is omitted
        ]);
        $request->setUserResolver(fn () => $this->adminUser);

        $response = $controller->saveTest($request);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);

        $this->assertDatabaseHas('exam_question_tbl', [
            'question' => 'Legacy saveTest subjective question statement',
            'question_type' => 2,
            'question_presentation_type_id' => null,
        ]);
    }
}
