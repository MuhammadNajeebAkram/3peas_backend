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
        Schema::create('question_statistics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')
                ->constrained('exam_question_tbl')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('attempt_count')->default(0);
            $table->unsignedBigInteger('correct_count')->default(0);
            $table->unsignedBigInteger('wrong_count')->default(0);
            $table->unsignedBigInteger('skip_count')->default(0);

            $table->decimal('difficulty_index', 5, 2)->nullable();
            $table->decimal('discrimination_index', 5, 2)->nullable();

            $table->enum('computed_difficulty_band', ['easy', 'medium', 'hard'])
                ->nullable();

            $table->boolean('is_calibrated')->default(false);
            $table->timestamp('last_calculated_at')->nullable();

            $table->timestamps();

            $table->unique('question_id');
            $table->index('computed_difficulty_band');
            $table->index('is_calibrated');
            $table->index('last_calculated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_statistics');
    }
};
