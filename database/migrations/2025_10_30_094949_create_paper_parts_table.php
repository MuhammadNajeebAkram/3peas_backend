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
        Schema::create('paper_parts', function (Blueprint $table) {
            $table->id();            
            $table->string('name');
            $table->string('name_um');
            $table->string('max_marks');
            $table->string('max_marks_um');
            $table->string('time_allowed');
            $table->string('time_allowed_um');
            $table->integer('no_of_sections')->default(1);           
            $table->foreignId('model_paper_id')->constrained('model_papers')->onDelete('cascade');            
            $table->boolean('activate')->default(1);
            $table->integer('sequence');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paper_parts');
    }
};
