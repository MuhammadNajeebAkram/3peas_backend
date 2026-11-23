<?php

use App\Http\Services\Freemium\DatabaseCompatibilityChecker;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the database compatibility preflight assertions.
     *
     * Validates that the active database engine natively enforces CHECK constraints
     * and stored generated column indexes.
     *
     * Required Minimum Versions:
     * - MySQL >= 8.0.16
     * - MariaDB >= 10.2.1
     *
     * This migration performs ZERO schema or data mutations. If the active database
     * is unsupported, a RuntimeException is thrown immediately, aborting migrations fail-closed.
     *
     * @throws RuntimeException
     */
    public function up(): void
    {
        DatabaseCompatibilityChecker::assertSupportedDatabaseEngine();
    }

    /**
     * Reverse the migrations.
     *
     * As this migration performs zero schema mutations, rollback is a safe no-op.
     */
    public function down(): void
    {
        // No-op: Preflight check does not alter database schema or records.
    }
};
