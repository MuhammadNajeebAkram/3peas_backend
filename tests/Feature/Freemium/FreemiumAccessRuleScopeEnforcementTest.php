<?php

namespace Tests\Feature\Freemium;

use App\Http\Services\Freemium\FreemiumAccessRuleEvaluator;
use App\Http\Services\Freemium\FreemiumEvaluationResult;
use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumStudentGrant;
use App\Models\User;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class FreemiumAccessRuleScopeEnforcementTest extends TestCase
{
    private FreemiumAccessRuleEvaluator $evaluator;
    private WebUser $student;
    private User $admin;
    private Carbon $now;

    private int $boardId;
    private int $classId;
    private int $subjectId;
    private int $bookId;
    private int $chapterId;
    private int $topicId;
    private int $questionId;

    private int $altBoardId;
    private int $altClassId;
    private int $altSubjectId;
    private int $altBookId;
    private int $altChapterId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new FreemiumAccessRuleEvaluator();
        $this->now = Carbon::parse('2026-11-21 12:00:00', 'UTC');

        $adminId = DB::table('users')->value('id');
        $this->admin = $adminId ? User::find($adminId) : User::create([
            'name'     => 'Scope Admin',
            'email'    => 'admin_scope_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
        ]);

        $studentId = DB::table('web_users')->insertGetId([
            'name'       => 'Scope Student',
            'email'      => 'student_scope_' . uniqid() . '@example.com',
            'password'   => bcrypt('secret'),
            'role'       => 'student',
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->student = WebUser::find($studentId);

        // Ensure Campaign #2 fixture entities exist in test database
        DB::table('curriculum_board_tbl')->updateOrInsert(['id' => 1], ['name' => 'Punjab Board', 'activate' => 1]);
        DB::table('curriculum_board_tbl')->updateOrInsert(['id' => 2], ['name' => 'Federal Board', 'activate' => 1]);
        DB::table('class_tbl')->updateOrInsert(['id' => 1], ['class_name' => 'Class 9th', 'activate' => 1]);
        DB::table('class_tbl')->updateOrInsert(['id' => 2], ['class_name' => 'Class 10th', 'activate' => 1]);
        DB::table('subject_tbl')->updateOrInsert(['id' => 1], ['subject_name' => 'English', 'icon_name' => 'english.png', 'activate' => 1]);
        DB::table('subject_tbl')->updateOrInsert(['id' => 2], ['subject_name' => 'Mathematics', 'icon_name' => 'math.png', 'activate' => 1]);
        DB::table('subject_tbl')->updateOrInsert(['id' => 3], ['subject_name' => 'Urdu', 'icon_name' => 'urdu.png', 'activate' => 1]);
        DB::table('subject_tbl')->updateOrInsert(['id' => 5], ['subject_name' => 'Biology', 'icon_name' => 'bio.png', 'activate' => 1]);
        DB::table('book_tbl')->updateOrInsert(['id' => 16], ['book_name' => 'P.T.B Urdu 9th', 'class_id' => 1, 'subject_id' => 3, 'curriculum_board_id' => 1, 'activate' => 1]);
        DB::table('book_tbl')->updateOrInsert(['id' => 25], ['book_name' => 'Federal Mathematics 9th', 'class_id' => 1, 'subject_id' => 2, 'curriculum_board_id' => 2, 'activate' => 1]);
        DB::table('book_tbl')->updateOrInsert(['id' => 28], ['book_name' => 'Federal Biology 9th', 'class_id' => 1, 'subject_id' => 5, 'curriculum_board_id' => 2, 'activate' => 1]);
        DB::table('book_tbl')->updateOrInsert(['id' => 30], ['book_name' => 'Federal Biology (Other)', 'class_id' => 1, 'subject_id' => 5, 'curriculum_board_id' => 2, 'activate' => 1]);
        DB::table('book_unit_tbl')->updateOrInsert(['id' => 263], ['unit_name' => 'THE SCIENCE OF BIOLOGY', 'unit_no' => 1, 'book_id' => 28, 'activate' => 1]);
        DB::table('book_unit_tbl')->updateOrInsert(['id' => 264], ['unit_name' => 'Cell Biology', 'unit_no' => 2, 'book_id' => 28, 'activate' => 1]);

        $this->boardId    = 2;
        $this->classId    = 1;
        $this->subjectId  = 5;
        $this->bookId     = 28;
        $this->chapterId  = 263;
        $this->topicId    = (int) (DB::table('book_unit_topic_tbl')->value('id') ?? 100);
        $this->questionId = (int) (DB::table('exam_question_tbl')->value('id') ?? 500);

        $this->altBoardId   = 1;
        $this->altClassId   = 2;
        $this->altSubjectId = 2;
        $this->altBookId    = 30;
        $this->altChapterId = 264;
    }

    protected function tearDown(): void
    {
        FreemiumAccessRule::query()->delete();
        FreemiumStudentGrant::query()->delete();
        FreemiumCampaign::query()->delete();
        DB::table('web_users')->where('id', $this->student->id)->delete();

        parent::tearDown();
    }

    private function createCampaign(array $overrides = []): FreemiumCampaign
    {
        $campaign = FreemiumCampaign::create(array_merge([
            'name'         => 'Scope Campaign ' . uniqid(),
            'status'       => 'active',
            'is_stackable' => true,
            'priority'     => 50,
            'start_at'     => $this->now->copy()->subDays(5),
            'end_at'       => $this->now->copy()->addDays(5),
            'created_by'   => $this->admin->id,
        ], $overrides));

        $startTs = ($overrides['start_at'] ?? $this->now->copy()->subDays(5))->getTimestamp();
        $endTs   = ($overrides['end_at'] ?? $this->now->copy()->addDays(5))->getTimestamp();

        DB::table('freemium_campaigns')->where('id', $campaign->id)->update([
            'start_at' => DB::raw("FROM_UNIXTIME({$startTs})"),
            'end_at'   => DB::raw("FROM_UNIXTIME({$endTs})"),
        ]);

        return $campaign->fresh();
    }

    private function createRule(array $attributes): FreemiumAccessRule
    {
        return FreemiumAccessRule::create(array_merge([
            'target_status'            => 'active',
            'perm_mcqs'                => 'allow',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['target_scope' => $attributes['target_scope'] ?? 'global'],
        ], $attributes));
    }

    // =========================================================================
    // 1. Positive Matching Tests
    // =========================================================================

    public function test_positive_matching_chapter_rule(): void
    {
        $campaign = $this->createCampaign();

        $rule = $this->createRule([
            'campaign_id'  => $campaign->id,
            'target_scope' => 'chapter',
            'chapter_id'   => $this->chapterId,
        ]);

        $result = $this->evaluator->evaluate($this->student, [
            'chapter_id' => $this->chapterId,
        ], 'perm_mcqs', $this->now);

        $this->assertTrue($result->isAllowed());
        $this->assertEquals(FreemiumAccessRuleEvaluator::SPECIFICITY_CHAPTER, $result->getResolvedSpecificity());
        $this->assertEquals($rule->id, $result->getWinningRule()->id);
    }

    public function test_positive_matching_book_rule(): void
    {
        $campaign = $this->createCampaign();

        $rule = $this->createRule([
            'campaign_id'  => $campaign->id,
            'target_scope' => 'book',
            'book_id'      => $this->bookId,
        ]);

        $result = $this->evaluator->evaluate($this->student, [
            'book_id' => $this->bookId,
        ], 'perm_mcqs', $this->now);

        $this->assertTrue($result->isAllowed());
        $this->assertEquals(FreemiumAccessRuleEvaluator::SPECIFICITY_BOOK, $result->getResolvedSpecificity());
        $this->assertEquals($rule->id, $result->getWinningRule()->id);
    }

    public function test_positive_matching_subject_rule(): void
    {
        $campaign = $this->createCampaign();

        $rule = $this->createRule([
            'campaign_id'  => $campaign->id,
            'target_scope' => 'subject',
            'subject_id'   => $this->subjectId,
        ]);

        $result = $this->evaluator->evaluate($this->student, [
            'subject_id' => $this->subjectId,
        ], 'perm_mcqs', $this->now);

        $this->assertTrue($result->isAllowed());
        $this->assertEquals(FreemiumAccessRuleEvaluator::SPECIFICITY_SUBJECT, $result->getResolvedSpecificity());
        $this->assertEquals($rule->id, $result->getWinningRule()->id);
    }

    public function test_positive_matching_class_rule(): void
    {
        $campaign = $this->createCampaign();

        $rule = $this->createRule([
            'campaign_id'  => $campaign->id,
            'target_scope' => 'class',
            'class_id'     => $this->classId,
        ]);

        $result = $this->evaluator->evaluate($this->student, [
            'class_id' => $this->classId,
        ], 'perm_mcqs', $this->now);

        $this->assertTrue($result->isAllowed());
        $this->assertEquals(FreemiumAccessRuleEvaluator::SPECIFICITY_CLASS, $result->getResolvedSpecificity());
        $this->assertEquals($rule->id, $result->getWinningRule()->id);
    }

    public function test_positive_matching_board_rule(): void
    {
        $campaign = $this->createCampaign();

        // Test with curriculum_board
        $rule = $this->createRule([
            'campaign_id'         => $campaign->id,
            'target_scope'        => 'curriculum_board',
            'curriculum_board_id' => $this->boardId,
        ]);

        $result = $this->evaluator->evaluate($this->student, [
            'curriculum_board_id' => $this->boardId,
        ], 'perm_mcqs', $this->now);

        $this->assertTrue($result->isAllowed());
        $this->assertEquals(FreemiumAccessRuleEvaluator::SPECIFICITY_BOARD, $result->getResolvedSpecificity());
        $this->assertEquals($rule->id, $result->getWinningRule()->id);
    }

    public function test_positive_matching_global_rule(): void
    {
        $campaign = $this->createCampaign();

        $rule = $this->createRule([
            'campaign_id'  => $campaign->id,
            'target_scope' => 'global',
        ]);

        $result = $this->evaluator->evaluate($this->student, [
            'global' => true,
        ], 'perm_mcqs', $this->now);

        $this->assertTrue($result->isAllowed());
        $this->assertEquals(FreemiumAccessRuleEvaluator::SPECIFICITY_GLOBAL, $result->getResolvedSpecificity());
        $this->assertEquals($rule->id, $result->getWinningRule()->id);
    }

    // =========================================================================
    // 2. Scope Leakage Prevention Tests
    // =========================================================================

    public function test_chapter_rule_with_populated_hierarchy_does_not_leak_to_broader_specificities(): void
    {
        $campaign = $this->createCampaign();

        // A chapter rule fully populated with parent hierarchy IDs
        $this->createRule([
            'campaign_id'         => $campaign->id,
            'target_scope'        => 'chapter',
            'curriculum_board_id' => $this->boardId,
            'class_id'            => $this->classId,
            'subject_id'          => $this->subjectId,
            'book_id'             => $this->bookId,
            'chapter_id'          => $this->chapterId,
        ]);

        // 1. Should NOT match when context only specifies book_id (SPECIFICITY_BOOK)
        $resBook = $this->evaluator->evaluate($this->student, [
            'curriculum_board_id' => $this->boardId,
            'class_id'            => $this->classId,
            'subject_id'          => $this->subjectId,
            'book_id'             => $this->bookId,
        ], 'perm_mcqs', $this->now);
        $this->assertFalse($resBook->isAllowed(), 'Chapter rule must not match at Book specificity');

        // 2. Should NOT match when context only specifies subject_id (SPECIFICITY_SUBJECT)
        $resSubj = $this->evaluator->evaluate($this->student, [
            'curriculum_board_id' => $this->boardId,
            'class_id'            => $this->classId,
            'subject_id'          => $this->subjectId,
        ], 'perm_mcqs', $this->now);
        $this->assertFalse($resSubj->isAllowed(), 'Chapter rule must not match at Subject specificity');

        // 3. Should NOT match when context only specifies class_id (SPECIFICITY_CLASS)
        $resClass = $this->evaluator->evaluate($this->student, [
            'curriculum_board_id' => $this->boardId,
            'class_id'            => $this->classId,
        ], 'perm_mcqs', $this->now);
        $this->assertFalse($resClass->isAllowed(), 'Chapter rule must not match at Class specificity');

        // 4. Should NOT match when context only specifies curriculum_board_id (SPECIFICITY_BOARD)
        $resBoard = $this->evaluator->evaluate($this->student, [
            'curriculum_board_id' => $this->boardId,
        ], 'perm_mcqs', $this->now);
        $this->assertFalse($resBoard->isAllowed(), 'Chapter rule must not match at Board specificity');
    }

    public function test_book_rule_does_not_leak_to_subject_or_class(): void
    {
        $campaign = $this->createCampaign();

        $this->createRule([
            'campaign_id'         => $campaign->id,
            'target_scope'        => 'book',
            'curriculum_board_id' => $this->boardId,
            'class_id'            => $this->classId,
            'subject_id'          => $this->subjectId,
            'book_id'             => $this->bookId,
        ]);

        // Same subject, different book
        $resOtherBook = $this->evaluator->evaluate($this->student, [
            'curriculum_board_id' => $this->boardId,
            'class_id'            => $this->classId,
            'subject_id'          => $this->subjectId,
            'book_id'             => $this->altBookId,
        ], 'perm_mcqs', $this->now);
        $this->assertFalse($resOtherBook->isAllowed(), 'Book rule must not match another book in the same subject');

        // Querying subject only
        $resSubj = $this->evaluator->evaluate($this->student, [
            'subject_id' => $this->subjectId,
        ], 'perm_mcqs', $this->now);
        $this->assertFalse($resSubj->isAllowed(), 'Book rule must not match at Subject specificity');

        // Querying class only
        $resClass = $this->evaluator->evaluate($this->student, [
            'class_id' => $this->classId,
        ], 'perm_mcqs', $this->now);
        $this->assertFalse($resClass->isAllowed(), 'Book rule must not match at Class specificity');
    }

    public function test_subject_rule_does_not_leak_to_class_or_board(): void
    {
        $campaign = $this->createCampaign();

        $this->createRule([
            'campaign_id'         => $campaign->id,
            'target_scope'        => 'subject',
            'curriculum_board_id' => $this->boardId,
            'class_id'            => $this->classId,
            'subject_id'          => $this->subjectId,
        ]);

        // Different subject in the same class
        $resOtherSubj = $this->evaluator->evaluate($this->student, [
            'curriculum_board_id' => $this->boardId,
            'class_id'            => $this->classId,
            'subject_id'          => $this->altSubjectId,
        ], 'perm_mcqs', $this->now);
        $this->assertFalse($resOtherSubj->isAllowed(), 'Subject rule must not match a different subject in the same class');

        // Class only
        $resClass = $this->evaluator->evaluate($this->student, [
            'class_id' => $this->classId,
        ], 'perm_mcqs', $this->now);
        $this->assertFalse($resClass->isAllowed(), 'Subject rule must not match at Class specificity');

        // Board only
        $resBoard = $this->evaluator->evaluate($this->student, [
            'curriculum_board_id' => $this->boardId,
        ], 'perm_mcqs', $this->now);
        $this->assertFalse($resBoard->isAllowed(), 'Subject rule must not match at Board specificity');
    }

    public function test_class_rule_does_not_leak_to_board(): void
    {
        $campaign = $this->createCampaign();

        $this->createRule([
            'campaign_id'         => $campaign->id,
            'target_scope'        => 'class',
            'curriculum_board_id' => $this->boardId,
            'class_id'            => $this->classId,
        ]);

        // Different class in the same board
        $resOtherClass = $this->evaluator->evaluate($this->student, [
            'curriculum_board_id' => $this->boardId,
            'class_id'            => $this->altClassId,
        ], 'perm_mcqs', $this->now);
        $this->assertFalse($resOtherClass->isAllowed(), 'Class rule must not match another class in the same board');

        // Board only
        $resBoard = $this->evaluator->evaluate($this->student, [
            'curriculum_board_id' => $this->boardId,
        ], 'perm_mcqs', $this->now);
        $this->assertFalse($resBoard->isAllowed(), 'Class rule must not match at Board specificity');
    }

    public function test_mismatched_target_id_does_not_match(): void
    {
        $campaign = $this->createCampaign();

        $this->createRule([
            'campaign_id'  => $campaign->id,
            'target_scope' => 'chapter',
            'chapter_id'   => $this->chapterId,
        ]);

        $result = $this->evaluator->evaluate($this->student, [
            'chapter_id' => $this->altChapterId, // Mismatched chapter
        ], 'perm_mcqs', $this->now);

        $this->assertFalse($result->isAllowed());
    }

    public function test_unknown_or_invalid_target_scope_fails_closed(): void
    {
        // 1. Test via filterRulesAtSpecificity reflection to verify arbitrary invalid scopes fail closed
        $refMethod = new ReflectionMethod(FreemiumAccessRuleEvaluator::class, 'filterRulesAtSpecificity');
        $refMethod->setAccessible(true);

        $invalidRule = new FreemiumAccessRule([
            'target_scope' => 'arbitrary_invalid_scope',
            'subject_id'   => $this->subjectId,
            'chapter_id'   => $this->chapterId,
            'perm_mcqs'    => 'allow',
        ]);

        foreach ([
            FreemiumAccessRuleEvaluator::SPECIFICITY_QUESTION,
            FreemiumAccessRuleEvaluator::SPECIFICITY_CHAPTER,
            FreemiumAccessRuleEvaluator::SPECIFICITY_BOOK,
            FreemiumAccessRuleEvaluator::SPECIFICITY_SUBJECT,
            FreemiumAccessRuleEvaluator::SPECIFICITY_CLASS,
            FreemiumAccessRuleEvaluator::SPECIFICITY_BOARD,
            FreemiumAccessRuleEvaluator::SPECIFICITY_GLOBAL,
        ] as $specificity) {
            $filtered = $refMethod->invoke(
                $this->evaluator,
                collect([$invalidRule]),
                $specificity,
                [
                    'curriculum_board_id' => $this->boardId,
                    'class_id'            => $this->classId,
                    'subject_id'          => $this->subjectId,
                    'book_id'             => $this->bookId,
                    'chapter_id'          => $this->chapterId,
                    'global'              => true,
                ]
            );

            $this->assertTrue($filtered->isEmpty(), "Invalid target_scope must not match at specificity {$specificity}");
        }
    }

    // =========================================================================
    // 3. Resolution Integrity Tests
    // =========================================================================

    public function test_legitimate_broader_scope_rule_applies_when_no_specific_rule_matches(): void
    {
        $campaign = $this->createCampaign();

        // Valid subject rule
        $subjRule = $this->createRule([
            'campaign_id'  => $campaign->id,
            'target_scope' => 'subject',
            'subject_id'   => $this->subjectId,
        ]);

        // Context includes a chapter that has no chapter-specific rule
        $result = $this->evaluator->evaluate($this->student, [
            'subject_id' => $this->subjectId,
            'book_id'    => $this->bookId,
            'chapter_id' => $this->altChapterId,
        ], 'perm_mcqs', $this->now);

        $this->assertTrue($result->isAllowed());
        $this->assertEquals(FreemiumAccessRuleEvaluator::SPECIFICITY_SUBJECT, $result->getResolvedSpecificity());
        $this->assertEquals($subjRule->id, $result->getWinningRule()->id);
    }

    public function test_chapter_rule_takes_precedence_over_broader_subject_rule(): void
    {
        $campaign = $this->createCampaign();

        // Broader subject rule ALLOW
        $this->createRule([
            'campaign_id'  => $campaign->id,
            'target_scope' => 'subject',
            'subject_id'   => $this->subjectId,
            'perm_mcqs'    => 'allow',
        ]);

        // Specific chapter rule DENY
        $chapterRule = $this->createRule([
            'campaign_id'  => $campaign->id,
            'target_scope' => 'chapter',
            'chapter_id'   => $this->chapterId,
            'perm_mcqs'    => 'deny',
        ]);

        // Querying Chapter: chapter rule DENY takes precedence
        $resChapter = $this->evaluator->evaluate($this->student, [
            'subject_id' => $this->subjectId,
            'chapter_id' => $this->chapterId,
        ], 'perm_mcqs', $this->now);

        $this->assertFalse($resChapter->isAllowed());
        $this->assertEquals(FreemiumAccessRuleEvaluator::SPECIFICITY_CHAPTER, $resChapter->getResolvedSpecificity());
        $this->assertEquals($chapterRule->id, $resChapter->getWinningRule()->id);

        // Querying another chapter in the same subject: subject rule ALLOW applies
        $resOther = $this->evaluator->evaluate($this->student, [
            'subject_id' => $this->subjectId,
            'chapter_id' => $this->altChapterId,
        ], 'perm_mcqs', $this->now);

        $this->assertTrue($resOther->isAllowed());
        $this->assertEquals(FreemiumAccessRuleEvaluator::SPECIFICITY_SUBJECT, $resOther->getResolvedSpecificity());
    }

    public function test_deny_wins_exact_tie_at_same_specificity(): void
    {
        $campaign = $this->createCampaign();

        // Two chapter rules with same priority, one ALLOW, one DENY
        $this->createRule([
            'campaign_id'   => $campaign->id,
            'target_scope'  => 'chapter',
            'chapter_id'    => $this->chapterId,
            'perm_mcqs'     => 'allow',
            'rule_priority' => 10,
        ]);

        $denyRule = $this->createRule([
            'campaign_id'   => $campaign->id,
            'target_scope'  => 'chapter',
            'chapter_id'    => $this->chapterId,
            'perm_mcqs'     => 'deny',
            'rule_priority' => 10,
        ]);

        $result = $this->evaluator->evaluate($this->student, [
            'chapter_id' => $this->chapterId,
        ], 'perm_mcqs', $this->now);

        $this->assertFalse($result->isAllowed());
        $this->assertEquals($denyRule->id, $result->getWinningRule()->id);
    }

    public function test_inherit_bubbles_to_next_valid_broader_rule(): void
    {
        $campaign = $this->createCampaign();

        // Subject rule: ALLOW
        $subjRule = $this->createRule([
            'campaign_id'   => $campaign->id,
            'target_scope'  => 'subject',
            'subject_id'    => $this->subjectId,
            'perm_mcqs'     => 'allow',
            'rule_priority' => 10,
        ]);

        // Chapter rule: INHERIT
        $this->createRule([
            'campaign_id'   => $campaign->id,
            'target_scope'  => 'chapter',
            'chapter_id'    => $this->chapterId,
            'perm_mcqs'     => 'inherit',
            'rule_priority' => 5,
        ]);

        $result = $this->evaluator->evaluate($this->student, [
            'subject_id' => $this->subjectId,
            'chapter_id' => $this->chapterId,
        ], 'perm_mcqs', $this->now);

        $this->assertTrue($result->isAllowed());
        $this->assertEquals(FreemiumAccessRuleEvaluator::SPECIFICITY_SUBJECT, $result->getResolvedSpecificity());
        $this->assertEquals($subjRule->id, $result->getWinningRule()->id);
    }

    public function test_default_deny_when_no_rules_match(): void
    {
        $campaign = $this->createCampaign();

        // Rule for a different subject (altSubjectId = 2)
        $this->createRule([
            'campaign_id'   => $campaign->id,
            'target_scope'  => 'subject',
            'subject_id'    => $this->altSubjectId,
            'perm_mcqs'     => 'allow',
            'rule_priority' => 10,
        ]);

        // Student queries subjectId = 5 (Biology)
        $result = $this->evaluator->evaluate($this->student, [
            'subject_id' => $this->subjectId,
        ], 'perm_mcqs', $this->now);

        $this->assertFalse($result->isAllowed());
        $this->assertEquals(FreemiumEvaluationResult::SOURCE_DEFAULT_DENY, $result->getSource());
    }

    // =========================================================================
    // 4. Exact Reproduction of Campaign #2's Chapter-Level Rule
    // =========================================================================

    public function test_campaign_2_chapter_rule_regression_prevents_subject_and_class_leakage(): void
    {
        $campaign = $this->createCampaign([
            'name'     => 'Free Federal Biology Class 9th',
            'priority' => 100,
        ]);

        // Exact reproduction of Rule #1 from Campaign #2:
        // target_scope = chapter, board = 2, class = 1, subject = 5, book = 28, chapter = 263
        $c2Rule = $this->createRule([
            'campaign_id'         => $campaign->id,
            'target_scope'        => 'chapter',
            'curriculum_board_id' => 2,
            'class_id'            => 1,
            'subject_id'          => 5,
            'book_id'             => 28,
            'chapter_id'          => 263,
            'perm_mcqs'           => 'allow',
            'perm_past_papers'    => 'allow',
            'rule_priority'       => 10,
            'academic_target_snapshot' => [
                'target_scope' => 'chapter',
                'board_id'     => 2,
                'class_id'     => 1,
                'subject_id'   => 5,
                'book_id'      => 28,
                'chapter_id'   => 263,
            ],
        ]);

        // 1. POSITIVE MATCH: Chapter 263 in Federal Biology 9th is allowed
        $resC2Target = $this->evaluator->evaluate($this->student, [
            'curriculum_board_id' => 2,
            'class_id'            => 1,
            'subject_id'          => 5,
            'book_id'             => 28,
            'chapter_id'          => 263,
        ], 'perm_mcqs', $this->now);

        $this->assertTrue($resC2Target->isAllowed());
        $this->assertEquals(FreemiumAccessRuleEvaluator::SPECIFICITY_CHAPTER, $resC2Target->getResolvedSpecificity());
        $this->assertEquals($c2Rule->id, $resC2Target->getWinningRule()->id);

        // 2. LEAKAGE PREVENTION: Other chapters in the same book (e.g. Chapter 264) must NOT be unlocked
        $resOtherChapter = $this->evaluator->evaluate($this->student, [
            'curriculum_board_id' => 2,
            'class_id'            => 1,
            'subject_id'          => 5,
            'book_id'             => 28,
            'chapter_id'          => 264,
        ], 'perm_mcqs', $this->now);

        $this->assertFalse($resOtherChapter->isAllowed(), 'Campaign #2 rule must NOT unlock other chapters in Book 28');

        // 3. LEAKAGE PREVENTION: Book-level query for Book 28 without chapter must NOT be unlocked
        $resBookLevel = $this->evaluator->evaluate($this->student, [
            'curriculum_board_id' => 2,
            'class_id'            => 1,
            'subject_id'          => 5,
            'book_id'             => 28,
        ], 'perm_mcqs', $this->now);

        $this->assertFalse($resBookLevel->isAllowed(), 'Campaign #2 chapter rule must NOT unlock the full Book 28');

        // 4. LEAKAGE PREVENTION: Other Biology books (e.g. Book 30 Federal Biology) must NOT be unlocked
        $resOtherBioBook = $this->evaluator->evaluate($this->student, [
            'curriculum_board_id' => 2,
            'class_id'            => 1,
            'subject_id'          => 5,
            'book_id'             => 30,
        ], 'perm_mcqs', $this->now);

        $this->assertFalse($resOtherBioBook->isAllowed(), 'Campaign #2 rule must NOT unlock other Biology books');

        // 5. LEAKAGE PREVENTION: Other 9th-grade subjects (e.g. Federal Mathematics, Book 25, Subject 2) must NOT be unlocked
        $resFedMath = $this->evaluator->evaluate($this->student, [
            'curriculum_board_id' => 2,
            'class_id'            => 1,
            'subject_id'          => 2,
            'book_id'             => 25,
        ], 'perm_mcqs', $this->now);

        $this->assertFalse($resFedMath->isAllowed(), 'Campaign #2 rule must NOT unlock 9th-grade Mathematics');

        // 6. LEAKAGE PREVENTION: Punjab Board 9th-grade subjects (e.g. Urdu, Book 16, Board 1, Class 1, Subject 3) must NOT be unlocked
        $resPunjabUrdu = $this->evaluator->evaluate($this->student, [
            'curriculum_board_id' => 1,
            'class_id'            => 1,
            'subject_id'          => 3,
            'book_id'             => 16,
        ], 'perm_mcqs', $this->now);

        $this->assertFalse($resPunjabUrdu->isAllowed(), 'Campaign #2 rule must NOT unlock Punjab Board Urdu');
    }
}
