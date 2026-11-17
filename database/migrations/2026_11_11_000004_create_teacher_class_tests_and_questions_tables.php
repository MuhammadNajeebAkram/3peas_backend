<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('teacher_classes') || !Schema::hasTable('teacher_profiles')) {
            throw new \RuntimeException('teacher_classes and teacher_profiles are required before creating teacher test tables.');
        }

        if (!Schema::hasTable('teacher_class_tests')) {
            Schema::create('teacher_class_tests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('teacher_class_id');
                $table->unsignedBigInteger('teacher_profile_id');
                $table->unsignedBigInteger('curriculum_board_id');
                $table->unsignedBigInteger('class_id');
                $table->unsignedBigInteger('subject_id');
                $table->string('title', 255);
                $table->text('description')->nullable();
                $table->text('instructions')->nullable();
                $table->enum('selection_method', ['manual', 'automatic', 'mixed'])->default('manual');
                $table->json('selection_criteria_json')->nullable();
                $table->unsignedSmallInteger('total_questions')->default(0);
                $table->decimal('marks_per_question', 6, 2)->default(1.00);
                $table->decimal('total_marks', 8, 2)->default(0.00);
                $table->decimal('negative_marks_per_wrong', 6, 2)->default(0.00);
                $table->enum('language_mode', ['english', 'urdu', 'bilingual'])->default('bilingual');
                $table->enum('status', ['draft', 'published', 'closed', 'archived'])->default('draft');
                $table->timestamps();

                $table->index(['teacher_class_id', 'status', 'created_at'], 'idx_tct_class_status_created');
                $table->index(['teacher_profile_id', 'status'], 'idx_tct_teacher_status');

                $table->foreign('teacher_class_id', 'tct_class_fk')
                    ->references('id')->on('teacher_classes')->cascadeOnDelete();
                $table->foreign('teacher_profile_id', 'tct_teacher_profile_fk')
                    ->references('id')->on('teacher_profiles')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('teacher_class_test_questions')) {
            Schema::create('teacher_class_test_questions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('teacher_class_test_id');
                $table->unsignedBigInteger('question_id');
                $table->unsignedBigInteger('unit_id')->nullable();
                $table->unsignedBigInteger('topic_id')->nullable();
                $table->unsignedBigInteger('slo_topic_id')->nullable();
                $table->unsignedBigInteger('scenario_group_id')->nullable();
                $table->unsignedSmallInteger('question_order');
                $table->decimal('marks', 6, 2)->default(1.00);
                $table->decimal('negative_marks', 6, 2)->default(0.00);
                $table->enum('question_source', ['bank'])->default('bank');
                $table->mediumText('question_text_snapshot');
                $table->mediumText('question_text_um_snapshot')->nullable();
                $table->mediumText('scenario_text_snapshot')->nullable();
                $table->mediumText('scenario_text_um_snapshot')->nullable();
                $table->string('scenario_image_snapshot', 500)->nullable();
                $table->json('options_snapshot_json');
                $table->unsignedBigInteger('correct_option_id_snapshot')->nullable();
                $table->string('correct_option_key_snapshot', 16)->nullable();
                $table->mediumText('explanation_snapshot')->nullable();
                $table->mediumText('explanation_um_snapshot')->nullable();
                $table->json('metadata_snapshot_json')->nullable();
                $table->timestamps();

                $table->unique(['teacher_class_test_id', 'question_id'], 'uk_tctq_test_question');
                $table->unique(['teacher_class_test_id', 'question_order'], 'uk_tctq_test_order');
                $table->index(['question_id'], 'idx_tctq_question');

                $table->foreign('teacher_class_test_id', 'tctq_test_fk')
                    ->references('id')->on('teacher_class_tests')->cascadeOnDelete();

                if (Schema::hasTable('exam_question_tbl')) {
                    $table->foreign('question_id', 'tctq_bank_question_fk')
                        ->references('id')->on('exam_question_tbl')->restrictOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_class_test_questions');
        Schema::dropIfExists('teacher_class_tests');
    }
};
