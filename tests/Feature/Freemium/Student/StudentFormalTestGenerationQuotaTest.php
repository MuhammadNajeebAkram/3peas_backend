<?php

namespace Tests\Feature\Freemium\Student;

use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumExamQuota;
use App\Models\Freemium\FreemiumQuotaConsumption;
use App\Models\Freemium\FreemiumQuotaCounter;
use App\Models\OfferedProgram;
use App\Models\ProgramSubject;
use App\Models\Test;
use App\Models\UserSubscription;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudentFormalTestGenerationQuotaTest extends TestCase
{
    use DatabaseTransactions;

    private WebUser $student;
    private int $programId;
    private int $subjectId;
    private int $bookId;
    private int $unitId;

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

        $this->student = WebUser::firstOrCreate(
            ['email' => 'formal_test_student_' . uniqid() . '@example.com'],
            [
                'name'     => 'Formal Test Student',
                'role'     => 'student',
                'status'   => 'active',
                'password' => bcrypt('Secret123'),
            ]
        );

        // Find or create valid test fixtures for subject 1 (Physics 9th)
        $this->subjectId = 1;
        $this->bookId = 1;
        $this->unitId = 1;

        // Use Program 994
        $this->programId = 994;

        // Ensure program_subjects link exists
        DB::table('program_subjects')->updateOrInsert(
            ['offered_program_id' => $this->programId, 'subject_id' => $this->subjectId],
            ['is_free' => 0, 'updated_at' => now()]
        );
    }

    public function test_missing_idempotency_key_returns_422(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-selected-units-formal-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 1,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => 0,
                'error'   => 'Missing required X-Idempotency-Key header.',
            ]);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->postJson('/web_api/auth/tests/generate-selected-units-formal-test', [
            'offered_program_id' => $this->programId,
            'subject_id'         => $this->subjectId,
            'unit_ids'           => [$this->unitId],
            'total_questions'    => 1,
        ], ['X-Idempotency-Key' => 'key_' . uniqid()]);

        $response->assertStatus(401);
    }

    public function test_unentitled_student_is_denied_with_403(): void
    {
        // Student has no subscription, no campaign, and is_free = 0
        DB::table('program_subjects')
            ->where('offered_program_id', $this->programId)
            ->where('subject_id', $this->subjectId)
            ->update(['is_free' => 0]);

        $key = 'unentitled_key_' . uniqid();

        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson('/web_api/auth/tests/generate-selected-units-formal-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 1,
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => 0,
            ]);

        // Assert zero tests created and zero quota ledger records
        $this->assertSame(0, FreemiumQuotaConsumption::where('user_id', $this->student->id)->count());
        $this->assertSame(0, Test::where('created_by', $this->student->id)->count());
    }

    public function test_paid_student_bypasses_freemium_quota_consumption(): void
    {
        // Create active subscription for this student covering Program 994
        UserSubscription::create([
            'user_id'            => $this->student->id,
            'offered_program_id' => $this->programId,
            'status'             => 'active',
            'start_date'         => now()->subDays(5),
            'end_date'           => now()->addDays(25),
        ]);

        $key = 'paid_test_key_' . uniqid();

        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson('/web_api/auth/tests/generate-selected-units-formal-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 1,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => 1,
            ]);

        $testId = $response->json('data.test_id');
        $this->assertNotNull($testId);

        // Verify test record exists and created_by is the student
        $test = Test::find($testId);
        $this->assertNotNull($test);
        $this->assertSame($this->student->id, (int) $test->created_by);

        // Crucial invariant: Paid access must bypass Freemium quota consumption entirely
        $consumptions = FreemiumQuotaConsumption::where('user_id', $this->student->id)->count();
        $this->assertSame(0, $consumptions, 'Paid students must NOT create freemium quota consumption rows.');

        $counters = FreemiumQuotaCounter::where('user_id', $this->student->id)->count();
        $this->assertSame(0, $counters, 'Paid students must NOT increment freemium quota counters.');
    }

    public function test_paid_scope_is_respected(): void
    {
        // Student is subscribed to Program 994, but requests Program 996 (not subscribed)
        UserSubscription::create([
            'user_id'            => $this->student->id,
            'offered_program_id' => $this->programId, // 994
            'status'             => 'active',
            'start_date'         => now()->subDays(5),
            'end_date'           => now()->addDays(25),
        ]);

        // Ensure Program 996 is not free
        DB::table('program_subjects')->updateOrInsert(
            ['offered_program_id' => 996, 'subject_id' => $this->subjectId],
            ['is_free' => 0]
        );

        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'scope_key_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-formal-test', [
                'offered_program_id' => 996,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 1,
            ]);

        $response->assertStatus(403);
    }

    public function test_legacy_free_student_receives_default_one_test_weekly_allowance(): void
    {
        // Ensure subject is free in legacy system
        DB::table('program_subjects')
            ->where('offered_program_id', $this->programId)
            ->where('subject_id', $this->subjectId)
            ->update(['is_free' => 1]);

        Config::set('freemium.fallback_quotas.formal_test', '1');

        $key1 = 'legacy_key_1_' . uniqid();

        // 1st generation succeeds
        $res1 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key1)
            ->postJson('/web_api/auth/tests/generate-selected-units-formal-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 1,
            ]);

        $res1->assertStatus(200)->assertJson(['success' => 1]);
        $test1Id = $res1->json('data.test_id');

        // Verify consumption row has NULL campaign and quota foreign keys
        $consumption = FreemiumQuotaConsumption::where('user_id', $this->student->id)
            ->where('test_id', $test1Id)
            ->first();

        $this->assertNotNull($consumption);
        $this->assertNull($consumption->primary_campaign_id);
        $this->assertNull($consumption->primary_quota_id);
        $this->assertSame('formal_test', $consumption->exam_type);
        $this->assertSame('generation', $consumption->event_type);
        $this->assertSame('legacy', $consumption->decision_snapshot['provider']);

        // Verify namespaced weekly counter equals 1
        $counter = FreemiumQuotaCounter::where('user_id', $this->student->id)
            ->where('exam_type', 'formal_test')
            ->where('window_type', 'weekly')
            ->first();

        $this->assertNotNull($counter);
        $this->assertSame(1, $counter->counter);
        $this->assertStringStartsWith('PK:legacy:weekly:', $counter->window_key);

        // 2nd generation in the same week fails with 403 QUOTA_EXHAUSTED
        $key2 = 'legacy_key_2_' . uniqid();
        $res2 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key2)
            ->postJson('/web_api/auth/tests/generate-selected-units-formal-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 1,
            ]);

        $res2->assertStatus(403)
            ->assertJson([
                'success'    => 0,
                'error_code' => 'QUOTA_EXHAUSTED',
            ]);
    }

    public function test_legacy_free_configured_quota_respected(): void
    {
        DB::table('program_subjects')
            ->where('offered_program_id', $this->programId)
            ->where('subject_id', $this->subjectId)
            ->update(['is_free' => 1]);

        // Allow 2 tests per week
        Config::set('freemium.fallback_quotas.formal_test', '2');

        // Generation 1 succeeds
        $res1 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'cfg_key_1_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-formal-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 1,
            ]);
        $res1->assertStatus(200);

        // Generation 2 succeeds
        $res2 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'cfg_key_2_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-formal-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 1,
            ]);
        $res2->assertStatus(200);

        // Generation 3 fails with 403 QUOTA_EXHAUSTED
        $res3 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'cfg_key_3_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-formal-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 1,
            ]);
        $res3->assertStatus(403)
            ->assertJson([
                'success'    => 0,
                'error_code' => 'QUOTA_EXHAUSTED',
            ]);
    }

    public function test_legacy_free_zero_quota_denies_generation(): void
    {
        DB::table('program_subjects')
            ->where('offered_program_id', $this->programId)
            ->where('subject_id', $this->subjectId)
            ->update(['is_free' => 1]);

        // Quota set to 0 (disabled by policy)
        Config::set('freemium.fallback_quotas.formal_test', '0');

        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'zero_quota_key_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-formal-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 1,
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'success'    => 0,
                'error_code' => 'QUOTA_EXHAUSTED',
            ]);
    }

    public function test_explicit_freemium_campaign_quota_enforced(): void
    {
        // Create an explicit campaign with a formal_test quota of 1
        $campaign = FreemiumCampaign::create([
            'name'       => 'Test Campaign ' . uniqid(),
            'code'       => 'CAMP_' . uniqid(),
            'priority'   => 1,
            'start_at'   => now()->subDays(2),
            'end_at'     => now()->addDays(10),
            'status'     => 'active',
            'is_default' => 0,
        ]);

        FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'formal_test',
            'window_type'           => 'weekly',
            'max_tests'             => 1,
            'allow_retakes'         => 0,
            'max_attempts_per_test' => 1,
        ]);

        // Grant access rule covering subject 1
        FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'target_scope'             => 'subject',
            'subject_id'               => $this->subjectId,
            'rule_priority'            => 1,
            'perm_mcqs'                => 'allow',
            'academic_target_snapshot' => ['subject_id' => $this->subjectId],
        ]);

        // 1st generation succeeds
        $res1 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'camp_key_1_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-formal-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 1,
            ]);
        $res1->assertStatus(200);

        // 2nd generation in same campaign window fails with 403 QUOTA_EXHAUSTED
        $res2 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'camp_key_2_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-formal-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 1,
            ]);
        $res2->assertStatus(403)->assertJson(['success' => 0, 'error_code' => 'QUOTA_EXHAUSTED']);
    }

    public function test_explicit_campaign_deny_does_not_fall_through_to_legacy(): void
    {
        // Make subject free in legacy system
        DB::table('program_subjects')
            ->where('offered_program_id', $this->programId)
            ->where('subject_id', $this->subjectId)
            ->update(['is_free' => 1]);

        // Create campaign with higher priority explicit DENY rule
        $campaign = FreemiumCampaign::create([
            'name'       => 'Deny Campaign ' . uniqid(),
            'code'       => 'DENY_' . uniqid(),
            'priority'   => 1,
            'start_at'   => now()->subDays(2),
            'end_at'     => now()->addDays(10),
            'status'     => 'active',
            'is_default' => 0,
        ]);

        FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'target_scope'             => 'subject',
            'subject_id'               => $this->subjectId,
            'rule_priority'            => 1,
            'perm_mcqs'                => 'deny',
            'academic_target_snapshot' => ['subject_id' => $this->subjectId],
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'deny_key_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-formal-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 1,
            ]);

        $response->assertStatus(403);
        $this->assertSame(0, FreemiumQuotaConsumption::where('user_id', $this->student->id)->count());
    }

    public function test_idempotency_same_key_and_payload_replays_original_test_response(): void
    {
        DB::table('program_subjects')
            ->where('offered_program_id', $this->programId)
            ->where('subject_id', $this->subjectId)
            ->update(['is_free' => 1]);

        Config::set('freemium.fallback_quotas.formal_test', '1');

        $key = 'replay_key_' . uniqid();
        $payload = [
            'offered_program_id' => $this->programId,
            'subject_id'         => $this->subjectId,
            'unit_ids'           => [$this->unitId],
            'total_questions'    => 1,
        ];

        // 1st request generates test
        $res1 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson('/web_api/auth/tests/generate-selected-units-formal-test', $payload);

        $res1->assertStatus(200);
        $test1Id = $res1->json('data.test_id');

        // 2nd request with same key and payload replays exact original response
        $res2 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson('/web_api/auth/tests/generate-selected-units-formal-test', $payload);

        $res2->assertStatus(200);
        $this->assertSame($test1Id, $res2->json('data.test_id'));

        // Assert no duplicate test created in tests table
        $totalTests = Test::where('created_by', $this->student->id)->count();
        $this->assertSame(1, $totalTests);

        // Assert quota counter was NOT incremented again
        $counter = FreemiumQuotaCounter::where('user_id', $this->student->id)->first();
        $this->assertSame(1, $counter->counter);
    }

    public function test_idempotency_same_key_different_payload_returns_409_conflict(): void
    {
        DB::table('program_subjects')
            ->where('offered_program_id', $this->programId)
            ->where('subject_id', $this->subjectId)
            ->update(['is_free' => 1]);

        $key = 'conflict_key_' . uniqid();

        // 1st request
        $res1 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson('/web_api/auth/tests/generate-selected-units-formal-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 1,
                'mcq_format'         => 'all',
            ]);
        $res1->assertStatus(200);

        // 2nd request with same key but different payload (different mcq_format)
        $res2 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson('/web_api/auth/tests/generate-selected-units-formal-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 1,
                'mcq_format'         => 'straight',
            ]);

        $res2->assertStatus(409)
            ->assertJson([
                'success'    => 0,
                'error_code' => 'IDEMPOTENCY_CONFLICT',
            ]);
    }

    public function test_full_book_formal_test_generation_enforces_quota_and_idempotency(): void
    {
        DB::table('program_subjects')
            ->where('offered_program_id', $this->programId)
            ->where('subject_id', $this->subjectId)
            ->update(['is_free' => 1]);

        Config::set('freemium.fallback_quotas.formal_test', '1');

        $key = 'full_book_key_' . uniqid();
        $payload = [
            'offered_program_id' => $this->programId,
            'subject_id'         => $this->subjectId,
            'book_id'            => $this->bookId,
            'total_questions'    => 1,
        ];

        // 1st call succeeds
        $res1 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson('/web_api/auth/tests/generate-full-book-formal-test', $payload);

        $res1->assertStatus(200)->assertJson(['success' => 1]);
        $testId = $res1->json('data.test_id');

        // Replay returns same test_id
        $res2 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson('/web_api/auth/tests/generate-full-book-formal-test', $payload);

        $res2->assertStatus(200);
        $this->assertSame($testId, $res2->json('data.test_id'));

        // New key fails with 403 QUOTA_EXHAUSTED
        $res3 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'full_book_key_2_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-full-book-formal-test', $payload);

        $res3->assertStatus(403)->assertJson(['success' => 0, 'error_code' => 'QUOTA_EXHAUSTED']);
    }

    public function test_failed_generation_rolls_back_quota_consumption(): void
    {
        DB::table('program_subjects')
            ->where('offered_program_id', $this->programId)
            ->where('subject_id', $this->subjectId)
            ->update(['is_free' => 1]);

        // Request 999 questions when only 1 exists
        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', 'insufficient_q_key_' . uniqid())
            ->postJson('/web_api/auth/tests/generate-selected-units-formal-test', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 999,
            ]);

        $response->assertStatus(422);

        // Invariant: Quota must not be consumed on failed generation
        $this->assertSame(0, FreemiumQuotaConsumption::where('user_id', $this->student->id)->count());
        $this->assertSame(0, FreemiumQuotaCounter::where('user_id', $this->student->id)->count());
    }
}
