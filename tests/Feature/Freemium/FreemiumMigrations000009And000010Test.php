<?php

namespace Tests\Feature\Freemium;

use App\Models\AssessmentIdempotencyRecord;
use App\Models\Freemium\FreemiumQuotaConsumption;
use App\Models\WebUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FreemiumMigrations000009And000010Test extends TestCase
{
    use DatabaseTransactions;

    private WebUser $student;

    protected function setUp(): void
    {
        parent::setUp();

        $activeDb = DB::selectOne("SELECT DATABASE() as db")->db ?? '';
        if ($activeDb !== '3peas_db_subscription_test' && $activeDb !== '3peas_db') {
            $this->markTestSkipped("Safety gate: test only runs against local databases.");
        }

        $this->student = WebUser::firstOrCreate(
            ['email' => 'migration_test_student@example.com'],
            [
                'name'       => 'Migration Student',
                'role'       => 'student',
                'status'     => 'active',
                'password'   => bcrypt('Secret123'),
            ]
        );
    }

    private function getOrCreateTestId(): int
    {
        $testId = DB::table('tests')->value('id');
        if ($testId) {
            return (int) $testId;
        }

        $programId = DB::table('offered_programs')->value('id') ?? 1;
        $subjectId = DB::table('subject_tbl')->value('id') ?? 1;

        return (int) DB::table('tests')->insertGetId([
            'offered_program_id' => $programId,
            'subject_id'         => $subjectId,
            'title'              => 'Migration Test',
            'test_source'        => 'system',
            'test_mode'          => 'test',
            'scope_type'         => 'chapter',
            'question_type'      => 'mcq',
            'total_questions'    => 10,
            'is_published'       => 1,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    public function test_schema_has_nullable_campaign_and_quota_columns(): void
    {
        $cols = collect(DB::select('DESCRIBE freemium_quota_consumptions'))->keyBy('Field');

        $this->assertTrue($cols->has('primary_campaign_id'));
        $this->assertTrue($cols->has('primary_quota_id'));

        $this->assertSame('YES', $cols->get('primary_campaign_id')->Null);
        $this->assertSame('YES', $cols->get('primary_quota_id')->Null);
    }

    public function test_can_insert_consumption_record_with_null_campaign_and_quota(): void
    {
        // Must succeed without foreign key or not-null violations
        $consumption = FreemiumQuotaConsumption::create([
            'user_id'             => $this->student->id,
            'exam_type'           => 'formal_test',
            'event_type'          => 'generation',
            'primary_campaign_id' => null,
            'primary_quota_id'    => null,
            'primary_grant_id'    => null,
            'test_id'             => $this->getOrCreateTestId(),
            'test_attempt_id'     => null,
            'practice_session_id' => null,
            'attempt_number'      => 0,
            'idempotency_key'     => 'mig_test_key_' . uniqid(),
            'decision_snapshot'   => ['legacy' => true],
        ]);

        $this->assertNotNull($consumption->id);
        $this->assertNull($consumption->primary_campaign_id);
        $this->assertNull($consumption->primary_quota_id);
    }

    public function test_assessment_idempotency_records_table_exists_and_enforces_unique_constraint(): void
    {
        $this->assertTrue(Schema::hasTable('assessment_idempotency_records'));

        $key = 'test_idemp_key_' . uniqid();
        $op = 'formal_test_generation';

        // 1. First record succeeds
        $rec1 = AssessmentIdempotencyRecord::create([
            'user_id'             => $this->student->id,
            'operation'           => $op,
            'idempotency_key'     => $key,
            'request_fingerprint' => hash('sha256', 'payload1'),
            'status'              => 'processing',
        ]);

        $this->assertNotNull($rec1->id);

        // 2. Duplicate record with same user, operation, and key throws QueryException
        $this->expectException(QueryException::class);

        AssessmentIdempotencyRecord::create([
            'user_id'             => $this->student->id,
            'operation'           => $op,
            'idempotency_key'     => $key,
            'request_fingerprint' => hash('sha256', 'payload2'),
            'status'              => 'processing',
        ]);
    }

    public function test_different_students_can_use_same_idempotency_key(): void
    {
        $student2 = WebUser::firstOrCreate(
            ['email' => 'migration_student_2@example.com'],
            [
                'name'       => 'Student Two',
                'role'       => 'student',
                'status'     => 'active',
                'password'   => bcrypt('Secret123'),
            ]
        );

        $sharedKey = 'shared_key_' . uniqid();
        $op = 'formal_test_generation';

        $rec1 = AssessmentIdempotencyRecord::create([
            'user_id'             => $this->student->id,
            'operation'           => $op,
            'idempotency_key'     => $sharedKey,
            'request_fingerprint' => hash('sha256', 'p1'),
            'status'              => 'processing',
        ]);

        $rec2 = AssessmentIdempotencyRecord::create([
            'user_id'             => $student2->id,
            'operation'           => $op,
            'idempotency_key'     => $sharedKey,
            'request_fingerprint' => hash('sha256', 'p2'),
            'status'              => 'processing',
        ]);

        $this->assertNotNull($rec1->id);
        $this->assertNotNull($rec2->id);
        $this->assertNotEquals($rec1->user_id, $rec2->user_id);
    }

    public function test_different_operations_do_not_collide(): void
    {
        $key = 'multi_op_key_' . uniqid();

        $rec1 = AssessmentIdempotencyRecord::create([
            'user_id'             => $this->student->id,
            'operation'           => 'formal_test_generation',
            'idempotency_key'     => $key,
            'request_fingerprint' => hash('sha256', 'p1'),
            'status'              => 'processing',
        ]);

        $rec2 = AssessmentIdempotencyRecord::create([
            'user_id'             => $this->student->id,
            'operation'           => 'practice_session_generation',
            'idempotency_key'     => $key,
            'request_fingerprint' => hash('sha256', 'p2'),
            'status'              => 'processing',
        ]);

        $this->assertNotNull($rec1->id);
        $this->assertNotNull($rec2->id);
    }

    public function test_migration_000009_down_fails_closed_when_null_references_exist(): void
    {
        // Insert a record with null campaign
        FreemiumQuotaConsumption::create([
            'user_id'             => $this->student->id,
            'exam_type'           => 'formal_test',
            'event_type'          => 'generation',
            'primary_campaign_id' => null,
            'primary_quota_id'    => null,
            'primary_grant_id'    => null,
            'test_id'             => $this->getOrCreateTestId(),
            'test_attempt_id'     => null,
            'practice_session_id' => null,
            'attempt_number'      => 0,
            'idempotency_key'     => 'rollback_fail_key_' . uniqid(),
            'decision_snapshot'   => ['legacy' => true],
        ]);

        // Attempt down() on a fresh instance of the migration
        $migration = require database_path('migrations/2026_12_01_000009_make_freemium_campaign_keys_nullable.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot roll back migration [2026_12_01_000009]');

        $migration->down();
    }
}
