<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('question_reports')) {
            Schema::create('question_reports', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('question_id');
                $table->unsignedBigInteger('reporter_web_user_id');
                $table->string('reporter_role', 20)->default('student');
                $table->string('report_category', 50);
                $table->text('description')->nullable();
                $table->string('status', 20)->default('pending');

                // Canonical Curriculum Ownership fields (resolved from question hierarchy)
                $table->unsignedBigInteger('curriculum_board_id')->nullable();
                $table->unsignedBigInteger('class_id')->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->unsignedBigInteger('book_unit_id')->nullable();
                $table->unsignedBigInteger('book_unit_topic_id')->nullable();

                // UI Context fields
                $table->string('portal', 20)->default('student'); // student or teacher
                $table->string('page_context', 100)->nullable(); // e.g. test_attempt, my_classes, teacher_test_builder
                $table->json('metadata')->nullable(); // non-essential context (e.g. client timestamp, test_id, attempt_id)
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->index('question_id');
                $table->index('reporter_web_user_id');
                $table->index('status');
                $table->index('report_category');
                $table->index('reporter_role');
                $table->index(['curriculum_board_id', 'class_id', 'subject_id']);
                $table->index(['question_id', 'status']);
                $table->index(['reporter_web_user_id', 'status']);
                $table->index(['status', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('question_reports');
    }
};
