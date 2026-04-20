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
        Schema::create('test_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_id')
        ->constrained('tests')
        ->cascadeOnDelete();

    $table->foreignId('unit_id')
        ->constrained('book_unit_tbl')
        ->cascadeOnDelete();

    $table->timestamps();

    $table->unique(['test_id', 'unit_id']);
    $table->index('unit_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('test_scopes');
    }
};
