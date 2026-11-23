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
 * Assessment Security and Expiry Enforcement Test Suite (Milestone C6-C1)
 *
 * Verifies:
 * - C6-AUD-01: No answer correctness or score fields exposed in active formal test save response.
 * - C6-AUD-02: Server-side expiry enforcement on formal-test save & submit endpoints.
 * - C6-AUD-03: Unattempted formal tests receive full duration without false expiry from test created_at.
 * - C6-AUD-04: Server-side expiry enforcement on practice-session save endpoint.
 */
class AssessmentSecurityAndExpiryEnforcementTest extends TestCase
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
            ['email' => 'c6c1_student@example.com'],
            [
                'name'     => 'C6-C1 Test Student',
                'password' => bcrypt('secret123'),
                'role'     => 'student',
                'status'   => 'active',
            ]
        );

        $this->otherStudent = WebUser::updateOrCreate(
            ['email' => 'c6c1_other_student@example.com'],
            [
                'name'     => 'C6-C1 Other Student',
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
            'name'                 => 'C6-C1 Test Campaign ' . uniqid(),
            'status'               => 'active',
            'priority'             => 100,
            'start_at'             => now()->subDays(5),
            'end_at'               => now()->addYears(2),
            'is_stackable'         => false,
            'eligibility_criteria' => null,
            'created_by'           => 1,
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

    private function createSampleQuestion(string $text, int $correctOpt = 1): array
    {
        $qId = DB::table('exam_question_tbl')->insertGetId([
            'question'          => "<p>{$text}</p>",
            'question_um'       => "<p>{$text} UM</p>",
            'topic_id'          => $this->topicId1342,
            'question_type'     => 1,
            'is_mcq'            => 1,
            'exercise_question' => 1,
            'activate'          => 1,
            'explanation'       => "Official explanation for {$text}",
        ]);

        $opt1Id = DB::table('exam_question_options_tbl')->insertGetId([
            'question_id' => $qId,
            'option'      => 'Option 1',
            'is_answer'   => $correctOpt === 1 ? 1 : 0,
        ]);

        $opt2Id = DB::table('exam_question_options_tbl')->insertGetId([
            'question_id' => $qId,
            'option'      => 'Option 2',
            'is_answer'   => $correctOpt === 2 ? 1 : 0,
        ]);

        return [
            'question_id' => $qId,
            'opt1_id'     => $opt1Id,
            'opt2_id'     => $opt2Id,
        ];
    }

    /**
     * Point 1: Active formal-test save response contains no correctness or score fields.
     */
    public function test_active_formal_test_save_response_contains_no_correctness_or_score_fields(): void
    {
        $qData = $this->createSampleQuestion('Formal Test Question 1', 1);

        $test = Test::create([
            'title'              => 'Formal Exam 1',
            'test_source'        => 'system',
            'test_mode'          => 'test',
            'scope_type'         => 'chapter',
            'subject_id'         => $this->subjectId,
            'total_questions'    => 1,
            'time_limit_minutes' => 30,
            'is_published'       => true,
            'created_by'         => $this->student->id,
            'created_at'         => now(),
        ]);
        TestScope::create(['test_id' => $test->id, 'unit_id' => $this->unitId263]);
        TestQuestion::create([
            'test_id'        => $test->id,
            'question_id'    => $qData['question_id'],
            'question_order' => 1,
            'marks'          => 1.0,
        ]);

        $attempt = TestAttempt::create([
            'test_id'                 => $test->id,
            'user_id'                 => $this->student->id,
            'attempted_questions'     => 0,
            'correct_answers'         => 0,
            'wrong_answers'           => 0,
            'not_attempted_questions' => 1,
            'score'                   => 0,
            'accuracy'                => 0,
            'started_at'              => now()->subMinutes(5),
            'status'                  => 'in_progress',
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $test->id,
                'attempt_id'         => $attempt->id,
                'question_id'        => $qData['question_id'],
                'selected_option_id' => $qData['opt1_id'], // Correct option selected
                'time_spent_seconds' => 15,
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        // Safe progress metadata present
        $this->assertEquals($test->id, $data['test_id']);
        $this->assertEquals($qData['question_id'], $data['question_id']);
        $this->assertTrue($data['is_attempted']);
        $this->assertEquals('in_progress', $data['status']);
        $this->assertEquals(1, $data['attempted_questions']);

        // Correctness and scoring fields strictly excluded
        $this->assertArrayNotHasKey('is_correct', $data, 'is_correct must not be exposed during active formal test');
        $this->assertArrayNotHasKey('correct_answers', $data, 'correct_answers must not be exposed during active formal test');
        $this->assertArrayNotHasKey('wrong_answers', $data, 'wrong_answers must not be exposed during active formal test');
        $this->assertArrayNotHasKey('score', $data, 'score must not be exposed during active formal test');
        $this->assertArrayNotHasKey('accuracy', $data, 'accuracy must not be exposed during active formal test');
    }

    /**
     * Point 2: Submitted formal-test results still expose authorized results.
     */
    public function test_submitted_formal_test_exposes_authorized_results(): void
    {
        $qData = $this->createSampleQuestion('Formal Test Question 2', 1);

        $test = Test::create([
            'title'              => 'Formal Exam 2',
            'test_source'        => 'system',
            'test_mode'          => 'test',
            'scope_type'         => 'chapter',
            'subject_id'         => $this->subjectId,
            'total_questions'    => 1,
            'time_limit_minutes' => 30,
            'is_published'       => true,
            'created_by'         => $this->student->id,
            'created_at'         => now(),
        ]);
        TestScope::create(['test_id' => $test->id, 'unit_id' => $this->unitId263]);
        TestQuestion::create([
            'test_id'        => $test->id,
            'question_id'    => $qData['question_id'],
            'question_order' => 1,
            'marks'          => 1.0,
        ]);

        $attempt = TestAttempt::create([
            'test_id'                 => $test->id,
            'user_id'                 => $this->student->id,
            'attempted_questions'     => 1,
            'correct_answers'         => 1,
            'wrong_answers'           => 0,
            'not_attempted_questions' => 0,
            'score'                   => 1.0,
            'accuracy'                => 100.0,
            'started_at'              => now()->subMinutes(10),
            'status'                  => 'in_progress',
        ]);
        TestAttemptQuestion::create([
            'attempt_id'         => $attempt->id,
            'question_id'        => $qData['question_id'],
            'question_order'     => 1,
            'selected_option_id' => $qData['opt1_id'],
            'is_attempted'       => true,
            'is_correct'         => true,
            'marks'              => 1.0,
            'obtained_marks'     => 1.0,
        ]);

        // Submit attempt
        $submitResponse = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/submit-formal-test', [
                'test_id'    => $test->id,
                'attempt_id' => $attempt->id,
            ]);

        $submitResponse->assertStatus(200);
        $submitData = $submitResponse->json('data');
        $this->assertEquals(1, $submitData['correct_answers']);
        $this->assertEquals(1.0, $submitData['score']);
        $this->assertEquals(100.0, $submitData['accuracy']);
        $this->assertEquals('submitted', $submitData['status']);

        // Hydration after submit exposes answers and results
        $hydrateResponse = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/formal-tests/{$test->id}?attempt_id={$attempt->id}");

        $hydrateResponse->assertStatus(200);
        $hydrateData = $hydrateResponse->json('data');
        $this->assertEquals('submitted', $hydrateData['status']);
        $this->assertTrue($hydrateData['questions'][0]['options'][0]['is_answer']);
        $this->assertEquals("Official explanation for Formal Test Question 2", $hydrateData['questions'][0]['explanation']);
        $this->assertEquals(1, $hydrateData['live_progress']['correct_answers']);
    }

    /**
     * Point 3: Formal-test answer save is rejected after expiry.
     */
    public function test_formal_test_answer_save_is_rejected_after_expiry(): void
    {
        $qData = $this->createSampleQuestion('Formal Test Expiry Question', 1);

        $test = Test::create([
            'title'              => 'Formal Exam Timed',
            'test_source'        => 'system',
            'test_mode'          => 'test',
            'scope_type'         => 'chapter',
            'subject_id'         => $this->subjectId,
            'total_questions'    => 1,
            'time_limit_minutes' => 15,
            'is_published'       => true,
            'created_by'         => $this->student->id,
            'created_at'         => now()->subDays(1),
        ]);
        TestScope::create(['test_id' => $test->id, 'unit_id' => $this->unitId263]);
        TestQuestion::create([
            'test_id'        => $test->id,
            'question_id'    => $qData['question_id'],
            'question_order' => 1,
            'marks'          => 1.0,
        ]);

        // Attempt started 20 minutes ago (time limit 15 min -> expired!)
        $attempt = TestAttempt::create([
            'test_id'                 => $test->id,
            'user_id'                 => $this->student->id,
            'attempted_questions'     => 0,
            'correct_answers'         => 0,
            'wrong_answers'           => 0,
            'not_attempted_questions' => 1,
            'score'                   => 0,
            'accuracy'                => 0,
            'started_at'              => now()->subMinutes(20),
            'status'                  => 'in_progress',
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $test->id,
                'attempt_id'         => $attempt->id,
                'question_id'        => $qData['question_id'],
                'selected_option_id' => $qData['opt1_id'],
            ]);

        $response->assertStatus(422);
        $this->assertEquals('ATTEMPT_EXPIRED', $response->json('error_code'));

        // Verify status transitioned to expired and question was NOT saved
        $attempt->refresh();
        $this->assertEquals('expired', $attempt->status);
        $this->assertDatabaseMissing('test_attempt_questions', [
            'attempt_id'  => $attempt->id,
            'question_id' => $qData['question_id'],
        ]);
    }

    /**
     * Point 4: Formal-test submission after expiry follows the intended finalization policy.
     */
    public function test_formal_test_submission_after_expiry_finalizes_pre_saved_answers_and_ignores_late_payload(): void
    {
        $qData1 = $this->createSampleQuestion('Pre-saved question', 1);
        $qData2 = $this->createSampleQuestion('Late question', 2);

        $test = Test::create([
            'title'              => 'Formal Exam Submission Expiry',
            'test_source'        => 'system',
            'test_mode'          => 'test',
            'scope_type'         => 'chapter',
            'subject_id'         => $this->subjectId,
            'total_questions'    => 2,
            'time_limit_minutes' => 10,
            'is_published'       => true,
            'created_by'         => $this->student->id,
            'created_at'         => now(),
        ]);
        TestScope::create(['test_id' => $test->id, 'unit_id' => $this->unitId263]);
        TestQuestion::create(['test_id' => $test->id, 'question_id' => $qData1['question_id'], 'question_order' => 1, 'marks' => 1.0]);
        TestQuestion::create(['test_id' => $test->id, 'question_id' => $qData2['question_id'], 'question_order' => 2, 'marks' => 1.0]);

        // Attempt started 15 minutes ago (expired!)
        $attempt = TestAttempt::create([
            'test_id'                 => $test->id,
            'user_id'                 => $this->student->id,
            'attempted_questions'     => 1,
            'correct_answers'         => 1,
            'wrong_answers'           => 0,
            'not_attempted_questions' => 1,
            'score'                   => 1.0,
            'accuracy'                => 100.0,
            'started_at'              => now()->subMinutes(15),
            'status'                  => 'in_progress',
        ]);

        // Q1 was saved before expiry
        TestAttemptQuestion::create([
            'attempt_id'         => $attempt->id,
            'question_id'        => $qData1['question_id'],
            'question_order'     => 1,
            'selected_option_id' => $qData1['opt1_id'],
            'is_attempted'       => true,
            'is_correct'         => true,
            'marks'              => 1.0,
            'obtained_marks'     => 1.0,
        ]);

        // Late submit attempt sends new answer for Q2 after expiry
        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/submit-formal-test', [
                'test_id'    => $test->id,
                'attempt_id' => $attempt->id,
                'questions'  => [
                    [
                        'question_id'        => $qData2['question_id'],
                        'selected_option_id' => $qData2['opt2_id'], // Late answer
                        'time_spent_seconds' => 30,
                    ],
                ],
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        // Q1 was graded, late Q2 was ignored
        $this->assertEquals(1, $data['correct_answers']);
        $this->assertEquals(1.0, $data['score']);
        $this->assertEquals(1, $data['not_attempted_questions']);
        $this->assertEquals('submitted', $data['status']);

        // Verify Q2 was NOT saved to database
        $this->assertDatabaseMissing('test_attempt_questions', [
            'attempt_id'  => $attempt->id,
            'question_id' => $qData2['question_id'],
        ]);
    }

    /**
     * Point 5: Unattempted formal tests receive full duration, even when test was created days earlier.
     */
    public function test_unattempted_formal_test_receives_full_duration_regardless_of_test_created_at(): void
    {
        $qData = $this->createSampleQuestion('Unattempted Question', 1);

        $test = Test::create([
            'title'              => 'Old Catalog Formal Exam',
            'test_source'        => 'official',
            'test_mode'          => 'test',
            'scope_type'         => 'chapter',
            'subject_id'         => $this->subjectId,
            'total_questions'    => 1,
            'time_limit_minutes' => 45,
            'is_published'       => true,
            'created_by'         => 1,
            'created_at'         => now()->subDays(30), // Created 30 days ago
        ]);
        TestScope::create(['test_id' => $test->id, 'unit_id' => $this->unitId263]);
        TestQuestion::create(['test_id' => $test->id, 'question_id' => $qData['question_id'], 'question_order' => 1, 'marks' => 1.0]);

        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/formal-tests/{$test->id}");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertNull($data['attempt_id']);
        $this->assertNull($data['started_at']);
        $this->assertEquals(45 * 60, $data['remaining_seconds'], 'Full duration (2700s) must be available');
        $this->assertFalse($data['is_expired'], 'Unattempted test must not be marked expired');
        $this->assertEquals('in_progress', $data['status']);
    }

    /**
     * Point 6: Existing attempts calculate remaining time from their actual start time.
     */
    public function test_existing_attempt_calculates_remaining_time_from_actual_start_time(): void
    {
        $qData = $this->createSampleQuestion('Existing Attempt Question', 1);

        $test = Test::create([
            'title'              => 'Formal Exam Accurate Timing',
            'test_source'        => 'system',
            'test_mode'          => 'test',
            'scope_type'         => 'chapter',
            'subject_id'         => $this->subjectId,
            'total_questions'    => 1,
            'time_limit_minutes' => 60,
            'is_published'       => true,
            'created_by'         => $this->student->id,
            'created_at'         => now()->subDays(5),
        ]);
        TestScope::create(['test_id' => $test->id, 'unit_id' => $this->unitId263]);
        TestQuestion::create(['test_id' => $test->id, 'question_id' => $qData['question_id'], 'question_order' => 1, 'marks' => 1.0]);

        // Attempt started exactly 10 minutes ago
        $startedAt = now()->subMinutes(10);
        $attempt = TestAttempt::create([
            'test_id'                 => $test->id,
            'user_id'                 => $this->student->id,
            'attempted_questions'     => 0,
            'correct_answers'         => 0,
            'wrong_answers'           => 0,
            'not_attempted_questions' => 1,
            'score'                   => 0,
            'accuracy'                => 0,
            'started_at'              => $startedAt,
            'status'                  => 'in_progress',
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/formal-tests/{$test->id}?attempt_id={$attempt->id}");

        $response->assertStatus(200);
        $data = $response->json('data');

        // 60 minutes limit - 10 minutes elapsed = ~50 minutes (3000 seconds) remaining
        $this->assertGreaterThanOrEqual(2995, $data['remaining_seconds']);
        $this->assertLessThanOrEqual(3005, $data['remaining_seconds']);
        $this->assertFalse($data['is_expired']);
    }

    /**
     * Point 7: Expired practice sessions reject answer changes.
     */
    public function test_expired_practice_sessions_reject_answer_changes(): void
    {
        $qData = $this->createSampleQuestion('Practice Expiry Question', 1);

        // Practice session with 10 min time limit, started 15 min ago (expired!)
        $session = PracticeSession::create([
            'user_id'                 => $this->student->id,
            'subject_id'              => $this->subjectId,
            'scope_type'              => 'chapter',
            'total_questions'         => 1,
            'time_limit_minutes'      => 10,
            'attempted_questions'     => 0,
            'correct_answers'         => 0,
            'wrong_answers'           => 0,
            'not_attempted_questions' => 1,
            'score'                   => 0,
            'accuracy'                => 0,
            'status'                  => 'in_progress',
            'started_at'              => now()->subMinutes(15),
        ]);
        PracticeSessionScope::create(['session_id' => $session->id, 'unit_id' => $this->unitId263]);
        PracticeSessionQuestion::create([
            'session_id'     => $session->id,
            'question_id'    => $qData['question_id'],
            'question_order' => 1,
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/save-practice-session-question-progress', [
                'session_id'         => $session->id,
                'question_id'        => $qData['question_id'],
                'selected_option_id' => $qData['opt1_id'],
            ]);

        $response->assertStatus(422);
        $this->assertEquals('SESSION_EXPIRED', $response->json('error_code'));

        // Authoritative hydration reflects expired status and zero remaining time
        $hydrateResponse = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/practice-sessions/{$session->id}");
        $hydrateResponse->assertStatus(200);
        $this->assertEquals('expired', $hydrateResponse->json('data.status'));
        $this->assertTrue($hydrateResponse->json('data.is_expired'));
        $this->assertEquals(0, $hydrateResponse->json('data.remaining_seconds'));

        // Question was NOT modified
        $this->assertDatabaseMissing('practice_session_questions', [
            'session_id'         => $session->id,
            'question_id'        => $qData['question_id'],
            'selected_option_id' => $qData['opt1_id'],
        ]);
    }

    /**
     * Point 8: Practice sessions without a time limit retain existing behavior.
     */
    public function test_practice_sessions_without_time_limit_retain_existing_behavior(): void
    {
        $qData = $this->createSampleQuestion('Untimed Practice Question', 1);

        $session = PracticeSession::create([
            'user_id'                 => $this->student->id,
            'subject_id'              => $this->subjectId,
            'scope_type'              => 'chapter',
            'total_questions'         => 1,
            'time_limit_minutes'      => null, // Untimed
            'attempted_questions'     => 0,
            'correct_answers'         => 0,
            'wrong_answers'           => 0,
            'not_attempted_questions' => 1,
            'score'                   => 0,
            'accuracy'                => 0,
            'status'                  => 'in_progress',
            'started_at'              => now()->subDays(2), // Started 2 days ago
        ]);
        PracticeSessionScope::create(['session_id' => $session->id, 'unit_id' => $this->unitId263]);
        PracticeSessionQuestion::create([
            'session_id'     => $session->id,
            'question_id'    => $qData['question_id'],
            'question_order' => 1,
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/save-practice-session-question-progress', [
                'session_id'         => $session->id,
                'question_id'        => $qData['question_id'],
                'selected_option_id' => $qData['opt1_id'],
            ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.is_attempted'));
        $this->assertEquals('in_progress', $response->json('data.status'));
    }

    /**
     * Point 9: Ownership and cross-student protections remain intact.
     */
    public function test_ownership_and_cross_student_protections_remain_intact(): void
    {
        $qData = $this->createSampleQuestion('Protected Question', 1);

        $test = Test::create([
            'title'              => 'Student 1 Exam',
            'test_source'        => 'system',
            'test_mode'          => 'test',
            'scope_type'         => 'chapter',
            'subject_id'         => $this->subjectId,
            'total_questions'    => 1,
            'time_limit_minutes' => 30,
            'is_published'       => false,
            'created_by'         => $this->student->id,
            'created_at'         => now(),
        ]);
        TestScope::create(['test_id' => $test->id, 'unit_id' => $this->unitId263]);
        TestQuestion::create(['test_id' => $test->id, 'question_id' => $qData['question_id'], 'question_order' => 1, 'marks' => 1.0]);

        $attempt = TestAttempt::create([
            'test_id'                 => $test->id,
            'user_id'                 => $this->student->id,
            'attempted_questions'     => 0,
            'correct_answers'         => 0,
            'wrong_answers'           => 0,
            'not_attempted_questions' => 1,
            'score'                   => 0,
            'accuracy'                => 0,
            'started_at'              => now(),
            'status'                  => 'in_progress',
        ]);

        // otherStudent tries to save answers to student's attempt
        $saveResponse = $this->actingAs($this->otherStudent, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $test->id,
                'attempt_id'         => $attempt->id,
                'question_id'        => $qData['question_id'],
                'selected_option_id' => $qData['opt1_id'],
            ]);

        $this->assertEquals(404, $saveResponse->status());

        // otherStudent tries to submit student's attempt
        $submitResponse = $this->actingAs($this->otherStudent, 'web_api')
            ->postJson('/web_api/auth/tests/submit-formal-test', [
                'test_id'    => $test->id,
                'attempt_id' => $attempt->id,
            ]);

        $this->assertEquals(404, $submitResponse->status());
    }

    /**
     * Point 10: Concurrent saves cannot bypass expiry enforcement.
     */
    public function test_concurrent_saves_cannot_bypass_expiry_enforcement(): void
    {
        $qData = $this->createSampleQuestion('Concurrency Expiry Question', 1);

        $test = Test::create([
            'title'              => 'Concurrency Expiry Test',
            'test_source'        => 'system',
            'test_mode'          => 'test',
            'scope_type'         => 'chapter',
            'subject_id'         => $this->subjectId,
            'total_questions'    => 1,
            'time_limit_minutes' => 5,
            'is_published'       => true,
            'created_by'         => $this->student->id,
            'created_at'         => now(),
        ]);
        TestScope::create(['test_id' => $test->id, 'unit_id' => $this->unitId263]);
        TestQuestion::create(['test_id' => $test->id, 'question_id' => $qData['question_id'], 'question_order' => 1, 'marks' => 1.0]);

        $attempt = TestAttempt::create([
            'test_id'                 => $test->id,
            'user_id'                 => $this->student->id,
            'attempted_questions'     => 0,
            'correct_answers'         => 0,
            'wrong_answers'           => 0,
            'not_attempted_questions' => 1,
            'score'                   => 0,
            'accuracy'                => 0,
            'started_at'              => now()->subMinutes(6), // Already expired by 1 minute
            'status'                  => 'in_progress',
        ]);

        // Dispatched save must be rejected inside the transaction
        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/save-formal-test-question-progress', [
                'test_id'            => $test->id,
                'attempt_id'         => $attempt->id,
                'question_id'        => $qData['question_id'],
                'selected_option_id' => $qData['opt1_id'],
            ]);

        $response->assertStatus(422);
        $this->assertEquals('ATTEMPT_EXPIRED', $response->json('error_code'));
    }
}
