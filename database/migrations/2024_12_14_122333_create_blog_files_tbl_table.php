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
        Schema::create('blog_files_tbl', function (Blueprint $table) {
            $table->id();
            $table->integer('blog_id');
            $table->string('path');
            $table->string('file_type');
            $table->boolean('activate')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_files_tbl');
    }
};
