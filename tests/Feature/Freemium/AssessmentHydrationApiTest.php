<?php

namespace Tests\Feature\Freemium;

use App\Models\BookUnitTopic;
use App\Models\ExamQuestion;
use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\PracticeSession;
use App\Models\PracticeSessionQuestion;
use App\Models\PracticeSessionScope;
use App\Models\Test;
use App\Models\TestAttempt;
use App\Models\TestAttemptQuestion;
use App\Models\TestQuestion;
use App\Models\TestScope;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Assessment Hydration and Recovery API Test Suite (Milestone C6)
 */
class AssessmentHydrationApiTest extends TestCase
{
    use DatabaseTransactions;

    private WebUser $student;
    private WebUser $otherStudent;
    private int $boardId = 2;
    private int $classId = 1;
    private int $subjectId = 5;
    private int $bookId = 28;
    private int $unitId263 = 263;
    private int $topicId1342 = 1342;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            \App\Http\Middleware\AttachJwtFromCookie::class,
            \App\Http\Middleware\AuthenticateJwtCookieGuard::class,
        ]);

        $this->student = WebUser::updateOrCreate(
            ['email' => 'c6_student@example.com'],
            [
                'name'     => 'C6 Test Student',
                'password' => bcrypt('secret123'),
                'role'     => 'student',
                'status'   => 'active',
            ]
        );

        $this->otherStudent = WebUser::updateOrCreate(
            ['email' => 'c6_other_student@example.com'],
            [
                'name'     => 'C6 Other Student',
                'password' => bcrypt('secret123'),
                'role'     => 'student',
                'status'   => 'active',
            ]
        );

        // Seed academic hierarchy
        DB::table('curriculum_board_tbl')->updateOrInsert(['id' => $this->boardId], ['name' => 'Federal Board', 'activate' => 1]);
        DB::table('class_tbl')->updateOrInsert(['id' => $this->classId], ['class_name' => '9th Class', 'activate' => 1]);
        DB::table('subject_tbl')->updateOrInsert(['id' => $this->subjectId], ['subject_name' => 'Biology', 'icon_name' => 'bio.png', 'activate' => 1]);
        DB::table('book_tbl')->updateOrInsert(['id' => $this->bookId], [
            'book_name'           => 'Federal Biology 9th',
            'curriculum_board_id' => $this->boardId,
            'class_id'            => $this->classId,
            'subject_id'          => $this->subjectId,
            'activate'            => 1,
        ]);
        DB::table('book_unit_tbl')->updateOrInsert(['id' => $this->unitId263], [
            'unit_name' => 'THE SCIENCE OF BIOLOGY',
            'unit_no'   => 1,
            'book_id'   => $this->bookId,
            'activate'  => 1,
        ]);
        DB::table('book_unit_topic_tbl')->updateOrInsert(['id' => $this->topicId1342], [
            'topic_name' => 'Introduction to Biology',
            'topic_no'   => 1,
            'unit_id'    => $this->unitId263,
            'activate'   => 1,
        ]);

        $campaign = FreemiumCampaign::create([
            'name'                 => 'C6 Test Campaign ' . uniqid(),
            'status'               => 'active',
            'priority'             => 100,
            'start_at'             => now()->subDays(5),
            'end_at'               => now()->addYears(2),
            'is_stackable'         => false,
            'eligibility_criteria' => null,
            'created_by'           => 1,
            'updated_by'           => 1,
        ]);

        FreemiumAccessRule::firstOrCreate(
            [
                'campaign_id' => $campaign->id,
                'chapter_id'  => $this->unitId263,
            ],
            [
                'target_scope'                 => 'chapter',
                'target_status'                => 'active',
                'curriculum_board_id'          => $this->boardId,
                'class_id'                     => $this->classId,
                'subject_id'                   => $this->subjectId,
                'book_id'                      => $this->bookId,
                'chapter_id'                   => $this->unitId263,
                'perm_mcqs'                    => 'allow',
                'perm_short_questions'         => 'allow',
                'perm_long_questions'          => 'allow',
                'perm_exercise_questions'      => 'allow',
                'perm_supplementary_questions' => 'allow',
                'perm_answers'                 => 'allow',
                'perm_explanations'            => 'allow',
                'perm_topic_contents'          => 'allow',
                'rule_priority'                => 10,
                'academic_target_snapshot'     => [
                    'board_id'   => $this->boardId,
                    'class_id'   => $this->classId,
                    'subject_id' => $this->subjectId,
                    'book_id'    => $this->bookId,
                    'chapter_id' => $this->unitId263,
                ],
            ]
        );
    }

    /**
     * Test 1: Hydrate practice session restores session metadata, ordered questions, and saved answers.
     */
    public function test_hydrate_practice_session_restores_questions_and_saved_answers(): void
    {
        // 1. Create a practice session with 2 questions
        $session = PracticeSession::create([
            'user_id'                 => $this->student->id,
            'subject_id'              => $this->subjectId,
            'scope_type'              => 'chapter',
            'total_questions'         => 2,
            'attempted_questions'     => 1,
            'correct_answers'         => 1,
            'wrong_answers'           => 0,
            'not_attempted_questions' => 1,
            'score'                   => 1.0,
            'accuracy'                => 100.0,
            'time_limit_minutes'      => 30,
            'started_at'              => now()->subMinutes(5),
            'status'                  => 'in_progress',
        ]);

        PracticeSessionScope::create([
            'session_id' => $session->id,
            'unit_id'    => $this->unitId263,
        ]);

        // Seed 2 questions with options
        for ($i = 1; $i <= 2; $i++) {
            $qid = 80000 + $i;
            DB::table('exam_question_tbl')->updateOrInsert(['id' => $qid], [
                'question'          => "<p>Question $i text</p>",
                'topic_id'          => $this->topicId1342,
                'question_type'     => 1,
                'is_mcq'            => 1,
                'exercise_question' => 1,
                'activate'          => 1,
                'explanation'       => "<p>Explanation $i</p>",
            ]);

            $opt1 = 81000 + ($i * 10) + 1;
            $opt2 = 81000 + ($i * 10) + 2;
            DB::table('exam_question_options_tbl')->updateOrInsert(['id' => $opt1], [
                'question_id' => $qid,
                'option'      => "<p>Option 1</p>",
                'is_answer'   => 1,
            ]);
            DB::table('exam_question_options_tbl')->updateOrInsert(['id' => $opt2], [
                'question_id' => $qid,
                'option'      => "<p>Option 2</p>",
                'is_answer'   => 0,
            ]);

            PracticeSessionQuestion::create([
                'session_id'         => $session->id,
                'question_id'        => $qid,
                'question_order'     => $i,
                'selected_option_id' => ($i === 1) ? $opt1 : null,
                'is_attempted'       => ($i === 1),
                'is_correct'         => ($i === 1),
                'answer_shown'       => false,
                'time_spent_seconds' => ($i === 1) ? 25 : 0,
            ]);
        }

        // Hydrate from GET endpoint
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/practice-sessions/{$session->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => 1]);

        $data = $response->json('data');
        $this->assertEquals($session->id, $data['session_id']);
        $this->assertEquals('in_progress', $data['status']);
        $this->assertEquals(2, $data['total_questions']);
        $this->assertGreaterThan(0, $data['remaining_seconds']);
        $this->assertFalse($data['is_expired']);
        $this->assertCount(2, $data['questions']);

        // Check question 1 saved answer restoration
        $q1 = $data['questions'][0];
        $this->assertEquals(80001, $q1['id']);
        $this->assertEquals(1, $q1['question_order']);
        $this->assertEquals(81011, $q1['saved_answer']['selected_option_id']);
        $this->assertTrue($q1['saved_answer']['is_attempted']);
        $this->assertEquals(25, $q1['saved_answer']['time_spent_seconds']);

        // Check question 2 unanswered preservation
        $q2 = $data['questions'][1];
        $this->assertEquals(80002, $q2['id']);
        $this->assertNull($q2['saved_answer']['selected_option_id']);
        $this->assertFalse($q2['saved_answer']['is_attempted']);
    }

    /**
     * Test 2: Student cannot access another student's practice session.
     */
    public function test_student_cannot_access_other_students_practice_session(): void
    {
        $session = PracticeSession::create([
            'user_id'         => $this->otherStudent->id,
            'subject_id'      => $this->subjectId,
            'scope_type'      => 'chapter',
            'total_questions' => 1,
            'status'          => 'in_progress',
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/practice-sessions/{$session->id}");

        $response->assertStatus(404);
    }

    /**
     * Test 3: Hydrate formal test restores questions, hides answer keys during active attempt, and restores saved answers.
     */
    public function test_hydrate_formal_test_hides_answers_during_active_attempt(): void
    {
        $test = Test::create([
            'created_by'         => $this->student->id,
            'title'              => 'C6 Test Formal Exam',
            'subject_id'         => $this->subjectId,
            'scope_type'         => 'chapter',
            'test_mode'          => 'test',
            'test_source'        => 'system',
            'time_limit_minutes' => 45,
            'total_questions'    => 1,
        ]);

        TestScope::create([
            'test_id' => $test->id,
            'unit_id' => $this->unitId263,
        ]);

        $qid = 80005;
        DB::table('exam_question_tbl')->updateOrInsert(['id' => $qid], [
            'question'      => '<p>Formal test question</p>',
            'topic_id'      => $this->topicId1342,
            'question_type' => 1,
            'is_mcq'        => 1,
            'activate'      => 1,
            'explanation'   => '<p>Secret explanation</p>',
        ]);

        $optId = 81051;
        DB::table('exam_question_options_tbl')->updateOrInsert(['id' => $optId], [
            'question_id' => $qid,
            'option'      => '<p>Correct Answer</p>',
            'is_answer'   => 1,
        ]);

        TestQuestion::create([
            'test_id'        => $test->id,
            'question_id'    => $qid,
            'question_order' => 1,
            'marks'          => 1.0,
        ]);

        $attempt = TestAttempt::create([
            'test_id'                 => $test->id,
            'user_id'                 => $this->student->id,
            'attempted_questions'     => 1,
            'correct_answers'         => 0,
            'wrong_answers'           => 0,
            'not_attempted_questions' => 0,
            'score'                   => 0,
            'accuracy'                => 0,
            'started_at'              => now()->subMinutes(10),
            'status'                  => 'in_progress',
        ]);

        TestAttemptQuestion::create([
            'attempt_id'         => $attempt->id,
            'question_id'        => $qid,
            'question_order'     => 1,
            'selected_option_id' => $optId,
            'is_attempted'       => true,
            'marks'              => 1.0,
            'obtained_marks'     => 0,
            'time_spent_seconds' => 40,
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/formal-tests/{$test->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => 1]);

        $data = $response->json('data');
        $this->assertEquals($test->id, $data['test_id']);
        $this->assertEquals($attempt->id, $data['attempt_id']);
        $this->assertEquals('in_progress', $data['status']);
        $this->assertGreaterThan(0, $data['remaining_seconds']);

        // Check question: is_answer and explanation MUST be concealed during active attempt
        $q = $data['questions'][0];
        $this->assertEquals($qid, $q['id']);
        $this->assertNull($q['explanation'], 'Explanation must not leak during active formal test');
        $this->assertNull($q['options'][0]['is_answer'], 'Correct option flag must not leak during active formal test');

        // Saved answer was preserved
        $this->assertEquals($optId, $q['saved_answer']['selected_option_id']);
        $this->assertTrue($q['saved_answer']['is_attempted']);
        $this->assertEquals(40, $q['saved_answer']['time_spent_seconds']);
    }

    /**
     * Test 4: When formal test is submitted, answers and explanations become visible and remaining_seconds = 0.
     */
    public function test_hydrate_submitted_formal_test_reveals_answers(): void
    {
        $test = Test::create([
            'created_by'         => $this->student->id,
            'title'              => 'C6 Submitted Test',
            'subject_id'         => $this->subjectId,
            'scope_type'         => 'chapter',
            'test_mode'          => 'test',
            'test_source'        => 'system',
            'time_limit_minutes' => 45,
            'total_questions'    => 1,
        ]);

        TestScope::create([
            'test_id' => $test->id,
            'unit_id' => $this->unitId263,
        ]);

        $qid = 80006;
        DB::table('exam_question_tbl')->updateOrInsert(['id' => $qid], [
            'question'      => '<p>Submitted question</p>',
            'topic_id'      => $this->topicId1342,
            'question_type' => 1,
            'is_mcq'        => 1,
            'activate'      => 1,
            'explanation'   => '<p>Visible Explanation</p>',
        ]);

        $optId = 81061;
        DB::table('exam_question_options_tbl')->updateOrInsert(['id' => $optId], [
            'question_id' => $qid,
            'option'      => '<p>Answer Text</p>',
            'is_answer'   => 1,
        ]);

        TestQuestion::create([
            'test_id'        => $test->id,
            'question_id'    => $qid,
            'question_order' => 1,
            'marks'          => 1.0,
        ]);

        TestAttempt::create([
            'test_id'                 => $test->id,
            'user_id'                 => $this->student->id,
            'attempted_questions'     => 1,
            'correct_answers'         => 1,
            'wrong_answers'           => 0,
            'not_attempted_questions' => 0,
            'score'                   => 1.0,
            'accuracy'                => 100.0,
            'started_at'              => now()->subMinutes(30),
            'submitted_at'            => now()->subMinutes(5),
            'status'                  => 'submitted',
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/formal-tests/{$test->id}");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals('submitted', $data['status']);
        $this->assertEquals(0, $data['remaining_seconds']);

        $q = $data['questions'][0];
        $this->assertEquals('<p>Visible Explanation</p>', $q['explanation']);
        $this->assertTrue($q['options'][0]['is_answer']);
    }
}
