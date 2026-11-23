<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates independent assessment idempotency records table for both paid and freemium
     * assessment generations, preventing duplicate test generation and race conditions.
     */
    public function up(): void
    {
        Schema::create('assessment_idempotency_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('web_users')->restrictOnDelete()->comment('References web_users(id)');
            $table->string('operation', 50)->comment('e.g. formal_test_generation, practice_session_generation');
            $table->string('idempotency_key', 100);
            $table->string('request_fingerprint', 64);
            $table->json('response_payload')->nullable();
            $table->unsignedSmallInteger('http_status')->default(200);
            $table->enum('status', ['processing', 'completed', 'failed'])->default('processing');
            $table->timestamps();

            // Unique constraint: student cannot duplicate idempotency keys within an operation
            $table->unique(['user_id', 'operation', 'idempotency_key'], 'uk_user_op_idempotency');

            // Fast lookup index
            $table->index(['user_id', 'operation', 'created_at'], 'idx_user_op_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $count = DB::table('assessment_idempotency_records')->count();
        if ($count > 0) {
            Log::warning(
                "Rolling back [2026_12_01_000010_create_assessment_idempotency_records_table]: " .
                "Dropping table with {$count} existing idempotency record(s)."
            );
        }

        Schema::dropIfExists('assessment_idempotency_records');
    }
};
