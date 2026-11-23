# Stage 1E.3.A — Student LMS Implementation Roadmap

**Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_IMPLEMENTATION_ROADMAP.md`  
**Execution Date:** 2026-11-20  
**Target Repository:** `E:\Al-Faraabi Software\al-faraabi-website` (`thestudentstimes.git`)  
**Backend Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Status:** Planning & Execution Schedule  

---

## 1. Overview & Phasing Strategy

The integration of Freemium into the Student LMS is organized into **6 sequential, testable milestones**. Each milestone targets a distinct functional layer, ensuring zero disruption to existing paid student flows and allowing incremental verification at each stage.

```
┌────────────────────────────────────────────────────────┐
│ Milestone 1: Client API Layer & Utilities               │
│ - freemiumApi.js, mergeFreemiumPrograms, UUIDv4 helper │
└───────────────────────────┬────────────────────────────┘
                            │
┌───────────────────────────▼────────────────────────────┐
│ Milestone 2: UI Presentation Components                │
│ - FreemiumQuotaBanner, QuotaExhaustedDialog, Badges   │
└───────────────────────────┬────────────────────────────┘
                            │
┌───────────────────────────▼────────────────────────────┐
│ Milestone 3: Tests Hub & Free Resource Discovery       │
│ - tests/page.jsx & TestsPageContent.jsx integration    │
└───────────────────────────┬────────────────────────────┘
                            │
┌───────────────────────────▼────────────────────────────┐
│ Milestone 4: Test Launch & Concurrency Protection      │
│ - TestLaunchScreen.jsx, Idempotency-Key, Quota Checks │
└───────────────────────────┬────────────────────────────┘
                            │
┌───────────────────────────▼────────────────────────────┐
│ Milestone 5: Attempt Runner & Submission Hardening     │
│ - TestAttemptScreen.jsx, submit Idempotency-Key, badges│
└───────────────────────────┬────────────────────────────┘
                            │
┌───────────────────────────▼────────────────────────────┐
│ Milestone 6: Recent Attempts & Retake Preflight        │
│ - RecentAttemptsSection.jsx, eligibility preflight     │
└────────────────────────────────────────────────────────┘
```

---

## 2. Milestone Breakdown & Technical Tasks

### Milestone 1: Client API Layer & Normalization Utilities
- **Goal:** Create the reusable network communication layer and data normalizer.
- **Files to Create / Modify:**
  - `src/lib/freemiumApi.js` (NEW)
  - `src/lib/lmsData.js` (MODIFIED: add `mergeFreemiumPrograms`)
- **Key Tasks:**
  1. Author `generateIdempotencyKey()` using native `crypto.randomUUID()`.
  2. Implement `getEntitlements()`, `getFreeResources()`, `getQuotaAllowances()`, `getTestEligibility()`, and `evaluateAccess()` wrapping `apiFetch()`.
  3. Implement `mergeFreemiumPrograms(paidPrograms, freeResources)` to normalize free catalog books into the standard program/subject/chapter hierarchy so that existing LMS selectors consume them without schema mismatch.
- **Acceptance Criteria:**
  - Service functions correctly return typed JSON when invoked.
  - Normalizer successfully combines empty paid programs with free resources into a valid program structure.

---

### Milestone 2: UI Presentation Components
- **Goal:** Build the visual components for quota balances, exhaustion dialogs, and attempt badges.
- **Files to Create:**
  - `src/components/LMS/freemium/FreemiumQuotaBanner.jsx` (NEW)
  - `src/components/LMS/freemium/QuotaExhaustedDialog.jsx` (NEW)
  - `src/components/LMS/freemium/AttemptNumberBadge.jsx` (NEW)
- **Key Tasks:**
  1. Construct `FreemiumQuotaBanner` supporting 3 quota cards (`formal_test`, `mock`, `practice`), live Monday reset countdown, and paid unlimited variant.
  2. Construct `QuotaExhaustedDialog` with clear explanation of weekly reset time in PKT, reason, and direct link to `/lms/subscribe/{slug}`.
  3. Construct `AttemptNumberBadge` for attempt number and retake status.
- **Acceptance Criteria:**
  - Components render cleanly in isolation across mobile and desktop breakpoints.
  - Zero hydration errors or unescaped HTML characters.

---

### Milestone 3: Tests Hub & Free Resource Discovery Integration
- **Goal:** Unblock unsubscribed students on `/lms/tests` so they can discover free subjects and see their quotas.
- **Files to Modify:**
  - `src/app/(lms)/lms/(protected)/tests/page.jsx`
  - `src/components/LMS/tests/TestsPageContent.jsx`
- **Key Tasks:**
  1. In `tests/page.jsx`: Add server-side fetching of `/auth/freemium/free-resources`, `/auth/freemium/entitlements`, and `/auth/freemium/quota-allowances`.
  2. Merge free resources with paid programs via `mergeFreemiumPrograms()`.
  3. In `TestsPageContent.jsx`: Mount `<FreemiumQuotaBanner />` at the top of the container.
  4. Pass merged programs to `TestsFilterBar`.
- **Acceptance Criteria:**
  - Unsubscribed free student logs in $\to$ navigates to `/lms/tests` $\to$ sees their free class/subject in dropdowns $\to$ sees quota banner showing `1/1 Formal Test`, `1/1 Mock Test`, `2/2 Practice Sessions`.
  - Paid subscriber logs in $\to$ sees all paid subjects and the "Unlimited Student Access" badge.

---

### Milestone 4: Test Launch & Concurrency Protection
- **Goal:** Enforce quota checks, send idempotency keys, and handle 403 quota exhaustion gracefully on the launch screen.
- **Files to Modify:**
  - `src/components/LMS/tests/TestLaunchScreen.jsx`
  - `src/components/LMS/tests/GenerateTestSection.jsx`
- **Key Tasks:**
  1. In `GenerateTestSection.jsx`: If `quota.remaining_tests === 0`, disable the "Generate Test" button and show a helpful tooltip.
  2. In `TestLaunchScreen.jsx`:
     - Generate a UUIDv4 idempotency key on start button click.
     - Inject `Idempotency-Key` header and payload parameter into the generation request.
     - Catch `403` or `422` quota exhaustion errors and open `<QuotaExhaustedDialog />`.
- **Acceptance Criteria:**
  - Launching a formal test consumes 1 allowance and succeeds.
  - Second attempt when quota is 0 triggers `QuotaExhaustedDialog` instead of a crash or raw error.
  - Backend `AssessmentIdempotencyRecord` stores the transmitted UUID.

---

### Milestone 5: Live Test Attempt & Submission Hardening
- **Goal:** Display attempt badges and protect test submission with idempotency keys.
- **Files to Modify:**
  - `src/components/LMS/tests/TestAttemptScreen.jsx`
- **Key Tasks:**
  1. Mount `<AttemptNumberBadge attemptNumber={attemptNumber} isRetake={isRetake} />` in the top test navigation bar.
  2. In `handleSubmit()`: Generate and send `Idempotency-Key` header and payload field.
  3. Disable submit button immediately upon first click to prevent rapid double-clicks.
- **Acceptance Criteria:**
  - Attempt screen clearly indicates "Attempt 1" or "Attempt 2 (Retake)".
  - Rapid double-clicking submit produces exactly one submission record on backend.

---

### Milestone 6: Recent Attempts & Retake Preflight
- **Goal:** Render attempt numbers in the recent history table and preflight retake eligibility on "Try Again".
- **Files to Modify:**
  - `src/components/LMS/tests/RecentAttemptsSection.jsx`
  - `src/components/LMS/tests/TestsPageContent.jsx` (`createRecentAttemptsFromResponse`)
- **Key Tasks:**
  1. Update `createRecentAttemptsFromResponse` to extract `attempt_number`, `test_id`, `is_retake`.
  2. In `RecentAttemptsSection.jsx`: Render `Attempt {item.attemptNumber}` column.
  3. Connect "Try Again" button:
     - On click, invoke `getTestEligibility(item.testId)`.
     - If allowed, navigate to test runner.
     - If denied, display toast/alert with `data.denial_reason`.
- **Acceptance Criteria:**
  - Recent attempts table shows accurate attempt numbers.
  - Clicking "Try Again" on an exhausted test displays "Maximum permitted attempts reached" without launching an illegal attempt.

---

## 3. Dependency Graph & Sequencing

```
Milestone 1 (freemiumApi.js) ───────────► Milestone 2 (UI Components)
             │                                        │
             ▼                                        ▼
Milestone 3 (Tests Hub & Discovery) ───► Milestone 4 (Launch & Idempotency)
                                                      │
                                                      ▼
                                         Milestone 5 (Attempt & Submit)
                                                      │
                                                      ▼
                                         Milestone 6 (Recent Attempts & Retakes)
```
Each milestone can be built and reviewed independently, ensuring total safety and incremental verification.
