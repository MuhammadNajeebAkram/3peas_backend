# Stage 1E.2.D.D — Testing and Regression Plan

**Document ID:** `PHASE_1E_2_D_D_TESTING_AND_REGRESSION_PLAN`  
**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Stage:** 1E.2.D.D — Planning and Source Inspection ONLY  
**Timestamp:** 2026-11-19  

---

## 1. Test Suite Objectives

The Stage D.D test plan establishes automated verification for:
1. **Recent Attempts Endpoint Hardening:** Role checks, attempt number visibility, mock test formatting, subject/program filtering, and chronological sorting.
2. **Mock-Test Full Lifecycle:** Eligibility evaluation, initial attempt, retakes, scoring, and activity logging.
3. **Concurrency & Student Isolation:** Simultaneous queries, shared mock test access, and zero cross-student data leakage.
4. **Full Suite Regression:** 100% pass rate across all existing 263 tests (D.A, D.B, D.C, and Subscription integration).

---

## 2. Planned Automated Test Matrix

### 2.1 Feature Tests: Recent Attempts Hardening (`TestRecentAttemptsHardeningTest.php`)

| Test Method | Test Objective | Expected HTTP Status | Key Assertions |
|---|---|---|---|
| `test_unauthenticated_request_is_rejected` | Verify unauthenticated access fails. | 401 Unauthorized | Unauthenticated rejection. |
| `test_teacher_and_admin_cannot_access_recent_attempts` | Verify non-student roles are denied. | 403 Forbidden | `message: Active student account required.` |
| `test_inactive_student_cannot_access_recent_attempts` | Verify suspended/inactive students are denied. | 403 Forbidden | `message: Active student account required.` |
| `test_recent_attempts_returns_attempt_number` | Verify sequential attempt numbers are exposed in JSON. | 200 OK | `data.*.attempt_number` matches DB `test_attempts.attempt_number`. |
| `test_recent_attempts_formats_mock_tests_distinctly` | Verify mock tests have distinct display titles. | 200 OK | `test_mode: 'mock'`, display title formatted appropriately. |
| `test_recent_attempts_filters_by_program_and_subject` | Verify program and subject query filters. | 200 OK | Only attempts matching filtered program/subject returned. |
| `test_recent_attempts_enforces_limit_boundary` | Verify validation on limit parameter (min 1, max 20). | 422 Unprocessable | Invalid limit (>20 or <1) rejected. |
| `test_recent_attempts_merges_and_sorts_chronologically` | Verify formal tests and practice sessions are merged by `submitted_at DESC`. | 200 OK | Chronological ordering verified. |
| `test_recent_attempts_isolates_student_records` | Verify Student A cannot see Student B's attempts. | 200 OK | 0 records belonging to other students returned. |

### 2.2 Feature Tests: Mock-Test Lifecycle (`TestMockTestLifecycleTest.php`)

| Test Method | Test Objective | Expected HTTP Status | Key Assertions |
|---|---|---|---|
| `test_mock_test_eligibility_reflects_campaign_and_fallback` | Verify `testEligibility` for `test_mode = 'mock'`. | 200 OK | `test_mode: 'mock'`, `can_attempt: true/false`. |
| `test_mock_test_first_progress_consumes_mock_attempt_quota` | Verify first progress consumes `attempt_start` under `exam_type = 'mock'`. | 200 OK | Ledger row written with `exam_type = 'mock'`. |
| `test_mock_test_retake_respects_campaign_policy` | Verify retake start consumes `retake_start` or is blocked if retakes disabled. | 200 OK / 403 | Policy enforced as configured. |
| `test_paid_student_has_unlimited_mock_attempts` | Verify paid student bypasses quotas on mock tests in paid program. | 200 OK | 0 freemium quota consumed, attempts created. |
| `test_mock_test_submission_records_mock_completed_activity` | Verify activity logged as `mock_completed`. | 200 OK | `student_activity_tbl` contains `activity_type: 'mock_completed'`. |

---

## 3. Concurrency & Stress Testing Scenarios

1. **Concurrent Recent-Attempt Inquiries:**
   - 10 parallel requests querying recent attempts for the same student.
   - Verified: All return identical results without deadlocks or timeouts.
2. **Concurrent Mock-Test Starts:**
   - 2 concurrent first-progress requests on a shared mock test for the same student.
   - Verified: Exactly 1 `test_attempts` record created (`attempt_number = 1`) and 1 quota ledger row created.

---

## 4. Regression Execution Protocol

Before Stage D.D completion:
```bash
php artisan test --filter=Freemium
php artisan test --filter=Subscription
```
Target: **100% Pass** (All 263 existing tests + new D.D test suite).
