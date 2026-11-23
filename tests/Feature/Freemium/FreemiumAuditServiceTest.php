<?php

namespace Tests\Feature\Freemium;

use App\Http\Services\Freemium\FreemiumAuditService;
use App\Models\Freemium\FreemiumAuditLog;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class FreemiumAuditServiceTest extends TestCase
{
    private FreemiumAuditService $auditService;
    private User $admin;
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auditService = new FreemiumAuditService();
        $this->now = Carbon::parse('2026-11-18 10:00:00', 'UTC');

        $adminId = DB::table('users')->value('id');
        $this->admin = $adminId ? User::find($adminId) : User::create([
            'name'     => 'Audit Test Admin',
            'email'    => 'admin_audit_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
        ]);
    }

    protected function tearDown(): void
    {
        FreemiumAuditLog::where('admin_id', $this->admin->id)->delete();

        parent::tearDown();
    }

    /**
     * Test audit logging captures correct actor and entity snapshots.
     */
    public function test_audit_log_captures_actor_and_entity_snapshots(): void
    {
        $log = $this->auditService->log(
            $this->admin,
            'campaign.created',
            'freemium_campaign',
            999,
            null,
            ['name' => 'Spring Campaign', 'priority' => 10],
            '192.168.1.100',
            'Mozilla/5.0 Test Agent',
            $this->now
        );

        $this->assertInstanceOf(FreemiumAuditLog::class, $log);
        $this->assertEquals($this->admin->id, $log->admin_id);
        $this->assertEquals($this->admin->name, $log->admin_name);
        $this->assertEquals($this->admin->email, $log->admin_email);
        $this->assertEquals('campaign.created', $log->action);
        $this->assertEquals('freemium_campaign', $log->entity_type);
        $this->assertEquals(999, $log->entity_id);
        $this->assertEquals('192.168.1.100', $log->ip_address);
        $this->assertEquals('Mozilla/5.0 Test Agent', $log->user_agent);
        $this->assertEquals($this->now->timestamp, $log->created_at->timestamp);
    }

    /**
     * Test audit logging captures correct before and after values.
     */
    public function test_audit_log_captures_before_and_after_data(): void
    {
        $oldState = ['status' => 'draft', 'priority' => 50];
        $newState = ['status' => 'active', 'priority' => 10];

        $log = $this->auditService->log(
            $this->admin,
            'campaign.updated',
            'freemium_campaign',
            888,
            $oldState,
            $newState
        );

        $this->assertEquals($oldState, $log->old_values);
        $this->assertEquals($newState, $log->new_values);
    }

    /**
     * Test sensitive field exclusion (passwords, secrets, tokens).
     */
    public function test_sensitive_fields_are_redacted(): void
    {
        $payloadWithSecrets = [
            'username'              => 'john_doe',
            'password'              => 'SuperSecret123!',
            'password_confirmation' => 'SuperSecret123!',
            'api_token'             => 'sk_live_abcdef123456',
            'remember_token'        => 'rem_token_xyz',
            'metadata'              => [
                'role'   => 'teacher',
                'secret' => 'nested_secret_value',
            ],
        ];

        $log = $this->auditService->log(
            $this->admin,
            'user.updated',
            'user',
            777,
            null,
            $payloadWithSecrets
        );

        $this->assertEquals('john_doe', $log->new_values['username']);
        $this->assertEquals('[REDACTED]', $log->new_values['password']);
        $this->assertEquals('[REDACTED]', $log->new_values['password_confirmation']);
        $this->assertEquals('[REDACTED]', $log->new_values['api_token']);
        $this->assertEquals('[REDACTED]', $log->new_values['remember_token']);
        $this->assertEquals('[REDACTED]', $log->new_values['metadata']['secret']);
        $this->assertEquals('teacher', $log->new_values['metadata']['role']);
    }

    /**
     * Test atomic transaction rollback: audit log rolls back if transaction fails.
     */
    public function test_atomic_transaction_rollback(): void
    {
        try {
            DB::transaction(function () {
                $this->auditService->log(
                    $this->admin,
                    'atomic.action',
                    'freemium_campaign',
                    555,
                    null,
                    ['test' => true]
                );

                // Simulate business operation failure
                throw new Exception("Intentional transaction failure");
            });
        } catch (Exception $e) {
            // Expected
        }

        // Verify audit log was rolled back
        $this->assertDatabaseMissing('freemium_audit_logs', [
            'action'    => 'atomic.action',
            'entity_id' => 555,
        ]);
    }

    /**
     * Test append-only Eloquent safeguards block update() on FreemiumAuditLog.
     */
    public function test_append_only_guard_blocks_update(): void
    {
        $log = $this->auditService->log(
            $this->admin,
            'immutable.test',
            'freemium_campaign',
            444
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("FreemiumAuditLog is an append-only immutable audit record. Updates are prohibited.");

        $log->update(['action' => 'tampered.action']);
    }

    /**
     * Test append-only Eloquent safeguards block delete() on FreemiumAuditLog.
     */
    public function test_append_only_guard_blocks_delete(): void
    {
        $log = $this->auditService->log(
            $this->admin,
            'immutable.delete.test',
            'freemium_campaign',
            333
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("FreemiumAuditLog is an append-only immutable audit record. Deletions are prohibited.");

        $log->delete();
    }
}
