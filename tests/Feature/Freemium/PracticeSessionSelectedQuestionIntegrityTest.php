<?php

namespace Tests\Feature\Freemium;

use App\Http\Services\Freemium\AssessmentIdempotencyService;
use App\Models\ExamQuestion;
use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumExamQuota;
use App\Models\PracticeSession;
use App\Models\PracticeSessionQuestion;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature Test Suite for Milestone C5-C1:
 * Backend Integrity and Idempotency Corrections for Selected-Question Practice.
 *
 * Covers:
 * - Scenario Group Integrity (complete 4-question, 1/2/3 partial rejected, contiguity, ordering)
 * - Question ID Integrity (existence, academic scope, accessibility, duplicates, rollback safety)
 * - Order-Sensitive Idempotency Fingerprints
 * - Backward Compatibility & Regression Checks
 */
class PracticeSessionSelectedQuestionIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    private WebUser $student;
    private WebUser $paidStudent;
    private Carbon $now;

    private int $boardId = 2;
    private int $classId = 1;
    private int $subjectId = 5;
    private int $bookId = 28;
    private int $unitId263 = 263; // Authorized
    private int $unitId264 = 264; // Unauthorized
    private int $topicId263 = 1342;
    private int $topicId264 = 1351;

    // Straight Questions
    private int $straightQ1 = 16609;
    private int $straightQ2 = 16610;

    // Scenario Group 101 Questions (Active, 4 members)
    private int $sg101_Q1 = 18001;
    private int $sg101_Q2 = 18002;
    private int $sg101_Q3 = 18003;
    private int $sg101_Q4 = 18004;

    // Scenario Group 102 Questions (Active, 4 members)
    private int $sg102_Q1 = 18005;
    private int $sg102_Q2 = 18006;
    private int $sg102_Q3 = 18007;
    private int $sg102_Q4 = 18008;

    // Scenario Group 103 (Inactive / Incomplete in DB)
    private int $sg103 = 103;
    private int $sg103_Q1 = 18009;
    private int $sg103_Q2 = 18010;

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

        $this->now = Carbon::parse('2026-11-23 12:00:00', 'UTC');

        // Seed Academic Hierarchy
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
            'topic_name' => 'Cell Structure',
            'topic_no'   => 1,
            'unit_id'    => $this->unitId264,
            'activate'   => 1,
        ]);

        // Seed Straight Questions
        DB::table('exam_question_tbl')->updateOrInsert(['id' => $this->straightQ1], [
            'question'          => '<p>Straight Question 1</p>',
            'topic_id'          => $this->topicId263,
            'question_type'     => 1,
            'is_mcq'            => 1,
            'exercise_question' => 1,
            'scenario_group_id' => null,
            'activate'          => 1,
        ]);

        DB::table('exam_question_tbl')->updateOrInsert(['id' => $this->straightQ2], [
            'question'          => '<p>Straight Question 2</p>',
            'topic_id'          => $this->topicId263,
            'question_type'     => 1,
            'is_mcq'            => 1,
            'exercise_question' => 0,
            'scenario_group_id' => null,
            'activate'          => 1,
        ]);

        // Question under Unauthorized Unit 264
        DB::table('exam_question_tbl')->updateOrInsert(['id' => 17031], [
            'question'          => '<p>Unauthorized Unit 264 Question</p>',
            'topic_id'          => $this->topicId264,
            'question_type'     => 1,
            'is_mcq'            => 1,
            'exercise_question' => 1,
            'scenario_group_id' => null,
            'activate'          => 1,
        ]);

        // Seed Scenario Group 101 (4 Questions, Order 1..4)
        DB::table('question_scenario_groups_tbl')->updateOrInsert(['id' => 101], [
            'title'            => 'Enzyme Kinetics Scenario',
            'scenario_text'    => 'An experiment was conducted on enzyme kinetics.',
            'scenario_text_um' => null,
            'scenario_image'   => null,
            'activate'         => 1,
        ]);

        for ($i = 1; $i <= 4; $i++) {
            DB::table('exam_question_tbl')->updateOrInsert(['id' => 18000 + $i], [
                'question'                => "<p>Enzyme Scenario Q$i</p>",
                'topic_id'                => $this->topicId263,
                'question_type'           => 1,
                'is_mcq'                  => 1,
                'exercise_question'       => 1,
                'scenario_group_id'       => 101,
                'scenario_question_order' => $i,
                'activate'                => 1,
            ]);
        }

        // Seed Scenario Group 102 (4 Questions, Order 1..4)
        DB::table('question_scenario_groups_tbl')->updateOrInsert(['id' => 102], [
            'title'            => 'Photosynthesis Scenario',
            'scenario_text'    => 'Photosynthesis experiment with light variables.',
            'scenario_text_um' => null,
            'scenario_image'   => null,
            'activate'         => 1,
        ]);

        for ($i = 5; $i <= 8; $i++) {
            DB::table('exam_question_tbl')->updateOrInsert(['id' => 18000 + $i], [
                'question'                => "<p>Photosynthesis Scenario Q$i</p>",
                'topic_id'                => $this->topicId263,
                'question_type'           => 1,
                'is_mcq'                  => 1,
                'exercise_question'       => 1,
                'scenario_group_id'       => 102,
                'scenario_question_order' => $i - 4,
                'activate'                => 1,
            ]);
        }

        // Seed Incomplete Scenario Group 103 (only 2 questions in DB)
        DB::table('question_scenario_groups_tbl')->updateOrInsert(['id' => $this->sg103], [
            'title'            => 'Incomplete Scenario',
            'scenario_text'    => 'Incomplete experiment.',
            'scenario_text_um' => null,
            'scenario_image'   => null,
            'activate'         => 1,
        ]);

        for ($i = 9; $i <= 10; $i++) {
            DB::table('exam_question_tbl')->updateOrInsert(['id' => 18000 + $i], [
                'question'                => "<p>Incomplete Scenario Q$i</p>",
                'topic_id'                => $this->topicId263,
                'question_type'           => 1,
                'is_mcq'                  => 1,
                'exercise_question'       => 1,
                'scenario_group_id'       => $this->sg103,
                'scenario_question_order' => $i - 8,
                'activate'                => 1,
            ]);
        }

        // Clean any residual campaign rules
        DB::table('freemium_access_rules')->delete();
        DB::table('freemium_student_grants')->delete();
        DB::table('freemium_quota_consumptions')->delete();
        DB::table('freemium_campaigns')->delete();

        // Campaign & Rules Setup
        $this->campaign = FreemiumCampaign::create([
            'name'                 => 'C5-C1 Integrity Campaign',
            'status'               => 'active',
            'priority'             => 100,
            'start_at'             => $this->now->copy()->subDays(5),
            'end_at'               => $this->now->copy()->addYears(2),
            'is_stackable'         => false,
            'eligibility_criteria' => null,
            'created_by'           => 1,
            'updated_by'           => 1,
        ]);

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

        FreemiumExamQuota::create([
            'campaign_id'  => $this->campaign->id,
            'exam_type'    => 'practice',
            'max_attempts' => 50,
            'allow_review' => true,
        ]);

        $this->student = WebUser::create([
            'name'     => 'C5C1 Student',
            'email'    => 'c5c1_student_' . uniqid() . '@example.com',
            'role'     => 'student',
            'status'   => 'active',
            'password' => bcrypt('secret123'),
        ]);

        $this->paidStudent = WebUser::create([
            'name'     => 'C5C1 Paid Student',
            'email'    => 'c5c1_paid_' . uniqid() . '@example.com',
            'role'     => 'student',
            'status'   => 'active',
            'password' => bcrypt('secret123'),
        ]);
    }

    // ========================================================================
    // Correction A: Scenario Group Integrity Tests
    // ========================================================================

    public function test_complete_four_question_scenario_group_accepted(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'sg_complete_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'question_ids'    => [$this->sg101_Q1, $this->sg101_Q2, $this->sg101_Q3, $this->sg101_Q4],
                'total_questions' => 4,
                'mcq_format'      => 'scenario',
            ]);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('success'));
        $data = $response->json('data');
        $this->assertSame([$this->sg101_Q1, $this->sg101_Q2, $this->sg101_Q3, $this->sg101_Q4], $data['question_ids']);
        $this->assertCount(1, $data['scenario_blocks']);
        $this->assertSame(101, $data['scenario_blocks'][0]['scenario_group_id']);
    }

    public function test_one_question_partial_scenario_group_rejected_with_422(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'sg_partial1_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'question_ids'    => [$this->sg101_Q1], // Only 1 question
                'total_questions' => 1,
                'mcq_format'      => 'all',
            ]);

        $response->assertStatus(422);
        $this->assertSame(0, $response->json('success'));
        $this->assertSame('INCOMPLETE_SCENARIO_GROUP', $response->json('error_code'));
    }

    public function test_two_question_partial_scenario_group_rejected_with_422(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'sg_partial2_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'question_ids'    => [$this->sg101_Q1, $this->sg101_Q2], // 2 questions
                'total_questions' => 2,
                'mcq_format'      => 'all',
            ]);

        $response->assertStatus(422);
        $this->assertSame(0, $response->json('success'));
        $this->assertSame('INCOMPLETE_SCENARIO_GROUP', $response->json('error_code'));
    }

    public function test_three_question_partial_scenario_group_rejected_with_422(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'sg_partial3_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'question_ids'    => [$this->sg101_Q1, $this->sg101_Q2, $this->sg101_Q3], // 3 questions
                'total_questions' => 3,
                'mcq_format'      => 'all',
            ]);

        $response->assertStatus(422);
        $this->assertSame(0, $response->json('success'));
        $this->assertSame('INCOMPLETE_SCENARIO_GROUP', $response->json('error_code'));
    }

    public function test_mixed_valid_and_incomplete_scenario_groups_rejected_with_422(): void
    {
        // 4 questions from Group 101 (complete) + 2 questions from Group 102 (incomplete)
        $qids = [$this->sg101_Q1, $this->sg101_Q2, $this->sg101_Q3, $this->sg101_Q4, $this->sg102_Q1, $this->sg102_Q2];

        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'sg_mixed_incomp_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'question_ids'    => $qids,
                'total_questions' => 6,
                'mcq_format'      => 'all',
            ]);

        $response->assertStatus(422);
        $this->assertSame('INCOMPLETE_SCENARIO_GROUP', $response->json('error_code'));
    }

    public function test_invalid_or_incomplete_db_group_rejected_with_422(): void
    {
        // Group 103 in DB only has 2 active questions
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'sg_db_incomp_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'question_ids'    => [$this->sg103_Q1, $this->sg103_Q2],
                'total_questions' => 2,
                'mcq_format'      => 'all',
            ]);

        $response->assertStatus(422);
        $this->assertSame('INCOMPLETE_SCENARIO_GROUP', $response->json('error_code'));
    }

    public function test_split_interleaved_scenario_group_rejected_with_422(): void
    {
        // Group 101 questions split by a straight MCQ in between
        $qids = [$this->sg101_Q1, $this->sg101_Q2, $this->straightQ1, $this->sg101_Q3, $this->sg101_Q4];

        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'sg_split_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'question_ids'    => $qids,
                'total_questions' => 5,
                'mcq_format'      => 'all',
            ]);

        $response->assertStatus(422);
        $this->assertSame('SPLIT_SCENARIO_GROUP', $response->json('error_code'));
    }

    public function test_out_of_order_scenario_questions_rejected_with_422(): void
    {
        // Group 101 questions provided in reverse order [Q4, Q3, Q2, Q1]
        $qids = [$this->sg101_Q4, $this->sg101_Q3, $this->sg101_Q2, $this->sg101_Q1];

        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'sg_reorder_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'question_ids'    => $qids,
                'total_questions' => 4,
                'mcq_format'      => 'scenario',
            ]);

        $response->assertStatus(422);
        $this->assertSame('INVALID_SCENARIO_QUESTION_ORDER', $response->json('error_code'));
    }

    // ========================================================================
    // Correction B: Question ID Integrity & Rollback Safety Tests
    // ========================================================================

    public function test_all_valid_eligible_ids_accepted(): void
    {
        $qids = [$this->straightQ1, $this->straightQ2];

        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'valid_ids_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'question_ids'    => $qids,
                'total_questions' => 2,
                'mcq_format'      => 'straight',
            ]);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('success'));
        $this->assertSame($qids, $response->json('data.question_ids'));
    }

    public function test_nonexistent_question_id_causes_422_rejection(): void
    {
        $nonExistentId = 9999999;

        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'nonexist_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'question_ids'    => [$this->straightQ1, $nonExistentId],
                'total_questions' => 2,
            ]);

        $response->assertStatus(422);
    }

    public function test_out_of_scope_unit_question_id_causes_422_rejection(): void
    {
        // 17031 belongs to unit 264, while unit 263 is requested
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'outofscope_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'question_ids'    => [$this->straightQ1, 17031],
                'total_questions' => 2,
            ]);

        $response->assertStatus(422);
        $this->assertSame('HIERARCHY_MISMATCH', $response->json('error_code'));
    }

    public function test_duplicate_question_ids_rejected_with_422(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'dup_ids_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'question_ids'    => [$this->straightQ1, $this->straightQ1], // Duplicated
                'total_questions' => 2,
            ]);

        $response->assertStatus(422);
        $this->assertSame('DUPLICATE_QUESTION_IDS', $response->json('error_code'));
    }

    public function test_mismatched_total_questions_count_rejected_with_422(): void
    {
        // 2 question IDs provided, but total_questions claims 10
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'count_mismatch_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'question_ids'    => [$this->straightQ1, $this->straightQ2],
                'total_questions' => 10,
            ]);

        $response->assertStatus(422);
        $this->assertSame('QUESTION_COUNT_MISMATCH', $response->json('error_code'));
    }

    public function test_no_session_or_pivot_records_created_on_validation_failure(): void
    {
        $initialSessionCount = PracticeSession::count();
        $initialPivotCount = PracticeSessionQuestion::count();

        // Send a request guaranteed to fail validation (incomplete scenario group)
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'rollback_test_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'question_ids'    => [$this->sg101_Q1], // Incomplete
                'total_questions' => 1,
            ]);

        $response->assertStatus(422);

        // Assert strictly zero records created
        $this->assertSame($initialSessionCount, PracticeSession::count());
        $this->assertSame($initialPivotCount, PracticeSessionQuestion::count());
    }

    // ========================================================================
    // Correction C: Order-Sensitive Idempotency Tests
    // ========================================================================

    public function test_identical_ordered_requests_produce_identical_fingerprints(): void
    {
        $service = new AssessmentIdempotencyService();

        $payloadA = [
            'operation'       => 'practice_session_generation',
            'subject_id'      => 5,
            'unit_ids'        => [263],
            'question_ids'    => [16609, 16610],
            'total_questions' => 2,
        ];

        $payloadB = [
            'operation'       => 'practice_session_generation',
            'subject_id'      => 5,
            'unit_ids'        => [263],
            'question_ids'    => [16609, 16610],
            'total_questions' => 2,
        ];

        $this->assertSame(
            $service->canonicalFingerprint($payloadA),
            $service->canonicalFingerprint($payloadB)
        );
    }

    public function test_different_question_order_produces_different_fingerprints(): void
    {
        $service = new AssessmentIdempotencyService();

        $payloadOrder1 = [
            'operation'       => 'practice_session_generation',
            'subject_id'      => 5,
            'unit_ids'        => [263],
            'question_ids'    => [16609, 16610],
            'total_questions' => 2,
        ];

        $payloadOrder2 = [
            'operation'       => 'practice_session_generation',
            'subject_id'      => 5,
            'unit_ids'        => [263],
            'question_ids'    => [16610, 16609], // Reordered
            'total_questions' => 2,
        ];

        $this->assertNotSame(
            $service->canonicalFingerprint($payloadOrder1),
            $service->canonicalFingerprint($payloadOrder2)
        );
    }

    public function test_retry_of_same_logical_request_remains_idempotent(): void
    {
        $idempotencyKey = 'idemp_retry_' . uniqid();
        $qids = [$this->straightQ1, $this->straightQ2];

        // 1st Attempt
        $res1 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'question_ids'    => $qids,
                'total_questions' => 2,
                'mcq_format'      => 'straight',
            ]);

        $res1->assertStatus(200);
        $sessionId1 = $res1->json('data.session_id');

        // 2nd Attempt with identical payload & key -> must replay same session
        $res2 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'question_ids'    => $qids,
                'total_questions' => 2,
                'mcq_format'      => 'straight',
            ]);

        $res2->assertStatus(200);
        $sessionId2 = $res2->json('data.session_id');
        $this->assertSame($sessionId1, $sessionId2);
    }

    public function test_altered_order_under_same_key_causes_409_conflict(): void
    {
        $idempotencyKey = 'idemp_conflict_' . uniqid();

        // 1st Attempt: [Q1, Q2]
        $res1 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'question_ids'    => [$this->straightQ1, $this->straightQ2],
                'total_questions' => 2,
                'mcq_format'      => 'straight',
            ]);
        $res1->assertStatus(200);

        // 2nd Attempt: [Q2, Q1] (different order) under same key -> must trigger 409 conflict
        $res2 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'question_ids'    => [$this->straightQ2, $this->straightQ1],
                'total_questions' => 2,
                'mcq_format'      => 'straight',
            ]);

        $res2->assertStatus(409);
    }
}
