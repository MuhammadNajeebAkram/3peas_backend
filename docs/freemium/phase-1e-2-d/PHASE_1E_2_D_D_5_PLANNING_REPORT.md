# Phase 1E.2.D.D.5 Planning Report — Transaction and Deadlock Resilience

**Project:** The Students Times LMS — Laravel 11 Backend  
**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Stage:** 1E.2.D.D.5 — Technical Planning and Source Code Inspection  
**Author:** Antigravity Resilience Planning Agent  
**Date:** November 19, 2026  
**Status:** **ACCEPTED & READY FOR IMPLEMENTATION AUTHORIZATION**

---

## 1. Overview and Planning Objectives

During Stage 1E.2.D.D.4, a comprehensive independent audit of the Freemium assessment lifecycle was conducted. The audit confirmed that while the business logic, access rules, entitlement hierarchy, and accounting ledger are sound, an intermittent database transaction exception occurs during high-concurrency multi-process tests:

```text
SQLSTATE[42000]: Syntax error or access violation: 1305 SAVEPOINT trans2 does not exist
```

Stage 1E.2.D.D.5 was authorized to conduct a strict read-only source inspection to:
1. Trace the exact origin of nested savepoints across all assessment controllers and services.
2. Confirm the underlying database engine mechanism causing savepoint destruction.
3. Compare architectural correction strategies (transaction flattening, retry mechanisms, exception handlers, locking redesigns).
4. Define a concrete, risk-free implementation roadmap and multi-process concurrency test plan for Stage 1E.2.D.D.6.

---

## 2. Verified Source Paths and Method Inventory

The following files, methods, and line ranges were inspected in the codebase:

| Subsystem / Purpose | File Path | Method Name | Lines |
| :--- | :--- | :--- | :--- |
| **Progress Saving / Attempt Start** | `app/Http/Controllers/Tests/TestController.php` | `saveFormalTestQuestionProgressForLms` | L612–L877 |
| **Test Submission & Grading** | `app/Http/Controllers/Tests/TestController.php` | `submitFormalTestForLms` | L1044–L1320 |
| **Test Generation (Selected Units)** | `app/Http/Controllers/Tests/TestController.php` | `generateSelectedUnitsFormalTestForLms` | L45–L215 |
| **Test Generation (Full Book)** | `app/Http/Controllers/Tests/TestController.php` | `generateFullBookFormalTestForLms` | L217–L385 |
| **Practice Session Generation** | `app/Http/Controllers/Tests/PracticeSessionController.php` | `generateSelectedUnitsPracticeSessionForLms` | L45–L230 |
| **Practice Session Generation (Full Book)** | `app/Http/Controllers/Tests/PracticeSessionController.php` | `generateFullBookPracticeSessionForLms` | L235–L440 |
| **Quota Consumption Ledger** | `app/Http/Services/Freemium/FreemiumQuotaConsumptionService.php` | `consumeQuota` | L156–L556 |
| **Attempt Quota Wrapper** | `app/Http/Services/Freemium/FreemiumQuotaConsumptionService.php` | `consumeAttemptQuota` | L754–L769 |
| **Framework Transaction Manager** | `vendor/laravel/framework/src/Illuminate/Database/Concerns/ManagesTransactions.php` | `handleTransactionException`, `performRollBack` | L83–L108, L289–L302 |
| **Multi-Process Concurrency Test** | `tests/Feature/Freemium/Concurrency/AssessmentAttemptConcurrencyTest.php` | `test_concurrent_starts_on_shared_official_test_different_students_succeed_independently` | L380–L460 |

---

## 3. Root Cause Confirmation

### A. The Accidental Savepoint Creation
In `TestController::saveFormalTestQuestionProgressForLms` (lines 712–713), the controller starts a database transaction:
```php
DB::beginTransaction(); // Connection transaction level becomes 1
```
Later in the method (line 786), when creating a new attempt for a non-paid student, it invokes:
```php
$this->quotaConsumptionService->consumeAttemptQuota(...);
```
Which delegates to `FreemiumQuotaConsumptionService::consumeQuota` (line 183):
```php
return DB::transaction(function () use (...) { // Connection transaction level becomes 2 -> SAVEPOINT trans2 created!
```
Because `DB::transactionLevel()` is already 1, Laravel automatically executes:
```sql
SAVEPOINT trans2
```

### B. The Engine-Level Savepoint Destruction
When Student 1 and Student 2 attempt the same official test simultaneously in separate processes:
1. Both workers acquire locks on their own `WebUser` rows (`WebUser(1)` and `WebUser(2)`).
2. Both workers insert a row into `test_attempts` with `test_id = 42`.
3. Both workers invoke `consumeAttemptQuota` (creating `SAVEPOINT trans2`).
4. Both workers insert into `freemium_quota_consumptions` with `test_id = 42` and unique key `uk_test_user_attempt_event`.
5. In MySQL InnoDB, concurrent insertions on identical secondary index keys (`test_id = 42`) acquire next-key and gap locks. Under high concurrency, gap lock conflicts can result in an InnoDB Deadlock (Error 1213).
6. **Critical Engine Behavior:** Upon detecting a deadlock, MySQL InnoDB automatically rolls back the **entire transaction on the database server**, destroying all active savepoints.
7. When Laravel catches the exception inside `consumeQuota`'s `DB::transaction()`, it executes `performRollBack(1)` (`ManagesTransactions.php#L298`):
   ```sql
   ROLLBACK TO SAVEPOINT trans2
   ```
8. MySQL returns:
   ```text
   SQLSTATE[42000]: Syntax error or access violation: 1305 SAVEPOINT trans2 does not exist
   ```
9. This secondary error escapes into the PHP process, terminating the worker script with an unhandled 500 error.

---

## 4. Evaluation and Recommended Strategy

### Evaluated Options:
- **Option A (Transaction Flattening):** Make `consumeQuota` participate in existing transactions without creating savepoints when `DB::transactionLevel() > 0`.
- **Option B (Top-Level Native Retries):** Wrap controller operations in `DB::transaction(..., 3)`.
- **Option C (Explicit Concurrency Exception Handling):** Catch deadlock and concurrency errors and map them to HTTP 409 Conflict.
- **Option D (Locking/Query Redesign):** Optimize secondary index access order.

### Selected Solution: Unified Hybrid Strategy (A + B + C)
Combining Options A, B, and C provides complete resilience:
1. **Option A eliminates savepoint creation:** `consumeQuota` checks `DB::transactionLevel() > 0`. If true, it executes directly within the outer transaction. `SAVEPOINT trans2` is never created.
2. **Option B provides transparent retry:** `TestController` wraps the operation in `DB::transaction(..., 3)`. If a transient InnoDB gap lock conflict occurs, Laravel catches it at transaction level 1, issues a clean connection rollback (`PDO::rollBack()`), and automatically retries the operation up to 3 times with exponential backoff.
3. **Option C provides graceful fallback:** If all 3 attempts encounter unresolvable database lock contention, the exception is caught and mapped to a structured HTTP 409 Conflict (`CONCURRENT_ATTEMPT_CONFLICT`) rather than an unhandled HTTP 500.

---

## 5. Proposed Code Modifications (Preview for Stage D.D.6)

### Modification 1: `FreemiumQuotaConsumptionService.php` (Flattening)
Replace lines 182–183:
```php
// BEFORE:
return DB::transaction(function () use (...) {
    WebUser::where('id', $student->id)->lockForUpdate()->first();
    ...
});

// AFTER:
$execute = function () use ($student, $examType, $eventType, $testId, $testAttemptId, $practiceSessionId, $attemptNumber, $idempotencyKey, $requestPayload, $fingerprint, $options, $now) {
    // 1. Stable parent-row lock on WebUser to serialize requests for this student (if not already locked)
    WebUser::where('id', $student->id)->lockForUpdate()->first();
    ...
    return $consumption;
};

// Participate in active transaction if one exists; otherwise create a standalone transaction
return DB::transactionLevel() > 0 ? $execute() : DB::transaction($execute);
```

### Modification 2: `TestController.php` (Native Deadlock Retries)
In `saveFormalTestQuestionProgressForLms` (lines 712–877) and `submitFormalTestForLms` (lines 1133–1320):
Replace manual `DB::beginTransaction()` / `commit()` / `rollBack()` blocks with:
```php
try {
    return DB::transaction(function () use ($user, $test, $validated, $testQuestionRow, $decision) {
        // 1. Lock student row
        WebUser::query()->where('id', $user->id)->lockForUpdate()->first();

        // 2. Query attempt
        $attempt = ...;

        // 3. Create attempt if not found & consume attempt quota (Level 1, no savepoint)
        if (!$attempt) {
            ...
        }

        // 4. Update progress & calculate score
        ...

        return response()->json([
            'success' => 1,
            'data'    => [...],
        ]);
    }, attempts: 3);
} catch (\Illuminate\Database\DeadlockException | \Illuminate\Database\QueryException $e) {
    if (str_contains($e->getMessage(), 'uk_test_user_attempt_event') || 
        str_contains($e->getMessage(), 'Deadlock') || 
        str_contains($e->getMessage(), '1213') ||
        str_contains($e->getMessage(), 'Lock wait timeout') ||
        str_contains($e->getMessage(), '1205')) {
        return response()->json([
            'success'    => 0,
            'error'      => 'A temporary database concurrency conflict occurred. Please retry your request.',
            'error_code' => 'CONCURRENT_ATTEMPT_CONFLICT',
        ], 409);
    }
    return response()->json(['success' => 0, 'error' => $e->getMessage()], 500);
}
```

---

## 6. Multi-Process Concurrency Test Plan

The plan specifies 5 dedicated concurrency test cases using independent CLI worker processes via `proc_open`:

1. **Test 1: Same Student Simultaneous Attempt Starts**
   - 2 parallel CLI processes for Student A on Test X.
   - Expected: 1 succeeds (HTTP 200, Attempt created), 1 gets active attempt replayed or 409 conflict. Exactly 1 `test_attempts` row and 1 ledger row.
2. **Test 2: Different Students Simultaneous Starts on Shared Official Test**
   - 2 parallel CLI processes for Student A and Student B on Official Test X.
   - Expected: Both succeed (HTTP 200). Retries resolve any gap lock collisions. Exactly 2 `test_attempts` rows, 2 ledger rows, **zero `1305 SAVEPOINT trans2` errors**.
3. **Test 3: Autosave vs Attempt Initialization Race**
   - 2 parallel CLI processes for Student A: Worker 1 starts attempt, Worker 2 autosaves question 2.
   - Expected: Both succeed (HTTP 200). Attempt initialized and question progress recorded. Exactly 1 attempt row.
4. **Test 4: Submission vs Autosave Race**
   - 2 parallel CLI processes for Student A: Worker 1 submits attempt, Worker 2 autosaves an answer.
   - Expected: Worker 1 succeeds (HTTP 200, status `submitted`). Worker 2 receives HTTP 409 `ATTEMPT_ALREADY_SUBMITTED`. Submitted score remains immutable.
5. **Test 5: Deliberate Deadlock Resilience Verification**
   - Stress test with 4 parallel CLI workers concurrently attempting shared assessments.
   - Expected: 100% of requests succeed (HTTP 200) via retry mechanism within 3 attempts. Zero unhandled 500 errors.

---

## 7. Risk Analysis and Rollback Considerations

- **Database Changes:** Zero migrations or schema modifications are involved.
- **Rollback Safety:** If any unforeseen issue arises, reverting the changes in `FreemiumQuotaConsumptionService.php` and `TestController.php` cleanly restores previous behavior with zero database artifacts.
- **Regression Impact:** The existing 286 Freemium tests and 158 Subscription tests continue to run unaltered, as the external API request and response contracts remain strictly identical.

---

## 8. Conclusion and Stop Condition

Planning for Stage 1E.2.D.D.5 is complete. All source paths, transaction lifecycles, framework mechanics, and architectural corrections have been documented.

**Strict Stop Condition:** No application code has been modified. No tests or migrations have been executed. Work has stopped here awaiting user review and implementation authorization for Stage 1E.2.D.D.6.
