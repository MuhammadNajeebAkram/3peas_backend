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
        Schema::create('practice_session_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')
        ->constrained('practice_sessions')
        ->cascadeOnDelete();

    $table->foreignId('unit_id')
        ->constrained('book_unit_tbl')
        ->cascadeOnDelete();

    $table->timestamps();

    $table->unique(['session_id', 'unit_id']);
    $table->index('unit_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('practice_session_scopes');
    }
};
