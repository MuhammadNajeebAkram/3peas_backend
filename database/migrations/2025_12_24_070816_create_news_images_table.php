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
        Schema::create('news_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('news_id')->index()->nullable();
            $table->string('image_path');
            $table->string('alt_text')->nullable();
            $table->integer('height')->nullable();
            $table->integer('width')->nullable();
            $table->integer('size_kb')->nullable();
            $table->foreign('news_id')->references('id')->on('news_tbl')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('news_images');
    }
};
