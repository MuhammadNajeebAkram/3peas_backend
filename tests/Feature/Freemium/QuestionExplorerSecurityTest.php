<?php

namespace Tests\Feature\Freemium;

use App\Http\Services\Freemium\UnifiedEntitlementResolver;
use App\Models\Book;
use App\Models\BookUnit;
use App\Models\BookUnitTopic;
use App\Models\ExamQuestion;
use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\UserSubscription;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Question Explorer Security and Access Test Suite (Phase 2A).
 *
 * Verifies that GET /web_api/auth/my-classes/get-questions-by-topic/{topic_id}/questions:
 *  - Strips correct answers (is_answer, answers relation) when perm_answers = 'deny'
 *  - Enforces question-type permissions (perm_mcqs, perm_short_questions, perm_long_questions)
 *  - Strips explanations when perm_explanations = 'deny'
 *  - Strips topic content when perm_topic_contents = 'deny'
 *  - Filters exercise and supplementary questions appropriately
 *  - Validates academic hierarchy and fails closed on invalid/unauthorized topic
 *  - Fully unlocks all attributes for paid students
 */
class QuestionExplorerSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private WebUser $student;
    private WebUser $paidStudent;
    private Carbon $now;

    private int $boardId = 2; // Federal Board
    private int $classId = 1; // 9th
    private int $subjectId = 5; // Biology
    private int $bookId = 28; // Federal Biology 9th
    private int $unitId263 = 263; // THE SCIENCE OF BIOLOGY (Authorized)
    private int $unitId264 = 264; // MOLECULAR BIOLOGY (Unauthorized)
    private int $topicId263 = 1342; // Topic under Unit 263
    private int $topicId264 = 1351; // Topic under Unit 264

    private FreemiumCampaign $campaign;
    private FreemiumAccessRule $rule;

    protected function setUp(): void
    {
        parent::setUp();

        $activeDb = DB::selectOne("SELECT DATABASE() as db")->db ?? '';
        if ($activeDb !== '3peas_db_subscription_test' && $activeDb !== '3peas_db') {
            $this->markTestSkipped("Safety gate: test only runs against local databases.");
        }

        $this->withoutMiddleware([
            \App\Http\Middleware\AttachJwtFromCookie::class,
            \App\Http\Middleware\AuthenticateJwtCookieGuard::class,
        ]);

        $this->now = Carbon::parse('2026-11-21 12:00:00', 'UTC');

        // Seed academic hierarchy
        DB::table('curriculum_board_tbl')->updateOrInsert(['id' => $this->boardId], ['name' => 'Federal Board', 'activate' => 1]);
        DB::table('class_tbl')->updateOrInsert(['id' => $this->classId], ['class_name' => 'Class 9th', 'activate' => 1]);
        DB::table('subject_tbl')->updateOrInsert(['id' => $this->subjectId], ['subject_name' => 'Biology', 'icon_name' => 'bio.png', 'activate' => 1]);

        DB::table('book_tbl')->updateOrInsert(['id' => $this->bookId], [
            'book_name'           => 'Federal Biology 9th',
            'class_id'            => $this->classId,
            'subject_id'          => $this->subjectId,
            'curriculum_board_id' => $this->boardId,
            'activate'            => 1,
        ]);

        DB::table('book_unit_tbl')->updateOrInsert(['id' => $this->unitId263], [
            'unit_name' => 'THE SCIENCE OF BIOLOGY',
            'unit_no'   => 1,
            'book_id'   => $this->bookId,
            'activate'  => 1,
        ]);

        DB::table('book_unit_tbl')->updateOrInsert(['id' => $this->unitId264], [
            'unit_name' => 'CELL BIOLOGY',
            'unit_no'   => 2,
            'book_id'   => $this->bookId,
            'activate'  => 1,
        ]);

        DB::table('book_unit_topic_tbl')->updateOrInsert(['id' => $this->topicId263], [
            'topic_name' => 'Introduction to Biology',
            'topic_no'   => 1,
            'unit_id'    => $this->unitId263,
            'activate'   => 1,
        ]);

        DB::table('book_unit_topic_tbl')->updateOrInsert(['id' => $this->topicId264], [
            'topic_name' => 'Introduction to Cell Biology',
            'topic_no'   => 1,
            'unit_id'    => $this->unitId264,
            'activate'   => 1,
        ]);

        // Questions for Topic 1342 (Unit 263)
        DB::table('exam_question_tbl')->updateOrInsert(['id' => 16609], [
            'question'          => '<p>What is Biology?</p>',
            'topic_id'          => $this->topicId263,
            'question_type'     => 1,
            'is_mcq'            => 1,
            'exercise_question' => 1,
            'activate'          => 1,
            'explanation'       => 'Biology is the study of life.',
            'topic_content'     => 1,
        ]);

        DB::table('exam_question_tbl')->updateOrInsert(['id' => 16610], [
            'question'          => '<p>What is Zoology?</p>',
            'topic_id'          => $this->topicId263,
            'question_type'     => 1,
            'is_mcq'            => 1,
            'exercise_question' => 0,
            'activate'          => 1,
            'explanation'       => 'Zoology is the study of animals.',
            'topic_content'     => 1,
        ]);

        // Questions for Topic 1351 (Unit 264)
        DB::table('exam_question_tbl')->updateOrInsert(['id' => 17031], [
            'question'          => '<p>What is Cell Theory?</p>',
            'topic_id'          => $this->topicId264,
            'question_type'     => 1,
            'is_mcq'            => 1,
            'exercise_question' => 1,
            'activate'          => 1,
            'explanation'       => 'Cells are basic units.',
            'topic_content'     => 1,
        ]);

        // Answer options and answers for 16609
        DB::table('exam_question_options_tbl')->updateOrInsert(['id' => 50001], [
            'question_id' => 16609,
            'option'      => 'Study of life',
            'is_answer'   => 1,
        ]);
        DB::table('exam_question_options_tbl')->updateOrInsert(['id' => 50002], [
            'question_id' => 16609,
            'option'      => 'Study of rocks',
            'is_answer'   => 0,
        ]);
        DB::table('exam_answer_tbl')->updateOrInsert(['id' => 50001], [
            'question_id' => 16609,
            'answer'      => 'Study of life',
        ]);

        // Options and answers for 17031
        DB::table('exam_question_options_tbl')->updateOrInsert(['id' => 50003], [
            'question_id' => 17031,
            'option'      => 'Basic unit of life',
            'is_answer'   => 1,
        ]);
        DB::table('exam_answer_tbl')->updateOrInsert(['id' => 50002], [
            'question_id' => 17031,
            'answer'      => 'Basic unit of life',
        ]);

        // Clean any residual campaign rules to have full control in tests
        DB::table('freemium_access_rules')->delete();
        DB::table('freemium_student_grants')->delete();
        DB::table('freemium_campaigns')->delete();

        $this->campaign = FreemiumCampaign::create([
            'name'                 => 'Free Federal Biology Class 9th',
            'status'               => 'active',
            'priority'             => 100,
            'start_at'             => $this->now->copy()->subDays(5),
            'end_at'               => $this->now->copy()->addYears(2),
            'is_stackable'         => false,
            'eligibility_criteria' => null,
            'created_by'           => 1,
            'updated_by'           => 1,
        ]);

        // Rule for Chapter 263: perm_mcqs = allow, others inherit/deny
        $this->rule = FreemiumAccessRule::create([
            'campaign_id'              => $this->campaign->id,
            'target_scope'             => 'chapter',
            'target_status'            => 'active',
            'curriculum_board_id'      => $this->boardId,
            'class_id'                 => $this->classId,
            'subject_id'               => $this->subjectId,
            'book_id'                  => $this->bookId,
            'chapter_id'               => $this->unitId263,
            'perm_mcqs'                => 'allow',
            'perm_short_questions'     => 'deny',
            'perm_long_questions'      => 'deny',
            'perm_exercise_questions'  => 'allow',
            'perm_supplementary_questions' => 'allow',
            'perm_answers'             => 'deny', // Default test state: answers denied
            'perm_explanations'        => 'deny', // Default test state: explanations denied
            'perm_topic_contents'      => 'deny', // Default test state: topic contents denied
            'rule_priority'            => 10,
            'academic_target_snapshot' => [
                'board_id'   => $this->boardId,
                'class_id'   => $this->classId,
                'subject_id' => $this->subjectId,
                'book_id'    => $this->bookId,
                'chapter_id' => $this->unitId263,
            ],
        ]);

        $this->student = WebUser::create([
            'name'     => 'Explorer Free Student',
            'email'    => 'explorer_free_' . uniqid() . '@example.com',
            'role'     => 'student',
            'status'   => 'active',
            'password' => bcrypt('password123'),
        ]);

        $this->paidStudent = WebUser::create([
            'name'     => 'Explorer Paid Student',
            'email'    => 'explorer_paid_' . uniqid() . '@example.com',
            'role'     => 'student',
            'status'   => 'active',
            'password' => bcrypt('password123'),
        ]);

        UserSubscription::create([
            'user_id'            => $this->paidStudent->id,
            'offered_program_id' => 994,
            'start_date'         => $this->now->copy()->subDays(1),
            'end_date'           => $this->now->copy()->addMonths(6),
            'status'             => 'active',
        ]);

        DB::table('program_subjects')->updateOrInsert(
            ['offered_program_id' => 994, 'subject_id' => $this->subjectId],
            ['is_free' => 0, 'is_active' => 1, 'updated_at' => now()]
        );

        DB::table('offered_classes')->updateOrInsert(['id' => 991], [
            'class_id'            => $this->classId,
            'curriculum_board_id' => $this->boardId,
            'is_active'           => 1,
        ]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson("/web_api/auth/my-classes/get-questions-by-topic/{$this->topicId263}/questions");
        $this->assertTrue(in_array($response->status(), [401, 403]));
    }

    public function test_non_existent_topic_returns_404(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/my-classes/get-questions-by-topic/999999/questions");

        $response->assertStatus(404);
        $this->assertSame(0, $response->json('success'));
    }

    public function test_hierarchy_mismatch_returns_422(): void
    {
        // Topic 1342 belongs to unit 263, book 28, subject 5. Passing mismatching unit_id:
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/my-classes/get-questions-by-topic/{$this->topicId263}/questions?unit_id=999");

        $response->assertStatus(422);
        $this->assertStringContainsString('Hierarchy mismatch', $response->json('message'));
    }

    public function test_unauthorized_unit_topic_is_denied(): void
    {
        // Topic 1351 is in Unit 264 (MOLECULAR BIOLOGY), which is NOT in Campaign #2
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/my-classes/get-questions-by-topic/{$this->topicId264}/questions");

        $response->assertStatus(403);
        $this->assertSame('ACCESS_DENIED', $response->json('code'));
    }

    public function test_correct_answers_stripped_when_perm_answers_is_denied(): void
    {
        // Campaign rule #1 has perm_answers = 'deny'
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/my-classes/get-questions-by-topic/{$this->topicId263}/questions");

        $response->assertStatus(200);
        $questions = $response->json();
        $this->assertIsArray($questions);
        $this->assertNotEmpty($questions);

        foreach ($questions as $q) {
            // answers relationship must be empty
            $this->assertEmpty($q['answers'], "Question {$q['id']} leaked answers relation.");

            // answer_options must not contain is_answer
            $options = $q['answer_options'] ?? $q['answerOptions'] ?? [];
            foreach ($options as $opt) {
                $this->assertArrayNotHasKey('is_answer', $opt, "Question {$q['id']} leaked is_answer in answer options.");
            }
        }
    }

    public function test_correct_answers_exposed_when_perm_answers_is_allowed(): void
    {
        $this->rule->update(['perm_answers' => 'allow']);

        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/my-classes/get-questions-by-topic/{$this->topicId263}/questions");

        $response->assertStatus(200);
        $questions = $response->json();
        $this->assertNotEmpty($questions);

        $hasFoundAnswer = false;
        foreach ($questions as $q) {
            $options = $q['answer_options'] ?? $q['answerOptions'] ?? [];
            foreach ($options as $opt) {
                if (array_key_exists('is_answer', $opt) && $opt['is_answer'] == 1) {
                    $hasFoundAnswer = true;
                    break 2;
                }
            }
        }
        $this->assertTrue($hasFoundAnswer, "Expected is_answer to be present when perm_answers is allow.");
    }

    public function test_explanations_hidden_when_perm_explanations_is_denied(): void
    {
        // Rule has perm_explanations = 'deny'
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/my-classes/get-questions-by-topic/{$this->topicId263}/questions");

        $response->assertStatus(200);
        $questions = $response->json();
        $this->assertNotEmpty($questions);

        foreach ($questions as $q) {
            $this->assertTrue(empty($q['explanation']), "Question {$q['id']} leaked explanation.");
            $this->assertTrue(empty($q['explanation_um']), "Question {$q['id']} leaked Urdu explanation.");
            $this->assertTrue(empty($q['explanation_video_url']), "Question {$q['id']} leaked explanation video.");
        }
    }

    public function test_topic_content_hidden_when_perm_topic_contents_is_denied(): void
    {
        // Rule has perm_topic_contents = 'deny'
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/my-classes/get-questions-by-topic/{$this->topicId263}/questions");

        $response->assertStatus(200);
        $questions = $response->json();
        $this->assertNotEmpty($questions);

        foreach ($questions as $q) {
            $this->assertTrue(empty($q['topic_content']), "Question {$q['id']} leaked topic_content.");
        }
    }

    public function test_question_type_permissions_enforced(): void
    {
        // Rule allows MCQs, denies short questions and long questions
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/my-classes/get-questions-by-topic/{$this->topicId263}/questions");

        $response->assertStatus(200);
        $questions = $response->json();
        $this->assertNotEmpty($questions);

        foreach ($questions as $q) {
            // Must only be MCQs
            $this->assertTrue((int) $q['is_mcq'] === 1 || (int) $q['question_type'] === 1, "Exposed non-MCQ question.");
        }

        // Explicit request for short questions (type 2) must be rejected with 403
        $shortResponse = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/my-classes/get-questions-by-topic/{$this->topicId263}/questions?question_type=2");

        $shortResponse->assertStatus(403);
        $this->assertSame('ACCESS_DENIED', $shortResponse->json('code'));
    }

    public function test_paid_student_has_full_unrestricted_access(): void
    {
        // Paid student accessing topic 1351 in Unit 264
        $response = $this->actingAs($this->paidStudent, 'web_api')
            ->getJson("/web_api/auth/my-classes/get-questions-by-topic/{$this->topicId264}/questions");

        $response->assertStatus(200);
        $questions = $response->json();
        $this->assertIsArray($questions);
        $this->assertNotEmpty($questions);

        // Paid student gets answer keys
        $hasFoundAnswer = false;
        foreach ($questions as $q) {
            $options = $q['answer_options'] ?? $q['answerOptions'] ?? [];
            foreach ($options as $opt) {
                if (array_key_exists('is_answer', $opt) && $opt['is_answer'] == 1) {
                    $hasFoundAnswer = true;
                    break 2;
                }
            }
        }
        $this->assertTrue($hasFoundAnswer, "Paid student should see correct answers.");
    }
}
