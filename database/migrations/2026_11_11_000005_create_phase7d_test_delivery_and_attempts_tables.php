<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('teacher_class_tests') || !Schema::hasTable('teacher_class_test_questions')) {
            throw new \RuntimeException('Phase 7C teacher_class_tests and teacher_class_test_questions tables are required before Phase 7D migration.');
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE `teacher_class_tests` MODIFY COLUMN `status` ENUM('draft', 'scheduled', 'published', 'closed', 'archived') NOT NULL DEFAULT 'draft'"
            );
        } elseif (DB::getDriverName() === 'sqlite') {
            Schema::table('teacher_class_tests', function (Blueprint $table) {
                $table->enum('status', ['draft', 'scheduled', 'published', 'closed', 'archived'])
                    ->default('draft')
                    ->change();
            });
        }

        Schema::table('teacher_class_tests', function (Blueprint $table) {
            if (!Schema::hasColumn('teacher_class_tests', 'test_version')) {
                $table->unsignedSmallInteger('test_version')->default(1)->after('status');
            }
            if (!Schema::hasColumn('teacher_class_tests', 'scheduled_start_at')) {
                $table->timestamp('scheduled_start_at')->nullable()->after('test_version');
            }
            if (!Schema::hasColumn('teacher_class_tests', 'scheduled_end_at')) {
                $table->timestamp('scheduled_end_at')->nullable()->after('scheduled_start_at');
            }
            if (!Schema::hasColumn('teacher_class_tests', 'duration_minutes')) {
                $table->unsignedSmallInteger('duration_minutes')->nullable()->after('scheduled_end_at');
            }
            if (!Schema::hasColumn('teacher_class_tests', 'max_attempts')) {
                $table->unsignedTinyInteger('max_attempts')->default(1)->after('duration_minutes');
            }
            if (!Schema::hasColumn('teacher_class_tests', 'result_visibility')) {
                $table->enum('result_visibility', ['after_submission', 'after_close', 'manual_release'])
                    ->default('manual_release')
                    ->after('max_attempts');
            }
            if (!Schema::hasColumn('teacher_class_tests', 'allow_answer_review')) {
                $table->boolean('allow_answer_review')->default(false)->after('result_visibility');
            }
            if (!Schema::hasColumn('teacher_class_tests', 'results_released_at')) {
                $table->timestamp('results_released_at')->nullable()->after('allow_answer_review');
            }
            if (!Schema::hasColumn('teacher_class_tests', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('results_released_at');
            }
            if (!Schema::hasColumn('teacher_class_tests', 'closed_at')) {
                $table->timestamp('closed_at')->nullable()->after('published_at');
            }
            if (!Schema::hasColumn('teacher_class_tests', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('closed_at');
            }
        });

        if (!Schema::hasTable('teacher_class_test_assignments')) {
            Schema::create('teacher_class_test_assignments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('teacher_class_test_id');
                $table->unsignedBigInteger('teacher_class_id');
                $table->unsignedBigInteger('teacher_profile_id');
                $table->unsignedSmallInteger('test_version')->default(1);
                $table->enum('assignment_mode', ['entire_class', 'selected_students'])->default('entire_class');
                $table->string('title_snapshot', 255);
                $table->text('instructions_snapshot')->nullable();
                $table->timestamp('starts_at');
                $table->timestamp('ends_at');
                $table->unsignedSmallInteger('duration_minutes');
                $table->unsignedTinyInteger('max_attempts')->default(1);
                $table->enum('result_visibility', ['after_submission', 'after_close', 'manual_release'])->default('manual_release');
                $table->boolean('allow_answer_review')->default(false);
                $table->timestamp('results_released_at')->nullable();
                $table->enum('status', ['scheduled', 'active', 'closed', 'cancelled'])->default('scheduled');
                $table->unsignedInteger('assigned_count')->default(0);
                $table->unsignedBigInteger('assigned_by_web_user_id')->nullable();
                $table->timestamp('assigned_at');
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();

                $table->index(['teacher_class_test_id', 'status'], 'idx_tcta_test_status');
                $table->index(['teacher_class_id', 'starts_at', 'ends_at'], 'idx_tcta_class_window');

                $table->foreign('teacher_class_test_id', 'tcta_test_fk')
                    ->references('id')->on('teacher_class_tests')->cascadeOnDelete();
                $table->foreign('teacher_class_id', 'tcta_class_fk')
                    ->references('id')->on('teacher_classes')->cascadeOnDelete();
                $table->foreign('teacher_profile_id', 'tcta_profile_fk')
                    ->references('id')->on('teacher_profiles')->cascadeOnDelete();
                $table->foreign('assigned_by_web_user_id', 'tcta_assigned_by_fk')
                    ->references('id')->on('web_users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('teacher_class_test_recipients')) {
            Schema::create('teacher_class_test_recipients', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('teacher_class_test_assignment_id');
                $table->unsignedBigInteger('teacher_class_test_id');
                $table->unsignedBigInteger('teacher_class_id');
                $table->unsignedBigInteger('student_web_user_id');
                $table->unsignedBigInteger('teacher_class_enrollment_id')->nullable();
                $table->enum('assignment_status', ['assigned', 'in_progress', 'submitted', 'expired', 'revoked'])->default('assigned');
                $table->timestamp('assigned_at');
                $table->timestamp('notified_assigned_at')->nullable();
                $table->timestamp('notified_available_at')->nullable();
                $table->timestamp('notified_result_at')->nullable();
                $table->timestamps();

                $table->unique(['teacher_class_test_assignment_id', 'student_web_user_id'], 'uk_tctr_assignment_student');
                $table->index(['student_web_user_id', 'assignment_status'], 'idx_tctr_student_status');
                $table->index(['teacher_class_test_id', 'student_web_user_id'], 'idx_tctr_test_student');

                $table->foreign('teacher_class_test_assignment_id', 'tctr_assignment_fk')
                    ->references('id')->on('teacher_class_test_assignments')->cascadeOnDelete();
                $table->foreign('teacher_class_test_id', 'tctr_test_fk')
                    ->references('id')->on('teacher_class_tests')->cascadeOnDelete();
                $table->foreign('teacher_class_id', 'tctr_class_fk')
                    ->references('id')->on('teacher_classes')->cascadeOnDelete();
                $table->foreign('student_web_user_id', 'tctr_student_fk')
                    ->references('id')->on('web_users')->cascadeOnDelete();
                $table->foreign('teacher_class_enrollment_id', 'tctr_enrollment_fk')
                    ->references('id')->on('teacher_class_enrollments')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('teacher_class_test_attempts')) {
            Schema::create('teacher_class_test_attempts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('teacher_class_test_assignment_id');
                $table->unsignedBigInteger('teacher_class_test_recipient_id');
                $table->unsignedBigInteger('teacher_class_test_id');
                $table->unsignedBigInteger('teacher_class_id');
                $table->unsignedBigInteger('student_web_user_id');
                $table->unsignedTinyInteger('attempt_number');
                $table->enum('status', ['in_progress', 'submitted', 'auto_submitted', 'expired'])->default('in_progress');
                $table->timestamp('started_at');
                $table->timestamp('deadline_at');
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('finalized_at')->nullable();
                $table->unsignedSmallInteger('total_questions');
                $table->unsignedSmallInteger('answered_questions')->default(0);
                $table->unsignedSmallInteger('correct_count')->default(0);
                $table->unsignedSmallInteger('incorrect_count')->default(0);
                $table->unsignedSmallInteger('unanswered_count')->default(0);
                $table->decimal('max_marks', 8, 2);
                $table->decimal('obtained_marks', 8, 2)->default(0.00);
                $table->decimal('percentage', 5, 2)->default(0.00);
                $table->unsignedInteger('time_spent_seconds')->default(0);
                $table->enum('submission_reason', ['manual', 'deadline_expired', 'window_closed'])->nullable();
                $table->unsignedBigInteger('active_recipient_guard')
                    ->nullable()
                    ->storedAs("CASE WHEN status = 'in_progress' THEN teacher_class_test_recipient_id ELSE NULL END");
                $table->timestamps();

                $table->unique(['teacher_class_test_recipient_id', 'attempt_number'], 'uk_tctatt_recipient_attempt_no');
                $table->unique(['active_recipient_guard'], 'uk_tctatt_active_recipient');
                $table->index(['teacher_class_test_assignment_id', 'status'], 'idx_tctatt_assignment_status');
                $table->index(['student_web_user_id', 'status'], 'idx_tctatt_student_status');

                $table->foreign('teacher_class_test_assignment_id', 'tctatt_assignment_fk')
                    ->references('id')->on('teacher_class_test_assignments')->restrictOnDelete();
                $table->foreign('teacher_class_test_recipient_id', 'tctatt_recipient_fk')
                    ->references('id')->on('teacher_class_test_recipients')->restrictOnDelete();
                $table->foreign('teacher_class_test_id', 'tctatt_test_fk')
                    ->references('id')->on('teacher_class_tests')->restrictOnDelete();
                $table->foreign('teacher_class_id', 'tctatt_class_fk')
                    ->references('id')->on('teacher_classes')->restrictOnDelete();
                $table->foreign('student_web_user_id', 'tctatt_student_fk')
                    ->references('id')->on('web_users')->restrictOnDelete();
            });
        }

        if (!Schema::hasTable('teacher_class_test_attempt_answers')) {
            Schema::create('teacher_class_test_attempt_answers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('attempt_id');
                $table->unsignedBigInteger('teacher_class_test_question_id');
                $table->unsignedBigInteger('question_id');
                $table->unsignedBigInteger('scenario_group_id')->nullable();
                $table->unsignedSmallInteger('question_order');
                $table->unsignedBigInteger('selected_option_id')->nullable();
                $table->string('selected_option_key', 16)->nullable();
                $table->unsignedBigInteger('correct_option_id_snapshot')->nullable();
                $table->string('correct_option_key_snapshot', 16)->nullable();
                $table->boolean('is_correct')->nullable();
                $table->decimal('marks_possible', 6, 2);
                $table->decimal('negative_marks_possible', 6, 2)->default(0.00);
                $table->decimal('marks_awarded', 6, 2)->default(0.00);
                $table->timestamp('answered_at')->nullable();
                $table->timestamps();

                $table->unique(['attempt_id', 'teacher_class_test_question_id'], 'uk_tctaa_attempt_question');
                $table->index(['attempt_id', 'question_order'], 'idx_tctaa_attempt_order');

                $table->foreign('attempt_id', 'tctaa_attempt_fk')
                    ->references('id')->on('teacher_class_test_attempts')->cascadeOnDelete();
                $table->foreign('teacher_class_test_question_id', 'tctaa_test_question_fk')
                    ->references('id')->on('teacher_class_test_questions')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_class_test_attempt_answers');
        Schema::dropIfExists('teacher_class_test_attempts');
        Schema::dropIfExists('teacher_class_test_recipients');
        Schema::dropIfExists('teacher_class_test_assignments');

        if (Schema::hasTable('teacher_class_tests')) {
            Schema::table('teacher_class_tests', function (Blueprint $table) {
                $dropCols = array_values(array_filter([
                    'test_version',
                    'scheduled_start_at',
                    'scheduled_end_at',
                    'duration_minutes',
                    'max_attempts',
                    'result_visibility',
                    'allow_answer_review',
                    'results_released_at',
                    'published_at',
                    'closed_at',
                    'archived_at',
                ], fn (string $col) => Schema::hasColumn('teacher_class_tests', $col)));

                if ($dropCols !== []) {
                    $table->dropColumn($dropCols);
                }
            });

            if (DB::getDriverName() === 'mysql') {
                DB::table('teacher_class_tests')->where('status', 'scheduled')->update(['status' => 'draft']);
                DB::statement(
                    "ALTER TABLE `teacher_class_tests` MODIFY COLUMN `status` ENUM('draft', 'published', 'closed', 'archived') NOT NULL DEFAULT 'draft'"
                );
            }
        }
    }
};
