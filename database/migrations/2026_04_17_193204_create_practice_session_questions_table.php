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
        Schema::create('practice_session_questions', function (Blueprint $table) {
            $table->id();
             $table->foreignId('session_id')
        ->constrained('practice_sessions')
        ->cascadeOnDelete();            

            $table->foreignId('question_id')
                ->constrained('exam_question_tbl')
                ->cascadeOnDelete();

           

            $table->foreignId('selected_option_id')
                ->nullable()
                ->constrained('exam_question_options_tbl')
                ->nullOnDelete();
                 $table->unsignedInteger('question_order')->default(0);

            $table->boolean('is_attempted')->default(false);
            $table->boolean('is_correct')->nullable();
            $table->boolean('answer_shown')->default(false);

            $table->unsignedInteger('time_spent_seconds')->nullable();

            $table->timestamp('practiced_at')->nullable();

            $table->timestamps();

          $table->index(['session_id', 'question_order']);
    $table->index(['session_id', 'is_attempted']);
    $table->index(['session_id', 'is_correct']);
    $table->index(['question_id', 'is_correct']);
    $table->index('practiced_at');

    $table->unique(['session_id', 'question_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('practice_session_questions');
    }
};
