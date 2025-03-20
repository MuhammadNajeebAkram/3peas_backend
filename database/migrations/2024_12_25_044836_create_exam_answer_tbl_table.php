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
        Schema::create('exam_answer_tbl', function (Blueprint $table) {
            $table->id();
            $table -> integer('question_id');
            $table -> Text('answer');
            $table -> Text('answer_um');
            $table -> boolean('answer_lang')->default(0);       // 0 mean english
            $table -> boolean('answer_um_lang')->default(1);    // 1 mean urdu
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_answer_tbl');
    }
};
