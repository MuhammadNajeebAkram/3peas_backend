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
        Schema::create('user_study_plan_tbl', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->integer('study_plan_id');
            $table->integer('qty')->default(1);
            $table->float('price');            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_study_plan_tbl');
    }
};
