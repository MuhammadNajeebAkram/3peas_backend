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
        Schema::create('freemium_quota_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('web_users')->cascadeOnDelete()->comment('References web_users(id)');

            $table->enum('exam_type', ['practice', 'formal_test', 'mock']);
            $table->enum('window_type', ['daily', 'weekly', 'monthly', 'annual', 'campaign', 'lifetime']);
            $table->string('window_key', 100);
            $table->unsignedInteger('counter')->default(0);

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['user_id', 'exam_type', 'window_type', 'window_key'], 'uk_user_exam_window');
            $table->index(['window_type', 'window_key'], 'idx_window_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('freemium_quota_counters');
    }
};
