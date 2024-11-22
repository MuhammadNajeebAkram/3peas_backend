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
        Schema::create('book_tbl', function(Blueprint $table){
            $table -> id();
            $table -> string('book_name');
            $table -> integer('class_id');
            $table -> integer('subject_id');
            $table -> boolean('activate')->default(true);
            $table -> timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::dropIfExists('book_tbl');
    }
};
