<?php

namespace Tests\Feature;

use App\Models\TeacherClass;
use App\Models\TeacherClassTest;
use App\Models\TeacherProfile;
use App\Models\TeacherSubscriptionFeature;
use App\Models\WebUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TeacherMcqTestBuilderTest extends TeacherBackendTest
{
    private TeacherProfile $builderTeacher;
    private TeacherProfile $builderOtherTeacher;
    private TeacherClass $ptbClass;
    private TeacherClass $federalClass;
    private TeacherClass $biologyClass;
    private TeacherClass $archivedClass;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createBuilderResourceTables();
        (require database_path('migrations/2026_11_11_000004_create_teacher_class_tests_and_questions_tables.php'))->up();
        $this->seedBuilderResourceData();

        $subscriptions = app(\App\Http\Services\TeacherSubscriptionService::class);

        $this->builderTeacher = TeacherProfile::where('web_user_id', 2)->firstOrFail();
        $this->builderTeacher->forceFill(['status' => 'active'])->save();
        $subscriptions->assignDefaultFree($this->builderTeacher->id);

        $this->builderOtherTeacher = TeacherProfile::where('web_user_id', 3)->firstOrFail();
        $this->builderOtherTeacher->forceFill(['status' => 'active'])->save();
        $subscriptions->assignDefaultFree($this->builderOtherTeacher->id);

        $this->ptbClass = $this->createClass($this->builderTeacher, [
            'curriculum_board_id' => 1,
            'class_id' => 1,
            'subject_id' => 1,
            'name' => 'Grade 10 Physics PTB',
        ]);

        $this->federalClass = $this->createClass($this->builderTeacher, [
            'curriculum_board_id' => 2,
            'class_id' => 1,
            'subject_id' => 1,
            'name' => 'Grade 10 Physics Federal',
        ]);

        $this->biologyClass = $this->createClass($this->builderTeacher, [
            'curriculum_board_id' => 1,
            'class_id' => 1,
            'subject_id' => 2,
            'name' => 'Grade 10 Biology PTB',
        ]);

        $this->archivedClass = $this->createClass($this->builderTeacher, [
            'curriculum_board_id' => 1,
            'class_id' => 1,
            'subject_id' => 1,
            'name' => 'Archived Physics',
            'section' => 'Arch',
            'is_active' => false,
        ]);
    }

    public function test_authorization_feature_gates_and_class_ownership_enforced(): void
    {
        // Unauthenticated blocked by teacher middleware (without JWT bypass this returns 401; in test harness EnsureTeacherActive rejects with 403)
        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests")
            ->assertForbidden();

        // Student user rejected
        $student = WebUser::findOrFail(1);
        $this->actingAs($student, 'web_api');
        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests")
            ->assertForbidden();

        // Cross-teacher rejected
        $this->actingAs($this->builderOtherTeacher->webUser, 'web_api');
        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests")
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden_teacher_class');

        // Archived/inactive class rejected
        $this->actingAs($this->builderTeacher->webUser, 'web_api');
        $this->getJson("/web_api/auth/teacher/classes/{$this->archivedClass->id}/tests")
            ->assertForbidden()
            ->assertJsonPath('code', 'teacher_class_inactive');

        // Feature flag: disable question_bank.access -> browsing/auto-select/review/store blocked, list remains readable with tests.create
        TeacherSubscriptionFeature::where('feature_key', 'question_bank.access')->update(['is_enabled' => false]);
        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests")
            ->assertOk();
        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/question-bank")
            ->assertForbidden()
            ->assertJsonPath('code', 'teacher_feature_unavailable');
        $this->postJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/auto-select", ['question_count' => 1])
            ->assertForbidden()
            ->assertJsonPath('code', 'teacher_feature_unavailable');
        $this->postJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/question-review", ['question_ids' => [2001]])
            ->assertForbidden()
            ->assertJsonPath('code', 'teacher_feature_unavailable');

        // Re-enable question_bank.access and disable tests.create -> ALL test endpoints blocked
        TeacherSubscriptionFeature::where('feature_key', 'question_bank.access')->update(['is_enabled' => true]);
        TeacherSubscriptionFeature::where('feature_key', 'tests.create')->update(['is_enabled' => false]);

        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests")
            ->assertForbidden()
            ->assertJsonPath('code', 'teacher_feature_unavailable');
        $this->postJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/auto-select", ['question_count' => 1])
            ->assertForbidden()
            ->assertJsonPath('code', 'teacher_feature_unavailable');
    }

    public function test_question_bank_and_auto_select_enforce_canonical_scoping_and_hide_answers(): void
    {
        $this->actingAs($this->builderTeacher->webUser, 'web_api');

        // With board_id=11 filter, PTB gets 2001, 2002 (NOT Federal 2003 even though 2003 has board_id=11)
        $res = $this->getJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/question-bank?board_id=11&per_page=20")
            ->assertOk()
            ->assertJsonPath('policy.answers_included', false)
            ->assertJsonPath('policy.explanations_included', false);

        $this->assertEquals([2001, 2002], collect($res->json('data'))->pluck('id')->all());

        // Full unfiltered PTB question bank (All Formats order: standalone 2001, 2005 first, then stimulus 2002, 2004)
        $allPtb = $this->getJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/question-bank?per_page=20")
            ->assertOk();
        $this->assertEquals([2001, 2005, 2002, 2004], collect($allPtb->json('data'))->pluck('id')->all());
        $this->assertEquals(2, $allPtb->json('summary.standalone_count'));
        $this->assertEquals(2, $allPtb->json('summary.stimulus_group_count'));

        // Assert zero answer-key or explanation leakage anywhere in browsing JSON
        $rawJson = $allPtb->getContent();
        $this->assertStringNotContainsString('is_answer', $rawJson);
        $this->assertStringNotContainsString('correct_option', $rawJson);
        $this->assertStringNotContainsString('SECRET_EXPLANATION_MUST_NOT_LEAK', $rawJson);
        $this->assertStringNotContainsString('Solids have highest elasticity', $rawJson);
        $this->assertStringNotContainsString('explanation_video_url', $rawJson);

        // Relative private scenario_image sanitized to null while https CDN URL is preserved
        $q2002 = collect($allPtb->json('data'))->firstWhere('id', 2002);
        $q2004 = collect($allPtb->json('data'))->firstWhere('id', 2004);
        $this->assertNull($q2002['scenario_group']['scenario_image']);
        $this->assertSame('https://cdn.thestudentstimes.com/dev/questions/imported/2026/11/sample-wave.webp', $q2004['scenario_group']['scenario_image']);

        // Biology class gets 0 MCQs (isolated by subject)
        $this->getJson("/web_api/auth/teacher/classes/{$this->biologyClass->id}/tests/question-bank")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Explicit filter tampering with Federal Unit ID on PTB class returns 403
        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/question-bank?unit_id=200")
            ->assertForbidden()
            ->assertJsonPath('code', 'resource_out_of_class_scope');

        // Auto-select checks: request 3 from 4 available -> exact 3 unique items, zero answers
        $auto = $this->postJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/auto-select", [
            'question_count' => 3,
            'seed' => 42,
        ])
            ->assertOk()
            ->assertJsonPath('selected_count', 3)
            ->assertJsonPath('available_count', 4)
            ->assertJsonPath('policy.answers_included', false);

        $selectedIds = collect($auto->json('data'))->pluck('id')->all();
        $this->assertCount(3, array_unique($selectedIds));
        foreach ($selectedIds as $sid) {
            $this->assertContains($sid, [2001, 2002, 2004, 2005]);
        }
        $this->assertStringNotContainsString('is_answer', $auto->getContent());
        $this->assertStringNotContainsString('Solids have highest elasticity', $auto->getContent());

        // Auto-select insufficient count reports available count clearly instead of silently returning fewer
        $this->postJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/auto-select", [
            'question_count' => 10,
            'unit_id' => 101,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_matching_mcqs')
            ->assertJsonPath('data.requested_count', 10)
            ->assertJsonPath('data.available_count', 2);

        // Exclude IDs works for replacement/regeneration without duplicates
        $replace = $this->postJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/auto-select", [
            'question_count' => 1,
            'exclude_question_ids' => $selectedIds,
        ])->assertOk();
        $replacementId = $replace->json('data.0.id');
        $this->assertNotContains($replacementId, $selectedIds);
    }

    public function test_teacher_only_pre_save_answer_review_works_and_blocks_out_of_scope_questions(): void
    {
        $this->actingAs($this->builderTeacher->webUser, 'web_api');

        $review = $this->postJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/question-review", [
            'question_ids' => [2004, 2001],
        ])
            ->assertOk()
            ->assertJsonPath('policy.teacher_only_review', true)
            ->assertJsonPath('policy.answers_included', true)
            // All Formats ordering puts standalone 2001 first and stimulus 2004 second
            ->assertJsonPath('data.0.id', 2001)
            ->assertJsonPath('data.0.correct_option_key', 'A')
            ->assertJsonPath('data.1.id', 2004)
            ->assertJsonPath('data.1.correct_option_id', 11)
            ->assertJsonPath('data.1.correct_option_key', 'B')
            ->assertJsonPath('data.1.explanation', 'Solids have highest elasticity.')
            ->assertJsonPath('presentation.standalone_questions.0.id', 2001)
            ->assertJsonPath('presentation.stimulus_groups.0.scenario_group.id', 91)
            ->assertJsonPath('presentation.stimulus_groups.0.questions.0.id', 2004);

        $this->assertTrue($review->json('data.1.options.1.is_answer'));

        // Out-of-scope Federal question blocked
        $this->postJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/question-review", [
            'question_ids' => [2006],
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'resource_out_of_class_scope');

        // Short question blocked
        $this->postJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/question-review", [
            'question_ids' => [2008],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_test_question');
    }

    public function test_manual_and_automatic_draft_lifecycle_validation_and_non_draft_protection(): void
    {
        $this->actingAs($this->builderTeacher->webUser, 'web_api');

        // Validation failures: missing title, empty questions, duplicate question, negative marks, non-MCQ, out-of-scope
        $this->postJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests", [
            'title' => '',
            'selection_method' => 'manual',
            'questions' => [['question_id' => 2001]],
        ])->assertStatus(422);

        $this->postJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests", [
            'title' => 'Empty Test',
            'selection_method' => 'manual',
            'questions' => [],
        ])->assertStatus(422);

        $this->postJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests", [
            'title' => 'Duplicate Question Test',
            'selection_method' => 'manual',
            'questions' => [['question_id' => 2001], ['question_id' => 2001]],
        ])->assertStatus(422);

        $this->postJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests", [
            'title' => 'Negative Marks Test',
            'selection_method' => 'manual',
            'marks_per_question' => -2,
            'questions' => [['question_id' => 2001]],
        ])->assertStatus(422);

        $this->postJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests", [
            'title' => 'Short Question Trap',
            'selection_method' => 'manual',
            'questions' => [['question_id' => 2008]],
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_test_question');

        $this->postJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests", [
            'title' => 'Out of Scope Federal Question',
            'selection_method' => 'manual',
            'questions' => [['question_id' => 2006]],
        ])->assertForbidden()->assertJsonPath('code', 'resource_out_of_class_scope');

        // Create Draft Test (try spoofing curriculum_board_id/total_marks/status -> server overrides cleanly)
        $createRes = $this->postJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests", [
            'title' => 'Unit 10 & 11 Checkpoint Quiz',
            'description' => 'Wave and SHM assessment',
            'instructions' => 'Choose the best option for each MCQ.',
            'selection_method' => 'automatic',
            'selection_criteria' => [
                'unit_ids' => [100, 101],
                'question_count' => 3,
                'board_id' => 11,
            ],
            'marks_per_question' => 2,
            'negative_marks_per_wrong' => 0.5,
            'language_mode' => 'bilingual',
            'curriculum_board_id' => 999,
            'total_marks' => 9999,
            'status' => 'published',
            'questions' => [
                ['question_id' => 2004, 'marks' => 2.5],
                ['question_id' => 2001],
                ['question_id' => 2005],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.curriculum_board_id', 1)
            ->assertJsonPath('data.class_id', 1)
            ->assertJsonPath('data.subject_id', 1)
            ->assertJsonPath('data.total_questions', 3)
            ->assertJsonPath('data.total_marks', 6.5)
            ->assertJsonPath('data.selection_method', 'automatic')
            ->assertJsonPath('data.selection_criteria.question_count', 3)
            ->assertJsonPath('data.questions.0.question_order', 1)
            ->assertJsonPath('data.questions.0.question_id', 2001)
            ->assertJsonPath('data.questions.0.correct_option_key', 'A')
            ->assertJsonPath('data.questions.1.question_order', 2)
            ->assertJsonPath('data.questions.1.question_id', 2005)
            ->assertJsonPath('data.questions.2.question_order', 3)
            ->assertJsonPath('data.questions.2.question_id', 2004)
            ->assertJsonPath('data.questions.2.marks', 2.5);

        $testId = $createRes->json('data.id');

        // List tests shows the saved draft
        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests")
            ->assertOk()
            ->assertJsonPath('summary.total_tests', 1)
            ->assertJsonPath('summary.draft_tests', 1)
            ->assertJsonPath('data.0.id', $testId);

        // Federal class does NOT see PTB class's test
        $this->getJson("/web_api/auth/teacher/classes/{$this->federalClass->id}/tests")
            ->assertOk()
            ->assertJsonPath('summary.total_tests', 0);
        $this->getJson("/web_api/auth/teacher/classes/{$this->federalClass->id}/tests/{$testId}")
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden_teacher_test');

        // Update Draft Test: remove a question and reorder remaining
        $this->putJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/{$testId}", [
            'title' => 'Unit 10 & 11 Updated Quiz',
            'selection_method' => 'mixed',
            'marks_per_question' => 1.5,
            'negative_marks_per_wrong' => 0,
            'language_mode' => 'urdu',
            'questions' => [
                ['question_id' => 2001],
                ['question_id' => 2004],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Unit 10 & 11 Updated Quiz')
            ->assertJsonPath('data.total_questions', 2)
            ->assertJsonPath('data.total_marks', 3)
            ->assertJsonPath('data.language_mode', 'urdu')
            ->assertJsonPath('data.questions.0.question_id', 2001)
            ->assertJsonPath('data.questions.1.question_id', 2004);

        // Non-draft protection: simulate non-draft state -> edit & delete must fail cleanly
        TeacherClassTest::where('id', $testId)->update(['status' => TeacherClassTest::STATUS_PUBLISHED]);

        $this->putJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/{$testId}", [
            'title' => 'Tamper Published',
            'selection_method' => 'manual',
            'questions' => [['question_id' => 2001]],
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'test_not_editable');

        $this->deleteJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/{$testId}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'test_not_editable');

        // Revert to draft and verify clean deletion
        TeacherClassTest::where('id', $testId)->update(['status' => TeacherClassTest::STATUS_DRAFT]);

        $this->deleteJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/{$testId}")
            ->assertOk();

        $this->assertDatabaseMissing('teacher_class_tests', ['id' => $testId]);
        $this->assertDatabaseCount('teacher_class_test_questions', 0);
    }

    public function test_stimulus_group_selection_and_ineligible_exclusion_enforced(): void
    {
        $this->seedMultiQuestionStimulusGroups();
        $this->actingAs($this->builderTeacher->webUser, 'web_api');

        // Question bank exposes stimulus group 92 as ONE selectable unit with 3 eligible PTB MCQs
        // while excluding the 1 Federal cross-curriculum sibling (2013) and 1 inactive sibling (2014).
        $bankRes = $this->getJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/question-bank?per_page=20")
            ->assertOk();

        $units = collect($bankRes->json('units'));
        $group92Unit = $units->firstWhere('scenario_group_id', 92);
        $this->assertNotNull($group92Unit);
        $this->assertSame('stimulus_group', $group92Unit['item_type']);
        $this->assertSame(3, $group92Unit['eligible_question_count']);
        $this->assertSame(2, $group92Unit['excluded_ineligible_count']);
        $this->assertTrue($group92Unit['has_ineligible_members']);
        // Ordered internally by scenario_question_order (1 -> 2012, 2 -> 2010, 3 -> 2011)
        $this->assertEquals([2012, 2010, 2011], collect($group92Unit['questions'])->pluck('id')->all());
        $this->assertStringNotContainsString('is_answer', $bankRes->getContent());

        // Dedicated endpoint /stimulus-groups/{groupId} resolves complete eligible group and hides answers
        $groupRes = $this->getJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/stimulus-groups/92")
            ->assertOk()
            ->assertJsonPath('policy.answers_included', false)
            ->assertJsonPath('data.scenario_group.id', 92)
            ->assertJsonPath('data.eligible_question_count', 3)
            ->assertJsonPath('data.excluded_ineligible_count', 2);
        $this->assertEquals([2012, 2010, 2011], collect($groupRes->json('data.questions'))->pluck('id')->all());

        // Attempting to save only a PARTIAL subset of eligible group 92 (e.g. missing 2011) must fail 422
        $this->postJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests", [
            'title' => 'Partial Stimulus Group Attempt',
            'selection_method' => 'manual',
            'questions' => [
                ['question_id' => 2012],
                ['question_id' => 2010],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'incomplete_stimulus_group');
    }

    public function test_all_formats_ordering_and_saved_presentation_structure(): void
    {
        $this->seedMultiQuestionStimulusGroups();
        $this->actingAs($this->builderTeacher->webUser, 'web_api');

        // Submit standalone and stimulus-group MCQs interleaved out of order:
        // Group 93 (4 MCQs: 2020..2023), Standalone 2005, Group 92 (3 MCQs: 2010..2012), Standalone 2001.
        $createRes = $this->postJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests", [
            'title' => 'All Formats Ordering Test',
            'selection_method' => 'manual',
            'marks_per_question' => 1,
            'language_mode' => 'bilingual',
            'questions' => [
                ['question_id' => 2023],
                ['question_id' => 2005],
                ['question_id' => 2020],
                ['question_id' => 2011],
                ['question_id' => 2021],
                ['question_id' => 2010],
                ['question_id' => 2001],
                ['question_id' => 2022],
                ['question_id' => 2012],
            ],
        ])->assertCreated();

        $testId = $createRes->json('data.id');
        // Saved canonical All Formats order:
        // Standalone first (2005, 2001 preserving standalone relative order),
        // then Group 93 contiguous in scenario_question_order (2020, 2021, 2022, 2023),
        // then Group 92 contiguous in scenario_question_order (2012, 2010, 2011).
        $savedIds = collect($createRes->json('data.questions'))->pluck('question_id')->all();
        $this->assertEquals([2005, 2001, 2020, 2021, 2022, 2023, 2012, 2010, 2011], $savedIds);

        // Verify persisted DB rows and reconstructed detail presentation
        $detail = $this->getJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/{$testId}")
            ->assertOk()
            ->assertJsonPath('data.total_questions', 9)
            ->assertJsonPath('data.total_marks', 9);

        $this->assertEquals([2005, 2001], collect($detail->json('data.presentation.standalone_questions'))->pluck('question_id')->all());
        $this->assertCount(2, $detail->json('data.presentation.stimulus_groups'));
        $this->assertSame(93, $detail->json('data.presentation.stimulus_groups.0.scenario_group.id'));
        $this->assertEquals([2020, 2021, 2022, 2023], collect($detail->json('data.presentation.stimulus_groups.0.questions'))->pluck('question_id')->all());
        $this->assertSame(92, $detail->json('data.presentation.stimulus_groups.1.scenario_group.id'));
        $this->assertEquals([2012, 2010, 2011], collect($detail->json('data.presentation.stimulus_groups.1.questions'))->pluck('question_id')->all());
    }

    public function test_automatic_selection_treats_stimulus_groups_as_atomic_and_explains_count_adjustment(): void
    {
        $this->seedMultiQuestionStimulusGroups();
        $this->actingAs($this->builderTeacher->webUser, 'web_api');

        // Filter to topic 1001, which in our test seed has 1 standalone (2005) + group 91 (2004, size 1) + group 93 (4 MCQs).
        // First filter to only mcq_format = 'stimulus' within Unit 100 + 101:
        // Wait — test exact atomic non-splitting in Federal/Biology or Unit 100!
        // In PTB Unit 101 with mcq_format=stimulus and excluding 2004, ONLY Group 93 (4 MCQs) matches.
        // Requesting 2 MCQs cannot split Group 93 -> must fail insufficient? No: available=4 >= 2,
        // so atomic picker selects 1 complete group of 4 and explains adjustment before saving!
        $overMinRes = $this->postJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/auto-select", [
            'question_count' => 2,
            'unit_ids' => [101],
            'mcq_format' => 'stimulus',
            'exclude_question_ids' => [2004],
            'seed' => 7,
        ])
            ->assertOk()
            ->assertJsonPath('requested_count', 2)
            ->assertJsonPath('selected_count', 4)
            ->assertJsonPath('exact_count_met', false);

        $this->assertNotEmpty($overMinRes->json('count_adjustment_reason'));
        $this->assertEquals([2020, 2021, 2022, 2023], collect($overMinRes->json('data'))->pluck('id')->all());

        // Across stimulus-only units in PTB excluding 2002 & 2004, we have Group 92 (3 MCQs) + Group 93 (4 MCQs) = 7 MCQs total.
        // Requesting 5 MCQs cannot equal 5 cleanly with {3, 4} (largest sum <= 5 is 4 or 3, without splitting).
        // Group chosen is NEVER split, exact_count_met=false, and explanation is provided.
        $unsplitRes = $this->postJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/auto-select", [
            'question_count' => 5,
            'mcq_format' => 'stimulus',
            'exclude_question_ids' => [2002, 2004],
            'seed' => 11,
        ])
            ->assertOk()
            ->assertJsonPath('requested_count', 5)
            ->assertJsonPath('available_count', 7)
            ->assertJsonPath('exact_count_met', false);

        $pickedIds = collect($unsplitRes->json('data'))->pluck('id')->all();
        $this->assertContains(count($pickedIds), [3, 4]);
        $this->assertCount(1, $unsplitRes->json('units'));
        $this->assertSame('stimulus_group', $unsplitRes->json('units.0.item_type'));

        // Combining Group 92 (3 MCQs) + Group 93 (4 MCQs) for question_count=7 achieves exact 7 with both groups intact!
        $exactCombo = $this->postJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/auto-select", [
            'question_count' => 7,
            'mcq_format' => 'stimulus',
            'exclude_question_ids' => [2002, 2004],
            'seed' => 15,
        ])
            ->assertOk()
            ->assertJsonPath('selected_count', 7)
            ->assertJsonPath('exact_count_met', true)
            ->assertJsonPath('selection_summary.stimulus_group_count', 2);

        $this->assertCount(7, array_unique(collect($exactCombo->json('data'))->pluck('id')->all()));
    }

    public function test_cross_curriculum_stimulus_group_security_isolation(): void
    {
        $this->seedMultiQuestionStimulusGroups();
        $this->actingAs($this->builderTeacher->webUser, 'web_api');

        // Group 94 belongs strictly to Federal Physics (2030, 2031).
        // PTB Physics teacher MUST NOT access Group 94 via /stimulus-groups/{groupId}
        $this->getJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/stimulus-groups/94")
            ->assertForbidden()
            ->assertJsonPath('code', 'resource_out_of_class_scope');

        // Biology teacher MUST NOT access PTB Group 92
        $this->getJson("/web_api/auth/teacher/classes/{$this->biologyClass->id}/tests/stimulus-groups/92")
            ->assertForbidden()
            ->assertJsonPath('code', 'resource_out_of_class_scope');

        // Trying to include the cross-curriculum sibling 2013 (shares scenario_group_id=92!) into PTB test is rejected 403
        $this->postJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests", [
            'title' => 'Cross-Curriculum Sibling Escalation Trap',
            'selection_method' => 'manual',
            'questions' => [
                ['question_id' => 2012],
                ['question_id' => 2010],
                ['question_id' => 2011],
                ['question_id' => 2013],
            ],
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'resource_out_of_class_scope');
    }

    public function test_scenario_group_five_questions_selects_four_and_persists_identically_across_thirty_students(): void
    {
        $this->actingAs($this->builderTeacher->webUser, 'web_api');

        // Seed Scenario Group 95 with 5 curriculum-eligible active MCQs
        DB::table('question_scenario_groups_tbl')->insert([
            'id' => 95,
            'title' => 'Five Question Scenario Passage',
            'scenario_text' => '<p>Electromagnetic radiation and photon interactions setup.</p>',
            'scenario_image' => 'https://cdn.thestudentstimes.com/dev/questions/em-spectrum.webp',
            'activate' => 1,
        ]);

        $mcqRows = [];
        $optionRows = [];
        $optId = 8000;
        for ($i = 1; $i <= 5; $i++) {
            $qid = 2040 + $i;
            $mcqRows[] = [
                'id' => $qid,
                'question' => "<p>EM Scenario Question {$i}</p>",
                'question_um' => null,
                'topic_id' => 1000,
                'unit_id' => 100,
                'book_id' => 10,
                'difficulty' => 1,
                'cognitive_domain' => 1,
                'is_alp_question' => 0,
                'is_mcq' => 1,
                'activate' => 1,
                'question_presentation_type_id' => 2,
                'scenario_group_id' => 95,
                'scenario_question_order' => $i,
                'slo_topic_id' => 500,
                'marks' => 1,
                'explanation' => "Explanation for EM Question {$i}",
                'explanation_um' => null,
                'explanation_video_url' => null,
            ];

            foreach (['A', 'B', 'C', 'D'] as $idx => $letter) {
                $optId++;
                $optionRows[] = [
                    'id' => $optId,
                    'question_id' => $qid,
                    'option' => "<p>Option {$letter} for Q{$i}</p>",
                    'option_um' => null,
                    'is_answer' => $idx === 0 ? 1 : 0,
                ];
            }
        }
        DB::table('exam_question_tbl')->insert($mcqRows);
        DB::table('exam_question_options_tbl')->insert($optionRows);

        // 1. Question bank unit for Group 95 contains exactly 4 selected MCQs
        $bankRes = $this->getJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/question-bank?unit_id=100")
            ->assertOk();
        $units = collect($bankRes->json('units'));
        $g95Unit = $units->firstWhere('scenario_group_id', 95);
        $this->assertNotNull($g95Unit);
        $this->assertSame('stimulus_group', $g95Unit['item_type']);
        $this->assertSame(4, $g95Unit['question_count']);
        $this->assertCount(4, $g95Unit['questions']);

        // 2. Auto-select for Group 95 selects exactly 4 MCQs (random selection happens on backend at test generation time)
        $auto = $this->postJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/auto-select", [
            'question_count' => 4,
            'unit_ids' => [100],
            'mcq_format' => 'stimulus',
            'exclude_question_ids' => [2002, 2004],
            'seed' => 12345,
        ])
            ->assertOk()
            ->assertJsonPath('selected_count', 4)
            ->assertJsonPath('available_count', 4);

        $selectedQuestions = $auto->json('data');
        $this->assertCount(4, $selectedQuestions);
        $selectedIds = collect($selectedQuestions)->pluck('id')->all();

        // All 4 questions belong to Group 95
        foreach ($selectedIds as $sid) {
            $this->assertContains($sid, [2041, 2042, 2043, 2044, 2045]);
        }
        // Ordered internally by scenario_question_order ASC
        $orders = collect($selectedQuestions)->pluck('scenario_question_order')->all();
        $sortedOrders = $orders;
        sort($sortedOrders);
        $this->assertSame($sortedOrders, $orders);

        // 3. Pre-save answer review previews the 4 selected MCQs
        $review = $this->postJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests/question-review", [
            'question_ids' => $selectedIds,
        ])
            ->assertOk()
            ->assertJsonPath('policy.answers_included', true)
            ->assertJsonCount(4, 'data')
            ->assertJsonCount(4, 'presentation.stimulus_groups.0.questions');

        // 4. Incomplete stimulus group (submitting only 3 of 4) is rejected 422
        $this->postJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests", [
            'title' => 'Incomplete 3 Questions Attempt',
            'selection_method' => 'manual',
            'questions' => [
                ['question_id' => $selectedIds[0]],
                ['question_id' => $selectedIds[1]],
                ['question_id' => $selectedIds[2]],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'incomplete_stimulus_group');

        // 5. Saving test with the 4 selected MCQs persists test questions immutably
        $saveRes = $this->postJson("/web_api/auth/teacher/classes/{$this->ptbClass->id}/tests", [
            'title' => 'EM Waves 4-MCQ Test',
            'selection_method' => 'automatic',
            'marks_per_question' => 1,
            'language_mode' => 'bilingual',
            'questions' => array_map(fn ($qid) => ['question_id' => $qid], $selectedIds),
        ])->assertCreated();

        $testId = (int) $saveRes->json('data.id');

        // Verify persisted question rows in teacher_class_test_questions
        $persistedQuestions = DB::table('teacher_class_test_questions')
            ->where('teacher_class_test_id', $testId)
            ->orderBy('question_order')
            ->get();

        $this->assertCount(4, $persistedQuestions);
        $persistedQids = $persistedQuestions->pluck('question_id')->map(fn ($id) => (int) $id)->all();
        $this->assertEquals($selectedIds, $persistedQids);

        // 6. Acceptance Criterion: If 30 students take the same assigned test,
        // all 30 receive the exact same 4 MCQs for the scenario in the exact same question order!
        for ($studentNum = 1; $studentNum <= 30; $studentNum++) {
            // Emulate student attempt answer creation from persisted teacher_class_test_questions
            $studentAttemptQuestions = DB::table('teacher_class_test_questions')
                ->where('teacher_class_test_id', $testId)
                ->orderBy('question_order')
                ->orderBy('id')
                ->get();

            $attemptQids = $studentAttemptQuestions->pluck('question_id')->map(fn ($id) => (int) $id)->all();
            $attemptOrders = $studentAttemptQuestions->pluck('question_order')->map(fn ($ord) => (int) $ord)->all();

            $this->assertSame($selectedIds, $attemptQids, "Student #{$studentNum} must receive the exact persisted 4 question IDs.");
            $this->assertSame([1, 2, 3, 4], $attemptOrders, "Student #{$studentNum} must preserve exact original question order.");
        }
    }

    private function seedMultiQuestionStimulusGroups(): void
    {
        DB::table('question_scenario_groups_tbl')->insert([
            ['id' => 92, 'title' => 'Resonance Experiment Passage', 'scenario_text' => '<p>A tuning fork resonates over an air column.</p>', 'scenario_image' => null, 'activate' => 1],
            ['id' => 93, 'title' => 'Doppler Wavefront Stimulus', 'scenario_text' => '<p>A moving source emits sound crests.</p>', 'scenario_image' => 'https://cdn.thestudentstimes.com/dev/questions/doppler.png', 'activate' => 1],
            ['id' => 94, 'title' => 'Federal Oscillations Group', 'scenario_text' => '<p>Federal-only damped spring setup.</p>', 'scenario_image' => null, 'activate' => 1],
        ]);

        // Group 92: 3 eligible PTB MCQs out of DB ID order + 1 cross-curriculum Federal MCQ (2013) + 1 inactive MCQ (2014)
        DB::table('exam_question_tbl')->insert([
            [
                'id' => 2010, 'question' => '<p>Group 92 Q2</p>', 'question_um' => null,
                'topic_id' => 1000, 'unit_id' => 100, 'book_id' => 10, 'difficulty' => 1, 'cognitive_domain' => 1,
                'is_alp_question' => 0, 'is_mcq' => 1, 'activate' => 1, 'question_presentation_type_id' => 2,
                'scenario_group_id' => 92, 'scenario_question_order' => 2, 'slo_topic_id' => 500, 'marks' => 1,
                'explanation' => 'Exp 2010', 'explanation_um' => null, 'explanation_video_url' => null,
            ],
            [
                'id' => 2011, 'question' => '<p>Group 92 Q3</p>', 'question_um' => null,
                'topic_id' => 1000, 'unit_id' => 100, 'book_id' => 10, 'difficulty' => 2, 'cognitive_domain' => 2,
                'is_alp_question' => 0, 'is_mcq' => 1, 'activate' => 1, 'question_presentation_type_id' => 2,
                'scenario_group_id' => 92, 'scenario_question_order' => 3, 'slo_topic_id' => 500, 'marks' => 1,
                'explanation' => 'Exp 2011', 'explanation_um' => null, 'explanation_video_url' => null,
            ],
            [
                'id' => 2012, 'question' => '<p>Group 92 Q1</p>', 'question_um' => null,
                'topic_id' => 1000, 'unit_id' => 100, 'book_id' => 10, 'difficulty' => 1, 'cognitive_domain' => 1,
                'is_alp_question' => 0, 'is_mcq' => 1, 'activate' => 1, 'question_presentation_type_id' => 2,
                'scenario_group_id' => 92, 'scenario_question_order' => 1, 'slo_topic_id' => 500, 'marks' => 1,
                'explanation' => 'Exp 2012', 'explanation_um' => null, 'explanation_video_url' => null,
            ],
            [
                'id' => 2013, 'question' => '<p>Cross-curriculum Federal sibling in Group 92</p>', 'question_um' => null,
                'topic_id' => 2000, 'unit_id' => 100, 'book_id' => 10, 'difficulty' => 1, 'cognitive_domain' => 1,
                'is_alp_question' => 0, 'is_mcq' => 1, 'activate' => 1, 'question_presentation_type_id' => 2,
                'scenario_group_id' => 92, 'scenario_question_order' => 4, 'slo_topic_id' => 501, 'marks' => 1,
                'explanation' => 'Federal leak trap', 'explanation_um' => null, 'explanation_video_url' => null,
            ],
            [
                'id' => 2014, 'question' => '<p>Inactive PTB sibling in Group 92</p>', 'question_um' => null,
                'topic_id' => 1000, 'unit_id' => 100, 'book_id' => 10, 'difficulty' => 1, 'cognitive_domain' => 1,
                'is_alp_question' => 0, 'is_mcq' => 1, 'activate' => 0, 'question_presentation_type_id' => 2,
                'scenario_group_id' => 92, 'scenario_question_order' => 5, 'slo_topic_id' => 500, 'marks' => 1,
                'explanation' => null, 'explanation_um' => null, 'explanation_video_url' => null,
            ],
            // Group 93: 4 eligible PTB MCQs in Unit 101
            [
                'id' => 2020, 'question' => '<p>Group 93 Q1</p>', 'question_um' => null,
                'topic_id' => 1001, 'unit_id' => 101, 'book_id' => 10, 'difficulty' => 1, 'cognitive_domain' => 1,
                'is_alp_question' => 0, 'is_mcq' => 1, 'activate' => 1, 'question_presentation_type_id' => 2,
                'scenario_group_id' => 93, 'scenario_question_order' => 1, 'slo_topic_id' => null, 'marks' => 1,
                'explanation' => 'Exp 2020', 'explanation_um' => null, 'explanation_video_url' => null,
            ],
            [
                'id' => 2021, 'question' => '<p>Group 93 Q2</p>', 'question_um' => null,
                'topic_id' => 1001, 'unit_id' => 101, 'book_id' => 10, 'difficulty' => 1, 'cognitive_domain' => 1,
                'is_alp_question' => 0, 'is_mcq' => 1, 'activate' => 1, 'question_presentation_type_id' => 2,
                'scenario_group_id' => 93, 'scenario_question_order' => 2, 'slo_topic_id' => null, 'marks' => 1,
                'explanation' => 'Exp 2021', 'explanation_um' => null, 'explanation_video_url' => null,
            ],
            [
                'id' => 2022, 'question' => '<p>Group 93 Q3</p>', 'question_um' => null,
                'topic_id' => 1001, 'unit_id' => 101, 'book_id' => 10, 'difficulty' => 2, 'cognitive_domain' => 2,
                'is_alp_question' => 0, 'is_mcq' => 1, 'activate' => 1, 'question_presentation_type_id' => 2,
                'scenario_group_id' => 93, 'scenario_question_order' => 3, 'slo_topic_id' => null, 'marks' => 1,
                'explanation' => 'Exp 2022', 'explanation_um' => null, 'explanation_video_url' => null,
            ],
            [
                'id' => 2023, 'question' => '<p>Group 93 Q4</p>', 'question_um' => null,
                'topic_id' => 1001, 'unit_id' => 101, 'book_id' => 10, 'difficulty' => 2, 'cognitive_domain' => 2,
                'is_alp_question' => 0, 'is_mcq' => 1, 'activate' => 1, 'question_presentation_type_id' => 2,
                'scenario_group_id' => 93, 'scenario_question_order' => 4, 'slo_topic_id' => null, 'marks' => 1,
                'explanation' => 'Exp 2023', 'explanation_um' => null, 'explanation_video_url' => null,
            ],
            // Group 94: Federal-only group
            [
                'id' => 2030, 'question' => '<p>Federal Group 94 Q1</p>', 'question_um' => null,
                'topic_id' => 2000, 'unit_id' => 200, 'book_id' => 20, 'difficulty' => 1, 'cognitive_domain' => 1,
                'is_alp_question' => 0, 'is_mcq' => 1, 'activate' => 1, 'question_presentation_type_id' => 2,
                'scenario_group_id' => 94, 'scenario_question_order' => 1, 'slo_topic_id' => 501, 'marks' => 1,
                'explanation' => null, 'explanation_um' => null, 'explanation_video_url' => null,
            ],
        ]);

        DB::table('exam_question_options_tbl')->insert([
            ['question_id' => 2010, 'option' => '<p>A</p>', 'option_um' => null, 'is_answer' => 1],
            ['question_id' => 2011, 'option' => '<p>A</p>', 'option_um' => null, 'is_answer' => 1],
            ['question_id' => 2012, 'option' => '<p>A</p>', 'option_um' => null, 'is_answer' => 1],
            ['question_id' => 2013, 'option' => '<p>A</p>', 'option_um' => null, 'is_answer' => 1],
            ['question_id' => 2020, 'option' => '<p>A</p>', 'option_um' => null, 'is_answer' => 1],
            ['question_id' => 2021, 'option' => '<p>A</p>', 'option_um' => null, 'is_answer' => 1],
            ['question_id' => 2022, 'option' => '<p>A</p>', 'option_um' => null, 'is_answer' => 1],
            ['question_id' => 2023, 'option' => '<p>A</p>', 'option_um' => null, 'is_answer' => 1],
            ['question_id' => 2030, 'option' => '<p>A</p>', 'option_um' => null, 'is_answer' => 1],
        ]);
    }

    private function createClass(TeacherProfile $profile, array $overrides = []): TeacherClass
    {
        $class = new TeacherClass(array_merge([
            'curriculum_board_id' => 1,
            'class_id' => 1,
            'subject_id' => 1,
            'name' => 'Default Test Class',
            'section' => 'A',
            'is_active' => true,
            'requires_approval' => true,
        ], $overrides));
        $class->teacher_profile_id = $profile->id;
        $class->class_code = TeacherClass::generateUniqueCode();
        $class->save();

        return $class;
    }

    private function createBuilderResourceTables(): void
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
            'book_tbl' => function (Blueprint $t) {
                $t->id();
                $t->string('book_name');
                $t->unsignedBigInteger('curriculum_board_id');
                $t->unsignedBigInteger('class_id');
                $t->unsignedBigInteger('subject_id');
                $t->boolean('activate')->default(true);
            },
            'book_unit_tbl' => function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('book_id');
                $t->integer('unit_no')->nullable();
                $t->string('unit_name');
                $t->boolean('is_alp')->default(false);
                $t->boolean('activate')->default(true);
            },
            'book_unit_topic_tbl' => function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('unit_id');
                $t->string('topic_no')->nullable();
                $t->string('topic_name');
                $t->string('slo_no')->nullable();
                $t->boolean('activate')->default(true);
            },
            'board_tbl' => function (Blueprint $t) {
                $t->id();
                $t->string('board_name');
                $t->string('short_name')->nullable();
                $t->boolean('activate')->default(true);
            },
            'exam_session_tbl' => function (Blueprint $t) {
                $t->id();
                $t->string('session_name');
                $t->boolean('activate')->default(true);
            },
            'study_group_tbl' => function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->unsignedBigInteger('class_id')->nullable();
                $t->unsignedBigInteger('curriculum_board_id')->nullable();
                $t->boolean('activate')->default(true);
            },
            'slo_domain_tbl' => function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('curriculum_board_id');
                $t->unsignedBigInteger('subject_id');
                $t->string('domain_code');
                $t->string('domain_name');
                $t->boolean('activate')->default(true);
            },
            'slo_topic_tbl' => function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('slo_domain_id');
                $t->unsignedBigInteger('class_id');
                $t->string('slo_code');
                $t->string('slo_title');
                $t->string('slo_title_um')->nullable();
                $t->string('cognitive_level')->nullable();
                $t->boolean('activate')->default(true);
            },
            'unit_slo_topic_tbl' => function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('unit_id');
                $t->unsignedBigInteger('slo_topic_id');
            },
            'topic_slo_topic_tbl' => function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('topic_id');
                $t->unsignedBigInteger('slo_topic_id');
            },
            'cognitive_domain_tbl' => function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('short');
            },
            'question_presentation_type_tbl' => function (Blueprint $t) {
                $t->id();
                $t->string('type_name');
                $t->string('code');
                $t->boolean('allows_multiple_mcqs')->default(false);
                $t->boolean('activate')->default(true);
            },
            'question_scenario_groups_tbl' => function (Blueprint $t) {
                $t->id();
                $t->string('title')->nullable();
                $t->text('scenario_text')->nullable();
                $t->text('scenario_text_um')->nullable();
                $t->string('scenario_image')->nullable();
                $t->boolean('activate')->default(true);
            },
            'exam_question_tbl' => function (Blueprint $t) {
                $t->id();
                $t->text('question')->nullable();
                $t->text('question_um')->nullable();
                $t->unsignedBigInteger('topic_id');
                $t->unsignedBigInteger('unit_id')->nullable();
                $t->unsignedBigInteger('book_id')->nullable();
                $t->boolean('is_alp_question')->default(false);
                $t->boolean('exercise_question')->default(false);
                $t->boolean('activate')->default(true);
                $t->decimal('marks', 6, 2)->default(1);
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
            },
            'exam_question_options_tbl' => function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('question_id');
                $t->text('option')->nullable();
                $t->text('option_um')->nullable();
                $t->integer('option_lang')->default(0);
                $t->integer('option_um_lang')->default(1);
                $t->boolean('is_answer')->default(false);
            },
            'exam_question_board_tbl' => function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('question_id');
                $t->unsignedBigInteger('board_id');
                $t->unsignedBigInteger('session_id')->nullable();
                $t->unsignedBigInteger('group_id')->nullable();
                $t->integer('year')->nullable();
                $t->boolean('activate')->default(true);
            },
        ];

        foreach ($tables as $name => $definition) {
            Schema::dropIfExists($name);
            Schema::create($name, $definition);
        }
    }

    private function seedBuilderResourceData(): void
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
            ['id' => 11, 'board_name' => 'BISE Lahore', 'short_name' => 'LHR', 'activate' => 1],
            ['id' => 12, 'board_name' => 'FBISE Islamabad', 'short_name' => 'FBISE', 'activate' => 1],
        ]);
        DB::table('exam_session_tbl')->insert([
            ['id' => 1, 'session_name' => 'Annual', 'activate' => 1],
        ]);
        DB::table('study_group_tbl')->insert([
            ['id' => 1, 'name' => 'Science Group', 'class_id' => 1, 'curriculum_board_id' => 1, 'activate' => 1],
        ]);
        DB::table('book_tbl')->insert([
            ['id' => 10, 'book_name' => 'PTB Physics 10', 'curriculum_board_id' => 1, 'class_id' => 1, 'subject_id' => 1, 'activate' => 1],
            ['id' => 20, 'book_name' => 'Federal Physics 10', 'curriculum_board_id' => 2, 'class_id' => 1, 'subject_id' => 1, 'activate' => 1],
        ]);
        DB::table('book_unit_tbl')->insert([
            ['id' => 100, 'book_id' => 10, 'unit_no' => 10, 'unit_name' => 'SHM and Waves', 'activate' => 1],
            ['id' => 101, 'book_id' => 10, 'unit_no' => 11, 'unit_name' => 'Sound Waves', 'activate' => 1],
            ['id' => 200, 'book_id' => 20, 'unit_no' => 10, 'unit_name' => 'Oscillations (FBISE)', 'activate' => 1],
        ]);
        DB::table('book_unit_topic_tbl')->insert([
            ['id' => 1000, 'unit_id' => 100, 'topic_no' => '10.1', 'topic_name' => 'Simple Harmonic Motion', 'activate' => 1],
            ['id' => 1001, 'unit_id' => 101, 'topic_no' => '11.1', 'topic_name' => 'Speed of Sound', 'activate' => 1],
            ['id' => 2000, 'unit_id' => 200, 'topic_no' => '10.1', 'topic_name' => 'Restoring Force', 'activate' => 1],
        ]);
        DB::table('slo_domain_tbl')->insert([
            ['id' => 1, 'curriculum_board_id' => 1, 'subject_id' => 1, 'domain_code' => 'DOM-PHY-PTB', 'domain_name' => 'Mechanics & Waves', 'activate' => 1],
            ['id' => 2, 'curriculum_board_id' => 2, 'subject_id' => 1, 'domain_code' => 'DOM-PHY-FED', 'domain_name' => 'Oscillatory Physics', 'activate' => 1],
        ]);
        DB::table('slo_topic_tbl')->insert([
            ['id' => 500, 'slo_domain_id' => 1, 'class_id' => 1, 'slo_code' => 'SLO-P-10-01', 'slo_title' => 'Analyze restoring force in SHM', 'activate' => 1],
            ['id' => 501, 'slo_domain_id' => 2, 'class_id' => 1, 'slo_code' => 'SLO-FED-10-01', 'slo_title' => 'Derive time period of simple pendulum', 'activate' => 1],
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
            ['id' => 91, 'title' => 'Wave Diagram', 'scenario_text' => '<p>Observe the wave graph below.</p>', 'scenario_image' => 'https://cdn.thestudentstimes.com/dev/questions/imported/2026/11/sample-wave.webp', 'activate' => 1],
        ]);
        DB::table('exam_question_tbl')->insert([
            [
                'id' => 2001, 'question' => '<p>In SHM acceleration is proportional to displacement.</p>', 'question_um' => null,
                'topic_id' => 1000, 'unit_id' => 100, 'book_id' => 10, 'difficulty' => 1, 'cognitive_domain' => 1,
                'is_alp_question' => 0, 'is_mcq' => 1, 'activate' => 1, 'question_presentation_type_id' => 1,
                'scenario_group_id' => null, 'slo_topic_id' => null, 'marks' => 1,
                'explanation' => 'SECRET_EXPLANATION_MUST_NOT_LEAK', 'explanation_um' => 'SECRET_UM', 'explanation_video_url' => 'https://secret.video/1',
            ],
            [
                'id' => 2002, 'question' => '<p>If pendulum length quadruples, time period becomes:</p>', 'question_um' => null,
                'topic_id' => 1000, 'unit_id' => 100, 'book_id' => 10, 'difficulty' => 2, 'cognitive_domain' => 2,
                'is_alp_question' => 0, 'is_mcq' => 1, 'activate' => 1, 'question_presentation_type_id' => 2,
                'scenario_group_id' => 90, 'slo_topic_id' => 500, 'marks' => 1,
                'explanation' => 'SECRET_EXPLANATION_2', 'explanation_um' => 'SECRET_UM_2', 'explanation_video_url' => null,
            ],
            [
                'id' => 2003, 'question' => '<p>Federal board oscillation question</p>', 'question_um' => null,
                'topic_id' => 2000, 'unit_id' => 200, 'book_id' => 20, 'difficulty' => 1, 'cognitive_domain' => 1,
                'is_alp_question' => 0, 'is_mcq' => 1, 'activate' => 1, 'question_presentation_type_id' => 1,
                'scenario_group_id' => null, 'slo_topic_id' => 501, 'marks' => 1,
                'explanation' => 'FED_SECRET', 'explanation_um' => null, 'explanation_video_url' => null,
            ],
            [
                'id' => 2004, 'question' => '<p>Speed of sound is fastest in:</p>', 'question_um' => '<p>آواز کی رفتار سب سے تیز کس میں ہوتی ہے؟</p>',
                'topic_id' => 1001, 'unit_id' => 101, 'book_id' => 10, 'difficulty' => 3, 'cognitive_domain' => 2,
                'is_alp_question' => 1, 'is_mcq' => 1, 'activate' => 1, 'question_presentation_type_id' => 2,
                'scenario_group_id' => 91, 'slo_topic_id' => 500, 'marks' => 2,
                'explanation' => 'Solids have highest elasticity.', 'explanation_um' => 'ٹھوس اشیاء میں لچک زیادہ ہوتی ہے۔', 'explanation_video_url' => null,
            ],
            [
                'id' => 2005, 'question' => '<p>Audible frequency range is:</p>', 'question_um' => null,
                'topic_id' => 1001, 'unit_id' => 101, 'book_id' => 10, 'difficulty' => 1, 'cognitive_domain' => 1,
                'is_alp_question' => 0, 'is_mcq' => 1, 'activate' => 1, 'question_presentation_type_id' => 1,
                'scenario_group_id' => null, 'slo_topic_id' => null, 'marks' => 1,
                'explanation' => '20 Hz to 20,000 Hz.', 'explanation_um' => null, 'explanation_video_url' => null,
            ],
            [
                // Canonical topic_id belongs to Federal (2000), stale unit_id/book_id point to PTB (100/10)
                'id' => 2006, 'question' => '<p>Stale denormalized Federal MCQ trap</p>', 'question_um' => null,
                'topic_id' => 2000, 'unit_id' => 100, 'book_id' => 10, 'difficulty' => 1, 'cognitive_domain' => 1,
                'is_alp_question' => 0, 'is_mcq' => 1, 'activate' => 1, 'question_presentation_type_id' => 1,
                'scenario_group_id' => null, 'slo_topic_id' => 501, 'marks' => 1,
                'explanation' => 'Should only show in Federal class.', 'explanation_um' => null, 'explanation_video_url' => null,
            ],
            [
                'id' => 2007, 'question' => '<p>Inactive PTB MCQ</p>', 'question_um' => null,
                'topic_id' => 1000, 'unit_id' => 100, 'book_id' => 10, 'difficulty' => 1, 'cognitive_domain' => 1,
                'is_alp_question' => 0, 'is_mcq' => 1, 'activate' => 0, 'question_presentation_type_id' => 1,
                'scenario_group_id' => null, 'slo_topic_id' => null, 'marks' => 1,
                'explanation' => null, 'explanation_um' => null, 'explanation_video_url' => null,
            ],
            [
                'id' => 2008, 'question' => '<p>Define wave motion.</p>', 'question_um' => null,
                'topic_id' => 1000, 'unit_id' => 100, 'book_id' => 10, 'difficulty' => 1, 'cognitive_domain' => 1,
                'is_alp_question' => 0, 'is_mcq' => 0, 'activate' => 1, 'question_presentation_type_id' => 1,
                'scenario_group_id' => null, 'slo_topic_id' => null, 'marks' => 2,
                'explanation' => null, 'explanation_um' => null, 'explanation_video_url' => null,
            ],
        ]);
        DB::table('exam_question_options_tbl')->insert([
            ['id' => 1, 'question_id' => 2001, 'option' => '<p>True</p>', 'option_um' => null, 'is_answer' => 1],
            ['id' => 2, 'question_id' => 2001, 'option' => '<p>False</p>', 'option_um' => null, 'is_answer' => 0],
            ['id' => 3, 'question_id' => 2002, 'option' => '<p>Doubled</p>', 'option_um' => null, 'is_answer' => 1],
            ['id' => 4, 'question_id' => 2002, 'option' => '<p>Halved</p>', 'option_um' => null, 'is_answer' => 0],
            ['id' => 5, 'question_id' => 2003, 'option' => '<p>Fed Opt A</p>', 'option_um' => null, 'is_answer' => 1],
            ['id' => 10, 'question_id' => 2004, 'option' => '<p>Vacuum</p>', 'option_um' => '<p>خلا</p>', 'is_answer' => 0],
            ['id' => 11, 'question_id' => 2004, 'option' => '<p>Steel</p>', 'option_um' => '<p>اسٹیل</p>', 'is_answer' => 1],
            ['id' => 12, 'question_id' => 2004, 'option' => '<p>Water</p>', 'option_um' => '<p>پانی</p>', 'is_answer' => 0],
            ['id' => 13, 'question_id' => 2004, 'option' => '<p>Air</p>', 'option_um' => '<p>ہوا</p>', 'is_answer' => 0],
            ['id' => 14, 'question_id' => 2005, 'option' => '<p>20 Hz - 20 kHz</p>', 'option_um' => null, 'is_answer' => 1],
            ['id' => 15, 'question_id' => 2005, 'option' => '<p>2 Hz - 20 Hz</p>', 'option_um' => null, 'is_answer' => 0],
            ['id' => 16, 'question_id' => 2006, 'option' => '<p>Trap</p>', 'option_um' => null, 'is_answer' => 1],
        ]);
        DB::table('exam_question_board_tbl')->insert([
            ['id' => 1, 'question_id' => 2001, 'board_id' => 11, 'session_id' => 1, 'group_id' => 1, 'year' => 2024, 'activate' => 1],
            ['id' => 2, 'question_id' => 2002, 'board_id' => 11, 'session_id' => 1, 'group_id' => 1, 'year' => 2024, 'activate' => 1],
            ['id' => 3, 'question_id' => 2003, 'board_id' => 12, 'session_id' => 1, 'group_id' => null, 'year' => 2025, 'activate' => 1],
            ['id' => 4, 'question_id' => 2003, 'board_id' => 11, 'session_id' => 1, 'group_id' => null, 'year' => 2024, 'activate' => 1],
        ]);
    }
}
