<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_explanation_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('ai_explanation_batches')->cascadeOnDelete();
            $table->unsignedBigInteger('question_id');
            $table->string('effective_language', 16);
            $table->string('status', 32)->default('pending')->index();
            $table->foreignId('ai_request_id')->nullable()->constrained('ai_requests')->nullOnDelete();

            $table->text('draft_explanation')->nullable();
            $table->text('draft_explanation_um')->nullable();
            $table->string('context_hash', 64)->nullable();
            $table->json('explanation_hashes')->nullable();

            $table->boolean('needs_review')->default(false);
            $table->text('review_reason')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();

            $table->foreignId('saved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('saved_at')->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'question_id']);
            $table->index(['batch_id', 'status']);
            $table->index('question_id');
            $table->foreign('question_id')->references('id')->on('exam_question_tbl')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_explanation_batch_items');
    }
};
