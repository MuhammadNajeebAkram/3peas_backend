<?php

namespace Tests\Feature\Freemium;

use App\Http\Controllers\Tests\Concerns\SelectsLmsMcqQuestions;
use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumExamQuota;
use App\Models\PracticeSession;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Milestone C4: Configurable Scenario MCQ Percentage Backend Test Suite.
 *
 * Verifies:
 *  - Unit calculations of calculateScenarioAndStraightCounts across 0%, 5%, 20%, 30%, 50%, 95%, 100%.
 *  - Exact midpoint tie-breaking rounding down to the lower multiple of 4.
 *  - Edge cases: total questions < 4, 100% scenario with count not divisible by 4.
 *  - Endpoint validation: 0..100 range, multiples of 5, default to 30%.
 *  - Integration tests generating practice sessions with various scenario percentages.
 *  - Insufficient pool error handling (structured 422, no partial records).
 *  - Straight and scenario modes unaffected by scenario_percentage.
 */
class PracticeSessionScenarioPercentageTest extends TestCase
{
    use DatabaseTransactions;
    use SelectsLmsMcqQuestions;

    private WebUser $student;
    private Carbon $now;

    private int $boardId = 2;
    private int $classId = 1;
    private int $subjectId = 5;
    private int $bookId = 28;
    private int $unitId263 = 263;
    private int $topicId263 = 1342;

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

        $this->now = Carbon::parse('2026-11-22 12:00:00', 'UTC');

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

        DB::table('book_unit_topic_tbl')->updateOrInsert(['id' => $this->topicId263], [
            'topic_name' => 'Introduction to Biology',
            'topic_no'   => 1,
            'unit_id'    => $this->unitId263,
            'activate'   => 1,
        ]);

        // Clean tables
        DB::table('freemium_access_rules')->delete();
        DB::table('freemium_student_grants')->delete();
        DB::table('freemium_quota_consumptions')->delete();
        DB::table('freemium_campaigns')->delete();

        // Seed 20 Straight MCQs under Unit 263
        for ($i = 1; $i <= 20; $i++) {
            DB::table('exam_question_tbl')->updateOrInsert(['id' => 19600 + $i], [
                'question'          => "<p>Straight Question $i</p>",
                'topic_id'          => $this->topicId263,
                'question_type'     => 1,
                'is_mcq'            => 1,
                'exercise_question' => 1,
                'scenario_group_id' => null,
                'activate'          => 1,
            ]);
        }

        // Seed 3 complete Scenario Groups (12 questions total)
        for ($g = 1; $g <= 3; $g++) {
            $groupId = 200 + $g;
            DB::table('question_scenario_groups_tbl')->updateOrInsert(['id' => $groupId], [
                'title'         => "Scenario Group $g",
                'scenario_text' => "Scenario text for group $g",
                'activate'      => 1,
            ]);

            for ($q = 1; $q <= 4; $q++) {
                $qId = 29000 + (($g - 1) * 4) + $q;
                DB::table('exam_question_tbl')->updateOrInsert(['id' => $qId], [
                    'question'                => "<p>Scenario Group $g Question $q</p>",
                    'topic_id'                => $this->topicId263,
                    'question_type'           => 1,
                    'is_mcq'                  => 1,
                    'exercise_question'       => 1,
                    'scenario_group_id'       => $groupId,
                    'scenario_question_order' => $q,
                    'activate'                => 1,
                ]);
            }
        }

        // Campaign & Base Rule
        $this->campaign = FreemiumCampaign::create([
            'name'                 => 'Free Biology C4 Test',
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
            'perm_exercise_questions'  => 'allow',
            'perm_supplementary_questions' => 'allow',
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
                'max_attempts'       => 100,
                'quota_window_hours' => null,
                'allow_review'       => true,
            ]
        );

        $this->student = WebUser::create([
            'name'     => 'C4 Student',
            'email'    => 'c4_student_' . uniqid() . '@example.com',
            'role'     => 'student',
            'status'   => 'active',
            'password' => bcrypt('password123'),
        ]);
    }

    // ==========================================
    // SECTION 1: UNIT TESTS FOR COMPOSITION ALGORITHM
    // ==========================================

    public function test_composition_algorithm_various_percentages(): void
    {
        // Total Q = 20
        // P = 0% -> S = 0, straight = 20
        $res0 = $this->calculateScenarioAndStraightCounts(20, 0);
        $this->assertSame(0, $res0['scenario_questions']);
        $this->assertSame(20, $res0['straight_questions']);

        // P = 5% -> Target = 1.0, nearest multiple of 4 is 0 (0 <= midpoint 2.0) -> S = 0, straight = 20
        $res5 = $this->calculateScenarioAndStraightCounts(20, 5);
        $this->assertSame(0, $res5['scenario_questions']);
        $this->assertSame(20, $res5['straight_questions']);

        // P = 20% -> Target = 4.0 -> S = 4, straight = 16
        $res20 = $this->calculateScenarioAndStraightCounts(20, 20);
        $this->assertSame(4, $res20['scenario_questions']);
        $this->assertSame(16, $res20['straight_questions']);

        // P = 30% -> Target = 6.0, midpoint tie: lower=4, upper=8, midpoint=6.0 -> S = 4, straight = 16
        $res30 = $this->calculateScenarioAndStraightCounts(20, 30);
        $this->assertSame(4, $res30['scenario_questions']);
        $this->assertSame(16, $res30['straight_questions']);

        // P = 50% -> Target = 10.0, midpoint tie: lower=8, upper=12, midpoint=10.0 -> S = 8, straight = 12
        $res50 = $this->calculateScenarioAndStraightCounts(20, 50);
        $this->assertSame(8, $res50['scenario_questions']);
        $this->assertSame(12, $res50['straight_questions']);

        // P = 95% -> Target = 19.0, lower=16, upper=20, midpoint=18.0. 19.0 > 18.0 -> S = 20, straight = 0
        $res95 = $this->calculateScenarioAndStraightCounts(20, 95);
        $this->assertSame(20, $res95['scenario_questions']);
        $this->assertSame(0, $res95['straight_questions']);

        // P = 100% -> S = 20, straight = 0
        $res100 = $this->calculateScenarioAndStraightCounts(20, 100);
        $this->assertSame(20, $res100['scenario_questions']);
        $this->assertSame(0, $res100['straight_questions']);
    }

    public function test_midpoint_tie_rounding_down(): void
    {
        // Q = 20, P = 30 -> 6.0. lower=4, upper=8, midpoint=6.0. Should pick 4, NOT 8.
        $res = $this->calculateScenarioAndStraightCounts(20, 30);
        $this->assertSame(4, $res['scenario_questions']);
        $this->assertSame(16, $res['straight_questions']);

        // Q = 20, P = 50 -> 10.0. lower=8, upper=12, midpoint=10.0. Should pick 8, NOT 12.
        $res = $this->calculateScenarioAndStraightCounts(20, 50);
        $this->assertSame(8, $res['scenario_questions']);
        $this->assertSame(12, $res['straight_questions']);

        // Q = 20, P = 70 -> 14.0. lower=12, upper=16, midpoint=14.0. Should pick 12, NOT 16.
        $res = $this->calculateScenarioAndStraightCounts(20, 70);
        $this->assertSame(12, $res['scenario_questions']);
        $this->assertSame(8, $res['straight_questions']);

        // Q = 20, P = 90 -> 18.0. lower=16, upper=20, midpoint=18.0. Should pick 16, NOT 20.
        $res = $this->calculateScenarioAndStraightCounts(20, 90);
        $this->assertSame(16, $res['scenario_questions']);
        $this->assertSame(4, $res['straight_questions']);
    }

    public function test_total_questions_smaller_than_four(): void
    {
        // Q = 3, P = 50 -> cannot have any complete 4-question scenario group. S = 0, straight = 3.
        $res = $this->calculateScenarioAndStraightCounts(3, 50);
        $this->assertSame(0, $res['scenario_questions']);
        $this->assertSame(3, $res['straight_questions']);

        // Q = 2, P = 100 -> count not divisible by 4 -> throws InvalidArgumentException
        $this->expectException(\InvalidArgumentException::class);
        $this->calculateScenarioAndStraightCounts(2, 100);
    }

    public function test_hundred_percent_scenario_requires_divisible_by_four(): void
    {
        // Q = 25, P = 100 -> 25 is not divisible by 4 -> exception
        $this->expectException(\InvalidArgumentException::class);
        $this->calculateScenarioAndStraightCounts(25, 100);
    }

    // ==========================================
    // SECTION 2: ENDPOINT VALIDATION TESTS
    // ==========================================

    private function postPracticeSession(array $payload)
    {
        return $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', (string) \Illuminate\Support\Str::uuid())
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', $payload);
    }

    public function test_invalid_percentage_rejected_with_422(): void
    {
        // Negative percentage
        $res1 = $this->postPracticeSession([
            'subject_id'          => $this->subjectId,
            'book_id'             => $this->bookId,
            'unit_ids'            => [$this->unitId263],
            'total_questions'     => 10,
            'mcq_format'          => 'all',
            'scenario_percentage' => -5,
        ]);
        $res1->assertStatus(422);

        // Greater than 100
        $res2 = $this->postPracticeSession([
            'subject_id'          => $this->subjectId,
            'book_id'             => $this->bookId,
            'unit_ids'            => [$this->unitId263],
            'total_questions'     => 10,
            'mcq_format'          => 'all',
            'scenario_percentage' => 105,
        ]);
        $res2->assertStatus(422);

        // Not a multiple of 5
        $res3 = $this->postPracticeSession([
            'subject_id'          => $this->subjectId,
            'book_id'             => $this->bookId,
            'unit_ids'            => [$this->unitId263],
            'total_questions'     => 10,
            'mcq_format'          => 'all',
            'scenario_percentage' => 33,
        ]);
        $res3->assertStatus(422);
        $this->assertSame('INVALID_SCENARIO_PERCENTAGE', $res3->json('error_code'));
    }

    public function test_hundred_percent_scenario_with_non_multiple_of_four_fails(): void
    {
        $res = $this->postPracticeSession([
            'subject_id'          => $this->subjectId,
            'book_id'             => $this->bookId,
            'unit_ids'            => [$this->unitId263],
            'total_questions'     => 10,
            'mcq_format'          => 'all',
            'scenario_percentage' => 100,
        ]);
        $res->assertStatus(422);
        $this->assertStringContainsString('multiple of 4', $res->json('error'));
    }

    // ==========================================
    // SECTION 3: INTEGRATION GENERATION TESTS
    // ==========================================

    public function test_default_scenario_percentage_is_thirty(): void
    {
        $res = $this->postPracticeSession([
            'subject_id'      => $this->subjectId,
            'book_id'         => $this->bookId,
            'unit_ids'        => [$this->unitId263],
            'total_questions' => 10,
            'mcq_format'      => 'all',
        ]);

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertSame(30, $data['scenario_percentage']);
        // For Q=10, P=30: Target = 3.0, lower=0, upper=4, midpoint=2.0 -> 3.0 > 2.0 -> S=4, straight=6
        $this->assertSame(1, $data['total_scenarios']);
        $this->assertCount(10, $data['question_ids']);
    }

    public function test_generate_practice_session_zero_percent_scenario(): void
    {
        $res = $this->postPracticeSession([
            'subject_id'          => $this->subjectId,
            'book_id'             => $this->bookId,
            'unit_ids'            => [$this->unitId263],
            'total_questions'     => 10,
            'mcq_format'          => 'all',
            'scenario_percentage' => 0,
        ]);

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertSame(0, $data['scenario_percentage']);
        $this->assertSame(0, $data['total_scenarios']);
        $this->assertEmpty($data['scenario_group_ids']);
        $this->assertCount(10, $data['question_ids']);

        // Verify all 10 questions are straight MCQs
        $scenarioQuestions = DB::table('exam_question_tbl')
            ->whereIn('id', $data['question_ids'])
            ->whereNotNull('scenario_group_id')
            ->count();
        $this->assertSame(0, $scenarioQuestions);
    }

    public function test_generate_practice_session_fifty_percent_scenario(): void
    {
        // Q = 8, P = 50 -> Target = 4.0 -> S = 4 (1 group), straight = 4
        $res = $this->postPracticeSession([
            'subject_id'          => $this->subjectId,
            'book_id'             => $this->bookId,
            'unit_ids'            => [$this->unitId263],
            'total_questions'     => 8,
            'mcq_format'          => 'all',
            'scenario_percentage' => 50,
        ]);

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertSame(50, $data['scenario_percentage']);
        $this->assertSame(1, $data['total_scenarios']);
        $this->assertCount(8, $data['question_ids']);
    }

    public function test_generate_practice_session_hundred_percent_scenario_divisible_by_four(): void
    {
        // Q = 8, P = 100 -> S = 8 (2 groups), straight = 0
        $res = $this->postPracticeSession([
            'subject_id'          => $this->subjectId,
            'book_id'             => $this->bookId,
            'unit_ids'            => [$this->unitId263],
            'total_questions'     => 8,
            'mcq_format'          => 'all',
            'scenario_percentage' => 100,
        ]);

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertSame(100, $data['scenario_percentage']);
        $this->assertSame(2, $data['total_scenarios']);
        $this->assertCount(8, $data['question_ids']);

        // Verify all 8 questions belong to scenario groups
        $straightQuestions = DB::table('exam_question_tbl')
            ->whereIn('id', $data['question_ids'])
            ->whereNull('scenario_group_id')
            ->count();
        $this->assertSame(0, $straightQuestions);
    }

    public function test_straight_and_scenario_modes_unaffected_by_percentage(): void
    {
        // Ensure no active session exists for student
        PracticeSession::where('user_id', $this->student->id)->update(['status' => 'submitted']);

        // Mode 'straight' with scenario_percentage=80 -> still generates only straight
        $resStraight = $this->postPracticeSession([
            'subject_id'          => $this->subjectId,
            'book_id'             => $this->bookId,
            'unit_ids'            => [$this->unitId263],
            'total_questions'     => 5,
            'mcq_format'          => 'straight',
            'scenario_percentage' => 80,
        ]);

        $resStraight->assertStatus(200);
        $dataStraight = $resStraight->json('data');
        $this->assertSame(0, $dataStraight['total_scenarios']);
        $this->assertCount(5, $dataStraight['question_ids']);

        // Mark straight session as submitted so active-session resumption does not interfere
        PracticeSession::where('user_id', $this->student->id)->update(['status' => 'submitted']);

        // Mode 'scenario' with scenario_percentage=20 -> generates all scenarios (requires count multiple of 4)
        $resScenario = $this->postPracticeSession([
            'subject_id'          => $this->subjectId,
            'book_id'             => $this->bookId,
            'unit_ids'            => [$this->unitId263],
            'total_questions'     => 4,
            'mcq_format'          => 'scenario',
            'scenario_percentage' => 20,
        ]);

        $resScenario->assertStatus(200);
        $dataScenario = $resScenario->json('data');
        $this->assertSame(1, $dataScenario['total_scenarios']);
        $this->assertCount(4, $dataScenario['question_ids']);
    }

    public function test_insufficient_scenario_pool_fails_without_creating_partial_assessment(): void
    {
        PracticeSession::where('user_id', $this->student->id)->update(['status' => 'submitted']);
        $initialSessions = PracticeSession::where('user_id', $this->student->id)->count();

        // There are only 3 scenario groups (12 questions) in the DB. Requesting 16 scenario questions (4 groups) must fail.
        $res = $this->postPracticeSession([
            'subject_id'          => $this->subjectId,
            'book_id'             => $this->bookId,
            'unit_ids'            => [$this->unitId263],
            'total_questions'     => 16,
            'mcq_format'          => 'all',
            'scenario_percentage' => 100,
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('Not enough complete active scenario groups', $res->json('error'));

        // Verify zero partial records were created
        $finalSessions = PracticeSession::where('user_id', $this->student->id)->count();
        $this->assertSame($initialSessions, $finalSessions);
    }
}
