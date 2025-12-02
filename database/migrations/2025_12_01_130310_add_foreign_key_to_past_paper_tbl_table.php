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
        Schema::table('past_paper_tbl', function (Blueprint $table) {
            //
            $table->unsignedBigInteger('subject_id')->change();
            $table->foreign('subject_id')->references('id')->on('subject_tbl')->onDelete('cascade');

             $table->unsignedBigInteger('class_id')->change();
            $table->foreign('class_id')->references('id')->on('class_tbl')->onDelete('cascade');

             $table->unsignedBigInteger('board_id')->change();
            $table->foreign('board_id')->references('id')->on('board_tbl')->onDelete('cascade');

             $table->unsignedBigInteger('session_id')->change();
            $table->foreign('session_id')->references('id')->on('exam_session_tbl')->onDelete('cascade');

           
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('past_paper_tbl', function (Blueprint $table) {
            //
            $table->dropForeign('subject_id');
            $table->dropForeign('class_id');
            $table->dropForeign('board_id');
            $table->dropForeign('session_id');
        });
    }
};
