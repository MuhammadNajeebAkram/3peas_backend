<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeacherClassTestDeliveryMySqlTest extends TestCase
{
    public function test_mysql_innodb_enforces_unique_recipients_single_active_attempt_and_restrict_delete_guards(): void
    {
        $conn = DB::connection('mysql');
        $conn->beginTransaction();

        try {
            $now = now()->format('Y-m-d H:i:s');
            $later = now()->addHours(2)->format('Y-m-d H:i:s');

            $classId = (int) $conn->table('class_tbl')->where('activate', 1)->value('id');
            $subjectId = (int) $conn->table('subject_tbl')->where('activate', 1)->value('id');
            $boardId = (int) $conn->table('curriculum_board_tbl')->where('activate', 1)->value('id');
            $bankQuestionId = (int) $conn->table('exam_question_tbl')->where('activate', 1)->value('id');

            $this->assertGreaterThan(0, $classId);
            $this->assertGreaterThan(0, $subjectId);
            $this->assertGreaterThan(0, $boardId);
            $this->assertGreaterThan(0, $bankQuestionId);

            $suffix = uniqid('p7d_', true);

            $teacherUserId = $conn->table('web_users')->insertGetId([
                'name' => 'Phase7D MySQL Teacher',
                'email' => "teacher_{$suffix}@example.test",
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
                'role' => 'teacher',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $studentUserId = $conn->table('web_users')->insertGetId([
                'name' => 'Phase7D MySQL Student',
                'email' => "student_{$suffix}@example.test",
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
                'role' => 'student',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $teacherProfileId = $conn->table('teacher_profiles')->insertGetId([
                'web_user_id' => $teacherUserId,
                'teacher_code' => 'P7D' . strtoupper(substr(md5($suffix), 0, 5)),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $teacherClassId = $conn->table('teacher_classes')->insertGetId([
                'teacher_profile_id' => $teacherProfileId,
                'curriculum_board_id' => $boardId,
                'class_id' => $classId,
                'subject_id' => $subjectId,
                'name' => 'MySQL Constraint Verification Class',
                'class_code' => strtoupper(substr(md5($suffix), 0, 8)),
                'is_active' => 1,
                'requires_approval' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $testId = $conn->table('teacher_class_tests')->insertGetId([
                'teacher_class_id' => $teacherClassId,
                'teacher_profile_id' => $teacherProfileId,
                'curriculum_board_id' => $boardId,
                'class_id' => $classId,
                'subject_id' => $subjectId,
                'title' => 'MySQL Test',
                'selection_method' => 'manual',
                'total_questions' => 1,
                'marks_per_question' => 1.00,
                'total_marks' => 1.00,
                'negative_marks_per_wrong' => 0.00,
                'language_mode' => 'english',
                'status' => 'scheduled',
                'test_version' => 1,
                'scheduled_start_at' => $now,
                'scheduled_end_at' => $later,
                'duration_minutes' => 30,
                'max_attempts' => 2,
                'result_visibility' => 'manual_release',
                'allow_answer_review' => 0,
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $testQuestionId = $conn->table('teacher_class_test_questions')->insertGetId([
                'teacher_class_test_id' => $testId,
                'question_id' => $bankQuestionId,
                'question_order' => 1,
                'marks' => 1.00,
                'negative_marks' => 0.00,
                'question_source' => 'bank',
                'question_text_snapshot' => '<p>Test MCQ</p>',
                'options_snapshot_json' => json_encode([['id' => 1, 'key' => 'A', 'option' => 'Yes']]),
                'correct_option_id_snapshot' => 1,
                'correct_option_key_snapshot' => 'A',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $assignmentId = $conn->table('teacher_class_test_assignments')->insertGetId([
                'teacher_class_test_id' => $testId,
                'teacher_class_id' => $teacherClassId,
                'teacher_profile_id' => $teacherProfileId,
                'test_version' => 1,
                'assignment_mode' => 'selected_students',
                'title_snapshot' => 'MySQL Test',
                'starts_at' => $now,
                'ends_at' => $later,
                'duration_minutes' => 30,
                'max_attempts' => 2,
                'result_visibility' => 'manual_release',
                'allow_answer_review' => 0,
                'status' => 'active',
                'assigned_count' => 1,
                'assigned_by_web_user_id' => $teacherUserId,
                'assigned_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $recipientId = $conn->table('teacher_class_test_recipients')->insertGetId([
                'teacher_class_test_assignment_id' => $assignmentId,
                'teacher_class_test_id' => $testId,
                'teacher_class_id' => $teacherClassId,
                'student_web_user_id' => $studentUserId,
                'assignment_status' => 'assigned',
                'assigned_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // 1. Unique recipient constraint uk_tctr_assignment_student
            $duplicateRecipientFailed = false;
            try {
                $conn->table('teacher_class_test_recipients')->insert([
                    'teacher_class_test_assignment_id' => $assignmentId,
                    'teacher_class_test_id' => $testId,
                    'teacher_class_id' => $teacherClassId,
                    'student_web_user_id' => $studentUserId,
                    'assignment_status' => 'assigned',
                    'assigned_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (QueryException $e) {
                $duplicateRecipientFailed = str_contains($e->getMessage(), 'uk_tctr_assignment_student')
                    || str_contains($e->getMessage(), 'Duplicate entry');
            }
            $this->assertTrue($duplicateRecipientFailed, 'Expected MySQL uk_tctr_assignment_student to reject duplicate recipient.');

            // 2. First in_progress attempt populates active_recipient_guard = recipientId
            $attempt1Id = $conn->table('teacher_class_test_attempts')->insertGetId([
                'teacher_class_test_assignment_id' => $assignmentId,
                'teacher_class_test_recipient_id' => $recipientId,
                'teacher_class_test_id' => $testId,
                'teacher_class_id' => $teacherClassId,
                'student_web_user_id' => $studentUserId,
                'attempt_number' => 1,
                'status' => 'in_progress',
                'started_at' => $now,
                'deadline_at' => $later,
                'total_questions' => 1,
                'max_marks' => 1.00,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $guardValue = $conn->table('teacher_class_test_attempts')->where('id', $attempt1Id)->value('active_recipient_guard');
            $this->assertSame($recipientId, (int) $guardValue);

            // Second concurrent in_progress attempt for same recipient rejected by uk_tctatt_active_recipient
            $concurrentAttemptFailed = false;
            try {
                $conn->table('teacher_class_test_attempts')->insert([
                    'teacher_class_test_assignment_id' => $assignmentId,
                    'teacher_class_test_recipient_id' => $recipientId,
                    'teacher_class_test_id' => $testId,
                    'teacher_class_id' => $teacherClassId,
                    'student_web_user_id' => $studentUserId,
                    'attempt_number' => 2,
                    'status' => 'in_progress',
                    'started_at' => $now,
                    'deadline_at' => $later,
                    'total_questions' => 1,
                    'max_marks' => 1.00,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (QueryException $e) {
                $concurrentAttemptFailed = str_contains($e->getMessage(), 'uk_tctatt_active_recipient')
                    || str_contains($e->getMessage(), 'Duplicate entry');
            }
            $this->assertTrue($concurrentAttemptFailed, 'Expected MySQL uk_tctatt_active_recipient to reject concurrent active attempt.');

            // Finalizing attempt 1 clears active_recipient_guard to NULL and allows attempt 2
            $conn->table('teacher_class_test_attempts')->where('id', $attempt1Id)->update([
                'status' => 'submitted',
                'submitted_at' => $now,
                'finalized_at' => $now,
            ]);
            $this->assertNull($conn->table('teacher_class_test_attempts')->where('id', $attempt1Id)->value('active_recipient_guard'));

            $attempt2Id = $conn->table('teacher_class_test_attempts')->insertGetId([
                'teacher_class_test_assignment_id' => $assignmentId,
                'teacher_class_test_recipient_id' => $recipientId,
                'teacher_class_test_id' => $testId,
                'teacher_class_id' => $teacherClassId,
                'student_web_user_id' => $studentUserId,
                'attempt_number' => 2,
                'status' => 'in_progress',
                'started_at' => $now,
                'deadline_at' => $later,
                'total_questions' => 1,
                'max_marks' => 1.00,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->assertGreaterThan($attempt1Id, $attempt2Id);

            // Insert attempt answer referencing testQuestionId
            $conn->table('teacher_class_test_attempt_answers')->insert([
                'attempt_id' => $attempt1Id,
                'teacher_class_test_question_id' => $testQuestionId,
                'question_id' => $bankQuestionId,
                'question_order' => 1,
                'selected_option_id' => 1,
                'selected_option_key' => 'A',
                'correct_option_id_snapshot' => 1,
                'correct_option_key_snapshot' => 'A',
                'is_correct' => 1,
                'marks_possible' => 1.00,
                'marks_awarded' => 1.00,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // 3. Restrict-on-delete prevents deleting assignment with attempts & test question with answers
            $deleteAssignmentBlocked = false;
            try {
                $conn->table('teacher_class_test_assignments')->where('id', $assignmentId)->delete();
            } catch (QueryException $e) {
                $deleteAssignmentBlocked = str_contains($e->getMessage(), 'tctatt_assignment_fk')
                    || str_contains($e->getMessage(), 'Cannot delete or update a parent row');
            }
            $this->assertTrue($deleteAssignmentBlocked, 'Expected RESTRICT FK to block deleting assignment with attempts.');

            $deleteQuestionBlocked = false;
            try {
                $conn->table('teacher_class_test_questions')->where('id', $testQuestionId)->delete();
            } catch (QueryException $e) {
                $deleteQuestionBlocked = str_contains($e->getMessage(), 'tctaa_test_question_fk')
                    || str_contains($e->getMessage(), 'Cannot delete or update a parent row');
            }
            $this->assertTrue($deleteQuestionBlocked, 'Expected RESTRICT FK to block deleting test question with attempt answers.');
        } finally {
            $conn->rollBack();
        }
    }
}
