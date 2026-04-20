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
        Schema::create('test_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_id')->constrained('tests')->cascadeOnDelete();
            $table->foreignId('question_id')
                ->constrained('exam_question_tbl')
                ->cascadeOnDelete();

                $table->unsignedInteger('question_order')->default(0);
                $table->decimal('marks', 6, 2)->default(1.00);
            $table->timestamps();

            $table->index(['test_id', 'question_order']);
    $table->unique(['test_id', 'question_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('test_questions');
    }
};
