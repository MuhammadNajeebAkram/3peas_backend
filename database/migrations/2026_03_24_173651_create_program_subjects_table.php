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
        Schema::create('program_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offered_program_id')->constrained('offered_programs')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subject_tbl')->cascadeOnDelete();
            $table->integer('display_order')->default(1);
            $table->boolean('is_demo_available')->default(false);
            $table->boolean('is_free')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('program_subjects');
    }
};
