# Freemium Student LMS Integration Audit

**Document Reference:** `docs/freemium/cross-project-audit/FREEMIUM_STUDENT_LMS_INTEGRATION_AUDIT.md`  
**Execution Date:** 2026-11-20  
**Target Repository:** `E:\Al-Faraabi Software\al-faraabi-website` (`thestudentstimes.git`)  
**Technology Stack:** Next.js 16.0.6, React 19.2.0, MUI 7.3.5, NextAuth 4.24, TanStack Query 5.95  
**Status:** **Partially Implemented (~25%)**  

---

## 1. Executive Summary

This audit examined the student-facing learning management system (Student LMS) in `al-faraabi-website` to evaluate how well it integrates with the backend's Freemium architecture.

**Core Findings:**
1. **Execution Forms Exist:** The LMS contains well-designed test execution screens (`TestLaunchScreen.jsx`, `TestAttemptScreen.jsx`, `FederalStudyWorkspace.jsx`) that interact with the backend test generation, progress saving, and submission APIs.
2. **Freemium Discovery & Quota Visibility Are Missing:** The student frontend contains zero integration with the backend's Freemium discovery and allowance APIs (`/freemium/entitlements`, `/freemium/free-resources`, `/freemium/quota-allowances`, `/freemium/tests/{id}/eligibility`).
3. **Severe Blocker for Unsubscribed Students:** The tests landing page (`tests/page.jsx`) only queries `/auth/get-user-subscribed-programs`. If a student has no active paid subscription, this API returns an empty collection, causing the program and subject selectors to remain blank. Consequently, unsubscribed Freemium students cannot select an academic scope to launch tests or practice sessions, despite being fully eligible on the backend.
4. **Idempotency Keys Omitted:** The frontend test launch and attempt submission screens do not generate or transmit UUIDv4 `Idempotency-Key` headers or payload fields.
5. **Attempt Number Not Rendered:** The backend's `GET /web_api/auth/tests/get-recent-attempts` endpoint provides accurate `attempt_number` and test modes, but `TestsPageContent.jsx` omits the attempt number from the mapped table state.

---

## 2. Source Code Inspection Details

### 2.1 Tests Hub Page (`src/app/(lms)/lms/(protected)/tests/page.jsx`)
- Server component that fetches initial data for the tests dashboard.
- **Lines 85–125:**
  ```javascript
  const response = await apiFetch("/auth/get-user-subscribed-programs", {
    cache: "no-store",
    cookieHeader,
  });
  const programs = normalizeSubscribedPrograms(response);
  ```
- **Architectural Gap:** The page exclusively fetches *paid* subscribed programs. It never queries `GET /web_api/auth/freemium/free-resources` or `GET /web_api/auth/freemium/entitlements`. Free and Freemium students receive `programs = []`, disabling all test generation dropdowns.

### 2.2 Client Content Component (`src/components/LMS/tests/TestsPageContent.jsx`)
- Client controller for the tests dashboard.
- **Lines 512–534:** Calls `/auth/tests/get-recent-attempts?offered_program_id=...&subject_id=...`.
- **Lines 76–95 (`createRecentAttemptsFromResponse`):**
  Maps `id`, `attemptType`, `title`, `scopeType`, `mode`, `questions`, `attemptedQuestions`, `score`, `accuracy`, and `submittedAt`.
- **Gap:** Completely ignores `attempt_number`, `is_retake`, and `test_id` returned by the backend. Students cannot see which attempt number they completed.

### 2.3 Test Launch Screen (`src/components/LMS/tests/TestLaunchScreen.jsx`)
- Configuration and trigger screen for formal tests, mock tests, and practice sessions.
- **Lines 98–105:** Successfully maps endpoints:
  - Practice (Full Book): `/auth/tests/generate-full-book-practice-session`
  - Practice (Selected Units): `/auth/tests/generate-selected-units-practice-session`
  - Formal (Full Book): `/auth/tests/generate-full-book-formal-test`
  - Formal (Selected Units): `/auth/tests/generate-selected-units-formal-test`
- **Gaps:**
  - Does NOT call `GET /web_api/auth/freemium/tests/{testId}/eligibility` prior to launching.
  - Does NOT transmit `idempotency_key` or `Idempotency-Key` header.
  - Does NOT display the student's remaining quota allowance or reset timer.

### 2.4 Test Attempt & Question Persistence (`src/components/LMS/tests/TestAttemptScreen.jsx`)
- Handles timer countdown, single question persistence, and test finalization.
- **Lines 474–502:** Correctly posts single question progress to `/auth/tests/save-formal-test-question-progress` or `/auth/tests/save-practice-session-question-progress`.
- **Lines 729–754:** Correctly posts submission to `/auth/tests/submit-formal-test` or `/auth/tests/submit-practice-session`.
- **Gaps:**
  - No `Idempotency-Key` header sent with submissions.
  - No attempt number badge displayed during the test attempt.

### 2.5 Federal Study Workspace (`src/components/LMS/my-class-program/FederalStudyWorkspace.jsx`)
- Alternative chapter-by-chapter practice interface for students.
- **Lines 61–64:** Generates practice or formal tests directly via `/auth/tests/generate-selected-units-*`.
- **Gap:** Relies entirely on `program` object passed from parent. If the student has no paid subscription for the program, the parent route restricts access.

---

## 3. Evaluation of the 12 Student Capabilities

| # | Student Capability | LMS Route | LMS Component / File | Backend API Consumed | Integration Status | Findings & Identified Gaps |
| :-: | :--- | :--- | :--- | :--- | :---: | :--- |
| **1** | **View Access Status & Entitlements** | `/lms/profile`, `/lms/dashboard` | `DashboardContent.jsx` | `/auth/get-user-subscribed-programs` | **Missing** | Only shows paid subscriptions. Never calls `/freemium/entitlements`. Free campaign access is completely invisible. |
| **2** | **Distinguish Free vs Paid Resources** | `/lms/my-classes`, `/lms/tests` | `FederalStudyWorkspace.jsx` | None | **Missing** | No badges, icons, or indicators distinguishing legacy free (`is_free = 1`), campaign-free, or paid resources. |
| **3** | **Discover Freemium Resources** | `/lms/tests` | `tests/page.jsx` | `/auth/get-user-subscribed-programs` | **Missing** | Never calls `/freemium/free-resources`. Free students receive empty programs array and cannot browse free chapters/subjects. |
| **4** | **View Quota Allowances & Balances** | `/lms/tests` | `TestsPageContent.jsx` | None | **Missing** | Never calls `/freemium/quota-allowances`. No UI component displays weekly formal quota, mock quota, or practice quota. |
| **5** | **Understand Quota Resets** | `/lms/tests` | None | None | **Missing** | Reset timestamp (`resets_at`) from backend is never queried or displayed. No Monday reset countdown timer. |
| **6** | **Access Permitted Topics / Questions** | `/lms/my-classes/*` | `FederalStudyWorkspace.jsx` | `/my-classes/get-topics-by-units/*` | **Partially Implemented** | Backend enforces access; frontend allows study sessions only if student has an active paid enrollment in that program. |
| **7** | **Generate Formal Tests** | `/lms/tests/launch` | `TestLaunchScreen.jsx` | `/auth/tests/generate-*-formal-test` | **Partially Implemented** | Generator works for paid students. Free students cannot select a program. No idempotency key is sent. |
| **8** | **Resume & Submit Formal Tests** | `/lms/tests/test/[id]` | `TestAttemptScreen.jsx` | `/auth/tests/save-*`, `/auth/tests/submit-*` | **Partially Implemented** | Question saving and test submission work. No attempt number is displayed; no idempotency key transmitted. |
| **9** | **Generate & Resume Practice Sessions**| `/lms/tests/practice/[id]` | `TestLaunchScreen.jsx`, `TestAttemptScreen.jsx` | `/auth/tests/generate-*-practice-session` | **Partially Implemented** | Practice session generation, resume, and submission work, but locked to paid program selection on frontend. |
| **10**| **Take Mock Tests** | `/lms/tests/launch` | `TestLaunchScreen.jsx` | `/auth/tests/generate-*-formal-test` | **Partially Implemented** | Has `mode === "mock"` check in `GenerateTestSection.jsx`, but lacks mock quota allowance pre-check and eligibility lookup. |
| **11**| **View Recent Attempts & Attempt Numbers** | `/lms/tests` | `TestsPageContent.jsx` | `/auth/tests/get-recent-attempts` | **Partially Implemented** | Table renders attempts, accuracy, and score, but completely omits the backend's `attempt_number` field. |
| **12**| **Understand Denials & Quota Exhaustion**| `/lms/tests/launch` | `TestLaunchScreen.jsx` | None | **Partially Implemented** | Errors appear in a generic red banner (`startError.message`). No dedicated quota exhaustion modal, reset timer, or upgrade link. |

---

## 4. Student LMS Audit Conclusion

The Student LMS is **partially implemented (~25%)**:
- The **execution infrastructure** (test generation, MCQ rendering, live progress saving, timer countdown, submission grading, recent attempts list) is functioning and compatible with the backend.
- The **Freemium integration layer** (entitlement discovery, free resource browsing, quota allowance visibility, eligibility checks, attempt numbering, idempotency tokens) is **completely absent**.
- Unsubscribed students are currently blocked at the threshold because the UI requires a paid subscription to populate the program selection dropdown.
