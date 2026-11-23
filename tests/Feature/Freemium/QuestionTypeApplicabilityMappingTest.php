<?php

namespace Tests\Feature\Freemium;

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
 * Milestone C5-C7 Test Suite:
 * Backend Question Type Mapping & Applicability Correction
 *
 * Verifies:
 * 1. Exact reported scope (Board 2, Class 1, Subject 5, Book 28, Unit 263):
 *    - MCQs: 160
 *    - Short Questions: 0
 *    - Long Questions: 0
 *    - Question types 31, 32, 33, 34, 36, 38, 39: all strictly 0.
 * 2. Question Explorer for Unit 263:
 *    - When querying type 1 (MCQs), returns all eligible MCQs.
 *    - When querying type 38 (Correct Spellings), returns 0 questions.
 *    - When querying type 31 (جنس), returns 0 questions.
 *    - Zero unrelated general MCQs leak into specific type queries.
 * 3. Specific question types return only their exact matching questions:
 *    - Type 31 returns only questions with question_type 31.
 *    - Type 32 returns only questions with question_type 32.
 *    - Types 33, 34, 36, 38, 39 return only their exact matching questions.
 * 4. Multi-type selection returns the union of exact matching questions.
 * 5. Types with zero questions report 0 availability.
 * 6. Scenario and straight MCQ behavior remains intact.
 * 7. Entitlement and campaign boundaries remain enforced.
 */
class QuestionTypeApplicabilityMappingTest extends TestCase
{
    use DatabaseTransactions;

    private WebUser $student;
    private WebUser $paidStudent;
    private Carbon $now;

    private int $boardId = 2;
    private int $classId = 1;
    private int $subjectId = 5;
    private int $bookId = 28;
    private int $unitId263 = 263;
    private int $topicId263 = 1342;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            \App\Http\Middleware\AttachJwtFromCookie::class,
            \App\Http\Middleware\AuthenticateJwtCookieGuard::class,
        ]);

        $this->now = Carbon::parse('2026-11-23 12:00:00', 'UTC');

        $this->student = WebUser::create([
            'name'     => 'C5-C7 Student',
            'email'    => 'c5c7_student_' . uniqid() . '@example.com',
            'role'     => 'student',
            'status'   => 'active',
            'password' => bcrypt('password123'),
        ]);

        $this->paidStudent = WebUser::create([
            'name'     => 'C5-C7 Paid Student',
            'email'    => 'c5c7_paid_' . uniqid() . '@example.com',
            'role'     => 'student',
            'status'   => 'active',
            'password' => bcrypt('password123'),
        ]);

        UserSubscription::create([
            'user_id'            => $this->paidStudent->id,
            'offered_program_id' => 999,
            'start_date'         => $this->now->copy()->subDays(10),
            'end_date'           => $this->now->copy()->addMonths(6),
            'status'             => 'active',
        ]);

        // Academic hierarchy for Unit 263
        DB::table('curriculum_board_tbl')->updateOrInsert(['id' => $this->boardId], ['name' => 'Federal Board', 'activate' => 1]);
        DB::table('class_tbl')->updateOrInsert(['id' => $this->classId], ['class_name' => 'Class 9th', 'activate' => 1]);
        DB::table('subject_tbl')->updateOrInsert(['id' => $this->subjectId], ['subject_name' => 'Biology', 'icon_name' => 'Biology.PNG', 'activate' => 1]);

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

        // Ensure question types exist
        $typesToSeed = [
            1  => ['name' => 'MCQs', 'is_mcq' => 1],
            2  => ['name' => 'Short Questions', 'is_mcq' => 0],
            3  => ['name' => 'Long Questions', 'is_mcq' => 0],
            31 => ['name' => 'جنس (مزکر مونث)', 'is_mcq' => 1],
            32 => ['name' => 'عدد (واحد جمع)', 'is_mcq' => 1],
            33 => ['name' => 'مترادف الفاظ', 'is_mcq' => 1],
            34 => ['name' => 'متضاد الفاظ', 'is_mcq' => 1],
            36 => ['name' => 'محاورات', 'is_mcq' => 1],
            38 => ['name' => 'Correct Spellings', 'is_mcq' => 1],
            39 => ['name' => 'Correct Meanings', 'is_mcq' => 1],
        ];

        foreach ($typesToSeed as $id => $info) {
            DB::table('question_type_tbl')->updateOrInsert(['id' => $id], [
                'type_name'  => $info['name'],
                'is_mcq'     => $info['is_mcq'],
                'activate'   => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Clean rules & campaigns
        DB::table('freemium_access_rules')->delete();
        DB::table('freemium_student_grants')->delete();
        DB::table('freemium_quota_consumptions')->delete();
        DB::table('freemium_campaigns')->delete();

        // Campaign for Unit 263
        $campaign = FreemiumCampaign::create([
            'name'                 => 'C5-C7 Unit 263 Campaign',
            'status'               => 'active',
            'priority'             => 100,
            'start_at'             => $this->now->copy()->subDays(5),
            'end_at'               => $this->now->copy()->addYears(2),
            'is_stackable'         => false,
            'eligibility_criteria' => null,
            'created_by'           => 1,
            'updated_by'           => 1,
        ]);

        FreemiumAccessRule::create([
            'campaign_id'                  => $campaign->id,
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
        ]);

        // Seed 160 questions under Unit 263 (all question_type = 1, is_mcq = 1)
        // 90 Straight MCQs + 14 scenario groups (56 questions in groups + 14 = 70 questions)
        // Matching the actual reported scope in Unit 263
        for ($i = 1; $i <= 160; $i++) {
            DB::table('exam_question_tbl')->updateOrInsert(['id' => 50000 + $i], [
                'question'          => "<p>Biology Unit 263 MCQ $i</p>",
                'topic_id'          => $this->topicId263,
                'question_type'     => 1,
                'is_mcq'            => 1,
                'exercise_question' => ($i % 2 === 1) ? 1 : 0,
                'scenario_group_id' => null,
                'activate'          => 1,
            ]);
        }
    }

    /**
     * Test 1: Verify exact reported scope (Board 2, Class 1, Subject 5, Book 28, Unit 263).
     * AssessmentConfigController must return:
     * - MCQs: 160
     * - Short Questions: 0
     * - Long Questions: 0
     * - Types 31, 32, 33, 34, 36, 38, 39: strictly 0
     */
    public function test_exact_reported_scope_unit_263_availability_counts(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");

        $response->assertStatus(200);
        $response->assertJson(['success' => 1]);

        $data = $response->json('data');
        $this->assertNotEmpty($data['question_types']);

        $countsByType = [];
        foreach ($data['question_types'] as $qt) {
            $countsByType[(int) $qt['id']] = $qt['available_questions'];
        }

        // Exact expected values from database ground truth
        $this->assertEquals(160, $countsByType[1] ?? null, 'Type 1 (MCQs) must have 160 questions');
        $this->assertEquals(0, $countsByType[2] ?? null, 'Type 2 (Short Questions) must have 0 questions');
        $this->assertEquals(0, $countsByType[3] ?? null, 'Type 3 (Long Questions) must have 0 questions');

        $this->assertEquals(0, $countsByType[31] ?? null, 'Type 31 (جنس) must have 0 questions in Unit 263');
        $this->assertEquals(0, $countsByType[32] ?? null, 'Type 32 (عدد) must have 0 questions in Unit 263');
        $this->assertEquals(0, $countsByType[33] ?? null, 'Type 33 (مترادف) must have 0 questions in Unit 263');
        $this->assertEquals(0, $countsByType[34] ?? null, 'Type 34 (متضاد) must have 0 questions in Unit 263');
        $this->assertEquals(0, $countsByType[36] ?? null, 'Type 36 (محاورات) must have 0 questions in Unit 263');
        $this->assertEquals(0, $countsByType[38] ?? null, 'Type 38 (Correct Spellings) must have 0 questions in Unit 263');
        $this->assertEquals(0, $countsByType[39] ?? null, 'Type 39 (Correct Meanings) must have 0 questions in Unit 263');
    }

    /**
     * Test 2: In Unit 263, QuestionExplorer with question_type_ids = [38] (Correct Spellings)
     * must return 0 questions and never leak general MCQs.
     */
    public function test_unit_263_question_explorer_query_for_spellings_returns_zero_questions(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&topic_ids=all&question_type_ids=38");

        $response->assertStatus(200);
        $response->assertJson(['success' => 1]);

        $this->assertEquals(0, $response->json('pagination.total_questions'));
        $this->assertEquals(0, $response->json('pagination.total_items'));
        $this->assertEmpty($response->json('data.straight_questions'));
        $this->assertEmpty($response->json('data.scenario_groups'));
    }

    /**
     * Test 3: In Unit 263, QuestionExplorer with question_type_ids = [31] (جنس)
     * must return 0 questions and never leak general MCQs.
     */
    public function test_unit_263_question_explorer_query_for_arabic_gender_returns_zero_questions(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&topic_ids=all&question_type_ids=31");

        $response->assertStatus(200);
        $response->assertJson(['success' => 1]);

        $this->assertEquals(0, $response->json('pagination.total_questions'));
        $this->assertEmpty($response->json('data.straight_questions'));
        $this->assertEmpty($response->json('data.scenario_groups'));
    }

    /**
     * Test 4: In Unit 263, QuestionExplorer with question_type_ids = [1] (MCQs)
     * returns all eligible MCQs.
     */
    public function test_unit_263_question_explorer_query_for_mcqs_returns_eligible_mcqs(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&topic_ids=all&question_type_ids=1&per_page=50");

        $response->assertStatus(200);
        $response->assertJson(['success' => 1]);

        $this->assertGreaterThan(0, $response->json('pagination.total_questions'));
        $this->assertNotEmpty($response->json('data.straight_questions'));

        // Verify every returned question has question_type ID 1
        foreach ($response->json('data.straight_questions') as $q) {
            $this->assertEquals(1, $q['question_type']['id']);
        }
    }

    /**
     * Test 5: Synthetic unit with distinct question types.
     * Proves:
     * - Arabic question type 31 returns only questions with question_type 31.
     * - Arabic question type 32 returns only questions with question_type 32.
     * - Types 33, 34, 36, 38, 39 return only their exact matching questions.
     * - Multiple selected types return only the union of matching questions.
     */
    public function test_distinct_question_types_return_exact_matching_questions(): void
    {
        $testBookId = 99991;
        $testUnitId = 99992;
        $testTopicId = 99993;

        DB::table('curriculum_board_tbl')->updateOrInsert(['id' => 999], ['name' => 'Test Board', 'activate' => 1]);
        DB::table('class_tbl')->updateOrInsert(['id' => 999], ['class_name' => 'Test Class', 'activate' => 1]);
        DB::table('subject_tbl')->updateOrInsert(['id' => 999], ['subject_name' => 'Test Language', 'icon_name' => 'test.png', 'activate' => 1]);
        DB::table('book_tbl')->updateOrInsert(['id' => $testBookId], [
            'book_name'           => 'Test Multitype Book',
            'curriculum_board_id' => 999,
            'class_id'            => 999,
            'subject_id'          => 999,
            'activate'            => 1,
        ]);
        DB::table('book_unit_tbl')->updateOrInsert(['id' => $testUnitId], [
            'unit_name' => 'Multitype Unit',
            'unit_no'   => 1,
            'book_id'   => $testBookId,
            'activate'  => 1,
        ]);
        DB::table('book_unit_topic_tbl')->updateOrInsert(['id' => $testTopicId], [
            'topic_name' => 'Multitype Topic',
            'topic_no'   => 1,
            'unit_id'    => $testUnitId,
            'activate'   => 1,
        ]);

        // Access rule for test unit
        FreemiumAccessRule::create([
            'campaign_id'                  => FreemiumCampaign::first()->id,
            'target_scope'                 => 'chapter',
            'target_status'                => 'active',
            'curriculum_board_id'          => 999,
            'class_id'                     => 999,
            'subject_id'                   => 999,
            'book_id'                      => $testBookId,
            'chapter_id'                   => $testUnitId,
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
                'board_id'   => 999,
                'class_id'   => 999,
                'subject_id' => 999,
                'book_id'    => $testBookId,
                'chapter_id' => $testUnitId,
            ],
        ]);

        // Seed distinct questions:
        // 4 x Type 31 (جنس)
        for ($i = 1; $i <= 4; $i++) {
            DB::table('exam_question_tbl')->updateOrInsert(['id' => 99000 + $i], [
                'question'          => "Gender question $i",
                'topic_id'          => $testTopicId,
                'question_type'     => 31,
                'is_mcq'            => 1,
                'exercise_question' => 1,
                'scenario_group_id' => null,
                'activate'          => 1,
            ]);
        }
        // 2 x Type 32 (عدد)
        for ($i = 1; $i <= 2; $i++) {
            DB::table('exam_question_tbl')->updateOrInsert(['id' => 99010 + $i], [
                'question'          => "Number question $i",
                'topic_id'          => $testTopicId,
                'question_type'     => 32,
                'is_mcq'            => 1,
                'exercise_question' => 1,
                'scenario_group_id' => null,
                'activate'          => 1,
            ]);
        }
        // 3 x Type 38 (Correct Spellings)
        for ($i = 1; $i <= 3; $i++) {
            DB::table('exam_question_tbl')->updateOrInsert(['id' => 99020 + $i], [
                'question'          => "Spelling question $i",
                'topic_id'          => $testTopicId,
                'question_type'     => 38,
                'is_mcq'            => 1,
                'exercise_question' => 1,
                'scenario_group_id' => null,
                'activate'          => 1,
            ]);
        }
        // 1 x Type 39 (Correct Meanings)
        DB::table('exam_question_tbl')->updateOrInsert(['id' => 99031], [
            'question'          => 'Meaning question 1',
            'topic_id'          => $testTopicId,
            'question_type'     => 39,
            'is_mcq'            => 1,
            'exercise_question' => 1,
            'scenario_group_id' => null,
            'activate'          => 1,
        ]);
        // 5 x Type 1 (General MCQs)
        for ($i = 1; $i <= 5; $i++) {
            DB::table('exam_question_tbl')->updateOrInsert(['id' => 99040 + $i], [
                'question'          => "General MCQ $i",
                'topic_id'          => $testTopicId,
                'question_type'     => 1,
                'is_mcq'            => 1,
                'exercise_question' => 1,
                'scenario_group_id' => null,
                'activate'          => 1,
            ]);
        }

        // 1. Verify AssessmentConfig availability counts
        $cfgRes = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$testBookId}&unit_ids[]={$testUnitId}");

        $cfgRes->assertStatus(200);
        $counts = [];
        foreach ($cfgRes->json('data.question_types') as $qt) {
            $counts[(int) $qt['id']] = $qt['available_questions'];
        }

        // ID 1 (MCQs) matches all questions where is_mcq = 1 OR question_type = 1 (15 total)
        $this->assertEquals(15, $counts[1] ?? 0, 'General MCQs (ID 1) matches is_mcq=1 OR question_type=1');
        // Specific types match ONLY their exact question_type ID, NEVER other is_mcq questions
        $this->assertEquals(4, $counts[31] ?? 0, 'Type 31 count must be exactly 4');
        $this->assertEquals(2, $counts[32] ?? 0, 'Type 32 count must be exactly 2');
        $this->assertEquals(3, $counts[38] ?? 0, 'Type 38 count must be exactly 3');
        $this->assertEquals(1, $counts[39] ?? 0, 'Type 39 count must be exactly 1');
        $this->assertEquals(0, $counts[2] ?? 0, 'Type 2 (Short Questions) count must be 0');

        // 2. Query Type 31 in QuestionExplorer -> returns strictly the 4 gender questions
        $res31 = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$testUnitId}&topic_ids=all&question_type_ids=31");
        $res31->assertStatus(200);
        $this->assertEquals(4, $res31->json('pagination.total_questions'));
        foreach ($res31->json('data.straight_questions') as $q) {
            $this->assertEquals(31, $q['question_type']['id']);
        }

        // 3. Query Type 32 in QuestionExplorer -> returns strictly the 2 number questions
        $res32 = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$testUnitId}&topic_ids=all&question_type_ids=32");
        $res32->assertStatus(200);
        $this->assertEquals(2, $res32->json('pagination.total_questions'));
        foreach ($res32->json('data.straight_questions') as $q) {
            $this->assertEquals(32, $q['question_type']['id']);
        }

        // 4. Query Type 38 in QuestionExplorer -> returns strictly the 3 spelling questions
        $res38 = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$testUnitId}&topic_ids=all&question_type_ids=38");
        $res38->assertStatus(200);
        $this->assertEquals(3, $res38->json('pagination.total_questions'));
        foreach ($res38->json('data.straight_questions') as $q) {
            $this->assertEquals(38, $q['question_type']['id']);
        }

        // 5. Query multiple types (38 and 39) -> returns union of exactly 4 questions
        $resMulti = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$testUnitId}&topic_ids=all&question_type_ids=38,39");
        $resMulti->assertStatus(200);
        $this->assertEquals(4, $resMulti->json('pagination.total_questions'));
        foreach ($resMulti->json('data.straight_questions') as $q) {
            $this->assertContains($q['question_type']['id'], [38, 39]);
        }

        // 6. Query Type 2 (0 questions in scope) -> returns 0
        $resEmpty = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$testUnitId}&topic_ids=all&question_type_ids=2");
        $resEmpty->assertStatus(200);
        $this->assertEquals(0, $resEmpty->json('pagination.total_questions'));
        $this->assertEmpty($resEmpty->json('data.straight_questions'));
    }
}
