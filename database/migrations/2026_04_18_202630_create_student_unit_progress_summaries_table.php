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
        Schema::create('student_unit_progress_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
        ->constrained('web_users')
        ->cascadeOnDelete();

    $table->foreignId('offered_program_id')
        ->nullable()
        ->constrained('offered_programs')
        ->nullOnDelete();

    $table->foreignId('unit_id')
        ->constrained('book_unit_tbl')
        ->cascadeOnDelete();

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

    $table->unique(['user_id', 'offered_program_id', 'unit_id'], 'sups_user_program_unit_idx');

    $table->index(['user_id', 'unit_id']);
    $table->index(['user_id', 'offered_program_id'], 'sups_user_program_idx');
    $table->index('last_practiced_at');
    $table->index('last_tested_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_unit_progress_summaries');
    }
};
