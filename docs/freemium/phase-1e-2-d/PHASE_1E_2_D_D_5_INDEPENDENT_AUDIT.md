# Independent Read-Only Audit Report — Stage 1E.2.D.D.5: Transaction and Deadlock Resilience

**Audit Reference:** `docs/freemium/phase-1e-2-d/PHASE_1E_2_D_D_5_INDEPENDENT_AUDIT.md`  
**Audit Execution Timestamp:** 2026-11-19T21:24:00+05:00 (UTC 16:24:00)  
**Target Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Target Branch:** `main`  
**Auditor:** Antigravity DeepMind Agent (Independent Read-Only Inspection Role)  
**Verdict:** **ACCEPT** (Unconditional)

---

## Executive Summary

Stage 1E.2.D.D.5 was initiated to address the concurrency deadlock and secondary savepoint failure identified during Stage 1E.2.D.D.4 (`SQLSTATE[42000]: Syntax error or access violation: 1305 SAVEPOINT trans2 does not exist`).

This independent audit conducted a thorough, line-by-line inspection of the implementation across transaction boundaries, retry semantics, error mapping, and multi-process concurrency testing. All authorized changes were verified against source code, live database semantics (MySQL 8.0.43 InnoDB), and fresh test executions.

The verdict of this independent audit is **ACCEPT**. All five audit areas are rated **PASS**.

---

## Audit Restrictions Compliance

| Restriction | Compliance Status | Evidence / Verification |
| :--- | :---: | :--- |
| Read-only inspection only | **PASS** | No application source code, migrations, configs, routes, or existing tests were modified during this audit. |
| No database writes or migrations | **PASS** | No migrations, seeders, or database mutations were executed. |
| Preserve all uncommitted work | **PASS** | Verified via `git status -s`; working tree changes across all prior stages remain intact. |
| Do not begin Stage D.D.6 | **PASS** | Work strictly halts upon completion of this audit deliverable. |

---

## 1. Transaction Flattening

**Target Files & Lines:**  
- `app/Http/Services/Freemium/FreemiumQuotaConsumptionService.php:182-198, 576-580`  
- Callers: `TestController.php:184, 375, 790, 1271`, `PracticeSessionController.php:201, 414`

### Findings:
1. **Behavior When No Transaction Exists:**
   - In `FreemiumQuotaConsumptionService.php:579`:
     ```php
     return DB::transactionLevel() > 0 ? $execute() : DB::transaction($execute);
     ```
   - When called from a context with no active transaction (`DB::transactionLevel() === 0`), `consumeQuota()` delegates to `DB::transaction($execute)`. This opens a root Level 1 transaction, executes parent-row locking, performs idempotency checks, records quota counter increments and envelope records, and commits cleanly.
2. **Behavior Inside an Existing Transaction:**
   - When called within an outer transaction (`DB::transactionLevel() >= 1`, such as from `TestController::saveFormalTestQuestionProgressForLms` or `submitFormalTestForLms`), the condition evaluates directly to `$execute()`.
   - **Zero savepoints are created:** Laravel does not invoke `beginTransaction()`, preventing the creation of `SAVEPOINT trans2`.
   - If an error or deadlock occurs, MySQL does not fail on savepoint rollback because no savepoints exist.
3. **Atomicity of Quota Counter and Consumption Writes:**
   - Parent-row lock (`WebUser::lockForUpdate()`), counter row creation/lock (`FreemiumQuotaCounter::lockForUpdate()`), counter increment, ledger creation (`FreemiumQuotaConsumption::create`), and envelope creation (`FreemiumQuotaConsumptionEnvelope::create`) execute within the unified transaction boundary.
   - Either all changes commit together on success, or all changes roll back together on exception. Partial ledger writes are impossible.
4. **Exception Propagation and Rollback Ownership:**
   - Rollback ownership is singularly assigned to the outer transaction owner (`DB::transaction(..., attempts: 3)` in `TestController`).
   - Inner exceptions bubble cleanly to the outer transaction handler, which executes Level 0 rollback and handles retry or error response formatting.
5. **Caller Dependency Analysis:**
   - Inspected all 7 invocation sites (`TestController` and `PracticeSessionController`). None depended on partial rollback to an inner savepoint. All callers intended total transaction rollback upon quota consumption failure.
6. **No Partial Commits or Left-Open Transactions:**
   - Verified that `$execute()` never calls `DB::commit()` or `DB::rollBack()`. Transaction level remains identical before and after invocation.

**Area 1 Verdict:** **PASS**

---

## 2. Laravel Transaction Retries

**Target Files & Lines:**  
- `app/Http/Controllers/Tests/TestController.php:713-850` (`saveFormalTestQuestionProgressForLms`)  
- `app/Http/Controllers/Tests/TestController.php:1160-1453` (`submitFormalTestForLms`)

### Findings:
1. **Complete Database Operation Inside Retryable Closure:**
   - In `saveFormalTestQuestionProgressForLms`: Lines 714–850 wrap all DB reads, pessimistic locks, attempt creation, quota deduction, question saving (`TestAttemptQuestion::updateOrCreate`), and score progress updates in `DB::transaction(callback, attempts: 3)`.
   - In `submitFormalTestForLms`: Lines 1160–1453 wrap all DB reads, pessimistic locks, entitlement evaluation, attempt creation/update, answer updates, question statistics, student activity logging, and summary recalculation in `DB::transaction(callback, attempts: 3)`.
2. **Parent `WebUser` Lock Remains First Relevant Lock:**
   - In `saveFormalTestQuestionProgressForLms`: Line 722 executes `WebUser::query()->where('id', $user->id)->lockForUpdate()->first();` as the first statement inside the transaction.
   - In `submitFormalTestForLms`: Line 1175 executes `WebUser::query()->where('id', $user->id)->lockForUpdate()->first();` as the first statement inside the transaction.
3. **Hierarchical Lock Ordering:**
   - Both methods strictly adhere to the lock hierarchy:
     1. `WebUser` (parent row for authenticated student)
     2. `TestAttempt` (student attempt row)
     3. `FreemiumQuotaCounter` (campaign quota counter row)
     4. `FreemiumQuotaConsumption` (ledger insert)
4. **Closure Multi-Execution Safety (Idempotence on Retry):**
   - In `submitFormalTestForLms`: Lines 1169–1172 reset `$attemptedQuestions = 0; $correctAnswers = 0; $wrongAnswers = 0; $score = 0.0;` at the beginning of each attempt, ensuring retry closures start with clean state.
   - If an initial attempt was rolled back by MySQL on deadlock, the retry re-evaluates database state and re-queries `TestAttempt`.
5. **No External Irreversible Side Effects:**
   - No emails, queues, webhooks, or file writes are dispatched inside the closures. All mutations are strictly database transactions managed by InnoDB.
6. **Statistics, Activity Records, and Summary Rollups:**
   - Question statistics (`updateQuestionStatistic`), option statistics (`updateQuestionOptionStatistic`), student activity (`recordStudentActivity`), and unit/subject progress summaries (`refreshStudentUnitProgressSummary`) are rolled back automatically if an engine deadlock occurs during an attempt, preventing ghost or double-counted metrics.
7. **Responses Returned Only After Successful Transaction:**
   - Both controller methods use `return DB::transaction(...)`. In Laravel, `DB::transaction()` commits the connection before returning the closure's `JsonResponse`.

**Area 2 Verdict:** **PASS**

---

## 3. Exception Handling

**Target Files & Lines:**  
- `app/Http/Controllers/Tests/TestController.php:851-898, 1454-1512, 1929-1957`

### Findings:
1. **MySQL 1213 and 1205 Identification:**
   - `TestController::isDeadlockOrLockTimeout(\Throwable $e): bool` (lines 1929–1957) checks:
     - Error 1213 (Deadlock found)
     - Error 1205 (Lock wait timeout exceeded)
     - SQLSTATE 40001 (Serialization failure)
   - Inspects both `$e->getMessage()` and `$e->getPrevious()?->getMessage()`.
2. **Secondary 1305 Savepoint Error Distinction:**
   - Secondary savepoint errors (`1305 SAVEPOINT ... does not exist`) are **not** present in `isDeadlockOrLockTimeout()`.
   - Any secondary 1305 error falls through to the generic `QueryException` handler, which logs complete diagnostic details and returns HTTP 500 without leaking raw SQL.
3. **Retry Exhaustion Produces Safe HTTP 409:**
   - In both methods, if retry exhaustion occurs after 3 attempts, `isDeadlockOrLockTimeout($e)` returns:
     ```json
     {
       "success": 0,
       "error": "A temporary database concurrency conflict occurred. Please retry your request.",
       "error_code": "CONCURRENT_ATTEMPT_CONFLICT"
     }
     ```
   - Matches the approved security specification and does not leak internal deadlock graphs to students.
4. **SQL Error Sanitization:**
   - Unhandled query exceptions return:
     - `'error' => 'A database error occurred while processing test progress.'` (autosave)
     - `'error' => 'A database error occurred while submitting the test.'` (submission)
   - Zero internal database schema names, column names, or raw SQL queries are leaked in the JSON response.
5. **Diagnostic Logging Preservation:**
   - Lines 868–874 and 1471–1477 log `test_id`, `student_id`, `attempt_id`, error message, and full stack trace to `storage/logs/laravel.log`.

**Area 3 Verdict:** **PASS**

---

## 4. Concurrency Verification

**Target File:** `tests/Feature/Freemium/Concurrency/AssessmentAttemptConcurrencyTest.php`

### Findings:
1. **Genuine Independent PHP CLI Processes:**
   - Scenarios 1, 2, 3, and 4 generate standalone PHP worker scripts executed concurrently via `proc_open()`.
   - Each worker initializes an independent PHP runtime, independent Laravel container, and separate PDO database connection.
2. **InnoDB Final State Assertions:**
   - All tests inspect actual MySQL InnoDB tables (`test_attempts`, `freemium_quota_consumptions`, `freemium_quota_counters`, `test_attempt_questions`).
3. **Same-Student Uniqueness (Scenario 1):**
   - Two concurrent CLI requests for the same student create exactly 1 active attempt; the second process receives HTTP 409.
4. **Shared Official Test Cross-Student Independence (Scenario 3):**
   - Two concurrent CLI requests for different students accessing the **same shared official test** (`test_id = 42`) both succeed with HTTP 200, creating two distinct attempt records without triggering deadlocks or 1305 savepoint errors.
5. **Autosave vs. Submission Race (Scenario 4):**
   - Submission completes with HTTP 200, late autosave receives HTTP 409 `ATTEMPT_ALREADY_SUBMITTED`, and the final score remains uncorrupted.
6. **Deterministic Deadlock Retry Execution (Scenario 5):**
   - `TestAttempt::creating` injects a simulated MySQL deadlock (Error 1213 / SQLSTATE 40001) on the first execution attempt.
   - Verified that Laravel catches the deadlock at Level 1, decrements transactions, rolls back, and invokes the closure a 2nd time, completing with HTTP 200 and `$attemptsCount === 2`.
7. **Deadlock Retry Exhaustion (Scenario 6):**
   - Injects deadlock on all attempts; verified that the closure is retried exactly 3 times before returning HTTP 409 `CONCURRENT_ATTEMPT_CONFLICT`.
8. **Lock Wait Timeout (Scenario 7):**
   - Injects Error 1205; verified clean mapping to HTTP 409 `CONCURRENT_ATTEMPT_CONFLICT`.
9. **SQL Error Sanitization (Scenario 8):**
   - Injects missing table Error 1146; verified generic HTTP 500 response without leaking table names or SQL text.
10. **Transaction Flattening Verification (Scenario 9):**
    - Verifies that `DB::transactionLevel()` remains at 1 throughout `consumeQuota()` when called inside an existing transaction.
11. **Parent-Row Locking Verification (Scenario 10):**
    - Verifies pessimistic lock acquisition on `WebUser`.
12. **Practice vs. Formal Test Isolation (Scenario 11):**
    - Verifies that formal test quotas and practice session quotas operate independently without cross-boundary interference.
13. **Idempotent Submission Replay (Scenario 12):**
    - Replaying a submission with identical `attempt_id` returns HTTP 200 with matching score, accuracy, status, and database timestamp.

**Area 4 Verdict:** **PASS**

---

## 5. Regression and Scope Verification

### Fresh Audit Test Execution Results (Executed 2026-11-19 21:19–21:23 PKT):

| Test Suite | Command | Result | Tests | Assertions | Duration |
| :--- | :--- | :---: | :---: | :---: | :---: |
| 1. Dedicated Concurrency Suite | `php artisan test tests/Feature/Freemium/Concurrency/AssessmentAttemptConcurrencyTest.php` | **PASS** | 12 | 58 | 4.99s |
| 2. Student Formal Attempt Accounting | `php artisan test tests/Feature/Freemium/Student/StudentFormalTestAttemptAccountingTest.php` | **PASS** | 13 | 75 | 4.22s |
| 3. Student Eligibility & History Consistency | `php artisan test tests/Feature/Freemium/Student/StudentEligibilityAndHistoryConsistencyTest.php` | **PASS** | 15 | 96 | 3.47s |
| 4. Full Freemium Regression Suite | `php artisan test --filter=Freemium` | **PASS** | 294 | 1,397 | 55.17s |
| 5. Subscription Regression Suite | `php artisan test --filter=Subscription` | **PASS** | 158 | 1,142 | 63.65s |

### Grand Total of Verified Tests:
- **Total Tests Passed:** 452
- **Total Assertions:** 2,539
- **Failures / Errors:** 0
- **Regressions:** 0

### Working Tree and File Scope Audit:
- `PracticeSessionController.php`: Inspected `LastWriteTime` (11/19/2026 4:06:21 PM). **Unmodified during Stage D.D.5.**
- Database Migrations: Zero migrations added, executed, or altered.
- Database Schema: Unchanged.
- Working Tree: All prior stage modifications preserved intact.

**Area 5 Verdict:** **PASS**

---

## 6. Audit Verdict and Recommendation

### Final Recommendation: **ACCEPT**

Stage 1E.2.D.D.5 has resolved the deadlock and `SAVEPOINT trans2` vulnerability with complete architectural soundness:
1. Transaction flattening in `FreemiumQuotaConsumptionService::consumeQuota()` eliminates nested savepoints.
2. Native Laravel retries (`attempts: 3`) in `TestController` provide transparent, automatic recovery from transient InnoDB deadlocks.
3. All 12 required concurrency, deadlock, timeout, and resilience scenarios are verified by automated tests.
4. Full regression suites across Freemium (294 tests) and Subscription (158 tests) pass with zero errors.

### Mandatory Next Action:
Stop here. Do not make code modifications or begin Stage 1E.2.D.D.6 until authorized by user direction.
