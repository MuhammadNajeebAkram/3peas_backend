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
        Schema::create('paper_question_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('paper_part_section_questions')->onDelete('cascade');
            $table->string('section_name');
            $table->integer('no_of_sub_sections')->default(1);
            $table->boolean('activate')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paper_question_sections');
    }
};
