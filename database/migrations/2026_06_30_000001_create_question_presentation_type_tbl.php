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
        Schema::create('question_presentation_type_tbl', function (Blueprint $table) {
            $table->id();
            $table->string('type_name')->unique();
            $table->string('code')->nullable()->unique();
            $table->text('description')->nullable();
            $table->boolean('activate')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_presentation_type_tbl');
    }
};
