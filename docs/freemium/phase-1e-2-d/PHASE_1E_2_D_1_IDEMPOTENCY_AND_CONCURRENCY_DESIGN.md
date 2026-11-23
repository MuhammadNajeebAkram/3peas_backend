# Stage 1E.2.D.1 — Idempotency and Concurrency Design

**Document ID:** `PHASE_1E_2_D_1_IDEMPOTENCY_AND_CONCURRENCY_DESIGN`  
**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Phase:** 1E.2.D.1 (Planning and Source Audit — Final Review Corrections)  
**Timestamp:** 2026-11-19  

---

## 1. Executive Summary

This document establishes the transaction atomicity, connection consistency, idempotency lifecycle phases, and multi-process concurrency verification architecture for assessment and practice quota enforcement. It incorporates the reconciled practice repeat/resume policy and explicit configuration validation.

---

## 2. Transaction Atomicity & Connection Consistency

### 2.1 The Same Connection & Unified Transaction Guarantee
In Laravel applications, atomicity can break if operations run across mismatched database connections or improper transaction nesting:
- **Connection Alignment:** All participating models (`Test`, `TestAttempt`, `PracticeSession`, `FreemiumQuotaConsumption`, `FreemiumQuotaCounter`, `WebUser`) reside on the default MySQL connection (`config('database.default')`). No cross-connection isolation leaks exist.
- **Unified Transaction Scope:**
  `TestAttempt::create()` (or `Test::create()`) and `FreemiumQuotaConsumptionService::consumeQuota()` must execute within a single enclosing `DB::transaction()`:
  ```php
  DB::connection()->transaction(function () use ($student, $testId, $options) {
      // 1. Lock student parent row on the default connection
      WebUser::query()->where('id', $student->id)->lockForUpdate()->first();

      // 2. Check and lock quota counter on the same connection
      $counter = FreemiumQuotaCounter::query()->where(...)->lockForUpdate()->first();

      // 3. Mutate business entities (Test / Attempt / PracticeSession)
      $attempt = TestAttempt::create([...]);

      // 4. Record consumption in Freemium Ledger
      $consumption = $this->quotaService->consumeQuota($student, ...);

      // 5. Commit all mutations simultaneously
      return $attempt;
  });
  ```
- **Nested Transactions:** `consumeQuota()` itself uses `DB::transaction()`. In Laravel/InnoDB, nested `DB::transaction()` calls utilize MySQL savepoints. If the inner block fails or the outer block rolls back, all mutations revert completely.

### 2.2 Timeout, Disconnection, and Partial Failure Recovery
Consider a network disconnect or query timeout occurring immediately after inserting `TestAttempt` but before committing:
1. **InnoDB Rollback:** MySQL automatically rolls back the uncommitted transaction upon connection drop or timeout.
2. **Lock Release:** All row-level locks on `WebUser` and `FreemiumQuotaCounter` are released.
3. **No Phantom State:** Zero orphaned `TestAttempt` or `FreemiumQuotaConsumption` rows exist.
4. **Retry Behavior:** When the student client retries:
   - It re-evaluates `getTestAttemptEligibility()`.
   - Sees the original attempt count (e.g. 0).
   - Re-acquires the lock cleanly.
   - Executes a fresh, atomic attempt creation and ledger consumption.

---

## 3. Refined Idempotency Operation Separation

Assessment operations are strictly partitioned into distinct idempotency lifecycle phases:

| Sub-Operation Category | Idempotency Required? | Identity / Key Source | Fingerprint Fields | Replay Behavior | Conflict Behavior (HTTP 409) |
|---|:---:|---|---|---|---|
| **Op-1: Formal Test Generation** | **YES** | `X-Idempotency-Key` or `idempotency_key` | `offered_program_id`, `subject_id`, `unit_ids`, `book_id`, `total_questions`, `mcq_format` | Replays generated test response; zero quota deducted | Same key + different parameters -> HTTP 409 |
| **Op-2: Practice Session Generation** | **YES** | `X-Idempotency-Key` or `idempotency_key` | `offered_program_id`, `subject_id`, `unit_ids`, `book_id`, `total_questions`, `mcq_format` | Replays generated session response; zero quota deducted | Same key + different parameters -> HTTP 409 |
| **Op-3: Attempt Start / Retake Creation** | **YES** | `test_id` + `attempt_number` | `test_id`, `attempt_number`, `user_id` | Returns active `TestAttempt`; zero extra ledger rows | Cannot create two attempts for same attempt number |
| **Op-4: In-Flight Answer Saving** | **NO** (Inherently Idempotent Upsert) | N/A (Session/Attempt context) | N/A | Normal update/overwrite of selected option for that question | Does not use idempotency keys; student can freely change answers |
| **Op-5: Attempt Finalization / Submission** | **YES** | `attempt_id` / `session_id` | `attempt_id`, `user_id`, submitted questions map | Returns finalized score & accuracy; zero duplicate statistics or activity records | Resubmitting already finalized attempt returns 200 with cached result |

---

## 4. Multi-Process Concurrency Verification Design

Six multi-process concurrency test scenarios will be automated using PHP CLI sub-processes (`proc_open`) against MySQL InnoDB (`3peas_db_subscription_test`):

### Scenario 1: Two Simultaneous Generations (1 Quota Credit Remaining)
- **Setup:** Student has 1 test quota credit remaining (`counter = 0`, `max_tests = 1`).
- **Parallel Dispatch:** Process 1 & Process 2 simultaneously POST `/generate-selected-units-formal-test` with unique idempotency keys.
- **Verification Assertions:**
  - Exactly one process receives HTTP 200 with test payload.
  - Exactly one process receives HTTP 403 `QUOTA_EXHAUSTED`.
  - `FreemiumQuotaCounter.counter` strictly equals `1`.
  - `freemium_quota_consumptions` has exactly 1 generation row.
  - `tests` table has exactly 1 new record.

### Scenario 2: Two Simultaneous Initial-Attempt Requests for Same Test
- **Setup:** Student has generated Test #201, zero prior attempts.
- **Parallel Dispatch:** Process 1 & Process 2 simultaneously POST `/save-formal-test-question-progress` for Test #201.
- **Verification Assertions:**
  - Both processes receive HTTP 200.
  - Exactly one `TestAttempt` record created (`status = 'in_progress'`).
  - Exactly one `freemium_quota_consumptions` record exists (`attempt_number = 1`, `event_type = 'attempt_start'`).
  - Zero SQL duplicate key exceptions leaked.

### Scenario 3: Two Simultaneous Retake Requests (Attempt Cap = 2)
- **Setup:** Test #201 has 1 submitted attempt. Quota allows `max_attempts_per_test = 2`, `allow_retakes = true`. Exactly 1 retake remaining.
- **Parallel Dispatch:** Process 1 & Process 2 simultaneously dispatch retake start requests for Test #201.
- **Verification Assertions:**
  - Exactly one process receives HTTP 200 (`attempt_number = 2` created).
  - Exactly one process receives HTTP 403 `QUOTA_EXHAUSTED` (`Maximum permitted attempts reached`).
  - Exactly two total `TestAttempt` records exist for Test #201.
  - Exactly one `retake_start` ledger row exists.

### Scenario 4: Two Simultaneous Practice Creation Requests (1 Credit Remaining)
- **Setup:** Student has 1 practice quota credit remaining (`counter = 0`, `max_tests = 1`).
- **Parallel Dispatch:** Process 1 & Process 2 simultaneously POST `/generate-selected-units-practice-session`.
- **Verification Assertions:**
  - Process 1 receives HTTP 200 (Session created).
  - Process 2 receives HTTP 403 (`QUOTA_EXHAUSTED`).
  - Exactly one `PracticeSession` created.
  - Practice counter equals `1`.

### Scenario 5: Two Simultaneous Resume Requests on Same Practice Session
- **Setup:** Practice Session #301 is active (`in_progress`).
- **Parallel Dispatch:** Process 1 posts answer for Question 1; Process 2 posts answer for Question 2.
- **Verification Assertions:**
  - Both processes receive HTTP 200.
  - Session #301 contains both answers in `practice_session_questions`.
  - Practice quota counter is completely unchanged (0 quota consumed).
  - Zero duplicate sessions created.

### Scenario 6: Retry After Simulated Partial Failure
- **Setup:** Generation request dispatched with idempotency key `KEY_FAIL_RETRY`. Simulates an unhandled exception or lock timeout right before commit.
- **Action:** Transaction rolls back completely. Client retries with identical `KEY_FAIL_RETRY`.
- **Verification Assertions:**
  - Retry succeeds with HTTP 200.
  - Counter increments exactly once (from 0 to 1).
  - Exactly 1 test and 1 ledger row exist.
  - Zero dangling or orphaned records in database.
