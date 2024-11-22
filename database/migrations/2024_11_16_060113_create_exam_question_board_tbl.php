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
        Schema::create('exam_question_board_tbl', function (Blueprint $table) {
            $table->id();
            $table->integer('question_id');
            $table -> integer('board_id');
            $table -> integer('session_id');
            $table -> integer('group_id');
            $table -> integer('year');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_question_board_tbl');
    }
};
