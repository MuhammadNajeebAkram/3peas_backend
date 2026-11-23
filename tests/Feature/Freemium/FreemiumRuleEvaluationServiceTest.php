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
use Tests\TestCase;

class FreemiumRuleEvaluationServiceTest extends TestCase
{
    private FreemiumAccessRuleEvaluator $evaluator;
    private WebUser $student;
    private User $admin;
    private Carbon $now;

    private int $subjectId;
    private int $bookId;
    private int $chapterId;
    private int $topicId;
    private int $questionId;
    private int $modelPaperId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new FreemiumAccessRuleEvaluator();
        $this->now = Carbon::parse('2026-11-18 12:00:00', 'UTC');

        $adminId = DB::table('users')->value('id');
        $this->admin = $adminId ? User::find($adminId) : User::create([
            'name'     => 'Rule Test Admin',
            'email'    => 'admin_rule_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
        ]);

        $studentId = DB::table('web_users')->insertGetId([
            'name'       => 'Rule Test Student',
            'email'      => 'student_rule_' . uniqid() . '@example.com',
            'password'   => bcrypt('secret'),
            'role'       => 'student',
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->student = WebUser::find($studentId);

        // Fetch real fixture IDs to satisfy foreign keys
        $this->subjectId  = (int) DB::table('subject_tbl')->value('id');
        $this->bookId     = (int) DB::table('book_tbl')->value('id');
        $this->chapterId  = (int) DB::table('book_unit_tbl')->value('id');
        $this->topicId    = (int) DB::table('book_unit_topic_tbl')->value('id');
        $this->questionId = (int) DB::table('exam_question_tbl')->value('id');

        $mpId = DB::table('model_papers')->value('id');
        if (!$mpId) {
            $mpId = DB::table('model_papers')->insertGetId([
                'title'               => 'Test Model Paper',
                'total_marks'         => 100,
                'time_allowed'        => 60,
                'instructions'        => 'Test instructions',
                'class_id'            => (int) DB::table('class_tbl')->value('id'),
                'subject_id'          => $this->subjectId,
                'activate'            => 1,
                'urdu_lang'           => 0,
                'curriculum_board_id' => (int) DB::table('curriculum_board_tbl')->value('id'),
                'total_questions'     => 10,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        }
        $this->modelPaperId = (int) $mpId;
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
            'name'         => 'Test Campaign ' . uniqid(),
            'status'       => 'active',
            'is_stackable' => true,
            'priority'     => 50,
            'start_at'     => $this->now->copy()->subDays(5),
            'end_at'       => $this->now->copy()->addDays(5),
            'created_by'   => $this->admin->id,
        ], $overrides));

        if (isset($overrides['start_at']) && $overrides['start_at'] instanceof Carbon) {
            DB::table('freemium_campaigns')->where('id', $campaign->id)->update([
                'start_at' => DB::raw("FROM_UNIXTIME({$overrides['start_at']->getTimestamp()})"),
            ]);
        }
        if (isset($overrides['end_at']) && $overrides['end_at'] instanceof Carbon) {
            DB::table('freemium_campaigns')->where('id', $campaign->id)->update([
                'end_at' => DB::raw("FROM_UNIXTIME({$overrides['end_at']->getTimestamp()})"),
            ]);
        }

        return $campaign->fresh();
    }

    /**
     * Test specificity precedence: Higher specificity wins over lower specificity.
     * Question (100000) > Subject (10) > Global (0).
     */
    public function test_specificity_precedence_higher_wins(): void
    {
        $campaign = $this->createCampaign();

        // Global rule: allow mcqs
        FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'target_scope'             => 'global',
            'target_status'            => 'active',
            'perm_mcqs'                => 'allow',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['scope' => 'global'],
        ]);

        // Subject rule (spec 10): deny mcqs
        FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'target_scope'             => 'subject',
            'target_status'            => 'active',
            'subject_id'               => $this->subjectId,
            'perm_mcqs'                => 'deny',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['subject_id' => $this->subjectId],
        ]);

        // Question rule (spec 100000): allow mcqs
        $questionRule = FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'target_scope'             => 'question',
            'target_status'            => 'active',
            'question_id'              => $this->questionId,
            'perm_mcqs'                => 'allow',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['question_id' => $this->questionId],
        ]);

        $result = $this->evaluator->evaluate($this->student, [
            'question_id' => $this->questionId,
            'subject_id'  => $this->subjectId,
        ], 'perm_mcqs', $this->now);

        $this->assertTrue($result->isAllowed());
        $this->assertEquals(FreemiumAccessRuleEvaluator::SPECIFICITY_QUESTION, $result->getResolvedSpecificity());
        $this->assertEquals($questionRule->id, $result->getWinningRule()->id);
        $this->assertEquals(FreemiumEvaluationResult::SOURCE_EXPLICIT_RULE, $result->getSource());
    }

    /**
     * Test paper specificity (50000) for model paper and past paper.
     */
    public function test_paper_specificity_level(): void
    {
        $campaign = $this->createCampaign();

        $rule = FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'target_scope'             => 'model_paper',
            'target_status'            => 'active',
            'model_paper_id'           => $this->modelPaperId,
            'perm_model_papers'        => 'allow',
            'rule_priority'            => 5,
            'academic_target_snapshot' => ['model_paper_id' => $this->modelPaperId],
        ]);

        $result = $this->evaluator->evaluate($this->student, [
            'model_paper_id' => $this->modelPaperId,
        ], 'perm_model_papers', $this->now);

        $this->assertTrue($result->isAllowed());
        $this->assertEquals(FreemiumAccessRuleEvaluator::SPECIFICITY_PAPER, $result->getResolvedSpecificity());
        $this->assertEquals($rule->id, $result->getWinningRule()->id);
    }

    /**
     * Test equal specificity campaign priority: Lower campaign priority integer wins.
     */
    public function test_equal_specificity_campaign_priority_precedence(): void
    {
        $campaignHighPriority = $this->createCampaign(['priority' => 10]); // Higher precedence
        $campaignLowPriority  = $this->createCampaign(['priority' => 50]); // Lower precedence

        // Campaign High Priority denies
        FreemiumAccessRule::create([
            'campaign_id'              => $campaignHighPriority->id,
            'target_scope'             => 'subject',
            'target_status'            => 'active',
            'subject_id'               => $this->subjectId,
            'perm_mcqs'                => 'deny',
            'rule_priority'            => 50,
            'academic_target_snapshot' => ['subject_id' => $this->subjectId],
        ]);

        // Campaign Low Priority allows
        FreemiumAccessRule::create([
            'campaign_id'              => $campaignLowPriority->id,
            'target_scope'             => 'subject',
            'target_status'            => 'active',
            'subject_id'               => $this->subjectId,
            'perm_mcqs'                => 'allow',
            'rule_priority'            => 1, // Rule priority lower, but campaign priority higher
            'academic_target_snapshot' => ['subject_id' => $this->subjectId],
        ]);

        $result = $this->evaluator->evaluate($this->student, [
            'subject_id' => $this->subjectId,
        ], 'perm_mcqs', $this->now);

        // Campaign priority 10 beats campaign priority 50
        $this->assertTrue($result->isDenied());
        $this->assertEquals(FreemiumEvaluationResult::DECISION_DENY, $result->getDecision());
    }

    /**
     * Test equal campaign priority rule priority: Lower rule priority integer wins.
     */
    public function test_equal_campaign_priority_rule_priority_precedence(): void
    {
        $campaign = $this->createCampaign(['priority' => 20]);

        // Rule A: priority 5 allows
        $ruleA = FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'target_scope'             => 'chapter',
            'target_status'            => 'active',
            'chapter_id'               => $this->chapterId,
            'perm_mcqs'                => 'allow',
            'rule_priority'            => 5,
            'academic_target_snapshot' => ['chapter_id' => $this->chapterId],
        ]);

        // Rule B: priority 15 denies
        FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'target_scope'             => 'chapter',
            'target_status'            => 'active',
            'chapter_id'               => $this->chapterId,
            'perm_mcqs'                => 'deny',
            'rule_priority'            => 15,
            'academic_target_snapshot' => ['chapter_id' => $this->chapterId],
        ]);

        $result = $this->evaluator->evaluate($this->student, [
            'chapter_id' => $this->chapterId,
        ], 'perm_mcqs', $this->now);

        $this->assertTrue($result->isAllowed());
        $this->assertEquals($ruleA->id, $result->getWinningRule()->id);
    }

    /**
     * Test DENY tie-break behavior: When campaign and rule priority tie, DENY wins.
     */
    public function test_deny_tie_break_behavior(): void
    {
        $campaign = $this->createCampaign(['priority' => 30]);

        // Tied rule 1: allows with priority 10
        FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'target_scope'             => 'book',
            'target_status'            => 'active',
            'book_id'                  => $this->bookId,
            'perm_mcqs'                => 'allow',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['book_id' => $this->bookId],
        ]);

        // Tied rule 2: denies with priority 10
        $ruleDeny = FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'target_scope'             => 'book',
            'target_status'            => 'active',
            'book_id'                  => $this->bookId,
            'perm_mcqs'                => 'deny',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['book_id' => $this->bookId],
        ]);

        $result = $this->evaluator->evaluate($this->student, [
            'book_id' => $this->bookId,
        ], 'perm_mcqs', $this->now);

        // DENY wins tie
        $this->assertTrue($result->isDenied());
        $this->assertEquals(FreemiumEvaluationResult::DECISION_DENY, $result->getDecision());
        $this->assertEquals($ruleDeny->id, $result->getWinningRule()->id);
    }

    /**
     * Test INHERIT behavior: moves evaluation down to next applicable lower specificity level.
     */
    public function test_inherit_moves_evaluation_down_to_next_level(): void
    {
        $campaign = $this->createCampaign();

        // Topic (10000): INHERIT
        FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'target_scope'             => 'topic',
            'target_status'            => 'active',
            'topic_id'                 => $this->topicId,
            'perm_mcqs'                => 'inherit',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['topic_id' => $this->topicId],
        ]);

        // Chapter (1000): ALLOW
        $chapterRule = FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'target_scope'             => 'chapter',
            'target_status'            => 'active',
            'chapter_id'               => $this->chapterId,
            'perm_mcqs'                => 'allow',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['chapter_id' => $this->chapterId],
        ]);

        $result = $this->evaluator->evaluate($this->student, [
            'topic_id'   => $this->topicId,
            'chapter_id' => $this->chapterId,
        ], 'perm_mcqs', $this->now);

        $this->assertTrue($result->isAllowed());
        $this->assertEquals(FreemiumAccessRuleEvaluator::SPECIFICITY_CHAPTER, $result->getResolvedSpecificity());
        $this->assertEquals($chapterRule->id, $result->getWinningRule()->id);
    }

    /**
     * Test campaign stack selection: non-stackable campaign isolates evaluation.
     */
    public function test_non_stackable_campaign_isolates_evaluation(): void
    {
        // Non-stackable campaign with priority 10
        $isolatedCampaign = $this->createCampaign([
            'is_stackable' => false,
            'priority'     => 10,
        ]);

        // Stackable campaign with priority 5
        $otherCampaign = $this->createCampaign([
            'is_stackable' => true,
            'priority'     => 5,
        ]);

        FreemiumAccessRule::create([
            'campaign_id'              => $isolatedCampaign->id,
            'target_scope'             => 'global',
            'target_status'            => 'active',
            'perm_mcqs'                => 'deny',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['scope' => 'global'],
        ]);

        FreemiumAccessRule::create([
            'campaign_id'              => $otherCampaign->id,
            'target_scope'             => 'global',
            'target_status'            => 'active',
            'perm_mcqs'                => 'allow',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['scope' => 'global'],
        ]);

        $result = $this->evaluator->evaluate($this->student, [], 'perm_mcqs', $this->now);

        // Non-stackable campaign wins and isolates
        $this->assertTrue($result->isDenied());
        $this->assertEquals($isolatedCampaign->id, $result->getPrimaryCampaign()->id);
        $this->assertCount(1, $result->getParticipatingCampaigns());
    }

    /**
     * Test campaign time boundaries: half-open interval [start_at, end_at).
     */
    public function test_campaign_temporal_half_open_interval(): void
    {
        $start = Carbon::parse('2026-11-01 00:00:00', 'UTC');
        $end   = Carbon::parse('2026-11-10 00:00:00', 'UTC');

        $campaign = $this->createCampaign([
            'start_at' => $start,
            'end_at'   => $end,
        ]);

        FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'target_scope'             => 'global',
            'target_status'            => 'active',
            'perm_mcqs'                => 'allow',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['scope' => 'global'],
        ]);

        // Before start_at: Denied
        $beforeResult = $this->evaluator->evaluate($this->student, [], 'perm_mcqs', $start->copy()->subMinute());
        $this->assertTrue($beforeResult->isDenied());

        // At exact start_at (inclusive): Allowed
        $atStartResult = $this->evaluator->evaluate($this->student, [], 'perm_mcqs', $start);
        $this->assertTrue($atStartResult->isAllowed());

        // Exactly at end_at (exclusive): Denied
        $atEndResult = $this->evaluator->evaluate($this->student, [], 'perm_mcqs', $end);
        $this->assertTrue($atEndResult->isDenied());

        // After end_at: Denied
        $afterResult = $this->evaluator->evaluate($this->student, [], 'perm_mcqs', $end->copy()->addMinute());
        $this->assertTrue($afterResult->isDenied());
    }

    /**
     * Test orphaned rules are ignored (target_status = 'orphaned').
     */
    public function test_orphaned_rules_are_ignored(): void
    {
        $campaign = $this->createCampaign();

        FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'target_scope'             => 'subject',
            'target_status'            => 'orphaned', // Orphaned!
            'subject_id'               => $this->subjectId,
            'perm_mcqs'                => 'allow',
            'rule_priority'            => 1,
            'academic_target_snapshot' => ['subject_id' => $this->subjectId],
        ]);

        $result = $this->evaluator->evaluate($this->student, [
            'subject_id' => $this->subjectId,
        ], 'perm_mcqs', $this->now);

        // Orphaned rule was ignored, results in default deny
        $this->assertTrue($result->isDenied());
        $this->assertEquals(FreemiumEvaluationResult::SOURCE_DEFAULT_DENY, $result->getSource());
    }

    /**
     * Test legacy free fallback on program_subjects and offered_classes.
     */
    public function test_legacy_fallback_ordering_and_default_deny(): void
    {
        // Pick an unused or clean subject
        $cleanSubjectId = $this->subjectId;

        // 1. With no rules and no legacy flags -> Default Deny
        $defaultResult = $this->evaluator->evaluate($this->student, [
            'subject_id' => $cleanSubjectId,
        ], 'perm_mcqs', $this->now);

        $this->assertTrue($defaultResult->isDenied());
        $this->assertEquals(FreemiumEvaluationResult::SOURCE_DEFAULT_DENY, $defaultResult->getSource());

        // 2. Insert legacy program_subject with is_free = 1
        $progId = (int) DB::table('offered_programs')->value('id');
        $programSubId = DB::table('program_subjects')->insertGetId([
            'offered_program_id' => $progId,
            'subject_id'         => $cleanSubjectId,
            'display_order'      => 99,
            'is_demo_available'  => 0,
            'is_free'            => 1,
            'is_active'          => 1,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $legacyResult = $this->evaluator->evaluate($this->student, [
            'subject_id' => $cleanSubjectId,
        ], 'perm_mcqs', $this->now);

        $this->assertTrue($legacyResult->isAllowed());
        $this->assertEquals(FreemiumEvaluationResult::SOURCE_LEGACY_FALLBACK, $legacyResult->getSource());

        // 3. Explicit Freemium DENY overrides legacy free flag!
        $campaign = $this->createCampaign();
        FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'target_scope'             => 'subject',
            'target_status'            => 'active',
            'subject_id'               => $cleanSubjectId,
            'perm_mcqs'                => 'deny',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['subject_id' => $cleanSubjectId],
        ]);

        $overrideResult = $this->evaluator->evaluate($this->student, [
            'subject_id' => $cleanSubjectId,
        ], 'perm_mcqs', $this->now);

        $this->assertTrue($overrideResult->isDenied());
        $this->assertEquals(FreemiumEvaluationResult::SOURCE_EXPLICIT_RULE, $overrideResult->getSource());

        // Clean up legacy row
        DB::table('program_subjects')->where('id', $programSubId)->delete();
    }
}
