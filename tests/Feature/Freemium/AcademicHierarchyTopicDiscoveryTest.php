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
 * Academic Hierarchy & Topic Discovery Test Suite (Milestone B1)
 *
 * Verifies:
 *  1. Authenticated student can retrieve the authorized hierarchy.
 *  2. Unauthenticated requests are rejected.
 *  3. Paid student sees the hierarchy consistent with paid access.
 *  4. Freemium student sees only authorized hierarchy records.
 *  5. Legacy-free behavior follows existing fallback rules.
 *  6. Unauthorized books and units are not exposed.
 *  7. Campaign #2 Unit 263 is accessible.
 *  8. Campaign #2 Unit 264 is denied.
 *  9. Topic list returns only topics belonging to the selected unit.
 * 10. Single-topic selection works.
 * 11. Multi-topic selection works.
 * 12. All-topic selection works.
 * 13. Fabricated topic IDs are rejected.
 * 14. Cross-unit topic IDs are rejected.
 * 15. Cross-book and cross-subject mismatches fail closed.
 * 16. Inactive topics and units are handled correctly.
 * 17. Mixed allowed/denied topic requests fail closed where required.
 * 18. Restricted question counts are not exposed.
 * 19. Scenario availability never counts incomplete groups.
 * 20. No question text, answers, explanations, or protected topic content leaks through discovery responses.
 */
class AcademicHierarchyTopicDiscoveryTest extends TestCase
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
    private int $unitId263 = 263; // THE SCIENCE OF BIOLOGY (Authorized)
    private int $unitId264 = 264; // CELL BIOLOGY (Unauthorized)
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

        // Seed question types
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

        // Clean tables
        DB::table('freemium_access_rules')->delete();
        DB::table('freemium_student_grants')->delete();
        DB::table('freemium_quota_consumptions')->delete();
        DB::table('freemium_campaigns')->delete();

        // Seed 16 Straight MCQs under Topic 1342 (8 Exercise, 8 Supplementary)
        for ($i = 1; $i <= 16; $i++) {
            $isEx = ($i % 2 === 1) ? 1 : 0;
            DB::table('exam_question_tbl')->updateOrInsert(['id' => 16608 + $i], [
                'question'          => "<p>Straight Question $i (" . ($isEx ? 'Exercise' : 'Supplementary') . ")</p>",
                'topic_id'          => $this->topicId1342,
                'question_type'     => 1,
                'is_mcq'            => 1,
                'exercise_question' => $isEx,
                'scenario_group_id' => null,
                'activate'          => 1,
                'explanation'       => 'Secret explanation ' . $i,
            ]);
        }

        // Seed Short Questions (3 questions, question_type = 2)
        for ($i = 1; $i <= 3; $i++) {
            DB::table('exam_question_tbl')->updateOrInsert(['id' => 16700 + $i], [
                'question'          => "<p>Short Question $i</p>",
                'topic_id'          => $this->topicId1342,
                'question_type'     => 2,
                'is_mcq'            => 0,
                'exercise_question' => 1,
                'activate'          => 1,
            ]);
        }

        // Seed Long Questions (2 questions, question_type = 3)
        for ($i = 1; $i <= 2; $i++) {
            DB::table('exam_question_tbl')->updateOrInsert(['id' => 16800 + $i], [
                'question'          => "<p>Long Question $i</p>",
                'topic_id'          => $this->topicId1342,
                'question_type'     => 3,
                'is_mcq'            => 0,
                'exercise_question' => 1,
                'activate'          => 1,
            ]);
        }

        // Seed Scenario Group 101 (Valid, 4 questions, all Exercise)
        DB::table('question_scenario_groups_tbl')->updateOrInsert(['id' => 101], [
            'title'                         => 'Enzyme Kinetics Scenario',
            'scenario_text'                 => 'An experiment was conducted to measure enzyme activity.',
            'question_presentation_type_id' => 2,
            'topic_id'                      => $this->topicId1342,
            'unit_id'                       => $this->unitId263,
            'book_id'                       => $this->bookId,
            'activate'                      => 1,
        ]);
        for ($i = 1; $i <= 4; $i++) {
            DB::table('exam_question_tbl')->updateOrInsert(['id' => 18000 + $i], [
                'question'                => "<p>Enzyme Scenario Q$i</p>",
                'topic_id'                => $this->topicId1342,
                'question_type'           => 1,
                'is_mcq'                  => 1,
                'exercise_question'       => 1,
                'scenario_group_id'       => 101,
                'scenario_question_order' => $i,
                'activate'                => 1,
            ]);
        }

        // Seed Scenario Group 102 (Valid, 4 questions, all Supplementary)
        DB::table('question_scenario_groups_tbl')->updateOrInsert(['id' => 102], [
            'title'                         => 'Photosynthesis Rate Scenario',
            'scenario_text'                 => 'A plant was exposed to light.',
            'question_presentation_type_id' => 2,
            'topic_id'                      => $this->topicId1342,
            'unit_id'                       => $this->unitId263,
            'book_id'                       => $this->bookId,
            'activate'                      => 1,
        ]);
        for ($i = 1; $i <= 4; $i++) {
            DB::table('exam_question_tbl')->updateOrInsert(['id' => 18010 + $i], [
                'question'                => "<p>Photosynthesis Scenario Q$i</p>",
                'topic_id'                => $this->topicId1342,
                'question_type'           => 1,
                'is_mcq'                  => 1,
                'exercise_question'       => 0,
                'scenario_group_id'       => 102,
                'scenario_question_order' => $i,
                'activate'                => 1,
            ]);
        }

        // Seed Incomplete Scenario Group 103 (Only 3 questions - must NOT qualify under Rule of Four)
        DB::table('question_scenario_groups_tbl')->updateOrInsert(['id' => 103], [
            'title'                         => 'Incomplete Scenario',
            'scenario_text'                 => 'Incomplete text',
            'question_presentation_type_id' => 2,
            'topic_id'                      => $this->topicId1342,
            'unit_id'                       => $this->unitId263,
            'book_id'                       => $this->bookId,
            'activate'                      => 1,
        ]);
        for ($i = 1; $i <= 3; $i++) {
            DB::table('exam_question_tbl')->updateOrInsert(['id' => 18020 + $i], [
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
            'question'          => '<p>Unauthorized Unit 264 Question</p>',
            'topic_id'          => $this->topicId1351,
            'question_type'     => 1,
            'is_mcq'            => 1,
            'exercise_question' => 1,
            'scenario_group_id' => null,
            'activate'          => 1,
        ]);

        // Seed Offered Program for paid student testing
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

        // Seed Students
        $this->student = WebUser::create([
            'name'     => 'Hierarchy Freemium Student',
            'email'    => 'hier_free_' . uniqid() . '@example.com',
            'role'     => 'student',
            'status'   => 'active',
            'password' => bcrypt('password123'),
        ]);

        $this->paidStudent = WebUser::create([
            'name'     => 'Hierarchy Paid Student',
            'email'    => 'hier_paid_' . uniqid() . '@example.com',
            'role'     => 'student',
            'status'   => 'active',
            'password' => bcrypt('password123'),
        ]);

        $this->legacyStudent = WebUser::create([
            'name'     => 'Hierarchy Legacy Student',
            'email'    => 'hier_legacy_' . uniqid() . '@example.com',
            'role'     => 'student',
            'status'   => 'active',
            'password' => bcrypt('password123'),
        ]);

        $this->blockedStudent = WebUser::create([
            'name'     => 'Hierarchy Blocked Student',
            'email'    => 'hier_blocked_' . uniqid() . '@example.com',
            'role'     => 'student',
            'status'   => 'blocked',
            'password' => bcrypt('password123'),
        ]);

        // Paid student subscription
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

        // Campaign #2 (Federal Board, Class 9th, Biology, Book 28, Unit 263)
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

        // Rule for Unit 263 (MCQs and Short ALLOW, Long DENY)
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
            'perm_answers'                 => 'deny',
            'perm_explanations'            => 'deny',
            'perm_topic_contents'          => 'deny',
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
     * 1. Authenticated student can retrieve the authorized hierarchy.
     */
    public function test_authenticated_student_can_retrieve_authorized_hierarchy(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson('/web_api/auth/tests/academic-hierarchy');

        $response->assertStatus(200)
            ->assertJson(['success' => 1])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'boards',
                    'classes',
                    'subjects',
                    'books',
                ],
            ]);

        $boardIds = array_column($response->json('data.boards'), 'id');
        $this->assertContains($this->boardId, $boardIds);
    }

    /**
     * 2. Unauthenticated requests are rejected.
     */
    public function test_unauthenticated_requests_are_rejected(): void
    {
        $responseHierarchy = $this->withMiddleware()->getJson('/web_api/auth/tests/academic-hierarchy');
        $this->assertTrue(in_array($responseHierarchy->status(), [401, 302], true));

        $responseTopics = $this->withMiddleware()->getJson("/web_api/auth/tests/topics?unit_id={$this->unitId263}");
        $this->assertTrue(in_array($responseTopics->status(), [401, 302], true));
    }

    /**
     * 3. Paid student sees the hierarchy consistent with paid access.
     */
    public function test_paid_student_sees_hierarchy_consistent_with_paid_access(): void
    {
        $response = $this->actingAs($this->paidStudent, 'web_api')
            ->getJson("/web_api/auth/tests/academic-hierarchy?book_id={$this->bookId}");

        $response->assertStatus(200);
        $units = $response->json('data.units');

        // Paid student is entitled to both Unit 263 and Unit 264
        $unit263 = collect($units)->firstWhere('id', $this->unitId263);
        $unit264 = collect($units)->firstWhere('id', $this->unitId264);

        $this->assertNotNull($unit263);
        $this->assertTrue($unit263['is_entitled']);

        $this->assertNotNull($unit264);
        $this->assertTrue($unit264['is_entitled']);
    }

    /**
     * 4. Freemium student sees only authorized hierarchy records.
     */
    public function test_freemium_student_sees_only_authorized_hierarchy_records(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/academic-hierarchy?book_id={$this->bookId}");

        $response->assertStatus(200);
        $units = $response->json('data.units');

        // Freemium Campaign #2 authorizes Unit 263, but NOT Unit 264
        $unit263 = collect($units)->firstWhere('id', $this->unitId263);
        $unit264 = collect($units)->firstWhere('id', $this->unitId264);

        $this->assertNotNull($unit263);
        $this->assertTrue($unit263['is_entitled']);

        $this->assertNotNull($unit264);
        $this->assertFalse($unit264['is_entitled']);
    }

    /**
     * 5. Legacy-free behavior follows existing fallback rules.
     */
    public function test_legacy_free_behavior_follows_existing_fallback_rules(): void
    {
        DB::table('freemium_access_rules')->delete();

        $response = $this->actingAs($this->legacyStudent, 'web_api')
            ->getJson("/web_api/auth/tests/topics?unit_id={$this->unitId263}");

        // Fails closed to default deny (403)
        $response->assertStatus(403)
            ->assertJson([
                'success' => 0,
                'code'    => 'ACCESS_DENIED',
            ]);
    }

    /**
     * 6. Unauthorized books and units are not exposed.
     */
    public function test_unauthorized_books_and_units_are_not_exposed(): void
    {
        // Book 999 does not exist
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson('/web_api/auth/tests/academic-hierarchy?book_id=999999');

        $response->assertStatus(404);
    }

    /**
     * 7. Campaign #2 Unit 263 is accessible.
     */
    public function test_campaign_2_unit_263_is_accessible(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/topics?unit_id={$this->unitId263}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => 1,
                'data' => [
                    'unit' => [
                        'id' => $this->unitId263,
                    ],
                ],
            ]);

        $topics = $response->json('data.topics');
        $this->assertNotEmpty($topics);
    }

    /**
     * 8. Campaign #2 Unit 264 is denied.
     */
    public function test_campaign_2_unit_264_is_denied(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/topics?unit_id={$this->unitId264}");

        $response->assertStatus(403)
            ->assertJson([
                'success' => 0,
                'code'    => 'ACCESS_DENIED',
            ]);
    }

    /**
     * 9. Topic list returns only topics belonging to the selected unit.
     */
    public function test_topic_list_returns_only_topics_belonging_to_selected_unit(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/topics?unit_id={$this->unitId263}");

        $response->assertStatus(200);
        $topics = $response->json('data.topics');
        $topicIds = array_column($topics, 'id');

        $this->assertContains($this->topicId1342, $topicIds);
        $this->assertContains($this->topicId1343, $topicIds);
        $this->assertContains($this->topicId1344, $topicIds);
        $this->assertNotContains($this->topicId1351, $topicIds); // Topic 1351 belongs to Unit 264
    }

    /**
     * 10. Single-topic selection works.
     */
    public function test_single_topic_selection_works(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/topics?unit_id={$this->unitId263}&topic_ids[]={$this->topicId1342}");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertEquals('single', $data['selection']['mode']);
        $this->assertEquals(1, $data['selection']['selected_topics_count']);
        $this->assertCount(1, $data['topics']);
        $this->assertEquals($this->topicId1342, $data['topics'][0]['id']);
    }

    /**
     * 11. Multi-topic selection works.
     */
    public function test_multi_topic_selection_works(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/topics?unit_id={$this->unitId263}&topic_ids[]={$this->topicId1342}&topic_ids[]={$this->topicId1343}");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertEquals('multiple', $data['selection']['mode']);
        $this->assertEquals(2, $data['selection']['selected_topics_count']);
        $this->assertCount(2, $data['topics']);

        $topicIds = array_column($data['topics'], 'id');
        $this->assertContains($this->topicId1342, $topicIds);
        $this->assertContains($this->topicId1343, $topicIds);
    }

    /**
     * 12. All-topic selection works.
     */
    public function test_all_topic_selection_works(): void
    {
        // When topic_ids is omitted or 'all'
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/topics?unit_id={$this->unitId263}&topic_ids=all");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertEquals('all', $data['selection']['mode']);
        $this->assertGreaterThanOrEqual(3, $data['selection']['selected_topics_count']);
    }

    /**
     * 13. Fabricated topic IDs are rejected.
     */
    public function test_fabricated_topic_ids_are_rejected(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/topics?unit_id={$this->unitId263}&topic_ids[]=999999");

        $response->assertStatus(422)
            ->assertJson([
                'success' => 0,
                'code'    => 'HIERARCHY_MISMATCH',
            ]);
    }

    /**
     * 14. Cross-unit topic IDs are rejected.
     */
    public function test_cross_unit_topic_ids_are_rejected(): void
    {
        // Topic 1351 belongs to Unit 264, not Unit 263
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/topics?unit_id={$this->unitId263}&topic_ids[]={$this->topicId1351}");

        $response->assertStatus(422)
            ->assertJson([
                'success' => 0,
                'code'    => 'HIERARCHY_MISMATCH',
            ]);
    }

    /**
     * 15. Cross-book and cross-subject mismatches fail closed.
     */
    public function test_cross_book_and_cross_subject_mismatches_fail_closed(): void
    {
        // Unit 263 passed with mismatched book_id 999
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/topics?unit_id={$this->unitId263}&book_id=999");

        $response->assertStatus(422)
            ->assertJson([
                'success' => 0,
                'code'    => 'HIERARCHY_MISMATCH',
            ]);
    }

    /**
     * 16. Inactive topics and units are handled correctly.
     */
    public function test_inactive_topics_and_units_are_handled_correctly(): void
    {
        // Inactive topic (1349) is rejected if explicitly submitted
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/topics?unit_id={$this->unitId263}&topic_ids[]={$this->topicIdInactive}");

        $response->assertStatus(422)
            ->assertJson([
                'success' => 0,
                'code'    => 'HIERARCHY_MISMATCH',
            ]);

        // When listing all topics, inactive topic is omitted
        $responseAll = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/topics?unit_id={$this->unitId263}");

        $responseAll->assertStatus(200);
        $topicIds = array_column($responseAll->json('data.topics'), 'id');
        $this->assertNotContains($this->topicIdInactive, $topicIds);
    }

    /**
     * 17. Mixed allowed/denied topic requests fail closed where required.
     */
    public function test_mixed_allowed_denied_topic_requests_fail_closed(): void
    {
        // Topic 1342 is valid under Unit 263; Topic 1351 belongs to Unit 264.
        // Mixed submission must fail closed with 422
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/topics?unit_id={$this->unitId263}&topic_ids[]={$this->topicId1342}&topic_ids[]={$this->topicId1351}");

        $response->assertStatus(422)
            ->assertJson([
                'success' => 0,
                'code'    => 'HIERARCHY_MISMATCH',
            ]);
    }

    /**
     * 18. Restricted question counts are not exposed.
     */
    public function test_restricted_question_counts_are_not_exposed(): void
    {
        // For campaign student: perm_long_questions = deny
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/topics?unit_id={$this->unitId263}&topic_ids[]={$this->topicId1342}");

        $response->assertStatus(200);
        $topicData = $response->json('data.topics.0');

        $this->assertNotNull($topicData['availability']['straight_mcqs']);
        $this->assertNotNull($topicData['availability']['short_questions']);
        // Long questions count MUST be null/restricted
        $this->assertNull($topicData['availability']['long_questions']);
        $this->assertFalse($topicData['permissions']['perm_long_questions']);

        // For paid student: perm_long_questions = allow, so count IS exposed
        $paidResponse = $this->actingAs($this->paidStudent, 'web_api')
            ->getJson("/web_api/auth/tests/topics?unit_id={$this->unitId263}&topic_ids[]={$this->topicId1342}");

        $paidResponse->assertStatus(200);
        $paidTopicData = $paidResponse->json('data.topics.0');
        $this->assertEquals(2, $paidTopicData['availability']['long_questions']);
        $this->assertTrue($paidTopicData['permissions']['perm_long_questions']);
    }

    /**
     * 19. Scenario availability never counts incomplete groups.
     */
    public function test_scenario_availability_never_counts_incomplete_groups(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/topics?unit_id={$this->unitId263}&topic_ids[]={$this->topicId1342}");

        $response->assertStatus(200);
        $topicData = $response->json('data.topics.0');

        // Topic 1342 has Group 101 (4 MCQs), Group 102 (4 MCQs), and Group 103 (3 MCQs).
        // Only groups of 4 qualify under the Rule of Four.
        // Thus, eligible_scenario_groups = 2, and scenario_mcqs = 8 (Group 103's 3 questions are excluded!).
        $this->assertEquals(2, $topicData['availability']['eligible_scenario_groups']);
        $this->assertEquals(8, $topicData['availability']['scenario_mcqs']);
        $this->assertEquals(16, $topicData['availability']['straight_mcqs']);
        $this->assertEquals(24, $topicData['availability']['total_eligible_mcqs']);
    }

    /**
     * 20. No question text, answers, explanations, or protected topic content leaks through discovery responses.
     */
    public function test_no_question_text_answers_explanations_or_topic_content_leak(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/topics?unit_id={$this->unitId263}");

        $response->assertStatus(200);
        $content = $response->getContent();

        $this->assertStringNotContainsString('Straight Question 1', $content);
        $this->assertStringNotContainsString('Secret explanation', $content);
        $this->assertStringNotContainsString('Secret topic content', $content);
        $this->assertStringNotContainsString('is_answer', $content);
        $this->assertStringNotContainsString('Enzyme Kinetics Scenario', $content);
    }

    /**
     * 21. Single-element unit_ids array works.
     */
    public function test_single_element_unit_ids_array_works(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/topics?unit_ids[]={$this->unitId263}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => 1,
                'data' => [
                    'unit' => ['id' => $this->unitId263],
                ],
            ]);
    }

    /**
     * 22. Multiple unit_ids returns HTTP 422 MULTIPLE_UNITS_NOT_SUPPORTED.
     */
    public function test_multiple_unit_ids_returns_422_multiple_units_not_supported(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/topics?unit_ids[]={$this->unitId263}&unit_ids[]={$this->unitId264}");

        $response->assertStatus(422)
            ->assertJson([
                'success' => 0,
                'code'    => 'MULTIPLE_UNITS_NOT_SUPPORTED',
            ]);
    }

    /**
     * 23. Conflicting unit_id and unit_ids returns HTTP 422.
     */
    public function test_conflicting_unit_id_and_unit_ids_returns_422(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/topics?unit_id={$this->unitId263}&unit_ids[]={$this->unitId264}");

        $response->assertStatus(422)
            ->assertJson([
                'success' => 0,
                'code'    => 'CONFLICTING_UNIT_SELECTION',
            ]);
    }

    /**
     * 24. Duplicate unit_ids normalizes to single unit and succeeds.
     */
    public function test_duplicate_unit_ids_normalizes_and_succeeds(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/topics?unit_ids[]={$this->unitId263}&unit_ids[]={$this->unitId263}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => 1,
                'data' => [
                    'unit' => ['id' => $this->unitId263],
                ],
            ]);
    }

    /**
     * 25. Empty unit_ids array returns 422 INVALID_UNIT.
     */
    public function test_empty_unit_ids_array_returns_422(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson('/web_api/auth/tests/topics?unit_ids[]=');

        $response->assertStatus(422)
            ->assertJson([
                'success' => 0,
                'code'    => 'INVALID_UNIT',
            ]);
    }

    /**
     * 26. Non-integer, zero, and negative unit_ids return 422 INVALID_UNIT.
     */
    public function test_non_integer_zero_negative_unit_ids_return_422(): void
    {
        $responseNegative = $this->actingAs($this->student, 'web_api')
            ->getJson('/web_api/auth/tests/topics?unit_ids[]=-5');
        $responseNegative->assertStatus(422)->assertJson(['code' => 'INVALID_UNIT']);

        $responseZero = $this->actingAs($this->student, 'web_api')
            ->getJson('/web_api/auth/tests/topics?unit_ids[]=0');
        $responseZero->assertStatus(422)->assertJson(['code' => 'INVALID_UNIT']);

        $responseString = $this->actingAs($this->student, 'web_api')
            ->getJson('/web_api/auth/tests/topics?unit_ids[]=invalid_string');
        $responseString->assertStatus(422)->assertJson(['code' => 'INVALID_UNIT']);
    }

    /**
     * 27. POST and GET unit validation behave consistently.
     */
    public function test_post_and_get_unit_validation_behave_consistently(): void
    {
        $responsePost = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/topics', [
                'unit_ids' => [$this->unitId263, $this->unitId264],
            ]);

        $responsePost->assertStatus(422)
            ->assertJson([
                'success' => 0,
                'code'    => 'MULTIPLE_UNITS_NOT_SUPPORTED',
            ]);
    }

    /**
     * 28. Mixed allowed/denied unit request cannot silently process only the allowed unit.
     */
    public function test_mixed_unit_request_cannot_silently_process_only_allowed_unit(): void
    {
        // unit 263 is allowed, unit 264 is denied. Must NOT process unit 263 and ignore 264!
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/topics?unit_ids[]={$this->unitId263}&unit_ids[]={$this->unitId264}");

        $response->assertStatus(422)
            ->assertJson([
                'success' => 0,
                'code'    => 'MULTIPLE_UNITS_NOT_SUPPORTED',
            ]);
    }

    /**
     * 29. Dynamically mapped question type with non-standard DB ID is counted correctly.
     */
    public function test_dynamically_mapped_question_type_counted_correctly(): void
    {
        // Seed a new question type with a different ID (777) but with type_name 'Short Question' (mapped in config)
        DB::table('question_type_tbl')->updateOrInsert(['id' => 777], [
            'type_name'  => 'Short Question',
            'is_mcq'     => 0,
            'activate'   => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed a question of this new type under Topic 1342
        DB::table('exam_question_tbl')->updateOrInsert(['id' => 19777], [
            'question'          => '<p>Dynamic Short Question</p>',
            'topic_id'          => $this->topicId1342,
            'question_type'     => 777,
            'is_mcq'            => 0,
            'exercise_question' => 1,
            'activate'          => 1,
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/topics?unit_id={$this->unitId263}&topic_ids[]={$this->topicId1342}");

        $response->assertStatus(200);
        $shortCount = $response->json('data.topics.0.availability.short_questions');

        // We originally had 3 short questions (ID 2). Now +1 from ID 777 = 4!
        $this->assertEquals(4, $shortCount);
    }

    /**
     * 30. Unknown or unmapped question type is excluded from availability counts.
     */
    public function test_unknown_question_type_is_excluded_from_counts(): void
    {
        // Seed an unmapped type (e.g. Essay ID 13)
        DB::table('question_type_tbl')->updateOrInsert(['id' => 13], [
            'type_name'  => 'Essay',
            'is_mcq'     => 0,
            'activate'   => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('exam_question_tbl')->updateOrInsert(['id' => 19013], [
            'question'          => '<p>Essay Question</p>',
            'topic_id'          => $this->topicId1342,
            'question_type'     => 13,
            'is_mcq'            => 0,
            'exercise_question' => 1,
            'activate'          => 1,
        ]);

        $response = $this->actingAs($this->paidStudent, 'web_api')
            ->getJson("/web_api/auth/tests/topics?unit_id={$this->unitId263}&topic_ids[]={$this->topicId1342}");

        $response->assertStatus(200);
        $topicData = $response->json('data.topics.0.availability');

        // Short questions count must only count short questions (3), NOT Essay!
        $this->assertEquals(3, $topicData['short_questions']);
        // Long questions count must only count long questions (2), NOT Essay!
        $this->assertEquals(2, $topicData['long_questions']);
    }

    /**
     * 31. Inactive question type is excluded from counts.
     */
    public function test_inactive_question_type_is_excluded_from_counts(): void
    {
        // Seed an inactive Short Questions type with ID 778
        DB::table('question_type_tbl')->updateOrInsert(['id' => 778], [
            'type_name'  => 'Short Question',
            'is_mcq'     => 0,
            'activate'   => 0, // INACTIVE
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('exam_question_tbl')->updateOrInsert(['id' => 19778], [
            'question'          => '<p>Inactive Type Question</p>',
            'topic_id'          => $this->topicId1342,
            'question_type'     => 778,
            'is_mcq'            => 0,
            'exercise_question' => 1,
            'activate'          => 1,
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/topics?unit_id={$this->unitId263}&topic_ids[]={$this->topicId1342}");

        $response->assertStatus(200);
        $shortCount = $response->json('data.topics.0.availability.short_questions');

        // Remains exactly 3 (ID 778 was inactive, so it's excluded!)
        $this->assertEquals(3, $shortCount);
    }
}
