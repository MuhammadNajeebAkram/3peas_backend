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
        Schema::create('offered_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offered_class_id')->constrained('offered_classes')->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->string('description')->nullable();
            $table->integer('display_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offered_programs');
    }
};
