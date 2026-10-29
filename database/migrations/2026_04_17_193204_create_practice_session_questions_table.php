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
        if (!Schema::hasTable('practice_sessions')) {
            Schema::create('practice_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('web_users')->cascadeOnDelete();      
                $table->foreignId('offered_program_id')->nullable()->constrained('offered_programs')->nullOnDelete();
                $table->foreignId('subject_id')->nullable()->constrained('subject_tbl')->nullOnDelete();
                $table->enum('scope_type', ['chapter', 'multiple_chapters', 'full_book']);
                $table->enum('question_type', ['mcq'])->default('mcq');
                $table->unsignedInteger('time_limit_minutes')->nullable();
                $table->unsignedInteger('total_questions')->default(0);
                $table->unsignedInteger('attempted_questions')->default(0);
                $table->unsignedInteger('correct_answers')->default(0);
                $table->unsignedInteger('wrong_answers')->default(0);
                $table->unsignedInteger('not_attempted_questions')->default(0);
                $table->decimal('score', 8, 2)->default(0);
                $table->decimal('accuracy', 5, 2)->default(0);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->enum('status', ['in_progress', 'submitted', 'abandoned'])->default('in_progress');
                $table->timestamps();

                $table->index(['user_id', 'status']);   
                $table->index(['user_id', 'submitted_at']);
                $table->index(['status', 'submitted_at']);
                $table->index('started_at');
                $table->index('submitted_at');
            });
        }

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
