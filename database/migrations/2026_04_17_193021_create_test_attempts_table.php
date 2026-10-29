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
        if (!Schema::hasTable('tests')) {
            Schema::create('tests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('offered_program_id')
                    ->nullable()
                    ->constrained('offered_programs')
                    ->nullOnDelete();

                $table->foreignId('subject_id')
                    ->nullable()
                    ->constrained('subject_tbl')
                    ->nullOnDelete();

                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

                $table->string('title');
                $table->string('description')->nullable();
                $table->enum('test_source', ['system', 'teacher', 'official'])->default('system');            

                $table->enum('test_mode', ['test', 'mock']);
                $table->enum('scope_type', ['chapter', 'multiple_chapters', 'full_book']);
                $table->enum('question_type', ['mcq'])->default('mcq');
                $table->unsignedInteger('time_limit_minutes')->nullable();
                $table->unsignedInteger('total_questions')->default(0);
                $table->boolean('is_published')->default(false);
                $table->timestamp('published_at')->nullable();
                $table->timestamps();

                $table->index(['offered_program_id', 'subject_id']);
                $table->index(['test_source', 'test_mode']);
                $table->index(['is_published', 'published_at']);
                $table->index(['created_by', 'test_source']);
                $table->index(['subject_id', 'test_mode']);
                $table->index(['offered_program_id', 'is_published']);
            });
        }

        Schema::create('test_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_id')->constrained('tests')->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('web_users')
                ->cascadeOnDelete();          

            $table->unsignedInteger('attempted_questions')->default(0);
            $table->unsignedInteger('correct_answers')->default(0);
            $table->unsignedInteger('wrong_answers')->default(0);
            $table->unsignedInteger('not_attempted_questions')->default(0);

            $table->decimal('score', 8, 2)->default(0);
            $table->decimal('accuracy', 5, 2)->default(0);
            

            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();

            $table->enum('status', ['in_progress', 'submitted', 'expired', 'cancelled'])
                ->default('in_progress');

            $table->timestamps();

            $table->index(['user_id', 'status']);
    $table->index(['user_id', 'test_id']);
    $table->index(['test_id', 'status']);
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
        Schema::dropIfExists('test_attempts');
    }
};
