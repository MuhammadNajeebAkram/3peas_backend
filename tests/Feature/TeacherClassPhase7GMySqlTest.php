<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TeacherClassPhase7GMySqlTest extends TestCase
{
    public function test_mysql_innodb_enforces_phase7g_unique_constraints(): void
    {
        $conn = DB::connection('mysql');
        $conn->beginTransaction();

        try {
            $now = now()->format('Y-m-d H:i:s');
            $classId = (int) $conn->table('class_tbl')->where('activate', 1)->value('id');
            $subjectId = (int) $conn->table('subject_tbl')->where('activate', 1)->value('id');
            $boardId = (int) $conn->table('curriculum_board_tbl')->where('activate', 1)->value('id');

            $suffix = uniqid('p7g_', true);
            $teacherUserId = $conn->table('web_users')->insertGetId([
                'name' => 'Phase7G MySQL Teacher',
                'email' => "p7g_t_{$suffix}@example.test",
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
                'role' => 'teacher',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $studentUserId = $conn->table('web_users')->insertGetId([
                'name' => 'Phase7G MySQL Student',
                'email' => "p7g_s_{$suffix}@example.test",
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
                'role' => 'student',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $teacherProfileId = $conn->table('teacher_profiles')->insertGetId([
                'web_user_id' => $teacherUserId,
                'teacher_code' => 'P7G' . strtoupper(substr(md5($suffix), 0, 5)),
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
                'name' => 'Phase7G MySQL Class',
                'class_code' => 'PG' . strtoupper(substr(md5($suffix), 0, 6)),
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
                'max_marks' => 20,
                'assignment_mode' => 'entire_class',
                'status' => 'published',
                'late_policy' => 'allow_late',
                'allow_resubmission' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // 1. uk_tcae_assignment_student
            $conn->table('teacher_class_assignment_evaluations')->insert([
                'assignment_id' => $assignId,
                'teacher_class_id' => $teacherClassId,
                'student_web_user_id' => $studentUserId,
                'evaluated_by_web_user_id' => $teacherUserId,
                'status' => 'draft',
                'evaluation_type' => 'graded',
                'obtained_marks' => 15,
                'max_marks_snapshot' => 20,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            try {
                $conn->table('teacher_class_assignment_evaluations')->insert([
                    'assignment_id' => $assignId,
                    'teacher_class_id' => $teacherClassId,
                    'student_web_user_id' => $studentUserId,
                    'evaluated_by_web_user_id' => $teacherUserId,
                    'status' => 'draft',
                    'evaluation_type' => 'graded',
                    'obtained_marks' => 16,
                    'max_marks_snapshot' => 20,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->fail('Expected uk_tcae_assignment_student unique constraint violation.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('1062', $e->getMessage());
            }

            // 2. uk_tcgc_class + uk_tcgcr_config_category
            $configId = $conn->table('teacher_class_gradebook_configs')->insertGetId([
                'teacher_class_id' => $teacherClassId,
                'retake_score_policy' => 'highest',
                'weighting_enabled' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            try {
                $conn->table('teacher_class_gradebook_configs')->insert([
                    'teacher_class_id' => $teacherClassId,
                    'retake_score_policy' => 'latest',
                    'weighting_enabled' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->fail('Expected uk_tcgc_class unique constraint violation.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('1062', $e->getMessage());
            }

            $conn->table('teacher_class_gradebook_category_rules')->insert([
                'gradebook_config_id' => $configId,
                'teacher_class_id' => $teacherClassId,
                'category_key' => 'mcq_test',
                'label' => 'MCQ Tests',
                'weight_percent' => 50,
                'is_included' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            try {
                $conn->table('teacher_class_gradebook_category_rules')->insert([
                    'gradebook_config_id' => $configId,
                    'teacher_class_id' => $teacherClassId,
                    'category_key' => 'mcq_test',
                    'label' => 'Duplicate',
                    'weight_percent' => 50,
                    'is_included' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->fail('Expected uk_tcgcr_config_category unique constraint violation.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('1062', $e->getMessage());
            }

            // 3. uk_tcgae_class_type_source
            $conn->table('teacher_class_gradebook_assessment_exclusions')->insert([
                'teacher_class_id' => $teacherClassId,
                'assessment_type' => 'assignment',
                'source_id' => $assignId,
                'is_excluded' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            try {
                $conn->table('teacher_class_gradebook_assessment_exclusions')->insert([
                    'teacher_class_id' => $teacherClassId,
                    'assessment_type' => 'assignment',
                    'source_id' => $assignId,
                    'is_excluded' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->fail('Expected uk_tcgae_class_type_source unique constraint violation.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('1062', $e->getMessage());
            }
        } finally {
            $conn->rollBack();
        }
    }

    public function test_mysql_innodb_concurrent_evaluation_releases_remain_consistent_with_single_notification(): void
    {
        $conn = DB::connection('mysql');
        $now = now()->format('Y-m-d H:i:s');
        $suffix = uniqid('p7gc_', true);

        $classId = (int) $conn->table('class_tbl')->where('activate', 1)->value('id');
        $subjectId = (int) $conn->table('subject_tbl')->where('activate', 1)->value('id');
        $boardId = (int) $conn->table('curriculum_board_tbl')->where('activate', 1)->value('id');

        $teacherUserId = $conn->table('web_users')->insertGetId([
            'name' => 'Phase7G Concurrent Teacher',
            'email' => "p7gc_t_{$suffix}@example.test",
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            'role' => 'teacher',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $studentUserId = $conn->table('web_users')->insertGetId([
            'name' => 'Phase7G Concurrent Student',
            'email' => "p7gc_s_{$suffix}@example.test",
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            'role' => 'student',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $teacherProfileId = $conn->table('teacher_profiles')->insertGetId([
            'web_user_id' => $teacherUserId,
            'teacher_code' => 'PGC' . strtoupper(substr(md5($suffix), 0, 5)),
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
            'name' => 'Phase7G Concurrency Class',
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
            'requested_at' => $now,
            'approved_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $assignId = $conn->table('teacher_class_assignments')->insertGetId([
            'teacher_class_id' => $teacherClassId,
            'teacher_profile_id' => $teacherProfileId,
            'created_by_web_user_id' => $teacherUserId,
            'title' => 'Concurrent Release Test Assignment',
            'instructions' => 'Test concurrent releases.',
            'max_marks' => 25,
            'assignment_mode' => 'entire_class',
            'status' => 'published',
            'late_policy' => 'allow_late',
            'allow_resubmission' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $conn->table('teacher_class_assignment_submissions')->insert([
            'assignment_id' => $assignId,
            'teacher_class_id' => $teacherClassId,
            'student_web_user_id' => $studentUserId,
            'status' => 'submitted',
            'submission_version' => 1,
            'text_response' => 'Concurrent worker test answer',
            'submitted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            $workerScript = base_path('storage/framework/testing/p7g_concurrent_eval_worker.php');
            if (!is_dir(dirname($workerScript))) {
                mkdir(dirname($workerScript), 0777, true);
            }

            file_put_contents($workerScript, <<<'PHP'
<?php
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$teacherProfileId = (int) $argv[1];
$teacherClassId = (int) $argv[2];
$assignmentId = (int) $argv[3];
$studentUserId = (int) $argv[4];
$marks = (float) $argv[5];

$profile = App\Models\TeacherProfile::findOrFail($teacherProfileId);
$service = app(App\Http\Services\TeacherAssignmentEvaluationService::class);

try {
    $res = $service->evaluate($profile, $teacherClassId, $assignmentId, $studentUserId, [
        'action' => 'release',
        'obtained_marks' => $marks,
        'feedback_text' => 'Concurrent worker release',
    ]);
    echo json_encode(['ok' => true, 'status' => $res['evaluation']['status']]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
PHP
            );

            $processes = [];
            for ($i = 0; $i < 4; $i++) {
                $p = new Process([
                    PHP_BINARY,
                    $workerScript,
                    (string) $teacherProfileId,
                    (string) $teacherClassId,
                    (string) $assignId,
                    (string) $studentUserId,
                    (string) (18 + $i),
                ], base_path());
                $p->start();
                $processes[] = $p;
            }

            $outputs = [];
            foreach ($processes as $p) {
                $p->wait();
                $outputs[] = json_decode(trim($p->getOutput()), true);
            }

            @unlink($workerScript);

            $successCount = count(array_filter($outputs, fn ($o) => ($o['ok'] ?? false) === true));
            $this->assertSame(1, $successCount, 'Exactly one concurrent initial release must succeed: ' . json_encode($outputs));

            $evalCount = $conn->table('teacher_class_assignment_evaluations')
                ->where('assignment_id', $assignId)
                ->where('student_web_user_id', $studentUserId)
                ->count();
            $this->assertSame(1, $evalCount);

            $notifCount = $conn->table('web_user_notifications')
                ->where('recipient_web_user_id', $studentUserId)
                ->where('type', 'assignment_evaluation_released')
                ->count();
            $this->assertSame(1, $notifCount, 'Exactly one evaluation-released notification must be dispatched.');
        } finally {
            $conn->table('web_user_notifications')->where('recipient_web_user_id', $studentUserId)->delete();
            $conn->table('teacher_class_assignments')->where('id', $assignId)->delete();
            $conn->table('teacher_class_enrollments')->where('teacher_class_id', $teacherClassId)->delete();
            $conn->table('teacher_classes')->where('id', $teacherClassId)->delete();
            $conn->table('teacher_profiles')->where('id', $teacherProfileId)->delete();
            $conn->table('web_users')->whereIn('id', [$teacherUserId, $studentUserId])->delete();
        }
    }
}
