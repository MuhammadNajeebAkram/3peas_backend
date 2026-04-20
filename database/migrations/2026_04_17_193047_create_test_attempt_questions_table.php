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
        Schema::create('test_attempt_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')
                ->constrained('test_attempts')
                ->cascadeOnDelete();

            $table->foreignId('question_id')
                ->constrained('exam_question_tbl')
                ->cascadeOnDelete();

            $table->unsignedInteger('question_order')->default(0);

            $table->foreignId('selected_option_id')
                ->nullable()
                ->constrained('exam_question_options_tbl')
                ->nullOnDelete();

            $table->boolean('is_attempted')->default(false);
            $table->boolean('is_correct')->nullable();

            $table->decimal('marks', 6, 2)->default(1.00);
            $table->decimal('obtained_marks', 6, 2)->default(0.00);

            $table->unsignedInteger('time_spent_seconds')->nullable();

            $table->timestamps();

            $table->index(['attempt_id', 'question_order']);
    $table->index(['attempt_id', 'is_attempted']);
    $table->index(['attempt_id', 'is_correct']);
    $table->index('question_id');
    $table->unique(['attempt_id', 'question_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('test_attempt_questions');
    }
};
