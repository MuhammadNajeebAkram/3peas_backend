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
        Schema::create('workshops', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();

            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();

            $table->string('cover_image')->nullable();
            $table->string('og_image')->nullable();

            $table->enum('workshop_mode', ['physical', 'online', 'hybrid'])->default('physical');

            $table->foreignId('institute_id')
                ->nullable()
                ->constrained('institutes')
                ->nullOnDelete();

            $table->string('speaker_name')->nullable();
            $table->string('speaker_designation')->nullable();

            $table->dateTime('start_at');
            $table->dateTime('end_at')->nullable();

            $table->string('location')->nullable();
            $table->string('meeting_link')->nullable();

            $table->unsignedInteger('seat_limit')->nullable();
            $table->unsignedInteger('registered_count')->default(0);

            $table->dateTime('registration_deadline')->nullable();
            $table->boolean('is_registration_open')->default(true);

            $table->string('recording_url')->nullable();
            $table->enum('recording_access', ['public', 'logged_in', 'registered_only'])
                ->default('logged_in');

            $table->boolean('quiz_enabled')->default(false);

            $table->boolean('is_featured')->default(false);
            $table->boolean('is_published')->default(false);
            $table->dateTime('published_at')->nullable();

            // SEO fields
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('meta_keywords')->nullable();
            $table->string('canonical_url')->nullable();

            $table->foreignId('created_by')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['is_published', 'published_at'], 'wrk_pub_idx');
            $table->index(['workshop_mode', 'is_published'], 'wrk_mode_pub_idx');
            $table->index(['institute_id', 'is_published'], 'wrk_inst_pub_idx');
            $table->index(['start_at', 'is_published'], 'wrk_start_pub_idx');
            $table->index(['is_featured', 'is_published'], 'wrk_feat_pub_idx');
            $table->index('registration_deadline', 'wrk_reg_deadline_idx');
            $table->index('created_by', 'wrk_created_by_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workshops');
    }
};
