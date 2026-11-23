# Stage 1E.3.A — Milestone 4 Phase 4C Testing Plan
## Comprehensive Frontend Attempt Runner & Eligibility Verification Suite (Clarified Revision)

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M4_C_TESTING_PLAN.md`
- **Revision Date:** November 20, 2026 (Clarified Revision)
- **Primary Repository:** `E:\Al-Faraabi Software\al-faraabi-website` (Student LMS Frontend)
- **Reference Repository (Read-Only):** `E:\Al-Faraabi Software\3peas_backend`
- **Scope:** Phase 4C Planning & Source Inspection ONLY

---

## 1. Test Suite Architecture

Testing for Phase 4C will be implemented in:
`tests/freemiumAttemptRunner.test.mjs`

It will execute natively with `node --test tests/freemiumAttemptRunner.test.mjs` alongside the 82 existing Freemium frontend tests.

---

## 2. Test Case Specifications (AR-01 through AR-14)

### 2.1 Preflight Eligibility & Access Gates
- **AR-01: Preflight Verification - Granted attempt initializes runner state**
  - Verify that when `getTestEligibility` returns `can_attempt: true, attempt_number: 1, test_source: 'system'`, the runner unlocks question interaction and populates attempt sequence.
- **AR-02: Preflight Verification - Denied retake halts runner and mounts quota dialog**
  - Verify that when `can_attempt: false, is_retake: true, denial_reason: 'Retakes are not permitted for this test.'`, the runner sets `eligibilityDenied: true` and mounts the denial dialog.
- **AR-03: Preflight Verification - Exhausted mock exam allowance triggers quota dialog**
  - Verify that 403 `QUOTA_EXHAUSTED` from preflight triggers `QuotaExhaustedDialog` with `examType = 'mock'`.

### 2.2 Attempt Badging & Mode Branding
- **AR-04: Badging - Renders initial attempt sequence (`Attempt 1 of 1`)**
  - Verify `AttemptNumberBadge` displays `Attempt 1 of 1` (or `Attempt 1`) when `attempt_number: 1, max_attempts: 1`.
- **AR-05: Badging - Renders retake attempt sequence (`Retake 2 of 3`)**
  - Verify `AttemptNumberBadge` displays `Retake 2 of 3` with replay icon when `attempt_number: 2, max_attempts: 3, is_retake: true`.
- **AR-06: Badging - Paid student displays unlimited retake badge**
  - Verify `AttemptNumberBadge` displays `Attempt N` without max limits when `is_paid: true, max_attempts: null`.
- **AR-07: Mode Branding - Board Mock Exam displays distinctive warning tone**
  - Verify that mock mode (`test_mode === 'mock'`) renders `"Board Mock Exam"` title and amber countdown timer chip (`#fef3c7`, `#92400e`).
- **AR-08: Mode Branding - Formal Test displays standard blue timer styling**
  - Verify that formal mode (`test_mode === 'test'`) renders `"Formal Test"` title and blue timer chip (`#dbeafe`, `#1d4ed8`).

### 2.3 Attempt ID Propagation & Storage Resiliency
- **AR-09: Progress Payload - Forwards active `attempt_id` when present**
  - Verify `persistQuestionProgress` includes `attempt_id: 4052` when `eligibility.activeAttemptId` is present.
- **AR-10: Dynamic ID Capture - Captures `attempt_id` from first save response**
  - Verify that when initial save returns `{ data: { attempt_id: 4052 } }`, the runner updates active attempt state and passes `4052` on subsequent saves.
- **AR-11: Quota Invariance - Question progress saves do NOT decrement weekly quota**
  - Verify that question progress payload targets `/save-formal-test-question-progress` and does not call test generation or alter weekly test counters.
- **AR-12: Missing Storage Safe Fallback - Fails safe on storage loss**
  - Verify that when `sessionStorage` is empty on load, the runner does not crash or fabricate questions, but surfaces an actionable message directing the user back to the Tests Hub or Results page.

### 2.4 Submission & Concurrency
- **AR-13: Idempotent Submission - Handles already-submitted attempt gracefully**
  - Verify that if the server returns 409 `ATTEMPT_ALREADY_SUBMITTED` or a 200 replay payload during submission, the runner accepts the evaluation and completes the transition without displaying errors.
- **AR-14: Timer Expiry - Automatically triggers submission at zero seconds**
  - Verify that countdown reaching 0 automatically calls `submitAttempt()` and disables user input.

---

## 3. Verification Command Checklist

```bash
# 1. Run new Attempt Runner test suite
node --test tests/freemiumAttemptRunner.test.mjs

# 2. Run all frontend Freemium test suites (Target: 96 total tests passing)
node --test tests/freemiumClient.test.mjs tests/freemiumComponents.test.mjs tests/freemiumTestsHub.test.mjs tests/freemiumLaunchScreen.test.mjs tests/freemiumAttemptRunner.test.mjs

# 3. Run ESLint on touched runner files
npx eslint src/components/LMS/tests/TestAttemptScreen.jsx
```
