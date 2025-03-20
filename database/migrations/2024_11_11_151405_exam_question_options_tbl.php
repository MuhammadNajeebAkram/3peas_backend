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
        Schema::create('exam_question_options_tbl', function(Blueprint $table){
            $table -> id();
            $table -> integer('question_id');
            $table -> string('option');
            $table -> boolean('is_answer') -> default(false);  
            $table -> boolean('option_lang') -> default(false);         
            $table -> boolean('option_um_lang') -> default(true);         
            $table -> timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::dropIfExists('exam_question_options_tbl');
    }
};
