# Stage 1E.3.A — UI Component Architecture & Design Plan

**Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_UI_COMPONENT_PLAN.md`  
**Execution Date:** 2026-11-20  
**Target Repository:** `E:\Al-Faraabi Software\al-faraabi-website` (`thestudentstimes.git`)  
**Design System:** Material-UI (MUI v7), Emotion, Responsive Grid / Flexbox  
**Status:** Planning & Architectural Specification  

---

## 1. Design Principles & Goals

1. **Non-Destructive Integration:** Preserve all existing layout grids, typography, and styling patterns. Do not rewrite working pages.
2. **Clear Access Transparency:** Enable students to instantly see their remaining weekly test and practice allowances without guessing.
3. **Graceful Degradation & Friendly Upsells:** When a quota is exhausted or access is restricted, provide clear explanations, reset countdowns, and a clean path to upgrade to unlimited access.
4. **Resilient Network Protection:** Prevent double clicks and race conditions by generating UUIDv4 idempotency keys before firing mutating assessment actions.

---

## 2. New Components Specification (`src/components/LMS/freemium/`)

### 2.1 `src/lib/freemiumApi.js` (Client API Service)
A dedicated, typed service module encapsulating all Freemium API calls and idempotency helpers.
- **Functions:**
  - `getEntitlements(cookieHeader)`: Calls `/auth/freemium/entitlements`.
  - `getFreeResources({ classId, boardId, subjectId, page }, cookieHeader)`: Calls `/auth/freemium/free-resources`.
  - `getQuotaAllowances(examType, cookieHeader)`: Calls `/auth/freemium/quota-allowances`.
  - `getTestEligibility(testId, cookieHeader)`: Calls `/auth/freemium/tests/${testId}/eligibility`.
  - `evaluateAccess(payload, cookieHeader)`: Calls `/auth/freemium/evaluate-access`.
  - `generateIdempotencyKey()`: Generates UUIDv4 using `crypto.randomUUID()` with fallback.
  - `mergeFreemiumPrograms(paidPrograms, freeResources)`: Merges free resource books into the existing program/subject collection schema so that unsubscribed students see available subjects seamlessly.

---

### 2.2 `FreemiumQuotaBanner.jsx` (Quota Allowance Dashboard)
A prominent, visually appealing banner mounted at the top of the Tests Hub (`/lms/tests`) and Test Launch screens.
- **Props:**
  - `quotas`: Array of quota allowances from `/auth/freemium/quota-allowances`.
  - `isPaid`: Boolean indicating if the student holds a paid subscription.
  - `loading`: Boolean indicating fetch status.
  - `onRefresh`: Callback to re-fetch allowances.
- **Visual Design:**
  - **Paid Students:** Displays a sleek purple/indigo card: *"⭐ Unlimited Student Access — All formal tests, mocks, and practice sessions are unrestricted."*
  - **Freemium Students:** Displays a 3-column card grid:
    1. **Formal Tests:** `X / Y remaining this week` (Chip: "Campaign" or "Weekly Free").
    2. **Mock Tests:** `X / Y remaining this week` (Chip: "Weekly Mock").
    3. **Practice Sessions:** `X / Y remaining this week/today` (Chip: "Practice").
  - **Reset Notice:** Live countdown timer: *"Weekly quotas reset on Monday at 00:00 PKT (in X days, Y hours)"* derived from `resets_at`.
- **States:**
  - *Full Quota:* Green badge (`1/1 available`).
  - *Partially Used:* Orange badge (`1/2 available`).
  - *Exhausted:* Red badge (`0/1 remaining · Resets Monday`).

---

### 2.3 `QuotaExhaustedDialog.jsx` (Exhaustion & Upgrade Modal)
A modal dialog triggered when a student attempts to generate a test or practice session with zero remaining quota, or receives a `403 Forbidden` from the backend.
- **Props:**
  - `open`: Boolean.
  - `onClose`: Function.
  - `examType`: `'formal_test'`, `'mock'`, or `'practice'`.
  - `resetsAt`: ISO string timestamp.
  - `denialReason`: String from backend (`data.message` or `data.denial_reason`).
  - `upgradeUrl`: String link to subscription page (e.g. `/lms/subscribe/fsc-pre-medical-part-1` or `/lms/pricing`).
- **Content:**
  - Graphic: Illustrated hourglass or lock icon.
  - Title: *"Weekly {Exam Type} Quota Reached"*
  - Body: Explain that the free weekly allowance has been fully utilized. Display the exact reset date/time in Pakistan Local Time (`Asia/Karachi`).
  - Action 1: *"Upgrade for Unlimited Tests"* (Primary button linking to `upgradeUrl`).
  - Action 2: *"Okay, Got It"* (Secondary button to dismiss).

---

### 2.4 `AttemptNumberBadge.jsx` (Attempt & Retake Indicator)
A compact badge rendered in the Test Attempt runner header, Result screen, and Recent Attempts table.
- **Props:**
  - `attemptNumber`: Integer (e.g. `1`, `2`).
  - `maxAttempts`: Integer or `null` (if unlimited).
  - `isRetake`: Boolean.
  - `mode`: String (`'test'`, `'mock'`, `'practice'`).
- **Visual Presentation:**
  - If First Attempt: Blue chip: `Attempt 1`.
  - If Retake: Purple chip: `Retake (Attempt 2 of {max})`.
  - If Mock Test: Teal chip: `Official Mock · Single Attempt`.

---

## 3. Enhancements to Existing Components

### 3.1 Tests Hub Page (`src/app/(lms)/lms/(protected)/tests/page.jsx`)
- **Server Data Fetching Enhancement:**
  ```javascript
  // 1. Fetch paid programs
  const paidResponse = await apiFetch("/auth/get-user-subscribed-programs", { cookieHeader });
  const paidPrograms = normalizeSubscribedPrograms(paidResponse);

  // 2. Fetch free resources and entitlements
  const [freeRes, entitlementsRes, quotaRes] = await Promise.allSettled([
    apiFetch("/auth/freemium/free-resources?per_page=50", { cookieHeader }),
    apiFetch("/auth/freemium/entitlements", { cookieHeader }),
    apiFetch("/auth/freemium/quota-allowances", { cookieHeader }),
  ]);

  // 3. Merge programs so unsubscribed students have program/subject options
  const mergedPrograms = mergeFreemiumPrograms(
    paidPrograms,
    freeRes.status === "fulfilled" ? freeRes.value?.data : []
  );

  // 4. Pass initial quotas and entitlement metadata to client content
  ```
- **Fallback Guarantee:** If the Freemium API is unavailable or returns an error, the page gracefully falls back to `paidPrograms` without crashing.

### 3.2 Client Content (`src/components/LMS/tests/TestsPageContent.jsx`)
- Mount `<FreemiumQuotaBanner quotas={quotas} isPaid={isPaid} />` above the filters.
- When `quotas` indicate 0 remaining for formal test or mock, disable the "Generate Test" button and show a helpful tooltip: *"Weekly quota exhausted. Resets Monday."*
- Maintain a local state `quotaExhaustedModalOpen` to trigger the dialog if backend rejects generation.

### 3.3 Test Launch Screen (`src/components/LMS/tests/TestLaunchScreen.jsx`)
- In `handleStart()`:
  - Generate a UUIDv4 idempotency key: `const idempotencyKey = generateIdempotencyKey();`.
  - Include `idempotency_key: idempotencyKey` in the request body.
  - Include headers: `{ 'Idempotency-Key': idempotencyKey }`.
  - Wrap network error in try/catch: if `error.status === 403` or `error.status === 422` with a quota reason, open `QuotaExhaustedDialog` instead of showing a raw error string.

### 3.4 Test Attempt Screen (`src/components/LMS/tests/TestAttemptScreen.jsx`)
- In top navigation bar: Render `<AttemptNumberBadge attemptNumber={attemptNumber} isRetake={isRetake} />`.
- In `handleSubmit()`: Include a generated UUIDv4 `Idempotency-Key` header and payload field to prevent accidental double submissions on rapid clicking.

### 3.5 Recent Attempts Table (`src/components/LMS/tests/RecentAttemptsSection.jsx`)
- In table columns:
  - Add `Attempt #` column rendering `item.attemptNumber ? `Attempt ${item.attemptNumber}` : '-'`.
- In "Try Again" button:
  - Attach `onClick` handler that calls `GET /auth/freemium/tests/{item.testId}/eligibility`.
  - If `can_attempt === true`: Navigate to the test retake URL.
  - If `can_attempt === false`: Show a snackbar or alert with `data.denial_reason` (e.g. *"Maximum permitted attempts (2) reached for this test."*).

---

## 4. Responsive & Accessibility Specifications

- **Desktop (>= 1200px):** 3-column quota grid, full attempt table with all metadata.
- **Tablet (768px – 1199px):** 2-column quota grid, condensed table with horizontal scroll.
- **Mobile (< 768px):** Single-column stacked quota cards, card-based attempt history items instead of wide table rows.
- **Accessibility:**
  - High-contrast colors for quota chips (WCAG AAA compliant: `#166534` on `#dcfce7`, `#1d4ed8` on `#dbeafe`, `#991b1b` on `#fee2e2`).
  - Keyboard accessible dialogs (`Tab`, `Escape` to close).
  - ARIA live region for live reset countdown timer.
