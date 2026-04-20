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
        Schema::create('practice_sessions', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('user_id')
        ->constrained('web_users')
        ->cascadeOnDelete();      

    $table->foreignId('offered_program_id')
                ->nullable()
                ->constrained('offered_programs')
                ->nullOnDelete();

                 $table->foreignId('subject_id')
                ->nullable()
                ->constrained('subject_tbl')
                ->nullOnDelete();

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

    $table->enum('status', ['in_progress', 'submitted', 'abandoned'])
        ->default('in_progress');

    $table->timestamps();

    $table->index(['user_id', 'status']);   
    
    $table->index(['user_id', 'submitted_at']);
    $table->index(['status', 'submitted_at']);
    $table->index('started_at');
    $table->index('submitted_at');
   
    
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('practice_sessions');
    }
};
