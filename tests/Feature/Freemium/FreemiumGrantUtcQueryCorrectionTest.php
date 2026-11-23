<?php

namespace Tests\Feature\Freemium;

use App\Http\Services\Freemium\FreemiumAccessRuleEvaluator;
use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumStudentGrant;
use App\Models\User;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FreemiumGrantUtcQueryCorrectionTest extends TestCase
{
    private FreemiumAccessRuleEvaluator $evaluator;
    private WebUser $student;
    private User $admin;
    private Carbon $nowUtc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new FreemiumAccessRuleEvaluator();
        $this->nowUtc = Carbon::parse('2026-11-21 12:00:00', 'UTC');

        $adminId = DB::table('users')->value('id');
        $this->admin = $adminId ? User::find($adminId) : User::first();

        // Ensure a test student exists
        $studentId = DB::table('web_users')->where('role', 'student')->value('id');
        if (!$studentId) {
            $studentId = DB::table('web_users')->insertGetId([
                'name'       => 'Grant Test Student',
                'email'      => 'student_grant_' . uniqid() . '@example.com',
                'password'   => bcrypt('secret'),
                'role'       => 'student',
                'status'     => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->student = WebUser::find($studentId);

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    /**
     * Helper to create a campaign requiring student grant.
     */
    private function createGrantRequiredCampaign(array $overrides = []): FreemiumCampaign
    {
        $campaign = FreemiumCampaign::create(array_merge([
            'name'                 => 'Grant Required Campaign ' . uniqid(),
            'status'               => 'active',
            'is_stackable'         => false,
            'priority'             => 10,
            'start_at'             => $this->nowUtc->copy()->subDays(5),
            'end_at'               => $this->nowUtc->copy()->addDays(5),
            'eligibility_criteria' => ['require_grant' => true],
            'created_by'           => $this->admin?->id,
            'updated_by'           => $this->admin?->id,
        ], $overrides));

        // Set exact timestamps
        DB::table('freemium_campaigns')->where('id', $campaign->id)->update([
            'start_at' => DB::raw("FROM_UNIXTIME({$this->nowUtc->copy()->subDays(5)->getTimestamp()})"),
            'end_at'   => DB::raw("FROM_UNIXTIME({$this->nowUtc->copy()->addDays(5)->getTimestamp()})"),
        ]);

        FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'target_scope'             => 'global',
            'target_status'            => 'active',
            'perm_mcqs'                => 'allow',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['scope' => 'global'],
        ]);

        return $campaign->fresh();
    }

    /**
     * Helper to create student grant with exact epoch timestamps.
     */
    private function createStudentGrant(int $campaignId, Carbon $grantedAt, ?Carbon $expiresAt = null): FreemiumStudentGrant
    {
        $grant = FreemiumStudentGrant::create([
            'campaign_id'  => $campaignId,
            'user_id'      => $this->student->id,
            'grant_type'   => 'direct',
            'voucher_code' => null,
            'granted_at'   => $grantedAt,
            'expires_at'   => $expiresAt,
            'status'       => 'active',
            'created_by'   => $this->admin?->id,
        ]);

        DB::table('freemium_student_grants')->where('id', $grant->id)->update([
            'granted_at' => DB::raw("FROM_UNIXTIME({$grantedAt->getTimestamp()})"),
            'expires_at' => $expiresAt ? DB::raw("FROM_UNIXTIME({$expiresAt->getTimestamp()})") : null,
        ]);

        return $grant->fresh();
    }

    /**
     * 1. Grant starting in the future is not yet valid.
     */
    public function test_grant_starting_in_future_is_not_yet_valid(): void
    {
        $campaign = $this->createGrantRequiredCampaign();
        $futureGranted = $this->nowUtc->copy()->addHours(2);
        $futureExpires = $this->nowUtc->copy()->addDays(10);

        $grant = $this->createStudentGrant($campaign->id, $futureGranted, $futureExpires);

        // Scope check
        $validGrantExists = FreemiumStudentGrant::validAt($this->nowUtc)
            ->where('id', $grant->id)
            ->exists();
        $this->assertFalse($validGrantExists, "Grant with future granted_at must not be valid");

        // Evaluator check
        $evalResult = $this->evaluator->evaluate($this->student, [], 'perm_mcqs', $this->nowUtc);
        $this->assertTrue($evalResult->isDenied(), "Student with future grant must be denied");
    }

    /**
     * 2. Grant issued in the past is valid.
     */
    public function test_grant_issued_in_past_is_valid(): void
    {
        $campaign = $this->createGrantRequiredCampaign();
        $pastGranted = $this->nowUtc->copy()->subHours(2);
        $futureExpires = $this->nowUtc->copy()->addDays(10);

        $grant = $this->createStudentGrant($campaign->id, $pastGranted, $futureExpires);

        // Scope check
        $validGrantExists = FreemiumStudentGrant::validAt($this->nowUtc)
            ->where('id', $grant->id)
            ->exists();
        $this->assertTrue($validGrantExists, "Grant issued in past and unexpired must be valid");

        // Evaluator check
        $evalResult = $this->evaluator->evaluate($this->student, [], 'perm_mcqs', $this->nowUtc);
        $this->assertTrue($evalResult->isAllowed(), "Student with valid past grant must be allowed");
    }

    /**
     * 3. Grant with null expiration remains valid after issuance.
     */
    public function test_grant_with_null_expiration_remains_valid_after_issuance(): void
    {
        $campaign = $this->createGrantRequiredCampaign();
        $pastGranted = $this->nowUtc->copy()->subDays(3);

        $grant = $this->createStudentGrant($campaign->id, $pastGranted, null);

        // Scope check at current time
        $validNow = FreemiumStudentGrant::validAt($this->nowUtc)
            ->where('id', $grant->id)
            ->exists();
        $this->assertTrue($validNow, "Grant with null expires_at must be valid now");

        // Scope check in far future
        $validFarFuture = FreemiumStudentGrant::validAt($this->nowUtc->copy()->addYears(5))
            ->where('id', $grant->id)
            ->exists();
        $this->assertTrue($validFarFuture, "Grant with null expires_at must remain valid far in the future");

        // Evaluator check
        $evalResult = $this->evaluator->evaluate($this->student, [], 'perm_mcqs', $this->nowUtc);
        $this->assertTrue($evalResult->isAllowed(), "Student with indefinite grant must be allowed");
    }

    /**
     * 4. Grant that has expired is invalid.
     */
    public function test_grant_that_has_expired_is_invalid(): void
    {
        $campaign = $this->createGrantRequiredCampaign();
        $pastGranted = $this->nowUtc->copy()->subDays(5);
        $pastExpired = $this->nowUtc->copy()->subHour();

        $grant = $this->createStudentGrant($campaign->id, $pastGranted, $pastExpired);

        // Scope check
        $validGrantExists = FreemiumStudentGrant::validAt($this->nowUtc)
            ->where('id', $grant->id)
            ->exists();
        $this->assertFalse($validGrantExists, "Expired grant must not be valid");

        // Evaluator check
        $evalResult = $this->evaluator->evaluate($this->student, [], 'perm_mcqs', $this->nowUtc);
        $this->assertTrue($evalResult->isDenied(), "Student with expired grant must be denied");
    }

    /**
     * 5. Exact granted_at boundary is inclusive.
     */
    public function test_exact_granted_at_boundary_is_inclusive(): void
    {
        $campaign = $this->createGrantRequiredCampaign();
        $exactGranted = Carbon::parse('2026-11-21 10:00:00', 'UTC');
        $expires = Carbon::parse('2026-11-22 10:00:00', 'UTC');

        $grant = $this->createStudentGrant($campaign->id, $exactGranted, $expires);

        // 1 second before: Inactive
        $beforeGranted = $exactGranted->copy()->subSecond();
        $this->assertFalse(
            FreemiumStudentGrant::validAt($beforeGranted)->where('id', $grant->id)->exists(),
            "1 second before granted_at must be inactive"
        );

        // Exact second: Active (inclusive boundary)
        $this->assertTrue(
            FreemiumStudentGrant::validAt($exactGranted)->where('id', $grant->id)->exists(),
            "Exact granted_at second must be active (inclusive boundary)"
        );
    }

    /**
     * 6. Exact expires_at boundary is exclusive.
     */
    public function test_exact_expires_at_boundary_is_exclusive(): void
    {
        $campaign = $this->createGrantRequiredCampaign();
        $granted = Carbon::parse('2026-11-21 10:00:00', 'UTC');
        $exactExpires = Carbon::parse('2026-11-22 10:00:00', 'UTC');

        $grant = $this->createStudentGrant($campaign->id, $granted, $exactExpires);

        // 1 second before: Active
        $beforeExpires = $exactExpires->copy()->subSecond();
        $this->assertTrue(
            FreemiumStudentGrant::validAt($beforeExpires)->where('id', $grant->id)->exists(),
            "1 second before expires_at must be active"
        );

        // Exact second: Inactive (exclusive boundary)
        $this->assertFalse(
            FreemiumStudentGrant::validAt($exactExpires)->where('id', $grant->id)->exists(),
            "Exact expires_at second must be inactive (exclusive boundary)"
        );

        // 1 second after: Inactive
        $afterExpires = $exactExpires->copy()->addSecond();
        $this->assertFalse(
            FreemiumStudentGrant::validAt($afterExpires)->where('id', $grant->id)->exists(),
            "1 second after expires_at must be inactive"
        );
    }

    /**
     * 7. A grant issued within the last five hours is immediately recognized as valid.
     * (Critical regression scenario: previously, naive string binding in +05:00 session subtracted 5 hours from $now)
     */
    public function test_grant_issued_within_last_five_hours_is_immediately_recognized_as_valid(): void
    {
        $campaign = $this->createGrantRequiredCampaign();

        // Issued 2 hours ago (inside the 5-hour window)
        $grantedTwoHoursAgo = $this->nowUtc->copy()->subHours(2);
        $expiresTomorrow = $this->nowUtc->copy()->addDay();

        $grant = $this->createStudentGrant($campaign->id, $grantedTwoHoursAgo, $expiresTomorrow);

        $isValid = FreemiumStudentGrant::validAt($this->nowUtc)->where('id', $grant->id)->exists();
        $this->assertTrue(
            $isValid,
            "Grant issued 2 hours ago must be valid immediately (would fail under old +05:00 string binding defect)"
        );

        $evalResult = $this->evaluator->evaluate($this->student, [], 'perm_mcqs', $this->nowUtc);
        $this->assertTrue(
            $evalResult->isAllowed(),
            "Evaluator must allow access for grant issued 2 hours ago"
        );
    }

    /**
     * 8. A grant expired within the last five hours is immediately rejected.
     * (Critical regression scenario: previously, naive string binding evaluated expires_at > (now - 5h), falsely active)
     */
    public function test_grant_expired_within_last_five_hours_is_immediately_rejected(): void
    {
        $campaign = $this->createGrantRequiredCampaign();

        // Granted 10 hours ago, expired 2 hours ago (inside the 5-hour window)
        $grantedTenHoursAgo = $this->nowUtc->copy()->subHours(10);
        $expiredTwoHoursAgo = $this->nowUtc->copy()->subHours(2);

        $grant = $this->createStudentGrant($campaign->id, $grantedTenHoursAgo, $expiredTwoHoursAgo);

        $isValid = FreemiumStudentGrant::validAt($this->nowUtc)->where('id', $grant->id)->exists();
        $this->assertFalse(
            $isValid,
            "Grant expired 2 hours ago must be rejected immediately (would falsely pass under old +05:00 string binding defect)"
        );

        $evalResult = $this->evaluator->evaluate($this->student, [], 'perm_mcqs', $this->nowUtc);
        $this->assertTrue(
            $evalResult->isDenied(),
            "Evaluator must deny access for grant expired 2 hours ago"
        );
    }

    /**
     * 9. UTC and Asia/Karachi Carbon inputs representing the same instant produce identical results.
     */
    public function test_carbon_input_in_utc_and_asia_karachi_represent_same_instant(): void
    {
        $campaign = $this->createGrantRequiredCampaign();
        $granted = Carbon::parse('2026-11-21 09:00:00', 'UTC');
        $expires = Carbon::parse('2026-11-21 15:00:00', 'UTC');

        $grant = $this->createStudentGrant($campaign->id, $granted, $expires);

        // 12:00:00 UTC == 17:00:00 Asia/Karachi
        $evalUtc     = Carbon::parse('2026-11-21 12:00:00', 'UTC');
        $evalKarachi = Carbon::parse('2026-11-21 17:00:00', 'Asia/Karachi');

        $this->assertEquals($evalUtc->getTimestamp(), $evalKarachi->getTimestamp());

        $validUtc     = FreemiumStudentGrant::validAt($evalUtc)->where('id', $grant->id)->exists();
        $validKarachi = FreemiumStudentGrant::validAt($evalKarachi)->where('id', $grant->id)->exists();

        $this->assertTrue($validUtc);
        $this->assertTrue($validKarachi);
        $this->assertEquals($validUtc, $validKarachi, "Both timezones representing same instant must yield identical validity");
    }

    /**
     * 10. Existing campaign access and grant precedence remain unchanged.
     */
    public function test_existing_campaign_access_and_grant_precedence_remain_unchanged(): void
    {
        // Campaign without grant requirement (e.g. All Students)
        $openCampaign = FreemiumCampaign::create([
            'name'                 => 'Open Campaign ' . uniqid(),
            'status'               => 'active',
            'is_stackable'         => false,
            'priority'             => 50,
            'start_at'             => $this->nowUtc->copy()->subDays(5),
            'end_at'               => $this->nowUtc->copy()->addDays(5),
            'eligibility_criteria' => ['require_grant' => false],
            'created_by'           => $this->admin?->id,
            'updated_by'           => $this->admin?->id,
        ]);

        DB::table('freemium_campaigns')->where('id', $openCampaign->id)->update([
            'start_at' => DB::raw("FROM_UNIXTIME({$this->nowUtc->copy()->subDays(5)->getTimestamp()})"),
            'end_at'   => DB::raw("FROM_UNIXTIME({$this->nowUtc->copy()->addDays(5)->getTimestamp()})"),
        ]);

        FreemiumAccessRule::create([
            'campaign_id'              => $openCampaign->id,
            'target_scope'             => 'global',
            'target_status'            => 'active',
            'perm_mcqs'                => 'allow',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['scope' => 'global'],
        ]);

        // Student with no grants evaluates as ALLOWED under open campaign
        $evalResult = $this->evaluator->evaluate($this->student, [], 'perm_mcqs', $this->nowUtc);
        $this->assertTrue($evalResult->isAllowed(), "Student without grant must be allowed under open campaign");
        $this->assertEquals($openCampaign->id, $evalResult->getPrimaryCampaign()->id);
    }
}
