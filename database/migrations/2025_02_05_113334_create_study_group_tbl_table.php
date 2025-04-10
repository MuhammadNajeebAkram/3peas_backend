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
        Schema::create('study_group_tbl', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('class_id');
            $table->integer('curriculum_board_id');
            $table->boolean('activate');
            $table->timestamps();

            $table->unique(['name', 'class_id', 'curriculum_board_id'], 'unique_name_class_curriculum');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('study_group_tbl');
    }
};
