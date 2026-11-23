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
 * Assessment Configuration API Test Suite (Milestone A)
 *
 * Verifies:
 *  1. Authenticated student can retrieve configuration for an authorized scope.
 *  2. Unauthenticated requests are rejected.
 *  3. Paid student receives configuration consistent with paid access.
 *  4. Eligible Freemium student receives configuration consistent with campaign permissions.
 *  5. Legacy-free access follows existing fallback rules.
 *  6. Unauthorized student cannot retrieve protected configuration.
 *  7. Campaign #2 unit 263 is handled according to its actual rule.
 *  8. Campaign #2 unit 264 remains denied.
 *  9. Question types are sourced from database records, not hardcoded IDs.
 * 10. Presentation types are sourced dynamically from active database records.
 * 11. Inactive presentation types are excluded.
 * 12. Scenario availability requires complete groups of four.
 * 13. A scope with no scenario groups does not falsely advertise scenario availability.
 * 14. Exercise and supplementary permissions are independently enforced.
 * 15. Invalid or cross-hierarchy topic/unit combinations fail closed.
 * 16. No question content, answer keys, explanations, or protected topic content leak through the configuration response.
 */
class AssessmentConfigurationApiTest extends TestCase
{
    use DatabaseTransactions;

    private WebUser $student;
    private WebUser $paidStudent;
    private WebUser $legacyStudent;
    private Carbon $now;

    private int $boardId = 2; // Federal Board
    private int $classId = 1; // 9th
    private int $subjectId = 5; // Biology
    private int $bookId = 28; // Federal Biology 9th
    private int $unitId263 = 263; // THE SCIENCE OF BIOLOGY (Authorized)
    private int $unitId264 = 264; // CELL BIOLOGY (Unauthorized)
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

        // Seed 16 Straight MCQs under Unit 263 (8 Exercise, 8 Supplementary)
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
                'explanation'       => 'Secret explanation ' . $i,
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

        // Seed Scenario Group 101 (Valid, 4 questions, all Exercise)
        DB::table('question_scenario_groups_tbl')->updateOrInsert(['id' => 101], [
            'title'                         => 'Enzyme Kinetics Scenario',
            'scenario_text'                 => 'An experiment was conducted to measure enzyme activity.',
            'scenario_text_um'              => 'ایک تجربہ کیا گیا',
            'scenario_image'                => 'enzymes.jpg',
            'question_presentation_type_id' => 2,
            'topic_id'                      => $this->topicId263,
            'unit_id'                       => $this->unitId263,
            'book_id'                       => $this->bookId,
            'activate'                      => 1,
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

        // Seed Scenario Group 102 (Valid, 4 questions, all Supplementary)
        DB::table('question_scenario_groups_tbl')->updateOrInsert(['id' => 102], [
            'title'                         => 'Photosynthesis Rate Scenario',
            'scenario_text'                 => 'A plant was exposed to light at varying wavelengths.',
            'scenario_text_um'              => 'روشنی کا تجربہ',
            'scenario_image'                => 'photosynthesis.jpg',
            'question_presentation_type_id' => 2,
            'topic_id'                      => $this->topicId263,
            'unit_id'                       => $this->unitId263,
            'book_id'                       => $this->bookId,
            'activate'                      => 1,
        ]);
        for ($i = 1; $i <= 4; $i++) {
            DB::table('exam_question_tbl')->updateOrInsert(['id' => 18010 + $i], [
                'question'                => "<p>Photosynthesis Scenario Q$i</p>",
                'topic_id'                => $this->topicId263,
                'question_type'           => 1,
                'is_mcq'                  => 1,
                'exercise_question'       => 0,
                'scenario_group_id'       => 102,
                'scenario_question_order' => $i,
                'activate'                => 1,
            ]);
        }

        // Seed Incomplete Scenario Group 103 (Only 3 questions - must NOT qualify)
        DB::table('question_scenario_groups_tbl')->updateOrInsert(['id' => 103], [
            'title'                         => 'Incomplete Scenario',
            'scenario_text'                 => 'Incomplete text',
            'question_presentation_type_id' => 2,
            'topic_id'                      => $this->topicId263,
            'unit_id'                       => $this->unitId263,
            'book_id'                       => $this->bookId,
            'activate'                      => 1,
        ]);
        for ($i = 1; $i <= 3; $i++) {
            DB::table('exam_question_tbl')->updateOrInsert(['id' => 18020 + $i], [
                'question'                => "<p>Incomplete Scenario Q$i</p>",
                'topic_id'                => $this->topicId263,
                'question_type'           => 1,
                'is_mcq'                  => 1,
                'exercise_question'       => 1,
                'scenario_group_id'       => 103,
                'scenario_question_order' => $i,
                'activate'                => 1,
            ]);
        }

        // Seed Question Types
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

        // Seed Presentation Types
        DB::table('question_presentation_type_tbl')->updateOrInsert(['id' => 1], [
            'type_name'            => 'Straight',
            'code'                 => 'st',
            'description'          => 'Standard single questions',
            'allows_multiple_mcqs' => 0,
            'activate'             => 1,
            'sort_order'           => 1,
        ]);
        DB::table('question_presentation_type_tbl')->updateOrInsert(['id' => 2], [
            'type_name'            => 'Stimulus',
            'code'                 => 'stimulus',
            'description'          => 'Scenario / stimulus based groups',
            'allows_multiple_mcqs' => 1,
            'activate'             => 1,
            'sort_order'           => 2,
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
            'name'     => 'Config Free Student',
            'email'    => 'config_free_' . uniqid() . '@example.com',
            'role'     => 'student',
            'status'   => 'active',
            'password' => bcrypt('password123'),
        ]);

        $this->paidStudent = WebUser::create([
            'name'     => 'Config Paid Student',
            'email'    => 'config_paid_' . uniqid() . '@example.com',
            'role'     => 'student',
            'status'   => 'active',
            'password' => bcrypt('password123'),
        ]);

        $this->legacyStudent = WebUser::create([
            'name'     => 'Config Legacy Student',
            'email'    => 'config_legacy_' . uniqid() . '@example.com',
            'role'     => 'student',
            'status'   => 'active',
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

        // Rule for Unit 263
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
     * 1. Authenticated student can retrieve configuration for an authorized scope.
     */
    public function test_authenticated_student_can_retrieve_configuration_for_authorized_scope(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => 1,
            ])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'question_types',
                    'mcq_presentation_types',
                    'assessment_composition_modes',
                    'scenario_percentage_config',
                    'scope_availability',
                ],
            ]);
    }

    /**
     * 2. Unauthenticated requests are rejected.
     */
    public function test_unauthenticated_requests_are_rejected(): void
    {
        // Re-enable middleware to test real unauthenticated rejection
        $response = $this->withMiddleware()->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");
        $this->assertTrue(in_array($response->status(), [401, 302], true));
    }

    /**
     * 3. Paid student receives configuration consistent with paid access.
     */
    public function test_paid_student_receives_configuration_consistent_with_paid_access(): void
    {
        $response = $this->actingAs($this->paidStudent, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertTrue($data['scope_availability']['permissions']['perm_mcqs']);
        $this->assertTrue($data['scope_availability']['permissions']['perm_short_questions']);
        $this->assertTrue($data['scope_availability']['permissions']['perm_long_questions']);
        $this->assertTrue($data['scope_availability']['permissions']['perm_exercise_questions']);
        $this->assertTrue($data['scope_availability']['permissions']['perm_supplementary_questions']);
    }

    /**
     * 4. Eligible Freemium student receives configuration consistent with campaign permissions.
     */
    public function test_eligible_freemium_student_receives_configuration_consistent_with_campaign_permissions(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");

        $response->assertStatus(200);
        $data = $response->json('data');

        // Campaign #2 grants MCQs and Short, but DENIES Long questions
        $this->assertTrue($data['scope_availability']['permissions']['perm_mcqs']);
        $this->assertTrue($data['scope_availability']['permissions']['perm_short_questions']);
        $this->assertFalse($data['scope_availability']['permissions']['perm_long_questions']);

        // Check returned question types: MCQs and Short present; Long questions omitted
        $typeCodes = array_column($data['question_types'], 'code');
        $this->assertContains('mcq', $typeCodes);
        $this->assertContains('short', $typeCodes);
        $this->assertNotContains('long', $typeCodes);
    }

    /**
     * 5. Legacy-free access follows existing fallback rules.
     */
    public function test_legacy_free_access_follows_existing_fallback_rules(): void
    {
        // Delete all freemium rules so student falls to default deny or legacy fallback
        DB::table('freemium_access_rules')->delete();

        $response = $this->actingAs($this->legacyStudent, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");

        // In standard 4-tier model without legacy free flags set, default deny returns 403
        $response->assertStatus(403)
            ->assertJson([
                'success' => 0,
                'code'    => 'ACCESS_DENIED',
            ]);
    }

    /**
     * 6. Unauthorized student cannot retrieve protected configuration.
     */
    public function test_unauthorized_student_cannot_retrieve_protected_configuration(): void
    {
        $otherStudent = WebUser::create([
            'name'     => 'Blocked Student',
            'email'    => 'blocked_' . uniqid() . '@example.com',
            'role'     => 'student',
            'status'   => 'blocked',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->actingAs($otherStudent, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");

        $response->assertStatus(401);
    }

    /**
     * 7. Campaign #2 unit 263 is handled according to its actual rule.
     */
    public function test_campaign_2_unit_263_is_handled_according_to_its_actual_rule(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->postJson("/web_api/auth/tests/assessment-configuration", [
                'book_id'  => $this->bookId,
                'unit_ids' => [$this->unitId263],
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertEquals($this->bookId, $data['scope_availability']['book_id']);
        $this->assertEquals([$this->unitId263], $data['scope_availability']['unit_ids']);
        $this->assertTrue($data['scope_availability']['is_scenario_supported']);
    }

    /**
     * 8. Campaign #2 unit 264 remains denied.
     */
    public function test_campaign_2_unit_264_remains_denied(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId264}");

        $response->assertStatus(403)
            ->assertJson([
                'success' => 0,
                'code'    => 'ACCESS_DENIED',
            ]);
    }

    /**
     * 9. Question types are sourced from database records, not hardcoded IDs.
     */
    public function test_question_types_are_sourced_from_database_records_not_hardcoded_ids(): void
    {
        $dbShortType = DB::table('question_type_tbl')->where('type_name', 'Short Questions')->first();
        $this->assertNotNull($dbShortType);

        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");

        $response->assertStatus(200);
        $questionTypes = $response->json('data.question_types');
        $shortType = collect($questionTypes)->firstWhere('code', 'short');

        $this->assertNotNull($shortType);
        $this->assertEquals((int) $dbShortType->id, $shortType['id']);
        $this->assertEquals($dbShortType->type_name, $shortType['type_name']);

        // Deactivating the record in the database removes it from the API
        DB::table('question_type_tbl')->where('id', $dbShortType->id)->update(['activate' => 0]);

        $response2 = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");

        $response2->assertStatus(200);
        $typeCodes = array_column($response2->json('data.question_types'), 'code');
        $this->assertNotContains('short', $typeCodes);
    }

    /**
     * 10. Presentation types are sourced dynamically from active database records.
     */
    public function test_presentation_types_are_sourced_dynamically_from_active_database_records(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");

        $response->assertStatus(200);
        $presentationTypes = $response->json('data.mcq_presentation_types');
        $typeNames = array_column($presentationTypes, 'type_name');

        $this->assertContains('Straight', $typeNames);
        $this->assertContains('Stimulus', $typeNames);
    }

    /**
     * 11. Inactive presentation types are excluded.
     */
    public function test_inactive_presentation_types_are_excluded(): void
    {
        // Deactivate Stimulus presentation type
        DB::table('question_presentation_type_tbl')->where('id', 2)->update(['activate' => 0]);

        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");

        $response->assertStatus(200);
        $presentationTypes = $response->json('data.mcq_presentation_types');
        $typeNames = array_column($presentationTypes, 'type_name');

        $this->assertContains('Straight', $typeNames);
        $this->assertNotContains('Stimulus', $typeNames);
    }

    /**
     * 12. Scenario availability requires complete groups of four.
     */
    public function test_scenario_availability_requires_complete_groups_of_four(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");

        $response->assertStatus(200);
        $data = $response->json('data');

        // We seeded two groups of 4 (101 and 102) and one group of 3 (103)
        // Group 103 MUST be excluded by the Rule of Four
        $this->assertEquals(2, $data['scope_availability']['eligible_scenario_groups_count']);
        $this->assertEquals(8, $data['scope_availability']['scenario_mcq_count']);
    }

    /**
     * 13. A scope with no scenario groups does not falsely advertise scenario availability.
     */
    public function test_scope_with_no_scenario_groups_does_not_falsely_advertise_scenario_availability(): void
    {
        // Deactivate all scenario groups
        DB::table('question_scenario_groups_tbl')->update(['activate' => 0]);

        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertEquals(0, $data['scope_availability']['eligible_scenario_groups_count']);
        $this->assertEquals(0, $data['scope_availability']['scenario_mcq_count']);
        $this->assertFalse($data['scope_availability']['is_scenario_supported']);

        // Scenario presentation type should be omitted
        $presCodes = array_column($data['mcq_presentation_types'], 'code');
        $this->assertNotContains('stimulus', $presCodes);

        // Composition mode scenario should be unavailable
        $scenarioMode = collect($data['assessment_composition_modes'])->firstWhere('mode', 'scenario');
        $this->assertFalse($scenarioMode['is_available']);

        // Slider config default should be 0 and is_applicable should be false
        $this->assertEquals(0, $data['scenario_percentage_config']['default']);
        $this->assertFalse($data['scenario_percentage_config']['is_applicable']);
    }

    /**
     * 14. Exercise and supplementary permissions are independently enforced.
     */
    public function test_exercise_and_supplementary_permissions_are_independently_enforced(): void
    {
        // Update campaign rule to DENY supplementary questions
        $this->rule->update(['perm_supplementary_questions' => 'DENY']);

        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");

        $response->assertStatus(200);
        $data = $response->json('data');

        // Out of 16 straight questions: 8 Exercise, 8 Supplementary. With supplementary DENY, only 8 remain.
        $this->assertEquals(8, $data['scope_availability']['straight_mcq_count']);

        // Scenario 101 has 4 exercise questions; Scenario 102 has 4 supplementary questions.
        // With supplementary DENY, only Scenario 101 qualifies (1 group, 4 questions).
        $this->assertEquals(1, $data['scope_availability']['eligible_scenario_groups_count']);
        $this->assertEquals(4, $data['scope_availability']['scenario_mcq_count']);
    }

    /**
     * 15. Invalid or cross-hierarchy topic/unit combinations fail closed.
     */
    public function test_invalid_or_cross_hierarchy_topic_unit_combinations_fail_closed(): void
    {
        // Unit 263 passed with Topic 1351 (which belongs to Unit 264)
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}&topic_ids[]={$this->topicId264}");

        $response->assertStatus(422)
            ->assertJson([
                'success' => 0,
                'code'    => 'HIERARCHY_MISMATCH',
            ]);
    }

    /**
     * 16. No question content, answer keys, explanations, or protected topic content leak through the configuration response.
     */
    public function test_no_question_content_answers_explanations_or_topic_content_leak(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");

        $response->assertStatus(200);
        $content = $response->getContent();

        // Ensure sensitive question content never appears in config response
        $this->assertStringNotContainsString('Straight Question 1', $content);
        $this->assertStringNotContainsString('Secret explanation', $content);
        $this->assertStringNotContainsString('Secret topic content', $content);
        $this->assertStringNotContainsString('is_answer', $content);
    }

    /**
     * 17. MCQ maps to perm_mcqs.
     */
    public function test_mcq_maps_to_perm_mcqs(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");

        $response->assertStatus(200);
        $questionTypes = $response->json('data.question_types');
        $mcqType = collect($questionTypes)->firstWhere('code', 'mcq');

        $this->assertNotNull($mcqType);
        $this->assertTrue($mcqType['is_mcq']);
        $this->assertTrue($mcqType['is_entitled']);
    }

    /**
     * 18. Short Questions map to perm_short_questions.
     */
    public function test_short_questions_map_to_perm_short_questions(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");

        $response->assertStatus(200);
        $questionTypes = $response->json('data.question_types');
        $shortType = collect($questionTypes)->firstWhere('code', 'short');

        $this->assertNotNull($shortType);
        $this->assertEquals('Short Questions', $shortType['type_name']);
        $this->assertFalse($shortType['is_mcq']);
    }

    /**
     * 19. Long Questions map to perm_long_questions.
     */
    public function test_long_questions_map_to_perm_long_questions(): void
    {
        // For campaign student with perm_long_questions = deny, Long Questions is omitted
        $responseDenied = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");
        $responseDenied->assertStatus(200);
        $typeCodes = array_column($responseDenied->json('data.question_types'), 'code');
        $this->assertNotContains('long', $typeCodes);

        // For paid student with perm_long_questions = allow, Long Questions is present
        $responseAllowed = $this->actingAs($this->paidStudent, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");
        $responseAllowed->assertStatus(200);
        $paidTypes = $responseAllowed->json('data.question_types');
        $longType = collect($paidTypes)->firstWhere('code', 'long');

        $this->assertNotNull($longType);
        $this->assertEquals('Long Questions', $longType['type_name']);
        $this->assertFalse($longType['is_mcq']);
    }

    /**
     * 20. Unknown question type is excluded.
     */
    public function test_unknown_question_type_is_excluded(): void
    {
        DB::table('question_type_tbl')->updateOrInsert(['id' => 888], [
            'type_name'  => 'Unknown Advanced Type',
            'is_mcq'     => 0,
            'activate'   => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->paidStudent, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");

        $response->assertStatus(200);
        $questionTypes = $response->json('data.question_types');
        $typeNames = array_column($questionTypes, 'type_name');

        $this->assertNotContains('Unknown Advanced Type', $typeNames);
    }

    /**
     * 21. A type named Essay cannot inherit perm_short_questions.
     */
    public function test_essay_cannot_inherit_perm_short_questions(): void
    {
        DB::table('question_type_tbl')->updateOrInsert(['id' => 889], [
            'type_name'  => 'Essay',
            'is_mcq'     => 0,
            'activate'   => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Student has perm_short_questions = allow, but Essay must NOT inherit it
        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");

        $response->assertStatus(200);
        $questionTypes = $response->json('data.question_types');
        $typeNames = array_column($questionTypes, 'type_name');

        $this->assertNotContains('Essay', $typeNames);
    }

    /**
     * 22. A type named Letter cannot inherit perm_short_questions.
     */
    public function test_letter_cannot_inherit_perm_short_questions(): void
    {
        DB::table('question_type_tbl')->updateOrInsert(['id' => 890], [
            'type_name'  => 'Letter',
            'is_mcq'     => 0,
            'activate'   => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");

        $response->assertStatus(200);
        $questionTypes = $response->json('data.question_types');
        $typeNames = array_column($questionTypes, 'type_name');

        $this->assertNotContains('Letter', $typeNames);
    }

    /**
     * 23. A type named Summary cannot inherit perm_short_questions.
     */
    public function test_summary_cannot_inherit_perm_short_questions(): void
    {
        DB::table('question_type_tbl')->updateOrInsert(['id' => 891], [
            'type_name'  => 'Summary',
            'is_mcq'     => 0,
            'activate'   => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");

        $response->assertStatus(200);
        $questionTypes = $response->json('data.question_types');
        $typeNames = array_column($questionTypes, 'type_name');

        $this->assertNotContains('Summary', $typeNames);
    }

    /**
     * 24. A malformed or missing mapping fails closed.
     */
    public function test_malformed_or_missing_mapping_fails_closed(): void
    {
        // Test with empty configuration
        config(['freemium.question_type_permissions.explicit_types' => []]);

        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");

        $response->assertStatus(200);
        $questionTypes = $response->json('data.question_types');
        $this->assertEmpty($questionTypes);

        // Test with malformed configuration (missing dimension or code)
        config(['freemium.question_type_permissions.explicit_types' => [
            'short questions' => ['invalid_key' => 'bar'],
        ]]);

        $response2 = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");

        $response2->assertStatus(200);
        $this->assertEmpty($response2->json('data.question_types'));
    }

    /**
     * 25. A denied permission excludes its corresponding question type.
     */
    public function test_denied_permission_excludes_corresponding_question_type(): void
    {
        // Deny MCQs in rule
        $this->rule->update(['perm_mcqs' => 'deny']);

        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");

        $response->assertStatus(200);
        $typeCodes = array_column($response->json('data.question_types'), 'code');

        $this->assertNotContains('mcq', $typeCodes);
        $this->assertContains('short', $typeCodes);
    }

    /**
     * 26. An allowed permission exposes its corresponding question type.
     */
    public function test_allowed_permission_exposes_corresponding_question_type(): void
    {
        // Allow Long Questions in rule
        $this->rule->update(['perm_long_questions' => 'allow']);

        $response = $this->actingAs($this->student, 'web_api')
            ->getJson("/web_api/auth/tests/assessment-configuration?book_id={$this->bookId}&unit_ids[]={$this->unitId263}");

        $response->assertStatus(200);
        $typeCodes = array_column($response->json('data.question_types'), 'code');

        $this->assertContains('mcq', $typeCodes);
        $this->assertContains('short', $typeCodes);
        $this->assertContains('long', $typeCodes);
    }
}
