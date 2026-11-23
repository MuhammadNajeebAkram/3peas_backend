<?php

namespace Tests\Feature\Freemium\Student;

use App\Models\ExamQuestion;
use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumExamQuota;
use App\Models\Freemium\FreemiumQuotaConsumption;
use App\Models\Freemium\FreemiumQuotaCounter;
use App\Models\PracticeSession;
use App\Models\PracticeSessionQuestion;
use App\Models\UserSubscription;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudentPracticeSessionAccountingTest extends TestCase
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
            ['email' => 'practice_test_student_' . uniqid() . '@example.com'],
            [
                'name'     => 'Practice Test Student',
                'role'     => 'student',
                'status'   => 'active',
                'password' => bcrypt('Secret123'),
            ]
        );

        $this->subjectId = 1;
        $this->bookId = 1;
        $this->unitId = 1;
        $this->programId = 994;

        DB::table('program_subjects')->updateOrInsert(
            ['offered_program_id' => $this->programId, 'subject_id' => $this->subjectId],
            ['is_free' => 0, 'updated_at' => now()]
        );
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
            'offered_program_id' => $this->programId,
            'subject_id'         => $this->subjectId,
            'unit_ids'           => [$this->unitId],
            'total_questions'    => 1,
        ], ['X-Idempotency-Key' => 'key_' . uniqid()]);

        $response->assertStatus(401);
    }

    public function test_missing_idempotency_key_returns_422(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
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

    public function test_unentitled_student_is_denied_with_403(): void
    {
        DB::table('program_subjects')
            ->where('offered_program_id', $this->programId)
            ->where('subject_id', $this->subjectId)
            ->update(['is_free' => 0]);

        $key = 'practice_unentitled_' . uniqid();

        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 1,
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => 0,
            ]);

        $this->assertSame(0, FreemiumQuotaConsumption::where('user_id', $this->student->id)->count());
        $this->assertSame(0, PracticeSession::where('user_id', $this->student->id)->count());
    }

    public function test_paid_student_bypasses_freemium_quota_consumption(): void
    {
        UserSubscription::create([
            'user_id'            => $this->student->id,
            'offered_program_id' => $this->programId,
            'status'             => 'active',
            'start_date'         => now()->subDays(5),
            'end_date'           => now()->addDays(25),
        ]);

        $key = 'practice_paid_key_' . uniqid();

        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 1,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => 1,
                'data'    => [
                    'offered_program_id' => $this->programId,
                    'subject_id'         => $this->subjectId,
                    'status'             => 'in_progress',
                ],
            ]);

        // Paid student must NOT generate any FreemiumQuotaConsumption records
        $this->assertSame(0, FreemiumQuotaConsumption::where('user_id', $this->student->id)->count());
        $this->assertSame(1, PracticeSession::where('user_id', $this->student->id)->count());
    }

    public function test_legacy_free_configured_quota_respected(): void
    {
        DB::table('program_subjects')
            ->where('offered_program_id', $this->programId)
            ->where('subject_id', $this->subjectId)
            ->update(['is_free' => 1]);

        Config::set('freemium.fallback_quotas.practice', '2');

        // Session 1: succeeds
        $key1 = 'legacy_practice_1_' . uniqid();
        $res1 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key1)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 1,
            ]);

        $res1->assertStatus(200);
        $sessionId1 = $res1->json('data.session_id');

        // Submit session 1 so it's not active anymore (allowing a new session to be created)
        PracticeSession::where('id', $sessionId1)->update(['status' => 'submitted']);

        // Session 2: succeeds (different unit to distinguish or different session)
        $key2 = 'legacy_practice_2_' . uniqid();
        $res2 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key2)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 1,
            ]);

        $res2->assertStatus(200);
        $sessionId2 = $res2->json('data.session_id');
        PracticeSession::where('id', $sessionId2)->update(['status' => 'submitted']);

        // Session 3: rejected with 403 (quota exhausted)
        $key3 = 'legacy_practice_3_' . uniqid();
        $res3 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key3)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
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

        // Exactly 2 quota consumptions
        $this->assertSame(2, FreemiumQuotaConsumption::where('user_id', $this->student->id)->count());
        $this->assertSame(2, PracticeSession::where('user_id', $this->student->id)->count());

        $consumption = FreemiumQuotaConsumption::where('user_id', $this->student->id)->first();
        $this->assertSame('practice', $consumption->exam_type);
        $this->assertSame('practice_generation', $consumption->event_type);
        $this->assertNotNull($consumption->practice_session_id);
        $this->assertNull($consumption->test_id);
    }

    public function test_legacy_free_zero_quota_denies_generation(): void
    {
        DB::table('program_subjects')
            ->where('offered_program_id', $this->programId)
            ->where('subject_id', $this->subjectId)
            ->update(['is_free' => 1]);

        Config::set('freemium.fallback_quotas.practice', '0');

        $key = 'practice_zero_quota_' . uniqid();

        $response = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
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

        $this->assertSame(0, PracticeSession::where('user_id', $this->student->id)->count());
    }

    public function test_explicit_freemium_campaign_quota_enforced(): void
    {
        $campaign = FreemiumCampaign::create([
            'name'       => 'Test Practice Campaign ' . uniqid(),
            'code'       => 'PRACTICE_' . uniqid(),
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
            'perm_mcqs'                => 'allow',
            'academic_target_snapshot' => ['subject_id' => $this->subjectId],
        ]);

        FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'practice',
            'window_type'           => 'weekly',
            'max_tests'             => 1,
            'allow_retakes'         => 0,
            'max_attempts_per_test' => 1,
        ]);

        // 1st generation succeeds
        $key1 = 'camp_practice_1_' . uniqid();
        $res1 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key1)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 1,
            ]);

        $res1->assertStatus(200);
        $sessionId1 = $res1->json('data.session_id');
        PracticeSession::where('id', $sessionId1)->update(['status' => 'submitted']);

        // 2nd generation blocked by campaign quota
        $key2 = 'camp_practice_2_' . uniqid();
        $res2 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key2)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
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

    public function test_active_session_resumption_returns_200_without_consuming_quota(): void
    {
        DB::table('program_subjects')
            ->where('offered_program_id', $this->programId)
            ->where('subject_id', $this->subjectId)
            ->update(['is_free' => 1]);

        Config::set('freemium.fallback_quotas.practice', '1');

        // Initial generation: creates session, consumes 1 quota
        $key1 = 'resumption_init_' . uniqid();
        $res1 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key1)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 1,
            ]);

        $res1->assertStatus(200);
        $originalSessionId = $res1->json('data.session_id');
        $this->assertNotNull($originalSessionId);
        $this->assertSame(1, FreemiumQuotaConsumption::where('user_id', $this->student->id)->count());

        // Subsequent generation with same scope (and different key): must reuse active session and NOT fail quota check
        $key2 = 'resumption_second_' . uniqid();
        $res2 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key2)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 1,
            ]);

        $res2->assertStatus(200)
            ->assertJson([
                'success' => 1,
                'data'    => [
                    'session_id' => $originalSessionId,
                    'status'     => 'in_progress',
                ],
            ]);

        // Still exactly 1 session and 1 consumption record (zero quota consumed on resumption)
        $this->assertSame(1, PracticeSession::where('user_id', $this->student->id)->count());
        $this->assertSame(1, FreemiumQuotaConsumption::where('user_id', $this->student->id)->count());
    }

    public function test_idempotency_same_key_and_payload_replays_original_session(): void
    {
        DB::table('program_subjects')
            ->where('offered_program_id', $this->programId)
            ->where('subject_id', $this->subjectId)
            ->update(['is_free' => 1]);

        $key = 'replay_practice_key_' . uniqid();
        $payload = [
            'offered_program_id' => $this->programId,
            'subject_id'         => $this->subjectId,
            'unit_ids'           => [$this->unitId],
            'total_questions'    => 1,
        ];

        $res1 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', $payload);

        $res1->assertStatus(200);
        $sessionId = $res1->json('data.session_id');

        // Replay with exact same key and payload
        $res2 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', $payload);

        $res2->assertStatus(200)
            ->assertJson([
                'success' => 1,
                'data'    => [
                    'session_id' => $sessionId,
                ],
            ]);

        $this->assertSame(1, PracticeSession::where('user_id', $this->student->id)->count());
        $this->assertSame(1, FreemiumQuotaConsumption::where('user_id', $this->student->id)->count());
    }

    public function test_idempotency_same_key_different_payload_returns_409_conflict(): void
    {
        DB::table('program_subjects')
            ->where('offered_program_id', $this->programId)
            ->where('subject_id', $this->subjectId)
            ->update(['is_free' => 1]);

        $key = 'conflict_practice_key_' . uniqid();

        $res1 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 1,
            ]);

        $res1->assertStatus(200);

        // Conflict: same key, different question count
        $res2 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 2,
            ]);

        $res2->assertStatus(409)
            ->assertJson([
                'success'    => 0,
                'error_code' => 'IDEMPOTENCY_CONFLICT',
            ]);
    }

    public function test_full_book_practice_session_generation_enforces_quota_and_idempotency(): void
    {
        DB::table('program_subjects')
            ->where('offered_program_id', $this->programId)
            ->where('subject_id', $this->subjectId)
            ->update(['is_free' => 1]);

        Config::set('freemium.fallback_quotas.practice', '1');

        $key1 = 'full_book_practice_' . uniqid();
        $payload = [
            'offered_program_id' => $this->programId,
            'subject_id'         => $this->subjectId,
            'book_id'            => $this->bookId,
            'total_questions'    => 1,
        ];

        $res1 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key1)
            ->postJson('/web_api/auth/tests/generate-full-book-practice-session', $payload);

        $res1->assertStatus(200)
            ->assertJson([
                'success' => 1,
                'data'    => [
                    'scope_type' => 'full_book',
                    'book_id'    => $this->bookId,
                    'status'     => 'in_progress',
                ],
            ]);

        // Replay with same key
        $res2 = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key1)
            ->postJson('/web_api/auth/tests/generate-full-book-practice-session', $payload);

        $res2->assertStatus(200);
        $this->assertSame($res1->json('data.session_id'), $res2->json('data.session_id'));
    }

    public function test_save_progress_fails_closed_404_on_foreign_session(): void
    {
        $otherStudent = WebUser::create([
            'email'    => 'other_student_' . uniqid() . '@example.com',
            'name'     => 'Other Student',
            'role'     => 'student',
            'status'   => 'active',
            'password' => bcrypt('Secret123'),
        ]);

        $session = PracticeSession::create([
            'user_id'            => $otherStudent->id,
            'offered_program_id' => $this->programId,
            'subject_id'         => $this->subjectId,
            'scope_type'         => 'chapter',
            'question_type'      => 'mcq',
            'total_questions'    => 1,
            'status'             => 'in_progress',
            'started_at'         => now(),
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/save-practice-session-question-progress', [
                'session_id'  => $session->id,
                'question_id' => 1,
            ]);

        $response->assertStatus(404)
            ->assertJson([
                'success' => 0,
            ]);
    }

    public function test_save_progress_fails_closed_422_on_submitted_session(): void
    {
        $session = PracticeSession::create([
            'user_id'            => $this->student->id,
            'offered_program_id' => $this->programId,
            'subject_id'         => $this->subjectId,
            'scope_type'         => 'chapter',
            'question_type'      => 'mcq',
            'total_questions'    => 1,
            'status'             => 'submitted',
            'started_at'         => now()->subHour(),
            'submitted_at'       => now(),
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/save-practice-session-question-progress', [
                'session_id'  => $session->id,
                'question_id' => 1,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => 0,
                'error'   => 'Cannot update progress on an already submitted practice session.',
            ]);
    }

    public function test_submit_practice_session_fails_closed_404_on_foreign_session(): void
    {
        $otherStudent = WebUser::create([
            'email'    => 'other_student_sub_' . uniqid() . '@example.com',
            'name'     => 'Other Student Sub',
            'role'     => 'student',
            'status'   => 'active',
            'password' => bcrypt('Secret123'),
        ]);

        $session = PracticeSession::create([
            'user_id'            => $otherStudent->id,
            'offered_program_id' => $this->programId,
            'subject_id'         => $this->subjectId,
            'scope_type'         => 'chapter',
            'question_type'      => 'mcq',
            'total_questions'    => 1,
            'status'             => 'in_progress',
            'started_at'         => now(),
        ]);

        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/submit-practice-session', [
                'session_id' => $session->id,
            ]);

        $response->assertStatus(404)
            ->assertJson([
                'success' => 0,
            ]);
    }

    public function test_submit_practice_session_succeeds_and_repeated_submission_replays_stored_metrics(): void
    {
        DB::table('program_subjects')
            ->where('offered_program_id', $this->programId)
            ->where('subject_id', $this->subjectId)
            ->update(['is_free' => 1]);

        $key = 'submit_replay_key_' . uniqid();

        $genRes = $this->actingAs($this->student, 'web_api')
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson('/web_api/auth/tests/generate-selected-units-practice-session', [
                'offered_program_id' => $this->programId,
                'subject_id'         => $this->subjectId,
                'unit_ids'           => [$this->unitId],
                'total_questions'    => 1,
            ]);

        $genRes->assertStatus(200);
        $sessionId = $genRes->json('data.session_id');
        $questionId = $genRes->json('data.question_ids.0');

        // First submission:
        $subRes1 = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/submit-practice-session', [
                'session_id' => $sessionId,
                'questions'  => [
                    [
                        'question_id'        => $questionId,
                        'selected_option_id' => null,
                        'time_spent_seconds' => 15,
                    ],
                ],
            ]);

        $subRes1->assertStatus(200)
            ->assertJson([
                'success' => 1,
                'data'    => [
                    'session_id' => $sessionId,
                    'status'     => 'submitted',
                ],
            ]);

        $score1 = $subRes1->json('data.score');
        $accuracy1 = $subRes1->json('data.accuracy');

        // Repeated submission: idempotent replay returning same stored metrics
        $subRes2 = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/auth/tests/submit-practice-session', [
                'session_id' => $sessionId,
            ]);

        $subRes2->assertStatus(200)
            ->assertJson([
                'success' => 1,
                'data'    => [
                    'session_id' => $sessionId,
                    'score'      => $score1,
                    'accuracy'   => $accuracy1,
                    'status'     => 'submitted',
                ],
            ]);
    }
}
