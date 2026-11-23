<?php

namespace Tests\Feature\Freemium\Student;

use App\Models\Book;
use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\OfferedClass;
use App\Models\OfferedProgram;
use App\Models\ProgramSubject;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudentFreemiumAccessControllerTest extends TestCase
{
    private WebUser $student;
    private WebUser $otherStudent;
    private User $admin;
    private Carbon $now;

    private int $classId;
    private int $boardId;
    private int $subjectId;
    private int $subjectIdAlt;
    private int $bookId;

    private OfferedClass $offeredClass;
    private OfferedProgram $programA;
    private OfferedProgram $programB;
    private ProgramSubject $progSubA;
    private ProgramSubject $progSubB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = Carbon::parse('2026-11-18 12:00:00', 'UTC');

        // Admin fixture
        $adminId = DB::table('users')->value('id');
        $this->admin = $adminId ? User::find($adminId) : User::create([
            'name'     => 'Access Test Admin',
            'email'    => 'admin_access_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
        ]);

        // Student fixture
        $studentId = DB::table('web_users')->insertGetId([
            'name'       => 'Student One',
            'email'      => 'student_one_' . uniqid() . '@example.com',
            'password'   => bcrypt('secret'),
            'role'       => 'student',
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->student = WebUser::find($studentId);

        // Other student fixture (for isolation testing)
        $otherStudentId = DB::table('web_users')->insertGetId([
            'name'       => 'Student Two',
            'email'      => 'student_two_' . uniqid() . '@example.com',
            'password'   => bcrypt('secret'),
            'role'       => 'student',
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->otherStudent = WebUser::find($otherStudentId);

        // Real fixture IDs
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

        // Isolated OfferedClass
        $this->offeredClass = OfferedClass::create([
            'class_id'            => $this->classId,
            'curriculum_board_id' => $this->boardId,
            'price'               => 1000,
            'discount_price'      => 800,
            'is_free'             => 0,
            'is_active'           => 1,
        ]);

        // Programs
        $this->programA = OfferedProgram::create([
            'offered_class_id' => $this->offeredClass->id,
            'title'            => 'Program Alpha ' . uniqid(),
            'slug'             => 'program-alpha-' . uniqid(),
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

        $this->programB = OfferedProgram::create([
            'offered_class_id' => $this->offeredClass->id,
            'title'            => 'Program Beta ' . uniqid(),
            'slug'             => 'program-beta-' . uniqid(),
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
        DB::table('user_subscriptions')->whereIn('user_id', [$this->student->id, $this->otherStudent->id])->delete();
        DB::table('program_subjects')->whereIn('id', [$this->progSubA->id, $this->progSubB->id])->delete();
        DB::table('offered_programs')->whereIn('id', [$this->programA->id, $this->programB->id])->delete();
        DB::table('offered_classes')->where('id', $this->offeredClass->id)->delete();
        DB::table('web_users')->whereIn('id', [$this->student->id, $this->otherStudent->id])->delete();

        parent::tearDown();
    }

    private function studentHeaders(WebUser $student): array
    {
        $token = auth('web_api')->login($student);
        $student->update(['last_token' => hash('sha256', $token)]);
        return [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ];
    }

    /**
     * Unauthenticated request is rejected with 401.
     */
    public function test_unauthenticated_request_is_rejected_with_401(): void
    {
        $this->getJson('/web_api/auth/freemium/entitlements')->assertStatus(401);
        $this->postJson('/web_api/auth/freemium/evaluate-access', [])->assertStatus(401);
        $this->getJson('/web_api/auth/freemium/free-resources')->assertStatus(401);
    }

    /**
     * Non-student role is rejected with 403.
     */
    public function test_non_student_role_is_forbidden_with_403(): void
    {
        $this->student->update(['role' => 'teacher']);

        $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/entitlements')
            ->assertStatus(403)
            ->assertJson(['message' => 'Active student account required.']);
    }

    /**
     * Inactive student account is rejected with 403.
     */
    public function test_inactive_student_account_is_forbidden_with_403(): void
    {
        $this->student->update(['status' => 'blocked']);

        $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/entitlements')
            ->assertStatus(403)
            ->assertJson(['message' => 'Active student account required.']);
    }

    /**
     * Entitlements endpoint returns paid subscriptions and active Freemium campaign.
     */
    public function test_entitlements_returns_paid_subscriptions_and_freemium_campaign(): void
    {
        // 1. Give student a paid subscription
        UserSubscription::create([
            'user_id'            => $this->student->id,
            'offered_program_id' => $this->programA->id,
            'status'             => 'active',
            'expires_at'         => now()->addMonths(3),
        ]);

        // 2. Active campaign
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'National Free STEM Prep',
            'status'       => 'active',
            'priority'     => 10,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonths(1),
            'is_stackable' => true,
        ]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/entitlements')
            ->assertStatus(200)
            ->assertJson(['success' => 1]);

        $data = $response->json('data');
        $this->assertSame($this->student->id, $data['student']['id']);
        $this->assertCount(1, $data['active_paid_subscriptions']);
        $this->assertSame($this->programA->id, $data['active_paid_subscriptions'][0]['program_id']);
        $this->assertTrue($data['freemium_access']['has_active_campaign']);
        $this->assertSame('National Free STEM Prep', $data['freemium_access']['campaign_display_name']);
    }

    /**
     * Unsubscribed student with no campaign returns empty entitlements cleanly.
     */
    public function test_entitlements_unsubscribed_student_returns_empty_state(): void
    {
        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/entitlements')
            ->assertStatus(200)
            ->assertJson(['success' => 1]);

        $data = $response->json('data');
        $this->assertEmpty($data['active_paid_subscriptions']);
        $this->assertFalse($data['freemium_access']['has_active_campaign']);
    }

    /**
     * Evaluate access grants paid when matching subscription exists.
     */
    public function test_evaluate_access_grants_paid_when_subscribed(): void
    {
        UserSubscription::create([
            'user_id'            => $this->student->id,
            'offered_program_id' => $this->programA->id,
            'status'             => 'active',
            'expires_at'         => now()->addMonth(),
        ]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->postJson('/web_api/auth/freemium/evaluate-access', [
                'offered_program_id' => $this->programA->id,
                'subject_id'         => $this->subjectId,
                'dimension'          => 'perm_mcqs',
            ])
            ->assertStatus(200)
            ->assertJson(['success' => 1]);

        $access = $response->json('data');
        $this->assertTrue($access['granted']);
        $this->assertSame('paid', $access['tier']);
        $this->assertSame('allow', $access['action']);
        $this->assertFalse($access['upgrade_required']);
        $this->assertFalse($access['requires_quota_evaluation']);
    }

    /**
     * Evaluate access grants freemium when explicit allow rule matches.
     */
    public function test_evaluate_access_grants_freemium_when_allowed_by_rule(): void
    {
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'Test Free Campaign',
            'status'       => 'active',
            'priority'     => 10,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonth(),
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

        // 1. Static learning resource: requires_quota_evaluation is false
        $response1 = $this->withHeaders($this->studentHeaders($this->student))
            ->postJson('/web_api/auth/freemium/evaluate-access', [
                'subject_id' => $this->subjectIdAlt,
                'dimension'  => 'perm_mcqs',
            ])
            ->assertStatus(200);

        $this->assertTrue($response1->json('data.granted'));
        $this->assertSame('freemium', $response1->json('data.tier'));
        $this->assertFalse($response1->json('data.requires_quota_evaluation'));

        // 2. Assessment generation (exam_type = 'practice'): requires_quota_evaluation is true
        $response2 = $this->withHeaders($this->studentHeaders($this->student))
            ->postJson('/web_api/auth/freemium/evaluate-access', [
                'subject_id' => $this->subjectIdAlt,
                'dimension'  => 'perm_mcqs',
                'exam_type'  => 'practice',
            ])
            ->assertStatus(200);

        $this->assertTrue($response2->json('data.granted'));
        $this->assertSame('freemium', $response2->json('data.tier'));
        $this->assertTrue($response2->json('data.requires_quota_evaluation'));
        $this->assertSame('practice', $response2->json('data.exam_type'));
    }

    /**
     * Evaluate access denies freemium when explicit deny rule matches.
     */
    public function test_evaluate_access_denies_freemium_when_denied_by_rule(): void
    {
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'Restricted Campaign',
            'status'       => 'active',
            'priority'     => 10,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonth(),
            'is_stackable' => true,
        ]);

        FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'subject_id'               => $this->subjectIdAlt,
            'target_scope'             => 'subject',
            'target_status'            => 'active',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['subject_id' => $this->subjectIdAlt],
            'perm_mcqs'                => 'deny',
        ]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->postJson('/web_api/auth/freemium/evaluate-access', [
                'subject_id' => $this->subjectIdAlt,
                'dimension'  => 'perm_mcqs',
            ])
            ->assertStatus(200);

        $access = $response->json('data');
        $this->assertFalse($access['granted']);
        $this->assertSame('freemium', $access['tier']);
        $this->assertSame('deny', $access['action']);
        $this->assertTrue($access['upgrade_required']);
        $this->assertSame('Access denied by Freemium policy.', $access['message']);
    }

    /**
     * Evaluate access default deny when unentitled fails closed.
     */
    public function test_evaluate_access_default_deny_when_unentitled(): void
    {
        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->postJson('/web_api/auth/freemium/evaluate-access', [
                'offered_program_id' => $this->programB->id,
                'subject_id'         => $this->subjectIdAlt,
                'dimension'          => 'perm_mcqs',
            ])
            ->assertStatus(200);

        $access = $response->json('data');
        $this->assertFalse($access['granted']);
        $this->assertSame('none', $access['tier']);
        $this->assertSame('deny', $access['action']);
        $this->assertTrue($access['upgrade_required']);
        $this->assertStringContainsString('/lms/subscribe/' . $this->programB->slug, $access['subscription_url']);
    }

    /**
     * Security: evaluate access does not leak internal campaign, rule, or specificity IDs.
     */
    public function test_evaluate_access_does_not_leak_internal_campaign_or_rule_data(): void
    {
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'Internal Secret Campaign 999',
            'status'       => 'active',
            'priority'     => 1,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonth(),
            'is_stackable' => true,
        ]);

        $rule = FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'subject_id'               => $this->subjectIdAlt,
            'target_scope'             => 'subject',
            'target_status'            => 'active',
            'rule_priority'            => 1,
            'academic_target_snapshot' => ['subject_id' => $this->subjectIdAlt],
            'perm_mcqs'                => 'deny',
        ]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->postJson('/web_api/auth/freemium/evaluate-access', [
                'subject_id' => $this->subjectIdAlt,
            ]);

        $jsonString = $response->getContent();

        // Must NOT expose internal campaign ID, rule ID, priority, or internal diagnostics
        $this->assertStringNotContainsString('"campaign_id"', $jsonString);
        $this->assertStringNotContainsString('"rule_id"', $jsonString);
        $this->assertStringNotContainsString('"winning_rule_id"', $jsonString);
        $this->assertStringNotContainsString('"specificity"', $jsonString);
        $this->assertStringNotContainsString('Internal Secret Campaign 999', $jsonString);
    }

    /**
     * Free resources discovery returns paginated books with accessibility flags.
     */
    public function test_free_resources_paginated_discovery(): void
    {
        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/free-resources?per_page=5')
            ->assertStatus(200)
            ->assertJson(['success' => 1]);

        $this->assertIsArray($response->json('data'));
        $this->assertArrayHasKey('meta', $response->json());
        $this->assertSame(1, $response->json('meta.current_page'));
    }
}
