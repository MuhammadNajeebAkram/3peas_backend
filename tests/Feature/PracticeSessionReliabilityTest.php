<?php

namespace Tests\Feature;

use App\Models\PracticeSession;
use App\Models\PracticeSessionQuestion;
use App\Models\PracticeSessionScope;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PracticeSessionReliabilityTest extends TestCase
{
    protected int $boardId = 9101;
    protected int $classId = 9102;
    protected int $subjectId = 9103;
    protected int $bookId = 9104;
    protected int $unitId = 9105;
    protected int $topicId = 9106;

    protected WebUser $student;
    protected WebUser $otherStudent;

    protected function setUp(): void
    {
        parent::setUp();

        $activeDb = DB::selectOne("SELECT DATABASE() as db")->db ?? '';
        if ($activeDb !== '3peas_db_subscription_test' && $activeDb !== '3peas_db') {
            $this->markTestSkipped("Safety gate: test only runs against local test databases.");
        }

        $this->withoutMiddleware([
            \App\Http\Middleware\AttachJwtFromCookie::class,
            \App\Http\Middleware\AuthenticateJwtCookieGuard::class,
        ]);

        // Seed academic hierarchy
        DB::table('curriculum_board_tbl')->updateOrInsert(['id' => $this->boardId], ['name' => 'Reliability Board', 'activate' => 1]);
        DB::table('class_tbl')->updateOrInsert(['id' => $this->classId], ['class_name' => 'Reliability Class', 'activate' => 1]);
        DB::table('subject_tbl')->updateOrInsert(['id' => $this->subjectId], ['subject_name' => 'Reliability Subject', 'icon_name' => 'rel.png', 'activate' => 1]);

        DB::table('book_tbl')->updateOrInsert(['id' => $this->bookId], [
            'book_name'           => 'Reliability Book',
            'class_id'            => $this->classId,
            'subject_id'          => $this->subjectId,
            'curriculum_board_id' => $this->boardId,
            'activate'            => 1,
        ]);

        DB::table('book_unit_tbl')->updateOrInsert(['id' => $this->unitId], [
            'unit_name' => 'Reliability Unit',
            'unit_no'   => 1,
            'book_id'   => $this->bookId,
            'activate'  => 1,
        ]);

        DB::table('book_unit_topic_tbl')->updateOrInsert(['id' => $this->topicId], [
            'topic_name' => 'Reliability Topic',
            'topic_no'   => 1,
            'unit_id'    => $this->unitId,
            'activate'   => 1,
        ]);

        DB::table('offered_classes')->updateOrInsert(['id' => 1], [
            'class_id'            => $this->classId,
            'curriculum_board_id' => $this->boardId,
            'is_active'           => 1,
        ]);

        DB::table('offered_programs')->updateOrInsert(['id' => 2], [
            'offered_class_id' => 1,
            'title'            => '9th Bio (Fed)',
            'slug'             => '9th-bio-fed',
            'is_active'        => 1,
        ]);

        // Seed 2 students
        $this->student = WebUser::updateOrCreate(
            ['email' => 'reliability_student_1@tst.test'],
            ['name' => 'Reliability Student 1', 'role' => 'student', 'status' => 'active', 'password' => bcrypt('password123')]
        );

        $this->otherStudent = WebUser::updateOrCreate(
            ['email' => 'reliability_student_2@tst.test'],
            ['name' => 'Reliability Student 2', 'role' => 'student', 'status' => 'active', 'password' => bcrypt('password123')]
        );

        // Clean up previous test sessions for test students
        PracticeSession::whereIn('user_id', [$this->student->id, $this->otherStudent->id])->delete();

        \App\Models\UserSubscription::updateOrCreate(
            ['user_id' => $this->student->id, 'offered_program_id' => 2],
            [
                'status'      => 'active',
                'access_type' => 'paid',
                'started_at'  => now()->subDays(1),
                'expires_at'  => now()->addMonths(6),
            ]
        );

        DB::table('program_subjects')->updateOrInsert(
            ['offered_program_id' => 2, 'subject_id' => $this->subjectId],
            ['is_free' => 0, 'is_active' => 1, 'updated_at' => now()]
        );

        // Seed straight questions (20 questions)
        for ($i = 1; $i <= 20; $i++) {
            $qid = 95000 + $i;
            DB::table('exam_question_tbl')->updateOrInsert(['id' => $qid], [
                'question'          => "<p>Straight Question {$i}</p>",
                'topic_id'          => $this->topicId,
                'question_type'     => 1,
                'is_mcq'            => 1,
                'activate'          => 1,
                'scenario_group_id' => null,
            ]);

            for ($opt = 1; $opt <= 4; $opt++) {
                DB::table('exam_question_options_tbl')->updateOrInsert(['id' => ($qid * 10) + $opt], [
                    'question_id' => $qid,
                    'option'      => "Option {$opt}",
                    'is_answer'   => $opt === 1 ? 1 : 0,
                ]);
            }
        }

        // Seed scenario group with 5 questions
        DB::table('question_scenario_groups_tbl')->updateOrInsert(['id' => 9501], [
            'title'         => 'Reliability Scenario 5-Questions',
            'scenario_text' => 'Passage with exactly 5 questions.',
            'activate'      => 1,
        ]);

        for ($i = 1; $i <= 5; $i++) {
            $qid = 95100 + $i;
            DB::table('exam_question_tbl')->updateOrInsert(['id' => $qid], [
                'question'                => "<p>Scenario Question {$i}</p>",
                'topic_id'                => $this->topicId,
                'question_type'           => 1,
                'is_mcq'                  => 1,
                'activate'                => 1,
                'scenario_group_id'       => 9501,
                'scenario_question_order' => $i,
            ]);

            for ($opt = 1; $opt <= 4; $opt++) {
                DB::table('exam_question_options_tbl')->updateOrInsert(['id' => ($qid * 10) + $opt], [
                    'question_id' => $qid,
                    'option'      => "Option {$opt}",
                    'is_answer'   => $opt === 1 ? 1 : 0,
                ]);
            }
        }

        // Seed second scenario group with 5 questions
        DB::table('question_scenario_groups_tbl')->updateOrInsert(['id' => 9502], [
            'title'         => 'Reliability Scenario 2 5-Questions',
            'scenario_text' => 'Second passage with 5 questions.',
            'activate'      => 1,
        ]);

        for ($i = 1; $i <= 5; $i++) {
            $qid = 95200 + $i;
            DB::table('exam_question_tbl')->updateOrInsert(['id' => $qid], [
                'question'                => "<p>Scenario 2 Question {$i}</p>",
                'topic_id'                => $this->topicId,
                'question_type'           => 1,
                'is_mcq'                  => 1,
                'activate'                => 1,
                'scenario_group_id'       => 9502,
                'scenario_question_order' => $i,
            ]);

            for ($opt = 1; $opt <= 4; $opt++) {
                DB::table('exam_question_options_tbl')->updateOrInsert(['id' => ($qid * 10) + $opt], [
                    'question_id' => $qid,
                    'option'      => "Option {$opt}",
                    'is_answer'   => $opt === 1 ? 1 : 0,
                ]);
            }
        }
    }

    public function test_incompatible_question_count_does_not_resume_old_session_and_auto_abandons_it(): void
    {
        // 1. Create existing in-progress session with 50 questions
        $oldSession = PracticeSession::create([
            'user_id'            => $this->student->id,
            'offered_program_id' => 2,
            'subject_id'         => $this->subjectId,
            'scope_type'         => 'chapter',
            'question_type'      => 'mcq',
            'total_questions'    => 50,
            'status'             => 'in_progress',
            'started_at'         => now(),
        ]);
        PracticeSessionScope::create(['session_id' => $oldSession->id, 'unit_id' => $this->unitId]);

        // 2. Student requests 8 questions for the same scope (2 scenario groups * 4 questions)
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'rel_incomp_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'offered_program_id' => 2,
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 8,
                'mcq_format'         => 'scenario',
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        // New session must have 8 questions and distinct ID
        $this->assertNotEquals($oldSession->id, $data['session_id']);
        $this->assertEquals(8, $data['total_questions']);

        // Old session must have been transitioned to abandoned
        $this->assertEquals('abandoned', $oldSession->fresh()->status);
    }

    public function test_compatible_session_resumes_cleanly(): void
    {
        $session = PracticeSession::create([
            'user_id'            => $this->student->id,
            'offered_program_id' => 2,
            'subject_id'         => $this->subjectId,
            'scope_type'         => 'chapter',
            'question_type'      => 'mcq',
            'total_questions'    => 10,
            'status'             => 'in_progress',
            'started_at'         => now(),
        ]);
        PracticeSessionScope::create(['session_id' => $session->id, 'unit_id' => $this->unitId]);

        // Attach 10 scenario questions to this session
        for ($i = 1; $i <= 5; $i++) {
            PracticeSessionQuestion::create([
                'session_id'     => $session->id,
                'question_id'    => 95100 + $i,
                'question_order' => $i,
            ]);
            PracticeSessionQuestion::create([
                'session_id'     => $session->id,
                'question_id'    => 95200 + $i,
                'question_order' => 5 + $i,
            ]);
        }

        // Student requests matching session (10 questions, scenario)
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'rel_comp_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'offered_program_id' => 2,
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 10,
                'mcq_format'         => 'scenario',
            ]);

        $response->assertStatus(200);
        $this->assertEquals($session->id, $response->json('data.session_id'));
        $this->assertEquals('scenario', $response->json('data.mcq_format'));
    }

    public function test_stale_session_older_than_48_hours_is_auto_abandoned(): void
    {
        $staleSession = PracticeSession::create([
            'user_id'            => $this->student->id,
            'offered_program_id' => 2,
            'subject_id'         => $this->subjectId,
            'scope_type'         => 'chapter',
            'question_type'      => 'mcq',
            'total_questions'    => 10,
            'status'             => 'in_progress',
            'started_at'         => now()->subHours(72),
            'created_at'         => now()->subHours(72),
        ]);
        PracticeSessionScope::create(['session_id' => $staleSession->id, 'unit_id' => $this->unitId]);
        DB::table('practice_sessions')->where('id', $staleSession->id)->update(['created_at' => now()->subHours(72)]);

        // Request 8 questions
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'rel_stale_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'offered_program_id' => 2,
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 8,
                'mcq_format'         => 'scenario',
            ]);

        $response->assertStatus(200);
        $this->assertNotEquals($staleSession->id, $response->json('data.session_id'));
        $this->assertEquals('abandoned', $staleSession->fresh()->status);
    }

    public function test_student_can_abandon_own_in_progress_session(): void
    {
        $session = PracticeSession::create([
            'user_id'         => $this->student->id,
            'subject_id'      => $this->subjectId,
            'scope_type'      => 'chapter',
            'question_type'   => 'mcq',
            'total_questions' => 10,
            'status'          => 'in_progress',
            'started_at'      => now(),
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->postJson("/web_api/auth/tests/practice-sessions/{$session->id}/abandon");

        if ($response->status() !== 200) {
            dump($response->json());
        }
        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('success'));
        $this->assertEquals('abandoned', $response->json('status'));
        $this->assertEquals('abandoned', $session->fresh()->status);
    }

    public function test_student_cannot_abandon_another_students_session(): void
    {
        $session = PracticeSession::create([
            'user_id'         => $this->student->id,
            'subject_id'      => $this->subjectId,
            'scope_type'      => 'chapter',
            'question_type'   => 'mcq',
            'total_questions' => 10,
            'status'          => 'in_progress',
            'started_at'      => now(),
        ]);

        $response = $this->actingAs($this->otherStudent, 'web_api')
            ->postJson("/web_api/auth/tests/practice-sessions/{$session->id}/abandon");

        $response->assertStatus(403);
        $this->assertEquals('in_progress', $session->fresh()->status);
    }

    public function test_submitted_session_cannot_be_abandoned(): void
    {
        $session = PracticeSession::create([
            'user_id'         => $this->student->id,
            'subject_id'      => $this->subjectId,
            'scope_type'      => 'chapter',
            'question_type'   => 'mcq',
            'total_questions' => 10,
            'status'          => 'submitted',
            'submitted_at'    => now(),
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->postJson("/web_api/auth/tests/practice-sessions/{$session->id}/abandon");

        $response->assertStatus(409);
        $this->assertEquals('submitted', $session->fresh()->status);
    }

    public function test_abandon_endpoint_is_idempotent(): void
    {
        $session = PracticeSession::create([
            'user_id'         => $this->student->id,
            'subject_id'      => $this->subjectId,
            'scope_type'      => 'chapter',
            'question_type'   => 'mcq',
            'total_questions' => 10,
            'status'          => 'abandoned',
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->postJson("/web_api/auth/tests/practice-sessions/{$session->id}/abandon");

        $response->assertStatus(200);
        $this->assertEquals('abandoned', $response->json('status'));
    }

    public function test_scenario_group_with_five_questions_selects_only_random_four_questions(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'rel_4q_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'offered_program_id' => 2,
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 8,
                'mcq_format'         => 'scenario',
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertEquals(8, $data['total_questions']);
        $this->assertCount(2, $data['scenario_blocks']);

        // Each 5-question scenario group in DB must only yield random 4 questions (no more than 4)
        foreach ($data['scenario_blocks'] as $block) {
            $this->assertCount(4, $block['questions']);
            $this->assertCount(4, $block['question_ids']);
        }
    }
}
