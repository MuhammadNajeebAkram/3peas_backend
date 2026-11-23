# Stage 1E.3.A — Student LMS Source Inventory & Architecture Analysis

**Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_LMS_SOURCE_INVENTORY.md`  
**Execution Date:** 2026-11-20  
**Target Repository:** `E:\Al-Faraabi Software\al-faraabi-website` (`thestudentstimes.git`)  
**Backend Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Framework Stack:** Next.js 16.0.6 (App Router), React 19.2.0, MUI 7.3.5, NextAuth 4.24, TanStack Query 5.95  
**Status:** Planning & Read-Only Inspection  

---

## 1. Executive Summary

This document establishes the authoritative source inventory of the student-facing Learning Management System (Student LMS) in `al-faraabi-website`. It analyzes existing routes, components, API client patterns, authentication handling, and state management to design a surgical, non-destructive integration of the Laravel Freemium backend APIs.

---

## 2. Directory & Component Inventory

### 2.1 Route Architecture (`src/app/(lms)/lms/(protected)/`)

| Route Path | Type | File Location | Purpose & Current Functionality | Freemium Integration Role |
| :--- | :---: | :--- | :--- | :--- |
| `/lms/tests` | Page | `src/app/(lms)/lms/(protected)/tests/page.jsx` | Tests hub & landing page; fetches subscribed programs and recent attempts on server. | **Primary Discovery Target:** Must fetch and merge `/auth/freemium/free-resources` and `/auth/freemium/entitlements` with paid programs. |
| `/lms/tests/launch` | Page | `src/app/(lms)/lms/(protected)/tests/launch/page.jsx` | Test parameter configuration (question count, time limit, scope). | Must check quota balance and preflight eligibility before initiating test generation. |
| `/lms/tests/test/[testId]` | Page | `src/app/(lms)/lms/(protected)/tests/test/[testId]/page.jsx` | Formal test attempt runner (timer, progress save, question navigation, submit). | Must transmit `Idempotency-Key` and display attempt number badges. |
| `/lms/tests/test/[testId]/result` | Page | `src/app/(lms)/lms/(protected)/tests/test/[testId]/result/page.jsx` | Formal test submission result and detailed answer review. | Displays score, answer explanations, and retake eligibility button. |
| `/lms/tests/practice/[sessionId]` | Page | `src/app/(lms)/lms/(protected)/tests/practice/[sessionId]/page.jsx` | Practice session attempt runner (instant answer reveal, progress save). | Displays practice quota status and supports resumption with 0 extra quota. |
| `/lms/tests/practice/[sessionId]/result`| Page | `src/app/(lms)/lms/(protected)/tests/practice/[sessionId]/result/page.jsx` | Practice session result summary. | Replays idempotent results. |
| `/lms/my-classes` | Page | `src/app/(lms)/lms/(protected)/my-classes/page.jsx` | Enrolled classes and study workspaces. | Shows enrolled free and paid classes. |
| `/lms/dashboard` | Page | `src/app/(lms)/lms/(protected)/dashboard/page.jsx` | Main student dashboard. | Surfaces account tier badge (Freemium vs Paid) and quota status summary. |

---

### 2.2 Client Components Inventory (`src/components/LMS/`)

#### A. Tests Hub Components (`src/components/LMS/tests/`)
1. **`TestsPageContent.jsx` (Client Controller):**
   - Coordinates filters, dropdowns, question bank statistics, recent attempts, and test launch.
   - *Current State:* Lines 130–150 recover programs strictly from `/auth/get-user-subscribed-programs`.
   - *Modification Plan:* Retain full UI structure; augment with Freemium entitlement merging and mount the new `FreemiumQuotaBanner` component.
2. **`TestsFilterBar.jsx` (Program / Subject / Unit Selectors):**
   - Renders dropdowns for Program, Subject, Unit Scope (Full Book, Single Chapter, Multiple Chapters), Question Type, and Mode (`test`, `practice`, `mock`).
   - *Modification Plan:* Reusable as-is. Will receive combined array of paid + free programs.
3. **`GenerateTestSection.jsx` (Test Configuration & Trigger):**
   - Contains question count input, time limit display, and "Generate Test" / "Start Mock Test" / "Generate Practice Test" button.
   - *Modification Plan:* Add remaining quota indicator (e.g. "1 test remaining this week") next to the button. Disable button with helpful tooltip when quota is 0.
4. **`RecentAttemptsSection.jsx` (Attempt History Table):**
   - Renders past attempts with title, scope, mode chip, questions, score, accuracy, date, and "Try Again" button.
   - *Current State:* Table header `#` renders row index `index + 1`. "Try Again" has no `onClick` handler.
   - *Modification Plan:* Display actual backend `attempt_number` (e.g. "Attempt 1" or "Attempt 2 (Retake)"). Connect "Try Again" to `/auth/freemium/tests/{id}/eligibility`.
5. **`TestLaunchScreen.jsx` (Launch Modal / Screen):**
   - Gathers launch parameters, prepares payload, calls backend test generation endpoint, and redirects to attempt screen.
   - *Current State:* Lines 92–170 do not send `Idempotency-Key` or check quota remaining.
   - *Modification Plan:* Inject UUIDv4 `Idempotency-Key` header and payload field; display dedicated quota exhaustion modal if backend returns 403.
6. **`TestAttemptScreen.jsx` (Live Test Runner):**
   - Manages live MCQ timer, option selection autosave (`save-*-progress`), and submission (`submit-*`).
   - *Current State:* Lines 474–502 save question progress; lines 729–754 submit test.
   - *Modification Plan:* Send `Idempotency-Key` on final submission. Render "Attempt {attempt_number}" badge in top header.

#### B. Study Workspace Components (`src/components/LMS/my-class-program/`)
1. **`FederalStudyWorkspace.jsx`:**
   - Chapter-by-chapter practice and study workspace.
   - *Current State:* Generates practice sessions and formal tests via `/auth/tests/generate-selected-units-*`.
   - *Modification Plan:* Reusable as-is once the parent route permits free program selection.

---

### 2.3 Shared Services & Helpers Inventory (`src/lib/`)

1. **`src/lib/api.js` (`apiFetch`):**
   - Standard fetch wrapper supporting credentials `"include"`, base URL `${process.env.NEXT_PUBLIC_LMS_API_BASE_URL}`, error extraction, and session revocation.
   - *Evaluation:* Fully compatible with all Freemium endpoints. No alterations to `apiFetch` core required.
2. **`src/lib/lmsData.js`:**
   - Normalization helpers: `normalizeSubscribedPrograms()`, `normalizeRecentAttempts()`.
   - *Evaluation:* `normalizeSubscribedPrograms()` expects `{ data: [...] }` or `[...]`.
   - *Enhancement Plan:* Add `mergeFreemiumPrograms(paidPrograms, freeResources)` to normalize free resources into the standard program/subject schema seamlessly.
3. **`src/context/AuthContext.jsx`:**
   - Manages authenticated student session via NextAuth. Provides `user`, `status`, `logout`.
   - *Evaluation:* Completely compatible. Student ID and role (`student`) are already maintained in context.

---

## 3. Reusability Assessment & Design Guidelines

- **Zero Redesign Principle:** Do NOT replace or rewrite `TestsPageContent.jsx`, `TestAttemptScreen.jsx`, or `TestLaunchScreen.jsx`.
- **Progressive Enhancement:** Augment existing UI components with small, modular components (`FreemiumQuotaCard`, `QuotaExhaustedDialog`, `AttemptNumberBadge`).
- **Backend Authority:** The frontend must never invent authorization rules. All access decisions, quota deductions, and eligibility verdicts come strictly from the backend JSON responses.
