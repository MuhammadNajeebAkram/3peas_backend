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
        Schema::create('unit_slo_topic_tbl', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('book_unit_tbl')->cascadeOnDelete();
            $table->foreignId('slo_topic_id')->constrained('slo_topic_tbl')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['unit_id', 'slo_topic_id'], 'uq_unit_slo_topic');
            $table->index('unit_id');
            $table->index('slo_topic_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unit_slo_topic_tbl');
    }
};
