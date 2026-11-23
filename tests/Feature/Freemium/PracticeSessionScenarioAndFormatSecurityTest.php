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
 * Practice Session Scenario and Format Security Test Suite (Phase 2-C & Phase 2-D).
 *
 * Verifies:
 *  - F-01: Correct origin evaluation for exercise and supplementary filtering in Question Explorer.
 *  - F-02: Permission enforcement for perm_exercise_questions and perm_supplementary_questions in practice sessions.
 *  - F-03: Strict validation between selected question_ids and requested mcq_format (straight/scenario/all).
 *  - FINDING-01: Stable, strictly consecutive question ordering across multiple scenario groups without intermingling.
 *  - FINDING-02: Approved 30% scenario mix for format 'all' with lower-multiple tie-breaking, complete 4-question groups, and standalone straight MCQs.
 *  - FINDING-03: Consistent dual-denial behavior returning HTTP 403 ACCESS_DENIED across Question Explorer and Practice Sessions.
 *  - FINDING-04: Full test coverage including supplementary DENY in scenarios, full-book scenario generation, post-progress resumption, and edge cases.
 *  - Idempotency & Quota: Replay stability, conflict detection, and no double consumption.
 */
class PracticeSessionScenarioAndFormatSecurityTest extends TestCase
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
            'topic_name' => 'Cell Structure',
            'topic_no'   => 1,
            'unit_id'    => $this->unitId264,
            'activate'   => 1,
        ]);

        // Clean tables
        DB::table('freemium_access_rules')->delete();
        DB::table('freemium_student_grants')->delete();
        DB::table('freemium_quota_consumptions')->delete();
        DB::table('freemium_campaigns')->delete();

        // 1. Seed 16 Straight MCQs under Unit 263 (8 Exercise, 8 Supplementary)
        for ($i = 1; $i <= 16; $i++) {
            $isEx = ($i % 2 === 1) ? 1 : 0;
            DB::table('exam_question_tbl')->updateOrInsert(['id' => 16608 + $i], [
                'question'          => "<p>Straight Question $i (" . ($isEx ? 'Exercise' : 'Supplementary') . ")</p>",
                'topic_id'          => $this->topicId263,
                'question_type'     => 1,
                'is_mcq'            => 1,
                'exercise_question' => $isEx,
                'scenario_group_id' => null,
                'activate'          => 1,
            ]);
        }

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

        // 2. Scenario Group 101 (Valid, 4 questions, all Exercise)
        DB::table('question_scenario_groups_tbl')->updateOrInsert(['id' => 101], [
            'title'            => 'Enzyme Kinetics Scenario',
            'scenario_text'    => 'An experiment was conducted to measure enzyme activity under varying temperatures.',
            'scenario_text_um' => 'ایک تجربہ کیا گیا',
            'scenario_image'   => 'enzymes.jpg',
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

        // 3. Scenario Group 102 (Valid, 4 questions, all Supplementary)
        DB::table('question_scenario_groups_tbl')->updateOrInsert(['id' => 102], [
            'title'            => 'Photosynthesis Scenario',
            'scenario_text'    => 'A plant was exposed to different wavelengths of light.',
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
                'exercise_question'       => 0,
                'scenario_group_id'       => 102,
                'scenario_question_order' => $i - 4,
                'activate'                => 1,
            ]);
        }

        // 4. Scenario Group 103 (Invalid, only 3 questions - must be excluded)
        DB::table('question_scenario_groups_tbl')->updateOrInsert(['id' => 103], [
            'title'         => 'Incomplete Scenario',
            'scenario_text' => 'Only three questions exist for this scenario.',
            'activate'      => 1,
        ]);
        for ($i = 9; $i <= 11; $i++) {
            DB::table('exam_question_tbl')->updateOrInsert(['id' => 18000 + $i], [
                'question'                => "<p>Incomplete Scenario Q$i</p>",
                'topic_id'                => $this->topicId263,
                'question_type'           => 1,
                'is_mcq'                  => 1,
                'exercise_question'       => 1,
                'scenario_group_id'       => 103,
                'scenario_question_order' => $i - 8,
                'activate'                => 1,
            ]);
        }

        // 5. Scenario Group 104 (In Unauthorized Unit 264)
        DB::table('question_scenario_groups_tbl')->updateOrInsert(['id' => 104], [
            'title'         => 'Unauthorized Cell Scenario',
            'scenario_text' => 'Scenario belonging to unauthorized chapter 264.',
            'activate'      => 1,
        ]);
        for ($i = 12; $i <= 15; $i++) {
            DB::table('exam_question_tbl')->updateOrInsert(['id' => 18000 + $i], [
                'question'                => "<p>Unauthorized Scenario Q$i</p>",
                'topic_id'                => $this->topicId264,
                'question_type'           => 1,
                'is_mcq'                  => 1,
                'exercise_question'       => 1,
                'scenario_group_id'       => 104,
                'scenario_question_order' => $i - 11,
                'activate'                => 1,
            ]);
        }

        // 6. Scenario Group 105 (Inactive group - must never be selected)
        DB::table('question_scenario_groups_tbl')->updateOrInsert(['id' => 105], [
            'title'         => 'Inactive Scenario Group',
            'scenario_text' => 'This group is inactive.',
            'activate'      => 0,
        ]);
        for ($i = 16; $i <= 19; $i++) {
            DB::table('exam_question_tbl')->updateOrInsert(['id' => 18000 + $i], [
                'question'                => "<p>Inactive Scenario Q$i</p>",
                'topic_id'                => $this->topicId263,
                'question_type'           => 1,
                'is_mcq'                  => 1,
                'exercise_question'       => 1,
                'scenario_group_id'       => 105,
                'scenario_question_order' => $i - 15,
                'activate'                => 1,
            ]);
        }

        // Campaign & Base Rule
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

        FreemiumExamQuota::updateOrCreate(
            [
                'campaign_id' => $this->campaign->id,
                'exam_type'   => 'practice',
            ],
            [
                'max_attempts'       => 50,
                'quota_window_hours' => null,
                'allow_review'       => true,
            ]
        );

        $this->student = WebUser::create([
            'name'     => 'Scenario Free Student',
            'email'    => 'scenario_free_' . uniqid() . '@example.com',
            'role'     => 'student',
            'status'   => 'active',
            'password' => bcrypt('password123'),
        ]);

        $this->paidStudent = WebUser::create([
            'name'     => 'Scenario Paid Student',
            'email'    => 'scenario_paid_' . uniqid() . '@example.com',
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
    }

    // ==========================================
    // PART A: F-01, F-02, FINDING-03 Permissions
    // ==========================================

    /**
     * F-01: Explicit exercise DENY in campaign rule filters exercise questions in Question Explorer.
     */
    public function test_f01_explicit_exercise_deny_filters_in_question_explorer(): void
    {
        $this->rule->update([
            'perm_exercise_questions'      => 'deny',
            'perm_supplementary_questions' => 'allow',
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/my-classes/get-questions-by-topic/{$this->topicId263}/questions");

        $response->assertStatus(200);
        $questions = $response->json();
        $this->assertIsArray($questions);
        $this->assertNotEmpty($questions);

        foreach ($questions as $q) {
            $this->assertEquals(0, $q['exercise_question'], "Question {$q['id']} should not be an exercise question.");
        }
    }

    /**
     * F-01: Explicit supplementary DENY in campaign rule filters supplementary questions in Question Explorer.
     */
    public function test_f01_explicit_supplementary_deny_filters_in_question_explorer(): void
    {
        $this->rule->update([
            'perm_exercise_questions'      => 'allow',
            'perm_supplementary_questions' => 'deny',
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/my-classes/get-questions-by-topic/{$this->topicId263}/questions");

        $response->assertStatus(200);
        $questions = $response->json();
        $this->assertIsArray($questions);
        $this->assertNotEmpty($questions);

        foreach ($questions as $q) {
            $this->assertEquals(1, $q['exercise_question'], "Question {$q['id']} should be an exercise question.");
        }
    }

    /**
     * FINDING-03: Dual denial in Question Explorer returns HTTP 403 ACCESS_DENIED.
     */
    public function test_f01_f02_dual_denial_in_question_explorer_fails_closed(): void
    {
        $this->rule->update([
            'perm_exercise_questions'      => 'deny',
            'perm_supplementary_questions' => 'deny',
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/my-classes/get-questions-by-topic/{$this->topicId263}/questions");

        $response->assertStatus(403);
        $this->assertSame('ACCESS_DENIED', $response->json('code'));
    }

    /**
     * F-02: Exercise DENY prevents exercise MCQs from entering random practice session.
     */
    public function test_f02_exercise_deny_prevents_exercise_mcqs_in_practice_session(): void
    {
        $this->rule->update([
            'perm_exercise_questions'      => 'deny',
            'perm_supplementary_questions' => 'allow',
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'ex_deny_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 2,
                'mcq_format'      => 'straight',
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $questionIds = $data['question_ids'];
        $this->assertCount(2, $questionIds);

        $exerciseCount = DB::table('exam_question_tbl')->whereIn('id', $questionIds)->where('exercise_question', 1)->count();
        $this->assertSame(0, $exerciseCount, "Exercise questions must not be selected when exercise is denied.");
    }

    /**
     * F-02: Supplementary DENY prevents supplementary MCQs from entering random practice session.
     */
    public function test_f02_supplementary_deny_prevents_supplementary_mcqs_in_practice_session(): void
    {
        $this->rule->update([
            'perm_exercise_questions'      => 'allow',
            'perm_supplementary_questions' => 'deny',
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'supp_deny_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 2,
                'mcq_format'      => 'straight',
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $questionIds = $data['question_ids'];
        $this->assertCount(2, $questionIds);

        $supplementaryCount = DB::table('exam_question_tbl')->whereIn('id', $questionIds)->where('exercise_question', 0)->count();
        $this->assertSame(0, $supplementaryCount, "Supplementary questions must not be selected when supplementary is denied.");
    }

    /**
     * F-02: Mixed permitted/denied question selections fail closed with HTTP 403 ACCESS_DENIED.
     */
    public function test_f02_mixed_permitted_denied_question_selection_fails_closed(): void
    {
        $this->rule->update([
            'perm_exercise_questions'      => 'allow',
            'perm_supplementary_questions' => 'deny',
        ]);

        // 16609 is exercise (allowed), 16610 is supplementary (denied)
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'mixed_perm_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'question_ids'    => [16609, 16610],
                'total_questions' => 2,
                'mcq_format'      => 'straight',
            ]);

        $response->assertStatus(403);
        $this->assertSame('ACCESS_DENIED', $response->json('code'));
    }

    // ==========================================
    // PART B & C: Format Validation & F-03
    // ==========================================

    /**
     * F-03: Straight format returns only straight MCQs (scenario_group_id IS NULL).
     */
    public function test_straight_format_returns_only_straight_mcqs(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'straight_only_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 4,
                'mcq_format'      => 'straight',
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $questionIds = $data['question_ids'];

        $scenarioQuestionsCount = DB::table('exam_question_tbl')
            ->whereIn('id', $questionIds)
            ->whereNotNull('scenario_group_id')
            ->count();

        $this->assertSame(0, $scenarioQuestionsCount, "Straight format must not include scenario questions.");
    }

    /**
     * F-03: Format mismatch between question_ids and requested format fails with HTTP 422 FORMAT_MISMATCH.
     */
    public function test_f03_question_ids_format_mismatch_fails_with_422(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'format_mismatch_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'question_ids'    => [18001],
                'total_questions' => 1,
                'mcq_format'      => 'straight',
            ]);

        $response->assertStatus(422);
        $this->assertSame('FORMAT_MISMATCH', $response->json('code'));
    }

    /**
     * F-03: Requesting scenario format with a straight question ID fails with HTTP 422 FORMAT_MISMATCH.
     */
    public function test_f03_straight_question_with_scenario_format_fails_with_422(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'format_mismatch2_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'question_ids'    => [16609, 16610, 16611, 16612],
                'total_questions' => 4,
                'mcq_format'      => 'scenario',
            ]);

        $response->assertStatus(422);
        $this->assertSame('FORMAT_MISMATCH', $response->json('code'));
    }

    /**
     * Legacy format 'mcq' normalizes to 'all' and is accepted.
     */
    public function test_legacy_format_mcq_normalizes_to_all(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'legacy_mcq_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 2,
                'mcq_format'      => 'mcq',
            ]);

        $response->assertStatus(200);
        $this->assertSame('all', $response->json('data.mcq_format'));
    }

    /**
     * Invalid format rejected with HTTP 422.
     */
    public function test_invalid_mcq_format_rejected_with_422(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'invalid_fmt_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 2,
                'mcq_format'      => 'unsupported_format',
            ]);

        $response->assertStatus(422);
    }

    // ==========================================
    // PART C: FINDING-01 Sorting & Scenario Integrity
    // ==========================================

    /**
     * FINDING-01: Multi-scenario question ordering is strictly consecutive without intermingling.
     */
    public function test_finding01_multi_scenario_question_ordering_is_strictly_consecutive(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'multi_scen_order_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 8,
                'mcq_format'      => 'scenario',
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data['scenario_blocks']);

        $block1 = $data['scenario_blocks'][0];
        $block2 = $data['scenario_blocks'][1];

        $qIds = $data['question_ids'];
        $this->assertCount(8, $qIds);

        // First 4 questions belong to block 1
        $firstFour = array_slice($qIds, 0, 4);
        $this->assertEqualsCanonicalizing($block1['question_ids'], $firstFour);

        // Next 4 questions belong to block 2
        $nextFour = array_slice($qIds, 4, 4);
        $this->assertEqualsCanonicalizing($block2['question_ids'], $nextFour);

        // Within block 1, question sequence matches block1.questions scenario_question_order
        $orders1 = collect($block1['questions'])->pluck('scenario_question_order')->toArray();
        $this->assertSame([1, 2, 3, 4], $orders1);

        // Within block 2, question sequence matches block2.questions scenario_question_order
        $orders2 = collect($block2['questions'])->pluck('scenario_question_order')->toArray();
        $this->assertSame([1, 2, 3, 4], $orders2);
    }

    /**
     * Scenario selection returns exactly 4 MCQs per group and populates scenario_blocks.
     */
    public function test_scenario_selection_returns_4_mcqs_per_group_and_blocks(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'scenario_4_test_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 4,
                'mcq_format'      => 'scenario',
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertArrayHasKey('scenario_blocks', $data);
        $this->assertCount(1, $data['scenario_blocks']);

        $block = $data['scenario_blocks'][0];
        $this->assertContains($block['scenario_group_id'], [101, 102]);
        $this->assertNotEmpty($block['title']);
        $this->assertNotEmpty($block['scenario_statement']);
        $this->assertCount(4, $block['question_ids']);
        $this->assertCount(4, $block['questions']);

        $this->assertCount(4, $data['question_ids']);
        foreach ($data['question_ids'] as $qid) {
            $this->assertContains($qid, $block['question_ids']);
        }
    }

    /**
     * Non-multiple of 4 for scenario format returns HTTP 422.
     */
    public function test_scenario_non_multiple_of_4_returns_422(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'non_mult_4_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 5,
                'mcq_format'      => 'scenario',
            ]);

        $response->assertStatus(422);
    }

    /**
     * Scenario groups with fewer than 4 eligible questions are excluded.
     */
    public function test_scenario_groups_under_4_questions_are_excluded(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'scenario_8_test_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 8,
                'mcq_format'      => 'scenario',
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data['scenario_blocks']);

        $blockGroupIds = collect($data['scenario_blocks'])->pluck('scenario_group_id')->toArray();
        $this->assertNotContains(103, $blockGroupIds, "Scenario group 103 has only 3 questions and must be excluded.");
        $this->assertEqualsCanonicalizing([101, 102], $blockGroupIds);
    }

    /**
     * Inactive scenario group (Group 105, activate = 0) is never selected.
     */
    public function test_inactive_scenario_group_is_never_selected(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'inactive_scen_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 8,
                'mcq_format'      => 'scenario',
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $blockGroupIds = collect($data['scenario_blocks'])->pluck('scenario_group_id')->toArray();
        $this->assertNotContains(105, $blockGroupIds, "Inactive scenario group 105 must never be selected.");
    }

    /**
     * Insufficient complete scenario groups returns HTTP 422 INSUFFICIENT_QUESTIONS.
     */
    public function test_insufficient_complete_scenario_groups_returns_422(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'insufficient_scen_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 12,
                'mcq_format'      => 'scenario',
            ]);

        $response->assertStatus(422);
        $this->assertSame('INSUFFICIENT_QUESTIONS', $response->json('code'));
    }

    /**
     * Scenario groups outside authorized units are denied.
     */
    public function test_scenario_groups_outside_authorized_unit_are_denied(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'unauth_scen_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId264],
                'total_questions' => 4,
                'mcq_format'      => 'scenario',
            ]);

        $response->assertStatus(403);
        $this->assertSame('ACCESS_DENIED', $response->json('code'));
    }

    /**
     * Exercise and supplementary restrictions apply inside scenario groups (Exercise DENY).
     */
    public function test_scenario_group_filtered_by_exercise_permission(): void
    {
        $this->rule->update([
            'perm_exercise_questions'      => 'deny',
            'perm_supplementary_questions' => 'allow',
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'scen_ex_filter_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 8,
                'mcq_format'      => 'scenario',
            ]);

        $response->assertStatus(422);
        $this->assertSame('INSUFFICIENT_QUESTIONS', $response->json('code'));

        $response4 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'scen_ex_filter_4_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 4,
                'mcq_format'      => 'scenario',
            ]);

        $response4->assertStatus(200);
        $this->assertSame(102, $response4->json('data.scenario_blocks.0.scenario_group_id'));
    }

    /**
     * Supplementary DENY restricts scenario group selection to exercise-only groups.
     */
    public function test_scenario_group_filtered_by_supplementary_permission(): void
    {
        $this->rule->update([
            'perm_exercise_questions'      => 'allow',
            'perm_supplementary_questions' => 'deny',
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'scen_supp_filter_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 8,
                'mcq_format'      => 'scenario',
            ]);

        $response->assertStatus(422);
        $this->assertSame('INSUFFICIENT_QUESTIONS', $response->json('code'));

        $response4 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'scen_supp_filter_4_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 4,
                'mcq_format'      => 'scenario',
            ]);

        $response4->assertStatus(200);
        $this->assertSame(101, $response4->json('data.scenario_blocks.0.scenario_group_id'));
    }

    /**
     * Full-book scenario generation works with four-question groups for entitled user,
     * while failing closed with 403 ACCESS_DENIED for chapter-scoped freemium user.
     */
    public function test_full_book_scenario_generation(): void
    {
        // 1. Chapter-scoped freemium user cannot generate full-book session covering unauthorized units
        $denyResponse = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'full_book_scen_deny_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-full-book-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'total_questions' => 4,
                'mcq_format'      => 'scenario',
            ]);

        $denyResponse->assertStatus(403)
            ->assertJson([
                'success' => false,
                'code'    => 'ACCESS_DENIED',
            ]);

        // 2. Paid student with full book access can generate full-book scenario session
        $response = $this->actingAs($this->paidStudent, 'web_api')
            ->withHeader('X-Idempotency-Key', 'full_book_scen_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-full-book-practice-session', [
                'offered_program_id' => 994,
                'subject_id'         => $this->subjectId,
                'book_id'            => $this->bookId,
                'total_questions'    => 4,
                'mcq_format'         => 'scenario',
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertSame(1, $data['total_scenarios']);
        $this->assertCount(1, $data['scenario_blocks']);
        $this->assertCount(4, $data['scenario_blocks'][0]['question_ids']);
    }

    // ==========================================
    // PART D: FINDING-02 'all' Format & 30% Ratio
    // ==========================================

    /**
     * Format 'all' with 10 questions returns 4 scenario questions (1 group) and 6 straight questions.
     */
    public function test_all_format_returns_30_percent_scenario_mix_10_questions(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'all_10_test_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 10,
                'mcq_format'      => 'all',
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertSame(10, $data['total_questions']);
        $this->assertSame(1, $data['total_scenarios']);
        $this->assertCount(1, $data['scenario_blocks']);
        $this->assertCount(4, $data['scenario_blocks'][0]['question_ids']);

        $qIds = $data['question_ids'];
        $this->assertCount(10, $qIds);

        // First 4 questions are scenario questions
        $scenarioQids = array_slice($qIds, 0, 4);
        $this->assertEqualsCanonicalizing($data['scenario_blocks'][0]['question_ids'], $scenarioQids);

        // Remaining 6 questions are straight questions
        $straightQids = array_slice($qIds, 4, 6);
        $this->assertCount(6, $straightQids);

        $straightDbCount = DB::table('exam_question_tbl')
            ->whereIn('id', $straightQids)
            ->whereNull('scenario_group_id')
            ->count();
        $this->assertSame(6, $straightDbCount, "All 6 remaining questions must be straight MCQs.");
    }

    /**
     * Format 'all' with 20 questions returns 4 scenario questions (lower-multiple tie break) and 16 straight questions.
     */
    public function test_all_format_returns_30_percent_scenario_mix_20_questions(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'all_20_test_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 20,
                'mcq_format'      => 'all',
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertSame(20, $data['total_questions']);
        $this->assertSame(1, $data['total_scenarios']);
        $this->assertCount(1, $data['scenario_blocks']);
        $this->assertCount(4, $data['scenario_blocks'][0]['question_ids']);
        $this->assertCount(20, $data['question_ids']);
    }

    /**
     * Format 'all' with 24 questions returns 8 scenario questions (2 groups) and 16 straight questions.
     */
    public function test_all_format_returns_30_percent_scenario_mix_24_questions(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'all_24_test_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 24,
                'mcq_format'      => 'all',
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertSame(24, $data['total_questions']);
        $this->assertSame(2, $data['total_scenarios']);
        $this->assertCount(2, $data['scenario_blocks']);
        $this->assertCount(24, $data['question_ids']);
    }

    /**
     * Format 'all' with total < 4 returns 0 scenario questions and all straight questions.
     */
    public function test_all_format_below_four_questions_returns_all_straight(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'all_sub4_test_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 2,
                'mcq_format'      => 'all',
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertSame(2, $data['total_questions']);
        $this->assertSame(0, $data['total_scenarios']);
        $this->assertEmpty($data['scenario_blocks']);
        $this->assertCount(2, $data['question_ids']);

        $straightCount = DB::table('exam_question_tbl')
            ->whereIn('id', $data['question_ids'])
            ->whereNull('scenario_group_id')
            ->count();
        $this->assertSame(2, $straightCount);
    }

    /**
     * Insufficient scenario groups in format 'all' returns HTTP 422 INSUFFICIENT_QUESTIONS.
     */
    public function test_all_format_insufficient_scenario_groups_returns_422(): void
    {
        // 40 questions requires 12 scenario questions = 3 scenario groups. Only 2 exist.
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'all_insuf_scen_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 40,
                'mcq_format'      => 'all',
            ]);

        $response->assertStatus(422);
        $this->assertSame('INSUFFICIENT_QUESTIONS', $response->json('code'));
        $this->assertStringContainsString('30% scenario requirement', $response->json('error'));
    }

    /**
     * Insufficient straight questions in format 'all' returns HTTP 422 INSUFFICIENT_QUESTIONS.
     */
    public function test_all_format_insufficient_straight_questions_returns_422(): void
    {
        // 30 questions requires 8 scenario + 22 straight questions. Only 16 straight questions exist.
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'all_insuf_straight_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 30,
                'mcq_format'      => 'all',
            ]);

        $response->assertStatus(422);
        $this->assertSame('INSUFFICIENT_QUESTIONS', $response->json('code'));
        $this->assertStringContainsString('straight MCQs', $response->json('error'));
    }

    /**
     * Format 'all' respects exercise/supplementary permission filtering across both pools.
     */
    public function test_all_format_respects_exercise_permission_filtering(): void
    {
        $this->rule->update([
            'perm_exercise_questions'      => 'deny',
            'perm_supplementary_questions' => 'allow',
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'all_ex_filter_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 10,
                'mcq_format'      => 'all',
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        // Scenario block must be Group 102 (supplementary)
        $this->assertSame(102, $data['scenario_blocks'][0]['scenario_group_id']);

        // None of the selected questions can be exercise
        $exerciseCount = DB::table('exam_question_tbl')
            ->whereIn('id', $data['question_ids'])
            ->where('exercise_question', 1)
            ->count();
        $this->assertSame(0, $exerciseCount, "No exercise questions allowed when exercise is denied.");
    }

    // ==========================================
    // PART E: Resumption & Idempotency
    // ==========================================

    /**
     * Scenario grouping survives session retrieval and resumption.
     */
    public function test_scenario_grouping_survives_retrieval_and_resumption(): void
    {
        $launchResponse = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'resume_scen_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 4,
                'mcq_format'      => 'scenario',
            ]);

        $launchResponse->assertStatus(200);
        $sessionId = $launchResponse->json('data.session_id');
        $this->assertNotNull($sessionId);

        $resumeResponse = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'resume_call_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 4,
                'mcq_format'      => 'scenario',
            ]);

        $resumeResponse->assertStatus(200);
        $resumeData = $resumeResponse->json('data');

        $this->assertSame($sessionId, $resumeData['session_id']);
        $this->assertArrayHasKey('scenario_blocks', $resumeData);
        $this->assertArrayHasKey('scenarios', $resumeData);
        $this->assertCount(1, $resumeData['scenario_blocks']);

        $block = $resumeData['scenario_blocks'][0];
        $this->assertContains($block['scenario_group_id'], [101, 102]);
        $this->assertNotEmpty($block['title']);
        $this->assertNotEmpty($block['scenario_statement']);
        $this->assertCount(4, $block['question_ids']);
    }

    /**
     * Scenario grouping survives resumption after partial progress has been saved.
     */
    public function test_scenario_grouping_survives_partial_progress_resumption(): void
    {
        $launchResponse = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'partial_prog_launch_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 4,
                'mcq_format'      => 'scenario',
            ]);

        $launchResponse->assertStatus(200);
        $sessionId = $launchResponse->json('data.session_id');
        $firstQuestionId = $launchResponse->json('data.question_ids.0');

        // Save progress for question 1
        $progressResponse = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/save-practice-session-question-progress', [
                'session_id'         => $sessionId,
                'question_id'        => $firstQuestionId,
                'time_spent_seconds' => 20,
            ]);

        $progressResponse->assertStatus(200);

        // Resume session
        $resumeResponse = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'partial_prog_resume_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 4,
                'mcq_format'      => 'scenario',
            ]);

        $resumeResponse->assertStatus(200);
        $resumeData = $resumeResponse->json('data');

        $this->assertSame($sessionId, $resumeData['session_id']);
        $this->assertCount(1, $resumeData['scenario_blocks']);
        $this->assertCount(4, $resumeData['scenario_blocks'][0]['question_ids']);
        $this->assertSame('in_progress', $resumeData['status']);
    }

    /**
     * Scenario selection is stable on identical retries and does not double-consume quota.
     */
    public function test_scenario_idempotent_replay_and_single_quota_consumption(): void
    {
        $idempotencyKey = 'scen_idem_' . uniqid();

        $initialConsumptions = FreemiumQuotaConsumption::where('user_id', $this->student->id)->count();

        $res1 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 4,
                'mcq_format'      => 'scenario',
            ]);

        $res1->assertStatus(200);
        $sessionId1 = $res1->json('data.session_id');
        $questionIds1 = $res1->json('data.question_ids');

        $this->assertSame($initialConsumptions + 1, FreemiumQuotaConsumption::where('user_id', $this->student->id)->count());

        $res2 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 4,
                'mcq_format'      => 'scenario',
            ]);

        $res2->assertStatus(200);
        $this->assertSame($sessionId1, $res2->json('data.session_id'));
        $questionIds2 = $res2->json('data.question_ids');
        $this->assertSame($questionIds1, $questionIds2);

        $this->assertSame($initialConsumptions + 1, FreemiumQuotaConsumption::where('user_id', $this->student->id)->count());
    }

    /**
     * Format 'all' idempotent replay preserves exact mixed question sequence and quota.
     */
    public function test_all_format_idempotency_replay_preserves_mixed_questions(): void
    {
        $idempotencyKey = 'all_idem_' . uniqid();

        $initialConsumptions = FreemiumQuotaConsumption::where('user_id', $this->student->id)->count();

        $res1 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 10,
                'mcq_format'      => 'all',
            ]);

        $res1->assertStatus(200);
        $sessionId1 = $res1->json('data.session_id');
        $questionIds1 = $res1->json('data.question_ids');

        $this->assertSame($initialConsumptions + 1, FreemiumQuotaConsumption::where('user_id', $this->student->id)->count());

        $res2 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 10,
                'mcq_format'      => 'all',
            ]);

        $res2->assertStatus(200);
        $this->assertSame($sessionId1, $res2->json('data.session_id'));
        $this->assertSame($questionIds1, $res2->json('data.question_ids'));

        $this->assertSame($initialConsumptions + 1, FreemiumQuotaConsumption::where('user_id', $this->student->id)->count());
    }

    /**
     * Altered format under same idempotency key causes HTTP 409 IDEMPOTENCY_CONFLICT.
     */
    public function test_altered_format_under_same_idempotency_key_causes_409(): void
    {
        $idempotencyKey = 'scen_conflict_' . uniqid();

        $res1 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 4,
                'mcq_format'      => 'straight',
            ]);

        $res1->assertStatus(200);

        $res2 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'subject_id'      => $this->subjectId,
                'book_id'         => $this->bookId,
                'unit_ids'        => [$this->unitId263],
                'total_questions' => 4,
                'mcq_format'      => 'scenario',
            ]);

        $res2->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_CONFLICT', $res2->json('code'));
    }
}
