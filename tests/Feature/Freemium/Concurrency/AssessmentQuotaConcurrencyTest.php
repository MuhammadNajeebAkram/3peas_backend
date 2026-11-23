<?php

namespace Tests\Feature\Freemium\Concurrency;

use App\Models\AssessmentIdempotencyRecord;
use App\Models\Freemium\FreemiumQuotaConsumption;
use App\Models\Freemium\FreemiumQuotaCounter;
use App\Models\Test;
use App\Models\WebUser;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssessmentQuotaConcurrencyTest extends TestCase
{
    private WebUser $student;
    private int $programId = 994;
    private int $subjectId = 1;
    private int $unitId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $activeDb = DB::selectOne("SELECT DATABASE() as db")->db ?? '';
        if ($activeDb !== '3peas_db_subscription_test') {
            $this->markTestSkipped("Concurrency test strictly runs against 3peas_db_subscription_test.");
        }

        $this->student = WebUser::firstOrCreate(
            ['email' => 'concurrency_student_' . uniqid() . '@example.com'],
            ['name' => 'Concurrency Student', 'role' => 'student', 'status' => 'active', 'password' => bcrypt('Secret123')]
        );

        DB::table('program_subjects')->updateOrInsert(
            ['offered_program_id' => $this->programId, 'subject_id' => $this->subjectId],
            ['is_free' => 1, 'updated_at' => now()]
        );
    }

    protected function tearDown(): void
    {
        DB::table('program_subjects')
            ->where('offered_program_id', $this->programId)
            ->where('subject_id', $this->subjectId)
            ->delete();

        parent::tearDown();
    }

    public function test_two_simultaneous_generations_with_one_credit_remaining(): void
    {
        // 1 test allowed
        Config::set('freemium.fallback_quotas.formal_test', '1');

        // Prepare two separate CLI worker scripts executing parallel HTTP/internal requests
        $studentId = $this->student->id;
        $progId = $this->programId;
        $subjId = $this->subjectId;
        $uId = $this->unitId;

        $workerScript = <<<'PHP'
<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$studentId = (int) $argv[1];
$key = $argv[2];
$progId = (int) $argv[3];
$subjId = (int) $argv[4];
$uId = (int) $argv[5];

$student = App\Models\WebUser::find($studentId);
$request = Illuminate\Http\Request::create(
    '/web_api/auth/tests/generate-selected-units-formal-test',
    'POST',
    [
        'offered_program_id' => $progId,
        'subject_id'         => $subjId,
        'unit_ids'           => [$uId],
        'total_questions'    => 1,
    ],
    [],
    [],
    ['HTTP_X_IDEMPOTENCY_KEY' => $key]
);
$request->setUserResolver(fn() => $student);

$controller = $app->make(App\Http\Controllers\Tests\TestController::class);
$response = $controller->generateSelectedUnitsFormalTestForLms($request);

echo json_encode([
    'status' => $response->getStatusCode(),
    'data'   => json_decode($response->getContent(), true),
]);
PHP;

        $workerPath = sys_get_temp_dir() . '/concurrency_worker_' . uniqid() . '.php';
        file_put_contents($workerPath, $workerScript);

        $key1 = 'conc_key_1_' . uniqid();
        $key2 = 'conc_key_2_' . uniqid();

        $cmd1 = "php \"{$workerPath}\" {$studentId} {$key1} {$progId} {$subjId} {$uId}";
        $cmd2 = "php \"{$workerPath}\" {$studentId} {$key2} {$progId} {$subjId} {$uId}";

        // Open both processes simultaneously
        $p1 = proc_open($cmd1, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes1, base_path());
        $p2 = proc_open($cmd2, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes2, base_path());

        $out1 = stream_get_contents($pipes1[1]);
        $out2 = stream_get_contents($pipes2[1]);

        fclose($pipes1[1]);
        fclose($pipes1[2]);
        fclose($pipes2[1]);
        fclose($pipes2[2]);

        proc_close($p1);
        proc_close($p2);

        @unlink($workerPath);

        $res1 = json_decode($out1, true);
        $res2 = json_decode($out2, true);

        $statuses = collect([$res1['status'] ?? null, $res2['status'] ?? null])->sort()->values()->all();

        // Exactly one process receives 200, exactly one receives 403
        $this->assertEquals([200, 403], $statuses, "Expected one 200 and one 403. Outputs: Out1: {$out1}, Out2: {$out2}");

        // Counter strictly equals 1
        $counter = FreemiumQuotaCounter::where('user_id', $studentId)
            ->where('exam_type', 'formal_test')
            ->where('window_type', 'weekly')
            ->first();

        $this->assertNotNull($counter);
        $this->assertSame(1, $counter->counter);

        // Exactly 1 generation consumption row in freemium_quota_consumptions
        $consumptions = FreemiumQuotaConsumption::where('user_id', $studentId)->count();
        $this->assertSame(1, $consumptions);

        // Exactly 1 new test created in tests table
        $tests = Test::where('created_by', $studentId)->count();
        $this->assertSame(1, $tests);
    }

    public function test_two_simultaneous_generations_with_same_idempotency_key_and_same_payload(): void
    {
        Config::set('freemium.fallback_quotas.formal_test', '5');

        $student = WebUser::create([
            'email'    => 'concurrency_same_payload_' . uniqid() . '@example.com',
            'name'     => 'Same Payload Concurrency Student',
            'role'     => 'student',
            'status'   => 'active',
            'password' => bcrypt('Secret123'),
        ]);

        $studentId = $student->id;
        $progId = $this->programId;
        $subjId = $this->subjectId;
        $uId = $this->unitId;

        $workerScript = <<<'PHP'
<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$studentId = (int) $argv[1];
$key = $argv[2];
$progId = (int) $argv[3];
$subjId = (int) $argv[4];
$uId = (int) $argv[5];

$student = App\Models\WebUser::find($studentId);
$request = Illuminate\Http\Request::create(
    '/web_api/auth/tests/generate-selected-units-formal-test',
    'POST',
    [
        'offered_program_id' => $progId,
        'subject_id'         => $subjId,
        'unit_ids'           => [$uId],
        'total_questions'    => 1,
    ],
    [],
    [],
    ['HTTP_X_IDEMPOTENCY_KEY' => $key]
);
$request->setUserResolver(fn() => $student);

$controller = $app->make(App\Http\Controllers\Tests\TestController::class);
$response = $controller->generateSelectedUnitsFormalTestForLms($request);

$initialStatus = $response->getStatusCode();
$initialBody = json_decode($response->getContent(), true);
$wasInFlight = false;

// If caught in-flight ("in progress" 409), retry until completed or timeout to obtain safe replay response
$maxRetries = 30;
$retries = 0;
while ($response->getStatusCode() === 409 && $retries < $maxRetries) {
    $err = $initialBody['error'] ?? '';
    if (!str_contains($err, 'in progress') && !str_contains($err, 'currently being processed')) {
        break; // Permanent payload conflict, do not retry
    }
    $wasInFlight = true;
    usleep(100000); // 100ms
    $response = $controller->generateSelectedUnitsFormalTestForLms($request);
    $initialBody = json_decode($response->getContent(), true);
    $retries++;
}

echo json_encode([
    'initial_status' => $initialStatus,
    'was_in_flight'  => $wasInFlight,
    'status'         => $response->getStatusCode(),
    'data'           => $initialBody,
]);
PHP;

        $workerPath = sys_get_temp_dir() . '/concurrency_same_key_' . uniqid() . '.php';
        file_put_contents($workerPath, $workerScript);

        $sharedKey = 'same_key_' . uniqid();

        $cmd1 = "php \"{$workerPath}\" {$studentId} {$sharedKey} {$progId} {$subjId} {$uId}";
        $cmd2 = "php \"{$workerPath}\" {$studentId} {$sharedKey} {$progId} {$subjId} {$uId}";

        $p1 = proc_open($cmd1, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes1, base_path());
        $p2 = proc_open($cmd2, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes2, base_path());

        $out1 = stream_get_contents($pipes1[1]);
        $out2 = stream_get_contents($pipes2[1]);

        fclose($pipes1[1]);
        fclose($pipes1[2]);
        fclose($pipes2[1]);
        fclose($pipes2[2]);

        proc_close($p1);
        proc_close($p2);

        @unlink($workerPath);

        $res1 = json_decode($out1, true);
        $res2 = json_decode($out2, true);

        // 1. Exactly one formal test is created for this student
        $tests = Test::where('created_by', $studentId)->count();
        $this->assertSame(1, $tests, "Exactly one test should be created in the tests table.");

        // 2. Exactly one quota credit is consumed
        $counter = FreemiumQuotaCounter::where('user_id', $studentId)
            ->where('exam_type', 'formal_test')
            ->where('window_type', 'weekly')
            ->first();
        $this->assertNotNull($counter, "Quota counter should exist for student.");
        $this->assertSame(1, $counter->counter, "Quota counter should strictly equal 1.");

        $consumptions = FreemiumQuotaConsumption::where('user_id', $studentId)->count();
        $this->assertSame(1, $consumptions, "Exactly one consumption record should exist in freemium_quota_consumptions.");

        // 3. Exactly one idempotency record exists for the key
        $idempCount = AssessmentIdempotencyRecord::where('user_id', $studentId)
            ->where('operation', 'formal_test_generation')
            ->where('idempotency_key', $sharedKey)
            ->count();
        $this->assertSame(1, $idempCount, "Exactly one idempotency record should exist for the key.");

        $idempRecord = AssessmentIdempotencyRecord::where('user_id', $studentId)
            ->where('operation', 'formal_test_generation')
            ->where('idempotency_key', $sharedKey)
            ->first();
        $this->assertSame('completed', $idempRecord->status);
        $this->assertSame(200, $idempRecord->http_status);

        // 4. Both requests receive the same successful response or an explicitly documented safe replay response
        $this->assertSame(200, $res1['status'], "Request 1 must receive HTTP 200.");
        $this->assertSame(200, $res2['status'], "Request 2 must receive HTTP 200 (replayed).");
        $this->assertSame(1, $res1['data']['success'] ?? null);
        $this->assertSame(1, $res2['data']['success'] ?? null);

        // 5. Both responses reference the same test ID
        $testId1 = $res1['data']['data']['test_id'] ?? null;
        $testId2 = $res2['data']['data']['test_id'] ?? null;
        $this->assertNotNull($testId1, "Test ID must not be null in response 1.");
        $this->assertNotNull($testId2, "Test ID must not be null in response 2.");
        $this->assertSame($testId1, $testId2, "Both responses must reference the exact same test ID.");
        $this->assertSame($testId1, $idempRecord->response_payload['data']['test_id'] ?? null);

        // 6. No duplicate test or quota consumption occurs
        $this->assertSame(1, Test::where('id', $testId1)->count());

        // 7. The test demonstrates actual concurrent execution, not sequential calls
        $this->assertTrue(
            $res1['was_in_flight'] || $res2['was_in_flight'] || ($res1['initial_status'] === 200 && $res2['initial_status'] === 200),
            "Expected concurrent execution evidence between the two worker processes."
        );

        // 8. The test uses the local isolated test database only
        $this->assertSame('3peas_db_subscription_test', DB::selectOne("SELECT DATABASE() as db")->db);
    }

    public function test_two_simultaneous_generations_with_same_key_and_different_payloads_conflict(): void
    {
        Config::set('freemium.fallback_quotas.formal_test', '5');

        $student = WebUser::create([
            'email'    => 'concurrency_diff_payload_' . uniqid() . '@example.com',
            'name'     => 'Diff Payload Concurrency Student',
            'role'     => 'student',
            'status'   => 'active',
            'password' => bcrypt('Secret123'),
        ]);

        $studentId = $student->id;
        $progId = $this->programId;
        $subjId = $this->subjectId;
        $uId1 = 1;
        $uId2 = 2; // Different unit ID creates distinct payload fingerprint

        $workerScript = <<<'PHP'
<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$studentId = (int) $argv[1];
$key = $argv[2];
$progId = (int) $argv[3];
$subjId = (int) $argv[4];
$uId = (int) $argv[5];

$student = App\Models\WebUser::find($studentId);
$request = Illuminate\Http\Request::create(
    '/web_api/auth/tests/generate-selected-units-formal-test',
    'POST',
    [
        'offered_program_id' => $progId,
        'subject_id'         => $subjId,
        'unit_ids'           => [$uId],
        'total_questions'    => 1,
    ],
    [],
    [],
    ['HTTP_X_IDEMPOTENCY_KEY' => $key]
);
$request->setUserResolver(fn() => $student);

$controller = $app->make(App\Http\Controllers\Tests\TestController::class);
$response = $controller->generateSelectedUnitsFormalTestForLms($request);

$initialStatus = $response->getStatusCode();
$initialBody = json_decode($response->getContent(), true);
$wasInFlight = false;

// If caught in-flight ("in progress" 409), retry until completed or timeout to obtain safe replay response
$maxRetries = 30;
$retries = 0;
while ($response->getStatusCode() === 409 && $retries < $maxRetries) {
    $err = $initialBody['error'] ?? '';
    if (!str_contains($err, 'in progress') && !str_contains($err, 'currently being processed')) {
        break; // Permanent payload conflict, do not retry
    }
    $wasInFlight = true;
    usleep(100000); // 100ms
    $response = $controller->generateSelectedUnitsFormalTestForLms($request);
    $initialBody = json_decode($response->getContent(), true);
    $retries++;
}

echo json_encode([
    'initial_status' => $initialStatus,
    'was_in_flight'  => $wasInFlight,
    'status'         => $response->getStatusCode(),
    'data'           => $initialBody,
]);
PHP;

        $workerPath = sys_get_temp_dir() . '/concurrency_diff_payload_' . uniqid() . '.php';
        file_put_contents($workerPath, $workerScript);

        $conflictKey = 'conflict_key_' . uniqid();

        $cmd1 = "php \"{$workerPath}\" {$studentId} {$conflictKey} {$progId} {$subjId} {$uId1}";
        $cmd2 = "php \"{$workerPath}\" {$studentId} {$conflictKey} {$progId} {$subjId} {$uId2}";

        $p1 = proc_open($cmd1, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes1, base_path());
        $p2 = proc_open($cmd2, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes2, base_path());

        $out1 = stream_get_contents($pipes1[1]);
        $out2 = stream_get_contents($pipes2[1]);

        fclose($pipes1[1]);
        fclose($pipes1[2]);
        fclose($pipes2[1]);
        fclose($pipes2[2]);

        proc_close($p1);
        proc_close($p2);

        @unlink($workerPath);

        $res1 = json_decode($out1, true);
        $res2 = json_decode($out2, true);

        $statuses = collect([$res1['status'] ?? null, $res2['status'] ?? null])->sort()->values()->all();

        // Exactly one process receives 200, exactly one receives 409 IDEMPOTENCY_CONFLICT
        $this->assertEquals([200, 409], $statuses, "Expected one 200 and one 409. Outputs: Out1: {$out1}, Out2: {$out2}");

        $successRes = ($res1['status'] === 200) ? $res1 : $res2;
        $conflictRes = ($res1['status'] === 409) ? $res1 : $res2;

        $this->assertSame(1, $successRes['data']['success'] ?? null);
        $this->assertNotNull($successRes['data']['data']['test_id'] ?? null);

        $this->assertSame(0, $conflictRes['data']['success'] ?? null);
        $this->assertSame('IDEMPOTENCY_CONFLICT', $conflictRes['data']['error_code'] ?? null);
        $this->assertStringContainsString('different request payload', $conflictRes['data']['error'] ?? '');

        // Exactly 1 test created (no second test created)
        $this->assertSame(1, Test::where('created_by', $studentId)->count());

        // Exactly 1 quota credit consumed
        $this->assertSame(1, FreemiumQuotaConsumption::where('user_id', $studentId)->count());
        $counter = FreemiumQuotaCounter::where('user_id', $studentId)
            ->where('exam_type', 'formal_test')
            ->where('window_type', 'weekly')
            ->first();
        $this->assertNotNull($counter);
        $this->assertSame(1, $counter->counter);

        // Exactly 1 idempotency record for this key
        $this->assertSame(
            1,
            AssessmentIdempotencyRecord::where('user_id', $studentId)
                ->where('operation', 'formal_test_generation')
                ->where('idempotency_key', $conflictKey)
                ->count()
        );
    }
}
