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
        Schema::create('freemium_exam_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('freemium_campaigns')->restrictOnDelete();

            $table->enum('exam_type', ['practice', 'formal_test', 'mock']);
            $table->enum('window_type', ['daily', 'weekly', 'monthly', 'annual', 'campaign', 'lifetime']);

            $table->integer('max_tests')->nullable()->comment('NULL or -1 represents unlimited tests in window');
            $table->boolean('allow_retakes')->default(false);
            $table->unsignedInteger('max_attempts_per_test')->default(1)->comment('Total permitted attempts');
            $table->unsignedInteger('time_limit_minutes')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->softDeletes();

            $table->unique(['campaign_id', 'exam_type', 'window_type'], 'uk_campaign_exam_window');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('freemium_exam_quotas');
    }
};
