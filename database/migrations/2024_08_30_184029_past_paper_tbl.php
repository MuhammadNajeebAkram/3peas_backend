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
        Schema::create('past_paper_tbl', function(Blueprint $table){
            $table -> id();
            $table -> string('paper_name');
            $table -> string('paper_path');
            $table -> integer('class_id');
            $table -> integer('subject_id');
            $table -> integer('board_id');
            $table -> integer('year');
            $table -> integer('group');
            $table -> timestamps();
        });
        //
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('past_paper_tbl');
        //
    }
};
