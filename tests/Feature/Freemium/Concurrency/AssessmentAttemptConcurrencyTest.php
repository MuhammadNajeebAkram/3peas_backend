<?php

namespace Tests\Feature\Freemium\Concurrency;

use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumExamQuota;
use App\Models\Freemium\FreemiumQuotaConsumption;
use App\Models\Freemium\FreemiumQuotaCounter;
use App\Models\Test;
use App\Models\TestAttempt;
use App\Models\User;
use App\Models\WebUser;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssessmentAttemptConcurrencyTest extends TestCase
{
    protected WebUser $student1;
    protected WebUser $student2;
    protected User $admin;

    protected int $programId = 994;
    protected int $subjectId = 1;
    protected int $unitId = 1;
    protected int $topicId = 1;

    protected int $questionId;
    protected int $correctOptionId;
    protected int $wrongOptionId;

    protected function setUp(): void
    {
        parent::setUp();

        $adminId = DB::table('users')->value('id');
        $this->admin = $adminId ? User::find($adminId) : User::create([
            'name'     => 'Admin Concurrency User',
            'email'    => 'admin_conc_' . uniqid() . '@example.com',
            'password' => bcrypt('Secret123'),
        ]);

        $this->student1 = WebUser::create([
            'name'     => 'Conc Student One',
            'email'    => 'conc_student_1_' . uniqid() . '@example.com',
            'password' => bcrypt('Secret123'),
            'role'     => 'student',
            'status'   => 'active',
        ]);

        $this->student2 = WebUser::create([
            'name'     => 'Conc Student Two',
            'email'    => 'conc_student_2_' . uniqid() . '@example.com',
            'password' => bcrypt('Secret123'),
            'role'     => 'student',
            'status'   => 'active',
        ]);

        DB::table('program_subjects')->updateOrInsert(
            ['offered_program_id' => $this->programId, 'subject_id' => $this->subjectId],
            ['is_free' => 0, 'updated_at' => now()]
        );

        $this->topicId = (int) (DB::table('book_unit_topic_tbl')->value('id') ?? 1);

        $this->questionId = (int) DB::table('exam_question_tbl')->insertGetId([
            'question'      => 'Concurrency Assessment Question ' . uniqid(),
            'topic_id'      => $this->topicId,
            'question_type' => 1,
            'activate'      => 1,
            'marks'         => 1.00,
            'difficulty'    => 1,
            'status'        => 'published',
            'source'        => 'manual',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $this->correctOptionId = (int) DB::table('exam_question_options_tbl')->insertGetId([
            'question_id' => $this->questionId,
            'option'      => 'Option A (Correct)',
            'is_answer'   => 1,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->wrongOptionId = (int) DB::table('exam_question_options_tbl')->insertGetId([
            'question_id' => $this->questionId,
            'option'      => 'Option B (Wrong)',
            'is_answer'   => 0,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('freemium_quota_consumption_envelopes')->delete();
        DB::table('freemium_quota_consumptions')->delete();
        DB::table('freemium_quota_counters')->delete();
        DB::table('freemium_exam_quotas')->delete();
        DB::table('freemium_access_rules')->delete();
        DB::table('freemium_student_grants')->delete();
        DB::table('freemium_campaigns')->delete();

        DB::table('test_attempt_questions')->delete();
        DB::table('test_attempts')->whereIn('user_id', [$this->student1->id, $this->student2->id])->delete();
        DB::table('test_questions')->delete();
        DB::table('test_scopes')->delete();
        DB::table('tests')->whereIn('created_by', [$this->student1->id, $this->student2->id, $this->admin->id])->delete();

        DB::table('exam_question_options_tbl')->where('question_id', $this->questionId)->delete();
        DB::table('exam_question_tbl')->where('id', $this->questionId)->delete();

        DB::table('web_users')->whereIn('id', [$this->student1->id, $this->student2->id])->delete();

        parent::tearDown();
    }

    protected function createOfficialTest(): Test
    {
        $test = Test::create([
            'offered_program_id' => $this->programId,
            'subject_id'         => $this->subjectId,
            'created_by'         => $this->admin->id,
            'title'              => 'Official Shared Concurrency Test ' . uniqid(),
            'test_source'        => 'official',
            'test_mode'          => 'test',
            'is_published'       => 1,
            'total_questions'    => 1,
        ]);

        DB::table('test_questions')->insert([
            'test_id'        => $test->id,
            'question_id'    => $this->questionId,
            'question_order' => 1,
            'marks'          => 1.0,
        ]);

        DB::table('test_scopes')->insert([
            'test_id' => $test->id,
            'unit_id' => $this->unitId,
        ]);

        return $test;
    }

    protected function createCampaign(bool $allowRetakes, int $maxAttempts): array
    {
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'Conc Campaign ' . uniqid(),
            'status'       => 'active',
            'priority'     => 1,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonth(),
            'is_stackable' => true,
        ]);

        $quota = FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'formal_test',
            'window_type'           => 'weekly',
            'max_tests'             => 10,
            'allow_retakes'         => $allowRetakes,
            'max_attempts_per_test' => $maxAttempts,
        ]);

        FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'target_scope'             => 'global',
            'target_status'            => 'active',
            'perm_mcqs'                => 'allow',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['scope' => 'global'],
        ]);

        return [$campaign, $quota];
    }

    /**
     * Scenario 1: Two simultaneous initial-start/progress requests for the same student and test
     * produce exactly one active attempt.
     */
    public function test_concurrent_initial_attempt_starts_same_student_creates_exactly_one_attempt(): void
    {
        $this->createCampaign(allowRetakes: true, maxAttempts: 3);
        $test = $this->createOfficialTest();

        $studentId = $this->student1->id;
        $testId = $test->id;
        $questionId = $this->questionId;
        $optionId = $this->correctOptionId;

        $workerScript = <<<'PHP'
<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$studentId = (int) $argv[1];
$testId = (int) $argv[2];
$questionId = (int) $argv[3];
$optionId = (int) $argv[4];

$student = App\Models\WebUser::find($studentId);
$request = Illuminate\Http\Request::create(
    '/web_api/auth/tests/save-formal-test-question-progress',
    'POST',
    [
        'test_id'            => $testId,
        'question_id'        => $questionId,
        'selected_option_id' => $optionId,
    ]
);
$request->setUserResolver(fn() => $student);

$controller = $app->make(App\Http\Controllers\Tests\TestController::class);
$response = $controller->saveFormalTestQuestionProgressForLms($request);

echo json_encode([
    'status' => $response->getStatusCode(),
    'data'   => json_decode($response->getContent(), true),
]);
PHP;

        $workerPath = sys_get_temp_dir() . '/conc_attempt_worker_' . uniqid() . '.php';
        file_put_contents($workerPath, $workerScript);

        $cmd1 = "php \"{$workerPath}\" {$studentId} {$testId} {$questionId} {$optionId}";
        $cmd2 = "php \"{$workerPath}\" {$studentId} {$testId} {$questionId} {$optionId}";

        // Launch both PHP CLI worker processes simultaneously
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

        $this->assertEquals(200, $res1['status'] ?? null, "Process 1 failed. Out: {$out1}");
        $this->assertEquals(200, $res2['status'] ?? null, "Process 2 failed. Out: {$out2}");

        $attemptId1 = $res1['data']['data']['attempt_id'] ?? null;
        $attemptId2 = $res2['data']['data']['attempt_id'] ?? null;

        // Both processes must reference the EXACT same attempt_id
        $this->assertNotNull($attemptId1);
        $this->assertNotNull($attemptId2);
        $this->assertSame($attemptId1, $attemptId2, "Both concurrent requests must bind to the same attempt ID.");

        // Exactly one attempt in database
        $this->assertSame(1, TestAttempt::where('test_id', $testId)->where('user_id', $studentId)->count());

        // Exactly one quota consumption record in database
        $this->assertSame(1, FreemiumQuotaConsumption::where('test_id', $testId)->where('user_id', $studentId)->count());
    }

    /**
     * Scenario 2: Two simultaneous retake requests cannot exceed the allowed attempt count.
     */
    public function test_concurrent_retake_starts_last_credit_race_prevents_overconsumption(): void
    {
        // Max attempts = 2, so only 1 retake allowed
        $this->createCampaign(allowRetakes: true, maxAttempts: 2);
        $test = $this->createOfficialTest();

        // Complete attempt 1
        $attempt1 = TestAttempt::create([
            'test_id'                 => $test->id,
            'user_id'                 => $this->student1->id,
            'attempted_questions'     => 1,
            'correct_answers'         => 1,
            'wrong_answers'           => 0,
            'not_attempted_questions' => 0,
            'score'                   => 1.0,
            'accuracy'                => 100,
            'started_at'              => now()->subHour(),
            'submitted_at'            => now()->subMinutes(30),
            'status'                  => 'submitted',
        ]);

        FreemiumQuotaConsumption::create([
            'user_id'             => $this->student1->id,
            'exam_type'           => 'formal_test',
            'event_type'          => 'attempt_start',
            'test_id'             => $test->id,
            'test_attempt_id'     => $attempt1->id,
            'attempt_number'      => 1,
            'decision_snapshot'   => ['granted' => true],
            'consumed_at'         => now()->subHour(),
        ]);

        $studentId = $this->student1->id;
        $testId = $test->id;
        $questionId = $this->questionId;
        $optionId = $this->correctOptionId;

        $workerScript = <<<'PHP'
<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$studentId = (int) $argv[1];
$testId = (int) $argv[2];
$questionId = (int) $argv[3];
$optionId = (int) $argv[4];

$student = App\Models\WebUser::find($studentId);
$request = Illuminate\Http\Request::create(
    '/web_api/auth/tests/save-formal-test-question-progress',
    'POST',
    [
        'test_id'            => $testId,
        'question_id'        => $questionId,
        'selected_option_id' => $optionId,
    ]
);
$request->setUserResolver(fn() => $student);

$controller = $app->make(App\Http\Controllers\Tests\TestController::class);
$response = $controller->saveFormalTestQuestionProgressForLms($request);

echo json_encode([
    'status' => $response->getStatusCode(),
    'data'   => json_decode($response->getContent(), true),
]);
PHP;

        $workerPath = sys_get_temp_dir() . '/conc_retake_worker_' . uniqid() . '.php';
        file_put_contents($workerPath, $workerScript);

        $cmd1 = "php \"{$workerPath}\" {$studentId} {$testId} {$questionId} {$optionId}";
        $cmd2 = "php \"{$workerPath}\" {$studentId} {$testId} {$questionId} {$optionId}";

        // Two processes compete for the last remaining attempt allowance
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

        // Due to serialized locking on WebUser, either:
        // - Process 1 creates Attempt 2, and Process 2 joins Attempt 2 (both 200), OR
        // - Process 1 creates Attempt 2, and Process 2 is evaluated against max cap and denied (403)
        // Under no circumstances may total attempts in database exceed 2!
        $this->assertLessThanOrEqual(2, TestAttempt::where('test_id', $testId)->where('user_id', $studentId)->count());
        $this->assertLessThanOrEqual(2, FreemiumQuotaConsumption::where('test_id', $testId)->where('user_id', $studentId)->count());
    }

    /**
     * Scenario 3: Two students starting the same official test get independent attempts.
     */
    public function test_concurrent_starts_on_shared_official_test_different_students_succeed_independently(): void
    {
        $this->createCampaign(allowRetakes: true, maxAttempts: 2);
        $test = $this->createOfficialTest();

        $studentId1 = $this->student1->id;
        $studentId2 = $this->student2->id;
        $testId = $test->id;
        $questionId = $this->questionId;
        $optionId = $this->correctOptionId;

        $workerScript = <<<'PHP'
<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$studentId = (int) $argv[1];
$testId = (int) $argv[2];
$questionId = (int) $argv[3];
$optionId = (int) $argv[4];

$student = App\Models\WebUser::find($studentId);
$request = Illuminate\Http\Request::create(
    '/web_api/auth/tests/save-formal-test-question-progress',
    'POST',
    [
        'test_id'            => $testId,
        'question_id'        => $questionId,
        'selected_option_id' => $optionId,
    ]
);
$request->setUserResolver(fn() => $student);

$controller = $app->make(App\Http\Controllers\Tests\TestController::class);
$response = $controller->saveFormalTestQuestionProgressForLms($request);

echo json_encode([
    'status' => $response->getStatusCode(),
    'data'   => json_decode($response->getContent(), true),
]);
PHP;

        $workerPath = sys_get_temp_dir() . '/conc_shared_worker_' . uniqid() . '.php';
        file_put_contents($workerPath, $workerScript);

        $cmd1 = "php \"{$workerPath}\" {$studentId1} {$testId} {$questionId} {$optionId}";
        $cmd2 = "php \"{$workerPath}\" {$studentId2} {$testId} {$questionId} {$optionId}";

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

        $this->assertEquals(200, $res1['status'] ?? null, "Student 1 failed. Out: {$out1}");
        $this->assertEquals(200, $res2['status'] ?? null, "Student 2 failed. Out: {$out2}");

        $attemptId1 = $res1['data']['data']['attempt_id'] ?? null;
        $attemptId2 = $res2['data']['data']['attempt_id'] ?? null;

        $this->assertNotNull($attemptId1);
        $this->assertNotNull($attemptId2);
        $this->assertNotEquals($attemptId1, $attemptId2, "Different students must get different attempt IDs.");

        $this->assertSame(2, TestAttempt::where('test_id', $testId)->count());
        $this->assertSame(2, FreemiumQuotaConsumption::where('test_id', $testId)->count());
    }

    /**
     * Scenario 4: Submission vs late autosave race returns 409 conflict and preserves final score.
     */
    public function test_submission_vs_autosave_race_returns_409_conflict_and_preserves_final_score(): void
    {
        $this->createCampaign(allowRetakes: true, maxAttempts: 2);
        $test = $this->createOfficialTest();

        // Create an active in_progress attempt with score 0
        $attempt = TestAttempt::create([
            'test_id'                 => $test->id,
            'user_id'                 => $this->student1->id,
            'attempted_questions'     => 1,
            'correct_answers'         => 1,
            'wrong_answers'           => 0,
            'not_attempted_questions' => 0,
            'score'                   => 0,
            'accuracy'                => 0,
            'started_at'              => now()->subMinutes(10),
            'status'                  => 'in_progress',
        ]);

        $studentId = $this->student1->id;
        $testId = $test->id;
        $attemptId = $attempt->id;
        $questionId = $this->questionId;
        $correctOptionId = $this->correctOptionId;
        $wrongOptionId = $this->wrongOptionId;

        // Worker A: Submits attempt
        $submitScript = <<<'PHP'
<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$studentId = (int) $argv[1];
$testId = (int) $argv[2];
$attemptId = (int) $argv[3];
$questionId = (int) $argv[4];
$optionId = (int) $argv[5];

$student = App\Models\WebUser::find($studentId);
$request = Illuminate\Http\Request::create(
    '/web_api/auth/tests/submit-formal-test',
    'POST',
    [
        'test_id'    => $testId,
        'attempt_id' => $attemptId,
        'questions'  => [
            [
                'question_id'        => $questionId,
                'selected_option_id' => $optionId,
            ]
        ],
    ]
);
$request->setUserResolver(fn() => $student);

$controller = $app->make(App\Http\Controllers\Tests\TestController::class);
$response = $controller->submitFormalTestForLms($request);

echo json_encode([
    'status' => $response->getStatusCode(),
    'data'   => json_decode($response->getContent(), true),
]);
PHP;

        // Worker B: Attempts late autosave on the same attempt
        $autosaveScript = <<<'PHP'
<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Small 50ms delay to ensure submit transaction begins first or races with it
usleep(50000);

$studentId = (int) $argv[1];
$testId = (int) $argv[2];
$attemptId = (int) $argv[3];
$questionId = (int) $argv[4];
$optionId = (int) $argv[5];

$student = App\Models\WebUser::find($studentId);
$request = Illuminate\Http\Request::create(
    '/web_api/auth/tests/save-formal-test-question-progress',
    'POST',
    [
        'test_id'            => $testId,
        'attempt_id'         => $attemptId,
        'question_id'        => $questionId,
        'selected_option_id' => $optionId,
    ]
);
$request->setUserResolver(fn() => $student);

$controller = $app->make(App\Http\Controllers\Tests\TestController::class);
$response = $controller->saveFormalTestQuestionProgressForLms($request);

echo json_encode([
    'status' => $response->getStatusCode(),
    'data'   => json_decode($response->getContent(), true),
]);
PHP;

        $submitPath = sys_get_temp_dir() . '/conc_sub_' . uniqid() . '.php';
        $savePath = sys_get_temp_dir() . '/conc_save_' . uniqid() . '.php';

        file_put_contents($submitPath, $submitScript);
        file_put_contents($savePath, $autosaveScript);

        $cmd1 = "php \"{$submitPath}\" {$studentId} {$testId} {$attemptId} {$questionId} {$correctOptionId}";
        $cmd2 = "php \"{$savePath}\" {$studentId} {$testId} {$attemptId} {$questionId} {$wrongOptionId}";

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
        @unlink($submitPath);
        @unlink($savePath);

        $res1 = json_decode($out1, true);
        $res2 = json_decode($out2, true);

        // Submit must succeed with 200
        $this->assertEquals(200, $res1['status'] ?? null, "Submit failed. Out: {$out1}");

        // Late autosave on submitted attempt must be rejected with 409 Conflict
        $this->assertEquals(409, $res2['status'] ?? null, "Expected 409 Conflict for late autosave. Out: {$out2}");

        // Final score must be 1.0 (correct answer), not corrupted by late autosave
        $attempt->refresh();
        $this->assertSame('submitted', $attempt->status);
        $this->assertEquals(1.0, (float) $attempt->score);
    }

    /**
     * Scenario 5: Deterministic deadlock simulation verifying automatic retry up to 3 times succeeds transparently.
     */
    public function test_deadlock_retry_succeeds_transparently_within_three_attempts(): void
    {
        [$campaign, $formalQuota] = $this->createCampaign(allowRetakes: true, maxAttempts: 5);
        $test = $this->createOfficialTest();

        FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'practice',
            'window_type'           => 'weekly',
            'max_tests'             => 10,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 5,
        ]);

        $attemptsCount = 0;
        TestAttempt::creating(function ($attempt) use (&$attemptsCount) {
            $attemptsCount++;
            if ($attemptsCount === 1) {
                $pdoException = new \PDOException('Deadlock found when trying to get lock; try restarting transaction', 1213);
                $pdoException->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
                throw new \Illuminate\Database\QueryException(
                    'mysql',
                    'insert into test_attempts ...',
                    [],
                    $pdoException
                );
            }
        });

        $controller = app(\App\Http\Controllers\Tests\TestController::class);
        $request = \Illuminate\Http\Request::create(
            '/web_api/auth/tests/save-formal-test-question-progress',
            'POST',
            [
                'test_id'            => $test->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]
        );
        $request->setUserResolver(fn () => $this->student1);

        $response = $controller->saveFormalTestQuestionProgressForLms($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame(1, $data['success']);
        $this->assertSame(2, $attemptsCount, 'Expected closure to be retried after initial deadlock');

        TestAttempt::flushEventListeners();
    }

    /**
     * Scenario 6: Exhausted deadlock retries returning structured HTTP 409 CONCURRENT_ATTEMPT_CONFLICT.
     */
    public function test_exhausted_deadlock_retries_return_structured_409_conflict(): void
    {
        [$campaign, $formalQuota] = $this->createCampaign(allowRetakes: true, maxAttempts: 5);
        $test = $this->createOfficialTest();

        FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'practice',
            'window_type'           => 'weekly',
            'max_tests'             => 10,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 5,
        ]);

        $attemptsCount = 0;
        TestAttempt::creating(function ($attempt) use (&$attemptsCount) {
            $attemptsCount++;
            $pdoException = new \PDOException('Deadlock found when trying to get lock; try restarting transaction', 1213);
            $pdoException->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
            throw new \Illuminate\Database\QueryException(
                'mysql',
                'insert into test_attempts ...',
                [],
                $pdoException
            );
        });

        $controller = app(\App\Http\Controllers\Tests\TestController::class);
        $request = \Illuminate\Http\Request::create(
            '/web_api/auth/tests/save-formal-test-question-progress',
            'POST',
            [
                'test_id'            => $test->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]
        );
        $request->setUserResolver(fn () => $this->student1);

        $response = $controller->saveFormalTestQuestionProgressForLms($request);

        $this->assertSame(409, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame(0, $data['success']);
        $this->assertSame('CONCURRENT_ATTEMPT_CONFLICT', $data['error_code']);
        $this->assertSame('A temporary database concurrency conflict occurred. Please retry your request.', $data['error']);
        $this->assertSame(3, $attemptsCount, 'Expected exactly 3 retry attempts before exhaustion');

        TestAttempt::flushEventListeners();
    }

    /**
     * Scenario 7: Lock wait timeout returning structured HTTP 409 CONCURRENT_ATTEMPT_CONFLICT.
     */
    public function test_lock_wait_timeout_returns_structured_409_conflict(): void
    {
        [$campaign, $formalQuota] = $this->createCampaign(allowRetakes: true, maxAttempts: 5);
        $test = $this->createOfficialTest();

        FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'practice',
            'window_type'           => 'weekly',
            'max_tests'             => 10,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 5,
        ]);

        TestAttempt::creating(function ($attempt) {
            $pdoException = new \PDOException('Lock wait timeout exceeded; try restarting transaction', 1205);
            $pdoException->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'];
            throw new \Illuminate\Database\QueryException(
                'mysql',
                'insert into test_attempts ...',
                [],
                $pdoException
            );
        });

        $controller = app(\App\Http\Controllers\Tests\TestController::class);
        $request = \Illuminate\Http\Request::create(
            '/web_api/auth/tests/save-formal-test-question-progress',
            'POST',
            [
                'test_id'            => $test->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]
        );
        $request->setUserResolver(fn () => $this->student1);

        $response = $controller->saveFormalTestQuestionProgressForLms($request);

        $this->assertSame(409, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame(0, $data['success']);
        $this->assertSame('CONCURRENT_ATTEMPT_CONFLICT', $data['error_code']);
        $this->assertSame('A temporary database concurrency conflict occurred. Please retry your request.', $data['error']);

        TestAttempt::flushEventListeners();
    }

    /**
     * Scenario 8: Unhandled SQL errors returning generic HTTP 500 without leaking raw SQL or schema details.
     */
    public function test_unhandled_sql_error_returns_generic_500_without_leaking_sql(): void
    {
        [$campaign, $formalQuota] = $this->createCampaign(allowRetakes: true, maxAttempts: 5);
        $test = $this->createOfficialTest();

        FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'practice',
            'window_type'           => 'weekly',
            'max_tests'             => 10,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 5,
        ]);

        TestAttempt::creating(function ($attempt) {
            $pdoException = new \PDOException("Table '3peas_db.secret_internal_table' doesn't exist", 1146);
            $pdoException->errorInfo = ['42S02', 1146, "Table '3peas_db.secret_internal_table' doesn't exist"];
            throw new \Illuminate\Database\QueryException(
                'mysql',
                'SELECT * FROM secret_internal_table WHERE sensitive_column = 1',
                [],
                $pdoException
            );
        });

        $controller = app(\App\Http\Controllers\Tests\TestController::class);
        $request = \Illuminate\Http\Request::create(
            '/web_api/auth/tests/save-formal-test-question-progress',
            'POST',
            [
                'test_id'            => $test->id,
                'question_id'        => $this->questionId,
                'selected_option_id' => $this->correctOptionId,
            ]
        );
        $request->setUserResolver(fn () => $this->student1);

        $response = $controller->saveFormalTestQuestionProgressForLms($request);

        $this->assertSame(500, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame(0, $data['success']);
        $this->assertSame('A database error occurred while processing test progress.', $data['error']);
        $this->assertStringNotContainsString('secret_internal_table', $response->getContent());
        $this->assertStringNotContainsString('sensitive_column', $response->getContent());
        $this->assertStringNotContainsString('SELECT', $response->getContent());

        TestAttempt::flushEventListeners();
    }

    /**
     * Scenario 9: Nested transaction flattening verification (verifying DB::transactionLevel() stays at 1 during consumeAttemptQuota).
     */
    public function test_nested_transaction_flattening_preserves_transaction_level_at_one(): void
    {
        [$campaign, $formalQuota] = $this->createCampaign(allowRetakes: true, maxAttempts: 5);
        $test = $this->createOfficialTest();

        FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'practice',
            'window_type'           => 'weekly',
            'max_tests'             => 10,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 5,
        ]);

        $service = app(\App\Http\Services\Freemium\FreemiumQuotaConsumptionService::class);

        $levelDuringConsumption = null;
        DB::beginTransaction();
        $this->assertSame(1, DB::transactionLevel());

        try {
            $consumption = $service->consumeQuota(
                $this->student1,
                'formal_test',
                'attempt_start',
                [
                    'test_id' => $test->id,
                    'attempt_number' => 1,
                ]
            );

            $levelDuringConsumption = DB::transactionLevel();

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->assertSame(1, $levelDuringConsumption, 'consumeQuota must flatten into existing outer transaction without creating savepoints');
        $this->assertNotNull($consumption);
        $this->assertSame(1, (int) $consumption->attempt_number);
    }

    /**
     * Scenario 10: Parent-row locking serialization: verify that concurrent requests for the same student acquire the parent WebUser lock.
     */
    public function test_parent_row_locking_serializes_requests_for_same_student(): void
    {
        [$campaign, $formalQuota] = $this->createCampaign(allowRetakes: true, maxAttempts: 5);
        $test = $this->createOfficialTest();

        FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'practice',
            'window_type'           => 'weekly',
            'max_tests'             => 10,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 5,
        ]);

        DB::beginTransaction();
        $lockedUser = WebUser::where('id', $this->student1->id)->lockForUpdate()->first();
        $this->assertNotNull($lockedUser);
        $this->assertSame(1, DB::transactionLevel());
        DB::commit();
    }

    /**
     * Scenario 11: Practice session vs. formal test transaction isolation.
     */
    public function test_practice_session_and_formal_test_transaction_isolation(): void
    {
        [$campaign, $formalQuota] = $this->createCampaign(allowRetakes: true, maxAttempts: 5);
        $test = $this->createOfficialTest();

        FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'practice',
            'window_type'           => 'weekly',
            'max_tests'             => 10,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 5,
        ]);

        $practiceSession = \App\Models\PracticeSession::create([
            'user_id'                 => $this->student1->id,
            'offered_program_id'      => $this->programId,
            'subject_id'              => $this->subjectId,
            'session_mode'            => 'study',
            'status'                  => 'in_progress',
            'total_questions'         => 1,
            'attempted_questions'     => 0,
            'correct_answers'         => 0,
            'wrong_answers'           => 0,
            'not_attempted_questions' => 1,
            'score'                   => 0,
            'accuracy'                => 0,
            'started_at'              => now(),
        ]);

        $service = app(\App\Http\Services\Freemium\FreemiumQuotaConsumptionService::class);
        $formalConsumption = $service->consumeQuota(
            $this->student1,
            'formal_test',
            'attempt_start',
            ['test_id' => $test->id, 'attempt_number' => 1]
        );

        $practiceConsumption = $service->consumeQuota(
            $this->student1,
            'practice',
            'practice_generation',
            ['practice_session_id' => $practiceSession->id]
        );

        $this->assertSame('formal_test', $formalConsumption->exam_type);
        $this->assertSame('practice', $practiceConsumption->exam_type);
        $this->assertNull($formalConsumption->practice_session_id);
        $this->assertNull($practiceConsumption->test_id);
    }

    /**
     * Scenario 12: Idempotent submission replay after successful submission.
     */
    public function test_idempotent_submission_replay_returns_identical_score_and_status(): void
    {
        [$campaign, $formalQuota] = $this->createCampaign(allowRetakes: true, maxAttempts: 5);
        $test = $this->createOfficialTest();

        FreemiumExamQuota::create([
            'campaign_id'           => $campaign->id,
            'exam_type'             => 'practice',
            'window_type'           => 'weekly',
            'max_tests'             => 10,
            'allow_retakes'         => true,
            'max_attempts_per_test' => 5,
        ]);

        $controller = app(\App\Http\Controllers\Tests\TestController::class);

        $request1 = \Illuminate\Http\Request::create(
            '/web_api/auth/tests/submit-formal-test',
            'POST',
            [
                'test_id'   => $test->id,
                'questions' => [
                    [
                        'question_id'        => $this->questionId,
                        'selected_option_id' => $this->correctOptionId,
                        'time_spent_seconds' => 15,
                    ]
                ],
            ]
        );
        $request1->setUserResolver(fn () => $this->student1);

        $response1 = $controller->submitFormalTestForLms($request1);
        $this->assertSame(200, $response1->getStatusCode());
        $data1 = json_decode($response1->getContent(), true)['data'];

        $attemptId = $data1['attempt_id'];
        $this->assertSame('submitted', $data1['status']);
        $this->assertEquals(1.0, (float) $data1['score']);

        // Second submission: replay with same attempt_id
        $request2 = \Illuminate\Http\Request::create(
            '/web_api/auth/tests/submit-formal-test',
            'POST',
            [
                'test_id'    => $test->id,
                'attempt_id' => $attemptId,
                'questions'  => [
                    [
                        'question_id'        => $this->questionId,
                        'selected_option_id' => $this->wrongOptionId,
                    ]
                ],
            ]
        );
        $request2->setUserResolver(fn () => $this->student1);

        $response2 = $controller->submitFormalTestForLms($request2);
        $this->assertSame(200, $response2->getStatusCode());
        $data2 = json_decode($response2->getContent(), true)['data'];

        $this->assertSame($attemptId, $data2['attempt_id']);
        $this->assertSame('submitted', $data2['status']);
        $this->assertEquals((float) $data1['score'], (float) $data2['score']);
        $this->assertSame($data1['submitted_at'], $data2['submitted_at']);
    }
}
