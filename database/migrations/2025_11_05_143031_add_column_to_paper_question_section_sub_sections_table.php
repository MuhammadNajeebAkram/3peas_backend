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
        Schema::table('paper_question_section_sub_sections', function (Blueprint $table) {
            //
            $table->boolean('is_random_units')->default(1);
            $table->integer('no_of_random_units')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('paper_question_section_sub_sections', function (Blueprint $table) {
            //
            $table->dropColumn('is_random_units');
            $table->dropColumn('no_of_random_units');
        });
    }
};
