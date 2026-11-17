<?php

namespace Tests\Feature;

use App\Models\TeacherClass;
use App\Models\TeacherProfile;
use App\Models\TeacherSubscriptionFeature;
use App\Models\WebUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class TeacherSubjectResourceScopeTest extends TeacherBackendTest
{
    private TeacherProfile $teacherProfile;
    private TeacherProfile $otherTeacherProfile;
    private TeacherClass $ptbPhysicsClass;
    private TeacherClass $federalPhysicsClass;
    private TeacherClass $ptbBiologyClass;
    private TeacherClass $archivedPtbClass;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLegacyResourceTables();
        $this->seedLegacyResourceData();

        $subscriptions = app(\App\Http\Services\TeacherSubscriptionService::class);

        $this->teacherProfile = TeacherProfile::where('web_user_id', 2)->firstOrFail();
        $this->teacherProfile->forceFill(['status' => 'active'])->save();
        $subscriptions->assignDefaultFree($this->teacherProfile->id);

        $this->otherTeacherProfile = TeacherProfile::where('web_user_id', 3)->firstOrFail();
        $this->otherTeacherProfile->forceFill(['status' => 'active'])->save();
        $subscriptions->assignDefaultFree($this->otherTeacherProfile->id);

        // Class 1: Punjab Curriculum Board (1), Grade 10 (1), Physics (1)
        $this->ptbPhysicsClass = $this->createClass($this->teacherProfile, [
            'curriculum_board_id' => 1,
            'class_id' => 1,
            'subject_id' => 1,
            'name' => 'Grade 10 Physics PTB',
        ]);

        // Class 2: Federal Curriculum Board (2), SAME Grade 10 (1), SAME Physics (1)
        $this->federalPhysicsClass = $this->createClass($this->teacherProfile, [
            'curriculum_board_id' => 2,
            'class_id' => 1,
            'subject_id' => 1,
            'name' => 'Grade 10 Physics Federal',
        ]);

        // Class 3: Punjab Curriculum Board (1), Grade 10 (1), Biology (2)
        $this->ptbBiologyClass = $this->createClass($this->teacherProfile, [
            'curriculum_board_id' => 1,
            'class_id' => 1,
            'subject_id' => 2,
            'name' => 'Grade 10 Biology PTB',
        ]);

        // Class 4: Archived PTB Physics class
        $this->archivedPtbClass = $this->createClass($this->teacherProfile, [
            'curriculum_board_id' => 1,
            'class_id' => 1,
            'subject_id' => 1,
            'name' => 'Archived Physics',
            'section' => 'Arch',
            'is_active' => false,
        ]);
    }

    public function test_teacher_receives_strictly_scoped_resources_across_all_categories(): void
    {
        $this->actingAs($this->teacherProfile->webUser, 'web_api');

        $overview = $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/overview")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('counts.textbooks', 1)
            ->assertJsonPath('counts.units', 1)
            ->assertJsonPath('counts.topics', 1)
            ->assertJsonPath('counts.slos', 1)
            ->assertJsonPath('counts.mcq_questions', 2)
            ->assertJsonPath('counts.model_papers', 1)
            ->assertJsonPath('counts.past_papers_structured', 1)
            ->assertJsonPath('counts.past_papers_catalog', 1)
            ->assertJsonPath('counts.past_papers_total', 2);

        $this->assertEquals(
            ['PTB Physics 10'],
            collect($overview->json('filters.books'))->pluck('book_name')->all()
        );

        // Textbooks & Textbook detail
        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/textbooks")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 10)
            ->assertJsonPath('data.0.book_name', 'PTB Physics 10')
            ->assertJsonPath('data.0.mcq_count', 2);

        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/textbooks/10")
            ->assertOk()
            ->assertJsonPath('book.book_name', 'PTB Physics 10')
            ->assertJsonPath('units.0.id', 100)
            ->assertJsonPath('units.0.topics.0.id', 1000);

        // Units & Topic Content Structures (topic_content_structure_tbl -> topic_content_type_tbl)
        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/units")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.topics.0.content_types.0.name', 'Key Definition');

        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/topics/1000/content")
            ->assertOk()
            ->assertJsonPath('topic.topic_name', 'Simple Harmonic Motion')
            ->assertJsonPath('content_structures.0.name', 'Key Definition')
            ->assertJsonPath('topic_slos.0.slo_code', 'SLO-P-10-01')
            ->assertJsonPath('unit_slos.0.slo_code', 'SLO-P-10-01');

        // SLOs scoped to (curriculum_board_id=1, class_id=1, subject_id=1)
        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/slos")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slo_code', 'SLO-P-10-01')
            ->assertJsonPath('data.0.units.0.id', 100)
            ->assertJsonPath('data.0.topics.0.id', 1000);

        // MCQ Question Bank (Read Only) with authorized teacher answer keys & explanations
        $questionsRes = $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/questions?per_page=10")
            ->assertOk()
            ->assertJsonPath('policy.read_only', true)
            ->assertJsonPath('policy.answer_keys_exposed', true)
            ->assertJsonPath('policy.explanations_exposed', true)
            ->assertJsonCount(2, 'data');

        $firstQ = $questionsRes->json('data.0');
        $this->assertEquals('SECRET_EXPLANATION_MUST_NOT_LEAK', $firstQ['explanation']);
        $this->assertEquals('SECRET_UM', $firstQ['explanation_um']);
        $this->assertTrue($firstQ['has_explanation']);
        $this->assertArrayNotHasKey('explanation_video_url', $firstQ);
        $this->assertArrayNotHasKey('scenario_image', $firstQ['scenario_group'] ?? []);
        $this->assertNotEmpty($firstQ['options']);
        $hasCorrectOption = false;
        foreach ($firstQ['options'] as $opt) {
            $this->assertArrayHasKey('is_answer', $opt);
            if ($opt['is_answer']) {
                $hasCorrectOption = true;
            }
        }
        $this->assertTrue($hasCorrectOption);
        // Examination board metadata preserved cleanly separately from curriculum_board_tbl
        $this->assertEquals('BISE Lahore', $firstQ['board_links'][0]['board_name']);
        $this->assertEquals(2024, $firstQ['board_links'][0]['year']);

        // Filters work (SLO filter via topic/unit mapping, difficulty, cognitive domain, stimulus format, exam board)
        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/questions?slo_topic_id=500&mcq_format=stimulus&difficulty=2&cognitive_domain=U&board_id=11&session_id=1&year=2024")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 2002)
            ->assertJsonPath('data.0.scenario_group.title', 'Pendulum Scenario');

        // Model Papers scoped to curriculum_board_id=1, class_id=1, subject_id=1
        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/model-papers")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 300)
            ->assertJsonPath('data.0.title', 'PTB Physics Model Paper 2026');

        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/model-papers/300")
            ->assertOk()
            ->assertJsonPath('model_paper.title', 'PTB Physics Model Paper 2026')
            ->assertJsonPath('parts.0.sections.0.questions.0.question_sections.0.sub_sections.0.pairing_schemes.0.unit_id', 100);
    }

    public function test_same_grade_and_subject_under_different_curriculum_board_are_isolated(): void
    {
        $this->actingAs($this->teacherProfile->webUser, 'web_api');

        // Federal Board class (curriculum_board_id=2) must ONLY see Federal textbooks, SLOs, MCQs, and Model Papers
        $this->getJson("/web_api/auth/teacher/classes/{$this->federalPhysicsClass->id}/resources/textbooks")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 20)
            ->assertJsonPath('data.0.book_name', 'Federal Physics 10');

        $this->getJson("/web_api/auth/teacher/classes/{$this->federalPhysicsClass->id}/resources/slos")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slo_code', 'SLO-FED-10-01');

        $this->getJson("/web_api/auth/teacher/classes/{$this->federalPhysicsClass->id}/resources/questions")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 2003);

        $this->getJson("/web_api/auth/teacher/classes/{$this->federalPhysicsClass->id}/resources/model-papers")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 301);

        // Manipulated PTB IDs submitted against Federal class must return 403
        $this->getJson("/web_api/auth/teacher/classes/{$this->federalPhysicsClass->id}/resources/textbooks/10")
            ->assertForbidden()->assertJsonPath('code', 'resource_out_of_class_scope');
        $this->getJson("/web_api/auth/teacher/classes/{$this->federalPhysicsClass->id}/resources/units?book_id=10")
            ->assertForbidden()->assertJsonPath('code', 'resource_out_of_class_scope');
        $this->getJson("/web_api/auth/teacher/classes/{$this->federalPhysicsClass->id}/resources/topics/1000/content")
            ->assertForbidden()->assertJsonPath('code', 'resource_out_of_class_scope');
        $this->getJson("/web_api/auth/teacher/classes/{$this->federalPhysicsClass->id}/resources/questions?unit_id=100")
            ->assertForbidden()->assertJsonPath('code', 'resource_out_of_class_scope');
        $this->getJson("/web_api/auth/teacher/classes/{$this->federalPhysicsClass->id}/resources/questions?topic_id=1000")
            ->assertForbidden()->assertJsonPath('code', 'resource_out_of_class_scope');
        $this->getJson("/web_api/auth/teacher/classes/{$this->federalPhysicsClass->id}/resources/questions?slo_topic_id=500")
            ->assertForbidden()->assertJsonPath('code', 'resource_out_of_class_scope');
        $this->getJson("/web_api/auth/teacher/classes/{$this->federalPhysicsClass->id}/resources/model-papers/300")
            ->assertForbidden()->assertJsonPath('code', 'resource_out_of_class_scope');
    }

    public function test_past_papers_preserve_exam_board_metadata_and_stream_without_exposing_s3_paths(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('prod/tst-past-papers/701/objective/page-1.jpeg', 'STRUCTURED_IMAGE_BYTES');
        Storage::disk('s3')->put('prod/tst-past-papers/701/objective/thumb-1.jpeg', 'STRUCTURED_THUMB_BYTES');
        Storage::disk('s3')->put('Phys_10_Lhr_2024_G1.webp', 'CATALOG_IMAGE_BYTES');

        $this->actingAs($this->teacherProfile->webUser, 'web_api');

        $listRes = $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/past-papers")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $rawListJson = json_encode($listRes->json());
        $this->assertStringNotContainsString('prod/tst-past-papers', $rawListJson);
        $this->assertStringNotContainsString('Phys_10_Lhr_2024_G1.webp', $rawListJson);

        // Structured paper detail & authorized proxy download
        $detailRes = $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/past-papers/structured/701")
            ->assertOk()
            ->assertJsonPath('paper.exam_board.name', 'BISE Lahore')
            ->assertJsonPath('paper.exam_session.name', 'Annual')
            ->assertJsonPath('paper.year', 2026)
            ->assertJsonCount(1, 'pages');

        $detailJson = json_encode($detailRes->json());
        $this->assertStringNotContainsString('image_path', $detailJson);
        $this->assertStringNotContainsString('thumbnail_path', $detailJson);
        $this->assertStringNotContainsString('prod/tst-past-papers', $detailJson);

        $fileRes = $this->get("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/past-papers/structured/701/pages/7001/file");
        $fileRes->assertOk();
        $this->assertSame('STRUCTURED_IMAGE_BYTES', $fileRes->getContent());
        $fileRes->assertHeader('X-Content-Type-Options', 'nosniff');

        $thumbRes = $this->get("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/past-papers/structured/701/pages/7001/file?variant=thumb");
        $thumbRes->assertOk();
        $this->assertSame('STRUCTURED_THUMB_BYTES', $thumbRes->getContent());

        // Catalog paper detail & proxy download
        $catDetailRes = $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/past-papers/catalog/physics-10-lahore-2024")
            ->assertOk()
            ->assertJsonPath('paper.exam_board.name', 'BISE Lahore')
            ->assertJsonPath('paper.year', 2024)
            ->assertJsonCount(1, 'pages');

        $this->assertStringNotContainsString('Phys_10_Lhr_2024_G1.webp', json_encode($catDetailRes->json()));

        $catFileRes = $this->get("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/past-papers/catalog/physics-10-lahore-2024/pages/8001/file");
        $catFileRes->assertOk();
        $this->assertSame('CATALOG_IMAGE_BYTES', $catFileRes->getContent());

        // Cross-subject class (Biology class) cannot inspect or download Physics past paper
        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbBiologyClass->id}/resources/past-papers")
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbBiologyClass->id}/resources/past-papers/structured/701")
            ->assertForbidden();
        $this->get("/web_api/auth/teacher/classes/{$this->ptbBiologyClass->id}/resources/past-papers/structured/701/pages/7001/file")
            ->assertForbidden();
    }

    public function test_idor_archived_class_entitlement_and_role_guards_are_enforced(): void
    {
        // Unauthenticated without active session -> rejected (403 when JWT cookie guard bypassed in base test, 401 when active)
        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/overview")
            ->assertForbidden();
        $this->withMiddleware([\App\Http\Middleware\AuthenticateJwtCookieGuard::class])
            ->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/overview")
            ->assertUnauthorized();
        $this->withoutMiddleware([\App\Http\Middleware\AuthenticateJwtCookieGuard::class]);

        // Student role -> 403
        $student = WebUser::findOrFail(1);
        $this->actingAs($student, 'web_api');
        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/overview")
            ->assertForbidden();

        // Other teacher (IDOR) -> 403 across endpoints
        $this->actingAs($this->otherTeacherProfile->webUser, 'web_api');
        foreach (['overview', 'textbooks', 'units', 'slos', 'questions', 'past-papers', 'model-papers'] as $segment) {
            $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/{$segment}")
                ->assertForbidden()->assertJsonPath('code', 'forbidden_teacher_class');
        }

        // Archived class -> 403 teacher_class_inactive
        $this->actingAs($this->teacherProfile->webUser, 'web_api');
        $this->getJson("/web_api/auth/teacher/classes/{$this->archivedPtbClass->id}/resources/overview")
            ->assertForbidden()->assertJsonPath('code', 'teacher_class_inactive');

        // Disabled question_bank.access feature -> ordinary curriculum resources remain available (200), MCQ bank -> 403
        TeacherSubscriptionFeature::where('feature_key', 'question_bank.access')
            ->update(['is_enabled' => false]);

        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/overview")
            ->assertOk()
            ->assertJsonPath('entitlements.classes_manage', true)
            ->assertJsonPath('entitlements.question_bank_access', false);

        foreach (['textbooks', 'units', 'topics/1000/content', 'slos', 'past-papers', 'model-papers'] as $ordinarySegment) {
            $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/{$ordinarySegment}")
                ->assertOk();
        }

        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/questions")
            ->assertForbidden()
            ->assertJsonPath('code', 'teacher_feature_unavailable');

        // Disabled classes.manage feature -> ordinary curriculum resource endpoints return 403
        TeacherSubscriptionFeature::where('feature_key', 'classes.manage')
            ->update(['is_enabled' => false]);

        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/overview")
            ->assertForbidden()
            ->assertJsonPath('code', 'teacher_feature_unavailable');
        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/textbooks")
            ->assertForbidden()
            ->assertJsonPath('code', 'teacher_feature_unavailable');
    }

    public function test_mcq_curriculum_chain_rejects_stale_book_or_unit_columns(): void
    {
        // Insert an MCQ whose topic_id belongs to Federal Physics (2000), while stale unit_id/book_id point to PTB (100 / 10).
        DB::table('exam_question_tbl')->insert([
            'id' => 2004,
            'question' => '<p>Federal question with stale PTB unit/book pointers</p>',
            'topic_id' => 2000,
            'unit_id' => 100,
            'book_id' => 10,
            'difficulty' => 1,
            'cognitive_domain' => 1,
            'is_mcq' => 1,
            'activate' => 1,
            'question_presentation_type_id' => 1,
            'scenario_group_id' => null,
            'slo_topic_id' => 501,
            'explanation' => 'SECRET',
            'explanation_um' => null,
            'explanation_video_url' => null,
        ]);
        DB::table('exam_question_options_tbl')->insert([
            ['id' => 6, 'question_id' => 2004, 'option' => '<p>Choice A</p>', 'is_answer' => 1],
        ]);

        // Also insert a Federal unit with activate=0 whose child MCQ has activate=1 -> must stay hidden
        DB::table('book_unit_tbl')->insert([
            'id' => 201,
            'book_id' => 20,
            'unit_no' => 2,
            'unit_name' => 'Inactive Federal Unit',
            'is_alp' => 0,
            'activate' => 0,
        ]);
        DB::table('book_unit_topic_tbl')->insert([
            'id' => 2001,
            'unit_id' => 201,
            'topic_no' => '2.1',
            'topic_name' => 'Inactive Topic',
            'topic_name_um' => null,
            'slo_no' => null,
            'is_alp' => 0,
            'activate' => 1,
        ]);
        DB::table('exam_question_tbl')->insert([
            'id' => 2006,
            'question' => '<p>Active MCQ inside inactive unit</p>',
            'topic_id' => 2001,
            'unit_id' => 201,
            'book_id' => 20,
            'difficulty' => 1,
            'cognitive_domain' => 1,
            'is_mcq' => 1,
            'activate' => 1,
            'question_presentation_type_id' => 1,
            'scenario_group_id' => null,
            'slo_topic_id' => null,
            'explanation' => null,
            'explanation_um' => null,
            'explanation_video_url' => null,
        ]);

        $this->actingAs($this->teacherProfile->webUser, 'web_api');

        // PTB class must still see ONLY 2001 and 2002 (NOT 2004!)
        $ptbRes = $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/questions?per_page=10")
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->assertEquals([2001, 2002], collect($ptbRes->json('data'))->pluck('id')->all());

        // Federal class sees 2003 and 2004 (via topic_id -> unit -> book), NOT inactive-chain 2006
        $fedRes = $this->getJson("/web_api/auth/teacher/classes/{$this->federalPhysicsClass->id}/resources/questions?per_page=10")
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->assertEquals([2003, 2004], collect($fedRes->json('data'))->pluck('id')->all());
    }

    public function test_mcq_multi_board_history_and_boardless_questions_dedup_and_combined_filtering(): void
    {
        // Question 2001 already has (board_id=11 Lahore, year=2024). Add a second board mapping (board_id=12 Gujranwala, year=2023).
        DB::table('exam_question_board_tbl')->insert([
            'id' => 4,
            'question_id' => 2001,
            'board_id' => 12,
            'session_id' => 1,
            'group_id' => 1,
            'year' => 2023,
            'activate' => 1,
        ]);

        // Add PTB Physics MCQ 2005 with NO exam_question_board_tbl history at all
        DB::table('exam_question_tbl')->insert([
            'id' => 2005,
            'question' => '<p>PTB question with no board appearance yet</p>',
            'topic_id' => 1000,
            'unit_id' => 100,
            'book_id' => 10,
            'difficulty' => 1,
            'cognitive_domain' => 1,
            'is_mcq' => 1,
            'activate' => 1,
            'question_presentation_type_id' => 1,
            'scenario_group_id' => null,
            'slo_topic_id' => 500,
            'explanation' => null,
            'explanation_um' => null,
            'explanation_video_url' => null,
        ]);
        DB::table('exam_question_options_tbl')->insert([
            ['id' => 7, 'question_id' => 2005, 'option' => '<p>Opt 1</p>', 'is_answer' => 1],
        ]);

        $this->actingAs($this->teacherProfile->webUser, 'web_api');

        // Unfiltered pagination returns 3 unique questions (2001 appears once, 2005 with empty board_links)
        $res = $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/questions?per_page=10")
            ->assertOk()
            ->assertJsonPath('pagination.total', 3)
            ->assertJsonCount(3, 'data');

        $byId = collect($res->json('data'))->keyBy('id');
        $this->assertCount(2, $byId[2001]['board_links']);
        $this->assertSame([], $byId[2005]['board_links']);

        // Cross-row false positive prevention: Lahore + 2023 never occurred together on one board appearance row
        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/questions?board_id=11&year=2023")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Exact matching row (Gujranwala + 2023) returns question 2001
        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/questions?board_id=12&year=2023")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 2001);
    }

    public function test_past_paper_manipulated_ids_and_slugs_are_rejected(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('prod/tst-past-papers/701/objective/page-1.jpeg', 'BYTES');
        Storage::disk('s3')->put('Phys_10_Lhr_2024_G1.webp', 'CAT_BYTES');

        // Second Biology past paper & page to verify cross-paper and cross-subject tampering
        DB::table('tst_past_papers')->insert([
            ['id' => 702, 'paper_title' => 'Biology 10th Lahore 2026', 'paper_slug' => 'biology-10th-lahore-2026', 'board_id' => 11, 'curriculum_board_id' => 1, 'subject_id' => 2, 'class_id' => 1, 'session_id' => 1, 'group' => '1', 'year' => 2026, 'is_active' => 1],
        ]);
        DB::table('tst_past_paper_pages')->insert([
            ['id' => 7002, 'past_paper_id' => 702, 'page_no' => 1, 'paper_type' => 'objective', 'image_path' => 'prod/tst-past-papers/702/objective/page-1.jpeg', 'thumbnail_path' => null, 'is_active' => 1],
        ]);

        $this->actingAs($this->teacherProfile->webUser, 'web_api');

        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/past-papers/structured/999999")
            ->assertNotFound();
        $this->get("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/past-papers/structured/701/pages/999999/file")
            ->assertNotFound();

        // Page 7002 belongs to paper 702 (not 701) -> 403
        $this->get("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/past-papers/structured/701/pages/7002/file")
            ->assertForbidden()
            ->assertJsonPath('code', 'resource_out_of_class_scope');

        // Manipulated catalog paper_slug for page 8001 -> 403
        $this->get("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/past-papers/catalog/different-slug/pages/8001/file")
            ->assertForbidden()
            ->assertJsonPath('code', 'resource_out_of_class_scope');

        // Cross-subject catalog page download -> 403
        $this->get("/web_api/auth/teacher/classes/{$this->ptbBiologyClass->id}/resources/past-papers/catalog/physics-10-lahore-2024/pages/8001/file")
            ->assertForbidden()
            ->assertJsonPath('code', 'resource_out_of_class_scope');
    }

    public function test_past_papers_enforce_explicit_curriculum_board_ownership_and_reject_unresolved(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('prod/tst-past-papers/701/objective/page-1.jpeg', 'PTB_STR_BYTES');
        Storage::disk('s3')->put('Phys_10_Lhr_2024_G1.webp', 'PTB_CAT_BYTES');
        Storage::disk('s3')->put('prod/tst-past-papers/703/objective/page-1.jpeg', 'FED_STR_BYTES');
        Storage::disk('s3')->put('prod/tst-past-papers/704/objective/page-1.jpeg', 'UNRES_STR_BYTES');
        Storage::disk('s3')->put('prod/tst-past-papers/705/objective/page-1.jpeg', 'FED_LHR_EXAM_BYTES');
        Storage::disk('s3')->put('Phys_10_Fed_2025_G1.webp', 'FED_CAT_BYTES');
        Storage::disk('s3')->put('Phys_10_Unresolved_2024.webp', 'UNRES_CAT_BYTES');

        // Seed FBISE curriculum papers (curriculum_board_id=2), one with FBISE exam board (12) and one with Lahore exam board (11),
        // PLUS unresolved legacy papers (curriculum_board_id=null) carrying Lahore exam board (11) for the SAME class_id=1 & subject_id=1.
        DB::table('tst_past_papers')->insert([
            [
                'id' => 703,
                'paper_title' => 'Physics 10th Federal Curriculum FBISE 2026',
                'paper_slug' => 'physics-10th-fbise-curr-2026',
                'board_id' => 12,
                'curriculum_board_id' => 2,
                'subject_id' => 1,
                'class_id' => 1,
                'session_id' => 1,
                'group' => '1',
                'year' => 2026,
                'is_active' => 1,
            ],
            [
                'id' => 704,
                'paper_title' => 'Physics 10th Unresolved Legacy Lahore 2025',
                'paper_slug' => 'physics-10th-unresolved-2025',
                'board_id' => 11,
                'curriculum_board_id' => null,
                'subject_id' => 1,
                'class_id' => 1,
                'session_id' => 1,
                'group' => '1',
                'year' => 2025,
                'is_active' => 1,
            ],
            [
                'id' => 705,
                'paper_title' => 'Physics 10th Federal Curriculum With Lahore Exam Metadata 2026',
                'paper_slug' => 'physics-10th-fed-lhr-meta-2026',
                'board_id' => 11,
                'curriculum_board_id' => 2,
                'subject_id' => 1,
                'class_id' => 1,
                'session_id' => 1,
                'group' => '2',
                'year' => 2026,
                'is_active' => 1,
            ],
        ]);
        DB::table('tst_past_paper_pages')->insert([
            ['id' => 7003, 'past_paper_id' => 703, 'page_no' => 1, 'paper_type' => 'objective', 'image_path' => 'prod/tst-past-papers/703/objective/page-1.jpeg', 'thumbnail_path' => null, 'is_active' => 1],
            ['id' => 7004, 'past_paper_id' => 704, 'page_no' => 1, 'paper_type' => 'objective', 'image_path' => 'prod/tst-past-papers/704/objective/page-1.jpeg', 'thumbnail_path' => null, 'is_active' => 1],
            ['id' => 7005, 'past_paper_id' => 705, 'page_no' => 1, 'paper_type' => 'objective', 'image_path' => 'prod/tst-past-papers/705/objective/page-1.jpeg', 'thumbnail_path' => null, 'is_active' => 1],
        ]);
        DB::table('past_paper_tbl')->insert([
            ['id' => 8003, 'paper_name' => 'Physics 10 Federal Curriculum 2025', 'paper_slug' => 'physics-10-fed-curr-2025', 'paper_path' => 'Phys_10_Fed_2025_G1.webp', 'class_id' => 1, 'subject_id' => 1, 'board_id' => 12, 'curriculum_board_id' => 2, 'year' => 2025, 'session_id' => 1, 'group' => '1', 'activate' => 1],
            ['id' => 8004, 'paper_name' => 'Physics 10 Unresolved Legacy 2024', 'paper_slug' => 'physics-10-unresolved-2024', 'paper_path' => 'Phys_10_Unresolved_2024.webp', 'class_id' => 1, 'subject_id' => 1, 'board_id' => 11, 'curriculum_board_id' => null, 'year' => 2024, 'session_id' => 1, 'group' => '1', 'activate' => 1],
        ]);

        // 1 & 2 & 3: Punjab teacher ONLY sees PTB papers (701, 8001) — NOT FBISE (703, 705, 8003) and NOT unresolved (704, 8004)
        $this->actingAs($this->teacherProfile->webUser, 'web_api');
        $ptbList = $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/past-papers")
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing(
            ['physics-10th-lahore-2026', 'physics-10-lahore-2024'],
            collect($ptbList->json('data'))->pluck('slug')->all()
        );

        // FBISE teacher ONLY sees Federal curriculum papers (703, 705, 8003) — NOT PTB (701, 8001) and NOT unresolved (704, 8004)
        $fedList = $this->getJson("/web_api/auth/teacher/classes/{$this->federalPhysicsClass->id}/resources/past-papers")
            ->assertOk()
            ->assertJsonCount(3, 'data');
        $this->assertEqualsCanonicalizing(
            ['physics-10th-fbise-curr-2026', 'physics-10th-fed-lhr-meta-2026', 'physics-10-fed-curr-2025'],
            collect($fedList->json('data'))->pluck('slug')->all()
        );

        // 9: FBISE paper 705 preserves BISE Lahore examination metadata while owned by Federal curriculum
        $fedDetail = $this->getJson("/web_api/auth/teacher/classes/{$this->federalPhysicsClass->id}/resources/past-papers/structured/705")
            ->assertOk()
            ->assertJsonPath('paper.curriculum_board.id', 2)
            ->assertJsonPath('paper.exam_board.name', 'BISE Lahore')
            ->assertJsonPath('paper.exam_session.name', 'Annual')
            ->assertJsonPath('paper.year', 2026);
        $this->assertStringNotContainsString('prod/tst-past-papers', json_encode($fedDetail->json()));

        $this->get("/web_api/auth/teacher/classes/{$this->federalPhysicsClass->id}/resources/past-papers/structured/703/pages/7003/file")
            ->assertOk();
        $this->get("/web_api/auth/teacher/classes/{$this->federalPhysicsClass->id}/resources/past-papers/catalog/physics-10-fed-curr-2025/pages/8003/file")
            ->assertOk();

        // 5 & 6: Cross-curriculum detail & page streaming rejected even with matching class_id & subject_id
        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/past-papers/structured/703")
            ->assertForbidden()->assertJsonPath('code', 'resource_out_of_class_scope');
        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/past-papers/structured/705")
            ->assertForbidden()->assertJsonPath('code', 'resource_out_of_class_scope');
        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/past-papers/catalog/physics-10-fed-curr-2025")
            ->assertForbidden()->assertJsonPath('code', 'resource_out_of_class_scope');
        $this->get("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/past-papers/structured/703/pages/7003/file")
            ->assertForbidden()->assertJsonPath('code', 'resource_out_of_class_scope');
        $this->get("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/past-papers/catalog/physics-10-fed-curr-2025/pages/8003/file")
            ->assertForbidden()->assertJsonPath('code', 'resource_out_of_class_scope');

        $this->getJson("/web_api/auth/teacher/classes/{$this->federalPhysicsClass->id}/resources/past-papers/structured/701")
            ->assertForbidden()->assertJsonPath('code', 'resource_out_of_class_scope');
        $this->get("/web_api/auth/teacher/classes/{$this->federalPhysicsClass->id}/resources/past-papers/structured/701/pages/7001/file")
            ->assertForbidden()->assertJsonPath('code', 'resource_out_of_class_scope');
        $this->get("/web_api/auth/teacher/classes/{$this->federalPhysicsClass->id}/resources/past-papers/catalog/physics-10-lahore-2024/pages/8001/file")
            ->assertForbidden()->assertJsonPath('code', 'resource_out_of_class_scope');

        // 7: Manipulated cross-paper / cross-curriculum page IDs rejected
        $this->get("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/past-papers/structured/701/pages/7003/file")
            ->assertForbidden()->assertJsonPath('code', 'resource_out_of_class_scope');
        $this->get("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/past-papers/structured/701/pages/7004/file")
            ->assertForbidden()->assertJsonPath('code', 'resource_out_of_class_scope');
        $this->get("/web_api/auth/teacher/classes/{$this->federalPhysicsClass->id}/resources/past-papers/catalog/physics-10-fed-curr-2025/pages/8001/file")
            ->assertForbidden()->assertJsonPath('code', 'resource_out_of_class_scope');

        // 8: Unresolved papers (curriculum_board_id = null) rejected for ALL teachers regardless of class/subject/exam board
        foreach ([$this->ptbPhysicsClass->id, $this->federalPhysicsClass->id] as $cid) {
            $this->getJson("/web_api/auth/teacher/classes/{$cid}/resources/past-papers/structured/704")
                ->assertForbidden()->assertJsonPath('code', 'resource_out_of_class_scope');
            $this->get("/web_api/auth/teacher/classes/{$cid}/resources/past-papers/structured/704/pages/7004/file")
                ->assertForbidden()->assertJsonPath('code', 'resource_out_of_class_scope');
            $this->getJson("/web_api/auth/teacher/classes/{$cid}/resources/past-papers/catalog/physics-10-unresolved-2024")
                ->assertForbidden()->assertJsonPath('code', 'resource_out_of_class_scope');
            $this->get("/web_api/auth/teacher/classes/{$cid}/resources/past-papers/catalog/physics-10-unresolved-2024/pages/8004/file")
                ->assertForbidden()->assertJsonPath('code', 'resource_out_of_class_scope');
        }

        // 4: Cross-teacher paper access is rejected
        $this->actingAs($this->otherTeacherProfile->webUser, 'web_api');
        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/past-papers/structured/701")
            ->assertForbidden()->assertJsonPath('code', 'forbidden_teacher_class');
        $this->get("/web_api/auth/teacher/classes/{$this->ptbPhysicsClass->id}/resources/past-papers/structured/701/pages/7001/file")
            ->assertForbidden()->assertJsonPath('code', 'forbidden_teacher_class');

        // Future past-paper creation/assignment via TstPastPaperController persists curriculum_board_id cleanly
        $admin = \App\Models\User::firstOrFail();
        $this->actingAs($admin, 'api');
        $createRes = $this->postJson('/api/admin/auth/tst-past-papers/add', [
            'paper_title' => 'Imported FBISE Paper 2027',
            'board_id' => 12,
            'curriculum_board_id' => 2,
            'subject_id' => 1,
            'class_id' => 1,
            'session_id' => 1,
            'group' => 1,
            'year' => 2027,
            'is_active' => true,
        ])->assertOk()->assertJsonPath('past_paper.curriculum_board_id', 2);
        $createdId = $createRes->json('past_paper.id');

        $this->postJson("/api/admin/auth/tst-past-papers/update/{$createdId}", [
            'paper_title' => 'Imported FBISE Paper 2027 Revised',
            'board_id' => 11,
            'curriculum_board_id' => 2,
            'subject_id' => 1,
            'class_id' => 1,
            'session_id' => 1,
            'group' => 1,
            'year' => 2027,
        ])->assertOk()->assertJsonPath('past_paper.curriculum_board_id', 2)->assertJsonPath('past_paper.board_id', 11);

        $this->getJson('/api/admin/auth/tst-past-papers/all?unresolved_curriculum=1')
            ->assertOk()
            ->assertJsonPath('past_papers.data.0.id', 704);

        $this->getJson('/api/admin/auth/tst-past-papers/all?curriculum_board_id=2')
            ->assertOk()
            ->assertJsonPath('past_papers.data.0.id', $createdId);
    }

    private function createClass(TeacherProfile $profile, array $overrides = []): TeacherClass
    {
        $class = new TeacherClass(array_merge([
            'class_id' => 1,
            'subject_id' => 1,
            'curriculum_board_id' => 1,
            'name' => 'Grade 10 Physics',
            'section' => 'A',
            'is_active' => true,
            'requires_approval' => true,
        ], $overrides));
        $class->teacher_profile_id = $profile->id;
        $class->class_code = TeacherClass::generateUniqueCode();
        $class->save();

        return $class;
    }

    private function createLegacyResourceTables(): void
    {
        $tables = [
            'class_tbl' => function (Blueprint $t) {
                $t->id();
                $t->string('class_name');
                $t->string('class_name_um')->nullable();
                $t->boolean('activate')->default(true);
                $t->timestamps();
            },
            'subject_tbl' => function (Blueprint $t) {
                $t->id();
                $t->string('subject_name');
                $t->string('subject_name_um')->nullable();
                $t->boolean('activate')->default(true);
                $t->timestamps();
            },
            'curriculum_board_tbl' => function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->boolean('activate')->default(true);
                $t->timestamps();
            },
            'board_tbl' => function (Blueprint $t) {
                $t->id();
                $t->string('board_name');
                $t->string('short_name')->nullable();
                $t->boolean('activate')->default(true);
                $t->timestamps();
            },
            'exam_session_tbl' => function (Blueprint $t) {
                $t->id();
                $t->string('session_name');
                $t->boolean('activate')->default(true);
                $t->timestamps();
            },
            'study_group_tbl' => function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->unsignedBigInteger('class_id')->nullable();
                $t->unsignedBigInteger('curriculum_board_id')->nullable();
                $t->boolean('activate')->default(true);
                $t->timestamps();
            },
            'book_tbl' => function (Blueprint $t) {
                $t->id();
                $t->string('book_name');
                $t->unsignedBigInteger('curriculum_board_id');
                $t->unsignedBigInteger('class_id');
                $t->unsignedBigInteger('subject_id');
                $t->boolean('activate')->default(true);
                $t->timestamps();
            },
            'book_unit_tbl' => function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('book_id');
                $t->integer('unit_no')->nullable();
                $t->string('unit_name');
                $t->boolean('is_alp')->default(false);
                $t->boolean('activate')->default(true);
                $t->timestamps();
            },
            'book_unit_topic_tbl' => function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('unit_id');
                $t->string('topic_no')->nullable();
                $t->string('topic_name');
                $t->string('topic_name_um')->nullable();
                $t->string('slo_no')->nullable();
                $t->boolean('is_alp')->default(false);
                $t->boolean('activate')->default(true);
                $t->timestamps();
            },
            'topic_content_type_tbl' => function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('name_um')->nullable();
                $t->boolean('has_child')->default(false);
                $t->boolean('is_mcq')->default(false);
                $t->boolean('activate')->default(true);
                $t->timestamps();
            },
            'topic_content_structure_tbl' => function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('topic_id');
                $t->unsignedBigInteger('topic_content_type_id');
                $t->timestamps();
            },
            'slo_domain_tbl' => function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('curriculum_board_id');
                $t->unsignedBigInteger('subject_id');
                $t->string('domain_code')->nullable();
                $t->string('domain_name');
                $t->string('domain_name_um')->nullable();
                $t->integer('sort_order')->default(1);
                $t->boolean('activate')->default(true);
                $t->timestamps();
            },
            'slo_topic_tbl' => function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('slo_domain_id');
                $t->unsignedBigInteger('class_id');
                $t->string('slo_code');
                $t->string('slo_title');
                $t->string('slo_title_um')->nullable();
                $t->text('benchmark_text')->nullable();
                $t->text('description')->nullable();
                $t->string('assessment_type')->nullable();
                $t->string('cognitive_level')->nullable();
                $t->boolean('is_practical')->default(false);
                $t->boolean('is_optional')->default(false);
                $t->boolean('is_alp')->default(false);
                $t->text('remarks')->nullable();
                $t->integer('sort_order')->default(1);
                $t->boolean('activate')->default(true);
                $t->timestamps();
            },
            'unit_slo_topic_tbl' => function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('unit_id');
                $t->unsignedBigInteger('slo_topic_id');
                $t->timestamps();
            },
            'topic_slo_topic_tbl' => function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('topic_id');
                $t->unsignedBigInteger('slo_topic_id');
                $t->timestamps();
            },
            'cognitive_domain_tbl' => function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('short');
                $t->timestamps();
            },
            'question_presentation_type_tbl' => function (Blueprint $t) {
                $t->id();
                $t->string('type_name');
                $t->string('code');
                $t->boolean('allows_multiple_mcqs')->default(false);
                $t->integer('sort_order')->default(1);
                $t->boolean('activate')->default(true);
                $t->timestamps();
            },
            'question_scenario_groups_tbl' => function (Blueprint $t) {
                $t->id();
                $t->string('title')->nullable();
                $t->text('scenario_text')->nullable();
                $t->text('scenario_text_um')->nullable();
                $t->string('scenario_image')->nullable();
                $t->boolean('activate')->default(true);
                $t->timestamps();
            },
            'exam_question_tbl' => function (Blueprint $t) {
                $t->id();
                $t->text('question')->nullable();
                $t->text('question_um')->nullable();
                $t->unsignedBigInteger('topic_id');
                $t->unsignedBigInteger('unit_id')->nullable();
                $t->unsignedBigInteger('book_id')->nullable();
                $t->integer('question_type')->nullable();
                $t->boolean('is_alp_question')->default(false);
                $t->boolean('exercise_question')->default(false);
                $t->boolean('activate')->default(true);
                $t->integer('marks')->default(1);
                $t->integer('difficulty')->default(1);
                $t->integer('question_lang')->default(0);
                $t->integer('question_um_lang')->default(1);
                $t->unsignedBigInteger('cognitive_domain')->nullable();
                $t->string('topic_content')->nullable();
                $t->boolean('is_mcq')->default(true);
                $t->boolean('has_diagram')->default(false);
                $t->unsignedBigInteger('question_presentation_type_id')->nullable();
                $t->unsignedBigInteger('scenario_group_id')->nullable();
                $t->integer('scenario_question_order')->nullable();
                $t->unsignedBigInteger('slo_topic_id')->nullable();
                $t->text('explanation')->nullable();
                $t->text('explanation_um')->nullable();
                $t->string('explanation_video_url')->nullable();
                $t->timestamps();
            },
            'exam_question_options_tbl' => function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('question_id');
                $t->text('option')->nullable();
                $t->boolean('is_answer')->default(false);
                $t->text('option_um')->nullable();
                $t->integer('option_lang')->default(0);
                $t->integer('option_um_lang')->default(1);
                $t->timestamps();
            },
            'exam_question_board_tbl' => function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('question_id');
                $t->unsignedBigInteger('board_id')->nullable();
                $t->unsignedBigInteger('session_id')->nullable();
                $t->unsignedBigInteger('group_id')->nullable();
                $t->integer('year')->nullable();
                $t->boolean('activate')->default(true);
                $t->timestamps();
            },
            'tst_past_papers' => function (Blueprint $t) {
                $t->id();
                $t->string('paper_title');
                $t->string('paper_slug');
                $t->unsignedBigInteger('board_id')->nullable();
                $t->unsignedBigInteger('subject_id');
                $t->unsignedBigInteger('class_id');
                $t->unsignedBigInteger('session_id')->nullable();
                $t->string('group')->nullable();
                $t->integer('year')->nullable();
                $t->boolean('is_active')->default(true);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
            },
            'tst_past_paper_pages' => function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('past_paper_id');
                $t->integer('page_no')->default(1);
                $t->string('paper_type')->nullable();
                $t->string('page_type')->nullable();
                $t->string('image_path');
                $t->string('thumbnail_path')->nullable();
                $t->integer('image_width')->nullable();
                $t->integer('image_height')->nullable();
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            },
            'past_paper_tbl' => function (Blueprint $t) {
                $t->id();
                $t->string('paper_name');
                $t->string('paper_slug');
                $t->string('paper_path');
                $t->unsignedBigInteger('class_id');
                $t->unsignedBigInteger('subject_id');
                $t->unsignedBigInteger('board_id')->nullable();
                $t->integer('year')->nullable();
                $t->unsignedBigInteger('session_id')->nullable();
                $t->string('group')->nullable();
                $t->boolean('activate')->default(true);
                $t->timestamps();
            },
            'model_papers' => function (Blueprint $t) {
                $t->id();
                $t->string('title');
                $t->integer('total_marks')->nullable();
                $t->string('time_allowed')->nullable();
                $t->text('instructions')->nullable();
                $t->unsignedBigInteger('class_id');
                $t->unsignedBigInteger('subject_id');
                $t->unsignedBigInteger('curriculum_board_id');
                $t->boolean('urdu_lang')->default(false);
                $t->integer('total_questions')->nullable();
                $t->boolean('activate')->default(true);
                $t->timestamps();
            },
            'paper_parts' => function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('name_um')->nullable();
                $t->integer('max_marks')->nullable();
                $t->string('time_allowed')->nullable();
                $t->integer('no_of_sections')->nullable();
                $t->unsignedBigInteger('model_paper_id');
                $t->integer('sequence')->default(1);
                $t->boolean('activate')->default(true);
                $t->timestamps();
            },
            'paper_part_sections' => function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->integer('no_of_questions')->nullable();
                $t->unsignedBigInteger('paper_part_id');
                $t->boolean('show_name')->default(true);
                $t->integer('sequence')->default(1);
                $t->boolean('urdu_lang')->default(false);
                $t->boolean('activate')->default(true);
                $t->timestamps();
            },
            'paper_part_section_questions' => function (Blueprint $t) {
                $t->id();
                $t->text('question_statement')->nullable();
                $t->string('question_no')->nullable();
                $t->integer('marks')->nullable();
                $t->unsignedBigInteger('section_id');
                $t->boolean('urdu_lang')->default(false);
                $t->integer('sequence')->default(1);
                $t->boolean('has_parts')->default(false);
                $t->boolean('activate')->default(true);
                $t->timestamps();
            },
            'paper_question_sections' => function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('question_id');
                $t->string('section_name')->nullable();
                $t->integer('no_of_sub_sections')->default(1);
                $t->boolean('activate')->default(true);
                $t->timestamps();
            },
            'question_type_tbl' => function (Blueprint $t) {
                $t->id();
                $t->string('type_name');
                $t->boolean('is_mcq')->default(true);
                $t->boolean('activate')->default(true);
                $t->timestamps();
            },
            'paper_question_section_sub_sections' => function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('section_id');
                $t->string('sub_section_name')->nullable();
                $t->integer('total_questions')->default(1);
                $t->unsignedBigInteger('question_type_id')->nullable();
                $t->boolean('is_random_units')->default(false);
                $t->integer('no_of_random_units')->nullable();
                $t->boolean('activate')->default(true);
                $t->timestamps();
            },
            'question_pairing_schemes' => function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_section_id');
                $t->unsignedBigInteger('unit_id');
                $t->integer('no_of_questions')->default(1);
                $t->timestamps();
            },
            'past_paper_curriculum_audit_logs' => function (Blueprint $t) {
                $t->id();
                $t->string('paper_source', 32);
                $t->unsignedBigInteger('paper_id');
                $t->string('operation_type', 32);
                $t->unsignedBigInteger('previous_curriculum_board_id')->nullable();
                $t->unsignedBigInteger('new_curriculum_board_id');
                $t->unsignedBigInteger('admin_user_id')->nullable();
                $t->string('note', 500)->nullable();
                $t->string('ip_address', 45)->nullable();
                $t->timestamp('created_at')->nullable();
            },
        ];

        foreach ($tables as $name => $definition) {
            Schema::dropIfExists($name);
            Schema::create($name, $definition);
        }

        (require database_path('migrations/2026_11_11_000002_add_curriculum_board_ownership_to_past_paper_tables.php'))->up();
    }

    private function seedLegacyResourceData(): void
    {
        $now = now();
        DB::table('class_tbl')->insert([
            ['id' => 1, 'class_name' => 'Grade 10', 'class_name_um' => 'دہم', 'activate' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('subject_tbl')->insert([
            ['id' => 1, 'subject_name' => 'Physics', 'subject_name_um' => 'طبیعیات', 'activate' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'subject_name' => 'Biology', 'subject_name_um' => 'حیاتیات', 'activate' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('curriculum_board_tbl')->insert([
            ['id' => 1, 'name' => 'Punjab Text Book Board', 'activate' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Federal Board (NBF)', 'activate' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('board_tbl')->insert([
            ['id' => 11, 'board_name' => 'BISE Lahore', 'short_name' => 'LHR', 'activate' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 12, 'board_name' => 'FBISE Islamabad', 'short_name' => 'FBISE', 'activate' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('exam_session_tbl')->insert([
            ['id' => 1, 'session_name' => 'Annual', 'activate' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('study_group_tbl')->insert([
            ['id' => 1, 'name' => 'Science Group', 'class_id' => 1, 'curriculum_board_id' => 1, 'activate' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Books: 10 (PTB Physics 10), 20 (Federal Physics 10)
        DB::table('book_tbl')->insert([
            ['id' => 10, 'book_name' => 'PTB Physics 10', 'curriculum_board_id' => 1, 'class_id' => 1, 'subject_id' => 1, 'activate' => 1],
            ['id' => 20, 'book_name' => 'Federal Physics 10', 'curriculum_board_id' => 2, 'class_id' => 1, 'subject_id' => 1, 'activate' => 1],
        ]);
        DB::table('book_unit_tbl')->insert([
            ['id' => 100, 'book_id' => 10, 'unit_no' => 10, 'unit_name' => 'Simple Harmonic Motion and Waves', 'activate' => 1],
            ['id' => 200, 'book_id' => 20, 'unit_no' => 10, 'unit_name' => 'Oscillations (FBISE)', 'activate' => 1],
        ]);
        DB::table('book_unit_topic_tbl')->insert([
            ['id' => 1000, 'unit_id' => 100, 'topic_no' => '10.1', 'topic_name' => 'Simple Harmonic Motion', 'slo_no' => 'SLO-P-10-01', 'activate' => 1],
            ['id' => 2000, 'unit_id' => 200, 'topic_no' => '10.1', 'topic_name' => 'Restoring Force', 'slo_no' => 'SLO-FED-10-01', 'activate' => 1],
        ]);

        DB::table('topic_content_type_tbl')->insert([
            ['id' => 1, 'name' => 'Key Definition', 'name_um' => 'تعریف', 'has_child' => 0, 'is_mcq' => 0, 'activate' => 1],
        ]);
        DB::table('topic_content_structure_tbl')->insert([
            ['id' => 1, 'topic_id' => 1000, 'topic_content_type_id' => 1],
        ]);

        DB::table('slo_domain_tbl')->insert([
            ['id' => 1, 'curriculum_board_id' => 1, 'subject_id' => 1, 'domain_code' => 'DOM-PHY-PTB', 'domain_name' => 'Mechanics & Waves', 'activate' => 1],
            ['id' => 2, 'curriculum_board_id' => 2, 'subject_id' => 1, 'domain_code' => 'DOM-PHY-FED', 'domain_name' => 'Oscillatory Physics', 'activate' => 1],
        ]);
        DB::table('slo_topic_tbl')->insert([
            ['id' => 500, 'slo_domain_id' => 1, 'class_id' => 1, 'slo_code' => 'SLO-P-10-01', 'slo_title' => 'Analyze restoring force in SHM', 'cognitive_level' => 'Understanding', 'activate' => 1],
            ['id' => 501, 'slo_domain_id' => 2, 'class_id' => 1, 'slo_code' => 'SLO-FED-10-01', 'slo_title' => 'Derive time period of simple pendulum', 'cognitive_level' => 'Application', 'activate' => 1],
        ]);
        DB::table('unit_slo_topic_tbl')->insert([
            ['id' => 1, 'unit_id' => 100, 'slo_topic_id' => 500],
            ['id' => 2, 'unit_id' => 200, 'slo_topic_id' => 501],
        ]);
        DB::table('topic_slo_topic_tbl')->insert([
            ['id' => 1, 'topic_id' => 1000, 'slo_topic_id' => 500],
            ['id' => 2, 'topic_id' => 2000, 'slo_topic_id' => 501],
        ]);

        DB::table('cognitive_domain_tbl')->insert([
            ['id' => 1, 'name' => 'Knowledge', 'short' => 'K'],
            ['id' => 2, 'name' => 'Understanding', 'short' => 'U'],
        ]);
        DB::table('question_presentation_type_tbl')->insert([
            ['id' => 1, 'type_name' => 'Straight', 'code' => 'st', 'allows_multiple_mcqs' => 0, 'activate' => 1],
            ['id' => 2, 'type_name' => 'Stimulus Based', 'code' => 'stimulus', 'allows_multiple_mcqs' => 1, 'activate' => 1],
        ]);
        DB::table('question_scenario_groups_tbl')->insert([
            ['id' => 90, 'title' => 'Pendulum Scenario', 'scenario_text' => '<p>Consider a simple pendulum of length L.</p>', 'scenario_image' => 'private/scenarios/pendulum.png', 'activate' => 1],
        ]);

        DB::table('exam_question_tbl')->insert([
            [
                'id' => 2001,
                'question' => '<p>In SHM acceleration is proportional to displacement.</p>',
                'topic_id' => 1000,
                'unit_id' => 100,
                'book_id' => 10,
                'difficulty' => 1,
                'cognitive_domain' => 1,
                'is_mcq' => 1,
                'activate' => 1,
                'question_presentation_type_id' => 1,
                'scenario_group_id' => null,
                'slo_topic_id' => null,
                'explanation' => 'SECRET_EXPLANATION_MUST_NOT_LEAK',
                'explanation_um' => 'SECRET_UM',
                'explanation_video_url' => 'https://secret.video/1',
            ],
            [
                'id' => 2002,
                'question' => '<p>If pendulum length quadruples, time period becomes:</p>',
                'topic_id' => 1000,
                'unit_id' => 100,
                'book_id' => 10,
                'difficulty' => 2,
                'cognitive_domain' => 2,
                'is_mcq' => 1,
                'activate' => 1,
                'question_presentation_type_id' => 2,
                'scenario_group_id' => 90,
                'slo_topic_id' => 500,
                'explanation' => 'SECRET_EXPLANATION_2',
                'explanation_um' => 'SECRET_UM_2',
                'explanation_video_url' => null,
            ],
            [
                'id' => 2003,
                'question' => '<p>Federal board oscillation question</p>',
                'topic_id' => 2000,
                'unit_id' => 200,
                'book_id' => 20,
                'difficulty' => 1,
                'cognitive_domain' => 1,
                'is_mcq' => 1,
                'activate' => 1,
                'question_presentation_type_id' => 1,
                'scenario_group_id' => null,
                'slo_topic_id' => 501,
                'explanation' => 'FED_SECRET',
                'explanation_um' => null,
                'explanation_video_url' => null,
            ],
        ]);

        DB::table('exam_question_options_tbl')->insert([
            ['id' => 1, 'question_id' => 2001, 'option' => '<p>True</p>', 'is_answer' => 1],
            ['id' => 2, 'question_id' => 2001, 'option' => '<p>False</p>', 'is_answer' => 0],
            ['id' => 3, 'question_id' => 2002, 'option' => '<p>Doubled</p>', 'is_answer' => 1],
            ['id' => 4, 'question_id' => 2002, 'option' => '<p>Halved</p>', 'is_answer' => 0],
            ['id' => 5, 'question_id' => 2003, 'option' => '<p>Fed Opt A</p>', 'is_answer' => 1],
        ]);

        DB::table('exam_question_board_tbl')->insert([
            ['id' => 1, 'question_id' => 2001, 'board_id' => 11, 'session_id' => 1, 'group_id' => 1, 'year' => 2024, 'activate' => 1],
            ['id' => 2, 'question_id' => 2002, 'board_id' => 11, 'session_id' => 1, 'group_id' => 1, 'year' => 2024, 'activate' => 1],
            ['id' => 3, 'question_id' => 2003, 'board_id' => 12, 'session_id' => 1, 'group_id' => null, 'year' => 2025, 'activate' => 1],
        ]);

        DB::table('tst_past_papers')->insert([
            ['id' => 701, 'paper_title' => 'Physics 10th Lahore 2026', 'paper_slug' => 'physics-10th-lahore-2026', 'board_id' => 11, 'curriculum_board_id' => 1, 'subject_id' => 1, 'class_id' => 1, 'session_id' => 1, 'group' => '1', 'year' => 2026, 'is_active' => 1],
        ]);
        DB::table('tst_past_paper_pages')->insert([
            ['id' => 7001, 'past_paper_id' => 701, 'page_no' => 1, 'paper_type' => 'objective', 'image_path' => 'prod/tst-past-papers/701/objective/page-1.jpeg', 'thumbnail_path' => 'prod/tst-past-papers/701/objective/thumb-1.jpeg', 'is_active' => 1],
        ]);
        DB::table('past_paper_tbl')->insert([
            ['id' => 8001, 'paper_name' => 'Physics 10 Lahore 2024 G1', 'paper_slug' => 'physics-10-lahore-2024', 'paper_path' => 'Phys_10_Lhr_2024_G1.webp', 'class_id' => 1, 'subject_id' => 1, 'board_id' => 11, 'curriculum_board_id' => 1, 'year' => 2024, 'session_id' => 1, 'group' => '1', 'activate' => 1],
        ]);

        DB::table('question_type_tbl')->insert([
            ['id' => 1, 'type_name' => 'MCQs', 'is_mcq' => 1, 'activate' => 1],
        ]);
        DB::table('model_papers')->insert([
            ['id' => 300, 'title' => 'PTB Physics Model Paper 2026', 'total_marks' => 60, 'time_allowed' => '2 Hours', 'class_id' => 1, 'subject_id' => 1, 'curriculum_board_id' => 1, 'total_questions' => 12, 'activate' => 1],
            ['id' => 301, 'title' => 'Federal Physics Model Paper 2026', 'total_marks' => 65, 'time_allowed' => '2.5 Hours', 'class_id' => 1, 'subject_id' => 1, 'curriculum_board_id' => 2, 'total_questions' => 15, 'activate' => 1],
        ]);
        DB::table('paper_parts')->insert([
            ['id' => 1, 'name' => 'Part I Objective', 'max_marks' => 12, 'model_paper_id' => 300, 'sequence' => 1, 'activate' => 1],
        ]);
        DB::table('paper_part_sections')->insert([
            ['id' => 1, 'name' => 'Section A', 'no_of_questions' => 12, 'paper_part_id' => 1, 'sequence' => 1, 'activate' => 1],
        ]);
        DB::table('paper_part_section_questions')->insert([
            ['id' => 1, 'question_statement' => 'Choose the correct option.', 'question_no' => 'Q1', 'marks' => 12, 'section_id' => 1, 'sequence' => 1, 'activate' => 1],
        ]);
        DB::table('paper_question_sections')->insert([
            ['id' => 1, 'question_id' => 1, 'section_name' => 'MCQs', 'no_of_sub_sections' => 1, 'activate' => 1],
        ]);
        DB::table('paper_question_section_sub_sections')->insert([
            ['id' => 1, 'section_id' => 1, 'sub_section_name' => 'Unit 10 MCQs', 'total_questions' => 1, 'question_type_id' => 1, 'activate' => 1],
        ]);
        DB::table('question_pairing_schemes')->insert([
            ['id' => 1, 'sub_section_id' => 1, 'unit_id' => 100, 'no_of_questions' => 1],
        ]);
    }
}
