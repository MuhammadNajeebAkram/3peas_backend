<?php

namespace Tests\Feature\Freemium;

use App\Models\Book;
use App\Models\BookUnit;
use App\Models\BookUnitTopic;
use App\Models\CurriculumBoard;
use App\Models\ExamQuestion;
use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumAuditLog;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumExamQuota;
use App\Models\Freemium\FreemiumQuotaConsumption;
use App\Models\Freemium\FreemiumQuotaConsumptionEnvelope;
use App\Models\Freemium\FreemiumQuotaCounter;
use App\Models\Freemium\FreemiumStudentGrant;
use App\Models\ModelPaper;
use App\Models\PracticeSession;
use App\Models\Subject;
use App\Models\Test;
use App\Models\TestAttempt;
use App\Models\TstPastPaper;
use App\Models\User;
use App\Models\UserClass;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Validates Eloquent models, relationships, casts, query scopes,
 * generated columns, and immutable append-only safeguards.
 */
class FreemiumModelRelationshipsTest extends TestCase
{
    private User $admin;
    private WebUser $student;
    private Test $formalTest;
    private PracticeSession $practiceSession;
    private FreemiumCampaign $campaign;
    private FreemiumExamQuota $quota;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Resolve or create user fixtures
        $adminId = DB::table('users')->value('id');
        if ($adminId) {
            $this->admin = User::find($adminId);
        } else {
            $this->admin = User::create([
                'name'     => 'Freemium Test Admin',
                'email'    => 'admin_model_test_' . uniqid() . '@example.com',
                'password' => bcrypt('secret'),
            ]);
        }

        $studentId = DB::table('web_users')->insertGetId([
            'name'       => 'Freemium Model Student',
            'email'      => 'student_model_test_' . uniqid() . '@example.com',
            'password'   => bcrypt('secret'),
            'role'       => 'student',
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->student = WebUser::find($studentId);

        // 2. Resolve or create academic exam entities
        $testId = DB::table('tests')->value('id');
        if ($testId) {
            $this->formalTest = Test::find($testId);
        } else {
            $this->formalTest = Test::create([
                'title'      => 'Freemium Model Test Exam',
                'test_mode'  => 'test',
                'scope_type' => 'chapter',
            ]);
        }

        $practiceId = DB::table('practice_sessions')->insertGetId([
            'user_id'    => $this->student->id,
            'scope_type' => 'chapter',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->practiceSession = PracticeSession::find($practiceId);

        // 3. Create base campaign & quota
        $this->campaign = FreemiumCampaign::create([
            'name'                 => 'Model Test Campaign ' . uniqid(),
            'description'          => 'Campaign for model relationship testing',
            'status'               => 'active',
            'is_stackable'         => true,
            'priority'             => 50,
            'start_at'             => now()->subDay(),
            'end_at'               => now()->addDays(30),
            'eligibility_criteria' => ['domains' => ['*']],
            'created_by'           => $this->admin->id,
            'updated_by'           => $this->admin->id,
        ]);

        $this->quota = FreemiumExamQuota::create([
            'campaign_id'           => $this->campaign->id,
            'exam_type'             => 'formal_test',
            'window_type'           => 'daily',
            'max_tests'             => 3,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 2,
            'time_limit_minutes'    => 45,
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up test data safely in reverse dependency order
        FreemiumAuditLog::where('admin_id', $this->admin->id)->forceDelete();
        FreemiumQuotaConsumptionEnvelope::where('campaign_id', $this->campaign->id)->forceDelete();
        FreemiumQuotaConsumption::where('primary_campaign_id', $this->campaign->id)->forceDelete();
        FreemiumQuotaCounter::where('user_id', $this->student->id)->forceDelete();
        FreemiumStudentGrant::where('campaign_id', $this->campaign->id)->forceDelete();
        FreemiumAccessRule::where('campaign_id', $this->campaign->id)->forceDelete();
        $this->quota->forceDelete();
        $this->campaign->forceDelete();

        DB::table('practice_sessions')->where('id', $this->practiceSession->id)->delete();
        DB::table('web_users')->where('id', $this->student->id)->delete();

        parent::tearDown();
    }

    /**
     * Test FreemiumCampaign lifecycle, casts, query scopes, and relationships.
     */
    public function test_freemium_campaign_model_lifecycle_and_relationships(): void
    {
        $this->assertInstanceOf(FreemiumCampaign::class, $this->campaign);
        $this->assertTrue($this->campaign->is_stackable);
        $this->assertIsInt($this->campaign->priority);
        $this->assertEquals(50, $this->campaign->priority);
        $this->assertInstanceOf(Carbon::class, $this->campaign->start_at);
        $this->assertInstanceOf(Carbon::class, $this->campaign->end_at);
        $this->assertIsArray($this->campaign->eligibility_criteria);

        // Relationships: creator & updater
        $this->assertInstanceOf(User::class, $this->campaign->creator);
        $this->assertEquals($this->admin->id, $this->campaign->creator->id);
        $this->assertInstanceOf(User::class, $this->campaign->updater);

        // Scopes
        $activeCount = FreemiumCampaign::active()->where('id', $this->campaign->id)->count();
        $this->assertEquals(1, $activeCount);

        $runningCount = FreemiumCampaign::currentlyRunning()->where('id', $this->campaign->id)->count();
        $this->assertEquals(1, $runningCount);

        // Soft deletes
        $this->campaign->delete();
        $this->assertSoftDeleted('freemium_campaigns', ['id' => $this->campaign->id]);
        $this->assertNull(FreemiumCampaign::find($this->campaign->id));
        $this->assertNotNull(FreemiumCampaign::withTrashed()->find($this->campaign->id));

        // Restore for tearDown
        $this->campaign->restore();
    }

    /**
     * Test FreemiumAccessRule model, casts, scopes, and all 9 academic relations.
     */
    public function test_freemium_access_rule_model_and_academic_relationships(): void
    {
        $board = CurriculumBoard::first();
        $class = UserClass::first();
        $subject = Subject::first();
        $book = Book::first();
        $chapter = BookUnit::first();
        $topic = BookUnitTopic::first();
        $question = ExamQuestion::first();
        $modelPaper = ModelPaper::first();
        $pastPaper = TstPastPaper::first();

        $rule = FreemiumAccessRule::create([
            'campaign_id'                 => $this->campaign->id,
            'target_scope'                => 'global',
            'target_status'               => 'active',
            'curriculum_board_id'         => $board?->id,
            'class_id'                    => $class?->id,
            'subject_id'                  => $subject?->id,
            'book_id'                     => $book?->id,
            'chapter_id'                  => $chapter?->id,
            'topic_id'                    => $topic?->id,
            'question_id'                 => $question?->id,
            'model_paper_id'              => $modelPaper?->id,
            'past_paper_id'               => $pastPaper?->id,
            'perm_mcqs'                   => 'allow',
            'perm_short_questions'        => 'deny',
            'perm_long_questions'         => 'inherit',
            'rule_priority'               => 15,
            'academic_target_snapshot'    => ['name' => 'All Mathematics Chapters'],
        ]);

        $this->assertIsInt($rule->rule_priority);
        $this->assertEquals(15, $rule->rule_priority);
        $this->assertIsArray($rule->academic_target_snapshot);
        $this->assertEquals('All Mathematics Chapters', $rule->academic_target_snapshot['name']);

        // Check campaign relationship
        $this->assertInstanceOf(FreemiumCampaign::class, $rule->campaign);
        $this->assertEquals($this->campaign->id, $rule->campaign->id);

        // Check academic hierarchy relationships
        if ($board) {
            $this->assertInstanceOf(CurriculumBoard::class, $rule->curriculumBoard);
            $this->assertEquals($board->id, $rule->curriculumBoard->id);
        }
        if ($class) {
            $this->assertInstanceOf(UserClass::class, $rule->userClass);
            $this->assertEquals($class->id, $rule->userClass->id);
        }
        if ($subject) {
            $this->assertInstanceOf(Subject::class, $rule->subject);
            $this->assertEquals($subject->id, $rule->subject->id);
        }
        if ($book) {
            $this->assertInstanceOf(Book::class, $rule->book);
            $this->assertEquals($book->id, $rule->book->id);
        }
        if ($chapter) {
            $this->assertInstanceOf(BookUnit::class, $rule->bookUnit);
            $this->assertEquals($chapter->id, $rule->bookUnit->id);
        }
        if ($topic) {
            $this->assertInstanceOf(BookUnitTopic::class, $rule->bookUnitTopic);
            $this->assertEquals($topic->id, $rule->bookUnitTopic->id);
        }
        if ($question) {
            $this->assertInstanceOf(ExamQuestion::class, $rule->question);
            $this->assertEquals($question->id, $rule->question->id);
        }
        if ($modelPaper) {
            $this->assertInstanceOf(ModelPaper::class, $rule->modelPaper);
            $this->assertEquals($modelPaper->id, $rule->modelPaper->id);
        }
        if ($pastPaper) {
            $this->assertInstanceOf(TstPastPaper::class, $rule->pastPaper);
            $this->assertEquals($pastPaper->id, $rule->pastPaper->id);
        }

        // Test scopes
        $this->assertEquals(1, FreemiumAccessRule::active()->where('id', $rule->id)->count());
        $this->assertEquals(0, FreemiumAccessRule::orphaned()->where('id', $rule->id)->count());

        $rule->delete();
    }

    /**
     * Test FreemiumExamQuota model, casts, helper methods, and relationships.
     */
    public function test_freemium_exam_quota_model_and_relationships(): void
    {
        $this->assertInstanceOf(FreemiumExamQuota::class, $this->quota);
        $this->assertIsInt($this->quota->max_tests);
        $this->assertTrue($this->quota->allow_retakes);
        $this->assertIsInt($this->quota->max_attempts_per_test);
        $this->assertEquals(2, $this->quota->max_attempts_per_test);
        $this->assertIsInt($this->quota->time_limit_minutes);
        $this->assertEquals(45, $this->quota->time_limit_minutes);

        // Helper method
        $this->assertFalse($this->quota->isUnlimited());

        $unlimitedQuota = new FreemiumExamQuota(['max_tests' => null]);
        $this->assertTrue($unlimitedQuota->isUnlimited());

        $unlimitedQuotaNeg = new FreemiumExamQuota(['max_tests' => -1]);
        $this->assertTrue($unlimitedQuotaNeg->isUnlimited());

        // Relationships
        $this->assertInstanceOf(FreemiumCampaign::class, $this->quota->campaign);
        $this->assertEquals($this->campaign->id, $this->quota->campaign->id);
    }

    /**
     * Test FreemiumStudentGrant model, casts, scopes, and relationships.
     */
    public function test_freemium_student_grant_model_and_relationships(): void
    {
        $grant = FreemiumStudentGrant::create([
            'campaign_id'  => $this->campaign->id,
            'user_id'      => $this->student->id,
            'grant_type'   => 'voucher',
            'voucher_code' => 'TEST-VOUCHER-XYZ',
            'granted_at'   => now(),
            'expires_at'   => now()->addDays(7),
            'status'       => 'active',
            'created_by'   => $this->admin->id,
        ]);

        $this->assertInstanceOf(Carbon::class, $grant->granted_at);
        $this->assertInstanceOf(Carbon::class, $grant->expires_at);

        // Relationships
        $this->assertInstanceOf(FreemiumCampaign::class, $grant->campaign);
        $this->assertInstanceOf(WebUser::class, $grant->student);
        $this->assertEquals($this->student->id, $grant->student->id);
        $this->assertInstanceOf(User::class, $grant->creator);
        $this->assertEquals($this->admin->id, $grant->creator->id);

        // Scopes
        $this->assertEquals(1, FreemiumStudentGrant::active()->where('id', $grant->id)->count());
        $this->assertEquals(1, FreemiumStudentGrant::validAt()->where('id', $grant->id)->count());

        $grant->delete();
        $this->assertSoftDeleted('freemium_student_grants', ['id' => $grant->id]);
    }

    /**
     * Test FreemiumQuotaCounter model, casts, and student relationship.
     */
    public function test_freemium_quota_counter_model_and_relationships(): void
    {
        $counter = FreemiumQuotaCounter::create([
            'user_id'     => $this->student->id,
            'exam_type'   => 'formal_test',
            'window_type' => 'daily',
            'window_key'  => '2026-11-18',
            'counter'     => 3,
        ]);

        $this->assertIsInt($counter->counter);
        $this->assertEquals(3, $counter->counter);
        $this->assertInstanceOf(WebUser::class, $counter->student);
        $this->assertEquals($this->student->id, $counter->student->id);

        $counter->delete();
    }

    /**
     * Test FreemiumQuotaConsumption model, casts, relationships, and generated columns.
     */
    public function test_freemium_quota_consumption_model_and_relationships(): void
    {
        $consumption = FreemiumQuotaConsumption::create([
            'user_id'             => $this->student->id,
            'exam_type'           => 'formal_test',
            'event_type'          => 'attempt_start',
            'primary_campaign_id' => $this->campaign->id,
            'primary_quota_id'    => $this->quota->id,
            'primary_grant_id'    => null,
            'test_id'             => $this->formalTest->id,
            'practice_session_id' => null,
            'attempt_number'      => 1,
            'idempotency_key'     => 'idem_' . uniqid(),
            'request_fingerprint' => hash('sha256', 'payload'),
            'decision_snapshot'   => ['granted' => true, 'source' => 'campaign'],
            'consumed_at'         => now(),
        ]);

        // Stored generated columns are computed by MySQL; refresh to load them from DB
        $consumption->refresh();

        // Casts
        $this->assertIsInt($consumption->attempt_number);
        $this->assertEquals(1, $consumption->attempt_number);
        $this->assertIsArray($consumption->decision_snapshot);
        $this->assertTrue($consumption->decision_snapshot['granted']);
        $this->assertInstanceOf(Carbon::class, $consumption->consumed_at);

        // Stored generated columns
        $this->assertEquals('test', $consumption->entity_type);
        $this->assertEquals($this->formalTest->id, $consumption->entity_id);

        // Relationships
        $this->assertInstanceOf(WebUser::class, $consumption->student);
        $this->assertInstanceOf(FreemiumCampaign::class, $consumption->primaryCampaign);
        $this->assertInstanceOf(FreemiumExamQuota::class, $consumption->primaryQuota);
        $this->assertInstanceOf(Test::class, $consumption->test);
        $this->assertEquals($this->formalTest->id, $consumption->test->id);
    }

    /**
     * Test FreemiumQuotaConsumption practice session entity_type generation.
     */
    public function test_freemium_quota_consumption_practice_session_polymorphic(): void
    {
        $consumption = FreemiumQuotaConsumption::create([
            'user_id'             => $this->student->id,
            'exam_type'           => 'practice',
            'event_type'          => 'practice_generation',
            'primary_campaign_id' => $this->campaign->id,
            'primary_quota_id'    => $this->quota->id,
            'primary_grant_id'    => null,
            'test_id'             => null,
            'practice_session_id' => $this->practiceSession->id,
            'attempt_number'      => 0,
            'idempotency_key'     => 'idem_practice_' . uniqid(),
            'decision_snapshot'   => ['granted' => true],
            'consumed_at'         => now(),
        ]);

        $consumption->refresh();

        $this->assertEquals('practice_session', $consumption->entity_type);
        $this->assertEquals($this->practiceSession->id, $consumption->entity_id);
        $this->assertInstanceOf(PracticeSession::class, $consumption->practiceSession);
        $this->assertEquals($this->practiceSession->id, $consumption->practiceSession->id);
    }

    /**
     * Test FreemiumQuotaConsumptionEnvelope model, casts, and relationships.
     */
    public function test_freemium_quota_consumption_envelope_model_and_relationships(): void
    {
        $consumption = FreemiumQuotaConsumption::create([
            'user_id'             => $this->student->id,
            'exam_type'           => 'formal_test',
            'event_type'          => 'attempt_start',
            'primary_campaign_id' => $this->campaign->id,
            'primary_quota_id'    => $this->quota->id,
            'test_id'             => $this->formalTest->id,
            'attempt_number'      => 2,
            'idempotency_key'     => 'idem_env_' . uniqid(),
            'decision_snapshot'   => ['granted' => true],
            'consumed_at'         => now(),
        ]);

        $envelope = FreemiumQuotaConsumptionEnvelope::create([
            'consumption_id'                  => $consumption->id,
            'campaign_id'                     => $this->campaign->id,
            'quota_id'                        => $this->quota->id,
            'grant_id'                        => null,
            'window_type'                     => 'daily',
            'window_key'                      => '2026-11-18',
            'counter_value_after_consumption' => 2,
            'allocated_at'                    => now(),
        ]);

        $this->assertIsInt($envelope->counter_value_after_consumption);
        $this->assertEquals(2, $envelope->counter_value_after_consumption);
        $this->assertInstanceOf(Carbon::class, $envelope->allocated_at);

        // Relationships
        $this->assertInstanceOf(FreemiumQuotaConsumption::class, $envelope->consumption);
        $this->assertEquals($consumption->id, $envelope->consumption->id);
        $this->assertInstanceOf(FreemiumCampaign::class, $envelope->campaign);
        $this->assertInstanceOf(FreemiumExamQuota::class, $envelope->quota);

        // Reverse relation on consumption
        $this->assertCount(1, $consumption->envelopes);
        $this->assertEquals($envelope->id, $consumption->envelopes->first()->id);
    }

    /**
     * Test FreemiumAuditLog model, casts, scopes, and administrator relationship.
     */
    public function test_freemium_audit_log_model_and_relationships(): void
    {
        $auditLog = FreemiumAuditLog::create([
            'admin_id'    => $this->admin->id,
            'admin_name'  => $this->admin->name,
            'admin_email' => $this->admin->email,
            'admin_role'  => 'super_admin',
            'action'      => 'campaign.created',
            'entity_type' => 'freemium_campaign',
            'entity_id'   => $this->campaign->id,
            'old_values'  => null,
            'new_values'  => ['status' => 'active', 'priority' => 50],
            'ip_address'  => '127.0.0.1',
            'user_agent'  => 'PHPUnit Test Agent',
            'created_at'  => now(),
        ]);

        $this->assertIsInt($auditLog->entity_id);
        $this->assertIsArray($auditLog->new_values);
        $this->assertEquals('active', $auditLog->new_values['status']);
        $this->assertInstanceOf(Carbon::class, $auditLog->created_at);

        // Relationship
        $this->assertInstanceOf(User::class, $auditLog->admin);
        $this->assertEquals($this->admin->id, $auditLog->admin->id);

        // Scopes
        $this->assertEquals(1, FreemiumAuditLog::forEntity('freemium_campaign', $this->campaign->id)->count());
        $this->assertEquals(1, FreemiumAuditLog::forAdmin($this->admin->id)->count());
    }

    /**
     * Test append-only guard blocks update() and delete() on FreemiumQuotaConsumption.
     */
    public function test_append_only_guard_blocks_update_and_delete_on_quota_consumption(): void
    {
        $consumption = FreemiumQuotaConsumption::create([
            'user_id'             => $this->student->id,
            'exam_type'           => 'formal_test',
            'event_type'          => 'attempt_start',
            'primary_campaign_id' => $this->campaign->id,
            'primary_quota_id'    => $this->quota->id,
            'test_id'             => $this->formalTest->id,
            'attempt_number'      => 3,
            'idempotency_key'     => 'idem_immutable_' . uniqid(),
            'decision_snapshot'   => ['granted' => true],
            'consumed_at'         => now(),
        ]);

        // Attempt update -> Expect RuntimeException
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FreemiumQuotaConsumption is an append-only immutable ledger. Updates are prohibited.');
        $consumption->update(['attempt_number' => 99]);
    }

    /**
     * Test append-only guard blocks delete() on FreemiumQuotaConsumption.
     */
    public function test_append_only_guard_blocks_delete_on_quota_consumption(): void
    {
        $consumption = FreemiumQuotaConsumption::create([
            'user_id'             => $this->student->id,
            'exam_type'           => 'formal_test',
            'event_type'          => 'attempt_start',
            'primary_campaign_id' => $this->campaign->id,
            'primary_quota_id'    => $this->quota->id,
            'test_id'             => $this->formalTest->id,
            'attempt_number'      => 4,
            'idempotency_key'     => 'idem_del_' . uniqid(),
            'decision_snapshot'   => ['granted' => true],
            'consumed_at'         => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FreemiumQuotaConsumption is an append-only immutable ledger. Deletions are prohibited.');
        $consumption->delete();
    }

    /**
     * Test append-only guard blocks update() on FreemiumQuotaConsumptionEnvelope.
     */
    public function test_append_only_guard_blocks_update_on_envelope(): void
    {
        $consumption = FreemiumQuotaConsumption::create([
            'user_id'             => $this->student->id,
            'exam_type'           => 'formal_test',
            'event_type'          => 'attempt_start',
            'primary_campaign_id' => $this->campaign->id,
            'primary_quota_id'    => $this->quota->id,
            'test_id'             => $this->formalTest->id,
            'attempt_number'      => 5,
            'idempotency_key'     => 'idem_env_upd_' . uniqid(),
            'decision_snapshot'   => ['granted' => true],
            'consumed_at'         => now(),
        ]);

        $envelope = FreemiumQuotaConsumptionEnvelope::create([
            'consumption_id'                  => $consumption->id,
            'campaign_id'                     => $this->campaign->id,
            'quota_id'                        => $this->quota->id,
            'window_type'                     => 'monthly',
            'window_key'                      => '2026-11',
            'counter_value_after_consumption' => 1,
            'allocated_at'                    => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FreemiumQuotaConsumptionEnvelope is an append-only immutable ledger. Updates are prohibited.');
        $envelope->update(['counter_value_after_consumption' => 99]);
    }

    /**
     * Test append-only guard blocks delete() on FreemiumQuotaConsumptionEnvelope.
     */
    public function test_append_only_guard_blocks_delete_on_envelope(): void
    {
        $consumption = FreemiumQuotaConsumption::create([
            'user_id'             => $this->student->id,
            'exam_type'           => 'formal_test',
            'event_type'          => 'attempt_start',
            'primary_campaign_id' => $this->campaign->id,
            'primary_quota_id'    => $this->quota->id,
            'test_id'             => $this->formalTest->id,
            'attempt_number'      => 6,
            'idempotency_key'     => 'idem_env_del_' . uniqid(),
            'decision_snapshot'   => ['granted' => true],
            'consumed_at'         => now(),
        ]);

        $envelope = FreemiumQuotaConsumptionEnvelope::create([
            'consumption_id'                  => $consumption->id,
            'campaign_id'                     => $this->campaign->id,
            'quota_id'                        => $this->quota->id,
            'window_type'                     => 'monthly',
            'window_key'                      => '2026-11-alt',
            'counter_value_after_consumption' => 1,
            'allocated_at'                    => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FreemiumQuotaConsumptionEnvelope is an append-only immutable ledger. Deletions are prohibited.');
        $envelope->delete();
    }

    /**
     * Test append-only guard blocks update() on FreemiumAuditLog.
     */
    public function test_append_only_guard_blocks_update_on_audit_log(): void
    {
        $log = FreemiumAuditLog::create([
            'admin_id'    => $this->admin->id,
            'action'      => 'test.create',
            'entity_type' => 'test',
            'entity_id'   => 1,
            'created_at'  => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FreemiumAuditLog is an append-only immutable audit record. Updates are prohibited.');
        $log->update(['action' => 'tampered']);
    }

    /**
     * Test append-only guard blocks delete() on FreemiumAuditLog.
     */
    public function test_append_only_guard_blocks_delete_on_audit_log(): void
    {
        $log = FreemiumAuditLog::create([
            'admin_id'    => $this->admin->id,
            'action'      => 'test.create',
            'entity_type' => 'test',
            'entity_id'   => 2,
            'created_at'  => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FreemiumAuditLog is an append-only immutable audit record. Deletions are prohibited.');
        $log->delete();
    }
}
