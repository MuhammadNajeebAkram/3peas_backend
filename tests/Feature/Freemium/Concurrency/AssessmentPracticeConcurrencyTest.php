<?php

namespace Tests\Feature\Freemium\Concurrency;

use App\Models\AssessmentIdempotencyRecord;
use App\Models\Freemium\FreemiumQuotaConsumption;
use App\Models\Freemium\FreemiumQuotaCounter;
use App\Models\PracticeSession;
use App\Models\PracticeSessionQuestion;
use App\Models\PracticeSessionScope;
use App\Models\WebUser;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssessmentPracticeConcurrencyTest extends TestCase
{
    private WebUser $student;
    private int $programId = 994;
    private int $subjectId = 1;
    private int $unitId1 = 1;
    private int $unitId2 = 2;

    protected function setUp(): void
    {
        parent::setUp();

        $activeDb = DB::selectOne("SELECT DATABASE() as db")->db ?? '';
        if ($activeDb !== '3peas_db_subscription_test') {
            $this->markTestSkipped("Concurrency test strictly runs against 3peas_db_subscription_test.");
        }

        $this->student = WebUser::firstOrCreate(
            ['email' => 'practice_conc_student_' . uniqid() . '@example.com'],
            ['name' => 'Practice Concurrency Student', 'role' => 'student', 'status' => 'active', 'password' => bcrypt('Secret123')]
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
        // 1 practice session allowed
        Config::set('freemium.fallback_quotas.practice', '1');

        $studentId = $this->student->id;
        $progId = $this->programId;
        $subjId = $this->subjectId;
        $uId1 = $this->unitId1;
        $uId2 = $this->unitId2;

        $workerScript = <<<'PHP'
<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
Illuminate\Support\Facades\Config::set('freemium.fallback_quotas.practice', '1');

$studentId = (int) $argv[1];
$key = $argv[2];
$progId = (int) $argv[3];
$subjId = (int) $argv[4];
$uId = (int) $argv[5];

$student = App\Models\WebUser::find($studentId);
$request = Illuminate\Http\Request::create(
    '/web_api/auth/tests/generate-selected-units-practice-session',
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

$controller = $app->make(App\Http\Controllers\Tests\PracticeSessionController::class);
$response = $controller->generateSelectedUnitsPracticeSessionForLms($request);

echo json_encode([
    'status' => $response->getStatusCode(),
    'data'   => json_decode($response->getContent(), true),
]);
PHP;

        $workerPath = sys_get_temp_dir() . '/practice_conc_worker_' . uniqid() . '.php';
        file_put_contents($workerPath, $workerScript);

        $key1 = 'prac_conc_key_1_' . uniqid();
        $key2 = 'prac_conc_key_2_' . uniqid();

        // Process 1 targets unit 1, Process 2 targets unit 2 (distinct scopes so neither can resume the other)
        $cmd1 = "php \"{$workerPath}\" {$studentId} {$key1} {$progId} {$subjId} {$uId1}";
        $cmd2 = "php \"{$workerPath}\" {$studentId} {$key2} {$progId} {$subjId} {$uId2}";

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
            ->where('exam_type', 'practice')
            ->where('window_type', 'weekly')
            ->first();

        $this->assertNotNull($counter);
        $this->assertSame(1, $counter->counter);

        // Exactly 1 generation consumption row in freemium_quota_consumptions
        $consumptions = FreemiumQuotaConsumption::where('user_id', $studentId)->count();
        $this->assertSame(1, $consumptions);

        // Exactly 1 new session created in practice_sessions table
        $sessions = PracticeSession::where('user_id', $studentId)->count();
        $this->assertSame(1, $sessions);
    }

    public function test_two_simultaneous_generations_with_same_idempotency_key_and_same_payload(): void
    {
        Config::set('freemium.fallback_quotas.practice', '5');

        $student = WebUser::create([
            'email'    => 'practice_same_payload_' . uniqid() . '@example.com',
            'name'     => 'Same Payload Practice Student',
            'role'     => 'student',
            'status'   => 'active',
            'password' => bcrypt('Secret123'),
        ]);

        $studentId = $student->id;
        $progId = $this->programId;
        $subjId = $this->subjectId;
        $uId = $this->unitId1;

        $workerScript = <<<'PHP'
<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
Illuminate\Support\Facades\Config::set('freemium.fallback_quotas.practice', '1');

$studentId = (int) $argv[1];
$key = $argv[2];
$progId = (int) $argv[3];
$subjId = (int) $argv[4];
$uId = (int) $argv[5];

$student = App\Models\WebUser::find($studentId);
$request = Illuminate\Http\Request::create(
    '/web_api/auth/tests/generate-selected-units-practice-session',
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

$controller = $app->make(App\Http\Controllers\Tests\PracticeSessionController::class);
$response = $controller->generateSelectedUnitsPracticeSessionForLms($request);

$initialStatus = $response->getStatusCode();
$initialBody = json_decode($response->getContent(), true);
$wasInFlight = false;

// If caught in-flight ("in progress" 409), retry until completed to obtain safe replay response
$maxRetries = 30;
$retries = 0;
while ($response->getStatusCode() === 409 && $retries < $maxRetries) {
    $err = $initialBody['error'] ?? '';
    if (!str_contains($err, 'in progress') && !str_contains($err, 'currently being processed')) {
        break;
    }
    $wasInFlight = true;
    usleep(100000); // 100ms
    $response = $controller->generateSelectedUnitsPracticeSessionForLms($request);
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

        $workerPath = sys_get_temp_dir() . '/practice_same_key_worker_' . uniqid() . '.php';
        file_put_contents($workerPath, $workerScript);

        $sharedKey = 'prac_shared_idemp_key_' . uniqid();

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

        // Both requests MUST succeed with 200 (one through creation, one through idempotent replay)
        $this->assertSame(200, $res1['status'] ?? null, "Process 1 failed: {$out1}");
        $this->assertSame(200, $res2['status'] ?? null, "Process 2 failed: {$out2}");

        // Both responses MUST reference the exact same session_id
        $sessionId1 = $res1['data']['data']['session_id'] ?? null;
        $sessionId2 = $res2['data']['data']['session_id'] ?? null;
        $this->assertNotNull($sessionId1);
        $this->assertSame($sessionId1, $sessionId2, "Both processes must return the same practice session ID");

        // Exactly ONE practice session created in practice_sessions
        $sessionsCount = PracticeSession::where('user_id', $studentId)->count();
        $this->assertSame(1, $sessionsCount, "Duplicate practice sessions created under same idempotency key");

        // Exactly ONE quota consumption row created
        $consumptionsCount = FreemiumQuotaConsumption::where('user_id', $studentId)->count();
        $this->assertSame(1, $consumptionsCount, "Duplicate quota consumed under same idempotency key");

        // Exactly ONE idempotency record in 'completed' state
        $idempRecords = AssessmentIdempotencyRecord::where('user_id', $studentId)
            ->where('operation', 'practice_session_generation')
            ->where('idempotency_key', $sharedKey)
            ->get();
        $this->assertCount(1, $idempRecords);
        $this->assertSame('completed', $idempRecords->first()->status);

        // Counter strictly equals 1
        $counter = FreemiumQuotaCounter::where('user_id', $studentId)
            ->where('exam_type', 'practice')
            ->where('window_type', 'weekly')
            ->first();
        $this->assertNotNull($counter);
        $this->assertSame(1, $counter->counter);
    }

    public function test_concurrent_active_session_resumptions_consume_zero_quota(): void
    {
        // 1 quota total
        Config::set('freemium.fallback_quotas.practice', '1');

        $student = WebUser::create([
            'email'    => 'practice_resumption_conc_' . uniqid() . '@example.com',
            'name'     => 'Resumption Concurrency Student',
            'role'     => 'student',
            'status'   => 'active',
            'password' => bcrypt('Secret123'),
        ]);

        $studentId = $student->id;
        $progId = $this->programId;
        $subjId = $this->subjectId;
        $uId = $this->unitId1;

        // Create an existing active session with scope
        $existingSession = PracticeSession::create([
            'user_id'                 => $studentId,
            'offered_program_id'      => $progId,
            'subject_id'              => $subjId,
            'scope_type'              => 'chapter',
            'question_type'           => 'mcq',
            'total_questions'         => 1,
            'attempted_questions'     => 0,
            'correct_answers'         => 0,
            'wrong_answers'           => 0,
            'not_attempted_questions' => 1,
            'score'                   => 0,
            'accuracy'                => 0,
            'started_at'              => now(),
            'status'                  => 'in_progress',
        ]);

        PracticeSessionScope::create([
            'session_id' => $existingSession->id,
            'unit_id'    => $uId,
        ]);

        $workerScript = <<<'PHP'
<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
Illuminate\Support\Facades\Config::set('freemium.fallback_quotas.practice', '1');

$studentId = (int) $argv[1];
$key = $argv[2];
$progId = (int) $argv[3];
$subjId = (int) $argv[4];
$uId = (int) $argv[5];

$student = App\Models\WebUser::find($studentId);
$request = Illuminate\Http\Request::create(
    '/web_api/auth/tests/generate-selected-units-practice-session',
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

$controller = $app->make(App\Http\Controllers\Tests\PracticeSessionController::class);
$response = $controller->generateSelectedUnitsPracticeSessionForLms($request);

echo json_encode([
    'status' => $response->getStatusCode(),
    'data'   => json_decode($response->getContent(), true),
]);
PHP;

        $workerPath = sys_get_temp_dir() . '/practice_resump_worker_' . uniqid() . '.php';
        file_put_contents($workerPath, $workerScript);

        $key1 = 'resump_conc_key_1_' . uniqid();
        $key2 = 'resump_conc_key_2_' . uniqid();

        $cmd1 = "php \"{$workerPath}\" {$studentId} {$key1} {$progId} {$subjId} {$uId}";
        $cmd2 = "php \"{$workerPath}\" {$studentId} {$key2} {$progId} {$subjId} {$uId}";

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

        // Both requests MUST succeed with 200 by reusing active session
        $this->assertSame(200, $res1['status'] ?? null, "Process 1 failed: {$out1}");
        $this->assertSame(200, $res2['status'] ?? null, "Process 2 failed: {$out2}");

        // Both responses MUST reference the exact existing session_id
        $this->assertSame($existingSession->id, $res1['data']['data']['session_id'] ?? null);
        $this->assertSame($existingSession->id, $res2['data']['data']['session_id'] ?? null);

        // Zero additional practice sessions created
        $this->assertSame(1, PracticeSession::where('user_id', $studentId)->count());

        // ZERO quota consumed
        $this->assertSame(0, FreemiumQuotaConsumption::where('user_id', $studentId)->count());
    }

    public function test_concurrent_progress_saving_and_submission(): void
    {
        $student = WebUser::create([
            'email'    => 'practice_race_student_' . uniqid() . '@example.com',
            'name'     => 'Race Practice Student',
            'role'     => 'student',
            'status'   => 'active',
            'password' => bcrypt('Secret123'),
        ]);

        $studentId = $student->id;

        $session = PracticeSession::create([
            'user_id'                 => $studentId,
            'offered_program_id'      => $this->programId,
            'subject_id'              => $this->subjectId,
            'scope_type'              => 'chapter',
            'question_type'           => 'mcq',
            'total_questions'         => 1,
            'attempted_questions'     => 0,
            'correct_answers'         => 0,
            'wrong_answers'           => 0,
            'not_attempted_questions' => 1,
            'score'                   => 0,
            'accuracy'                => 0,
            'started_at'              => now(),
            'status'                  => 'in_progress',
        ]);

        $questionId = DB::table('exam_question_tbl as q')
            ->join('book_unit_topic_tbl as t', 't.id', '=', 'q.topic_id')
            ->where('t.unit_id', $this->unitId1)
            ->where('q.activate', 1)
            ->value('q.id');

        $this->assertNotNull($questionId, "Valid question fixture required for progress save test");

        PracticeSessionScope::create([
            'session_id' => $session->id,
            'unit_id'    => $this->unitId1,
        ]);

        PracticeSessionQuestion::create([
            'session_id'     => $session->id,
            'question_id'    => $questionId,
            'question_order' => 1,
            'is_attempted'   => false,
        ]);

        $workerScript = <<<'PHP'
<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
Illuminate\Support\Facades\Config::set('freemium.fallback_quotas.practice', '1');

$studentId = (int) $argv[1];
$sessionId = (int) $argv[2];
$action = $argv[3];
$questionId = (int) $argv[4];

$student = App\Models\WebUser::find($studentId);
$controller = $app->make(App\Http\Controllers\Tests\PracticeSessionController::class);

if ($action === 'save') {
    $request = Illuminate\Http\Request::create(
        '/web_api/auth/tests/save-practice-session-question-progress',
        'POST',
        [
            'session_id'         => $sessionId,
            'question_id'        => $questionId,
            'time_spent_seconds' => 10,
        ]
    );
    $request->setUserResolver(fn() => $student);
    $response = $controller->savePracticeSessionQuestionProgressForLms($request);
} else {
    $request = Illuminate\Http\Request::create(
        '/web_api/auth/tests/submit-practice-session',
        'POST',
        [
            'session_id' => $sessionId,
        ]
    );
    $request->setUserResolver(fn() => $student);
    $response = $controller->submitPracticeSessionForLms($request);
}

echo json_encode([
    'status' => $response->getStatusCode(),
    'data'   => json_decode($response->getContent(), true),
]);
PHP;

        $workerPath = sys_get_temp_dir() . '/practice_race_worker_' . uniqid() . '.php';
        file_put_contents($workerPath, $workerScript);

        $cmd1 = "php \"{$workerPath}\" {$studentId} {$session->id} save {$questionId}";
        $cmd2 = "php \"{$workerPath}\" {$studentId} {$session->id} submit {$questionId}";

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

        $res1 = json_decode($out1, true); // save
        $res2 = json_decode($out2, true); // submit

        // Submit MUST succeed with 200
        $this->assertSame(200, $res2['status'] ?? null, "Submit failed: {$out2}");

        // Save progress must either succeed with 200 (if executed before submit) or fail with 422 (if submit finished first)
        $this->assertContains($res1['status'] ?? null, [200, 422], "Autosave returned invalid status: {$out1}");

        // Final session status must be 'submitted'
        $finalSession = PracticeSession::find($session->id);
        $this->assertSame('submitted', $finalSession->status);
    }
}
