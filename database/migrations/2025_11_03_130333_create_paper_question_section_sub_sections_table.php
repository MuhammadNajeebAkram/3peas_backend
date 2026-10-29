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
        Schema::create('paper_question_section_sub_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained('paper_question_sections')->onDelete('cascade');
            $table->string('sub_section_name');
            $table->integer('total_questions')->default(0);
            $table->foreignId('question_type_id')->constrained('question_type_tbl')->onDelete('no action');
            $table->boolean('activate')->default(1);
            $table->timestamps();
        });

        if (Schema::hasTable('question_pairing_schemes')) {
            try {
                Schema::table('question_pairing_schemes', function (Blueprint $table) {
                    $table->foreign('sub_section_id')->references('id')->on('paper_question_section_sub_sections')->onDelete('cascade');
                });
            } catch (\Throwable $e) {}
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paper_question_section_sub_sections');
    }
};
