<?php

namespace Tests\Feature;

use App\Http\Controllers\QuestionsController;
use App\Http\Middleware\CheckPermission;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QuestionExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'question_export_test', 'database.connections.question_export_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge('question_export_test');
        $tables = [
            'exam_question_tbl' => 'question question_um topic_id unit_id book_id question_type cognitive_domain topic_content difficulty marks status reviewed_by reviewed_at explanation explanation_um explanation_video_url activate is_mcq has_diagram question_presentation_type_id scenario_group_id scenario_question_order is_alp_question created_at updated_at question_lang question_um_lang',
            'book_unit_topic_tbl' => 'unit_id topic_name topic_name_um',
            'book_unit_tbl' => 'book_id unit_name',
            'book_tbl' => 'book_name class_id subject_id curriculum_board_id',
            'curriculum_board_tbl' => 'name',
            'class_tbl' => 'class_name',
            'subject_tbl' => 'subject_name',
            'question_type_tbl' => 'type_name',
            'question_presentation_type_tbl' => 'type_name code allows_multiple_mcqs',
            'question_scenario_groups_tbl' => 'title scenario_text scenario_text_um scenario_image',
            'exam_question_board_tbl' => 'question_id board_id session_id year group_id activate',
            'board_tbl' => 'board_name',
            'exam_session_tbl' => 'session_name',
            'study_group_tbl' => 'name',
            'exam_answer_tbl' => 'question_id answer answer_um',
            'exam_question_options_tbl' => 'question_id option option_um is_answer',
            'permissions' => 'name',
            'roles' => 'name display_name created_at updated_at',
            'role_permissions' => 'role_id permission_id',
            'role_permission_scopes' => 'role_id permission_id scope_type scope_id',
            'users' => 'name email password role_id is_active created_at updated_at',
        ];
        foreach ($tables as $name => $columns) {
            Schema::create($name, function (Blueprint $table) use ($columns) {
                $table->id();
                foreach (explode(' ', $columns) as $column) {
                    $table->string($column)->nullable();
                }
            });
        }
        DB::table('curriculum_board_tbl')->insert(['id' => 1, 'name' => 'National Curriculum']);
        DB::table('class_tbl')->insert(['id' => 1, 'class_name' => 'Grade 9']);
        DB::table('subject_tbl')->insert(['id' => 1, 'subject_name' => 'Physics Subject']);
        DB::table('book_tbl')->insert(['id' => 1, 'book_name' => 'Physics', 'curriculum_board_id' => 1, 'class_id' => 1, 'subject_id' => 1]);
        DB::table('board_tbl')->insert([
            ['id' => 1, 'board_name' => 'Exam Board A'],
            ['id' => 2, 'board_name' => 'Exam Board B'],
        ]);
        DB::table('book_unit_tbl')->insert(['id' => 1, 'book_id' => 1, 'unit_name' => 'Motion']);
        DB::table('book_unit_topic_tbl')->insert(['id' => 1, 'unit_id' => 1, 'topic_name' => 'Speed']);
        foreach (range(1, 55) as $id) {
            DB::table('exam_question_tbl')->insert(['id' => $id, 'question' => 'Find speed', 'topic_id' => 1, 'activate' => 0]);
        }
        DB::table('exam_answer_tbl')->insert(['question_id' => 55, 'answer' => 'Distance / time', 'answer_um' => 'Urdu answer']);
        DB::table('exam_question_options_tbl')->insert(['question_id' => 55, 'option' => '10', 'is_answer' => 1]);
        foreach ([2024, 2025] as $year) {
            DB::table('exam_question_board_tbl')->insert(['question_id' => 55, 'board_id' => 1, 'year' => $year]);
        }
        DB::table('exam_question_board_tbl')->insert(['question_id' => 55, 'board_id' => 2, 'year' => 2023]);
    }

    public function test_export_returns_all_answers_and_uses_existing_search(): void
    {
        $controller = new QuestionsController;
        $request = Request::create('/', 'POST', ['search' => 'Motion', 'activate' => 0, 'book_id' => [1]]);
        $export = $controller->exportQuestions($request)->getData(true);
        $listing = $controller->getQuestionsByFilters($request)->getData(true);
        $this->assertSame(55, $export['total']);
        $this->assertSame($listing['questions']['total'], $export['total']);
        $this->assertSame(array_column($listing['questions']['data'], 'id'), array_slice(array_column($export['questions'], 'id'), 0, 50));
        $this->assertSame('Distance / time', $export['questions'][0]['answers'][0]['answer']);
        $this->assertEquals(1, $export['questions'][0]['options'][0]['is_correct']);
        $this->assertCount(3, $export['questions'][0]['board_links']);
        foreach (['curriculum_board' => 'National Curriculum', 'class' => 'Grade 9',
            'subject' => 'Physics Subject', 'book' => 'Physics', 'unit' => 'Motion', 'topic' => 'Speed'] as $field => $name) {
            $this->assertEquals(1, $export['questions'][0][$field . '_id']);
            $this->assertSame($name, $export['questions'][0][$field . '_name']);
        }
        $filtered = $controller->exportQuestions(Request::create('/', 'POST', ['board_id' => 1, 'year' => 2025]))->getData(true);
        $this->assertSame(1, $filtered['total']);
        $this->assertCount(3, $filtered['questions'][0]['board_links']);
        $this->assertSame(['Exam Board A', 'Exam Board A', 'Exam Board B'], array_column($filtered['questions'][0]['board_links'], 'board_name'));
        $filteredListing = $controller->getQuestionsByFilters(Request::create('/', 'POST', ['board_id' => 1, 'year' => 2025]))->getData(true);
        $this->assertCount(1, $filteredListing['questions']['data'][0]['board_links']);
        $empty = $controller->exportQuestions(Request::create('/', 'POST', ['search' => 'missing']))->getData(true);
        $this->assertSame([], $empty['questions']);
    }

    public function test_both_report_scopes_restrict_export(): void
    {
        DB::table('permissions')->insert([
            ['id' => 1, 'name' => 'questions.report.view'],
            ['id' => 2, 'name' => 'questions.report.export'],
        ]);
        DB::table('roles')->insert([
            ['id' => 1, 'name' => 'reporter', 'display_name' => 'Reporter', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('role_permissions')->insert([
            ['role_id' => 1, 'permission_id' => 1],
            ['role_id' => 1, 'permission_id' => 2],
        ]);
        DB::table('role_permission_scopes')->insert([
            ['role_id' => 1, 'permission_id' => 1, 'scope_type' => 'book', 'scope_id' => 1],
            ['role_id' => 1, 'permission_id' => 2, 'scope_type' => 'topic', 'scope_id' => 2],
        ]);
        $user = \App\Models\User::create([
            'id' => 1,
            'name' => 'Reporter Admin',
            'email' => 'reporter@example.com',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => 1,
        ]);
        $request = Request::create('/', 'POST');
        $request->setUserResolver(fn ($guard = null) => $user);
        $this->assertSame(0, (new QuestionsController)->exportQuestions($request)->getData(true)['total']);
        DB::table('role_permission_scopes')->where('permission_id', 2)->update(['scope_id' => 1]);
        $this->assertSame(55, (new QuestionsController)->exportQuestions($request)->getData(true)['total']);
    }

    public function test_export_requires_both_permissions(): void
    {
        $route = app('router')->getRoutes()->match(Request::create('/api/admin/auth/question/export', 'POST'));
        $this->assertContains('permission:questions.report.view', $route->gatherMiddleware());
        $this->assertContains('permission:questions.report.export', $route->gatherMiddleware());

        DB::table('permissions')->insertOrIgnore([
            ['id' => 10, 'name' => 'questions.report.view'],
            ['id' => 11, 'name' => 'questions.report.export'],
            ['id' => 12, 'name' => 'questions.view'],
        ]);

        foreach ([[], ['questions.view'], ['questions.report.view'], ['questions.report.export'], ['questions.report.view', 'questions.report.export']] as $idx => $permissions) {
            $roleId = 100 + $idx;
            $userId = 100 + $idx;
            DB::table('roles')->insert([
                'id' => $roleId,
                'name' => 'role_' . $idx,
                'display_name' => 'Role ' . $idx,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ($permissions as $pName) {
                $pId = DB::table('permissions')->where('name', $pName)->value('id');
                if ($pId) {
                    DB::table('role_permissions')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $pId,
                    ]);
                }
            }
            $user = \App\Models\User::create([
                'id' => $userId,
                'name' => 'User ' . $idx,
                'email' => 'user' . $idx . '@example.com',
                'password' => bcrypt('secret'),
                'role_id' => $roleId,
                'is_active' => 1,
            ]);

            $middleware = new CheckPermission;
            $request = Request::create('/');
            $request->setUserResolver(fn ($guard = null) => $user);

            $response = $middleware->handle($request, fn ($req) =>
                $middleware->handle($req, fn () => response()->json(['success' => 1]), 'questions.report.export'),
                'questions.report.view');
            $this->assertSame(count($permissions) === 2 ? 200 : 403, $response->getStatusCode());
        }
    }
}
