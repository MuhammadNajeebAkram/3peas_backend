<?php

namespace Tests\Feature\Freemium;

use App\Http\Services\Freemium\DTOs\UnifiedAccessDecision;
use App\Http\Services\Freemium\FreemiumAccessRuleEvaluator;
use App\Http\Services\Freemium\UnifiedEntitlementResolver;
use App\Http\Services\StudentClassAccessService;
use App\Models\Book;
use App\Models\BookUnit;
use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumStudentGrant;
use App\Models\OfferedClass;
use App\Models\OfferedProgram;
use App\Models\ProgramSubject;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Freemium Unit ID Normalization Test Suite.
 *
 * Verifies Phase 2 corrective normalization:
 *  - unit_ids [263] normalizes to chapter_id 263 and enriches book_id 28, subject_id 5, class_id 1, board_id 2
 *  - Campaign #2 Rule #1 (target_scope = chapter, chapter_id = 263) matches normalized Unit 263 context
 *  - Rule #1 does NOT match other chapters (e.g. Unit 264)
 *  - Multiple unit IDs handled safely without picking an arbitrary first ID (all-or-nothing fail-closed)
 *  - Non-existent unit IDs fail closed immediately without falling back to broader permissions
 *  - Cross-book/cross-subject unit mismatches fail closed immediately
 *  - Conflicting explicit chapter_id and unit_ids fails closed
 *  - Existing explicit chapter_id and subject-level requests continue working without regression
 */
class FreemiumUnitIdNormalizationTest extends TestCase
{
    private UnifiedEntitlementResolver $resolver;
    private StudentClassAccessService $paidAccessService;
    private FreemiumAccessRuleEvaluator $ruleEvaluator;

    private WebUser $student;
    private User $admin;
    private Carbon $now;

    private int $boardId;
    private int $classId;
    private int $subjectIdBio;
    private int $subjectIdEng;
    private int $bookIdBio;
    private int $bookIdEng;
    private int $unitIdBio1; // 263
    private int $unitIdBio2; // 264
    private int $unitIdEng1; // 10

    private FreemiumCampaign $campaign2;
    private FreemiumAccessRule $rule1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paidAccessService = new StudentClassAccessService();
        $this->ruleEvaluator     = new FreemiumAccessRuleEvaluator();
        $this->resolver          = new UnifiedEntitlementResolver($this->paidAccessService, $this->ruleEvaluator);

        $this->now = Carbon::parse('2026-11-21 12:00:00', 'UTC');

        // Admin fixture
        $adminId = DB::table('users')->value('id');
        $this->admin = $adminId ? User::find($adminId) : User::create([
            'name'     => 'Unit Normalization Admin',
            'email'    => 'admin_unit_norm_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
        ]);

        // Student fixture
        $studentId = DB::table('web_users')->insertGetId([
            'name'       => 'Unit Normalization Student',
            'email'      => 'student_unit_norm_' . uniqid() . '@example.com',
            'password'   => bcrypt('secret'),
            'role'       => 'student',
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->student = WebUser::find($studentId);

        // Seed / Ensure Academic Hierarchy Fixtures in Test DB
        DB::table('curriculum_board_tbl')->updateOrInsert(['id' => 1], ['name' => 'Punjab Board', 'activate' => 1]);
        DB::table('curriculum_board_tbl')->updateOrInsert(['id' => 2], ['name' => 'Federal Board', 'activate' => 1]);
        DB::table('class_tbl')->updateOrInsert(['id' => 1], ['class_name' => 'Class 9th', 'activate' => 1]);
        DB::table('class_tbl')->updateOrInsert(['id' => 2], ['class_name' => 'Class 10th', 'activate' => 1]);
        DB::table('subject_tbl')->updateOrInsert(['id' => 1], ['subject_name' => 'English', 'icon_name' => 'english.png', 'activate' => 1]);
        DB::table('subject_tbl')->updateOrInsert(['id' => 5], ['subject_name' => 'Biology', 'icon_name' => 'bio.png', 'activate' => 1]);

        // Books: Book 28 (Federal Biology 9th), Book 1 (Federal English 9th)
        DB::table('book_tbl')->updateOrInsert(['id' => 28], [
            'book_name'           => 'Federal Biology 9th',
            'class_id'            => 1,
            'subject_id'          => 5,
            'curriculum_board_id' => 2,
            'activate'            => 1,
        ]);
        DB::table('book_tbl')->updateOrInsert(['id' => 1], [
            'book_name'           => 'Federal English 9th',
            'class_id'            => 1,
            'subject_id'          => 1,
            'curriculum_board_id' => 2,
            'activate'            => 1,
        ]);

        // Units: Unit 263 (Biology Ch 1), Unit 264 (Biology Ch 2), Unit 10 (English Ch 1)
        DB::table('book_unit_tbl')->updateOrInsert(['id' => 263], [
            'unit_name' => 'THE SCIENCE OF BIOLOGY',
            'unit_no'   => 1,
            'book_id'   => 28,
            'activate'  => 1,
        ]);
        DB::table('book_unit_tbl')->updateOrInsert(['id' => 264], [
            'unit_name' => 'CELL BIOLOGY',
            'unit_no'   => 2,
            'book_id'   => 28,
            'activate'  => 1,
        ]);
        DB::table('book_unit_tbl')->updateOrInsert(['id' => 10], [
            'unit_name' => 'ENGLISH BASICS',
            'unit_no'   => 1,
            'book_id'   => 1,
            'activate'  => 1,
        ]);

        $this->boardId       = 2;
        $this->classId       = 1;
        $this->subjectIdBio  = 5;
        $this->subjectIdEng  = 1;
        $this->bookIdBio     = 28;
        $this->bookIdEng     = 1;
        $this->unitIdBio1    = 263;
        $this->unitIdBio2    = 264;
        $this->unitIdEng1    = 10;

        // Clean up prior test campaigns & rules
        DB::table('freemium_access_rules')->delete();
        DB::table('freemium_student_grants')->delete();
        DB::table('freemium_campaigns')->delete();

        // Create Campaign #2 fixture: Free Federal Biology Class 9th
        $this->campaign2 = FreemiumCampaign::create([
            'id'                   => 2,
            'created_by'           => $this->admin->id,
            'name'                 => 'Free Federal Biology Class 9th',
            'status'               => 'active',
            'priority'             => 10,
            'start_at'             => $this->now->copy()->subDays(5),
            'end_at'               => $this->now->copy()->addMonths(1),
            'is_stackable'         => true,
            'eligibility_criteria' => ['require_grant' => false],
        ]);

        // Rule #1: target_scope = chapter, chapter_id = 263
        $this->rule1 = FreemiumAccessRule::create([
            'id'                       => 1,
            'campaign_id'              => $this->campaign2->id,
            'curriculum_board_id'      => $this->boardId,
            'class_id'                 => $this->classId,
            'subject_id'               => $this->subjectIdBio,
            'book_id'                  => $this->bookIdBio,
            'chapter_id'               => $this->unitIdBio1,
            'target_scope'             => 'chapter',
            'target_status'            => 'active',
            'rule_priority'            => 10,
            'perm_mcqs'                => 'allow',
            'perm_view'                => 'allow',
            'academic_target_snapshot' => [
                'curriculum_board_id' => $this->boardId,
                'class_id'            => $this->classId,
                'subject_id'          => $this->subjectIdBio,
                'book_id'             => $this->bookIdBio,
                'chapter_id'          => $this->unitIdBio1,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('freemium_access_rules')->delete();
        DB::table('freemium_student_grants')->delete();
        DB::table('freemium_quota_consumptions')->delete();
        DB::table('freemium_campaigns')->delete();
        DB::table('user_subscriptions')->where('user_id', $this->student->id)->delete();
        DB::table('web_users')->where('id', $this->student->id)->delete();

        parent::tearDown();
    }

    /**
     * Requirement 1: unit_ids: [263] normalizes to chapter_id: 263 and enriches full academic hierarchy.
     */
    public function test_single_unit_263_normalizes_to_chapter_and_enriches_full_hierarchy(): void
    {
        $context = [
            'subject_id' => $this->subjectIdBio,
            'unit_ids'   => [$this->unitIdBio1],
        ];

        $normalized = $this->resolver->normalizeTargetContext($context);

        $this->assertSame($this->unitIdBio1, $normalized['chapter_id']);
        $this->assertSame($this->bookIdBio, $normalized['book_id']);
        $this->assertSame($this->subjectIdBio, $normalized['subject_id']);
        $this->assertSame($this->classId, $normalized['class_id']);
        $this->assertSame($this->boardId, $normalized['curriculum_board_id']);
        $this->assertSame([$this->unitIdBio1], $normalized['unit_ids']);
        $this->assertArrayNotHasKey('_fail_closed', $normalized);
    }

    /**
     * Requirement 2: Campaign #2's chapter-level rule matches the normalized Unit 263 context.
     */
    public function test_campaign_2_chapter_rule_matches_normalized_unit_263_context(): void
    {
        $context = [
            'subject_id' => $this->subjectIdBio,
            'unit_ids'   => [$this->unitIdBio1],
        ];

        $decision = $this->resolver->resolve($this->student, $context, 'perm_mcqs', 'practice', $this->now);

        $this->assertTrue($decision->isGranted());
        $this->assertFalse($decision->isDenied());
        $this->assertSame(UnifiedAccessDecision::PROVIDER_FREEMIUM, $decision->getProvider());
        $this->assertSame(UnifiedAccessDecision::ORIGIN_FREEMIUM_RULE, $decision->getOrigin());
        $this->assertSame($this->campaign2->id, $decision->getCampaignId());
        $this->assertSame($this->rule1->id, $decision->getWinningRuleId());
        $this->assertTrue($decision->requiresQuotaEvaluation());
    }

    /**
     * Requirement 3: Campaign #2 chapter rule does NOT match another chapter (Unit 264).
     */
    public function test_campaign_2_chapter_rule_does_not_match_another_chapter(): void
    {
        $context = [
            'subject_id' => $this->subjectIdBio,
            'unit_ids'   => [$this->unitIdBio2],
        ];

        $decision = $this->resolver->resolve($this->student, $context, 'perm_mcqs', 'practice', $this->now);

        $this->assertFalse($decision->isGranted());
        $this->assertTrue($decision->isDenied());
        $this->assertSame(UnifiedAccessDecision::PROVIDER_NONE, $decision->getProvider());
        $this->assertSame(UnifiedAccessDecision::ORIGIN_DEFAULT_DENY, $decision->getOrigin());
    }

    /**
     * Requirement 4: Multiple unit IDs are handled without selecting an arbitrary first ID.
     * When Unit 263 is free but Unit 264 is unentitled, the request fails closed.
     */
    public function test_multiple_unit_ids_partial_freemium_fails_closed_without_picking_first(): void
    {
        // 263 is free, 264 is unentitled. Order 1: [263, 264]
        $context1 = [
            'subject_id' => $this->subjectIdBio,
            'unit_ids'   => [$this->unitIdBio1, $this->unitIdBio2],
        ];

        $decision1 = $this->resolver->resolve($this->student, $context1, 'perm_mcqs', 'practice', $this->now);

        $this->assertFalse($decision1->isGranted());
        $this->assertTrue($decision1->isDenied());
        $this->assertSame(UnifiedAccessDecision::PROVIDER_NONE, $decision1->getProvider());
        $this->assertStringContainsString((string) $this->unitIdBio2, $decision1->getDenialReason());

        // Order 2: [264, 263] — Same fail-closed outcome
        $context2 = [
            'subject_id' => $this->subjectIdBio,
            'unit_ids'   => [$this->unitIdBio2, $this->unitIdBio1],
        ];

        $decision2 = $this->resolver->resolve($this->student, $context2, 'perm_mcqs', 'practice', $this->now);

        $this->assertFalse($decision2->isGranted());
        $this->assertTrue($decision2->isDenied());
    }

    /**
     * Requirement 4b: Multiple unit IDs where all units are covered by paid subscription.
     */
    public function test_multiple_unit_ids_all_covered_by_paid_subscription_grants_access(): void
    {
        // Setup OfferedClass, OfferedProgram, ProgramSubject for Biology
        $offeredClass = OfferedClass::create([
            'class_id'            => $this->classId,
            'curriculum_board_id' => $this->boardId,
            'price'               => 1000,
            'is_free'             => 0,
            'is_active'           => 1,
        ]);
        $program = OfferedProgram::create([
            'offered_class_id' => $offeredClass->id,
            'title'            => 'Paid Biology 9th Program',
            'slug'             => 'paid-bio-9th-' . uniqid(),
            'display_order'    => 1,
            'is_active'        => 1,
        ]);
        ProgramSubject::create([
            'offered_program_id' => $program->id,
            'subject_id'         => $this->subjectIdBio,
            'display_order'      => 1,
            'is_free'            => 0,
            'is_active'          => 1,
        ]);

        // Student has active paid subscription
        UserSubscription::create([
            'user_id'            => $this->student->id,
            'offered_program_id' => $program->id,
            'status'             => 'active',
            'started_at'         => $this->now->copy()->subMonth(),
            'expires_at'         => $this->now->copy()->addMonth(),
        ]);

        // Request multiple units in Biology
        $context = [
            'subject_id' => $this->subjectIdBio,
            'unit_ids'   => [$this->unitIdBio1, $this->unitIdBio2],
        ];

        $decision = $this->resolver->resolve($this->student, $context, 'perm_mcqs', 'practice', $this->now);

        $this->assertTrue($decision->isGranted());
        $this->assertSame(UnifiedAccessDecision::PROVIDER_PAID, $decision->getProvider());
        $this->assertSame($program->id, $decision->getMetadata()['program_id']);

        // Clean up
        UserSubscription::where('user_id', $this->student->id)->delete();
        ProgramSubject::where('offered_program_id', $program->id)->delete();
        OfferedProgram::where('id', $program->id)->delete();
        OfferedClass::where('id', $offeredClass->id)->delete();
    }

    /**
     * Requirement 4c: Multiple unit IDs where all units are covered by Freemium rules.
     */
    public function test_multiple_unit_ids_all_covered_by_freemium_rules_grants_access(): void
    {
        // Add Rule #2 covering Unit 264
        $rule2 = FreemiumAccessRule::create([
            'id'                       => 2,
            'campaign_id'              => $this->campaign2->id,
            'curriculum_board_id'      => $this->boardId,
            'class_id'                 => $this->classId,
            'subject_id'               => $this->subjectIdBio,
            'book_id'                  => $this->bookIdBio,
            'chapter_id'               => $this->unitIdBio2,
            'target_scope'             => 'chapter',
            'target_status'            => 'active',
            'rule_priority'            => 10,
            'perm_mcqs'                => 'allow',
            'perm_view'                => 'allow',
            'academic_target_snapshot' => [
                'curriculum_board_id' => $this->boardId,
                'class_id'            => $this->classId,
                'subject_id'          => $this->subjectIdBio,
                'book_id'             => $this->bookIdBio,
                'chapter_id'          => $this->unitIdBio2,
            ],
        ]);

        $context = [
            'subject_id' => $this->subjectIdBio,
            'unit_ids'   => [$this->unitIdBio1, $this->unitIdBio2],
        ];

        $decision = $this->resolver->resolve($this->student, $context, 'perm_mcqs', 'practice', $this->now);

        $this->assertTrue($decision->isGranted());
        $this->assertSame(UnifiedAccessDecision::PROVIDER_FREEMIUM, $decision->getProvider());
        $this->assertSame($this->campaign2->id, $decision->getCampaignId());

        $rule2->delete();
    }

    /**
     * Requirement 5: Invalid unit IDs fail closed immediately.
     * Does NOT silently fall back to broader subject permissions.
     */
    public function test_invalid_unit_id_fails_closed_without_subject_fallback(): void
    {
        // Case A: Single invalid unit ID
        $context = [
            'subject_id' => $this->subjectIdBio,
            'unit_ids'   => [999999],
        ];

        $normalized = $this->resolver->normalizeTargetContext($context);
        $this->assertTrue($normalized['_fail_closed'] ?? false);

        $decision = $this->resolver->resolve($this->student, $context, 'perm_mcqs', 'practice', $this->now);
        $this->assertFalse($decision->isGranted());
        $this->assertTrue($decision->isDenied());
        $this->assertSame(UnifiedAccessDecision::ORIGIN_DEFAULT_DENY, $decision->getOrigin());

        // Case B: Multi-unit containing invalid ID
        $contextMulti = [
            'subject_id' => $this->subjectIdBio,
            'unit_ids'   => [$this->unitIdBio1, 999999],
        ];

        $decisionMulti = $this->resolver->resolve($this->student, $contextMulti, 'perm_mcqs', 'practice', $this->now);
        $this->assertFalse($decisionMulti->isGranted());
        $this->assertTrue($decisionMulti->isDenied());
    }

    /**
     * Requirement 6: Unit belonging to another book cannot use Campaign #2's chapter rule.
     */
    public function test_unit_belonging_to_another_book_fails_closed_or_cannot_match_campaign_2(): void
    {
        // Unit 10 belongs to Book 1 (English, Subject 1).
        // If caller specifies subject_id = 5 (Biology) with unit_id = 10, mismatch fails closed.
        $contextMismatched = [
            'subject_id' => $this->subjectIdBio,
            'unit_ids'   => [$this->unitIdEng1],
        ];

        $normalized = $this->resolver->normalizeTargetContext($contextMismatched);
        $this->assertTrue($normalized['_fail_closed'] ?? false);

        $decisionMismatched = $this->resolver->resolve($this->student, $contextMismatched, 'perm_mcqs', 'practice', $this->now);
        $this->assertFalse($decisionMismatched->isGranted());

        // If caller specifies correct subject_id = 1 (English) with unit_id = 10:
        // Campaign #2 is for Biology Ch 263, so it does NOT match English Ch 10.
        $contextEng = [
            'subject_id' => $this->subjectIdEng,
            'unit_ids'   => [$this->unitIdEng1],
        ];

        $decisionEng = $this->resolver->resolve($this->student, $contextEng, 'perm_mcqs', 'practice', $this->now);
        $this->assertFalse($decisionEng->isGranted());
        $this->assertSame(UnifiedAccessDecision::ORIGIN_DEFAULT_DENY, $decisionEng->getOrigin());
    }

    /**
     * Requirement 7: Conflicting explicit chapter_id is rejected / fails closed.
     */
    public function test_conflicting_explicit_chapter_id_fails_closed(): void
    {
        // Conflicting chapter_id (263) vs unit_ids ([264])
        $contextConflict = [
            'chapter_id' => $this->unitIdBio1,
            'unit_ids'   => [$this->unitIdBio2],
        ];

        $normalized = $this->resolver->normalizeTargetContext($contextConflict);
        $this->assertTrue($normalized['_fail_closed'] ?? false);

        $decision = $this->resolver->resolve($this->student, $contextConflict, 'perm_mcqs', 'practice', $this->now);
        $this->assertFalse($decision->isGranted());
        $this->assertTrue($decision->isDenied());

        // Consistent explicit chapter_id (263) and unit_ids ([263]) succeeds
        $contextConsistent = [
            'chapter_id' => $this->unitIdBio1,
            'unit_ids'   => [$this->unitIdBio1],
        ];

        $decisionConsistent = $this->resolver->resolve($this->student, $contextConsistent, 'perm_mcqs', 'practice', $this->now);
        $this->assertTrue($decisionConsistent->isGranted());
    }

    /**
     * Requirement 8: Existing explicit chapter_id requests continue working.
     */
    public function test_existing_explicit_chapter_id_without_unit_ids_continues_working(): void
    {
        $context = [
            'chapter_id' => $this->unitIdBio1,
        ];

        $decision = $this->resolver->resolve($this->student, $context, 'perm_mcqs', 'practice', $this->now);

        $this->assertTrue($decision->isGranted());
        $this->assertSame(UnifiedAccessDecision::PROVIDER_FREEMIUM, $decision->getProvider());
        $this->assertSame($this->campaign2->id, $decision->getCampaignId());
        $this->assertSame($this->rule1->id, $decision->getWinningRuleId());
    }

    /**
     * Requirement 9: Existing subject-level requests without unit IDs continue working.
     */
    public function test_existing_subject_level_requests_without_unit_ids_continue_working(): void
    {
        // Create subject-level allow rule for English
        $engRule = FreemiumAccessRule::create([
            'id'                       => 99,
            'campaign_id'              => $this->campaign2->id,
            'curriculum_board_id'      => $this->boardId,
            'class_id'                 => $this->classId,
            'subject_id'               => $this->subjectIdEng,
            'target_scope'             => 'subject',
            'target_status'            => 'active',
            'rule_priority'            => 10,
            'perm_mcqs'                => 'allow',
            'academic_target_snapshot' => [
                'subject_id' => $this->subjectIdEng,
            ],
        ]);

        $context = [
            'subject_id' => $this->subjectIdEng,
        ];

        $decision = $this->resolver->resolve($this->student, $context, 'perm_mcqs', 'practice', $this->now);

        $this->assertTrue($decision->isGranted());
        $this->assertSame(UnifiedAccessDecision::PROVIDER_FREEMIUM, $decision->getProvider());
        $this->assertSame($engRule->id, $decision->getWinningRuleId());

        $engRule->delete();
    }

    /**
     * Requirement 10 & Controller Flow: Test end-to-end LMS Practice Session Controller request.
     */
    public function test_lms_controller_request_context_for_selected_units(): void
    {
        // Replicates exactly what PracticeSessionController::generateSelectedUnitsPracticeSessionForLms() builds:
        // $unitIds = [263];
        // $targetContext = [
        //     'offered_program_id' => null,
        //     'subject_id'         => 5,
        //     'unit_ids'           => [263],
        // ];
        $targetContext = [
            'offered_program_id' => null,
            'subject_id'         => $this->subjectIdBio,
            'unit_ids'           => [$this->unitIdBio1],
        ];

        $decision = $this->resolver->resolve(
            $this->student,
            $targetContext,
            'perm_mcqs',
            'practice',
            $this->now
        );

        $this->assertTrue($decision->isGranted());
        $this->assertFalse($decision->isDenied());
        $this->assertSame('freemium', $decision->getProvider());
        $this->assertSame($this->campaign2->id, $decision->getCampaignId());
        $this->assertSame($this->rule1->id, $decision->getWinningRuleId());
        $this->assertTrue($decision->requiresQuotaEvaluation());
    }
}
