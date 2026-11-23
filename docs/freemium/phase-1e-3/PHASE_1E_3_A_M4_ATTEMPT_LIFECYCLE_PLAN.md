# Stage 1E.3.A — Milestone 4 Attempt Lifecycle & Accounting Plan
## Attempt Eligibility, Retake Accounting, and Concurrency Controls

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M4_ATTEMPT_LIFECYCLE_PLAN.md`
- **Execution Date:** November 20, 2026
- **Target Repository (Primary):** `E:\Al-Faraabi Software\al-faraabi-website` (`thestudentstimes.git`)
- **Backend Reference (Read-Only):** `E:\Al-Faraabi Software\3peas_backend`
- **Status:** Read-Only Architectural Specification

---

## 1. Lifecycle States & Event Model

The student assessment lifecycle flows through four discrete stages:

```
[1. Generation]
       │  Event: 'generation' (or 'practice_generation')
       │  Quota Action: Increments used_tests counter in freemium_quota_counters (if Freemium/Legacy)
       │  Record: Test record created (test_mode: 'test'|'mock') OR PracticeSession created
       ▼
[2. Attempt Start / Retake Start]
       │  Event: 'attempt_start' (attempt 1) OR 'retake_start' (attempt > 1)
       │  Trigger: Student answers first MCQ via /save-*-question-progress
       │  Quota Action: Verifies max_attempts_per_test & allow_retakes; DOES NOT decrement weekly quota
       │  Record: TestAttempt created (status: 'in_progress')
       ▼
[3. Incremental Progress]
       │  Actions: Answer selection, timing capture, flag toggles
       │  Idempotency: Re-saving answer updates existing record without duplicate attempts
       ▼
[4. Submission / Finalization]
       │  Trigger: Student clicks "Submit Test" OR timer expires
       │  Actions: TestAttempt updated (status: 'submitted', score, accuracy)
       │  Result: Immutable evaluation saved in database
```

---

## 2. Policy Enforcement Invariants

### 2.1 `max_attempts_per_test` Includes Initial Attempt
- By platform definition, `max_attempts_per_test = 1` permits exactly **one** attempt.
- `attempt_number` is derived as `completedAttemptsCount + 1`.
- When `completedAttemptsCount >= max_attempts_per_test`:
  - `getTestAttemptEligibility` returns `can_attempt = false`, `denial_reason = 'Maximum attempt limit reached for this test.'`
  - Attempt start requests are rejected with HTTP 403 `QUOTA_EXHAUSTED`.

### 2.2 `allow_retakes = false` Prevents Subsequent Attempts
- If a campaign or legacy rule defines `allow_retakes = false`:
  - A student may complete the initial attempt (`attempt_number = 1`).
  - Any subsequent attempt (`attempt_number >= 2`) is rejected with:
    `"Retakes are not permitted for this test."`
- If `allow_retakes = true` and `attempt_number <= max_attempts_per_test`:
  - Retake is permitted.
  - The retake event is logged as `retake_start` in `freemium_quota_consumptions`.
  - **Critical Rule:** Retakes do NOT consume additional weekly test generation quota!

### 2.3 Interrupted Practice Sessions
- Practice sessions maintain `status = 'in_progress'` until explicitly submitted.
- If a student closes their browser, experiences a disconnect, or refreshes the page:
  - The session ID remains active in `practice_sessions`.
  - Navigating back to `/lms/tests/practice/[sessionId]` restores the session and questions without creating a new session or deducting quota.

### 2.4 Abandoned Formal / Mock Attempts
- Timed formal and mock assessments require strict integrity.
- Once the initial question is saved, `TestAttempt` is created with `status = 'in_progress'`.
- If a student leaves or abandons the browser:
  - The attempt is locked to that student.
  - When the client returns or when the server background sweeper evaluates the test, the elapsed time consumes the allocated attempt.
  - An abandoned attempt counts toward `max_attempts_per_test` and cannot be reclaimed.

---

## 3. Concurrency & Database Locking Design

In `FreemiumQuotaConsumptionService.php`:
1. **Parent-Row Serialization:**
   Every quota consumption or attempt start locks the student's `WebUser` record:
   ```php
   WebUser::where('id', $student->id)->lockForUpdate()->first();
   ```
   This serializes concurrent requests for the same student, eliminating race conditions across parallel browser tabs or double-clicks.
2. **Flattened Transactions:**
   Nested transactions are flattened to prevent `SAVEPOINT trans2` failures during rollbacks.
3. **Database Unique Index Constraints:**
   - Unique constraint `uk_user_test_attempt` on `test_attempts(user_id, test_id, attempt_number)` guarantees at the database engine level that duplicate attempt records can never be created for the same attempt sequence number.
