<?php

namespace Tests\Feature;

use App\Http\Controllers\Stats\StudentStudyTimeController;
use App\Http\Controllers\Tests\PracticeSessionController;
use App\Http\Controllers\Tests\TestController;
use App\Models\PracticeSession;
use App\Models\PracticeSessionQuestion;
use App\Models\Test;
use App\Models\TestAttempt;
use App\Models\TestAttemptQuestion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FederalStudyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'federal_test', 'database.connections.federal_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('federal_test');
        $this->table('offered_classes', ['class_id', 'curriculum_board_id']);
        $this->table('offered_programs', ['offered_class_id', 'title', 'is_active']);
        $this->table('program_subjects', ['offered_program_id', 'subject_id', 'is_active']);
        $this->table('subject_tbl', ['subject_name']);
        $this->table('book_tbl', ['class_id', 'curriculum_board_id', 'subject_id', 'book_name', 'activate']);
        $this->table('book_unit_tbl', ['book_id', 'unit_no', 'unit_name', 'activate']);
        $this->table('book_unit_topic_tbl', ['unit_id']);
        $this->table('question_scenario_groups_tbl', ['activate']);
        $this->table('exam_question_tbl', ['topic_id', 'is_mcq', 'activate', 'scenario_group_id']);
        $this->table('practice_sessions', (new PracticeSession)->getFillable());
        $this->table('practice_session_questions', (new PracticeSessionQuestion)->getFillable());
        $this->table('practice_session_scopes', ['session_id', 'unit_id']);
        $this->table('tests', (new Test)->getFillable());
        $this->table('test_scopes', ['test_id', 'unit_id']);
        $this->table('test_questions', ['test_id', 'question_id', 'question_order', 'marks']);
        $this->table('test_attempts', (new TestAttempt)->getFillable());
        $this->table('test_attempt_questions', (new TestAttemptQuestion)->getFillable());

        DB::table('offered_classes')->insert(['id' => 1, 'class_id' => 9, 'curriculum_board_id' => 2]);
        DB::table('offered_programs')->insert(['id' => 1, 'offered_class_id' => 1, 'title' => 'Federal Biology', 'is_active' => 1]);
        DB::table('subject_tbl')->insert(['id' => 1, 'subject_name' => 'Physics']);
        DB::table('program_subjects')->insert(['offered_program_id' => 1, 'subject_id' => 1, 'is_active' => 1]);
        DB::table('book_tbl')->insert(['id' => 1, 'class_id' => 9, 'curriculum_board_id' => 2, 'subject_id' => 1, 'book_name' => 'Physics', 'activate' => 1]);
        foreach ([1, 2] as $id) {
            DB::table('book_unit_tbl')->insert(['id' => $id, 'book_id' => 1, 'unit_no' => $id, 'unit_name' => "Chapter {$id}", 'activate' => 1]);
            DB::table('book_unit_topic_tbl')->insert(['id' => $id, 'unit_id' => $id]);
        }
        DB::table('question_scenario_groups_tbl')->insert([['id' => 1, 'activate' => 1], ['id' => 2, 'activate' => 0]]);
        DB::table('exam_question_tbl')->insert([
            ['id' => 1, 'topic_id' => 1, 'is_mcq' => 1, 'activate' => 1, 'scenario_group_id' => null],
            ['id' => 2, 'topic_id' => 1, 'is_mcq' => 1, 'activate' => 1, 'scenario_group_id' => 1],
            ['id' => 3, 'topic_id' => 1, 'is_mcq' => 1, 'activate' => 1, 'scenario_group_id' => 2],
            ['id' => 4, 'topic_id' => 2, 'is_mcq' => 1, 'activate' => 1, 'scenario_group_id' => null],
        ]);
    }

    private function table(string $name, array $columns): void
    {
        Schema::create($name, function (Blueprint $table) use ($columns) {
            $table->id();
            foreach (array_unique($columns) as $column) {
                $table->string($column)->nullable();
            }
            $table->timestamps();
        });
    }

    private function request(array $data): Request
    {
        $request = Request::create('/', 'POST', $data);
        $request->setUserResolver(fn () => (object) ['id' => 7]);
        return $request;
    }

    public function test_practice_and_formal_generation_filter_mcqs_without_changing_default_behavior(): void
    {
        foreach ([new PracticeSessionController, new TestController] as $controller) {
            $method = $controller instanceof PracticeSessionController
                ? 'generateSelectedUnitsPracticeSessionForLms' : 'generateSelectedUnitsFormalTestForLms';
            foreach (['straight' => [1], 'scenario' => [2], 'all' => [1, 2, 3]] as $format => $expected) {
                $payload = ['offered_program_id' => 1, 'subject_id' => 1, 'unit_ids' => [1], 'total_questions' => count($expected)];
                if ($format !== 'all') $payload['mcq_format'] = $format;
                $response = $controller->$method($this->request($payload));
                $this->assertSame(200, $response->status(), $response->getContent());
                $data = $response->getData(true)['data'];
                $this->assertSame($format, $data['mcq_format']);
                $this->assertEqualsCanonicalizing($expected, $data['question_ids']);
            }
        }
    }

    public function test_study_time_is_scoped_to_current_user_and_program(): void
    {
        DB::table('practice_sessions')->insert([
            ['id' => 1, 'user_id' => 7, 'offered_program_id' => 1],
            ['id' => 2, 'user_id' => 8, 'offered_program_id' => 1],
            ['id' => 3, 'user_id' => 7, 'offered_program_id' => 2],
        ]);
        foreach ([1 => 60, 2 => 900, 3 => 800] as $sessionId => $seconds) {
            DB::table('practice_session_questions')->insert(['session_id' => $sessionId, 'question_id' => 1, 'time_spent_seconds' => $seconds]);
        }
        DB::table('tests')->insert(['id' => 1, 'offered_program_id' => 1]);
        DB::table('test_attempts')->insert([['id' => 1, 'test_id' => 1, 'user_id' => 7], ['id' => 2, 'test_id' => 1, 'user_id' => 8]]);
        DB::table('test_attempt_questions')->insert([
            ['attempt_id' => 1, 'question_id' => 2, 'time_spent_seconds' => 30],
            ['attempt_id' => 2, 'question_id' => 2, 'time_spent_seconds' => 700],
        ]);
        $response = (new StudentStudyTimeController)->getStudentStudyTimeForLms($this->request(['offered_program_id' => 1, 'subject_id' => 1]));
        $this->assertSame(200, $response->status(), $response->getContent());
        $data = $response->getData(true)['data'];
        $this->assertSame(60, $data['subjects'][0]['practice_seconds']);
        $this->assertSame(30, $data['subjects'][0]['test_seconds']);
        $this->assertSame(60, $data['chapters'][0]['practice_seconds']);
        $this->assertSame(0, $data['chapters'][1]['practice_seconds']);
    }
}
