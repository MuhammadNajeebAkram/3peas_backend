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
        Schema::create('news', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('news_categories')->onDelete('cascade');
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('summary')->nullable();
            $table->longText('content');
            $table->enum('news_type', ['article', 'educational', 'event', 'video', 'gallery', 'announcement'])->default('article');
            $table->enum('language', ['en', 'ur'])->default('en');
            $table->foreignId('institute_id')->nullable()->constrained('institute_tbl')->noActionOnDelete();
            $table->date('event_date')->nullable();
            $table->string('location')->nullable();
            $table->string('featured_image')->nullable();
            $table->string('thumbnail_image')->nullable();
            $table->string('og_image')->nullable();
            $table->string('video_url')->nullable();
            $table->boolean('is_breaking')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_published')->default(false);
            $table->dateTime('published_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->integer('view_count')->default(0);
            $table->integer('display_order')->default(0);
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->string('meta_keywords')->nullable();
            $table->foreignId('created_by')->constrained('users')->noActionOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->noActionOnDelete();
            $table->boolean('is_activated')->default(true);

            $table->timestamps();

            $table->index(['is_published', 'published_at']);
$table->index(['category_id', 'is_published', 'published_at']);
$table->index(['news_type', 'is_published', 'published_at']);
$table->index(['language', 'is_published', 'published_at']);
$table->index(['is_featured', 'is_published', 'published_at']);
$table->index(['is_breaking', 'is_published', 'published_at']);
$table->index(['institute_id', 'is_published', 'published_at']);
$table->index('expires_at');
$table->index('display_order');
$table->index('created_by');
$table->index('updated_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('news');
    }
};
