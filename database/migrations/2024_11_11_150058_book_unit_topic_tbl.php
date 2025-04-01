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
        Schema::create('book_unit_topic_tbl', function(Blueprint $table){
            $table -> id();
            $table -> string('topic_name');
            $table -> string('topic_name_um')->nullable();
            $table -> integer('topic_no')->nullable();
            $table -> integer('unit_id');
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
        Schema::dropIfExists('book_unit_topic_tbl');
    }
};
