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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tests');
    }
};
