<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_explanation_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ai_provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->foreignId('ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->json('filters_snapshot')->nullable();
            $table->string('target_language', 16)->default('both');
            $table->boolean('overwrite_existing')->default(false);
            $table->string('status', 32)->default('queued')->index();

            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('pending_items')->default(0);
            $table->unsignedInteger('processing_items')->default(0);
            $table->unsignedInteger('generated_items')->default(0);
            $table->unsignedInteger('needs_review_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->unsignedInteger('skipped_items')->default(0);
            $table->unsignedInteger('saved_items')->default(0);

            $table->decimal('estimated_cost', 18, 8)->nullable();
            $table->decimal('actual_cost', 18, 8)->nullable();
            $table->unsignedBigInteger('actual_input_tokens')->default(0);
            $table->unsignedBigInteger('actual_output_tokens')->default(0);
            $table->unsignedBigInteger('actual_total_tokens')->default(0);
            $table->json('cost_estimate_snapshot')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamps();

            $table->index(['created_by', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_explanation_batches');
    }
};
