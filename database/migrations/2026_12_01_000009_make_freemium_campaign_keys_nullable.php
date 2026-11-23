<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Makes `primary_campaign_id` and `primary_quota_id` in `freemium_quota_consumptions`
     * nullable so that legacy-free fallback quota consumption can be recorded in the master
     * ledger without generating artificial campaign or quota records.
     */
    public function up(): void
    {
        Schema::table('freemium_quota_consumptions', function (Blueprint $table) {
            $table->unsignedBigInteger('primary_campaign_id')->nullable()->change();
            $table->unsignedBigInteger('primary_quota_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Fail-closed rollback pre-check: Restoring NOT NULL will fail with SQL integrity errors
     * if any legacy consumption records exist with NULL campaign or quota foreign keys.
     */
    public function down(): void
    {
        $nullCount = DB::table('freemium_quota_consumptions')
            ->whereNull('primary_campaign_id')
            ->orWhereNull('primary_quota_id')
            ->count();

        if ($nullCount > 0) {
            throw new \RuntimeException(
                "Cannot roll back migration [2026_12_01_000009]: {$nullCount} consumption record(s) " .
                "with NULL campaign/quota references exist. Restoring NOT NULL constraints would violate database " .
                "integrity or delete historical student records. Rollback aborted to protect student consumption ledger."
            );
        }

        Schema::table('freemium_quota_consumptions', function (Blueprint $table) {
            $table->unsignedBigInteger('primary_campaign_id')->nullable(false)->change();
            $table->unsignedBigInteger('primary_quota_id')->nullable(false)->change();
        });
    }
};
