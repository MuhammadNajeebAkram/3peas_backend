# Stage 1E.2.D.B — Shared-Test Schema and Uniqueness Analysis

**Document ID:** `PHASE_1E_2_D_B_SHARED_TEST_SCHEMA_AND_UNIQUENESS`  
**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Stage:** 1E.2.D.B — Shared Official-Test Uniqueness, Attempt & Retake Accounting (Planning Mode)  
**Timestamp:** 2026-11-19  

---

## 1. The Shared Official-Test Problem

In LMS platforms, tests fall into two categories:
1. **Dynamic Student Tests:** Generated on-the-fly for a specific student (`test_source = 'system'`, `created_by = $student->id`).
2. **Official Shared Assessments:** Curated and published once by administrators or teachers for an entire cohort (`test_source = 'official'`, `is_published = 1`).

When thousands of students attempt the same official test (e.g. Test #100), each student undertakes their own independent attempt progression:
- Student A starts their initial attempt -> `attempt_number = 1`, `event_type = 'attempt_start'`.
- Student B starts their initial attempt -> `attempt_number = 1`, `event_type = 'attempt_start'`.

### The Existing Unique Index Defect
Migration `2026_12_01_000006_create_freemium_quota_consumptions_table.php` (line 42) created:
```php
$table->unique(['test_id', 'attempt_number', 'event_type'], 'uk_test_attempt_event');
```
Because `user_id` was omitted from this compound index:
- Student A successfully inserts `(test_id = 100, attempt_number = 1, event_type = 'attempt_start')`.
- Student B attempts to insert `(test_id = 100, attempt_number = 1, event_type = 'attempt_start')`.
- **MySQL InnoDB crashes on Student B with Error 1062:**
  `Duplicate entry '100-1-attempt_start' for key 'uk_test_attempt_event'`.
- Result: **Only one student in the entire system can ever attempt a shared official test.**

---

## 2. Approved Schema Remedy

Replace the 3-column index with a 4-column compound unique index:
```sql
ALTER TABLE freemium_quota_consumptions DROP INDEX uk_test_attempt_event;
ALTER TABLE freemium_quota_consumptions ADD UNIQUE INDEX uk_test_user_attempt_event (test_id, user_id, attempt_number, event_type);
```

### Analysis of New 4-Column Index: `(test_id, user_id, attempt_number, event_type)`
1. **Multi-Student Concurrency:**
   - Student A (ID: 10): `(test_id = 100, user_id = 10, attempt_number = 1, event_type = 'attempt_start')` -> Allowed.
   - Student B (ID: 20): `(test_id = 100, user_id = 20, attempt_number = 1, event_type = 'attempt_start')` -> Allowed.
   - Both students proceed simultaneously without storage engine collision.
2. **Per-Student Retake Uniqueness:**
   - Student A can take Attempt #2: `(test_id = 100, user_id = 10, attempt_number = 2, event_type = 'retake_start')` -> Allowed.
   - Student A cannot duplicate Attempt #1: `(test_id = 100, user_id = 10, attempt_number = 1, event_type = 'attempt_start')` -> InnoDB blocks duplicate insertion.
3. **Availability of `user_id`:**
   Inspection of `freemium_quota_consumptions` confirms `user_id` is defined as `foreignId('user_id')->constrained('web_users')->restrictOnDelete()` and is **strictly `NOT NULL`** across all rows. `user_id` is guaranteed to be populated on every consumption record.
4. **Data Integrity for Existing Records:**
   Inspection confirms that current row count in `freemium_quota_consumptions` on both `3peas_db` and `3peas_db_subscription_test` is currently **0**. Zero migration data backfill or collation repair is required.
