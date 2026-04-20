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
        Schema::create('student_subject_progress_summaries', function (Blueprint $table) {
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
           

            $table->unsignedInteger('total_questions')->default(0);

            $table->unsignedInteger('practice_attempted')->default(0);
            $table->unsignedInteger('practice_correct')->default(0);
            $table->unsignedInteger('practice_wrong')->default(0);

            $table->unsignedInteger('formal_attempted')->default(0);
            $table->unsignedInteger('formal_correct')->default(0);
            $table->unsignedInteger('formal_wrong')->default(0);

            $table->unsignedInteger('distinct_questions_seen')->default(0);

            $table->decimal('practice_accuracy', 5, 2)->default(0);
            $table->decimal('formal_accuracy', 5, 2)->default(0);

            $table->timestamp('last_practiced_at')->nullable();
            $table->timestamp('last_tested_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'offered_program_id', 'subject_id'], 'ssps_user_program_subj_idx');

            $table->index(['user_id', 'subject_id']);           
            $table->index(['user_id', 'offered_program_id'], 'ssps_user_program_idx');
            $table->index('last_practiced_at');
            $table->index('last_tested_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_subject_progress_summaries');
    }
};
