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
        Schema::table('exam_question_tbl', function (Blueprint $table) {
            //
             $table->unsignedBigInteger('topic_id')->change();
             $table->unsignedBigInteger('question_type')->change();

            $table->foreign('topic_id')->references('id')->on('book_unit_topic_tbl')->onDelete('cascade');
            $table->foreign('question_type')->references('id')->on('question_type_tbl')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exam_question_tbl', function (Blueprint $table) {
            //
            $table->dropForeign('topic_id');
            $table->dropForeign('question_type');
        });
    }
};
