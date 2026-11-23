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
        Schema::create('freemium_quota_consumption_envelopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumption_id')->constrained('freemium_quota_consumptions')->restrictOnDelete()->comment('References freemium_quota_consumptions(id)');
            $table->foreignId('campaign_id')->constrained('freemium_campaigns')->restrictOnDelete()->comment('References freemium_campaigns(id)');
            $table->foreignId('quota_id')->constrained('freemium_exam_quotas')->restrictOnDelete()->comment('References freemium_exam_quotas(id)');
            $table->foreignId('grant_id')->nullable()->constrained('freemium_student_grants')->restrictOnDelete()->comment('References freemium_student_grants(id) when grant-funded');

            $table->enum('window_type', ['daily', 'weekly', 'monthly', 'annual', 'campaign', 'lifetime']);
            $table->string('window_key', 100);
            $table->unsignedInteger('counter_value_after_consumption');
            $table->timestamp('allocated_at')->useCurrent();

            $table->unique(['consumption_id', 'campaign_id', 'quota_id', 'window_key'], 'uk_consumption_envelope');
            $table->index(['campaign_id', 'window_type', 'window_key', 'allocated_at'], 'idx_campaign_window_audit');
            $table->index(['grant_id', 'allocated_at'], 'idx_grant_audit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('freemium_quota_consumption_envelopes');
    }
};
