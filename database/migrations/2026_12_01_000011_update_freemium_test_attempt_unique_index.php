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
     * Updates unique constraint on freemium_quota_consumptions from (test_id, attempt_number, event_type)
     * to (test_id, user_id, attempt_number, event_type).
     *
     * This permits multiple students to attempt the same shared official test independently without collision.
     */
    public function up(): void
    {
        Schema::table('freemium_quota_consumptions', function (Blueprint $table) {
            // 1. Add student-scoped composite unique index first so MySQL foreign key covering test_id is continuously satisfied
            $table->unique(
                ['test_id', 'user_id', 'attempt_number', 'event_type'],
                'uk_test_user_attempt_event'
            );

            // 2. Drop the old 3-column index that omitted user_id
            $table->dropUnique('uk_test_attempt_event');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Fail-closed rollback:
     * Verifies whether multi-student attempt records exist for the same test, attempt_number, and event_type.
     * If conflicting records exist, rollback terminates with a RuntimeException, leaving all records untouched.
     * Under no circumstances are consumption records deleted or rewritten to force rollback.
     */
    public function down(): void
    {
        $duplicateGroups = DB::table('freemium_quota_consumptions')
            ->whereNotNull('test_id')
            ->select('test_id', 'attempt_number', 'event_type', DB::raw('COUNT(DISTINCT user_id) as student_count'))
            ->groupBy('test_id', 'attempt_number', 'event_type')
            ->having('student_count', '>', 1)
            ->get();

        $conflictCount = $duplicateGroups->count();

        if ($conflictCount > 0) {
            $sample = $duplicateGroups->first();
            throw new \RuntimeException(
                "Cannot rollback migration [2026_12_01_000011_update_freemium_test_attempt_unique_index]: " .
                "Found {$conflictCount} test attempt tuple(s) with multiple student records. " .
                "Example: Test ID {$sample->test_id}, Attempt {$sample->attempt_number}, Event '{$sample->event_type}' " .
                "has {$sample->student_count} distinct students. " .
                "Restoring the 3-column unique index (test_id, attempt_number, event_type) would cause duplicate key collisions (MySQL Error 1062). " .
                "Rollback aborted. All records have been left completely untouched."
            );
        }

        Schema::table('freemium_quota_consumptions', function (Blueprint $table) {
            // 1. Restore the original 3-column index first so test_id foreign key index coverage is maintained
            $table->unique(
                ['test_id', 'attempt_number', 'event_type'],
                'uk_test_attempt_event'
            );

            // 2. Drop the student-scoped index
            $table->dropUnique('uk_test_user_attempt_event');
        });
    }
};
