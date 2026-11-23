<?php

namespace Tests\Feature\Freemium\Student;

use App\Models\Freemium\FreemiumQuotaConsumption;
use App\Models\PracticeSession;
use App\Models\Test;
use App\Models\TestAttempt;
use App\Models\User;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stage 1E.2.D.D.1 — Recent Attempts API Security, Role Enforcement & Response Hardening Tests.
 *
 * Route: GET /web_api/auth/tests/get-recent-attempts
 */
class StudentRecentAttemptsApiTest extends TestCase
{
    protected WebUser $activeStudent;
    protected WebUser $secondStudent;
    protected WebUser $inactiveStudent;
    protected WebUser $teacherUser;
    protected User $adminUser;

    protected int $programId = 994;
    protected int $subjectId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $adminId = DB::table('users')->value('id');
        $this->adminUser = $adminId ? User::find($adminId) : User::create([
            'name'     => 'Admin DD1 User',
            'email'    => 'admin_dd1_' . uniqid() . '@example.com',
            'password' => bcrypt('Secret123'),
        ]);

        $this->activeStudent = WebUser::create([
            'name'     => 'Active Student DD1',
            'email'    => 'active_student_dd1_' . uniqid() . '@example.com',
            'password' => bcrypt('Secret123'),
            'role'     => 'student',
            'status'   => 'active',
        ]);

        $this->secondStudent = WebUser::create([
            'name'     => 'Second Student DD1',
            'email'    => 'second_student_dd1_' . uniqid() . '@example.com',
            'password' => bcrypt('Secret123'),
            'role'     => 'student',
            'status'   => 'active',
        ]);

        $this->inactiveStudent = WebUser::create([
            'name'     => 'Inactive Student DD1',
            'email'    => 'inactive_student_dd1_' . uniqid() . '@example.com',
            'password' => bcrypt('Secret123'),
            'role'     => 'student',
            'status'   => 'blocked',
        ]);

        $this->teacherUser = WebUser::create([
            'name'     => 'Teacher User DD1',
            'email'    => 'teacher_dd1_' . uniqid() . '@example.com',
            'password' => bcrypt('Secret123'),
            'role'     => 'teacher',
            'status'   => 'active',
        ]);

        // Ensure program_subjects link exists
        DB::table('program_subjects')->updateOrInsert(
            ['offered_program_id' => $this->programId, 'subject_id' => $this->subjectId],
            ['is_free' => 0, 'updated_at' => now()]
        );
    }

    protected function tearDown(): void
    {
        // Clean up any created attempts and sessions
        FreemiumQuotaConsumption::whereIn('user_id', [
            $this->activeStudent->id,
            $this->secondStudent->id,
            $this->inactiveStudent->id,
            $this->teacherUser->id,
        ])->delete();

        TestAttempt::whereIn('user_id', [
            $this->activeStudent->id,
            $this->secondStudent->id,
            $this->inactiveStudent->id,
            $this->teacherUser->id,
        ])->delete();

        PracticeSession::whereIn('user_id', [
            $this->activeStudent->id,
            $this->secondStudent->id,
            $this->inactiveStudent->id,
            $this->teacherUser->id,
        ])->delete();

        $this->activeStudent->delete();
        $this->secondStudent->delete();
        $this->inactiveStudent->delete();
        $this->teacherUser->delete();

        parent::tearDown();
    }

    /**
     * 1. Active student can retrieve their own recent attempts.
     */
    public function test_active_student_can_retrieve_their_own_recent_attempts(): void
    {
        $test = $this->createTest('Biology Midterm Test', 'test');
        $this->createTestAttempt($test, $this->activeStudent, 1, 8.5, 85.0, now()->subMinutes(10));
        $this->createPracticeSession($this->activeStudent, 7.0, 70.0, now()->subMinutes(5));

        $response = $this->withoutMiddleware([
            \App\Http\Middleware\AttachJwtFromCookie::class,
            \App\Http\Middleware\AuthenticateJwtCookieGuard::class,
        ])
            ->actingAs($this->activeStudent, 'web_api')
            ->getJson('/web_api/auth/tests/get-recent-attempts');

        $response->assertStatus(200);
        $response->assertJson(['success' => 1]);

        $data = $response->json('data');
        $this->assertCount(2, $data);

        // Verify chronological descending order (practice session was 5 min ago, test was 10 min ago)
        $this->assertSame('practice_session', $data[0]['attempt_type']);
        $this->assertSame('formal_test', $data[1]['attempt_type']);
    }

    /**
     * 2. Unauthenticated request is rejected.
     */
    public function test_unauthenticated_request_is_rejected(): void
    {
        // Calling without actingAs or token through middleware yields 401
        $response = $this->getJson('/web_api/auth/tests/get-recent-attempts');
        $response->assertStatus(401);

        // Calling with middleware disabled but no authenticated user yields 401 from controller guard
        $responseWithoutMw = $this->withoutMiddleware([
            \App\Http\Middleware\AttachJwtFromCookie::class,
            \App\Http\Middleware\AuthenticateJwtCookieGuard::class,
        ])->getJson('/web_api/auth/tests/get-recent-attempts');

        $responseWithoutMw->assertStatus(401);
        $responseWithoutMw->assertJson([
            'success' => 0,
            'message' => 'Unauthenticated.',
        ]);
    }

    /**
     * 3. Non-student role is rejected.
     */
    public function test_non_student_role_is_rejected(): void
    {
        $response = $this->withoutMiddleware([
            \App\Http\Middleware\AttachJwtFromCookie::class,
            \App\Http\Middleware\AuthenticateJwtCookieGuard::class,
        ])
            ->actingAs($this->teacherUser, 'web_api')
            ->getJson('/web_api/auth/tests/get-recent-attempts');

        $response->assertStatus(403);
        $response->assertJson([
            'success' => 0,
            'message' => 'Active student account required.',
        ]);
    }

    /**
     * 4. Inactive student is rejected.
     */
    public function test_inactive_student_is_rejected(): void
    {
        $response = $this->withoutMiddleware([
            \App\Http\Middleware\AttachJwtFromCookie::class,
            \App\Http\Middleware\AuthenticateJwtCookieGuard::class,
        ])
            ->actingAs($this->inactiveStudent, 'web_api')
            ->getJson('/web_api/auth/tests/get-recent-attempts');

        $response->assertStatus(403);
        $response->assertJson([
            'success' => 0,
            'message' => 'Active student account required.',
        ]);

        // Also test blocked student model attribute
        $this->assertSame('blocked', $this->inactiveStudent->status);
    }

    /**
     * 5. Attempts belonging to another student are never returned.
     */
    public function test_attempts_belonging_to_another_student_are_never_returned(): void
    {
        $sharedTest = $this->createTest('Shared Official Exam', 'test', 'official');

        // Student 1 attempt
        $this->createTestAttempt($sharedTest, $this->activeStudent, 1, 9.0, 90.0, now()->subMinutes(15));

        // Student 2 attempt on same test
        $this->createTestAttempt($sharedTest, $this->secondStudent, 1, 6.0, 60.0, now()->subMinutes(5));

        // Student 2 practice session
        $this->createPracticeSession($this->secondStudent, 5.0, 50.0, now()->subMinutes(2));

        $response = $this->withoutMiddleware([
            \App\Http\Middleware\AttachJwtFromCookie::class,
            \App\Http\Middleware\AuthenticateJwtCookieGuard::class,
        ])
            ->actingAs($this->activeStudent, 'web_api')
            ->getJson('/web_api/auth/tests/get-recent-attempts');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertSame(9.0, (float) $data[0]['score']);
        $this->assertSame(90.0, (float) $data[0]['accuracy']);
    }

    /**
     * 6. Attempt number is correct for Freemium and Paid students.
     */
    public function test_attempt_number_is_correct(): void
    {
        $test = $this->createTest('Multi Attempt Test', 'test');

        // Attempt 1 with Freemium ledger entry
        $att1 = $this->createTestAttempt($test, $this->activeStudent, 1, 7.0, 70.0, now()->subMinutes(30));
        FreemiumQuotaConsumption::create([
            'user_id'           => $this->activeStudent->id,
            'exam_type'         => 'formal_test',
            'event_type'        => 'attempt_start',
            'test_id'           => $test->id,
            'test_attempt_id'   => $att1->id,
            'attempt_number'    => 1,
            'decision_snapshot' => ['verified' => true],
            'consumed_at'       => now()->subMinutes(30),
        ]);

        // Attempt 2 with Freemium ledger entry
        $att2 = $this->createTestAttempt($test, $this->activeStudent, 2, 9.0, 90.0, now()->subMinutes(10));
        FreemiumQuotaConsumption::create([
            'user_id'           => $this->activeStudent->id,
            'exam_type'         => 'formal_test',
            'event_type'        => 'retake_start',
            'test_id'           => $test->id,
            'test_attempt_id'   => $att2->id,
            'attempt_number'    => 2,
            'decision_snapshot' => ['verified' => true],
            'consumed_at'       => now()->subMinutes(10),
        ]);

        // Practice session
        $this->createPracticeSession($this->activeStudent, 8.0, 80.0, now()->subMinutes(2));

        $response = $this->withoutMiddleware([
            \App\Http\Middleware\AttachJwtFromCookie::class,
            \App\Http\Middleware\AuthenticateJwtCookieGuard::class,
        ])
            ->actingAs($this->activeStudent, 'web_api')
            ->getJson('/web_api/auth/tests/get-recent-attempts');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(3, $data);

        // Item 0: Practice session -> attempt_number: 1
        $this->assertSame('practice_session', $data[0]['attempt_type']);
        $this->assertSame(1, $data[0]['attempt_number']);

        // Item 1: Attempt 2 -> attempt_number: 2
        $this->assertSame('formal_test', $data[1]['attempt_type']);
        $this->assertSame(2, $data[1]['attempt_number']);

        // Item 2: Attempt 1 -> attempt_number: 1
        $this->assertSame('formal_test', $data[2]['attempt_type']);
        $this->assertSame(1, $data[2]['attempt_number']);
    }

    /**
     * 7. Mock and formal test types are distinguished correctly.
     */
    public function test_mock_and_formal_test_types_are_distinguished_correctly(): void
    {
        $formalTest = $this->createTest('Physics Chapter 3 Test', 'test');
        $mockTest = $this->createTest('Physics Board Model Exam', 'mock');

        $this->createTestAttempt($formalTest, $this->activeStudent, 1, 8.0, 80.0, now()->subMinutes(20));
        $this->createTestAttempt($mockTest, $this->activeStudent, 1, 9.5, 95.0, now()->subMinutes(10));

        $response = $this->withoutMiddleware([
            \App\Http\Middleware\AttachJwtFromCookie::class,
            \App\Http\Middleware\AuthenticateJwtCookieGuard::class,
        ])
            ->actingAs($this->activeStudent, 'web_api')
            ->getJson('/web_api/auth/tests/get-recent-attempts');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(2, $data);

        // First item is Mock Exam (submitted 10 min ago)
        $this->assertSame('mock', $data[0]['test_mode']);
        $this->assertSame('Physics Board Model Exam', $data[0]['title']);
        $this->assertSame('Mock Exam: Physics Board Model Exam', $data[0]['display_title']);

        // Second item is Formal Test (submitted 20 min ago)
        $this->assertSame('test', $data[1]['test_mode']);
        $this->assertSame('Physics Chapter 3 Test', $data[1]['title']);
        $this->assertSame('Physics Chapter 3 Test', $data[1]['display_title']);
    }

    /**
     * 8. Empty history returns the established valid response.
     */
    public function test_empty_history_returns_established_valid_response(): void
    {
        $response = $this->withoutMiddleware([
            \App\Http\Middleware\AttachJwtFromCookie::class,
            \App\Http\Middleware\AuthenticateJwtCookieGuard::class,
        ])
            ->actingAs($this->activeStudent, 'web_api')
            ->getJson('/web_api/auth/tests/get-recent-attempts');

        $response->assertStatus(200);
        $response->assertExactJson([
            'success' => 1,
            'data'    => [],
        ]);
    }

    /**
     * 9. Existing response fields and compatibility are preserved.
     */
    public function test_existing_response_fields_and_compatibility_are_preserved(): void
    {
        $test = $this->createTest('Complete Chemistry Evaluation', 'test');
        $this->createTestAttempt($test, $this->activeStudent, 1, 7.5, 75.0, now()->subMinutes(5));

        $response = $this->withoutMiddleware([
            \App\Http\Middleware\AttachJwtFromCookie::class,
            \App\Http\Middleware\AuthenticateJwtCookieGuard::class,
        ])
            ->actingAs($this->activeStudent, 'web_api')
            ->getJson('/web_api/auth/tests/get-recent-attempts');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);

        $item = $data[0];

        // Assert all expected legacy keys exist
        $expectedKeys = [
            'id',
            'attempt_type',
            'attempt_number',
            'title',
            'display_title',
            'scope_type',
            'test_mode',
            'offered_program_id',
            'subject_id',
            'subject_name',
            'time_limit_minutes',
            'total_questions',
            'attempted_questions',
            'correct_answers',
            'wrong_answers',
            'not_attempted_questions',
            'score',
            'accuracy',
            'submitted_at',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $item, "Missing expected response key: {$key}");
        }

        // Verify data types
        $this->assertIsInt($item['id']);
        $this->assertIsInt($item['attempt_number']);
        $this->assertIsString($item['attempt_type']);
        $this->assertIsString($item['title']);
        $this->assertIsString($item['display_title']);
        $this->assertIsString($item['scope_type']);
        $this->assertIsString($item['test_mode']);
        $this->assertIsInt($item['offered_program_id']);
        $this->assertIsInt($item['subject_id']);
        $this->assertIsInt($item['total_questions']);
        $this->assertIsNumeric($item['score']);
        $this->assertIsNumeric($item['accuracy']);
        $this->assertIsString($item['submitted_at']);

        // Verify limit parameter enforcement
        $resLimit = $this->withoutMiddleware([
            \App\Http\Middleware\AttachJwtFromCookie::class,
            \App\Http\Middleware\AuthenticateJwtCookieGuard::class,
        ])
            ->actingAs($this->activeStudent, 'web_api')
            ->getJson('/web_api/auth/tests/get-recent-attempts?limit=25');

        $resLimit->assertStatus(422); // limit max 20
    }

    // --- Helper Methods ---

    protected function createTest(string $title, string $testMode = 'test', string $testSource = 'system'): Test
    {
        return Test::create([
            'offered_program_id' => $this->programId,
            'subject_id'         => $this->subjectId,
            'created_by'         => null,
            'title'              => $title,
            'test_source'        => $testSource,
            'test_mode'          => $testMode,
            'scope_type'         => 'chapter',
            'time_limit_minutes' => 30,
            'total_questions'    => 10,
            'is_published'       => true,
            'published_at'       => now(),
        ]);
    }

    protected function createTestAttempt(
        Test $test,
        WebUser $user,
        int $attemptNumber,
        float $score,
        float $accuracy,
        Carbon $submittedAt
    ): TestAttempt {
        return TestAttempt::create([
            'test_id'                 => $test->id,
            'user_id'                 => $user->id,
            'attempted_questions'     => 10,
            'correct_answers'         => (int) $score,
            'wrong_answers'           => 10 - (int) $score,
            'not_attempted_questions' => 0,
            'score'                   => $score,
            'accuracy'                => $accuracy,
            'status'                  => 'submitted',
            'started_at'              => $submittedAt->copy()->subMinutes(15),
            'submitted_at'            => $submittedAt,
        ]);
    }

    protected function createPracticeSession(
        WebUser $user,
        float $score,
        float $accuracy,
        Carbon $submittedAt
    ): PracticeSession {
        return PracticeSession::create([
            'user_id'                 => $user->id,
            'offered_program_id'      => $this->programId,
            'subject_id'              => $this->subjectId,
            'scope_type'              => 'chapter',
            'question_type'           => 'mcq',
            'time_limit_minutes'      => 30,
            'total_questions'         => 10,
            'attempted_questions'     => 10,
            'correct_answers'         => (int) $score,
            'wrong_answers'           => 10 - (int) $score,
            'not_attempted_questions' => 0,
            'score'                   => $score,
            'accuracy'                => $accuracy,
            'status'                  => 'submitted',
            'started_at'              => $submittedAt->copy()->subMinutes(15),
            'submitted_at'            => $submittedAt,
        ]);
    }
}
