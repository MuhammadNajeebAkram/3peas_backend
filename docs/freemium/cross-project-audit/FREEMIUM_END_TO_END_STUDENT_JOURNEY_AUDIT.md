# End-to-End Student Journey Audit

**Document Reference:** `docs/freemium/cross-project-audit/FREEMIUM_END_TO_END_STUDENT_JOURNEY_AUDIT.md`  
**Execution Date:** 2026-11-20  
**Target Systems:** Laravel Backend (`3peas_backend`), Admin Panel (`tst-admin`), Student LMS (`al-faraabi-website`)  
**Status:** **Broken at Discovery & Allowance Steps (LMS Frontend Gap)**  

---

## 1. Executive Summary

This document evaluates the 12-step end-to-end student learning journey across the The Students Times ecosystem under the Freemium model. It assesses whether a student without an active paid subscription can successfully register, discover free educational material, check their quota, take practice and formal tests, and view their history. It also assesses whether administrators can configure the underlying policies.

**Core Audit Finding:**  
While the backend fully supports every step with robust atomicity and 4-tier entitlement resolution, the **student journey breaks at Step 2 and Step 3** in the frontend. Because `al-faraabi-website` only loads paid subscriptions from `/auth/get-user-subscribed-programs`, a non-paying Freemium student is met with empty program dropdowns and cannot start Step 5 (Formal Test Generation), Step 8 (Mock Tests), or Step 9 (Practice Sessions) through the primary tests interface.

---

## 2. Step-by-Step Student Journey Evaluation

### Step 1: Student Logs In
- **Expected Behavior:** Student logs into LMS via email/password or Google OAuth; session cookie is issued.
- **Actual Status:** **Fully Functional (100%)**
- **Evidence:** `al-faraabi-website/src/app/(lms)/lms/login/page.jsx` uses NextAuth with `/auth/login` on backend. Successful login sets `laravel_session` and token cookies.

### Step 2: Student Sees Current Access Status
- **Expected Behavior:** Dashboard informs student of their account tier (e.g., "Freemium Plan" vs "Subscribed to FSC Pre-Medical"), active campaigns, and available benefits.
- **Actual Status:** **Unavailable (0%)**
- **Evidence:** `DashboardContent.jsx` only displays enrolled paid classes. If unsubscribed, dashboard displays "You have not enrolled in any classes yet" with an upsell to paid subscriptions. Backend endpoint `GET /web_api/auth/freemium/entitlements` is never invoked.

### Step 3: Student Discovers Free Resources
- **Expected Behavior:** Student browses available free classes, subjects, books, and question banks unlocked under legacy free flags (`is_free = 1`) or active Freemium campaigns.
- **Actual Status:** **Unavailable (0%)**
- **Evidence:** `tests/page.jsx` calls `/auth/get-user-subscribed-programs`. It never queries `GET /web_api/auth/freemium/free-resources`. As a result, the student sees no programs or subjects to choose from.

### Step 4: Student Opens an Allowed Learning Resource
- **Expected Behavior:** Student accesses chapters and topics for a free subject.
- **Actual Status:** **Partially Functional (30%)**
- **Evidence:** Backend `UnitsController` and `TopicsController` enforce the 4-tier entitlement resolver. However, in `FederalStudyWorkspace.jsx`, the student must already have a valid `program` object injected from the parent container. Free students without paid subscriptions cannot navigate to this workspace through standard menus.

### Step 5: Student Generates a Formal Test
- **Expected Behavior:** Student selects units or full book, specifies MCQ parameters, and generates a formal test within their weekly allowance.
- **Actual Status:** **Partially Functional (40%)**
- **Evidence:**
  - *If student is paid:* Fully works via `TestLaunchScreen.jsx` $\to$ `/auth/tests/generate-*-formal-test`.
  - *If student is free:* Button is disabled due to empty program dropdown.
  - *Idempotency:* Frontend does not send `Idempotency-Key` headers.

### Step 6: Student Completes and Submits the Test
- **Expected Behavior:** Student answers MCQs, time spent is tracked, answers are autosaved on every selection, and test is submitted with immediate grading.
- **Actual Status:** **Fully Functional (90% for active tests)**
- **Evidence:** `TestAttemptScreen.jsx` calls `/auth/tests/save-formal-test-question-progress` and `/auth/tests/submit-formal-test`. The backend handles attempt creation, quota deduction, and score calculation seamlessly. Minor gap: no `Idempotency-Key` sent with submission.

### Step 7: Student Views Result and Attempt History
- **Expected Behavior:** Submission shows score, accuracy, answer review, and attempt number (e.g. "Attempt 1 of 2"). Recent attempts table lists past attempts.
- **Actual Status:** **Partially Functional (50%)**
- **Evidence:** Immediate results screen renders score and review. Recent attempts table in `TestsPageContent.jsx` displays past attempts, but **omits `attempt_number`**, leaving students unsure whether they used their first attempt or a retake.

### Step 8: Student Uses Available Mock-Test Allowance
- **Expected Behavior:** Student sees a separate weekly mock test quota (e.g. 1/week), selects a published official mock test, and attempts it without deducting formal test quota.
- **Actual Status:** **Partially Functional (30%)**
- **Evidence:** Backend correctly isolates mock quota (`exam_type = 'mock'`). Frontend has a `mode === "mock"` branch in `GenerateTestSection.jsx`, but does not pre-check `/freemium/tests/{id}/eligibility` or display mock quota balances.

### Step 9: Student Generates and Resumes a Practice Session
- **Expected Behavior:** Student generates a practice session, views instant explanations, resumes unfinished sessions with zero extra quota deduction, and submits.
- **Actual Status:** **Partially Functional (50%)**
- **Evidence:** `TestAttemptScreen.jsx` supports practice mode (instant answer reveal). However, free students cannot generate practice sessions through the main test generator due to the program selector blocker.

### Step 10: Student Sees Remaining Quota and Reset Information
- **Expected Behavior:** A clear quota card shows: "Formal Tests: 1 remaining this week. Resets Monday at 00:00 PKT. Practice Sessions: 2 remaining today."
- **Actual Status:** **Unavailable (0%)**
- **Evidence:** Zero UI components call `GET /web_api/auth/freemium/quota-allowances`. No quota badges, remaining counters, or reset countdown timers exist in `al-faraabi-website`.

### Step 11: Student Receives Appropriate Feedback When Quota is Exhausted
- **Expected Behavior:** When a quota is exhausted, the UI informs the student: "Weekly test quota reached. Your quota resets on Monday at 00:00 PKT. Upgrade to Unlimited for unrestricted access."
- **Actual Status:** **Partially Functional (20%)**
- **Evidence:** The backend correctly throws 403/422 with descriptive denial reasons (`"Weekly formal test quota (1) exhausted for this window."`). However, `TestLaunchScreen.jsx` merely displays a plain red error string without reset countdowns or dedicated quota modal dialogs.

### Step 12: Paid Access Continues Without Interference
- **Expected Behavior:** Paid subscribers bypass Freemium quota limits, enjoy unlimited tests within their scope, and can still access Freemium resources outside their scope.
- **Actual Status:** **Fully Functional (100%)**
- **Evidence:** Verified by backend `UnifiedEntitlementResolver` and `StudentClassSubscriptionAccessTest` (158 passing tests). Paid students retain full access.

---

## 3. Administrative Journey Evaluation

Can an administrator configure the corresponding Freemium policies through the Admin Panel (`tst-admin`)?
- **Create a campaign with dates and priority:** **NO** (No UI in `tst-admin`).
- **Create academic access rules (allow/deny):** **NO** (No UI in `tst-admin`).
- **Configure formal, mock, and practice quotas:** **NO** (No UI in `tst-admin`).
- **Issue or revoke student grants:** **NO** (No UI in `tst-admin`).
- **Inspect student consumption and audit logs:** **NO** (No UI in `tst-admin`).
- **View Freemium analytics summary:** **NO** (No UI in `tst-admin`).

---

## 4. Journey Verdict & Gap Summary

| Journey Track | Steps Available | Steps Blocked / Missing | Functional Rating |
| :--- | :---: | :---: | :---: |
| **Student Journey (Paid Subscriber)** | 10 / 12 | 2 / 12 (Quota cards not needed, but nice to have) | **Good (83%)** |
| **Student Journey (Freemium / Unsubscribed)** | 2 / 12 | 10 / 12 (Blocked at Program Discovery & Quotas) | **Broken (17%)** |
| **Administrative Policy Journey** | 0 / 6 | 6 / 6 (No UI exists) | **Non-Existent (0%)** |
