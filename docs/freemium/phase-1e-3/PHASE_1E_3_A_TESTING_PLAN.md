# Stage 1E.3.A — Quality Assurance & Testing Plan

**Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_TESTING_PLAN.md`  
**Execution Date:** 2026-11-20  
**Target Applications:** Student LMS Next.js (`al-faraabi-website`) & Laravel Backend (`3peas_backend`)  
**Status:** Planning & Verification Protocol  

---

## 1. Testing Strategy Overview

The testing strategy validates that the Student LMS properly integrates all backend Freemium capabilities without regressions to existing paid subscriber workflows.

### Testing Dimensions:
1. **Data Normalization & Helpers:** Verify UUIDv4 generation, program merging, and quota countdown calculations.
2. **Student Journey & State Verification:** Test complete learning journeys for Free Students, Active Paid Subscribers, and Expired Subscribers.
3. **Quota Enforcement & Boundary Testing:** Test first attempt, retake, quota exhaustion, and Pakistan Monday resets.
4. **Idempotency & Concurrency:** Verify protection against rapid double-clicks on test generation and submission.
5. **Cross-Browser & Responsive UI Verification:** Test visual layout across desktop, tablet, and mobile viewports.

---

## 2. Test Scenarios & Verification Matrix

### 2.1 Free Unsubscribed Student Scenarios

| Test Case ID | Scenario Description | Initial State | User Action | Expected Frontend & Backend Behavior | Pass Criteria |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **TC-FREE-01** | **Free Resource Discovery** | Student has no paid subscriptions (`user_subscriptions` is empty). | Navigates to `/lms/tests`. | Page calls `/auth/freemium/free-resources` and `/auth/freemium/quota-allowances`. Program and subject dropdowns populate with available free books. | Selectors are populated; no empty state error; "Freemium" badge visible. |
| **TC-FREE-02** | **Quota Banner Visibility** | Free student on `/lms/tests`. | Views top of page. | `FreemiumQuotaBanner` displays: Formal Tests (1/1), Mock Tests (1/1), Practice Sessions (2/2), and a live countdown to Monday 00:00 PKT. | Quotas match backend allowance API; reset time accurately reflects Asia/Karachi boundary. |
| **TC-FREE-03** | **First Formal Test Generation** | Remaining formal test quota = 1. | Configures 10 MCQs and clicks "Generate Test". | Client sends `Idempotency-Key` header; test generates successfully; redirect to attempt screen; remaining quota becomes 0. | Test launches; backend `freemium_quota_counters` increments to 1; attempt screen loads. |
| **TC-FREE-04** | **Autosave Progress Quota Safety** | Active formal test attempt in progress. | Answers question 1, 2, and 3. | Autosaves to `/auth/tests/save-formal-test-question-progress`. Subsequent saves consume 0 extra quota. | Progress saved without quota deductions; timer continues countdown. |
| **TC-FREE-05** | **Test Submission & Idempotency** | 10 MCQs answered. | Clicks "Submit Test" rapidly twice. | Only one submission request fires; `Idempotency-Key` protects payload; result screen displays score and answers. | Exactly one attempt submitted in database; score calculated; results rendered. |
| **TC-FREE-06** | **Quota Exhaustion Handling** | Remaining formal test quota = 0. | Attempts to generate another formal test. | UI disables button with tooltip OR client catches 403 and opens `<QuotaExhaustedDialog />`. | Clear modal explains weekly quota is reached, shows Monday reset time, and offers upgrade link. |
| **TC-FREE-07** | **Mock Test Independence** | Formal test quota = 0; Mock quota = 1. | Selects mode "mock" and clicks "Start Mock Test". | Mock test launches successfully using mock quota (`exam_type = 'mock'`). Formal test quota remains 0. | Mock attempt starts; independent quota consumed; no collision with formal test quota. |
| **TC-FREE-08** | **Practice Session Generation & Resume** | Practice quota = 2. | Generates practice session, leaves, and returns. | Resuming practice session consumes 0 quota. Practice session answer reveal operates smoothly. | Resumes session seamlessly; no quota deduction on resume. |
| **TC-FREE-09** | **Recent Attempts Attempt Number** | Completed attempt 1 and retake attempt 2. | Navigates to `/lms/tests`. | Recent attempts table displays "Attempt 1" and "Attempt 2 (Retake)" with appropriate mode chips. | `attempt_number` column clearly renders actual attempt numbers instead of generic row index. |
| **TC-FREE-10** | **Retake Eligibility Pre-Check** | Test attempt completed; retakes allowed = false. | Clicks "Try Again" in recent attempts. | Preflights `GET /auth/freemium/tests/{id}/eligibility`. Backend returns `can_attempt: false`. | Alert informs student: "Retakes are not permitted for this test"; illegal launch prevented. |

---

### 2.2 Paid Subscriber Scenarios (Non-Regression)

| Test Case ID | Scenario Description | Initial State | User Action | Expected Frontend & Backend Behavior | Pass Criteria |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **TC-PAID-01** | **Unlimited Paid Access** | Student has active subscription for FSC Part 1. | Navigates to `/lms/tests`. | Banner renders "⭐ Unlimited Student Access". No quota limits displayed for subscribed program subjects. | All paid subjects available; test generation has no quota constraints. |
| **TC-PAID-02** | **Cross-Scope Freemium Fallback** | Paid for FSC Part 1; accesses Matric Physics (free catalog). | Selects Matric Physics. | Normal Freemium quota rules apply to the unsubscribed subject without error or distortion. | Clear scoping: unlimited inside paid subscription, Freemium outside. |

---

### 2.3 Expired Subscriber Scenarios

| Test Case ID | Scenario Description | Initial State | User Action | Expected Frontend & Backend Behavior | Pass Criteria |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **TC-EXP-01** | **Expired Subscription Fallback** | Subscription expired yesterday. | Navigates to `/lms/tests`. | Falls through to Freemium tier seamlessly. Quota banner displays 1 formal test, 1 mock, 2 practice sessions. | Zero crashes; clean fallback to Freemium quotas. |

---

## 3. Network & Edge-Case Verifications

- **401 Unauthorized:** If student session expires mid-test, `apiFetch` handles session revocation and cleanly redirects to `/lms/login?reason=session_revoked`.
- **404 Not Found:** Opening an unowned or deleted test displays "Test not found or access denied."
- **409 Conflict:** Replaying test generation with different payload returns 409 and prompts user to generate a fresh test.
- **Offline / Network Interruption:** Autosave shows "Unable to save question progress right now" retry alert without clearing student answers.

---

## 4. Acceptance Criteria Checklist

- [ ] All 10 Free Student test cases pass.
- [ ] All 2 Paid Subscriber test cases pass without regressions.
- [ ] Expired subscriber fallback operates cleanly.
- [ ] Next.js production build (`npm run build`) compiles with zero lint or TypeScript/JSX syntax errors.
- [ ] All 460 backend tests continue to pass with 100% success.
