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
        Schema::create('offered_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('class_tbl')->onDelete('cascade');
            $table->foreignId('curriculum_board_id')->constrained('curriculum_board_tbl')->onDelete('cascade');
            $table->double('Price')->nullable();
            $table->double('discount_price')->nullable();
            $table->double('discount_percent')->nullable();
            $table->boolean('is_free')->default(false);
            $table->date('session_start')->nullable();
            $table->date('session_end')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offered_classes');
    }
};
