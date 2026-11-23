# Stage 1E.2.D.1 — Testing and Regression Plan

**Document ID:** `PHASE_1E_2_D_1_TESTING_AND_REGRESSION_PLAN`  
**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Phase:** 1E.2.D.1 (Planning and Source Audit — Final Review Corrections)  
**Timestamp:** 2026-11-19  

---

## 1. Overview & Strategy

The Stage 1E.2.D test plan establishes multi-layered automated verification across four categories:
1. **Unit & Configuration Tests:** Quota limits, attempt calculations, window resets, fingerprint matching, and configuration parser boundary tests (0, negative, malformed, missing).
2. **Feature / Integration Tests:** End-to-end HTTP API tests against MySQL for test generation, retakes, practice sessions, quota exhaustion, and idempotency replays.
3. **Multi-Process Concurrency Tests:** Multi-process race condition tests testing InnoDB row-level locking.
4. **Subscription Regression Tests:** Ensuring zero regressions on the existing paid subscription system (59 tests must continue passing 100%).

---

## 2. Configuration Parser & Migration Rollback Unit Tests

**File:** `tests/Unit/Freemium/FreemiumLegacyQuotaConfigurationTest.php`

| Test Method | Category | Invariant Verified |
|---|---|---|
| `test_missing_variable_uses_defaults` | Config Boundary | `null` configuration key returns safe defaults (`1` test, `2` practice). |
| `test_empty_and_whitespace_strings_use_defaults` | Config Boundary | Values `""` and `"   "` return safe defaults (`1` test, `2` practice). |
| `test_non_numeric_strings_use_defaults` | Config Boundary | Values `"abc"`, `"foo"` rejected and return safe defaults (`1` test, `2` practice). |
| `test_decimal_strings_rejected_and_use_defaults` | Config Boundary | Values `"1.5"`, `"2.0"` rejected (integer-only enforced) and return safe defaults. |
| `test_all_negative_integers_including_minus_one_rejected` | Config Boundary | Values `-1`, `"-1"`, `-5`, `"-5"` are ALL rejected and replaced with safe defaults (`1` test, `2` practice). |
| `test_zero_quota_is_valid_and_disables_generation` | Config Boundary | Values `0` and `"0"` preserved as intentional disablement; generation fails closed with 403 `QUOTA_EXHAUSTED`. |
| `test_positive_integers_accepted` | Config Boundary | Values `1`, `5`, `"1"`, `"5"` accepted and parsed accurately to integer ceilings `1`, `5`. |
| `test_boolean_allow_retakes_safe_parsing` | Config Boundary | Eliminates unsafe PHP boolean casting: `"false"`, `false`, `"0"`, `"off"`, `"no"` return `false`; `"true"`, `true`, `"1"`, `"on"` return `true`; invalid strings (`"abc"`, `"-1"`, `"2"`) fall back safely to `false`. |
| `test_practice_retake_policy_strictly_single_attempt` | Quota Invariant | Practice sessions enforce `allow_retakes = false` and `max_attempts_per_test = 1`. |

**File:** `tests/Feature/Freemium/FreemiumMigrationRollbackSafetyTest.php`

| Test Method | Category | Invariant Verified |
|---|---|---|
| `test_forward_migration_rollback_succeeds_when_clean` | Rollback Pre-Check | `000009 down()` executes cleanly when zero multi-student conflicts exist. |
| `test_forward_migration_rollback_aborts_when_conflicts_exist` | Rollback Pre-Check | `000009 down()` throws `RuntimeException` with conflict details if multiple students have taken the same shared test; records are preserved. |

---

## 3. Multi-Process Concurrency Test Suite Specification

**File:** `tests/Feature/Freemium/Concurrency/AssessmentQuotaConcurrencyTest.php`  
**Execution Environment:** Local MySQL 8.0.43 InnoDB (`3peas_db_subscription_test`) via independent PHP CLI processes.

### 3.1 Concurrency Scenario 1: Two Simultaneous Generations (1 Quota Credit Remaining)
- **Setup:** Student assigned Freemium campaign with `max_tests = 1`, current `counter = 0`.
- **Parallel Dispatch:** Process 1 & Process 2 simultaneously POST `/web_api/auth/tests/generate-selected-units-formal-test` with unique idempotency keys.
- **Verification Assertions:**
  - Exactly one process receives HTTP 200 with test payload.
  - Exactly one process receives HTTP 403 `QUOTA_EXHAUSTED`.
  - Database table `freemium_quota_counters.counter` strictly equals `1`.
  - Database table `freemium_quota_consumptions` has exactly 1 row with `event_type = 'generation'`.
  - Database table `tests` has exactly 1 new record.

### 3.2 Concurrency Scenario 2: Two Simultaneous Initial-Attempt Requests for Same Test
- **Setup:** Student has generated Test #201, zero prior attempts.
- **Parallel Dispatch:** Process 1 & Process 2 simultaneously POST `/web_api/auth/tests/save-formal-test-question-progress` for Test #201.
- **Verification Assertions:**
  - Both processes receive HTTP 200.
  - Exactly one `TestAttempt` record exists in database (`status = 'in_progress'`).
  - Exactly one `freemium_quota_consumptions` record exists (`attempt_number = 1`, `event_type = 'attempt_start'`).
  - Zero SQL duplicate key exceptions leaked to client.

### 3.3 Concurrency Scenario 3: Two Simultaneous Retake Requests (Attempt Cap = 2)
- **Setup:** Test #201 has 1 submitted attempt. Quota allows `max_attempts_per_test = 2`, `allow_retakes = true`. Exactly 1 retake remaining.
- **Parallel Dispatch:** Process 1 & Process 2 simultaneously attempt to start a retake on Test #201.
- **Verification Assertions:**
  - Exactly one process receives HTTP 200 (`attempt_number = 2` created).
  - Exactly one process receives HTTP 403 `QUOTA_EXHAUSTED` (`Maximum permitted attempts reached`).
  - Exactly two total `TestAttempt` records exist for Test #201 (1 submitted, 1 in progress).
  - Exactly one `retake_start` ledger row exists.

### 3.4 Concurrency Scenario 4: Two Simultaneous Practice Creation Requests (1 Credit Remaining)
- **Setup:** Student has 1 practice quota credit remaining (`counter = 0`, `max_tests = 1`).
- **Parallel Dispatch:** Process 1 & Process 2 simultaneously POST `/web_api/auth/tests/generate-selected-units-practice-session`.
- **Verification Assertions:**
  - Process 1 receives HTTP 200 (Session created).
  - Process 2 receives HTTP 403 (`QUOTA_EXHAUSTED`).
  - Exactly one `PracticeSession` created in database.
  - Practice counter equals `1`.

### 3.5 Concurrency Scenario 5: Two Simultaneous Resume Requests on Same Practice Session
- **Setup:** Practice Session #301 is active (`in_progress`).
- **Parallel Dispatch:** Process 1 posts answer for Question 1; Process 2 posts answer for Question 2.
- **Verification Assertions:**
  - Both processes receive HTTP 200.
  - Session #301 contains both answers in `practice_session_questions`.
  - Practice quota counter is **completely unchanged** (0 quota consumed).
  - Zero duplicate sessions created.

### 3.6 Concurrency Scenario 6: Retry After Simulated Partial Failure
- **Setup:** Generation request dispatched with idempotency key `KEY_FAIL_RETRY`. Simulates an unhandled exception or lock timeout right before commit.
- **Action:** Transaction rolls back completely. Client retries with identical `KEY_FAIL_RETRY`.
- **Verification Assertions:**
  - Retry succeeds with HTTP 200.
  - Counter increments exactly once (from 0 to 1).
  - Exactly 1 test and 1 ledger row exist.
  - Zero dangling or orphaned records in database.

---

## 4. Existing Test Regression Invariants

Before any Stage 1E.2.D code is merged, the complete existing test suite must be executed:
1. `tests/Feature/Freemium/Student/`: All 41 student Freemium feature tests must pass.
2. `tests/Unit/Freemium tests/Feature/Freemium`: All 166 Freemium subsystem tests must pass.
3. `tests/Feature/StudentClassSubscriptionIntegrationTest.php tests/Feature/StudentClassSubscriptionAccessTest.php`: All 59 legacy subscription integration tests must pass.

**Total Invariant Baseline: 225 passing tests, 0 failures, 0 regressions.**
