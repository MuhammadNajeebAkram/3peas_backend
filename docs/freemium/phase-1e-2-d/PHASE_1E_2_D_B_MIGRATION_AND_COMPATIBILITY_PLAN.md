# Stage 1E.2.D.B — Migration and Data Compatibility Plan

**Document ID:** `PHASE_1E_2_D_B_MIGRATION_AND_COMPATIBILITY_PLAN`  
**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Stage:** 1E.2.D.B — Shared Official-Test Uniqueness, Attempt & Retake Accounting (Planning Mode)  
**Timestamp:** 2026-11-19  

---

## 1. Migration Specification

### Proposed Migration File
- **Filename:** `database/migrations/2026_12_01_000011_update_freemium_test_attempt_unique_index.php`
- **Sequencing:** Follows `2026_12_01_000010_create_assessment_idempotency_records_table.php` (Batch 141).
- **Target Table:** `freemium_quota_consumptions`

### Up Migration Logic (`up`)
```php
public function up(): void
{
    Schema::table('freemium_quota_consumptions', function (Blueprint $table) {
        // 1. Drop old 3-column index that omitted user_id
        $table->dropUnique('uk_test_attempt_event');

        // 2. Add student-scoped 4-column compound unique index
        $table->unique(
            ['test_id', 'user_id', 'attempt_number', 'event_type'],
            'uk_test_user_attempt_event'
        );
    });
}
```

### Safe Fail-Closed Down Migration Logic (`down`)
```php
public function down(): void
{
    // 1. Strict pre-check: Count how many (test_id, attempt_number, event_type) tuples have multiple distinct students
    $duplicateGroups = DB::table('freemium_quota_consumptions')
        ->whereNotNull('test_id')
        ->select('test_id', 'attempt_number', 'event_type', DB::raw('COUNT(DISTINCT user_id) as student_count'))
        ->groupBy('test_id', 'attempt_number', 'event_type')
        ->having('student_count', '>', 1)
        ->get();

    $conflictCount = $duplicateGroups->count();

    // 2. Fail-closed: If ANY collision exists, ABORT rollback immediately
    if ($conflictCount > 0) {
        $firstConflict = $duplicateGroups->first();
        throw new \RuntimeException(
            "Cannot rollback migration [2026_12_01_000011_update_freemium_test_attempt_unique_index]: " .
            "Found {$conflictCount} test attempt tuple(s) with multiple student records. " .
            "Example: Test ID {$firstConflict->test_id}, Attempt {$firstConflict->attempt_number}, Event '{$firstConflict->event_type}' " .
            "has {$firstConflict->student_count} distinct students. " .
            "Restoring the 3-column unique index (test_id, attempt_number, event_type) would fail with MySQL Error 1062. " .
            "Rollback aborted. Operational data is preserved; records must NEVER be deleted or modified to force rollback."
        );
    }

    // 3. Safe to revert ONLY when zero multi-student tuples exist
    Schema::table('freemium_quota_consumptions', function (Blueprint $table) {
        $table->dropUnique('uk_test_user_attempt_event');
        $table->unique(['test_id', 'attempt_number', 'event_type'], 'uk_test_attempt_event');
    });
}
```

---

## 2. Strict Zero-Deletion & Zero-Rewrite Rollback Policy

1. **Absolute Data Immutability:**
   - Under NO circumstance may a migration rollback script execute `DELETE`, `TRUNCATE`, or `UPDATE` on `freemium_quota_consumptions` to bypass unique constraint collisions.
   - The ledger is an append-only audit trail. Deleting records to satisfy a historical 3-column constraint would destroy student attempt history and violate academic integrity.
2. **Fail-Closed Principle:**
   - If even a single shared official test has been attempted by two or more students, the pre-check detects `student_count > 1` and throws `\RuntimeException`.
   - The rollback terminates before dropping `uk_test_user_attempt_event`.
   - The database remains in its valid, healthy, multi-student state.
3. **Safe Rollback Window:**
   - Rollback is permitted **only** in fresh development or staging environments where zero multi-student attempts have been recorded (`conflictCount === 0`).

---

## 3. Data Compatibility & Backfill Verification

1. **Current Table Row Counts:**
   - Development DB (`3peas_db`): **0 rows** in `freemium_quota_consumptions`.
   - Test DB (`3peas_db_subscription_test`): **0 rows** in `freemium_quota_consumptions`.
2. **Backfill Requirement:**
   - Because row count is 0, zero records require data translation or user_id re-assignment.
3. **Safety Guarantee:**
   - Historical migrations `000006`, `000009`, and `000010` remain 100% untouched.
