<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('student_question_progress_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('web_users')
                ->cascadeOnDelete();

            $table->foreignId('question_id')
                ->constrained('exam_question_tbl')
                ->cascadeOnDelete();

            $table->foreignId('offered_program_id')
                ->nullable()
                ->constrained('offered_programs')
                ->nullOnDelete();

            $table->foreignId('subject_id')
                ->nullable()
                ->constrained('subject_tbl')
                ->nullOnDelete();

            $table->foreignId('unit_id')
                ->nullable()
                ->constrained('book_unit_tbl')
                ->nullOnDelete();

            $table->unsignedInteger('practice_attempts')->default(0);
            $table->unsignedInteger('practice_correct')->default(0);
            $table->unsignedInteger('practice_wrong')->default(0);

            $table->unsignedInteger('formal_attempts')->default(0);
            $table->unsignedInteger('formal_correct')->default(0);
            $table->unsignedInteger('formal_wrong')->default(0);

            $table->timestamp('last_practiced_at')->nullable();
            $table->timestamp('last_tested_at')->nullable();

            $table->boolean('is_mastered')->default(false);

            $table->timestamps();

            $table->unique(['user_id', 'question_id']);

            $table->index(['user_id', 'subject_id']);
            $table->index(['user_id', 'unit_id']);
            $table->index(['user_id', 'offered_program_id'], 'sqps_user_program_idx');
            $table->index('is_mastered');
            $table->index('last_practiced_at');
            $table->index('last_tested_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_question_progress_summaries');
    }
};
