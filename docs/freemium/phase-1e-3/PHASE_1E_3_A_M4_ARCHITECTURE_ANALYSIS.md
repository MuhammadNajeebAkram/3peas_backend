# Stage 1E.3.A — Milestone 4 Current Architecture Analysis
## Test-Launch and Attempt-Runner Architecture

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M4_ARCHITECTURE_ANALYSIS.md`
- **Execution Date:** November 20, 2026
- **Target Repository (Primary):** `E:\Al-Faraabi Software\al-faraabi-website` (`thestudentstimes.git`)
- **Backend Reference (Read-Only):** `E:\Al-Faraabi Software\3peas_backend`
- **Status:** Read-Only Architectural Specification

---

## 1. Architectural Workflow Overview

The test execution lifecycle spans three consecutive phases:
```
[Tests Hub (/lms/tests)] 
       │ (router.push with searchParams: mode, scope, subjectId, bookId, offeredProgramId*)
       ▼
[Launch Screen (/lms/tests/launch)] 
       │ (pre-flight check, idempotency key generation, generation POST request)
       ▼
[Attempt Runner (/lms/tests/test/[id] OR /lms/tests/practice/[id])] 
       │ (ordered question fetch, live progress save, attempt submission)
       ▼
[Result Screen (/lms/tests/test/[id]/result OR /lms/tests/practice/[id]/result)]
```

---

## 2. Component Architecture & State Flow

### 2.1 `TestLaunchScreen.jsx`
- **Parameter Hydration:**
  - Extracts parameters from `useSearchParams()`:
    - `mode`: `"practice"`, `"test"`, `"mock"`.
    - `scope`: `"full_book"`, `"chapter"`, `"multiple_chapters"`.
    - `offeredProgramId`: numeric integer or `0` if omitted.
    - `subjectId`, `bookId`: authentic catalog identifiers.
    - `unitId`, `unitLabel`: arrays of selected chapters.
    - `questionCount`, `timeLimit`: test constraints.
- **Current Generation Request Dispatch:**
  - Evaluates `isPracticeMode = (config.mode === "practice")`.
  - Determines endpoint:
    - Practice Full Book: `/auth/tests/generate-full-book-practice-session`
    - Practice Unit: `/auth/tests/generate-selected-units-practice-session`
    - Formal Full Book: `/auth/tests/generate-full-book-formal-test`
    - Formal Unit: `/auth/tests/generate-selected-units-formal-test`
  - **Defect in Current Implementation:** Notice that `config.mode === "mock"` routes to formal test endpoints without transmitting any mock mode indicator!
- **Session Handover:**
  - Stores launch configuration and initial backend metadata in `window.sessionStorage`:
    - Key: `lms-attempt:practice:${nextId}` or `lms-attempt:test:${nextId}`.
  - Pushes route to `/lms/tests/practice/${nextId}` or `/lms/tests/test/${nextId}`.

### 2.2 `TestAttemptScreen.jsx`
- **State Initialization:**
  - Reads session metadata from `sessionStorage` (`lms-attempt:${attemptType}:${attemptId}`).
  - If missing (e.g. direct URL entry or cleared storage), immediately redirects to `/lms/tests`.
- **Question Retrieval:**
  - Does NOT receive full questions from the generation endpoint.
  - Generation endpoint returns `{ test_id, unit_ids, question_ids }`.
  - `TestAttemptScreen` queries `/auth/my-classes/get-topics-by-units/${unitId}/topics` and `/auth/my-classes/get-questions-by-topic/${topic.id}/questions`.
  - Normalizes options and scenario groups via `clusterScenarioQuestions()`.
- **Progress Saving (`persistQuestionProgress`):**
  - Practice: POST `/auth/tests/save-practice-session-question-progress` with `{ session_id, question_id, selected_option_id, time_spent_seconds, answer_shown }`.
  - Formal / Mock: POST `/auth/tests/save-formal-test-question-progress` with `{ test_id, question_id, selected_option_id, time_spent_seconds, started_at }`.
- **Attempt Submission (`submitAttempt`):**
  - Practice: POST `/auth/tests/submit-practice-session`.
  - Formal / Mock: POST `/auth/tests/submit-formal-test`.
  - Stores submission result in `sessionStorage` (`lms-attempt-result:${attemptType}:${attemptId}`).
  - Redirects to `/lms/tests/test/${attemptId}/result` or `/lms/tests/practice/${attemptId}/result`.

### 2.3 `TestResultScreen.jsx`
- **State Hydration:**
  - Reads `resultPayload` from `sessionStorage` (`lms-attempt-result:${attemptType}:${attemptId}`).
  - If null, redirects to `/lms/tests`.
- **Presentation:**
  - Renders score, accuracy percentage, correct answers, wrong answers, not attempted.
  - Action button: "Back To Tests" navigates to `/lms/tests`.

---

## 3. Confirmed Architecture Gaps

1. **Idempotency Key Missing:** Neither `TestLaunchScreen` nor the attempt runners pass `X-Idempotency-Key` headers on mutating requests.
2. **Dynamic Mock Misdirection:** Dynamic mock generation communicates identical requests to formal test generation.
3. **Synthetic ID Contamination:** `TestLaunchScreen` defaults missing `offeredProgramId` to `0` and transmits `offered_program_id: 0`, failing backend validation.
4. **Pre-flight Quota Ignorance:** The launch screen initiates generation without checking student attempt eligibility (`getTestEligibility`).
5. **No Error Recovery Modal:** Backend 403 quota exhaustion renders a generic text string rather than opening the M2 `QuotaExhaustedDialog`.
