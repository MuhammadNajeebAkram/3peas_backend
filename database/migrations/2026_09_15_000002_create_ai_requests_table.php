<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_id');
            $table->unsignedInteger('attempt_number')->default(1);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->string('purpose', 100)->index();
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->index(['subject_type', 'subject_id']);
            $table->string('trigger_type', 20)->default('user');
            // CRUD-created records are explicitly distinguishable from provider telemetry.
            $table->string('record_source', 20)->default('admin');
            $table->string('environment', 50);
            $table->string('provider', 50);
            $table->string('requested_model', 150);
            $table->string('returned_model', 150)->nullable();
            $table->string('language', 20)->nullable();
            $table->string('prompt_version', 100)->nullable();
            $table->json('request_parameters')->nullable();
            $table->json('metadata')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->string('response_status', 50)->nullable();
            foreach (['input_tokens', 'cached_input_tokens', 'output_tokens', 'reasoning_tokens', 'total_tokens'] as $column) {
                $table->unsignedBigInteger($column)->nullable();
            }
            $table->decimal('estimated_cost', 18, 8)->nullable();
            $table->json('pricing_snapshot')->nullable();
            $table->string('provider_request_id')->nullable()->index();
            $table->string('provider_response_id')->nullable()->index();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['operation_id', 'attempt_number']);
            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
            $table->index(['ai_model_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
