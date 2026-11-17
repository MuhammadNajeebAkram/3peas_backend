<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('teacher_classes') || !Schema::hasTable('teacher_class_assignments') || !Schema::hasTable('web_users')) {
            throw new \RuntimeException('Phase 7A–7F tables are required before Phase 7G migration.');
        }

        if (!Schema::hasTable('teacher_class_assignment_evaluations')) {
            Schema::create('teacher_class_assignment_evaluations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('assignment_id');
                $table->unsignedBigInteger('teacher_class_id');
                $table->unsignedBigInteger('submission_id')->nullable();
                $table->unsignedBigInteger('student_web_user_id');
                $table->unsignedBigInteger('evaluated_by_web_user_id')->nullable();
                $table->enum('status', ['draft', 'released', 'reopened'])->default('draft');
                $table->enum('evaluation_type', ['graded', 'finalized_zero'])->default('graded');
                $table->decimal('obtained_marks', 8, 2)->nullable();
                $table->decimal('max_marks_snapshot', 8, 2)->nullable();
                $table->longText('feedback_text')->nullable();
                $table->string('last_correction_reason', 500)->nullable();
                $table->unsignedInteger('submission_version_evaluated')->nullable();
                $table->timestamp('draft_saved_at')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->timestamp('reopened_at')->nullable();
                $table->timestamp('notified_released_at')->nullable();
                $table->timestamps();

                $table->unique(['assignment_id', 'student_web_user_id'], 'uk_tcae_assign_student');
                $table->index(['teacher_class_id', 'status'], 'idx_tcae_class_status');
                $table->index(['student_web_user_id', 'status'], 'idx_tcae_student_status');

                $table->foreign('assignment_id', 'tcae_assign_fk')
                    ->references('id')->on('teacher_class_assignments')->cascadeOnDelete();
                $table->foreign('teacher_class_id', 'tcae_class_fk')
                    ->references('id')->on('teacher_classes')->cascadeOnDelete();
                $table->foreign('submission_id', 'tcae_sub_fk')
                    ->references('id')->on('teacher_class_assignment_submissions')->nullOnDelete();
                $table->foreign('student_web_user_id', 'tcae_student_fk')
                    ->references('id')->on('web_users')->cascadeOnDelete();
                $table->foreign('evaluated_by_web_user_id', 'tcae_evaluator_fk')
                    ->references('id')->on('web_users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('teacher_class_assignment_evaluation_histories')) {
            Schema::create('teacher_class_assignment_evaluation_histories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('evaluation_id');
                $table->unsignedBigInteger('assignment_id');
                $table->unsignedBigInteger('teacher_class_id');
                $table->unsignedBigInteger('student_web_user_id');
                $table->unsignedBigInteger('actor_web_user_id')->nullable();
                $table->enum('action', ['draft_saved', 'released', 'reopened', 'corrected', 'finalized_zero']);
                $table->string('previous_status', 30)->nullable();
                $table->string('new_status', 30);
                $table->decimal('previous_marks', 8, 2)->nullable();
                $table->decimal('new_marks', 8, 2)->nullable();
                $table->longText('previous_feedback')->nullable();
                $table->longText('new_feedback')->nullable();
                $table->string('reason', 500)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['evaluation_id', 'created_at'], 'idx_tcaeh_eval_created');
                $table->index(['assignment_id', 'student_web_user_id'], 'idx_tcaeh_assign_student');

                $table->foreign('evaluation_id', 'tcaeh_eval_fk')
                    ->references('id')->on('teacher_class_assignment_evaluations')->cascadeOnDelete();
                $table->foreign('assignment_id', 'tcaeh_assign_fk')
                    ->references('id')->on('teacher_class_assignments')->cascadeOnDelete();
                $table->foreign('teacher_class_id', 'tcaeh_class_fk')
                    ->references('id')->on('teacher_classes')->cascadeOnDelete();
                $table->foreign('student_web_user_id', 'tcaeh_student_fk')
                    ->references('id')->on('web_users')->cascadeOnDelete();
                $table->foreign('actor_web_user_id', 'tcaeh_actor_fk')
                    ->references('id')->on('web_users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('teacher_class_gradebook_configs')) {
            Schema::create('teacher_class_gradebook_configs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('teacher_class_id');
                $table->enum('retake_score_policy', ['highest', 'latest', 'average'])->default('highest');
                $table->boolean('weighting_enabled')->default(false);
                $table->unsignedBigInteger('study_session_id')->nullable();
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->unsignedBigInteger('updated_by_web_user_id')->nullable();
                $table->timestamps();

                $table->unique(['teacher_class_id'], 'uk_tcgc_class');

                $table->foreign('teacher_class_id', 'tcgc_class_fk')
                    ->references('id')->on('teacher_classes')->cascadeOnDelete();
                if (Schema::hasTable('study_session_tbl')) {
                    $table->foreign('study_session_id', 'tcgc_session_fk')
                        ->references('id')->on('study_session_tbl')->nullOnDelete();
                }
                $table->foreign('updated_by_web_user_id', 'tcgc_updater_fk')
                    ->references('id')->on('web_users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('teacher_class_gradebook_category_rules')) {
            Schema::create('teacher_class_gradebook_category_rules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('gradebook_config_id');
                $table->unsignedBigInteger('teacher_class_id');
                $table->enum('category_key', ['mcq_test', 'assignment']);
                $table->string('label', 80);
                $table->decimal('weight_percent', 5, 2)->default(0.00);
                $table->boolean('is_included')->default(true);
                $table->boolean('exclude_ungraded')->default(true);
                $table->timestamps();

                $table->unique(['teacher_class_id', 'category_key'], 'uk_tcgcr_class_cat');

                $table->foreign('gradebook_config_id', 'tcgcr_config_fk')
                    ->references('id')->on('teacher_class_gradebook_configs')->cascadeOnDelete();
                $table->foreign('teacher_class_id', 'tcgcr_class_fk')
                    ->references('id')->on('teacher_classes')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('teacher_class_gradebook_assessment_exclusions')) {
            Schema::create('teacher_class_gradebook_assessment_exclusions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('teacher_class_id');
                $table->enum('assessment_type', ['mcq_test', 'assignment']);
                $table->unsignedBigInteger('source_id');
                $table->boolean('is_excluded')->default(false);
                $table->unsignedBigInteger('updated_by_web_user_id')->nullable();
                $table->timestamps();

                $table->unique(['teacher_class_id', 'assessment_type', 'source_id'], 'uk_tcgae_class_src');
                $table->index(['teacher_class_id', 'is_excluded'], 'idx_tcgae_class_excl');

                $table->foreign('teacher_class_id', 'tcgae_class_fk')
                    ->references('id')->on('teacher_classes')->cascadeOnDelete();
                $table->foreign('updated_by_web_user_id', 'tcgae_updater_fk')
                    ->references('id')->on('web_users')->nullOnDelete();
            });
        }

        if (Schema::hasTable('permissions')) {
            $now = now();
            $exists = DB::table('permissions')->where('name', 'academic-reports.view')->exists();
            if (!$exists) {
                DB::table('permissions')->insert([
                    'name' => 'academic-reports.view',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            if (Schema::hasTable('roles') && Schema::hasTable('role_permissions')) {
                $permId = DB::table('permissions')->where('name', 'academic-reports.view')->value('id');
                $superRoleId = DB::table('roles')->where('name', 'super_admin')->value('id');
                if ($permId && $superRoleId) {
                    $linkExists = DB::table('role_permissions')
                        ->where('role_id', $superRoleId)
                        ->where('permission_id', $permId)
                        ->exists();
                    if (!$linkExists) {
                        DB::table('role_permissions')->insert([
                            'role_id' => $superRoleId,
                            'permission_id' => $permId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_class_gradebook_assessment_exclusions');
        Schema::dropIfExists('teacher_class_gradebook_category_rules');
        Schema::dropIfExists('teacher_class_gradebook_configs');
        Schema::dropIfExists('teacher_class_assignment_evaluation_histories');
        Schema::dropIfExists('teacher_class_assignment_evaluations');
    }
};
