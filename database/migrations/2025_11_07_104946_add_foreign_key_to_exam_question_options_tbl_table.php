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
        Schema::table('exam_question_options_tbl', function (Blueprint $table) {
            //
             $table->unsignedBigInteger('question_id')->change();

            $table->foreign('question_id')->references('id')->on('exam_question_tbl')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exam_question_options_tbl', function (Blueprint $table) {
            //
             $table->dropForeign('question_id');
        });
    }
};
