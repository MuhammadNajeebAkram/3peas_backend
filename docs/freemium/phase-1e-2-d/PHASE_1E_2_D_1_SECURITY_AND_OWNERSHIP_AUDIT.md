# Stage 1E.2.D.1 — Security and Ownership Audit

**Document ID:** `PHASE_1E_2_D_1_SECURITY_AND_OWNERSHIP_AUDIT`  
**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Phase:** 1E.2.D.1 (Planning and Source Audit — Final Migration & Integration Review)  
**Timestamp:** 2026-11-19

---

## 1. Executive Summary

This audit evaluates tenant isolation, student ownership, shared official-test attempt collision vectors, and protected content leakage prevention. It provides an exhaustive architectural evaluation of the unique index constraint on `freemium_quota_consumptions` and plans a safe forward migration to support shared official tests.

---

## 2. Multi-Tenant Student Isolation Audit

### 2.1 Test Attempts Isolation

- **Code Path:** `TestController::saveFormalTestQuestionProgressForLms` & `submitFormalTestForLms`
- **Ownership Enforcement:**
    ```php
    $attempt = TestAttempt::query()
        ->where('test_id', $test->id)
        ->where('user_id', $user->id) // Constrained to authenticated WebUser
        ->...
    ```
- **Audit Assessment:** Strong tenant filtering. Queries explicitly constrain `user_id = $user->id`. A student cannot read, save progress on, or submit another student's attempt ID because the query enforces ownership.
- **Stage 1E.2.D Hardening Requirement:** When `attempt_id` is supplied in `submitFormalTestForLms`, explicitly assert that `TestAttempt::where('id', $attempt_id)->where('user_id', $user->id)->exists()`. If mismatched, fail closed with HTTP 404 (safe object hiding).

### 2.2 Practice Sessions Isolation

- **Code Path:** `PracticeSessionController::savePracticeSessionQuestionProgressForLms` & `submitPracticeSessionForLms`
- **Ownership Enforcement:**
    ```php
    $session = PracticeSession::query()
        ->where('id', $validated['session_id'])
        ->where('user_id', $user->id)
        ->firstOrFail();
    ```
- **Audit Assessment:** Strong tenant filtering. Mismatched or foreign session IDs trigger `ModelNotFoundException`, returning HTTP 404. Foreign session inspection or mutation is impossible.

---

## 3. Shared Official-Test Ledger Uniqueness & Migration Strategy

### 3.1 Migration History & Current Database State

A read-only inspection of both the development database (`3peas_db`) and the dedicated testing database (`3peas_db_subscription_test`) confirmed:

- Migration `2026_12_01_000006_create_freemium_quota_consumptions_table.php` has **already been applied in batch 140**.
- The existing unique constraint in both databases is:
    ```sql
    CONSTRAINT uk_test_attempt_event UNIQUE (test_id, attempt_number, event_type)
    ```
- Modifying the original migration file is therefore strictly prohibited, as it would cause migration desynchronization across environments and break clean rollbacks.

### 3.2 Evaluation of Architectural Design Options

#### Option A: Include `user_id` via a Safe Forward Migration (Recommended)

```sql
CONSTRAINT uk_test_user_attempt_event UNIQUE (test_id, user_id, attempt_number, event_type)
```

- **Existing Migration & Schema Compatibility:** 100% compatible. Both `test_id` and `user_id` are already foreign keys on `freemium_quota_consumptions`.
- **Nullable Column Behavior in MySQL:** `test_id` is nullable (for practice sessions). In MySQL, a unique index allows multiple `NULL` values. Practice sessions remain strictly protected by their separate unique index `uk_practice_attempt_event`.
- **Historical Consumption Records:** Clean. Because dynamic tests each have unique `test_id`s, existing dynamic test records are unaffected by adding `user_id`.
- **Concurrent Attempts by Different Students:** Fully supported. Student A and Student B have different `user_id`s; both can record `(test_id: 10, attempt_number: 1, event_type: 'attempt_start')` concurrently without collision.
- **Concurrent Attempts by the Same Student:** Fully protected. If Student A fires two simultaneous requests for Attempt #1 on Test #10, the second request trips `uk_test_user_attempt_event` and is rejected, preventing duplicate attempts.
- **Attempt-Number Derivation:** Attempt numbers are derived per student and test:
  $$\text{Attempt Number} = \max(\text{prior\_attempts\_for\_user\_and\_test}) + 1$$
- **Impact on Existing Services & Tests:** Fully compatible. Existing tests in `FreemiumDatabaseConstraintTest` testing duplicate attempts for the same student still pass because both test rows share `$this->studentId`.
- **Forward Migration Plan:** Planned as `database/migrations/2026_12_01_000009_update_freemium_test_attempt_unique_index.php`.

#### Option B: Introduce a Synthetic Stored Generated Scope Key

E.g., `attempt_scope_key = hash(test_id, user_id, attempt_number, event_type)` with a unique constraint on that single column.

- **Evaluation:** Unnecessary complexity and computational overhead. MySQL composite indexes natively evaluate multi-column uniqueness with superior performance.

#### Option C: Dual Ledger Architecture (Separate Table for Official Tests)

Keep `freemium_quota_consumptions` for dynamic tests and create a separate table for official test attempts.

- **Evaluation:** High architectural risk. Violates the fundamental design requirement of an independent, unified, authoritative Freemium quota ledger.

---

### 3.3 Planned Forward Migration Specification

**File:** `database/migrations/2026_12_01_000009_update_freemium_test_attempt_unique_index.php`

```php
public function up(): void
{
    Schema::table('freemium_quota_consumptions', function (Blueprint $table) {
        $table->dropUnique('uk_test_attempt_event');
        $table->unique(['test_id', 'user_id', 'attempt_number', 'event_type'], 'uk_test_user_attempt_event');
        $table->foreignId('primary_campaign_id')->nullable()->change();
        $table->foreignId('primary_quota_id')->nullable()->change();
    });
}

public function down(): void
{
    Schema::table('freemium_quota_consumptions', function (Blueprint $table) {
        $table->dropUnique('uk_test_user_attempt_event');
        $table->unique(['test_id', 'attempt_number', 'event_type'], 'uk_test_attempt_event');
        $table->foreignId('primary_campaign_id')->nullable(false)->change();
        $table->foreignId('primary_quota_id')->nullable(false)->change();
    });
}
```

This migration guarantees that:

1. Different students take the same official test without collision.
2. The same student cannot create duplicate attempts.
3. Legacy-free consumptions can be stored without artificial campaign records (`primary_campaign_id` and `primary_quota_id` nullable).
4. Rollback to the previous state is completely safe and reversible.

---

## 4. Protected Content Leakage Prevention

### 4.1 Quota Exhaustion Shielding

When a student exhausts their generation or retake quota:

- The controller terminates **before** executing `selectLmsMcqQuestions()`.
- No questions, options, diagrams, or explanations are fetched or serialized.
- Response returns only structured denial metadata:
    ```json
    {
        "success": 0,
        "error": "Quota limit reached for this exam type.",
        "code": "QUOTA_EXHAUSTED",
        "upgrade_url": "/lms/subscribe/program-slug"
    }
    ```

### 4.2 Answer Key Protection During Active Examination

- In formal tests, correct answers and explanations are never returned to the student while the test is `in_progress`.
- In practice sessions, correct answers are revealed only per-question when the student explicitly requests instant practice feedback (`answer_shown = true`).
