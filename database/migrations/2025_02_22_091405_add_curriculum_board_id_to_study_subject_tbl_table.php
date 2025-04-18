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
        Schema::table('study_subjects_tbl', function (Blueprint $table) {
            //
            $table->integer('curriculum_board_id')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('study_subjects_tbl', function (Blueprint $table) {
            //
            $table->dropColumn('curriculum_board_tbl');
        });
    }
};
