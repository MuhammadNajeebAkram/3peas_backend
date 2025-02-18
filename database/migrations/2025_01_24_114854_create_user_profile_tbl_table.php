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
        Schema::create('user_profile_tbl', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->string('address')->nullable();
            $table->integer('city_id');
            $table->string('phone');
            $table->integer('class_id');
            $table->integer('curriculum_board_id');
            $table->integer('institute_id');
            $table->string('incharge_name')->nullable();
            $table->string('incharge_phone')->nullable();
            $table->integer('gender_id');
            $table->integer('study_plan_id');
            $table->Text('avatar')->nullable();
            $table->integer('heard_about_id');
            $table->boolean('activate')->default(true); 
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_profile_tbl');
    }
};
