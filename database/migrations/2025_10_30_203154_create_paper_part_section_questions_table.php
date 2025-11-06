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
        Schema::create('paper_part_section_questions', function (Blueprint $table) {
            $table->id();
            $table->string('question_statement')->nullable();
            $table->string('question_no');
            $table->string('marks')->nullable();
            $table->foreignId('section_id')->constrained('paper_part_sections')->onDelete('cascade');           
            $table->boolean('urdu_lang')->default(0);
            $table->integer('sequence')->default(1);
            $table->boolean('activate')->default(1);
            $table->boolean('is_get_statement')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paper_part_section_questions');
    }
};
