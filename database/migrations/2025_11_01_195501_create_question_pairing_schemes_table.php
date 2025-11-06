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
        Schema::create('question_pairing_schemes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sub_section_id')->constrained('paper_question_section_sub_sections')->onDelete('cascade');
            $table->foreignId('unit_id')->constrained('book_unit_tbl')->onDelete('cascade');
            $table->integer('no_of_questions')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_pairing_schemes');
    }
};
