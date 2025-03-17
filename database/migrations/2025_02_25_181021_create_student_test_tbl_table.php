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
        Schema::create('student_test_tbl', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->tinyInteger('test_type');
            $table->timestamps();
            $table->timestamp('test_start_at')->nullable();
            $table->timestamp('test_end_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_test_tbl');
    }
};
