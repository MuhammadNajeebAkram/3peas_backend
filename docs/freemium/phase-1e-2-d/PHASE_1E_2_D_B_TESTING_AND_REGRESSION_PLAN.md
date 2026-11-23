# Stage 1E.2.D.B — Testing and Regression Plan

**Document ID:** `PHASE_1E_2_D_B_TESTING_AND_REGRESSION_PLAN`  
**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Stage:** 1E.2.D.B — Shared Official-Test Uniqueness, Attempt & Retake Accounting (Planning Mode)  
**Timestamp:** 2026-11-19  

---

## 1. Automated Test Suites Specification

### Test Suite 1: Shared Official-Test & Attempt Accounting Unit & Feature Tests
**File:** `tests/Feature/Freemium/Student/StudentFormalTestAttemptAccountingTest.php`

1. **`test_two_students_can_attempt_same_official_test_without_collision()`**
   - Official test #500 created (`test_source = 'official'`, `is_published = 1`).
   - Student A and Student B both start Attempt #1.
   - Assert: Both receive HTTP 200; two distinct `TestAttempt` rows created; two distinct `freemium_quota_consumptions` rows created (`(500, studentA, 1, 'attempt_start')` and `(500, studentB, 1, 'attempt_start')`).
2. **`test_student_can_take_initial_attempt_and_permitted_retake()`**
   - Campaign allows `allow_retakes = true`, `max_attempts_per_test = 2`.
   - Student starts attempt 1 -> `attempt_number = 1`, `attempt_start`. Submits test.
   - Student starts retake -> `attempt_number = 2`, `retake_start`.
   - Assert: Exactly 2 attempts in `test_attempts`, exactly 2 ledger records in `freemium_quota_consumptions`.
3. **`test_retake_blocked_when_retakes_disabled()`**
   - Campaign has `allow_retakes = false`, `max_attempts_per_test = 1`.
   - Student completes attempt 1.
   - Student attempts retake -> HTTP 403 `QUOTA_EXHAUSTED` (`Retakes are not permitted for this test.`).
4. **`test_retake_blocked_when_max_attempts_cap_reached()`**
   - Campaign has `allow_retakes = true`, `max_attempts_per_test = 2`.
   - Student completes attempt 1 and attempt 2.
   - Student attempts retake 3 -> HTTP 403 `QUOTA_EXHAUSTED` (`Maximum permitted attempts (2) reached for this test.`).
5. **`test_abandoned_attempt_burns_attempt_allowance()`**
   - Student starts attempt 1 (`status = 'in_progress'`), but never submits.
   - Retakes are disabled (`allow_retakes = false`).
   - If student tries to start a *new* attempt, it is rejected with 403.
6. **`test_in_progress_attempt_resumed_without_consuming_extra_attempt()`**
   - Student saves progress on question 1 -> Attempt #1 created (`in_progress`).
   - Student saves progress on question 2, then updates question 1 answer.
   - Assert: Exactly 1 `TestAttempt` exists, exactly 1 `attempt_start` ledger row exists.
7. **`test_paid_student_creates_attempts_without_freemium_ledger_consumption()`**
   - Active paid subscriber starts initial attempt and retakes.
   - Assert: `TestAttempt` created with scores; zero rows written to `freemium_quota_consumptions`.
8. **`test_cross_student_unowned_test_attempt_rejected_with_404()`**
   - Student B tries to save progress or submit on Student A's custom generated test.
   - Assert: HTTP 404 `Not Found` (`Test not found or access denied.`).

---

## 2. Multi-Process Concurrency Test Suite (MySQL InnoDB Engine)
**File:** `tests/Feature/Freemium/Concurrency/AssessmentAttemptConcurrencyTest.php`

1. **Scenario 1: Two Students Starting Same Official Test Simultaneously (Multi-Process CLI)**
   - Two concurrent PHP CLI processes simultaneously POST `/save-formal-test-question-progress` for the same official test.
   - Assert: Both processes return HTTP 200; two distinct attempt rows created; zero MySQL 1062 errors.
2. **Scenario 2: Same Student Double-Click Initial Attempt Start (Multi-Process CLI)**
   - Two concurrent processes for same student simultaneously dispatch initial save.
   - Assert: Both return HTTP 200 referencing the same `attempt_id`; exactly 1 `TestAttempt` created; exactly 1 `attempt_start` consumption row created.
3. **Scenario 3: Two Simultaneous Retake Requests Competing for Last Attempt Slot**
   - Student has 1 completed attempt, exactly 1 retake remaining. Two processes simultaneously dispatch retake start.
   - Assert: Exactly one process receives HTTP 200 (attempt 2 created); exactly one receives HTTP 403 `QUOTA_EXHAUSTED`; total attempts = 2.
4. **Scenario 4: Submission vs Autosave Race**
   - Process 1 submits attempt (`submitFormalTestForLms`); Process 2 attempts late autosave (`saveFormalTestQuestionProgressForLms`).
   - Assert: Submission commits cleanly with final score; late autosave is rejected with HTTP 409 Conflict without altering finalized score.

---

## 3. Regression Test Coverage
- **Freemium Subsystem Suite:** Run all 204 existing Freemium unit & feature tests.
- **Classroom Assessment Regression:** Run all 16 `StudentClassSubscriptionIntegrationTest` tests.
- **Practice Session Regression:** Verify `PracticeSessionController` remains unaffected.
