<?php

namespace Tests\Feature\Freemium;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Validates native database engine constraints, generated columns, and foreign-key actions
 * on the 8 physical Freemium tables in MySQL 8.0+.
 */
class FreemiumDatabaseConstraintTest extends TestCase
{
    private int $adminId;
    private int $studentId;
    private int $testId;
    private int $practiceId;
    private int $campaignId;
    private int $quotaId;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Resolve or create parent fixtures
        $this->adminId = DB::table('users')->value('id') ?? DB::table('users')->insertGetId([
            'name'       => 'Freemium Test Admin',
            'email'      => 'admin_constraint_test_' . uniqid() . '@example.com',
            'password'   => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->studentId = DB::table('web_users')->insertGetId([
            'name'       => 'Freemium Test Student',
            'email'      => 'student_constraint_test_' . uniqid() . '@example.com',
            'password'   => bcrypt('secret'),
            'role'       => 'student',
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->testId = DB::table('tests')->value('id') ?? DB::table('tests')->insertGetId([
            'title'      => 'Freemium Constraint Test',
            'test_mode'  => 'test',
            'scope_type' => 'chapter',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->practiceId = DB::table('practice_sessions')->value('id') ?? DB::table('practice_sessions')->insertGetId([
            'user_id'    => $this->studentId,
            'scope_type' => 'chapter',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Create test campaign and quota
        $this->campaignId = DB::table('freemium_campaigns')->insertGetId([
            'name'       => 'Constraint Verification Campaign ' . uniqid(),
            'status'     => 'active',
            'start_at'   => now(),
            'end_at'     => now()->addDays(30),
            'created_by' => $this->adminId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->quotaId = DB::table('freemium_exam_quotas')->insertGetId([
            'campaign_id' => $this->campaignId,
            'exam_type'   => 'formal_test',
            'window_type' => 'daily',
            'max_tests'   => 5,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up test rows created during test execution
        DB::table('freemium_quota_consumption_envelopes')->delete();
        DB::table('freemium_quota_consumptions')->delete();
        DB::table('freemium_quota_counters')->where('user_id', $this->studentId)->delete();
        DB::table('freemium_access_rules')->where('campaign_id', $this->campaignId)->delete();
        DB::table('freemium_student_grants')->where('campaign_id', $this->campaignId)->delete();
        DB::table('freemium_exam_quotas')->where('campaign_id', $this->campaignId)->delete();
        DB::table('freemium_campaigns')->where('id', $this->campaignId)->delete();
        DB::table('web_users')->where('id', $this->studentId)->delete();

        parent::tearDown();
    }

    /**
     * Test CHECK constraint accepts valid test_id with null practice_session_id.
     */
    public function test_chk_exam_entity_mutex_accepts_test_id(): void
    {
        $id = DB::table('freemium_quota_consumptions')->insertGetId([
            'user_id'             => $this->studentId,
            'exam_type'           => 'formal_test',
            'event_type'          => 'attempt_start',
            'primary_campaign_id' => $this->campaignId,
            'primary_quota_id'    => $this->quotaId,
            'test_id'             => $this->testId,
            'practice_session_id' => null,
            'attempt_number'      => 1,
            'decision_snapshot'   => json_encode(['granted' => true]),
            'consumed_at'         => now(),
        ]);

        $this->assertGreaterThan(0, $id);
    }

    /**
     * Test CHECK constraint accepts valid practice_session_id with null test_id.
     */
    public function test_chk_exam_entity_mutex_accepts_practice_session_id(): void
    {
        $id = DB::table('freemium_quota_consumptions')->insertGetId([
            'user_id'             => $this->studentId,
            'exam_type'           => 'practice',
            'event_type'          => 'practice_generation',
            'primary_campaign_id' => $this->campaignId,
            'primary_quota_id'    => $this->quotaId,
            'test_id'             => null,
            'practice_session_id' => $this->practiceId,
            'attempt_number'      => 0,
            'decision_snapshot'   => json_encode(['granted' => true]),
            'consumed_at'         => now(),
        ]);

        $this->assertGreaterThan(0, $id);
    }

    /**
     * Test CHECK constraint rejects consumption when BOTH test_id and practice_session_id are populated.
     */
    public function test_chk_exam_entity_mutex_rejects_both_ids_populated(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/chk_exam_entity_mutex/i');

        DB::table('freemium_quota_consumptions')->insert([
            'user_id'             => $this->studentId,
            'exam_type'           => 'formal_test',
            'event_type'          => 'attempt_start',
            'primary_campaign_id' => $this->campaignId,
            'primary_quota_id'    => $this->quotaId,
            'test_id'             => $this->testId,
            'practice_session_id' => $this->practiceId,
            'attempt_number'      => 1,
            'decision_snapshot'   => json_encode(['granted' => true]),
            'consumed_at'         => now(),
        ]);
    }

    /**
     * Test CHECK constraint rejects consumption when NEITHER test_id nor practice_session_id is populated.
     */
    public function test_chk_exam_entity_mutex_rejects_neither_id_populated(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/chk_exam_entity_mutex/i');

        DB::table('freemium_quota_consumptions')->insert([
            'user_id'             => $this->studentId,
            'exam_type'           => 'formal_test',
            'event_type'          => 'attempt_start',
            'primary_campaign_id' => $this->campaignId,
            'primary_quota_id'    => $this->quotaId,
            'test_id'             => null,
            'practice_session_id' => null,
            'attempt_number'      => 1,
            'decision_snapshot'   => json_encode(['granted' => true]),
            'consumed_at'         => now(),
        ]);
    }

    /**
     * Test stored generated columns (entity_type, entity_id) evaluate accurately.
     */
    public function test_stored_generated_columns_evaluate_accurately(): void
    {
        // 1. Test record
        $id1 = DB::table('freemium_quota_consumptions')->insertGetId([
            'user_id'             => $this->studentId,
            'exam_type'           => 'formal_test',
            'event_type'          => 'attempt_start',
            'primary_campaign_id' => $this->campaignId,
            'primary_quota_id'    => $this->quotaId,
            'test_id'             => $this->testId,
            'practice_session_id' => null,
            'attempt_number'      => 1,
            'decision_snapshot'   => json_encode(['granted' => true]),
            'consumed_at'         => now(),
        ]);

        $row1 = DB::table('freemium_quota_consumptions')->where('id', $id1)->first();
        $this->assertSame('test', $row1->entity_type);
        $this->assertEquals($this->testId, $row1->entity_id);

        // 2. Practice record
        $id2 = DB::table('freemium_quota_consumptions')->insertGetId([
            'user_id'             => $this->studentId,
            'exam_type'           => 'practice',
            'event_type'          => 'practice_generation',
            'primary_campaign_id' => $this->campaignId,
            'primary_quota_id'    => $this->quotaId,
            'test_id'             => null,
            'practice_session_id' => $this->practiceId,
            'attempt_number'      => 0,
            'decision_snapshot'   => json_encode(['granted' => true]),
            'consumed_at'         => now(),
        ]);

        $row2 = DB::table('freemium_quota_consumptions')->where('id', $id2)->first();
        $this->assertSame('practice_session', $row2->entity_type);
        $this->assertEquals($this->practiceId, $row2->entity_id);
    }

    /**
     * Test unique counter window constraint uk_user_exam_window blocks duplicate rows.
     */
    public function test_quota_counters_unique_constraint(): void
    {
        DB::table('freemium_quota_counters')->insert([
            'user_id'     => $this->studentId,
            'exam_type'   => 'formal_test',
            'window_type' => 'daily',
            'window_key'  => 'PK:2026-11-18',
            'counter'     => 1,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/Duplicate entry.*uk_user_exam_window/i');

        DB::table('freemium_quota_counters')->insert([
            'user_id'     => $this->studentId,
            'exam_type'   => 'formal_test',
            'window_type' => 'daily',
            'window_key'  => 'PK:2026-11-18',
            'counter'     => 2,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    /**
     * Test unique attempt event constraint uk_test_attempt_event prevents duplicate attempts.
     */
    public function test_unique_test_attempt_event_constraint(): void
    {
        DB::table('freemium_quota_consumptions')->insert([
            'user_id'             => $this->studentId,
            'exam_type'           => 'formal_test',
            'event_type'          => 'attempt_start',
            'primary_campaign_id' => $this->campaignId,
            'primary_quota_id'    => $this->quotaId,
            'test_id'             => $this->testId,
            'practice_session_id' => null,
            'attempt_number'      => 1,
            'decision_snapshot'   => json_encode(['granted' => true]),
            'consumed_at'         => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/Duplicate entry.*(uk_test_attempt_event|uk_test_user_attempt_event)/i');

        DB::table('freemium_quota_consumptions')->insert([
            'user_id'             => $this->studentId,
            'exam_type'           => 'formal_test',
            'event_type'          => 'attempt_start',
            'primary_campaign_id' => $this->campaignId,
            'primary_quota_id'    => $this->quotaId,
            'test_id'             => $this->testId,
            'practice_session_id' => null,
            'attempt_number'      => 1,
            'decision_snapshot'   => json_encode(['granted' => true]),
            'consumed_at'         => now(),
        ]);
    }

    /**
     * Test unique idempotency key constraint uk_user_idempotency prevents duplicate deductions.
     */
    public function test_unique_user_idempotency_constraint(): void
    {
        $idempotencyKey = 'unique-req-uuid-' . uniqid();

        DB::table('freemium_quota_consumptions')->insert([
            'user_id'             => $this->studentId,
            'exam_type'           => 'formal_test',
            'event_type'          => 'attempt_start',
            'primary_campaign_id' => $this->campaignId,
            'primary_quota_id'    => $this->quotaId,
            'test_id'             => $this->testId,
            'practice_session_id' => null,
            'attempt_number'      => 1,
            'idempotency_key'     => $idempotencyKey,
            'decision_snapshot'   => json_encode(['granted' => true]),
            'consumed_at'         => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/Duplicate entry.*uk_user_idempotency/i');

        DB::table('freemium_quota_consumptions')->insert([
            'user_id'             => $this->studentId,
            'exam_type'           => 'formal_test',
            'event_type'          => 'retake_start',
            'primary_campaign_id' => $this->campaignId,
            'primary_quota_id'    => $this->quotaId,
            'test_id'             => $this->testId,
            'practice_session_id' => null,
            'attempt_number'      => 2,
            'idempotency_key'     => $idempotencyKey,
            'decision_snapshot'   => json_encode(['granted' => true]),
            'consumed_at'         => now(),
        ]);
    }

    /**
     * Test multi-campaign consumption envelopes unique constraint.
     */
    public function test_consumption_envelopes_unique_constraint(): void
    {
        $consumptionId = DB::table('freemium_quota_consumptions')->insertGetId([
            'user_id'             => $this->studentId,
            'exam_type'           => 'formal_test',
            'event_type'          => 'attempt_start',
            'primary_campaign_id' => $this->campaignId,
            'primary_quota_id'    => $this->quotaId,
            'test_id'             => $this->testId,
            'practice_session_id' => null,
            'attempt_number'      => 1,
            'decision_snapshot'   => json_encode(['granted' => true]),
            'consumed_at'         => now(),
        ]);

        DB::table('freemium_quota_consumption_envelopes')->insert([
            'consumption_id'                  => $consumptionId,
            'campaign_id'                     => $this->campaignId,
            'quota_id'                        => $this->quotaId,
            'window_type'                     => 'daily',
            'window_key'                      => 'PK:2026-11-18',
            'counter_value_after_consumption' => 1,
            'allocated_at'                    => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/Duplicate entry.*uk_consumption_envelope/i');

        DB::table('freemium_quota_consumption_envelopes')->insert([
            'consumption_id'                  => $consumptionId,
            'campaign_id'                     => $this->campaignId,
            'quota_id'                        => $this->quotaId,
            'window_type'                     => 'daily',
            'window_key'                      => 'PK:2026-11-18',
            'counter_value_after_consumption' => 1,
            'allocated_at'                    => now(),
        ]);
    }

    /**
     * Test foreign-key RESTRICT prevents deleting campaign referenced in consumptions or quotas.
     */
    public function test_campaign_deletion_blocked_by_restrict_foreign_key(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/Cannot delete or update a parent row.*foreign key constraint fails/i');

        DB::table('freemium_campaigns')->where('id', $this->campaignId)->delete();
    }

    /**
     * Test foreign-key CASCADE deletes student counters when student is deleted.
     */
    public function test_quota_counters_cascade_delete_on_student_deletion(): void
    {
        $tempStudentId = DB::table('web_users')->insertGetId([
            'name'       => 'Cascade Test Student',
            'email'      => 'cascade_' . uniqid() . '@example.com',
            'password'   => bcrypt('secret'),
            'role'       => 'student',
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('freemium_quota_counters')->insert([
            'user_id'     => $tempStudentId,
            'exam_type'   => 'formal_test',
            'window_type' => 'daily',
            'window_key'  => 'PK:2026-11-18',
            'counter'     => 1,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->assertEquals(1, DB::table('freemium_quota_counters')->where('user_id', $tempStudentId)->count());

        // Delete parent student
        DB::table('web_users')->where('id', $tempStudentId)->delete();

        // Verify child counters row was automatically cascaded
        $this->assertEquals(0, DB::table('freemium_quota_counters')->where('user_id', $tempStudentId)->count());
    }
}
