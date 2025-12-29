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
        Schema::table('news_tbl', function (Blueprint $table) {
            //
            $table->string('ticker_text')->nullable();
            $table->string('featured_image')->nullable();
            $table->string('breaking_news_image')->nullable();
            $table->string('thumbnail_image')->nullable();
            $table->string('og_image')->nullable();
            $table->integer('priority_score')->index()->default(30);
            $table->boolean('is_breaking_news')->default(false);
            $table->date('expires_at')->index()->nullable();
            $table->enum('status', ['draft', 'published', 'archived'])->index()->default('draft');
            $table->string('meta_title')->nullable();
            $table->text('url_link')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('news_tbl', function (Blueprint $table) {
            //
            $table->dropColumn('featured_image');
            $table->dropColumn('carousel_image');
            $table->dropColumn('thumbnail_image');
            $table->dropColumn('og_image');
            $table->dropColumn('priority_score');
            $table->dropColumn('is_breaking_news');
            $table->dropColumn('expires_at');
            $table->dropColumn('status');
            $table->dropColumn('meta_title');
        });
    }
};
