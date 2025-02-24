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
            $table->unique(['subject_id', 'class_id', 'curriculum_board_id'], 'unique_subject_class_curriculum');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('study_subjects_tbl', function (Blueprint $table) {
            //
            $table->dropUnique('unique_subject_class_curriculum');
            
        });
    }
};
