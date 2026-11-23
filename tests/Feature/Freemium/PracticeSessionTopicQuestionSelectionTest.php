<?php

namespace Tests\Feature\Freemium;

use App\Models\ExamQuestion;
use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumExamQuota;
use App\Models\Freemium\FreemiumQuotaConsumption;
use App\Models\PracticeSession;
use App\Models\PracticeSessionQuestion;
use App\Models\UserSubscription;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Practice Session Topic and Question Selection Test Suite (Phase 2B & 2C).
 *
 * Verifies that POST /web_api/auth/tests/generate-selected-units-practice-session:
 *  - Supports entire authorized chapter (unit_ids: [263])
 *  - Supports selected topics within authorized chapter (topic_ids: [1342])
 *  - Supports individually selected authorized questions (question_ids: [16609, 16610])
 *  - Enforces DB hierarchy: rejects topics/questions not belonging to unit/subject
 *  - Evaluates student entitlement: fails closed if any selected topic or question is unauthorized
 *  - Fails closed on mixed selections containing any unauthorized item
 *  - Includes topic_ids and question_ids in idempotency fingerprint
 *  - Prevents double quota consumption on idempotent replay
 *  - Rejects altered topic/question payload under same idempotency key with HTTP 409
 *  - Fully allows paid students unrestricted selections
 */
class PracticeSessionTopicQuestionSelectionTest extends TestCase
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
    private int $questionId263_1 = 16609; // MCQ under Topic 1342, Unit 263
    private int $questionId263_2 = 16610; // MCQ under Topic 1342, Unit 263
    private int $questionId264 = 17031; // MCQ under Topic 1351, Unit 264

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

        DB::table('book_unit_topic_tbl')->updateOrInsert(['id' => 1343], [
            'topic_name' => 'Divisions and Branches of Biology',
            'topic_no'   => 2,
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
        ]);

        DB::table('exam_question_tbl')->updateOrInsert(['id' => 16610], [
            'question'          => '<p>What is Zoology?</p>',
            'topic_id'          => $this->topicId263,
            'question_type'     => 1,
            'is_mcq'            => 1,
            'exercise_question' => 0,
            'activate'          => 1,
        ]);

        DB::table('exam_question_tbl')->updateOrInsert(['id' => 16611], [
            'question'          => '<p>What is Botany?</p>',
            'topic_id'          => $this->topicId263,
            'question_type'     => 1,
            'is_mcq'            => 1,
            'exercise_question' => 1,
            'activate'          => 1,
        ]);

        // Question for Topic 1351 (Unit 264)
        DB::table('exam_question_tbl')->updateOrInsert(['id' => 17031], [
            'question'          => '<p>What is Cell Theory?</p>',
            'topic_id'          => $this->topicId264,
            'question_type'     => 1,
            'is_mcq'            => 1,
            'exercise_question' => 1,
            'activate'          => 1,
        ]);

        // Clean any residual campaign rules to have full control in tests
        DB::table('freemium_access_rules')->delete();
        DB::table('freemium_student_grants')->delete();
        DB::table('freemium_quota_consumptions')->delete();
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

        // Rule for Chapter 263: perm_mcqs = allow
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
            'perm_short_questions'     => 'allow',
            'perm_long_questions'      => 'allow',
            'perm_exercise_questions'  => 'allow',
            'perm_supplementary_questions' => 'allow',
            'perm_answers'             => 'allow',
            'perm_explanations'        => 'allow',
            'perm_topic_contents'      => 'allow',
            'rule_priority'            => 10,
            'academic_target_snapshot' => [
                'board_id'   => $this->boardId,
                'class_id'   => $this->classId,
                'subject_id' => $this->subjectId,
                'book_id'    => $this->bookId,
                'chapter_id' => $this->unitId263,
            ],
        ]);

        // Campaign Quota for practice: 10 allowed
        FreemiumExamQuota::updateOrCreate(
            [
                'campaign_id' => $this->campaign->id,
                'exam_type'   => 'practice',
            ],
            [
                'max_attempts'       => 10,
                'quota_window_hours' => null,
                'allow_review'       => true,
            ]
        );

        $this->student = WebUser::create([
            'name'     => 'Practice Free Student',
            'email'    => 'practice_free_' . uniqid() . '@example.com',
            'role'     => 'student',
            'status'   => 'active',
            'password' => bcrypt('password123'),
        ]);

        $this->paidStudent = WebUser::create([
            'name'     => 'Practice Paid Student',
            'email'    => 'practice_paid_' . uniqid() . '@example.com',
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

    public function test_entire_authorized_chapter_practice_generation(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'entire_ch_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 2,
                'mcq_format'      => 'all',
            ]);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('success'));
        $data = $response->json('data');
        $this->assertNotEmpty($data['session_id']);
        $this->assertSame([$this->unitId263], $data['unit_ids']);
        $this->assertCount(2, $data['question_ids']);
    }

    public function test_selected_topics_practice_generation(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'topics_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'topic_ids'       => [$this->topicId263],
                'total_questions' => 2,
                'mcq_format'      => 'all',
            ]);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('success'));
        $data = $response->json('data');
        $this->assertSame([$this->topicId263], $data['topic_ids']);
        $this->assertCount(2, $data['question_ids']);

        // Verify generated questions belong to topic 1342
        $questions = ExamQuestion::whereIn('id', $data['question_ids'])->get();
        foreach ($questions as $q) {
            $this->assertSame($this->topicId263, (int) $q->topic_id);
        }
    }

    public function test_selected_questions_practice_generation(): void
    {
        $requestedQuestions = [$this->questionId263_1, $this->questionId263_2];

        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'questions_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'question_ids'    => $requestedQuestions,
                'total_questions' => 2,
                'mcq_format'      => 'all',
            ]);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('success'));
        $data = $response->json('data');
        $this->assertSame($requestedQuestions, $data['question_ids']);
    }

    public function test_unauthorized_unit_is_rejected_with_403(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'unauth_unit_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId264], // Unauthorized Unit 264
                'total_questions' => 2,
            ]);

        $response->assertStatus(403);
        $this->assertSame('ACCESS_DENIED', $response->json('code'));
    }

    public function test_topic_hierarchy_mismatch_is_rejected_with_422(): void
    {
        // Topic 1351 belongs to Unit 264, not Unit 263
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'mismatch_topic_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'topic_ids'       => [$this->topicId264],
                'total_questions' => 2,
            ]);

        $response->assertStatus(422);
        $msg = $response->json('error') ?? $response->json('message') ?? '';
        $this->assertStringContainsString('belong to the selected units', $msg);
    }

    public function test_question_hierarchy_mismatch_is_rejected_with_422(): void
    {
        // Question 17031 belongs to Unit 264, not Unit 263
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'mismatch_q_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'question_ids'    => [$this->questionId264],
                'total_questions' => 1,
            ]);

        $response->assertStatus(422);
        $msg = $response->json('error') ?? $response->json('message') ?? '';
        $this->assertStringContainsString('belong to the selected units', $msg);
    }

    public function test_mixed_topics_fails_closed(): void
    {
        // Mixing topics across units where one is unauthorized
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'mixed_t_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263, $this->unitId264],
                'topic_ids'       => [$this->topicId263, $this->topicId264],
                'total_questions' => 2,
            ]);

        // Fails closed with 403 because Unit 264 / Topic 1351 is unauthorized
        $response->assertStatus(403);
        $this->assertSame('ACCESS_DENIED', $response->json('code'));
    }

    public function test_mixed_questions_fails_closed(): void
    {
        // Mixing questions where one is unauthorized
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'mixed_q_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263, $this->unitId264],
                'question_ids'    => [$this->questionId263_1, $this->questionId264],
                'total_questions' => 2,
            ]);

        $response->assertStatus(403);
        $this->assertSame('ACCESS_DENIED', $response->json('code'));
    }

    public function test_idempotency_fingerprint_and_quota_consumption(): void
    {
        $idempotencyKey = 'idemp-' . uniqid();

        $payload = [
            'subject_id'      => $this->subjectId,
            'book_id'         => $this->bookId,
            'unit_ids'        => [$this->unitId263],
            'topic_ids'       => [$this->topicId263],
            'total_questions' => 2,
            'mcq_format'      => 'all',
        ];

        // First call creates session and records quota consumption
        $response1 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', $payload);

        $response1->assertStatus(200);
        $sessionId1 = $response1->json('data.session_id');

        $consumptionsCount = FreemiumQuotaConsumption::where('user_id', $this->student->id)
            ->where('idempotency_key', $idempotencyKey)
            ->count();
        $this->assertSame(1, $consumptionsCount, "Initial generation must consume 1 quota entry.");

        // Idempotent retry with identical key and payload: must replay response without double-consuming quota
        $response2 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', $payload);

        $response2->assertStatus(200);
        $this->assertSame($sessionId1, $response2->json('data.session_id'));

        $consumptionsCountAfterRetry = FreemiumQuotaConsumption::where('user_id', $this->student->id)
            ->where('idempotency_key', $idempotencyKey)
            ->count();
        $this->assertSame(1, $consumptionsCountAfterRetry, "Idempotent replay must not consume quota again.");

        // Altering payload under the SAME idempotency key must return HTTP 409 Conflict
        $alteredPayload = $payload;
        $alteredPayload['topic_ids'] = [$this->topicId263, 1343]; // Different topic selection

        $conflictResponse = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', $alteredPayload);

        $conflictResponse->assertStatus(409);
        $this->assertSame(0, $conflictResponse->json('success'));
    }

    public function test_paid_student_has_full_practice_access(): void
    {
        // Paid student can generate practice on Unit 264 with topic 1351
        $response = $this->actingAs($this->paidStudent, 'web_api')
            ->withHeader('X-Idempotency-Key', 'paid_prac_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'offered_program_id' => 994,
                'unit_ids'           => [$this->unitId264],
                'topic_ids'          => [$this->topicId264],
                'total_questions'    => 1,
                'mcq_format'         => 'all',
            ]);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('success'));
        $data = $response->json('data');
        $this->assertNotEmpty($data['session_id']);
        $this->assertSame([$this->topicId264], $data['topic_ids']);
    }
}
