<?php

namespace Tests\Feature;

use App\Http\Services\PrivateMessageService;
use App\Http\Services\TeacherClassAnnouncementService;
use App\Models\TeacherProfile;
use App\Models\WebUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TeacherClassCommunicationMySqlTest extends TestCase
{
    public function test_mysql_innodb_enforces_phase7e_unique_constraints_and_prevents_duplicates(): void
    {
        $conn = DB::connection('mysql');
        $conn->beginTransaction();

        try {
            $now = now()->format('Y-m-d H:i:s');
            $classId = (int) $conn->table('class_tbl')->where('activate', 1)->value('id');
            $subjectId = (int) $conn->table('subject_tbl')->where('activate', 1)->value('id');
            $boardId = (int) $conn->table('curriculum_board_tbl')->where('activate', 1)->value('id');

            $this->assertGreaterThan(0, $classId);
            $this->assertGreaterThan(0, $subjectId);
            $this->assertGreaterThan(0, $boardId);

            $suffix = uniqid('p7e_', true);

            $teacherUserId = $conn->table('web_users')->insertGetId([
                'name' => 'Phase7E MySQL Teacher',
                'email' => "p7e_teacher_{$suffix}@example.test",
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
                'role' => 'teacher',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $studentUserId = $conn->table('web_users')->insertGetId([
                'name' => 'Phase7E MySQL Student',
                'email' => "p7e_student_{$suffix}@example.test",
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
                'role' => 'student',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $teacherProfileId = $conn->table('teacher_profiles')->insertGetId([
                'web_user_id' => $teacherUserId,
                'teacher_code' => 'P7E' . strtoupper(substr(md5($suffix), 0, 5)),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $freePlanId = (int) $conn->table('teacher_subscription_plans')->where('code', 'free')->value('id');
            $conn->table('teacher_subscriptions')->insert([
                'teacher_profile_id' => $teacherProfileId,
                'teacher_subscription_plan_id' => $freePlanId,
                'status' => 'active',
                'starts_at' => $now,
                'expires_at' => now()->addMonth()->format('Y-m-d H:i:s'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $teacherClassId = $conn->table('teacher_classes')->insertGetId([
                'teacher_profile_id' => $teacherProfileId,
                'curriculum_board_id' => $boardId,
                'class_id' => $classId,
                'subject_id' => $subjectId,
                'name' => 'Phase7E MySQL Class',
                'class_code' => strtoupper(substr(md5($suffix), 0, 8)),
                'is_active' => 1,
                'requires_approval' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $conn->table('teacher_class_enrollments')->insert([
                'teacher_class_id' => $teacherClassId,
                'student_web_user_id' => $studentUserId,
                'status' => 'active',
                'approved_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // 1. Unique announcement read constraint: uk_tcar_announcement_student
            $announcementId = $conn->table('teacher_class_announcements')->insertGetId([
                'teacher_class_id' => $teacherClassId,
                'teacher_profile_id' => $teacherProfileId,
                'created_by_web_user_id' => $teacherUserId,
                'title' => 'MySQL Constraint Announcement',
                'body' => 'Testing unique read receipt.',
                'status' => 'published',
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $conn->table('teacher_class_announcement_reads')->insert([
                'announcement_id' => $announcementId,
                'student_web_user_id' => $studentUserId,
                'read_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $duplicateReadFailed = false;
            try {
                $conn->table('teacher_class_announcement_reads')->insert([
                    'announcement_id' => $announcementId,
                    'student_web_user_id' => $studentUserId,
                    'read_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (QueryException) {
                $duplicateReadFailed = true;
            }
            $this->assertTrue($duplicateReadFailed, 'Expected unique constraint uk_tcar_announcement_student to reject duplicate read.');

            // 2. Unique 1:1 conversation constraint: uk_tsc_teacher_student
            $convId = $conn->table('teacher_student_conversations')->insertGetId([
                'teacher_profile_id' => $teacherProfileId,
                'teacher_web_user_id' => $teacherUserId,
                'student_web_user_id' => $studentUserId,
                'context_teacher_class_id' => $teacherClassId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $duplicateConvFailed = false;
            try {
                $conn->table('teacher_student_conversations')->insert([
                    'teacher_profile_id' => $teacherProfileId,
                    'teacher_web_user_id' => $teacherUserId,
                    'student_web_user_id' => $studentUserId,
                    'context_teacher_class_id' => $teacherClassId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (QueryException) {
                $duplicateConvFailed = true;
            }
            $this->assertTrue($duplicateConvFailed, 'Expected unique constraint uk_tsc_teacher_student to reject duplicate 1:1 conversation.');

            // 3. Service idempotency: repeated startOrGetConversation reuses exact row
            $teacherUser = WebUser::on('mysql')->findOrFail($teacherUserId);
            $studentUser = WebUser::on('mysql')->findOrFail($studentUserId);
            DB::setDefaultConnection('mysql');
            try {
                $msgService = app(PrivateMessageService::class);
                $resA = $msgService->startOrGetConversation($teacherUser, $studentUserId, $teacherClassId);
                $resB = $msgService->startOrGetConversation($studentUser, $teacherUserId, $teacherClassId);
                $this->assertEquals($convId, $resA['data']['id']);
                $this->assertEquals($convId, $resB['data']['id']);

                // Verify scheduled publisher handles notification idempotency cleanly
                $scheduledId = $conn->table('teacher_class_announcements')->insertGetId([
                    'teacher_class_id' => $teacherClassId,
                    'teacher_profile_id' => $teacherProfileId,
                    'created_by_web_user_id' => $teacherUserId,
                    'title' => 'Due Scheduled Announcement',
                    'body' => 'Should notify once only.',
                    'status' => 'scheduled',
                    'scheduled_at' => now()->subMinute()->format('Y-m-d H:i:s'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $annService = app(TeacherClassAnnouncementService::class);
                $publishedCount1 = $annService->syncScheduledForClasses([$teacherClassId]);
                $publishedCount2 = $annService->syncScheduledForClasses([$teacherClassId]);

                $this->assertEquals(1, $publishedCount1);
                $this->assertEquals(0, $publishedCount2);
                $this->assertNotNull($conn->table('teacher_class_announcements')->where('id', $scheduledId)->value('notified_at'));
            } finally {
                DB::setDefaultConnection(config('database.default'));
            }
        } finally {
            $conn->rollBack();
        }
    }

    public function test_concurrent_private_message_creation_does_not_create_duplicate_conversations(): void
    {
        $prefix = 'p7ec_' . substr(str_replace('.', '', uniqid('', true)), -6) . '_';
        $mysql = array_merge(config('database.connections.mysql'), ['prefix' => $prefix]);
        config(['database.default' => 'conc_pm_mysql', 'database.connections.conc_pm_mysql' => $mysql]);
        DB::purge('conc_pm_mysql');

        $schema = Schema::connection('conc_pm_mysql');
        $db = DB::connection('conc_pm_mysql');
        $barrierDir = sys_get_temp_dir() . '/p7e_conc_' . uniqid('', true);
        @mkdir($barrierDir, 0777, true);

        $tables = [
            'web_users',
            'teacher_profiles',
            'teacher_subscription_plans',
            'teacher_subscription_features',
            'teacher_subscriptions',
            'teacher_classes',
            'teacher_class_enrollments',
            'web_user_notifications',
            'teacher_student_conversations',
            'teacher_student_messages',
        ];

        try {
            $schema->create('web_users', function ($table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('role', 30);
                $table->string('status', 30)->default('active');
                $table->timestamps();
                $table->softDeletes();
            });
            $schema->create('teacher_profiles', function ($table) {
                $table->id();
                $table->unsignedBigInteger('web_user_id')->unique();
                $table->string('teacher_code', 20)->unique();
                $table->string('status', 30)->default('active');
                $table->timestamps();
            });
            $schema->create('teacher_subscription_plans', function ($table) {
                $table->id();
                $table->string('code', 50)->unique();
                $table->string('name');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
            $schema->create('teacher_subscription_features', function ($table) {
                $table->id();
                $table->unsignedBigInteger('teacher_subscription_plan_id');
                $table->string('feature_key', 100);
                $table->boolean('is_enabled')->default(true);
                $table->integer('limit_value')->nullable();
                $table->timestamps();
            });
            $schema->create('teacher_subscriptions', function ($table) {
                $table->id();
                $table->unsignedBigInteger('teacher_profile_id');
                $table->unsignedBigInteger('teacher_subscription_plan_id');
                $table->string('status', 30)->default('active');
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
            $schema->create('teacher_classes', function ($table) {
                $table->id();
                $table->unsignedBigInteger('teacher_profile_id');
                $table->unsignedBigInteger('curriculum_board_id')->default(1);
                $table->unsignedBigInteger('class_id')->default(1);
                $table->unsignedBigInteger('subject_id')->default(1);
                $table->string('section', 20)->default('A');
                $table->string('name');
                $table->string('class_code', 20)->unique();
                $table->boolean('is_active')->default(true);
                $table->boolean('requires_approval')->default(true);
                $table->timestamps();
            });
            $schema->create('teacher_class_enrollments', function ($table) {
                $table->id();
                $table->unsignedBigInteger('teacher_class_id');
                $table->unsignedBigInteger('student_web_user_id');
                $table->string('status', 30)->default('active');
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
            });
            $schema->create('web_user_notifications', function ($table) {
                $table->id();
                $table->unsignedBigInteger('recipient_web_user_id');
                $table->unsignedBigInteger('actor_web_user_id')->nullable();
                $table->unsignedBigInteger('teacher_class_id')->nullable();
                $table->unsignedBigInteger('teacher_class_enrollment_id')->nullable();
                $table->string('type', 64);
                $table->string('title');
                $table->text('message');
                $table->string('action_url', 500)->nullable();
                $table->json('metadata')->nullable();
                $table->boolean('is_read')->default(false);
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
            $schema->create('teacher_student_conversations', function ($table) {
                $table->id();
                $table->unsignedBigInteger('teacher_profile_id');
                $table->unsignedBigInteger('teacher_web_user_id');
                $table->unsignedBigInteger('student_web_user_id');
                $table->unsignedBigInteger('context_teacher_class_id')->nullable();
                $table->unsignedBigInteger('last_message_id')->nullable();
                $table->string('last_message_preview', 200)->nullable();
                $table->unsignedBigInteger('last_message_sender_id')->nullable();
                $table->timestamp('last_message_at')->nullable();
                $table->timestamp('teacher_last_read_at')->nullable();
                $table->timestamp('student_last_read_at')->nullable();
                $table->timestamps();

                $table->unique(['teacher_profile_id', 'student_web_user_id'], 'uk_conc_tsc_pair');
            });
            $schema->create('teacher_student_messages', function ($table) {
                $table->id();
                $table->unsignedBigInteger('conversation_id');
                $table->unsignedBigInteger('sender_web_user_id');
                $table->string('sender_role', 20);
                $table->text('body');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });

            $now = now()->format('Y-m-d H:i:s');
            $db->table('web_users')->insert([
                ['id' => 10, 'name' => 'Concurrent Teacher', 'email' => 'conc_t@example.test', 'role' => 'teacher', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 11, 'name' => 'Concurrent Student', 'email' => 'conc_s@example.test', 'role' => 'student', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ]);
            $db->table('teacher_profiles')->insert([
                'id' => 1, 'web_user_id' => 10, 'teacher_code' => 'CONCP7E1', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->table('teacher_subscription_plans')->insert([
                'id' => 1, 'code' => 'free', 'name' => 'Free', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->table('teacher_subscription_features')->insert([
                'teacher_subscription_plan_id' => 1, 'feature_key' => 'messaging.use', 'is_enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->table('teacher_subscriptions')->insert([
                'teacher_profile_id' => 1, 'teacher_subscription_plan_id' => 1, 'status' => 'active',
                'starts_at' => $now, 'expires_at' => now()->addMonth()->format('Y-m-d H:i:s'),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->table('teacher_classes')->insert([
                'id' => 1, 'teacher_profile_id' => 1, 'name' => 'Concurrent Class', 'class_code' => 'CONC01',
                'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->table('teacher_class_enrollments')->insert([
                'teacher_class_id' => 1, 'student_web_user_id' => 11, 'status' => 'active', 'approved_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $results = $this->runConcurrentConversationWorkers($prefix, $barrierDir, [
                ['actor_id' => 10, 'recipient_id' => 11, 'class_id' => 1, 'message' => 'Parallel Teacher Hello'],
                ['actor_id' => 11, 'recipient_id' => 10, 'class_id' => 1, 'message' => 'Parallel Student Hello'],
            ]);

            $this->assertTrue($results[0]['barrier_synced'] && $results[1]['barrier_synced'], 'Both workers must synchronize right at the conversation creation transaction.');
            $this->assertSame(200, $results[0]['status'], 'Worker 0 failed: ' . json_encode($results[0]));
            $this->assertSame(200, $results[1]['status'], 'Worker 1 failed: ' . json_encode($results[1]));
            $this->assertSame($results[0]['conversation_id'], $results[1]['conversation_id']);
            $this->assertSame(1, $db->table('teacher_student_conversations')->count());
            $this->assertSame(2, $db->table('teacher_student_messages')->where('conversation_id', $results[0]['conversation_id'])->count());
        } finally {
            foreach (array_reverse($tables) as $table) {
                $schema->dropIfExists($table);
            }
            foreach (glob($barrierDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($barrierDir);
            DB::setDefaultConnection(config('database.default'));
            DB::purge('conc_pm_mysql');
        }
    }

    private function runConcurrentConversationWorkers(string $prefix, string $barrierDir, array $workers): array
    {
        $script = <<<'PHP'
$autoload = $argv[1];
$basePath = $argv[2];
$payload = json_decode(base64_decode($argv[3]), true);
require $autoload;
$app = require $basePath . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$mysql = array_merge(config('database.connections.mysql'), ['prefix' => $payload['prefix']]);
config(['database.default' => 'conc_pm_worker', 'database.connections.conc_pm_worker' => $mysql]);
Illuminate\Support\Facades\DB::purge('conc_pm_worker');

$barrierSynced = false;
$syncBarrier = function () use ($payload, &$barrierSynced) {
    if ($barrierSynced) return;
    file_put_contents($payload['self_file'], '1');
    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline) {
        if (file_exists($payload['peer_file'])) {
            $barrierSynced = true;
            break;
        }
        usleep(10000);
    }
};

Illuminate\Support\Facades\DB::listen(function ($query) use ($syncBarrier, &$barrierSynced) {
    if (!$barrierSynced && str_contains($query->sql, 'teacher_student_conversations') && str_contains(strtolower($query->sql), 'for update')) {
        $syncBarrier();
    }
});

try {
    $actor = App\Models\WebUser::findOrFail((int) $payload['actor_id']);
    $res = app(App\Http\Services\PrivateMessageService::class)->startOrGetConversation(
        $actor,
        (int) $payload['recipient_id'],
        (int) $payload['class_id'],
        (string) $payload['message']
    );
    echo json_encode([
        'status' => 200,
        'conversation_id' => $res['data']['id'] ?? null,
        'barrier_synced' => $barrierSynced,
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'status' => 500,
        'error' => get_class($e) . ': ' . $e->getMessage(),
        'barrier_synced' => $barrierSynced,
    ]);
}
PHP;

        $procs = [];
        foreach ($workers as $idx => $worker) {
            $peerIdx = $idx === 0 ? 1 : 0;
            $payload = array_merge($worker, [
                'prefix' => $prefix,
                'self_file' => $barrierDir . "/pm_{$idx}.ready",
                'peer_file' => $barrierDir . "/pm_{$peerIdx}.ready",
            ]);
            $cmd = [
                PHP_BINARY,
                '-r',
                $script,
                base_path('vendor/autoload.php'),
                base_path(),
                base64_encode(json_encode($payload)),
            ];
            $pipes = [];
            $proc = proc_open($cmd, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, base_path());
            fclose($pipes[0]);
            $procs[$idx] = ['proc' => $proc, 'pipes' => $pipes];
        }

        $results = [];
        foreach ($procs as $idx => $item) {
            $stdout = stream_get_contents($item['pipes'][1]);
            $stderr = stream_get_contents($item['pipes'][2]);
            fclose($item['pipes'][1]);
            fclose($item['pipes'][2]);
            proc_close($item['proc']);
            $decoded = json_decode(trim($stdout), true);
            $this->assertIsArray($decoded, "Worker {$idx} invalid output: {$stdout} STDERR: {$stderr}");
            $results[$idx] = $decoded;
        }

        return $results;
    }
}
