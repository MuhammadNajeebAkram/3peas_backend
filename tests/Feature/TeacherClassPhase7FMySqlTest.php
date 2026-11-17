<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeacherClassPhase7FMySqlTest extends TestCase
{
    public function test_mysql_innodb_enforces_phase7f_unique_constraints(): void
    {
        $conn = DB::connection('mysql');
        $conn->beginTransaction();

        try {
            $now = now()->format('Y-m-d H:i:s');
            $classId = (int) $conn->table('class_tbl')->where('activate', 1)->value('id');
            $subjectId = (int) $conn->table('subject_tbl')->where('activate', 1)->value('id');
            $boardId = (int) $conn->table('curriculum_board_tbl')->where('activate', 1)->value('id');

            $suffix = uniqid('p7f_', true);
            $teacherUserId = $conn->table('web_users')->insertGetId([
                'name' => 'Phase7F MySQL Teacher',
                'email' => "p7f_t_{$suffix}@example.test",
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
                'role' => 'teacher',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $studentUserId = $conn->table('web_users')->insertGetId([
                'name' => 'Phase7F MySQL Student',
                'email' => "p7f_s_{$suffix}@example.test",
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
                'role' => 'student',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $teacherProfileId = $conn->table('teacher_profiles')->insertGetId([
                'web_user_id' => $teacherUserId,
                'teacher_code' => 'P7F' . strtoupper(substr(md5($suffix), 0, 5)),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $teacherClassId = $conn->table('teacher_classes')->insertGetId([
                'teacher_profile_id' => $teacherProfileId,
                'curriculum_board_id' => $boardId,
                'class_id' => $classId,
                'subject_id' => $subjectId,
                'section' => 'A',
                'name' => 'Phase7F MySQL Class',
                'class_code' => 'PF' . strtoupper(substr(md5($suffix), 0, 6)),
                'is_active' => 1,
                'requires_approval' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $assignId = $conn->table('teacher_class_assignments')->insertGetId([
                'teacher_class_id' => $teacherClassId,
                'teacher_profile_id' => $teacherProfileId,
                'created_by_web_user_id' => $teacherUserId,
                'title' => 'MySQL Constraint Assignment',
                'instructions' => 'Verify InnoDB constraints',
                'assignment_mode' => 'selected_students',
                'status' => 'published',
                'late_policy' => 'allow_late',
                'allow_resubmission' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // 1. uk_tcar_assign_student
            $conn->table('teacher_class_assignment_recipients')->insert([
                'assignment_id' => $assignId,
                'student_web_user_id' => $studentUserId,
                'assigned_by_web_user_id' => $teacherUserId,
                'assigned_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            try {
                $conn->table('teacher_class_assignment_recipients')->insert([
                    'assignment_id' => $assignId,
                    'student_web_user_id' => $studentUserId,
                    'assigned_by_web_user_id' => $teacherUserId,
                    'assigned_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->fail('Expected uk_tcar_assign_student unique constraint violation.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('uk_tcar_assign_student', $e->getMessage());
            }

            // 2. uk_tcas_assign_student
            $subId = $conn->table('teacher_class_assignment_submissions')->insertGetId([
                'assignment_id' => $assignId,
                'teacher_class_id' => $teacherClassId,
                'student_web_user_id' => $studentUserId,
                'status' => 'submitted',
                'submission_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            try {
                $conn->table('teacher_class_assignment_submissions')->insert([
                    'assignment_id' => $assignId,
                    'teacher_class_id' => $teacherClassId,
                    'student_web_user_id' => $studentUserId,
                    'status' => 'submitted',
                    'submission_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->fail('Expected uk_tcas_assign_student unique constraint violation.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('uk_tcas_assign_student', $e->getMessage());
            }

            // 3. uk_tcasv_sub_ver
            $conn->table('teacher_class_assignment_submission_versions')->insert([
                'submission_id' => $subId,
                'assignment_id' => $assignId,
                'student_web_user_id' => $studentUserId,
                'version_number' => 1,
                'text_response' => 'v1',
                'submitted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            try {
                $conn->table('teacher_class_assignment_submission_versions')->insert([
                    'submission_id' => $subId,
                    'assignment_id' => $assignId,
                    'student_web_user_id' => $studentUserId,
                    'version_number' => 1,
                    'text_response' => 'dup v1',
                    'submitted_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->fail('Expected uk_tcasv_sub_ver unique constraint violation.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('uk_tcasv_sub_ver', $e->getMessage());
            }

            // 4. uk_tcas_class_date_slot & uk_tcar_session_student
            $sessId = $conn->table('teacher_class_attendance_sessions')->insertGetId([
                'teacher_class_id' => $teacherClassId,
                'teacher_profile_id' => $teacherProfileId,
                'created_by_web_user_id' => $teacherUserId,
                'session_date' => '2026-11-11',
                'slot_key' => '09:00',
                'status' => 'open',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            try {
                $conn->table('teacher_class_attendance_sessions')->insert([
                    'teacher_class_id' => $teacherClassId,
                    'teacher_profile_id' => $teacherProfileId,
                    'created_by_web_user_id' => $teacherUserId,
                    'session_date' => '2026-11-11',
                    'slot_key' => '09:00',
                    'status' => 'open',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->fail('Expected uk_tcas_class_date_slot unique constraint violation.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('uk_tcas_class_date_slot', $e->getMessage());
            }

            $conn->table('teacher_class_attendance_records')->insert([
                'session_id' => $sessId,
                'teacher_class_id' => $teacherClassId,
                'student_web_user_id' => $studentUserId,
                'status' => 'present',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            try {
                $conn->table('teacher_class_attendance_records')->insert([
                    'session_id' => $sessId,
                    'teacher_class_id' => $teacherClassId,
                    'student_web_user_id' => $studentUserId,
                    'status' => 'late',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->fail('Expected uk_tcar_session_student unique constraint violation.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('uk_tcar_session_student', $e->getMessage());
            }
        } finally {
            $conn->rollBack();
        }
    }

    public function test_parallel_php_workers_concurrent_submission_and_attendance_session_creation(): void
    {
        $conn = DB::connection('mysql');
        $suffix = uniqid('p7fc_', true);
        $now = now()->format('Y-m-d H:i:s');

        $classId = (int) $conn->table('class_tbl')->where('activate', 1)->value('id');
        $subjectId = (int) $conn->table('subject_tbl')->where('activate', 1)->value('id');
        $boardId = (int) $conn->table('curriculum_board_tbl')->where('activate', 1)->value('id');
        $planId = (int) $conn->table('teacher_subscription_plans')->value('id');

        $teacherUserId = 0;
        $studentUserId = 0;
        $teacherProfileId = 0;
        $teacherClassId = 0;
        $assignId = 0;

        $workerScript = tempnam(sys_get_temp_dir(), 'p7f_conc_') . '.php';

        try {
            $teacherUserId = $conn->table('web_users')->insertGetId([
                'name' => 'P7F Parallel Teacher',
                'email' => "p7f_pt_{$suffix}@example.test",
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
                'role' => 'teacher',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $studentUserId = $conn->table('web_users')->insertGetId([
                'name' => 'P7F Parallel Student',
                'email' => "p7f_ps_{$suffix}@example.test",
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
                'role' => 'student',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $teacherProfileId = $conn->table('teacher_profiles')->insertGetId([
                'web_user_id' => $teacherUserId,
                'teacher_code' => 'PPC' . strtoupper(substr(md5($suffix), 0, 5)),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $conn->table('teacher_subscriptions')->insert([
                'teacher_profile_id' => $teacherProfileId,
                'teacher_subscription_plan_id' => $planId,
                'status' => 'active',
                'starts_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $teacherClassId = $conn->table('teacher_classes')->insertGetId([
                'teacher_profile_id' => $teacherProfileId,
                'curriculum_board_id' => $boardId,
                'class_id' => $classId,
                'subject_id' => $subjectId,
                'section' => 'A',
                'name' => 'P7F Parallel Class',
                'class_code' => 'PC' . strtoupper(substr(md5($suffix), 0, 6)),
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
            $assignId = $conn->table('teacher_class_assignments')->insertGetId([
                'teacher_class_id' => $teacherClassId,
                'teacher_profile_id' => $teacherProfileId,
                'created_by_web_user_id' => $teacherUserId,
                'title' => 'Parallel Submission Test',
                'instructions' => 'Submit concurrently',
                'assignment_mode' => 'entire_class',
                'status' => 'published',
                'late_policy' => 'allow_late',
                'allow_resubmission' => 1,
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $basePath = base_path();
            $code = <<<'PHP'
<?php
$basePath = $argv[1];
$teacherProfileId = (int) $argv[2];
$classId = (int) $argv[3];
$studentUserId = (int) $argv[4];
$assignId = (int) $argv[5];
$barrierTime = (float) $argv[6];

require $basePath . '/vendor/autoload.php';
$app = require $basePath . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

while (microtime(true) < $barrierTime) {
    usleep(5000);
}

$attStatus = 'ok';
try {
    $profile = \App\Models\TeacherProfile::findOrFail($teacherProfileId);
    app(\App\Http\Services\TeacherClassAttendanceService::class)->createSession($profile, $classId, [
        'session_date' => '2026-11-18',
        'start_time' => '10:00',
        'end_time' => '10:45',
    ]);
} catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
    $attStatus = 'http_' . $e->getResponse()->getStatusCode();
} catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
    $attStatus = 'http_' . $e->getStatusCode();
} catch (\Illuminate\Database\QueryException $e) {
    $attStatus = 'query_' . $e->getCode();
}

$subStatus = 'ok';
try {
    $student = \App\Models\WebUser::findOrFail($studentUserId);
    app(\App\Http\Services\StudentClassAssignmentService::class)->submit($student, $assignId, [
        'text_response' => 'Concurrent worker response ' . getmypid(),
    ]);
} catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
    $subStatus = 'http_' . $e->getResponse()->getStatusCode();
} catch (\Throwable $e) {
    $subStatus = 'err:' . get_class($e);
}

echo json_encode(['att' => $attStatus, 'sub' => $subStatus]);
PHP;
            file_put_contents($workerScript, $code);

            $barrier = microtime(true) + 0.8;
            $procs = [];
            for ($i = 0; $i < 2; $i++) {
                $cmd = sprintf(
                    'php %s %s %d %d %d %d %.4F',
                    escapeshellarg($workerScript),
                    escapeshellarg($basePath),
                    $teacherProfileId,
                    $teacherClassId,
                    $studentUserId,
                    $assignId,
                    $barrier
                );
                $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
            }

            $outputs = [];
            foreach ($procs as $i => $proc) {
                $stdout = stream_get_contents($pipes[$i][1]);
                fclose($pipes[$i][1]);
                fclose($pipes[$i][2]);
                proc_close($proc);
                $outputs[] = json_decode((string) $stdout, true);
            }

            $attSessionCount = $conn->table('teacher_class_attendance_sessions')
                ->where('teacher_class_id', $teacherClassId)
                ->where('session_date', '2026-11-18')
                ->count();
            $this->assertEquals(1, $attSessionCount);

            $this->assertCount(2, array_filter($outputs, 'is_array'), 'Both worker scripts must emit JSON: ' . json_encode($outputs));
            $attStatuses = array_column($outputs, 'att');
            sort($attStatuses);
            $this->assertSame(
                ['http_422', 'ok'],
                $attStatuses,
                'Expected 1 ok and 1 http_422 duplicate rejection: ' . json_encode($outputs)
            );
            $this->assertSame(
                0,
                count(array_filter($attStatuses, fn ($s) => str_starts_with((string) $s, 'query_'))),
                'No uncaught SQL QueryException should escape attendance creation.'
            );

            $subRowCount = $conn->table('teacher_class_assignment_submissions')
                ->where('assignment_id', $assignId)
                ->where('student_web_user_id', $studentUserId)
                ->count();
            $this->assertEquals(1, $subRowCount);

            $subStatuses = array_column($outputs, 'sub');
            foreach ($subStatuses as $st) {
                $this->assertEquals('ok', $st, 'Expected concurrent resubmission workers to serialize without QueryException: ' . json_encode($outputs));
            }
            $versions = $conn->table('teacher_class_assignment_submission_versions')
                ->where('assignment_id', $assignId)
                ->where('student_web_user_id', $studentUserId)
                ->get(['id', 'version_number', 'text_response']);
            $this->assertEquals(
                2,
                $versions->count(),
                'outputs=' . json_encode($outputs) . ' versions=' . $versions->toJson()
            );
        } finally {
            @unlink($workerScript);
            if ($assignId > 0) {
                $conn->table('teacher_class_assignments')->where('id', $assignId)->delete();
            }
            if ($teacherClassId > 0) {
                $conn->table('teacher_class_attendance_sessions')->where('teacher_class_id', $teacherClassId)->delete();
                $conn->table('teacher_class_enrollments')->where('teacher_class_id', $teacherClassId)->delete();
                $conn->table('teacher_classes')->where('id', $teacherClassId)->delete();
            }
            if ($teacherProfileId > 0) {
                $conn->table('teacher_subscriptions')->where('teacher_profile_id', $teacherProfileId)->delete();
                $conn->table('teacher_profiles')->where('id', $teacherProfileId)->delete();
            }
            if ($teacherUserId > 0) {
                $conn->table('web_user_notifications')->where('recipient_web_user_id', $teacherUserId)->delete();
                $conn->table('web_users')->where('id', $teacherUserId)->delete();
            }
            if ($studentUserId > 0) {
                $conn->table('web_user_notifications')->where('recipient_web_user_id', $studentUserId)->delete();
                $conn->table('web_users')->where('id', $studentUserId)->delete();
            }
        }
    }

    public function test_parallel_php_workers_reject_overlapping_attendance_single_submit_and_concurrent_marking(): void
    {
        $conn = DB::connection('mysql');
        $suffix = uniqid('p7fo_', true);
        $now = now()->format('Y-m-d H:i:s');

        $classId = (int) $conn->table('class_tbl')->where('activate', 1)->value('id');
        $subjectId = (int) $conn->table('subject_tbl')->where('activate', 1)->value('id');
        $boardId = (int) $conn->table('curriculum_board_tbl')->where('activate', 1)->value('id');
        $planId = (int) $conn->table('teacher_subscription_plans')->value('id');

        $teacherUserId = 0;
        $studentUserId = 0;
        $teacherProfileId = 0;
        $teacherClassId = 0;
        $singleAssignId = 0;
        $attSessionId = 0;

        $workerScript = tempnam(sys_get_temp_dir(), 'p7f_ovlp_') . '.php';

        try {
            $teacherUserId = $conn->table('web_users')->insertGetId([
                'name' => 'P7F Overlap Teacher',
                'email' => "p7f_ot_{$suffix}@example.test",
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
                'role' => 'teacher',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $studentUserId = $conn->table('web_users')->insertGetId([
                'name' => 'P7F Overlap Student',
                'email' => "p7f_os_{$suffix}@example.test",
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
                'role' => 'student',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $teacherProfileId = $conn->table('teacher_profiles')->insertGetId([
                'web_user_id' => $teacherUserId,
                'teacher_code' => 'PPO' . strtoupper(substr(md5($suffix), 0, 5)),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $conn->table('teacher_subscriptions')->insert([
                'teacher_profile_id' => $teacherProfileId,
                'teacher_subscription_plan_id' => $planId,
                'status' => 'active',
                'starts_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $teacherClassId = $conn->table('teacher_classes')->insertGetId([
                'teacher_profile_id' => $teacherProfileId,
                'curriculum_board_id' => $boardId,
                'class_id' => $classId,
                'subject_id' => $subjectId,
                'section' => 'A',
                'name' => 'P7F Overlap Class',
                'class_code' => 'PO' . strtoupper(substr(md5($suffix), 0, 6)),
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
            $singleAssignId = $conn->table('teacher_class_assignments')->insertGetId([
                'teacher_class_id' => $teacherClassId,
                'teacher_profile_id' => $teacherProfileId,
                'created_by_web_user_id' => $teacherUserId,
                'title' => 'Single Submit Race Test',
                'instructions' => 'Resubmission disabled',
                'assignment_mode' => 'entire_class',
                'status' => 'published',
                'late_policy' => 'allow_late',
                'allow_resubmission' => 0,
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $attSessionId = $conn->table('teacher_class_attendance_sessions')->insertGetId([
                'teacher_class_id' => $teacherClassId,
                'teacher_profile_id' => $teacherProfileId,
                'created_by_web_user_id' => $teacherUserId,
                'session_date' => '2026-11-19',
                'start_time' => '14:00',
                'end_time' => '15:00',
                'slot_key' => '14:00-15:00',
                'status' => 'open',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $basePath = base_path();
            $code = <<<'PHP'
<?php
$basePath = $argv[1];
$teacherProfileId = (int) $argv[2];
$classId = (int) $argv[3];
$studentUserId = (int) $argv[4];
$assignId = (int) $argv[5];
$attSessionId = (int) $argv[6];
$workerIdx = (int) $argv[7];
$barrierTime = (float) $argv[8];

require $basePath . '/vendor/autoload.php';
$app = require $basePath . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

while (microtime(true) < $barrierTime) {
    usleep(5000);
}

$attStatus = 'ok';
try {
    $profile = \App\Models\TeacherProfile::findOrFail($teacherProfileId);
    $start = $workerIdx === 0 ? '09:00' : '09:30';
    $end = $workerIdx === 0 ? '10:00' : '10:30';
    app(\App\Http\Services\TeacherClassAttendanceService::class)->createSession($profile, $classId, [
        'session_date' => '2026-11-20',
        'start_time' => $start,
        'end_time' => $end,
    ]);
} catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
    $attStatus = 'http_' . $e->getResponse()->getStatusCode();
} catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
    $attStatus = 'http_' . $e->getStatusCode();
} catch (\Throwable $e) {
    $attStatus = 'err:' . get_class($e);
}

$subStatus = 'ok';
try {
    $student = \App\Models\WebUser::findOrFail($studentUserId);
    app(\App\Http\Services\StudentClassAssignmentService::class)->submit($student, $assignId, [
        'text_response' => 'Single-submit race worker ' . $workerIdx,
    ]);
} catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
    $subStatus = 'http_' . $e->getResponse()->getStatusCode();
} catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
    $subStatus = 'http_' . $e->getStatusCode();
} catch (\Throwable $e) {
    $subStatus = 'err:' . get_class($e);
}

$markStatus = 'ok';
try {
    $profile = \App\Models\TeacherProfile::findOrFail($teacherProfileId);
    app(\App\Http\Services\TeacherClassAttendanceService::class)->markRecords($profile, $classId, $attSessionId, [
        'records' => [['student_web_user_id' => $studentUserId, 'status' => $workerIdx === 0 ? 'present' : 'late']],
    ]);
} catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
    $markStatus = 'http_' . $e->getResponse()->getStatusCode();
} catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
    $markStatus = 'http_' . $e->getStatusCode();
} catch (\Throwable $e) {
    $markStatus = 'err:' . get_class($e);
}

echo json_encode(['att' => $attStatus, 'sub' => $subStatus, 'mark' => $markStatus]);
PHP;
            file_put_contents($workerScript, $code);

            $barrier = microtime(true) + 0.8;
            $procs = [];
            for ($i = 0; $i < 2; $i++) {
                $cmd = sprintf(
                    'php %s %s %d %d %d %d %d %d %.4F',
                    escapeshellarg($workerScript),
                    escapeshellarg($basePath),
                    $teacherProfileId,
                    $teacherClassId,
                    $studentUserId,
                    $singleAssignId,
                    $attSessionId,
                    $i,
                    $barrier
                );
                $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
            }

            $outputs = [];
            foreach ($procs as $i => $proc) {
                $stdout = stream_get_contents($pipes[$i][1]);
                fclose($pipes[$i][1]);
                fclose($pipes[$i][2]);
                proc_close($proc);
                $outputs[] = json_decode((string) $stdout, true);
            }

            // 1. Overlapping slots serialized: 1 ok + 1 http_422, 1 DB row
            $attStatuses = array_column($outputs, 'att');
            sort($attStatuses);
            $this->assertSame(['http_422', 'ok'], $attStatuses, json_encode($outputs));
            $this->assertEquals(
                1,
                $conn->table('teacher_class_attendance_sessions')
                    ->where('teacher_class_id', $teacherClassId)
                    ->where('session_date', '2026-11-20')
                    ->count()
            );

            // 2. Concurrent single-submit first race: 1 ok + 1 http_422, 1 version row
            $subStatuses = array_column($outputs, 'sub');
            sort($subStatuses);
            $this->assertSame(['http_422', 'ok'], $subStatuses, json_encode($outputs));
            $this->assertEquals(
                1,
                $conn->table('teacher_class_assignment_submission_versions')
                    ->where('assignment_id', $singleAssignId)
                    ->where('student_web_user_id', $studentUserId)
                    ->count()
            );

            // 3. Concurrent record marking: both ok, 1 DB record row (no uk_tcar_session_student crash)
            $this->assertSame(['ok', 'ok'], array_column($outputs, 'mark'), json_encode($outputs));
            $this->assertEquals(
                1,
                $conn->table('teacher_class_attendance_records')
                    ->where('session_id', $attSessionId)
                    ->where('student_web_user_id', $studentUserId)
                    ->count()
            );
        } finally {
            @unlink($workerScript);
            if ($singleAssignId > 0) {
                $conn->table('teacher_class_assignments')->where('id', $singleAssignId)->delete();
            }
            if ($teacherClassId > 0) {
                $conn->table('teacher_class_attendance_sessions')->where('teacher_class_id', $teacherClassId)->delete();
                $conn->table('teacher_class_enrollments')->where('teacher_class_id', $teacherClassId)->delete();
                $conn->table('teacher_classes')->where('id', $teacherClassId)->delete();
            }
            if ($teacherProfileId > 0) {
                $conn->table('teacher_subscriptions')->where('teacher_profile_id', $teacherProfileId)->delete();
                $conn->table('teacher_profiles')->where('id', $teacherProfileId)->delete();
            }
            if ($teacherUserId > 0) {
                $conn->table('web_user_notifications')->where('recipient_web_user_id', $teacherUserId)->delete();
                $conn->table('web_users')->where('id', $teacherUserId)->delete();
            }
            if ($studentUserId > 0) {
                $conn->table('web_user_notifications')->where('recipient_web_user_id', $studentUserId)->delete();
                $conn->table('web_users')->where('id', $studentUserId)->delete();
            }
        }
    }
}
