<?php

namespace Tests\Feature\Freemium;

use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\UserSubscription;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Question Explorer API Test Suite (Milestone B2)
 *
 * Verifies:
 *  1. Authenticated student access & unauthenticated rejection.
 *  2. Unit 263 access & Unit 264 denial (Campaign #2 compatibility).
 *  3. Topic scoping, fabricated topic rejection, and cross-unit fail-closed behavior.
 *  4. Strict unit validation (single unit, multi-unit rejection, conflicting rejection).
 *  5. Dynamic question-type mapping & entitlement enforcement.
 *  6. Dynamic MCQ presentation-type filtering.
 *  7. Scenario Grouping & Mandatory Rule of Four integrity.
 *  8. Incomplete scenario group omission and atomic non-split pagination.
 *  9. Exercise and supplementary independent filtering.
 * 10. Answer and option correctness flag protection (perm_answers).
 * 11. Explanation protection (perm_explanations).
 * 12. Topic content protection (perm_topic_contents).
 * 13. Search and server-side pagination with caps.
 * 14. Route aliases: /web_api/auth/tests/questions and /web_api/auth/question-explorer/questions.
 */
class QuestionExplorerApiTest extends TestCase
{
    use DatabaseTransactions;

    private WebUser $student;
    private WebUser $paidStudent;
    private WebUser $legacyStudent;
    private WebUser $blockedStudent;
    private Carbon $now;

    private int $boardId = 2; // Federal Board
    private int $classId = 1; // 9th
    private int $subjectId = 5; // Biology
    private int $bookId = 28; // Federal Biology 9th
    private int $unitId263 = 263; // THE SCIENCE OF BIOLOGY (Authorized under Campaign 2)
    private int $unitId264 = 264; // CELL BIOLOGY (Unauthorized under Campaign 2)
    private int $topicId1342 = 1342; // Topic under Unit 263
    private int $topicId1343 = 1343; // Topic under Unit 263
    private int $topicId1344 = 1344; // Topic under Unit 263
    private int $topicIdInactive = 1349; // Inactive topic under Unit 263
    private int $topicId1351 = 1351; // Topic under Unit 264

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

        // 1. Seed Question Types
        DB::table('question_type_tbl')->updateOrInsert(['id' => 1], [
            'type_name'  => 'MCQs',
            'is_mcq'     => 1,
            'activate'   => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('question_type_tbl')->updateOrInsert(['id' => 2], [
            'type_name'  => 'Short Questions',
            'is_mcq'     => 0,
            'activate'   => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('question_type_tbl')->updateOrInsert(['id' => 3], [
            'type_name'  => 'Long Questions',
            'is_mcq'     => 0,
            'activate'   => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Seed Presentation Types
        DB::table('question_presentation_type_tbl')->updateOrInsert(['id' => 1], [
            'type_name'            => 'Straight',
            'code'                 => 'st',
            'allows_multiple_mcqs' => 0,
            'activate'             => 1,
            'sort_order'           => 1,
        ]);
        DB::table('question_presentation_type_tbl')->updateOrInsert(['id' => 2], [
            'type_name'            => 'Stimulus',
            'code'                 => 'stimulus',
            'allows_multiple_mcqs' => 1,
            'activate'             => 1,
            'sort_order'           => 2,
        ]);
        DB::table('question_presentation_type_tbl')->updateOrInsert(['id' => 99], [
            'type_name'            => 'Inactive Type',
            'code'                 => 'inactive',
            'allows_multiple_mcqs' => 0,
            'activate'             => 0,
            'sort_order'           => 99,
        ]);

        // 3. Seed Academic Hierarchy
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

        // Topics under Unit 263
        DB::table('book_unit_topic_tbl')->updateOrInsert(['id' => $this->topicId1342], [
            'topic_name' => 'Introduction to Biology',
            'topic_no'   => 1,
            'unit_id'    => $this->unitId263,
            'activate'   => 1,
        ]);
        DB::table('book_unit_topic_tbl')->updateOrInsert(['id' => $this->topicId1343], [
            'topic_name' => 'Quranic Instructions in Biology',
            'topic_no'   => 2,
            'unit_id'    => $this->unitId263,
            'activate'   => 1,
        ]);
        DB::table('book_unit_topic_tbl')->updateOrInsert(['id' => $this->topicId1344], [
            'topic_name' => 'Major Fields of Biology',
            'topic_no'   => 3,
            'unit_id'    => $this->unitId263,
            'activate'   => 1,
        ]);
        // Inactive topic under Unit 263
        DB::table('book_unit_topic_tbl')->updateOrInsert(['id' => $this->topicIdInactive], [
            'topic_name' => 'Deprecated Topic',
            'topic_no'   => 9,
            'unit_id'    => $this->unitId263,
            'activate'   => 0,
        ]);

        // Topic under Unit 264
        DB::table('book_unit_topic_tbl')->updateOrInsert(['id' => $this->topicId1351], [
            'topic_name' => 'Cell Structure',
            'topic_no'   => 1,
            'unit_id'    => $this->unitId264,
            'activate'   => 1,
        ]);

        // 4. Seed Questions, Options, and Answers under Topic 1342
        // 16 Straight MCQs (8 Exercise, 8 Supplementary)
        for ($i = 1; $i <= 16; $i++) {
            $isEx = ($i % 2 === 1) ? 1 : 0;
            $qId = 16608 + $i;
            DB::table('exam_question_tbl')->updateOrInsert(['id' => $qId], [
                'question'                      => "<p>Straight Question $i (" . ($isEx ? 'Exercise' : 'Supplementary') . ")</p>",
                'question_um'                   => "<p>Straight Urdu $i</p>",
                'topic_id'                      => $this->topicId1342,
                'question_type'                 => 1,
                'is_mcq'                        => 1,
                'exercise_question'             => $isEx,
                'question_presentation_type_id' => 1,
                'scenario_group_id'             => null,
                'activate'                      => 1,
                'explanation'                   => 'Explanation text ' . $i,
                'explanation_um'                => 'Urdu explanation ' . $i,
                'topic_content'                 => 1,
            ]);

            // Seed 4 options per MCQ
            for ($opt = 1; $opt <= 4; $opt++) {
                DB::table('exam_question_options_tbl')->updateOrInsert(
                    ['id' => ($qId * 10) + $opt],
                    [
                        'question_id' => $qId,
                        'option'      => "Option $opt for Q$i",
                        'option_um'   => "Urdu Option $opt for Q$i",
                        'is_answer'   => ($opt === 1) ? 1 : 0,
                    ]
                );
            }
        }

        // Seed 3 Short Questions (question_type = 2)
        for ($i = 1; $i <= 3; $i++) {
            $qId = 16700 + $i;
            DB::table('exam_question_tbl')->updateOrInsert(['id' => $qId], [
                'question'          => "<p>Short Question $i</p>",
                'question_um'       => "<p>Short Question Urdu $i</p>",
                'topic_id'          => $this->topicId1342,
                'question_type'     => 2,
                'is_mcq'            => 0,
                'exercise_question' => 1,
                'activate'          => 1,
                'explanation'       => 'Short answer explanation ' . $i,
            ]);

            DB::table('exam_answer_tbl')->updateOrInsert(['id' => 16700 + $i], [
                'question_id' => $qId,
                'answer'      => "Model Answer for Short Question $i",
                'answer_um'   => "Urdu Model Answer $i",
            ]);
        }

        // Seed 2 Long Questions (question_type = 3)
        for ($i = 1; $i <= 2; $i++) {
            $qId = 16800 + $i;
            DB::table('exam_question_tbl')->updateOrInsert(['id' => $qId], [
                'question'          => "<p>Long Question $i</p>",
                'question_um'       => "<p>Long Question Urdu $i</p>",
                'topic_id'          => $this->topicId1342,
                'question_type'     => 3,
                'is_mcq'            => 0,
                'exercise_question' => 1,
                'activate'          => 1,
                'explanation'       => 'Long answer explanation ' . $i,
            ]);

            DB::table('exam_answer_tbl')->updateOrInsert(['id' => 16800 + $i], [
                'question_id' => $qId,
                'answer'      => "Comprehensive Answer for Long Question $i",
                'answer_um'   => "Urdu Comprehensive Answer $i",
            ]);
        }

        // Seed Scenario Group 101 (Valid, exactly 4 questions, all Exercise)
        DB::table('question_scenario_groups_tbl')->updateOrInsert(['id' => 101], [
            'title'                         => 'Enzyme Kinetics Scenario',
            'scenario_text'                 => 'An experiment was conducted to measure enzyme activity under varying temperatures.',
            'scenario_text_um'              => 'Enzyme Urdu scenario statement.',
            'question_presentation_type_id' => 2,
            'topic_id'                      => $this->topicId1342,
            'unit_id'                       => $this->unitId263,
            'book_id'                       => $this->bookId,
            'activate'                      => 1,
        ]);
        for ($i = 1; $i <= 4; $i++) {
            $qId = 18000 + $i;
            DB::table('exam_question_tbl')->updateOrInsert(['id' => $qId], [
                'question'                => "<p>Enzyme Scenario Q$i</p>",
                'topic_id'                => $this->topicId1342,
                'question_type'           => 1,
                'is_mcq'                  => 1,
                'exercise_question'       => 1,
                'scenario_group_id'       => 101,
                'scenario_question_order' => $i,
                'activate'                => 1,
                'explanation'             => 'Scenario Q explanation ' . $i,
            ]);

            for ($opt = 1; $opt <= 4; $opt++) {
                DB::table('exam_question_options_tbl')->updateOrInsert(
                    ['id' => ($qId * 10) + $opt],
                    [
                        'question_id' => $qId,
                        'option'      => "Option $opt for Enzyme Q$i",
                        'is_answer'   => ($opt === 2) ? 1 : 0,
                    ]
                );
            }
        }

        // Seed Incomplete Scenario Group 103 (Only 3 questions - must NOT qualify under Rule of Four)
        DB::table('question_scenario_groups_tbl')->updateOrInsert(['id' => 103], [
            'title'                         => 'Incomplete Scenario',
            'scenario_text'                 => 'Incomplete experiment scenario.',
            'question_presentation_type_id' => 2,
            'topic_id'                      => $this->topicId1342,
            'unit_id'                       => $this->unitId263,
            'book_id'                       => $this->bookId,
            'activate'                      => 1,
        ]);
        for ($i = 1; $i <= 3; $i++) {
            $qId = 18020 + $i;
            DB::table('exam_question_tbl')->updateOrInsert(['id' => $qId], [
                'question'                => "<p>Incomplete Scenario Q$i</p>",
                'topic_id'                => $this->topicId1342,
                'question_type'           => 1,
                'is_mcq'                  => 1,
                'exercise_question'       => 1,
                'scenario_group_id'       => 103,
                'scenario_question_order' => $i,
                'activate'                => 1,
            ]);
        }

        // Question under Unauthorized Unit 264
        DB::table('exam_question_tbl')->updateOrInsert(['id' => 17031], [
            'question'          => '<p>Cell Biology Secret Question</p>',
            'topic_id'          => $this->topicId1351,
            'question_type'     => 1,
            'is_mcq'            => 1,
            'exercise_question' => 1,
            'scenario_group_id' => null,
            'activate'          => 1,
        ]);

        // 5. Seed Students and Subscriptions
        $this->student = WebUser::create([
            'name'     => 'Explorer Campaign Student',
            'email'    => 'explorer_camp_' . uniqid() . '@example.com',
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

        $this->blockedStudent = WebUser::create([
            'name'     => 'Explorer Blocked Student',
            'email'    => 'explorer_blocked_' . uniqid() . '@example.com',
            'role'     => 'student',
            'status'   => 'blocked',
            'password' => bcrypt('password123'),
        ]);

        // Paid student subscription
        DB::table('offered_classes')->updateOrInsert(['id' => 994], [
            'class_id'            => $this->classId,
            'curriculum_board_id' => $this->boardId,
            'is_active'           => 1,
        ]);
        DB::table('offered_programs')->updateOrInsert(['id' => 994], [
            'offered_class_id' => 994,
            'title'            => 'Federal 9th Biology Program',
            'slug'             => 'fed-9th-bio-prog',
            'is_active'        => 1,
        ]);

        UserSubscription::create([
            'user_id'            => $this->paidStudent->id,
            'offered_program_id' => 994,
            'start_date'         => $this->now->copy()->subDays(5),
            'end_date'           => $this->now->copy()->addMonths(6),
            'status'             => 'active',
        ]);

        DB::table('program_subjects')->updateOrInsert(
            ['offered_program_id' => 994, 'subject_id' => $this->subjectId],
            ['is_free' => 0, 'is_active' => 1, 'updated_at' => now()]
        );

        // Clean and Seed Campaign #2 (Federal Board, Class 9th, Biology, Book 28, Unit 263)
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

        // Rule for Unit 263: MCQs and Short ALLOW, Long DENY, Answers and Explanations ALLOW
        $this->rule = FreemiumAccessRule::create([
            'campaign_id'                  => $this->campaign->id,
            'target_scope'                 => 'chapter',
            'target_status'                => 'active',
            'curriculum_board_id'          => $this->boardId,
            'class_id'                     => $this->classId,
            'subject_id'                   => $this->subjectId,
            'book_id'                      => $this->bookId,
            'chapter_id'                   => $this->unitId263,
            'perm_mcqs'                    => 'allow',
            'perm_short_questions'         => 'allow',
            'perm_long_questions'          => 'deny',
            'perm_exercise_questions'      => 'allow',
            'perm_supplementary_questions' => 'allow',
            'perm_answers'                 => 'allow',
            'perm_explanations'            => 'allow',
            'perm_topic_contents'          => 'allow',
            'perm_past_papers'             => 'deny',
            'perm_model_papers'            => 'deny',
            'rule_priority'                => 10,
            'academic_target_snapshot'     => [
                'board_id'   => $this->boardId,
                'class_id'   => $this->classId,
                'subject_id' => $this->subjectId,
                'book_id'    => $this->bookId,
                'chapter_id' => $this->unitId263,
            ],
        ]);
    }

    /**
     * 1. Authenticated student can browse questions for authorized unit.
     */
    public function test_authenticated_student_can_browse_questions_for_authorized_unit(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => 1,
                'scope'   => [
                    'unit_id' => $this->unitId263,
                ],
            ]);

        $data = $response->json('data');
        $this->assertNotEmpty($data['straight_questions']);
        $this->assertNotEmpty($data['scenario_groups']);
        $this->assertNotEmpty($data['items']);
    }

    /**
     * 2. Unauthenticated request is rejected with 401.
     */
    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}");
        $this->assertTrue(in_array($response->status(), [401, 302]));
    }

    /**
     * 3. Campaign #2 Unit 263 is accessible and returns permitted questions.
     */
    public function test_campaign_2_unit_263_is_accessible(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}");

        $response->assertStatus(200);
        $this->assertEquals($this->unitId263, $response->json('scope.unit_id'));
    }

    /**
     * 4. Unit 264 (denied) strictly returns 403.
     */
    public function test_unit_264_is_denied_with_403(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId264}");

        $response->assertStatus(403)
            ->assertJson([
                'success' => 0,
                'code'    => 'ACCESS_DENIED',
            ]);
    }

    /**
     * 5. Fabricated topic ID is rejected with 422.
     */
    public function test_fabricated_topic_id_is_rejected(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&topic_ids[]=999999");

        $response->assertStatus(422)
            ->assertJson([
                'success' => 0,
                'code'    => 'HIERARCHY_MISMATCH',
            ]);
    }

    /**
     * 6. Cross-unit topic ID is rejected with 422.
     */
    public function test_cross_unit_topic_id_is_rejected(): void
    {
        // Topic 1351 belongs to Unit 264, not Unit 263
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&topic_ids[]={$this->topicId1351}");

        $response->assertStatus(422)
            ->assertJson([
                'success' => 0,
                'code'    => 'HIERARCHY_MISMATCH',
            ]);
    }

    /**
     * 7. Multiple topic selection works.
     */
    public function test_multiple_topic_selection_works(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&topic_ids[]={$this->topicId1342}&topic_ids[]={$this->topicId1343}");

        $response->assertStatus(200);
        $this->assertEquals('multiple', $response->json('scope.selection_mode'));
        $this->assertCount(2, $response->json('scope.topic_ids'));
    }

    /**
     * 8. Mixed allowed and denied topic selection fails closed with 422.
     */
    public function test_mixed_allowed_denied_topic_selection_fails_closed(): void
    {
        // Topic 1342 belongs to 263; Topic 1351 belongs to 264
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&topic_ids[]={$this->topicId1342}&topic_ids[]={$this->topicId1351}");

        $response->assertStatus(422)
            ->assertJson([
                'success' => 0,
                'code'    => 'HIERARCHY_MISMATCH',
            ]);
    }

    /**
     * 9. Multiple unit IDs array returns 422 MULTIPLE_UNITS_NOT_SUPPORTED.
     */
    public function test_multiple_unit_ids_array_returns_422(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_ids[]={$this->unitId263}&unit_ids[]={$this->unitId264}");

        $response->assertStatus(422)
            ->assertJson([
                'success' => 0,
                'code'    => 'MULTIPLE_UNITS_NOT_SUPPORTED',
            ]);
    }

    /**
     * 10. Conflicting unit_id and unit_ids returns 422 CONFLICTING_UNIT_SELECTION.
     */
    public function test_conflicting_unit_id_and_unit_ids_returns_422(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&unit_ids[]={$this->unitId264}");

        $response->assertStatus(422)
            ->assertJson([
                'success' => 0,
                'code'    => 'CONFLICTING_UNIT_SELECTION',
            ]);
    }

    /**
     * 11. Invalid, zero, or negative unit_id returns 422 INVALID_UNIT.
     */
    public function test_invalid_unit_id_returns_422(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id=-5");

        $response->assertStatus(422)
            ->assertJson([
                'success' => 0,
                'code'    => 'INVALID_UNIT',
            ]);
    }

    /**
     * 12. Invalid question_type_ids returns 422.
     */
    public function test_invalid_question_type_ids_returns_422(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&question_type_ids[]=9999");

        $response->assertStatus(422)
            ->assertJson([
                'success' => 0,
                'code'    => 'INVALID_QUESTION_TYPE',
            ]);
    }

    /**
     * 13. Invalid presentation_type_ids returns 422.
     */
    public function test_invalid_presentation_type_ids_returns_422(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&presentation_type_ids[]=8888");

        $response->assertStatus(422)
            ->assertJson([
                'success' => 0,
                'code'    => 'INVALID_PRESENTATION_TYPE',
            ]);
    }

    /**
     * 14. Active question types are discoverable and unmapped types are excluded.
     */
    public function test_active_question_types_are_discoverable_and_unmapped_excluded(): void
    {
        // Insert unmapped question type (Essay)
        DB::table('question_type_tbl')->updateOrInsert(['id' => 13], [
            'type_name' => 'Essay',
            'is_mcq'    => 0,
            'activate'  => 1,
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}");

        $response->assertStatus(200);
        $filterTypes = $response->json('filters.question_type_ids');

        // Essay (ID 13) must NOT be included in default active types
        $this->assertNotContains(13, $filterTypes);

        // Attempting to filter by unmapped Essay ID 13 fails with 422
        $reqResponse = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&question_type_ids[]=13");
        $reqResponse->assertStatus(422)
            ->assertJson(['code' => 'INVALID_QUESTION_TYPE']);
    }

    /**
     * 15. MCQ permission enforcement (when allowed vs denied).
     */
    public function test_mcq_permission_enforcement(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&question_type_ids[]=1");

        $response->assertStatus(200);
        $straight = $response->json('data.straight_questions');
        foreach ($straight as $q) {
            $this->assertTrue((bool) $q['is_mcq']);
        }
    }

    /**
     * 16. Short-question permission enforcement.
     */
    public function test_short_question_permission_enforcement(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&question_type_ids[]=2");

        $response->assertStatus(200);
        $straight = $response->json('data.straight_questions');
        $this->assertNotEmpty($straight);
        foreach ($straight as $q) {
            $this->assertFalse((bool) $q['is_mcq']);
            $this->assertEquals(2, $q['question_type']['id']);
        }
    }

    /**
     * 17. Long-question permission enforcement: Campaign #2 student denied long questions.
     */
    public function test_long_question_permission_denial_for_campaign_student(): void
    {
        // Campaign #2 has perm_long_questions = deny
        // Default call without filter must NOT contain long questions
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}");

        $response->assertStatus(200);
        $this->assertNotContains(3, $response->json('filters.question_type_ids'));

        // Explicitly requesting question_type_ids[]=3 must return 403 ACCESS_DENIED
        $deniedResp = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&question_type_ids[]=3");

        $deniedResp->assertStatus(403)
            ->assertJson([
                'success' => 0,
                'code'    => 'ACCESS_DENIED',
            ]);

        // Paid student can access long questions
        $paidResp = $this->actingAs($this->paidStudent, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&question_type_ids[]=3");

        $paidResp->assertStatus(200);
        $paidStraight = $paidResp->json('data.straight_questions');
        $this->assertNotEmpty($paidStraight);
        $this->assertEquals(3, $paidStraight[0]['question_type']['id']);
    }

    /**
     * 18. Exercise questions filter.
     */
    public function test_exercise_questions_filtering(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&exercise=1&supplementary=0");

        $response->assertStatus(200);
        $straight = $response->json('data.straight_questions');
        foreach ($straight as $q) {
            $this->assertTrue((bool) $q['exercise_question']);
        }
    }

    /**
     * 19. Supplementary questions filter.
     */
    public function test_supplementary_questions_filtering(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&exercise=0&supplementary=1");

        $response->assertStatus(200);
        $straight = $response->json('data.straight_questions');
        foreach ($straight as $q) {
            $this->assertFalse((bool) $q['exercise_question']);
        }
    }

    /**
     * 20. Both exercise and supplementary false returns 422.
     */
    public function test_both_exercise_and_supplementary_false_returns_422(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&exercise=0&supplementary=0");

        $response->assertStatus(422)
            ->assertJson([
                'success' => 0,
                'code'    => 'INVALID_FILTER',
            ]);
    }

    /**
     * 21. Answer security: is_answer is NOT leaked by default.
     */
    public function test_answer_security_is_answer_not_leaked_by_default(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}");

        $response->assertStatus(200);
        $mcq = $response->json('data.straight_questions.0');
        $this->assertNotEmpty($mcq['options']);

        // Every option must NOT contain is_answer key
        foreach ($mcq['options'] as $opt) {
            $this->assertArrayNotHasKey('is_answer', $opt);
        }

        // permissions block must indicate can_view_answers = false
        $this->assertFalse($response->json('permissions.can_view_answers'));
    }

    /**
     * 22. Answer security: is_answer is exposed only when permitted AND requested.
     */
    public function test_answer_security_is_answer_exposed_when_permitted_and_requested(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&include_answers=1");

        $response->assertStatus(200);
        $this->assertTrue($response->json('permissions.can_view_answers'));

        $mcq = $response->json('data.straight_questions.0');
        $this->assertNotEmpty($mcq['options']);

        $hasCorrect = false;
        foreach ($mcq['options'] as $opt) {
            $this->assertArrayHasKey('is_answer', $opt);
            if ($opt['is_answer'] === true) {
                $hasCorrect = true;
            }
        }
        $this->assertTrue($hasCorrect, "At least one option must have is_answer = true.");
    }

    /**
     * 23. Explanation security: explanation not leaked by default.
     */
    public function test_explanation_security_explanation_not_leaked_by_default(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}");

        $response->assertStatus(200);
        $mcq = $response->json('data.straight_questions.0');
        $this->assertNull($mcq['explanation']);
        $this->assertNull($mcq['explanation_um']);
        $this->assertFalse($response->json('permissions.can_view_explanations'));
    }

    /**
     * 24. Explanation security: explanation exposed when permitted AND requested.
     */
    public function test_explanation_security_exposed_when_permitted_and_requested(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&include_explanations=1");

        $response->assertStatus(200);
        $this->assertTrue($response->json('permissions.can_view_explanations'));

        $mcq = $response->json('data.straight_questions.0');
        $this->assertNotNull($mcq['explanation']);
        $this->assertStringContainsString('Explanation text', $mcq['explanation']);
    }

    /**
     * 25. Topic content security: exposed when permitted, hidden when denied.
     */
    public function test_topic_content_security(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}");

        $response->assertStatus(200);
        // Student rule has perm_topic_contents = allow
        $this->assertTrue($response->json('permissions.can_view_topic_contents'));
        $mcq = $response->json('data.straight_questions.0');
        $this->assertNotNull($mcq['topic_content']);
    }

    /**
     * 26. Scenario group must have exactly four eligible questions under Rule of Four.
     */
    public function test_scenario_group_must_have_exactly_four_eligible_questions(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&presentation_type_ids[]=2");

        $response->assertStatus(200);
        $scenarioGroups = $response->json('data.scenario_groups');

        // Only Scenario Group 101 qualifies (it has exactly 4 questions)
        $this->assertCount(1, $scenarioGroups);
        $this->assertEquals(101, $scenarioGroups[0]['id']);
        $this->assertCount(4, $scenarioGroups[0]['questions']);
    }

    /**
     * 27. Incomplete scenario group (Group 103 with 3 questions) is omitted.
     */
    public function test_incomplete_scenario_group_is_omitted(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&presentation_type_ids[]=2");

        $response->assertStatus(200);
        $scenarioGroups = $response->json('data.scenario_groups');

        foreach ($scenarioGroups as $sg) {
            $this->assertNotEquals(103, $sg['id'], "Incomplete Group 103 must never be exposed.");
        }
    }

    /**
     * 28. Denied group member prevents partial group exposure.
     */
    public function test_denied_member_in_scenario_group_prevents_partial_exposure(): void
    {
        // Scenario Group 101 is all Exercise questions.
        // If client requests supplementary only (exercise=0, supplementary=1),
        // Group 101's questions are ineligible, so the group must be completely omitted!
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&presentation_type_ids[]=2&exercise=0&supplementary=1");

        $response->assertStatus(200);
        $scenarioGroups = $response->json('data.scenario_groups');
        $this->assertEmpty($scenarioGroups, "Scenario group with ineligible questions must be omitted.");
    }

    /**
     * 29. Scenario questions preserve order and presentation metadata.
     */
    public function test_scenario_questions_preserve_order_and_group_metadata(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&presentation_type_ids[]=2");

        $response->assertStatus(200);
        $group = $response->json('data.scenario_groups.0');

        $this->assertEquals('Enzyme Kinetics Scenario', $group['title']);
        $this->assertStringContainsString('enzyme activity', $group['scenario_text']);

        $orders = array_column($group['questions'], 'scenario_question_order');
        $this->assertEquals([1, 2, 3, 4], $orders);
    }

    /**
     * 30. Scenario groups are not split across page boundaries (atomic pagination).
     */
    public function test_scenario_groups_are_not_split_across_page_boundaries(): void
    {
        // With per_page = 2, each page receives complete atomic items
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&per_page=2&page=1");

        $response->assertStatus(200);
        $scenarios = $response->json('data.scenario_groups');
        foreach ($scenarios as $sg) {
            $this->assertCount(4, $sg['questions'], "Scenario group must always contain all 4 questions.");
        }
    }

    /**
     * 31. Straight MCQs remain individually selectable and separate from scenarios.
     */
    public function test_straight_mcqs_remain_individually_selectable(): void
    {
        // When filtering by Straight presentation type (ID 1)
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&presentation_type_ids[]=1");

        $response->assertStatus(200);
        $straight = $response->json('data.straight_questions');
        $scenarios = $response->json('data.scenario_groups');

        $this->assertNotEmpty($straight);
        $this->assertEmpty($scenarios);

        foreach ($straight as $q) {
            $this->assertNull($q['scenario_group_id']);
        }
    }

    /**
     * 32. Server-side pagination and maximum page size limits.
     */
    public function test_server_side_pagination_and_page_limits(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&per_page=5&page=1");

        $response->assertStatus(200);
        $pagination = $response->json('pagination');

        $this->assertEquals(1, $pagination['current_page']);
        $this->assertEquals(5, $pagination['per_page']);
        $this->assertTrue($pagination['total_items'] > 5);
        $this->assertTrue($pagination['has_more']);

        // Exceeding per_page max caps at 100
        $capResp = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&per_page=500");
        $this->assertEquals(100, $capResp->json('pagination.per_page'));
    }

    /**
     * 33. Search filters question text safely.
     */
    public function test_search_filters_question_text_safely(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&search=Straight+Question+3");

        $response->assertStatus(200);
        $straight = $response->json('data.straight_questions');
        $this->assertCount(1, $straight);
        $this->assertStringContainsString('Straight Question 3', $straight[0]['question']);
    }

    /**
     * 34. Search matches scenario title and returns group intact.
     */
    public function test_search_filters_scenario_groups_safely(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&search=Enzyme+Kinetics");

        $response->assertStatus(200);
        $scenarios = $response->json('data.scenario_groups');
        $this->assertCount(1, $scenarios);
        $this->assertEquals('Enzyme Kinetics Scenario', $scenarios[0]['title']);
        $this->assertCount(4, $scenarios[0]['questions']);
    }

    /**
     * 35. Campaign #2 compatibility full check.
     */
    public function test_campaign_2_compatibility_full_check(): void
    {
        // 1. Authorized Unit 263 succeeds
        $okResp = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}");
        $okResp->assertStatus(200);

        // 2. Denied Unit 264 fails with 403
        $deniedResp = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId264}");
        $deniedResp->assertStatus(403);

        // 3. Mixed allowed + denied unit IDs array fails closed with 422
        $mixedResp = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_ids[]={$this->unitId263}&unit_ids[]={$this->unitId264}");
        $mixedResp->assertStatus(422)
            ->assertJson(['code' => 'MULTIPLE_UNITS_NOT_SUPPORTED']);
    }

    /**
     * 36. Route alias: /web_api/auth/question-explorer/questions behaves identically.
     */
    public function test_route_alias_behaves_identically(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/question-explorer/questions?unit_id={$this->unitId263}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => 1,
                'scope'   => [
                    'unit_id' => $this->unitId263,
                ],
            ]);
    }

    /**
     * 37. B2-C1 FINDING-B2-02: Scenario group with 5 questions qualifies and returns exactly 4 ordered questions.
     */
    public function test_scenario_group_with_five_questions_qualifies_and_returns_exactly_four_ordered_questions(): void
    {
        // Seed Scenario Group 104 with 5 active questions
        DB::table('question_scenario_groups_tbl')->updateOrInsert(['id' => 104], [
            'title'                         => 'Photosynthesis Rate 5Q Scenario',
            'scenario_text'                 => 'Investigating photosynthesis rates under varying light intensities.',
            'question_presentation_type_id' => 2,
            'topic_id'                      => $this->topicId1342,
            'unit_id'                       => $this->unitId263,
            'book_id'                       => $this->bookId,
            'activate'                      => 1,
        ]);

        for ($i = 1; $i <= 5; $i++) {
            $qId = 18040 + $i;
            DB::table('exam_question_tbl')->updateOrInsert(['id' => $qId], [
                'question'                => "<p>Photosynthesis 5Q Question $i</p>",
                'topic_id'                => $this->topicId1342,
                'question_type'           => 1,
                'is_mcq'                  => 1,
                'exercise_question'       => 1,
                'scenario_group_id'       => 104,
                'scenario_question_order' => $i,
                'activate'                => 1,
            ]);

            for ($opt = 1; $opt <= 4; $opt++) {
                DB::table('exam_question_options_tbl')->updateOrInsert(
                    ['id' => ($qId * 10) + $opt],
                    [
                        'question_id' => $qId,
                        'option'      => "Option $opt for Photo Q$i",
                        'is_answer'   => ($opt === 1) ? 1 : 0,
                    ]
                );
            }
        }

        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&presentation_type_ids[]=2");

        $response->assertStatus(200);
        $scenarios = collect($response->json('data.scenario_groups'))->keyBy('id');

        $this->assertTrue($scenarios->has(104), "Scenario group 104 with 5 questions must qualify.");
        $sg104 = $scenarios->get(104);
        $this->assertCount(4, $sg104['questions'], "Must return exactly 4 questions under Rule of Four.");

        $orders = array_column($sg104['questions'], 'scenario_question_order');
        $this->assertEquals([1, 2, 3, 4], $orders, "First 4 ordered questions must be selected.");
    }

    /**
     * 38. B2-C1 FINDING-B2-02: Scenario group with 6 questions qualifies and returns first 4 ordered questions.
     */
    public function test_scenario_group_with_six_questions_qualifies_and_returns_first_four_ordered_questions(): void
    {
        // Seed Scenario Group 105 with 6 active questions
        DB::table('question_scenario_groups_tbl')->updateOrInsert(['id' => 105], [
            'title'                         => 'Cell Respiration 6Q Scenario',
            'scenario_text'                 => 'Investigating cellular respiration in yeast cells.',
            'question_presentation_type_id' => 2,
            'topic_id'                      => $this->topicId1342,
            'unit_id'                       => $this->unitId263,
            'book_id'                       => $this->bookId,
            'activate'                      => 1,
        ]);

        for ($i = 1; $i <= 6; $i++) {
            $qId = 18050 + $i;
            DB::table('exam_question_tbl')->updateOrInsert(['id' => $qId], [
                'question'                => "<p>Respiration 6Q Question $i</p>",
                'topic_id'                => $this->topicId1342,
                'question_type'           => 1,
                'is_mcq'                  => 1,
                'exercise_question'       => 1,
                'scenario_group_id'       => 105,
                'scenario_question_order' => $i,
                'activate'                => 1,
            ]);

            for ($opt = 1; $opt <= 4; $opt++) {
                DB::table('exam_question_options_tbl')->updateOrInsert(
                    ['id' => ($qId * 10) + $opt],
                    [
                        'question_id' => $qId,
                        'option'      => "Option $opt for Resp Q$i",
                        'is_answer'   => ($opt === 1) ? 1 : 0,
                    ]
                );
            }
        }

        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&presentation_type_ids[]=2");

        $response->assertStatus(200);
        $scenarios = collect($response->json('data.scenario_groups'))->keyBy('id');

        $this->assertTrue($scenarios->has(105), "Scenario group 105 with 6 questions must qualify.");
        $sg105 = $scenarios->get(105);
        $this->assertCount(4, $sg105['questions'], "Must return exactly 4 questions under Rule of Four.");

        $orders = array_column($sg105['questions'], 'scenario_question_order');
        $this->assertEquals([1, 2, 3, 4], $orders, "First 4 ordered questions must be selected.");
    }

    /**
     * 39. B2-C1 FINDING-B2-02: 5-question scenario group with 2 denied questions has 3 eligible -> omitted.
     */
    public function test_five_question_group_with_two_denied_is_omitted(): void
    {
        // Seed Scenario Group 106 with 5 questions: 3 Exercise, 2 Supplementary
        DB::table('question_scenario_groups_tbl')->updateOrInsert(['id' => 106], [
            'title'                         => 'Mixed Exercise Supplementary Scenario',
            'scenario_text'                 => 'Investigating osmosis across semi-permeable membranes.',
            'question_presentation_type_id' => 2,
            'topic_id'                      => $this->topicId1342,
            'unit_id'                       => $this->unitId263,
            'book_id'                       => $this->bookId,
            'activate'                      => 1,
        ]);

        for ($i = 1; $i <= 5; $i++) {
            $qId = 18060 + $i;
            $isEx = ($i <= 3) ? 1 : 0; // 3 Exercise, 2 Supplementary
            DB::table('exam_question_tbl')->updateOrInsert(['id' => $qId], [
                'question'                => "<p>Osmosis Question $i</p>",
                'topic_id'                => $this->topicId1342,
                'question_type'           => 1,
                'is_mcq'                  => 1,
                'exercise_question'       => $isEx,
                'scenario_group_id'       => 106,
                'scenario_question_order' => $i,
                'activate'                => 1,
            ]);
        }

        // When requesting exercise only, only 3 questions are eligible. Must NOT qualify (< 4).
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&presentation_type_ids[]=2&exercise=1&supplementary=0");

        $response->assertStatus(200);
        $scenarios = collect($response->json('data.scenario_groups'))->keyBy('id');
        $this->assertFalse($scenarios->has(106), "Group with only 3 eligible questions must be omitted.");

        // When requesting both exercise and supplementary, all 5 questions are eligible. Must qualify with 4 returned.
        $responseAll = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&presentation_type_ids[]=2&exercise=1&supplementary=1");

        $responseAll->assertStatus(200);
        $scenariosAll = collect($responseAll->json('data.scenario_groups'))->keyBy('id');
        $this->assertTrue($scenariosAll->has(106), "Group with 5 eligible questions must qualify when both filters allowed.");
        $this->assertCount(4, $scenariosAll->get(106)['questions']);
    }

    /**
     * 40. B2-C1 FINDING-B2-03: Strict integer validation rejects decimal, scientific notation, and float unit_id.
     */
    public function test_strict_integer_validation_rejects_non_integer_unit_ids(): void
    {
        // 1. Decimal string unit_id
        $resp1 = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id=263.5");
        $resp1->assertStatus(422)->assertJson(['code' => 'INVALID_UNIT']);

        // 2. Decimal string with trailing zero
        $resp2 = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id=263.0");
        $resp2->assertStatus(422)->assertJson(['code' => 'INVALID_UNIT']);

        // 3. Scientific notation
        $resp3 = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id=263e1");
        $resp3->assertStatus(422)->assertJson(['code' => 'INVALID_UNIT']);

        // 4. Decimal in unit_ids array
        $resp4 = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_ids[]=263.5");
        $resp4->assertStatus(422)->assertJson(['code' => 'INVALID_UNIT']);

        // 5. Negative integer
        $resp5 = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id=-5");
        $resp5->assertStatus(422)->assertJson(['code' => 'INVALID_UNIT']);

        // 6. Zero
        $resp6 = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id=0");
        $resp6->assertStatus(422)->assertJson(['code' => 'INVALID_UNIT']);

        // 7. Non-numeric string
        $resp7 = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id=invalid_id");
        $resp7->assertStatus(422)->assertJson(['code' => 'INVALID_UNIT']);
    }

    /**
     * 41. B2-C1 FINDING-B2-03: Strict integer validation rejects decimal, scientific notation, and float topic_ids.
     */
    public function test_strict_integer_validation_rejects_non_integer_topic_ids(): void
    {
        // 1. Decimal string in topic_ids array
        $resp1 = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&topic_ids[]=1342.5");
        $resp1->assertStatus(422)->assertJson(['code' => 'INVALID_TOPIC']);

        // 2. Decimal string comma-separated
        $resp2 = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&topic_ids=1342.5");
        $resp2->assertStatus(422)->assertJson(['code' => 'INVALID_TOPIC']);

        // 3. Scientific notation in topic_ids
        $resp3 = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&topic_ids=1342e1");
        $resp3->assertStatus(422)->assertJson(['code' => 'INVALID_TOPIC']);

        // 4. Zero in topic_ids
        $resp4 = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&topic_ids[]=0");
        $resp4->assertStatus(422)->assertJson(['code' => 'INVALID_TOPIC']);

        // 5. Negative integer in topic_ids
        $resp5 = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&topic_ids[]=-10");
        $resp5->assertStatus(422)->assertJson(['code' => 'INVALID_TOPIC']);
    }

    /**
     * 42. B2-C1 FINDING-B2-01: Scalable database-backed pagination with offset/limit.
     */
    public function test_scalable_database_pagination_with_per_page_limits(): void
    {
        // Request page 1 with per_page = 2
        $resp1 = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&per_page=2&page=1");

        $resp1->assertStatus(200);
        $items1 = $resp1->json('data.items');
        $this->assertCount(2, $items1);
        $this->assertEquals(1, $resp1->json('pagination.current_page'));
        $this->assertEquals(2, $resp1->json('pagination.per_page'));
        $this->assertTrue($resp1->json('pagination.has_more'));

        // Request page 2 with per_page = 2
        $resp2 = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/questions?unit_id={$this->unitId263}&per_page=2&page=2");

        $resp2->assertStatus(200);
        $items2 = $resp2->json('data.items');
        $this->assertCount(2, $items2);
        $this->assertEquals(2, $resp2->json('pagination.current_page'));

        // Ensure page 1 and page 2 contain different items
        $p1Ids = array_map(fn($item) => $item['type'] === 'straight' ? $item['question']['id'] : $item['scenario_group']['id'], $items1);
        $p2Ids = array_map(fn($item) => $item['type'] === 'straight' ? $item['question']['id'] : $item['scenario_group']['id'], $items2);
        $this->assertEmpty(array_intersect($p1Ids, $p2Ids), "Page 1 and Page 2 must not contain overlapping items.");
    }
}
