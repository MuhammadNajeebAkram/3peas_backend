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
        Schema::table('study_group_detail_tbl', function (Blueprint $table) {
            //
             $table->unsignedBigInteger('study_group_id')->change();
            $table->foreign('study_group_id')->references('id')->on('study_group_tbl')->onDelete('cascade');

            $table->unsignedBigInteger('subject_id')->change();
            $table->foreign('subject_id')->references('id')->on('subject_tbl')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('study_group_detail_tbl', function (Blueprint $table) {
            //
            $table->dropForeign('study_group_id');
            $table->dropForeign('subject_id');
        });
    }
};
