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
        Schema::create('paper_part_sections', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();            
            $table->integer('no_of_questions')->default(1);
            $table->foreignId('paper_part_id')->constrained('paper_parts')->onDelete('cascade');
            $table->boolean('activate')->default(1);
            $table->boolean('show_name')->default(1);
            $table->integer('sequence');
            $table->boolean('urdu_lang')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paper_part_sections');
    }
};
