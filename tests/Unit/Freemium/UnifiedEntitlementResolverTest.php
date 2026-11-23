<?php

namespace Tests\Unit\Freemium;

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
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnifiedEntitlementResolverTest extends TestCase
{
    private UnifiedEntitlementResolver $resolver;
    private StudentClassAccessService $paidAccessService;
    private FreemiumAccessRuleEvaluator $ruleEvaluator;

    private WebUser $student;
    private User $admin;
    private Carbon $now;

    private int $classId;
    private int $boardId;
    private int $subjectId;
    private int $subjectIdAlt;
    private int $bookId;
    private int $chapterId;

    private OfferedClass $offeredClass;
    private OfferedProgram $programA;
    private OfferedProgram $programB;
    private ProgramSubject $progSubA;
    private ProgramSubject $progSubB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paidAccessService = new StudentClassAccessService();
        $this->ruleEvaluator     = new FreemiumAccessRuleEvaluator();
        $this->resolver          = new UnifiedEntitlementResolver($this->paidAccessService, $this->ruleEvaluator);

        $this->now = Carbon::parse('2026-11-18 12:00:00', 'UTC');

        // Admin fixture
        $adminId = DB::table('users')->value('id');
        $this->admin = $adminId ? User::find($adminId) : User::create([
            'name'     => 'Resolver Admin',
            'email'    => 'resolver_admin_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
        ]);

        // Student fixture
        $studentId = DB::table('web_users')->insertGetId([
            'name'       => 'Resolver Student',
            'email'      => 'student_resolver_' . uniqid() . '@example.com',
            'password'   => bcrypt('secret'),
            'role'       => 'student',
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->student = WebUser::find($studentId);

        // Fetch physical IDs from database fixtures
        $this->classId      = (int) (DB::table('class_tbl')->value('id') ?? 1);
        $this->boardId      = (int) (DB::table('curriculum_board_tbl')->value('id') ?? 1);
        $this->subjectId    = (int) (DB::table('subject_tbl')->value('id') ?? 1);
        $altSub = DB::table('subject_tbl')->where('id', '!=', $this->subjectId)->value('id');
        $this->subjectIdAlt = $altSub ? (int) $altSub : (int) DB::table('subject_tbl')->insertGetId([
            'subject_name' => 'Alternative Subject ' . uniqid(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $this->bookId = (int) (DB::table('book_tbl')->value('id') ?? 1);
        $this->chapterId = (int) (DB::table('book_unit_tbl')->value('id') ?? 1);

        // Create isolated OfferedClass for this test
        $this->offeredClass = OfferedClass::create([
            'class_id'            => $this->classId,
            'curriculum_board_id' => $this->boardId,
            'price'               => 1000,
            'discount_price'      => 800,
            'is_free'             => 0,
            'is_active'           => 1,
        ]);

        // Create OfferedProgram A (covers subjectId)
        $this->programA = OfferedProgram::create([
            'offered_class_id' => $this->offeredClass->id,
            'title'            => 'Program A Pre-Med ' . uniqid(),
            'slug'             => 'program-a-' . uniqid(),
            'description'      => 'Program A Description',
            'display_order'    => 1,
            'is_active'        => 1,
        ]);

        $this->progSubA = ProgramSubject::create([
            'offered_program_id' => $this->programA->id,
            'subject_id'         => $this->subjectId,
            'display_order'      => 1,
            'is_free'            => 0,
            'is_active'          => 1,
        ]);

        // Create OfferedProgram B (covers subjectIdAlt only)
        $this->programB = OfferedProgram::create([
            'offered_class_id' => $this->offeredClass->id,
            'title'            => 'Program B ICS ' . uniqid(),
            'slug'             => 'program-b-' . uniqid(),
            'description'      => 'Program B Description',
            'display_order'    => 2,
            'is_active'        => 1,
        ]);

        $this->progSubB = ProgramSubject::create([
            'offered_program_id' => $this->programB->id,
            'subject_id'         => $this->subjectIdAlt,
            'display_order'      => 1,
            'is_free'            => 0,
            'is_active'          => 1,
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('freemium_access_rules')->delete();
        DB::table('freemium_student_grants')->delete();
        DB::table('freemium_quota_consumptions')->delete();
        DB::table('freemium_campaigns')->delete();
        DB::table('user_subscriptions')->where('user_id', $this->student->id)->delete();
        DB::table('program_subjects')->whereIn('id', [$this->progSubA->id, $this->progSubB->id])->delete();
        DB::table('offered_programs')->whereIn('id', [$this->programA->id, $this->programB->id])->delete();
        DB::table('offered_classes')->where('id', $this->offeredClass->id)->delete();
        DB::table('web_users')->where('id', $this->student->id)->delete();

        parent::tearDown();
    }

    /**
     * Tier 1: Valid active paid subscription covering requested program and subject grants access.
     */
    public function test_tier1_active_paid_subscription_grants_access(): void
    {
        // Give student active subscription to Program A
        UserSubscription::create([
            'user_id'            => $this->student->id,
            'offered_program_id' => $this->programA->id,
            'status'             => 'active',
            'expires_at'         => $this->now->copy()->addMonths(6),
        ]);

        $context = [
            'offered_program_id'  => $this->programA->id,
            'subject_id'          => $this->subjectId,
            'class_id'            => $this->classId,
            'curriculum_board_id' => $this->boardId,
        ];

        $decision = $this->resolver->resolve($this->student, $context, 'perm_mcqs', 'practice', $this->now);

        $this->assertTrue($decision->isGranted());
        $this->assertFalse($decision->isDenied());
        $this->assertSame(UnifiedAccessDecision::PROVIDER_PAID, $decision->getProvider());
        $this->assertSame(UnifiedAccessDecision::ORIGIN_USER_SUBSCRIPTION, $decision->getOrigin());
        // Paid subscriptions never consume freemium quota!
        $this->assertFalse($decision->requiresQuotaEvaluation());
        $this->assertNull($decision->getCampaignId());
        $this->assertNull($decision->getWinningRuleId());
        $this->assertSame('perm_mcqs', $decision->getDimension());
    }

    /**
     * Tier 1: Active subscription covering subject without explicit program_id grants access.
     */
    public function test_tier1_active_subscription_matches_by_subject_alone(): void
    {
        UserSubscription::create([
            'user_id'            => $this->student->id,
            'offered_program_id' => $this->programA->id,
            'status'             => 'active',
            'expires_at'         => null, // lifetime / indefinite
        ]);

        // Request subject without offering program ID
        $context = [
            'subject_id'          => $this->subjectId,
            'class_id'            => $this->classId,
            'curriculum_board_id' => $this->boardId,
        ];

        $decision = $this->resolver->resolve($this->student, $context, 'perm_view', null, $this->now);

        $this->assertTrue($decision->isGranted());
        $this->assertSame(UnifiedAccessDecision::PROVIDER_PAID, $decision->getProvider());
        $this->assertSame(UnifiedAccessDecision::ORIGIN_USER_SUBSCRIPTION, $decision->getOrigin());
    }

    /**
     * Tier 1: Active subscription covering Program A does NOT cover Program B's subject, falling through to Tier 2.
     */
    public function test_tier1_subscription_scope_mismatch_falls_through_to_tier2(): void
    {
        // Subscribed ONLY to Program A (which has subjectId, NOT subjectIdAlt)
        UserSubscription::create([
            'user_id'            => $this->student->id,
            'offered_program_id' => $this->programA->id,
            'status'             => 'active',
            'expires_at'         => $this->now->copy()->addMonths(3),
        ]);

        // Request subjectIdAlt which belongs to Program B
        $context = [
            'offered_program_id'  => $this->programB->id,
            'subject_id'          => $this->subjectIdAlt,
            'class_id'            => $this->classId,
            'curriculum_board_id' => $this->boardId,
        ];

        // Without any Freemium campaign, should fail through to Tier 4 default deny
        $decision = $this->resolver->resolve($this->student, $context, 'perm_mcqs', null, $this->now);

        $this->assertFalse($decision->isGranted());
        $this->assertSame(UnifiedAccessDecision::PROVIDER_NONE, $decision->getProvider());
        $this->assertSame(UnifiedAccessDecision::ORIGIN_DEFAULT_DENY, $decision->getOrigin());
        $this->assertSame($this->programB->slug, $decision->getUpgradeProgramSlug());
    }

    /**
     * Tier 1: Expired paid subscription falls through to Freemium / default deny.
     */
    public function test_tier1_expired_subscription_falls_through(): void
    {
        UserSubscription::create([
            'user_id'            => $this->student->id,
            'offered_program_id' => $this->programA->id,
            'status'             => 'active',
            'expires_at'         => $this->now->copy()->subDay(), // Expired yesterday
        ]);

        $context = [
            'offered_program_id' => $this->programA->id,
            'subject_id'         => $this->subjectId,
        ];

        $decision = $this->resolver->resolve($this->student, $context, 'perm_mcqs', null, $this->now);

        $this->assertFalse($decision->isGranted());
        $this->assertSame(UnifiedAccessDecision::PROVIDER_NONE, $decision->getProvider());
        $this->assertSame(UnifiedAccessDecision::ORIGIN_DEFAULT_DENY, $decision->getOrigin());
    }

    /**
     * Tier 2: Explicit Freemium ALLOW rule grants access when no paid subscription exists.
     */
    public function test_tier2_freemium_explicit_allow_rule_grants_access(): void
    {
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'Punjab Free STEM',
            'slug'         => 'punjab-stem-' . uniqid(),
            'status'       => 'active',
            'priority'     => 10,
            'start_at'     => $this->now->copy()->subDay(),
            'end_at'       => $this->now->copy()->addMonths(1),
            'is_stackable' => true,
        ]);

        $rule = FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'subject_id'               => $this->subjectId,
            'target_scope'             => 'subject',
            'target_status'            => 'active',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['subject_id' => $this->subjectId],
            'perm_mcqs'                => 'allow',
            'perm_view'                => 'allow',
            'perm_answers'             => 'allow',
            'perm_solutions'           => 'allow',
            'perm_download'            => 'inherit',
            'perm_video'               => 'inherit',
            'perm_slos'                => 'inherit',
            'perm_exercise_questions'  => 'inherit',
        ]);

        $context = [
            'subject_id' => $this->subjectId,
        ];

        // 1. Learning resource request ($examType === null): requiresQuotaEvaluation is FALSE
        $decision1 = $this->resolver->resolve($this->student, $context, 'perm_mcqs', null, $this->now);
        $this->assertTrue($decision1->isGranted());
        $this->assertSame(UnifiedAccessDecision::PROVIDER_FREEMIUM, $decision1->getProvider());
        $this->assertSame(UnifiedAccessDecision::ORIGIN_FREEMIUM_RULE, $decision1->getOrigin());
        $this->assertSame($campaign->id, $decision1->getCampaignId());
        $this->assertSame($rule->id, $decision1->getWinningRuleId());
        $this->assertFalse($decision1->requiresQuotaEvaluation());

        // 2. Assessment generation request ($examType === 'practice'): requiresQuotaEvaluation is TRUE
        $decision2 = $this->resolver->resolve($this->student, $context, 'perm_mcqs', 'practice', $this->now);
        $this->assertTrue($decision2->isGranted());
        $this->assertSame(UnifiedAccessDecision::PROVIDER_FREEMIUM, $decision2->getProvider());
        $this->assertTrue($decision2->requiresQuotaEvaluation());
        $this->assertSame('practice', $decision2->getExamType());
    }

    /**
     * Tier 2: Explicit Freemium DENY rule denies access even if other rules might allow at lower specificity.
     */
    public function test_tier2_freemium_explicit_deny_rule_denies_access(): void
    {
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'Restricted Pilot',
            'slug'         => 'restricted-pilot-' . uniqid(),
            'status'       => 'active',
            'priority'     => 5,
            'start_at'     => $this->now->copy()->subDay(),
            'end_at'       => $this->now->copy()->addMonths(1),
            'is_stackable' => true,
        ]);

        $rule = FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'subject_id'               => $this->subjectId,
            'target_scope'             => 'subject',
            'target_status'            => 'active',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['subject_id' => $this->subjectId],
            'perm_mcqs'                => 'deny', // Strict DENY
            'perm_view'                => 'deny',
            'perm_answers'             => 'deny',
            'perm_solutions'           => 'deny',
            'perm_download'            => 'deny',
            'perm_video'               => 'deny',
            'perm_slos'                => 'deny',
            'perm_exercise_questions'  => 'deny',
        ]);

        $context = [
            'subject_id' => $this->subjectId,
        ];

        $decision = $this->resolver->resolve($this->student, $context, 'perm_mcqs', 'formal_test', $this->now);

        $this->assertFalse($decision->isGranted());
        $this->assertTrue($decision->isDenied());
        $this->assertSame(UnifiedAccessDecision::PROVIDER_FREEMIUM, $decision->getProvider());
        $this->assertSame(UnifiedAccessDecision::ORIGIN_FREEMIUM_RULE, $decision->getOrigin());
        $this->assertSame($campaign->id, $decision->getCampaignId());
        $this->assertSame($rule->id, $decision->getWinningRuleId());
        $this->assertFalse($decision->requiresQuotaEvaluation());
    }

    /**
     * Tier 3: Legacy free flag (program_subjects.is_free = 1) grants access when Freemium rules inherit.
     */
    public function test_tier3_legacy_free_flag_on_program_subject_grants_access(): void
    {
        // Mark Program A's subject as legacy free
        $this->progSubA->update(['is_free' => 1]);

        $context = [
            'offered_program_id' => $this->programA->id,
            'subject_id'         => $this->subjectId,
        ];

        $decision = $this->resolver->resolve($this->student, $context, 'perm_mcqs', null, $this->now);

        $this->assertTrue($decision->isGranted());
        $this->assertSame(UnifiedAccessDecision::PROVIDER_LEGACY, $decision->getProvider());
        $this->assertSame(UnifiedAccessDecision::ORIGIN_LEGACY_FALLBACK, $decision->getOrigin());
        $this->assertFalse($decision->requiresQuotaEvaluation());
    }

    /**
     * Tier 3: Legacy free flag (offered_classes.is_free = 1) grants access when Freemium rules inherit.
     */
    public function test_tier3_legacy_free_flag_on_offered_class_grants_access(): void
    {
        // Mark class as legacy free
        $this->offeredClass->update(['is_free' => 1]);

        $context = [
            'class_id'            => $this->classId,
            'curriculum_board_id' => $this->boardId,
        ];

        $decision = $this->resolver->resolve($this->student, $context, 'perm_view', null, $this->now);

        $this->assertTrue($decision->isGranted());
        $this->assertSame(UnifiedAccessDecision::PROVIDER_LEGACY, $decision->getProvider());
        $this->assertSame(UnifiedAccessDecision::ORIGIN_LEGACY_FALLBACK, $decision->getOrigin());
    }

    /**
     * Tier 4: Default Deny when unsubscribed, no freemium rules, and no legacy free flags.
     */
    public function test_tier4_default_deny_fails_closed(): void
    {
        $context = [
            'offered_program_id'  => $this->programA->id,
            'subject_id'          => $this->subjectId,
            'class_id'            => $this->classId,
            'curriculum_board_id' => $this->boardId,
        ];

        $decision = $this->resolver->resolve($this->student, $context, 'perm_mcqs', null, $this->now);

        $this->assertFalse($decision->isGranted());
        $this->assertTrue($decision->isDenied());
        $this->assertSame(UnifiedAccessDecision::PROVIDER_NONE, $decision->getProvider());
        $this->assertSame(UnifiedAccessDecision::ORIGIN_DEFAULT_DENY, $decision->getOrigin());
        $this->assertSame($this->programA->slug, $decision->getUpgradeProgramSlug());
        $this->assertStringContainsString('subscription is required', $decision->getDenialReason());
    }

    /**
     * Security: Inactive/blocked student account fails closed immediately.
     */
    public function test_inactive_student_account_fails_closed_to_default_deny(): void
    {
        $this->student->update(['status' => 'blocked']);

        $context = [
            'subject_id' => $this->subjectId,
        ];

        $decision = $this->resolver->resolve($this->student, $context, 'perm_mcqs', null, $this->now);

        $this->assertFalse($decision->isGranted());
        $this->assertSame(UnifiedAccessDecision::PROVIDER_NONE, $decision->getProvider());
        $this->assertSame(UnifiedAccessDecision::ORIGIN_DEFAULT_DENY, $decision->getOrigin());
        $this->assertStringContainsString('Active student account required', $decision->getDenialReason());
    }

    /**
     * Security: Non-student role fails closed immediately.
     */
    public function test_non_student_role_fails_closed_to_default_deny(): void
    {
        $this->student->update(['role' => 'teacher']);

        $context = [
            'subject_id' => $this->subjectId,
        ];

        $decision = $this->resolver->resolve($this->student, $context, 'perm_mcqs', null, $this->now);

        $this->assertFalse($decision->isGranted());
        $this->assertSame(UnifiedAccessDecision::PROVIDER_NONE, $decision->getProvider());
        $this->assertSame(UnifiedAccessDecision::ORIGIN_DEFAULT_DENY, $decision->getOrigin());
    }

    /**
     * Hierarchy Context Normalization: Resolving via chapter_id resolves book and subject.
     */
    public function test_context_normalization_resolves_book_and_subject_from_chapter(): void
    {
        // Give student active subscription to Program A
        UserSubscription::create([
            'user_id'            => $this->student->id,
            'offered_program_id' => $this->programA->id,
            'status'             => 'active',
            'expires_at'         => null,
        ]);

        // Link chapter -> book -> subject
        $book = Book::firstOrCreate(
            ['id' => $this->bookId],
            [
                'book_name'           => 'Test Physics Book',
                'class_id'            => $this->classId,
                'subject_id'          => $this->subjectId,
                'curriculum_board_id' => $this->boardId,
                'activate'            => 1,
            ]
        );
        $book->update([
            'subject_id'          => $this->subjectId,
            'class_id'            => $this->classId,
            'curriculum_board_id' => $this->boardId,
        ]);

        $unit = BookUnit::firstOrCreate(
            ['id' => $this->chapterId],
            [
                'book_id'   => $this->bookId,
                'unit_name' => 'Unit 1 Introduction',
                'unit_no'   => 1,
                'activate'  => 1,
            ]
        );
        $unit->update(['book_id' => $this->bookId]);

        // Only provide chapter_id in context
        $context = [
            'chapter_id' => $this->chapterId,
        ];

        $decision = $this->resolver->resolve($this->student, $context, 'perm_view', null, $this->now);

        $this->assertTrue($decision->isGranted());
        $this->assertSame(UnifiedAccessDecision::PROVIDER_PAID, $decision->getProvider());
    }

    /**
     * Temporal Half-Open Interval: Expired campaign rules do not participate, falling through to default deny.
     */
    public function test_temporal_interval_expired_campaign_falls_through_to_default_deny(): void
    {
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'Past Campaign',
            'status'       => 'active',
            'priority'     => 10,
            'start_at'     => $this->now->copy()->subDays(10),
            'end_at'       => $this->now->copy()->subDay(), // Expired
            'is_stackable' => true,
        ]);

        FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'subject_id'               => $this->subjectIdAlt,
            'target_scope'             => 'subject',
            'target_status'            => 'active',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['subject_id' => $this->subjectIdAlt],
            'perm_mcqs'                => 'allow',
        ]);

        $context = ['subject_id' => $this->subjectIdAlt];

        $decision = $this->resolver->resolve($this->student, $context, 'perm_mcqs', null, $this->now);

        $this->assertFalse($decision->isGranted());
        $this->assertSame(UnifiedAccessDecision::PROVIDER_NONE, $decision->getProvider());
        $this->assertSame(UnifiedAccessDecision::ORIGIN_DEFAULT_DENY, $decision->getOrigin());
    }

    /**
     * Eligibility Criteria: Campaign requiring voucher grant denies access if student lacks grant, grants if grant exists.
     */
    public function test_campaign_voucher_requirement_enforces_active_grant(): void
    {
        $campaign = FreemiumCampaign::create([
            'created_by'           => $this->admin->id,
            'name'                 => 'Grant-Only Campaign',
            'status'               => 'active',
            'priority'             => 10,
            'start_at'             => $this->now->copy()->subDay(),
            'end_at'               => $this->now->copy()->addMonths(1),
            'is_stackable'         => true,
            'eligibility_criteria' => ['require_grant' => true],
        ]);

        $rule = FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'subject_id'               => $this->subjectIdAlt,
            'target_scope'             => 'subject',
            'target_status'            => 'active',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['subject_id' => $this->subjectIdAlt],
            'perm_mcqs'                => 'allow',
        ]);

        $context = ['subject_id' => $this->subjectIdAlt];

        // 1. Without grant: student is ineligible, falls through to default deny
        $decision1 = $this->resolver->resolve($this->student, $context, 'perm_mcqs', null, $this->now);
        $this->assertFalse($decision1->isGranted());
        $this->assertSame(UnifiedAccessDecision::PROVIDER_NONE, $decision1->getProvider());

        // 2. Grant active voucher
        FreemiumStudentGrant::create([
            'campaign_id' => $campaign->id,
            'user_id'     => $this->student->id,
            'status'      => 'active',
            'granted_at'  => $this->now->copy()->subHour(),
            'expires_at'  => $this->now->copy()->addDays(7),
        ]);

        $decision2 = $this->resolver->resolve($this->student, $context, 'perm_mcqs', null, $this->now);
        $this->assertTrue($decision2->isGranted());
        $this->assertSame(UnifiedAccessDecision::PROVIDER_FREEMIUM, $decision2->getProvider());
        $this->assertSame($campaign->id, $decision2->getCampaignId());
        $this->assertSame($rule->id, $decision2->getWinningRuleId());
    }

    /**
     * Multiple Active Subscriptions: Resolves correct program according to requested subject.
     */
    public function test_multiple_active_subscriptions_resolves_correct_program(): void
    {
        // Student subscribed to both Program A and Program B
        UserSubscription::create([
            'user_id'            => $this->student->id,
            'offered_program_id' => $this->programA->id,
            'status'             => 'active',
            'expires_at'         => null,
        ]);
        UserSubscription::create([
            'user_id'            => $this->student->id,
            'offered_program_id' => $this->programB->id,
            'status'             => 'active',
            'expires_at'         => null,
        ]);

        // Request subject from Program B
        $contextB = ['subject_id' => $this->subjectIdAlt];
        $decisionB = $this->resolver->resolve($this->student, $contextB, 'perm_mcqs', null, $this->now);
        $this->assertTrue($decisionB->isGranted());
        $this->assertSame(UnifiedAccessDecision::PROVIDER_PAID, $decisionB->getProvider());
        $this->assertSame($this->programB->id, $decisionB->getMetadata()['program_id']);

        // Request subject from Program A
        $contextA = ['subject_id' => $this->subjectId];
        $decisionA = $this->resolver->resolve($this->student, $contextA, 'perm_mcqs', null, $this->now);
        $this->assertTrue($decisionA->isGranted());
        $this->assertSame(UnifiedAccessDecision::PROVIDER_PAID, $decisionA->getProvider());
        $this->assertSame($this->programA->id, $decisionA->getMetadata()['program_id']);
    }

    /**
     * DTO: toArray() produces structured, complete representation for API responses and logging.
     */
    public function test_decision_dto_to_array_serialization(): void
    {
        $decision = UnifiedAccessDecision::grantedFreemium(
            campaignId: 42,
            winningRuleId: 99,
            dimension: 'perm_mcqs',
            requiresQuota: true,
            examType: 'practice',
            diagnostic: 'Allowed via practice rule'
        );

        $array = $decision->toArray();

        $this->assertTrue($array['is_granted']);
        $this->assertSame('freemium', $array['provider']);
        $this->assertSame('freemium_access_rule', $array['origin']);
        $this->assertSame('perm_mcqs', $array['dimension']);
        $this->assertTrue($array['requires_quota_evaluation']);
        $this->assertSame(42, $array['campaign_id']);
        $this->assertSame(99, $array['winning_rule_id']);
        $this->assertSame('practice', $array['exam_type']);
        $this->assertSame('Allowed via practice rule', $array['diagnostic_message']);
    }
}
