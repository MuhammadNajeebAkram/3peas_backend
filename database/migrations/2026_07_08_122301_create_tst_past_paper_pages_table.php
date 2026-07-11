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
        Schema::create('tst_past_paper_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('past_paper_id')->constrained('tst_past_papers')->cascadeOnDelete();
            $table->integer('page_no');
            $table->enum('paper_type', ['objective', 'subjective', 'complete'])->default('objective');
            $table->string('image_path');
            $table->string('thumbnail_path');
            $table->integer('image_width')->nullable();
            $table->integer('image_height')->nullable();
            $table->string('page_type')->nullable();  // webp, png, pdf
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['past_paper_id', 'paper_type', 'page_no'],
                'tst_pp_pages_unique'
            );
            $table->index(
                ['past_paper_id', 'page_no'],
                'tst_pp_pages_order_idx'
            );
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tst_past_paper_pages');
    }
};
