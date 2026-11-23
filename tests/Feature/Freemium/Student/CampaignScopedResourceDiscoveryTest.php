<?php

namespace Tests\Feature\Freemium\Student;

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
 * Campaign-Scoped Resource Discovery Test Suite (Phase 3A).
 *
 * Verifies that GET /web_api/auth/freemium/free-resources:
 *  - Only returns resources genuinely authorized by active Freemium campaigns
 *  - Excludes paid-only resources and legacy-only resources
 *  - Correctly exposes Book 28 and Unit 263 for Campaign #2
 *  - Excludes Unit 264 unless independently authorized
 *  - Preserves exact chapter-level authorized scope without unlocking entire book
 *  - Returns complete campaign identity metadata (campaign_id, campaign_name, rule_id, target_scope, access_tier)
 *  - Paginates strictly over the campaign-authorized resource set (Book 28 is on page 1)
 *  - Respects stacking rules, DENY precedence, and student eligibility
 */
class CampaignScopedResourceDiscoveryTest extends TestCase
{
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

        $this->now = Carbon::parse('2026-11-21 12:00:00', 'UTC');

        // Admin fixture
        $adminId = DB::table('users')->value('id');
        $this->admin = $adminId ? User::find($adminId) : User::create([
            'name'     => 'Discovery Test Admin',
            'email'    => 'admin_discovery_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
        ]);

        // Student fixture
        $studentId = DB::table('web_users')->insertGetId([
            'name'       => 'Discovery Test Student',
            'email'      => 'student_discovery_' . uniqid() . '@example.com',
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
        DB::table('offered_classes')->where('curriculum_board_id', 2)->where('class_id', 1)->delete();
        DB::table('web_users')->where('id', $this->student->id)->delete();

        parent::tearDown();
    }

    private function studentHeaders(WebUser $student): array
    {
        $token = auth('web_api')->login($student);
        $student->update(['last_token' => hash('sha256', $token)]);
        return [
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ];
    }

    /**
     * Requirement 1 & 2: Campaign #2 returns Book 28 with Unit 263 as the authorized unit.
     */
    public function test_campaign_2_returns_book_28_and_identifies_unit_263(): void
    {
        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/free-resources')
            ->assertStatus(200)
            ->assertJson(['success' => 1]);

        $data = $response->json('data');
        $this->assertCount(1, $data);

        $resource = $data[0];
        $this->assertSame($this->bookIdBio, $resource['book_id']);
        $this->assertSame('Federal Biology 9th', $resource['book_name']);
        $this->assertSame($this->classId, $resource['class_id']);
        $this->assertSame($this->boardId, $resource['curriculum_board_id']);
        $this->assertSame($this->subjectIdBio, $resource['subject_id']);
        $this->assertTrue($resource['is_accessible']);
        $this->assertSame('freemium', $resource['access_tier']);
        $this->assertSame('chapter', $resource['target_scope']);
        $this->assertSame($this->campaign2->id, $resource['campaign_id']);
        $this->assertSame($this->campaign2->name, $resource['campaign_name']);
        $this->assertSame($this->rule1->id, $resource['rule_id']);

        // Check authorized units
        $this->assertSame([$this->unitIdBio1], $resource['authorized_unit_ids']);
        $this->assertCount(1, $resource['authorized_units']);
        $this->assertSame($this->unitIdBio1, $resource['authorized_units'][0]['unit_id']);
        $this->assertSame('THE SCIENCE OF BIOLOGY', $resource['authorized_units'][0]['unit_name']);
    }

    /**
     * Requirement 3: Unit 264 is excluded from authorized units under Campaign #2.
     */
    public function test_unit_264_is_excluded_unless_independently_authorized(): void
    {
        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/free-resources')
            ->assertStatus(200);

        $resource = $response->json('data.0');
        $this->assertNotContains($this->unitIdBio2, $resource['authorized_unit_ids']);

        foreach ($resource['authorized_units'] as $u) {
            $this->assertNotSame($this->unitIdBio2, $u['unit_id']);
        }
    }

    /**
     * Requirement 4: Paid-only resources are excluded from Freemium free resources.
     */
    public function test_paid_only_resources_are_excluded(): void
    {
        // Give student active paid subscription to English (Subject 1, Book 1)
        $offeredClass = OfferedClass::create([
            'class_id'            => $this->classId,
            'curriculum_board_id' => $this->boardId,
            'price'               => 1000,
            'is_free'             => 0,
            'is_active'           => 1,
        ]);
        $program = OfferedProgram::create([
            'offered_class_id' => $offeredClass->id,
            'title'            => 'Paid English Program',
            'slug'             => 'paid-eng-' . uniqid(),
            'display_order'    => 1,
            'is_active'        => 1,
        ]);
        ProgramSubject::create([
            'offered_program_id' => $program->id,
            'subject_id'         => $this->subjectIdEng,
            'display_order'      => 1,
            'is_free'            => 0,
            'is_active'          => 1,
        ]);
        UserSubscription::create([
            'user_id'            => $this->student->id,
            'offered_program_id' => $program->id,
            'status'             => 'active',
            'started_at'         => $this->now->copy()->subMonth(),
            'expires_at'         => $this->now->copy()->addMonth(),
        ]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/free-resources')
            ->assertStatus(200);

        $bookIds = collect($response->json('data'))->pluck('book_id')->all();

        // English Book 1 must NOT be in freemium free resources
        $this->assertNotContains($this->bookIdEng, $bookIds);
        // Only Biology Book 28 is present
        $this->assertContains($this->bookIdBio, $bookIds);
        $this->assertCount(1, $bookIds);

        // Clean up
        UserSubscription::where('user_id', $this->student->id)->delete();
        ProgramSubject::where('offered_program_id', $program->id)->delete();
        OfferedProgram::where('id', $program->id)->delete();
        OfferedClass::where('id', $offeredClass->id)->delete();
    }

    /**
     * Requirement 5: Legacy-only resources (is_free = 1) are excluded from Freemium free resources.
     */
    public function test_legacy_only_resources_are_excluded(): void
    {
        // Set English to legacy free
        $offeredClass = OfferedClass::create([
            'class_id'            => $this->classId,
            'curriculum_board_id' => $this->boardId,
            'price'               => 1000,
            'is_free'             => 1, // Legacy free
            'is_active'           => 1,
        ]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/free-resources')
            ->assertStatus(200);

        $bookIds = collect($response->json('data'))->pluck('book_id')->all();

        // English Book 1 must NOT be present
        $this->assertNotContains($this->bookIdEng, $bookIds);

        $offeredClass->delete();
    }

    /**
     * Requirement 6: Resources from unrelated or ineligible campaigns are excluded.
     */
    public function test_resources_from_unrelated_or_ineligible_campaigns_are_excluded(): void
    {
        // Create an ineligible campaign (requires grant that student does NOT have)
        $ineligibleCampaign = FreemiumCampaign::create([
            'created_by'           => $this->admin->id,
            'name'                 => 'Grant-Required English Campaign',
            'status'               => 'active',
            'priority'             => 5,
            'start_at'             => $this->now->copy()->subDays(2),
            'end_at'               => $this->now->copy()->addMonths(1),
            'is_stackable'         => true,
            'eligibility_criteria' => ['require_grant' => true],
        ]);

        FreemiumAccessRule::create([
            'campaign_id'              => $ineligibleCampaign->id,
            'book_id'                  => $this->bookIdEng,
            'subject_id'               => $this->subjectIdEng,
            'target_scope'             => 'book',
            'target_status'            => 'active',
            'rule_priority'            => 10,
            'perm_mcqs'                => 'allow',
            'academic_target_snapshot' => ['book_id' => $this->bookIdEng],
        ]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/free-resources')
            ->assertStatus(200);

        $bookIds = collect($response->json('data'))->pluck('book_id')->all();

        // English Book 1 must NOT appear
        $this->assertNotContains($this->bookIdEng, $bookIds);
        $this->assertCount(1, $bookIds);
    }

    /**
     * Requirement 7: Multiple active campaigns follow stacking rules.
     */
    public function test_multiple_active_campaigns_follow_stacking_rules(): void
    {
        // Create a second stackable campaign for English Book 1 with same priority
        $camp2 = FreemiumCampaign::create([
            'created_by'           => $this->admin->id,
            'name'                 => 'Free Federal English 9th',
            'status'               => 'active',
            'priority'             => 10,
            'start_at'             => $this->now->copy()->subDays(2),
            'end_at'               => $this->now->copy()->addMonths(1),
            'is_stackable'         => true,
            'eligibility_criteria' => ['require_grant' => false],
        ]);

        $ruleEng = FreemiumAccessRule::create([
            'campaign_id'              => $camp2->id,
            'book_id'                  => $this->bookIdEng,
            'subject_id'               => $this->subjectIdEng,
            'class_id'                 => $this->classId,
            'curriculum_board_id'      => $this->boardId,
            'target_scope'             => 'book',
            'target_status'            => 'active',
            'rule_priority'            => 10,
            'perm_mcqs'                => 'allow',
            'academic_target_snapshot' => ['book_id' => $this->bookIdEng],
        ]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/free-resources')
            ->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(2, $data);

        $bioResource = collect($data)->firstWhere('book_id', $this->bookIdBio);
        $engResource = collect($data)->firstWhere('book_id', $this->bookIdEng);

        $this->assertNotNull($bioResource);
        $this->assertSame($this->campaign2->id, $bioResource['campaign_id']);
        $this->assertSame($this->rule1->id, $bioResource['rule_id']);

        $this->assertNotNull($engResource);
        $this->assertSame($camp2->id, $engResource['campaign_id']);
        $this->assertSame($ruleEng->id, $engResource['rule_id']);
    }

    /**
     * Requirement 8: DENY resolution correctly excludes resource.
     */
    public function test_explicit_deny_rule_excludes_resource(): void
    {
        // Add a higher priority DENY rule for Unit 263
        $denyRule = FreemiumAccessRule::create([
            'campaign_id'              => $this->campaign2->id,
            'chapter_id'               => $this->unitIdBio1,
            'book_id'                  => $this->bookIdBio,
            'subject_id'               => $this->subjectIdBio,
            'class_id'                 => $this->classId,
            'curriculum_board_id'      => $this->boardId,
            'target_scope'             => 'chapter',
            'target_status'            => 'active',
            'rule_priority'            => 5, // Lower integer = higher priority than 10
            'perm_mcqs'                => 'deny',
            'academic_target_snapshot' => ['chapter_id' => $this->unitIdBio1],
        ]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/free-resources')
            ->assertStatus(200);

        // Book 28 has no authorized units now, so it must be excluded
        $this->assertCount(0, $response->json('data'));

        $denyRule->delete();
    }

    /**
     * Requirement 9: Chapter rule does not unlock an entire book or subject.
     */
    public function test_chapter_rule_does_not_unlock_entire_book(): void
    {
        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/free-resources')
            ->assertStatus(200);

        $resource = $response->json('data.0');
        $this->assertSame('chapter', $resource['target_scope']);
        $this->assertSame([$this->unitIdBio1], $resource['authorized_unit_ids']);
        $this->assertCount(1, $resource['authorized_units']);
    }

    /**
     * Requirement 10: Book 28 is discoverable on page 1 regardless of its ID or general catalogue position.
     */
    public function test_book_28_discoverable_regardless_of_general_catalogue_position(): void
    {
        // Request with per_page = 5, page = 1
        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/free-resources?per_page=5&page=1')
            ->assertStatus(200);

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame(1, $response->json('meta.current_page'));
        $this->assertSame(1, $response->json('meta.last_page'));
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($this->bookIdBio, $response->json('data.0.book_id'));
    }

    /**
     * Requirement 11: Pagination counts and results reflect strictly the campaign-authorized set.
     */
    public function test_pagination_counts_contain_only_campaign_resources(): void
    {
        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/free-resources')
            ->assertStatus(200);

        $this->assertSame(1, $response->json('meta.total'));
    }

    /**
     * Requirement 12: Student with paid subscriptions but no eligible Freemium campaign receives no campaign resources.
     */
    public function test_student_without_eligible_campaign_receives_empty_list(): void
    {
        // Deactivate Campaign #2 by setting status to paused
        $this->campaign2->update(['status' => 'paused']);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/free-resources')
            ->assertStatus(200);

        $this->assertSame(0, $response->json('meta.total'));
        $this->assertSame([], $response->json('data'));
    }

    /**
     * Requirement 13: Campaign identity and winning rule metadata are correct.
     */
    public function test_campaign_identity_metadata_is_complete_and_correct(): void
    {
        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/freemium/free-resources')
            ->assertStatus(200);

        $resource = $response->json('data.0');
        $this->assertArrayHasKey('campaign_id', $resource);
        $this->assertArrayHasKey('campaign_name', $resource);
        $this->assertArrayHasKey('rule_id', $resource);
        $this->assertArrayHasKey('target_scope', $resource);
        $this->assertArrayHasKey('access_tier', $resource);
        $this->assertArrayHasKey('authorized_unit_ids', $resource);
        $this->assertArrayHasKey('authorized_units', $resource);

        $this->assertSame($this->campaign2->id, $resource['campaign_id']);
        $this->assertSame('Free Federal Biology Class 9th', $resource['campaign_name']);
        $this->assertSame($this->rule1->id, $resource['rule_id']);
        $this->assertSame('chapter', $resource['target_scope']);
        $this->assertSame('freemium', $resource['access_tier']);
    }
}
