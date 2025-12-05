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
        Schema::table('study_group_tbl', function (Blueprint $table) {
            //
            $table->unsignedBigInteger('class_id')->change();
            $table->foreign('class_id')->references('id')->on('class_tbl')->onDelete('cascade');

            $table->unsignedBigInteger('curriculum_board_id')->change();
            $table->foreign('curriculum_board_id')->references('id')->on('curriculum_board_tbl')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('study_group_tbl', function (Blueprint $table) {
            //
            $table->dropForeign('class_id');
            $table->dropForeign('curriculum_board_id');
        });
    }
};
