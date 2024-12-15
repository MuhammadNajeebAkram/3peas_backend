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
        Schema::create('blog_tbl', function (Blueprint $table) {
            $table->id();
            $table -> string('title');
            $table -> string('slug');
            $table -> Text('content');
            $table -> integer('author_id');
            $table -> integer('category_id');
            $table -> boolean('activate') -> default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_tbl');
    }
};
