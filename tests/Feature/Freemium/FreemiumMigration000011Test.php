<?php

namespace Tests\Feature\Freemium;

use App\Models\Freemium\FreemiumQuotaConsumption;
use App\Models\Test;
use App\Models\WebUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FreemiumMigration000011Test extends TestCase
{
    use DatabaseTransactions;

    private WebUser $student1;
    private WebUser $student2;
    private int $testId1;
    private int $testId2;

    protected function setUp(): void
    {
        parent::setUp();

        $activeDb = DB::selectOne("SELECT DATABASE() as db")->db ?? '';
        if ($activeDb !== '3peas_db_subscription_test' && $activeDb !== '3peas_db') {
            $this->markTestSkipped("Safety gate: test only runs against local databases.");
        }

        $this->student1 = WebUser::create([
            'name'     => 'Shared Test Student 1 ' . uniqid(),
            'email'    => 'shared_student_1_' . uniqid() . '@example.com',
            'role'     => 'student',
            'status'   => 'active',
            'password' => bcrypt('Secret123'),
        ]);

        $this->student2 = WebUser::create([
            'name'     => 'Shared Test Student 2 ' . uniqid(),
            'email'    => 'shared_student_2_' . uniqid() . '@example.com',
            'role'     => 'student',
            'status'   => 'active',
            'password' => bcrypt('Secret123'),
        ]);

        $programId = (int) (DB::table('offered_programs')->value('id') ?? 1);
        $subjectId = (int) (DB::table('subject_tbl')->value('id') ?? 1);

        $test1 = Test::create([
            'offered_program_id' => $programId,
            'subject_id'         => $subjectId,
            'title'              => 'Official Shared Test 1 ' . uniqid(),
            'test_source'        => 'official',
            'test_mode'          => 'test',
            'scope_type'         => 'chapter',
            'question_type'      => 'mcq',
            'total_questions'    => 10,
            'is_published'       => 1,
            'published_at'       => now(),
        ]);
        $this->testId1 = $test1->id;

        $test2 = Test::create([
            'offered_program_id' => $programId,
            'subject_id'         => $subjectId,
            'title'              => 'Official Shared Test 2 ' . uniqid(),
            'test_source'        => 'official',
            'test_mode'          => 'test',
            'scope_type'         => 'chapter',
            'question_type'      => 'mcq',
            'total_questions'    => 10,
            'is_published'       => 1,
            'published_at'       => now(),
        ]);
        $this->testId2 = $test2->id;
    }

    private function createConsumption(WebUser $user, int $testId, int $attemptNumber, string $eventType): FreemiumQuotaConsumption
    {
        return FreemiumQuotaConsumption::create([
            'user_id'             => $user->id,
            'exam_type'           => 'formal_test',
            'event_type'          => $eventType,
            'primary_campaign_id' => null,
            'primary_quota_id'    => null,
            'primary_grant_id'    => null,
            'test_id'             => $testId,
            'test_attempt_id'     => null,
            'practice_session_id' => null,
            'attempt_number'      => $attemptNumber,
            'idempotency_key'     => 'mig11_key_' . uniqid(),
            'decision_snapshot'   => ['tested' => true],
        ]);
    }

    /**
     * Requirement 1: Two different students can have the same attempt number and event type
     * for the same shared official test.
     */
    public function test_two_different_students_can_have_same_attempt_and_event_for_same_shared_official_test(): void
    {
        // Student 1 starts Attempt #1 on Official Test 1
        $c1 = $this->createConsumption($this->student1, $this->testId1, 1, 'attempt_start');
        $this->assertNotNull($c1->id);

        // Student 2 starts Attempt #1 on the EXACT SAME Official Test 1
        $c2 = $this->createConsumption($this->student2, $this->testId1, 1, 'attempt_start');
        $this->assertNotNull($c2->id);

        $this->assertSame($c1->test_id, $c2->test_id);
        $this->assertSame($c1->attempt_number, $c2->attempt_number);
        $this->assertSame($c1->event_type, $c2->event_type);
        $this->assertNotEquals($c1->user_id, $c2->user_id);
    }

    /**
     * Requirement 2: The same student cannot create a duplicate combination.
     */
    public function test_same_student_cannot_create_duplicate_combination(): void
    {
        // First insertion succeeds
        $this->createConsumption($this->student1, $this->testId1, 1, 'attempt_start');

        // Duplicate insertion for the same student, test, attempt_number, and event_type must violate unique constraint
        $this->expectException(QueryException::class);

        $this->createConsumption($this->student1, $this->testId1, 1, 'attempt_start');
    }

    /**
     * Requirement 3: Different attempt numbers for the same student remain valid.
     */
    public function test_different_attempt_numbers_for_same_student_remain_valid(): void
    {
        // Initial attempt (Attempt 1)
        $c1 = $this->createConsumption($this->student1, $this->testId1, 1, 'attempt_start');

        // Subsequent retake (Attempt 2)
        $c2 = $this->createConsumption($this->student1, $this->testId1, 2, 'retake_start');

        $this->assertNotNull($c1->id);
        $this->assertNotNull($c2->id);
        $this->assertSame(1, $c1->attempt_number);
        $this->assertSame(2, $c2->attempt_number);
    }

    /**
     * Requirement 4: Different tests remain independent.
     */
    public function test_different_tests_remain_independent(): void
    {
        // Attempt 1 on Test 1
        $c1 = $this->createConsumption($this->student1, $this->testId1, 1, 'attempt_start');

        // Attempt 1 on Test 2 by the same student
        $c2 = $this->createConsumption($this->student1, $this->testId2, 1, 'attempt_start');

        $this->assertNotNull($c1->id);
        $this->assertNotNull($c2->id);
        $this->assertNotEquals($c1->test_id, $c2->test_id);
        $this->assertSame($c1->attempt_number, $c2->attempt_number);
    }

    /**
     * Requirement 5: Rollback succeeds when no conflicting records exist.
     */
    public function test_rollback_succeeds_when_no_conflicting_records_exist(): void
    {
        // When only single-student records exist per (test_id, attempt_number, event_type), down() must succeed
        $this->createConsumption($this->student1, $this->testId1, 1, 'attempt_start');

        $migration = require database_path('migrations/2026_12_01_000011_update_freemium_test_attempt_unique_index.php');

        // Rollback down() should succeed cleanly without exception
        $migration->down();

        // Verify index state after down()
        $indexes = collect(DB::select("SHOW INDEX FROM freemium_quota_consumptions"))
            ->pluck('Key_name')
            ->unique()
            ->all();

        $this->assertContains('uk_test_attempt_event', $indexes);
        $this->assertNotContains('uk_test_user_attempt_event', $indexes);

        // Re-run up() to restore the standard migrated state for subsequent tests
        $migration->up();

        $restoredIndexes = collect(DB::select("SHOW INDEX FROM freemium_quota_consumptions"))
            ->pluck('Key_name')
            ->unique()
            ->all();

        $this->assertContains('uk_test_user_attempt_event', $restoredIndexes);
        $this->assertNotContains('uk_test_attempt_event', $restoredIndexes);
    }

    /**
     * Requirement 6: Rollback fails safely when multiple users share the same (test_id, attempt_number, event_type).
     */
    public function test_rollback_fails_safely_when_multiple_users_share_same_combination(): void
    {
        // Seed multi-student attempt records on the same shared test
        $this->createConsumption($this->student1, $this->testId1, 1, 'attempt_start');
        $this->createConsumption($this->student2, $this->testId1, 1, 'attempt_start');

        $migration = require database_path('migrations/2026_12_01_000011_update_freemium_test_attempt_unique_index.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot rollback migration [2026_12_01_000011_update_freemium_test_attempt_unique_index]');

        $migration->down();
    }

    /**
     * Requirement 7: Failed rollback does not delete or modify existing records.
     */
    public function test_failed_rollback_does_not_delete_or_modify_existing_records(): void
    {
        // Seed records
        $c1 = $this->createConsumption($this->student1, $this->testId1, 1, 'attempt_start');
        $c2 = $this->createConsumption($this->student2, $this->testId1, 1, 'attempt_start');

        $initialCount = FreemiumQuotaConsumption::whereIn('id', [$c1->id, $c2->id])->count();
        $this->assertSame(2, $initialCount);

        $migration = require database_path('migrations/2026_12_01_000011_update_freemium_test_attempt_unique_index.php');

        try {
            $migration->down();
            $this->fail("Expected RuntimeException was not thrown on conflicting rollback.");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Found 1 test attempt tuple(s) with multiple student records', $e->getMessage());
        }

        // Verify that zero records were deleted or altered
        $afterCount = FreemiumQuotaConsumption::whereIn('id', [$c1->id, $c2->id])->count();
        $this->assertSame(2, $afterCount, "Failed rollback must not delete or alter any consumption records.");

        $persisted1 = FreemiumQuotaConsumption::find($c1->id);
        $persisted2 = FreemiumQuotaConsumption::find($c2->id);

        $this->assertSame($this->student1->id, $persisted1->user_id);
        $this->assertSame($this->student2->id, $persisted2->user_id);
        $this->assertSame(1, $persisted1->attempt_number);
        $this->assertSame(1, $persisted2->attempt_number);

        // Verify index is still the student-scoped index (rollback was aborted prior to schema mutation)
        $indexes = collect(DB::select("SHOW INDEX FROM freemium_quota_consumptions"))
            ->pluck('Key_name')
            ->unique()
            ->all();

        $this->assertContains('uk_test_user_attempt_event', $indexes);
    }
}
