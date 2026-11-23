<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('freemium_quota_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('web_users')->restrictOnDelete()->comment('References web_users(id)');

            $table->enum('exam_type', ['practice', 'formal_test', 'mock']);
            $table->enum('event_type', ['generation', 'attempt_start', 'retake_start', 'practice_generation', 'practice_completion']);

            $table->foreignId('primary_campaign_id')->constrained('freemium_campaigns')->restrictOnDelete();
            $table->foreignId('primary_quota_id')->constrained('freemium_exam_quotas')->restrictOnDelete();
            $table->foreignId('primary_grant_id')->nullable()->constrained('freemium_student_grants')->restrictOnDelete()->comment('References freemium_student_grants(id) when grant-funded');

            // Dual Physical Foreign Keys with Real Database-Enforced Referential Integrity
            $table->foreignId('test_id')->nullable()->constrained('tests')->restrictOnDelete()->comment('References tests(id) for formal/mock');
            $table->foreignId('test_attempt_id')->nullable()->constrained('test_attempts')->restrictOnDelete()->comment('References test_attempts(id) for start/retake');
            $table->foreignId('practice_session_id')->nullable()->constrained('practice_sessions')->restrictOnDelete()->comment('References practice_sessions(id)');

            $table->unsignedInteger('attempt_number')->default(0)->comment('0 for generation; 1..N for attempts');
            $table->string('idempotency_key', 100)->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->json('decision_snapshot');
            $table->timestamp('consumed_at')->useCurrent();

            // Stored Generated Columns for Polymorphic Mapping
            $table->string('entity_type', 20)->storedAs("IF(test_id IS NOT NULL, 'test', 'practice_session')");
            $table->unsignedBigInteger('entity_id')->storedAs("COALESCE(test_id, practice_session_id)");

            // Specialized Unique Attempt & Idempotency Constraints
            $table->unique(['test_id', 'attempt_number', 'event_type'], 'uk_test_attempt_event');
            $table->unique(['practice_session_id', 'attempt_number', 'event_type'], 'uk_practice_attempt_event');
            $table->unique(['user_id', 'idempotency_key'], 'uk_user_idempotency');

            // Specialized Lookup Indexes
            $table->index(['user_id', 'exam_type', 'consumed_at'], 'idx_user_exam_consumed');
            $table->index(['entity_type', 'entity_id'], 'idx_entity_lookup');
        });

        // Enforce mutual exclusivity of test_id vs practice_session_id at the database engine level
        DB::statement("ALTER TABLE freemium_quota_consumptions ADD CONSTRAINT chk_exam_entity_mutex CHECK (
            (test_id IS NOT NULL AND practice_session_id IS NULL) OR
            (test_id IS NULL AND practice_session_id IS NOT NULL)
        )");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('freemium_quota_consumptions');
    }
};
