<?php

namespace Tests\Feature\Freemium;

use App\Http\Services\Freemium\AssessmentIdempotencyService;
use App\Http\Services\Freemium\Exceptions\IdempotencyConflictException;
use App\Models\AssessmentIdempotencyRecord;
use App\Models\WebUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AssessmentIdempotencyServiceTest extends TestCase
{
    use DatabaseTransactions;

    private AssessmentIdempotencyService $service;
    private WebUser $student1;
    private WebUser $student2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AssessmentIdempotencyService();

        $this->student1 = WebUser::firstOrCreate(
            ['email' => 'idemp_student_1@example.com'],
            ['name' => 'Idemp Student 1', 'role' => 'student', 'status' => 'active', 'password' => bcrypt('Secret123')]
        );

        $this->student2 = WebUser::firstOrCreate(
            ['email' => 'idemp_student_2@example.com'],
            ['name' => 'Idemp Student 2', 'role' => 'student', 'status' => 'active', 'password' => bcrypt('Secret123')]
        );
    }

    public function test_same_key_and_same_payload_replays_original_result(): void
    {
        $key = 'test_replay_key_' . uniqid();
        $op = 'formal_test_generation';
        $payload = ['program_id' => 1, 'subject_id' => 2, 'unit_ids' => [10, 20]];

        $executions = 0;
        $callback = function () use (&$executions) {
            $executions++;
            return [
                'payload' => ['success' => 1, 'data' => ['test_id' => 999]],
                'status'  => 200,
            ];
        };

        // First call executes callback
        $res1 = $this->service->execute($this->student1, $op, $key, $payload, $callback);
        $this->assertSame(1, $executions);
        $this->assertFalse($res1['replayed']);
        $this->assertSame(999, $res1['payload']['data']['test_id']);

        // Second call with same key and payload replays stored result (callback NOT executed)
        $res2 = $this->service->execute($this->student1, $op, $key, $payload, $callback);
        $this->assertSame(1, $executions, 'Callback should not be executed again on replay');
        $this->assertTrue($res2['replayed']);
        $this->assertSame(999, $res2['payload']['data']['test_id']);
    }

    public function test_same_key_with_different_payload_throws_409_conflict(): void
    {
        $key = 'test_conflict_key_' . uniqid();
        $op = 'formal_test_generation';
        $payload1 = ['program_id' => 1, 'subject_id' => 2, 'unit_ids' => [10, 20]];
        $payload2 = ['program_id' => 1, 'subject_id' => 2, 'unit_ids' => [10, 30]]; // Different unit

        $callback = fn () => ['payload' => ['success' => 1], 'status' => 200];

        // First call succeeds
        $this->service->execute($this->student1, $op, $key, $payload1, $callback);

        // Second call with same key but different payload throws IdempotencyConflictException
        $this->expectException(IdempotencyConflictException::class);
        $this->expectExceptionMessage("Idempotency key '{$key}' was already used with a different request payload.");

        $this->service->execute($this->student1, $op, $key, $payload2, $callback);
    }

    public function test_different_students_can_independently_use_same_key(): void
    {
        $sharedKey = 'shared_student_key_' . uniqid();
        $op = 'formal_test_generation';
        $payload = ['program_id' => 1, 'subject_id' => 2];

        $res1 = $this->service->execute($this->student1, $op, $sharedKey, $payload, fn () => [
            'payload' => ['student_id' => $this->student1->id],
            'status'  => 200,
        ]);

        $res2 = $this->service->execute($this->student2, $op, $sharedKey, $payload, fn () => [
            'payload' => ['student_id' => $this->student2->id],
            'status'  => 200,
        ]);

        $this->assertSame($this->student1->id, $res1['payload']['student_id']);
        $this->assertSame($this->student2->id, $res2['payload']['student_id']);
        $this->assertFalse($res1['replayed']);
        $this->assertFalse($res2['replayed']);
    }

    public function test_different_operations_do_not_collide_with_same_key(): void
    {
        $key = 'multi_op_key_' . uniqid();
        $payload = ['unit_id' => 5];

        $res1 = $this->service->execute($this->student1, 'formal_test_generation', $key, $payload, fn () => [
            'payload' => ['type' => 'formal_test'],
            'status'  => 200,
        ]);

        $res2 = $this->service->execute($this->student1, 'practice_session_generation', $key, $payload, fn () => [
            'payload' => ['type' => 'practice_session'],
            'status'  => 200,
        ]);

        $this->assertSame('formal_test', $res1['payload']['type']);
        $this->assertSame('practice_session', $res2['payload']['type']);
    }

    public function test_failed_operation_does_not_leave_completed_or_blocking_record(): void
    {
        $key = 'test_failure_key_' . uniqid();
        $op = 'formal_test_generation';
        $payload = ['unit_id' => 10];

        $attempt = 0;
        $callback = function () use (&$attempt) {
            $attempt++;
            if ($attempt === 1) {
                throw new \RuntimeException("Database deadlocked or generation failed");
            }
            return [
                'payload' => ['success' => 1, 'recovered' => true],
                'status'  => 200,
            ];
        };

        // First attempt fails
        try {
            $this->service->execute($this->student1, $op, $key, $payload, $callback);
            $this->fail("Expected RuntimeException was not thrown");
        } catch (\RuntimeException $e) {
            $this->assertSame("Database deadlocked or generation failed", $e->getMessage());
        }

        // Verify no completed record was left behind
        $rec = AssessmentIdempotencyRecord::where('user_id', $this->student1->id)
            ->where('operation', $op)
            ->where('idempotency_key', $key)
            ->first();

        $this->assertNull($rec, "Failed attempt should clean up the processing record");

        // Retry with same key now succeeds cleanly!
        $res = $this->service->execute($this->student1, $op, $key, $payload, $callback);
        $this->assertTrue($res['payload']['recovered']);
    }
}
