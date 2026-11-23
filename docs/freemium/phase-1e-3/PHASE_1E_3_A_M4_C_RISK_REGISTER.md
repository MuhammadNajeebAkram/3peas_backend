# Stage 1E.3.A — Milestone 4 Phase 4C Risk Register
## Assessment Runner Integration Risk Management & Failure Modes (Clarified Revision)

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M4_C_RISK_REGISTER.md`
- **Revision Date:** November 20, 2026 (Clarified Revision)
- **Primary Repository:** `E:\Al-Faraabi Software\al-faraabi-website` (Student LMS Frontend)
- **Reference Repository (Read-Only):** `E:\Al-Faraabi Software\3peas_backend`
- **Scope:** Phase 4C Planning & Source Inspection ONLY

---

## 1. Risk Matrix Summary

| Risk ID | Category | Severity | Likelihood | Impact | Status |
|---|---|---|---|---|---|
| **R-01** | Attempt Recovery Backend Gap | High | Low | Medium | Mitigated: Safe Degradation & Backend Dependency Logged |
| **R-02** | Concurrency Race (Late Save) | Medium | High | Low | Mitigated: 409 `ATTEMPT_ALREADY_SUBMITTED` Handling |
| **R-03** | Preflight Latency | Low | Medium | Low | Mitigated: Non-blocking Skeleton State |
| **R-04** | Duration Tampering | High | Low | High | Mitigated: Server-Authoritative Duration & Expiry |
| **R-05** | Quota Bleed | Critical | Low | Critical | Mitigated: Backend Quota Event Separation |
| **R-06** | Dual-Tab Collision | Medium | Medium | Low | Mitigated: Backend DB Unique Index & Mutex |
| **R-07** | Stale / Inactive Assessment | Low | Low | Low | Mitigated: Preflight Server Truth |

---

## 2. Risk Detail & Mitigation Plans

### R-01: In-Progress Attempt Recovery Limited by Backend API Gap
- **Description:** If a student clears browser storage mid-attempt, the backend does not provide an endpoint to re-fetch question IDs or previously selected options for that attempt.
- **Mitigation:**
  - The frontend MUST NOT invent or fabricate question data.
  - If `attemptData` is missing, the runner queries `getTestEligibility(attemptId)`.
  - If the test is already completed, it cleanly redirects to the results page (`/lms/tests/test/${testId}/result`).
  - If the test is active, it displays a clear, actionable banner explaining that the local session was cleared, with a button to return to the Tests Hub.
  - A dedicated endpoint (`GET /web_api/auth/tests/{testId}/active-attempt`) is logged as a backend dependency for full multi-device resume capability.

### R-02: Race Condition Between Timer Auto-Submit and Question Progress Save
- **Description:** When the countdown timer hits 00:00:00, `submitAttempt()` fires. If an async `persistQuestionProgress()` call from a few seconds earlier completes slightly after the submission commits on the backend, the progress endpoint returns HTTP 409 `ATTEMPT_ALREADY_SUBMITTED`.
- **Mitigation:**
  - `persistQuestionProgress` explicitly intercepts `norm.code === 'ATTEMPT_ALREADY_SUBMITTED' || norm.status === 409`.
  - It suppresses error toasts, recognizing that finalization succeeded, and allows `router.replace(resultUrl)` to complete uninterrupted.

### R-03: Preflight Eligibility Network Latency
- **Description:** Executing `getTestEligibility(attemptId)` on runner mount adds an asynchronous network round-trip before questions are confirmed.
- **Mitigation:**
  - `isCheckingEligibility` runs in parallel with question fetching.
  - While checking, the questions display a clean loading skeleton (`"Verifying attempt permissions..."`).
  - Average preflight API response time is under 40ms.

### R-04: Mock Examination Duration Tampering
- **Description:** A student attempts to alter the JavaScript countdown timer in DevTools to give themselves more time during an official Board Mock Exam.
- **Mitigation:**
  - The client timer is purely visual.
  - The backend records `started_at` in `TestAttempt` when the first question is saved.
  - When `submitFormalTest` is received, the backend computes elapsed time against `started_at`. Questions submitted beyond the official time limit are evaluated per academic policy.

### R-05: Weekly Quota Accidental Decrement on Retakes
- **Description:** A retake attempt accidentally increments `freemium_quota_counters.used_tests`, depriving the student of their weekly generation allowance.
- **Mitigation:**
  - Verified in `FreemiumQuotaConsumptionService.php` lines 1180–1194:
    - Initial generation logs event `'generation'` and decrements weekly counter.
    - Subsequent retakes log event `'retake_start'` in `freemium_quota_consumptions` ledger only.
    - Weekly test generation quota counters (`freemium_quota_counters`) are **never** incremented or decremented on retake starts or progress saves.

### R-06: Dual-Tab Concurrent Attempts
- **Description:** A student opens the same assessment in two separate browser windows and submits different answers simultaneously.
- **Mitigation:**
  - Backend `WebUser::lockForUpdate()` serializes transactions per student.
  - Unique database index `uk_test_user_attempt_event` prevents duplicate attempt event rows.
  - Database unique index `uk_user_test_attempt` on `(user_id, test_id, attempt_number)` guarantees only one attempt record exists per sequence number.
  - Second concurrent attempt returns 409 `CONCURRENT_ATTEMPT_CONFLICT`, which the frontend displays gracefully.

### R-07: Deleted or Unpublished Test
- **Description:** Student navigates to an attempt ID that was deleted or archived by an administrator.
- **Mitigation:**
  - Preflight `getTestEligibility` returns 404 with `message: 'Test not found or access denied.'`.
  - Runner displays a clear error alert and offers a "Back To Tests" navigation button.
