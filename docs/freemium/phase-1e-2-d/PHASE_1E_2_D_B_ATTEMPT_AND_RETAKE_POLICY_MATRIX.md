# Stage 1E.2.D.B — Attempt and Retake Policy Matrix

**Document ID:** `PHASE_1E_2_D_B_ATTEMPT_AND_RETAKE_POLICY_MATRIX`  
**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Stage:** 1E.2.D.B — Shared Official-Test Uniqueness, Attempt & Retake Accounting (Planning Mode)  
**Timestamp:** 2026-11-19  

---

## 1. Approved Entitlement Policy Matrix

| Entitlement Tier | Initial Attempt Policy | Retake Policy | Attempt Limit (`max_attempts_per_test`) | Freemium Ledger Recording |
|---|---|---|---|---|
| **Tier 1: Paid Subscription** | **Unlimited** within active paid program/subject scope. | **Unlimited** within active paid program/subject scope. | **Unlimited** (Bypasses caps). | **NO** consumption records created. `test_attempts` created for score & analytics history only. |
| **Tier 2: Explicit Freemium Campaign** | Permitted if campaign quota allows. | Governed strictly by campaign `allow_retakes` boolean. | Governed strictly by campaign `max_attempts_per_test` (inclusive of initial attempt). | **YES.** `attempt_start` (attempt 1) and `retake_start` (attempt 2..N) recorded with campaign & quota foreign keys. |
| **Tier 3: Legacy-Free Fallback** | **One attempt** allowed per generated or shared test. | **DENIED by default** (`allow_retakes = false`). Configurable via `config('freemium.fallback_quotas.allow_retakes')`. | Defaults to **1** (`config('freemium.fallback_quotas.max_attempts_per_test')`). | **YES.** `attempt_start` recorded with nullable campaign/quota keys (enabled by migration 000009). |
| **Tier 4: Denied / No Entitlement** | **DENIED** (HTTP 403 Forbidden). | **DENIED** (HTTP 403 Forbidden). | **0** | **NO** records created. |

---

## 2. Core Business Rules

1. **Generation vs Attempt Quota Decoupling:**
   - Formal test generation consumes **generation quota** (tracked in `freemium_quota_counters`).
   - Starting an initial attempt or a permitted retake **does NOT consume generation quota**. Weekly counters are not incremented on attempt starts.
   - Attempts and retakes are audited independently in `freemium_quota_consumptions`.
2. **Inclusive Attempt Counting:**
   - `max_attempts_per_test` includes the initial attempt.
   - Example: If `max_attempts_per_test = 2` and `allow_retakes = true`, the student gets:
     - 1 Initial Attempt (`attempt_number = 1`, `event_type = 'attempt_start'`).
     - 1 Retake (`attempt_number = 2`, `event_type = 'retake_start'`).
     - Total allowed attempts = 2. Any further retake attempt is rejected with HTTP 403 `QUOTA_EXHAUSTED` (`Maximum permitted attempts reached`).
3. **Retake Disablement:**
   - When `allow_retakes = false`, any attempt after attempt 1 is rejected with HTTP 403 `QUOTA_EXHAUSTED` (`Retakes are not permitted for this test.`).
4. **Active Attempt Resumption:**
   - If an attempt is currently `status = 'in_progress'`, saving progress or submitting answers operates on the existing attempt.
   - Zero additional attempts are consumed, and zero new ledger rows are written.
5. **Abandoned Attempt Allowance Burn:**
   - If a student initiates an attempt (`status = 'in_progress'`) and abandons it (e.g. leaves without submitting):
   - That attempt is recorded in `test_attempts` and counted towards `$attemptCount`.
   - If `allow_retakes = false`, the student cannot start a second attempt on that test; the abandoned attempt burned their sole allowance.
6. **Paid Exemption:**
   - Paid subscribers do not have records inserted into `freemium_quota_consumptions` or `freemium_quota_counters`.
   - They create standard `test_attempts` rows for LMS grading, review, and teacher gradebooks without freemium quotas.

---

## 3. Paid Attempt History & Shared Official Tests

### 3.1 Attempt History Without Freemium Ledger Rows
Paid students maintain complete academic and operational history without participating in the Freemium quota ledger:
1. **Physical Persistence:**
   - Every paid attempt is persisted directly in the core `test_attempts` table with `user_id = $student->id` and `test_id = $test->id`.
   - Question-level answers and scores are persisted in `test_attempt_questions`.
2. **Distinguishing Sequential Attempts:**
   - Because `test_attempts` lacks an `attempt_number` column, sequential attempts for paid students are distinguished ordinally by chronological order (`started_at ASC`, `id ASC`):
     ```sql
     SELECT id, test_id, score, accuracy, started_at, submitted_at, status,
            ROW_NUMBER() OVER (PARTITION BY test_id, user_id ORDER BY id ASC) AS attempt_number
     FROM test_attempts
     WHERE test_id = :testId AND user_id = :userId
     ORDER BY id ASC;
     ```
   - In Eloquent / API serialization:
     ```php
     $attempts = TestAttempt::query()
         ->where('test_id', $testId)
         ->where('user_id', $student->id)
         ->orderBy('id', 'asc')
         ->get()
         ->values()
         ->map(function ($attempt, int $index) {
             $attempt->attempt_number = $index + 1;
             return $attempt;
         });
     ```
3. **Active Attempt Identification:**
   - An active attempt is uniquely identified by:
     ```php
     $activeAttempt = TestAttempt::query()
         ->where('test_id', $testId)
         ->where('user_id', $student->id)
         ->where('status', 'in_progress')
         ->latest('id')
         ->first();
     ```
   - If an `in_progress` record exists, progress saves (`saveFormalTestQuestionProgressForLms`) update that record.
   - When submitted, its status updates to `'submitted'`.
   - The subsequent progress save sees 0 active `in_progress` rows and seamlessly creates the next attempt.

### 3.2 Full Shared Official-Test Support for Paid Students
- For official shared tests (`test_source = 'official'`, `is_published = 1`):
  - Every paid student's attempts are filtered strictly by `where('user_id', $student->id)`.
  - Paid Student A taking Test #100 receives `attempt_number = 1`, `attempt_number = 2`, etc.
  - Paid Student B taking Test #100 independently receives `attempt_number = 1`, `attempt_number = 2`, etc.
  - Zero cross-student interference occurs because `test_attempts` is naturally partitioned by `user_id`.
  - Zero rows are written to `freemium_quota_consumptions`, eliminating any possibility of index collision or quota exhaustion.
