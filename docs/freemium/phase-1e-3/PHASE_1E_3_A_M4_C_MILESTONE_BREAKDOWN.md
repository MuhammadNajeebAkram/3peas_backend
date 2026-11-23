# Stage 1E.3.A — Milestone 4 Phase 4C Milestone Breakdown
## Phased Implementation Sequence & Deliverables for Phase 4C (Clarified Revision)

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M4_C_MILESTONE_BREAKDOWN.md`
- **Revision Date:** November 20, 2026 (Clarified Revision)
- **Primary Repository:** `E:\Al-Faraabi Software\al-faraabi-website` (Student LMS Frontend)
- **Reference Repository (Read-Only):** `E:\Al-Faraabi Software\3peas_backend`
- **Scope:** Phase 4C Planning & Source Inspection ONLY

---

## 1. Phased Execution Sequence

Phase 4C is subdivided into five modular work packages:

```
┌────────────────────────────────────────────────────────────────────────┐
│ Phase 4C.1: Preflight Eligibility Hook & Denial UI Integration        │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│ Phase 4C.2: Attempt Badging & Mode Branding (Board Mock Exam)          │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│ Phase 4C.3: Attempt ID Propagation & Storage Resiliency               │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│ Phase 4C.4: Concurrency & 409 Already-Submitted Exception Handling     │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│ Phase 4C.5: Verification Suite & Implementation Reporting              │
└────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Detailed Work Packages

### Phase 4C.1: Preflight Eligibility Hook & Denial UI Integration
- **Target File:** `src/components/LMS/tests/TestAttemptScreen.jsx`
- **Objective:** Prevent unauthorized attempts by validating eligibility before question interactions are unlocked.
- **Tasks:**
  1. Add `isCheckingEligibility`, `eligibility`, and `eligibilityDenied` state hooks.
  2. Implement `useEffect` calling `getTestEligibility(attemptId)` on mount when `attemptType === 'test'`.
  3. If `can_attempt === false` or `eligible === false`:
     - Mount `<QuotaExhaustedDialog>` or in-place denial alert.
     - Block question selection and submit button.
  4. Intercept 403 `QUOTA_EXHAUSTED` responses and route to the quota dialog with `examType = eligibility?.testMode || attemptMode`.
- **Dependencies:** `getTestEligibility` in `src/lib/freemiumApi.js`, `QuotaExhaustedDialog.jsx`.
- **Acceptance Criteria:** A user visiting a test with exhausted allowance is blocked with an explanatory denial modal.

### Phase 4C.2: Attempt Badging & Mode Branding (Board Mock Exam)
- **Target File:** `src/components/LMS/tests/TestAttemptScreen.jsx`
- **Objective:** Provide clear visual feedback of the attempt number, retake status, and official board mock branding.
- **Tasks:**
  1. Import and mount `<AttemptNumberBadge />` in the sticky header alongside the countdown timer chip.
  2. Pass `attemptNumber`, `maxAttempts`, `isRetake`, and `mode` from normalized eligibility data.
  3. When `attemptMode === 'mock'` or `eligibility?.testMode === 'mock'`:
     - Set header title to `"Board Mock Exam"`.
     - Apply amber tone (`#fef3c7`, `#92400e`, `#fde68a`) to the timer chip.
  4. When `attemptMode === 'test'`:
     - Keep `"Formal Test"` with standard blue timer chip styling (`#dbeafe`, `#1d4ed8`).
- **Dependencies:** `AttemptNumberBadge.jsx`, `freemiumUiHelpers.mjs`.
- **Acceptance Criteria:** Badges correctly show `Attempt 1 of 1`, `Retake 2 of 3`, or unlimited paid status with accessible contrast.

### Phase 4C.3: Attempt ID Propagation & Storage Resiliency
- **Target File:** `src/components/LMS/tests/TestAttemptScreen.jsx`
- **Objective:** Propagate authoritative attempt IDs and fail safe if browser session storage is wiped.
- **Tasks:**
  1. Initialize `activeAttemptId` from `eligibility.activeAttemptId` if present.
  2. In `persistQuestionProgress`, capture `response.data.attempt_id` on first save and forward `attempt_id: activeAttemptId` on all subsequent saves.
  3. In `submitAttempt`, forward `attempt_id: activeAttemptId`.
  4. When `sessionStorage` has no `attemptData` on load:
     - Check `getTestEligibility(attemptId)`.
     - If test is completed, redirect to `/lms/tests/test/${attemptId}/result`.
     - If attempt is active but question list is missing, render an actionable notice directing student back to Tests Hub, preventing attempt corruption or fake state.
- **Dependencies:** Backend `TestController::saveFormalTestQuestionProgressForLms`.
- **Note on Full Attempt Recovery:** Full question/answer reconstruction across storage wipes is a documented backend dependency (requires a new backend endpoint).

### Phase 4C.4: Concurrency & 409 Already-Submitted Exception Handling
- **Target File:** `src/components/LMS/tests/TestAttemptScreen.jsx`
- **Objective:** Handle late-arriving progress saves and double-submissions safely.
- **Tasks:**
  1. In `persistQuestionProgress`, catch HTTP 409 `ATTEMPT_ALREADY_SUBMITTED` and suppress user-facing error toasts (treat as completed).
  2. In `submitAttempt`, support backend 200 replay payload without re-triggering error states.
  3. Ensure double-clicks on "Submit Test" are blocked by `isSubmitting` guard.
- **Dependencies:** Backend idempotent replay logic in `TestController::submitFormalTestForLms`.
- **Acceptance Criteria:** Late network packets after test submission do not trigger false error alerts.

### Phase 4C.5: Verification Suite & Implementation Reporting
- **Target Files:** `tests/freemiumAttemptRunner.test.mjs`, `PHASE_1E_3_A_M4_C_IMPLEMENTATION_REPORT.md`
- **Tasks:**
  1. Author 14 focused tests in `tests/freemiumAttemptRunner.test.mjs` (AR-01 through AR-14).
  2. Run the complete frontend test suite (target: 96 tests passing).
  3. Run ESLint clean on `TestAttemptScreen.jsx`.
  4. Generate and save the Phase 4C implementation report.
- **Acceptance Criteria:** 100% test pass rate, 0 ESLint errors, zero backend code changes.
