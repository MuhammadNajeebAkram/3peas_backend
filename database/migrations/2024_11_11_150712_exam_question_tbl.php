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
        //
        Schema::create('exam_question_tbl', function(Blueprint $table){
            $table -> id();
            $table -> string('question');
            $table -> integer('topic_id');            
            $table -> integer('question_type');     // MCQ, Short Question, Long Question
            $table -> boolean('exercise_question') -> default(false);
            $table -> boolean('question_lang') -> default(0);
            $table -> boolean('question_um_lang') -> default(0);
            $table -> tinyInteger('cognitive_domain') -> default(1);    // 1 = Knowledge, 2 = Understanding, 3 = Application
            $table -> tinyInteger('is_mcq') -> default(1);
            $table -> boolean('activate') -> default(true);
            $table -> timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::dropIfExists('exam_question_tbl');
    }
};
