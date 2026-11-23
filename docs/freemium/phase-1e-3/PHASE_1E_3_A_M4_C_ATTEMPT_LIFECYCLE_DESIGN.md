# Stage 1E.3.A — Milestone 4 Phase 4C Attempt Lifecycle Design
## Complete Assessment Lifecycle, Quota Accounting & Academic Policies (Clarified Revision)

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M4_C_ATTEMPT_LIFECYCLE_DESIGN.md`
- **Revision Date:** November 20, 2026 (Clarified Revision)
- **Primary Repository:** `E:\Al-Faraabi Software\al-faraabi-website` (Student LMS Frontend)
- **Reference Repository (Read-Only):** `E:\Al-Faraabi Software\3peas_backend` (Laravel Backend)
- **Scope:** Phase 4C Planning & Source Inspection ONLY

---

## 1. Assessment Lifecycle Stages & Quota Accounting

The assessment lifecycle is governed by an authoritative two-tier ledger system:
1. **Generation Counter (`freemium_quota_counters`):** Regulates how many new tests a student can generate per weekly rolling window (`max_tests`, `used_tests`, `remaining_tests`).
2. **Attempt Ledger (`freemium_quota_consumptions`):** Records individual attempt starts (`attempt_start`, `retake_start`) and validates per-test limits (`max_attempts_per_test`, `allow_retakes`).

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│ STAGE 1: TEST GENERATION                                                         │
│ Trigger: User clicks "Start Test" on TestLaunchScreen                            │
│ Endpoint: POST /web_api/auth/tests/generate-*                                    │
│ Counter Action: Decrements weekly remaining_tests (for Freemium)                 │
│ Quota Event: 'generation' (or 'practice_generation')                             │
│ Entity Created: Test (test_mode: 'test'|'mock', test_source: 'system')           │
└────────────────────────────────────────┬─────────────────────────────────────────┘
                                         │
                                         ▼
┌──────────────────────────────────────────────────────────────────────────────────┐
│ STAGE 2: PREFLIGHT RUNNER MOUNT                                                  │
│ Trigger: TestAttemptScreen mounts at /lms/tests/test/[testId]                    │
│ Endpoint: GET /web_api/auth/freemium/tests/{testId}/eligibility                 │
│ Counter Action: None (Read-Only)                                                 │
│ Decision: Validates can_attempt, captures active_attempt_id if present           │
└────────────────────────────────────────┬─────────────────────────────────────────┘
                                         │
                                         ▼
┌──────────────────────────────────────────────────────────────────────────────────┐
│ STAGE 3: ATTEMPT INITIALIZATION (FIRST ANSWER)                                   │
│ Trigger: Student selects first MCQ option                                       │
│ Endpoint: POST /web_api/auth/tests/save-formal-test-question-progress            │
│ Counter Action: ZERO IMPACT ON WEEKLY TEST COUNTERS                              │
│ Quota Event: 'attempt_start' (attempt 1) OR 'retake_start' (attempt > 1)          │
│ Entity Created: TestAttempt (status: 'in_progress', started_at: now())           │
│ Response: Returns attempt_id for client to store and propagate                   │
└────────────────────────────────────────┬─────────────────────────────────────────┘
                                         │
                                         ▼
┌──────────────────────────────────────────────────────────────────────────────────┐
│ STAGE 4: INCREMENTAL QUESTION PROGRESS                                           │
│ Trigger: Student answers question, navigates, or flags                           │
│ Endpoint: POST /web_api/auth/tests/save-formal-test-question-progress            │
│ Payload: Forwards captured attempt_id                                            │
│ Counter Action: None                                                             │
│ Entity Updated: TestAttemptQuestion (selected_option_id, time_spent)             │
│ Security: Backend verifies attempt_id matches user_id and test_id                │
└────────────────────────────────────────┬─────────────────────────────────────────┘
                                         │
                                         ▼
┌──────────────────────────────────────────────────────────────────────────────────┐
│ STAGE 5: FINALIZATION & SUBMISSION                                               │
│ Trigger: Student clicks "Submit Test" OR timer reaches 00:00:00                  │
│ Endpoint: POST /web_api/auth/tests/submit-formal-test                            │
│ Counter Action: None                                                             │
│ Entity Updated: TestAttempt (status: 'submitted', score, accuracy)              │
│ Idempotent Replay: If already submitted, replays result data with 200 OK         │
└──────────────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Invariant Policies & Rules

### 2.1 Separation of Generation Quota from Attempt Quota
- **Weekly Generation Limit:** Generating a test consumes 1 unit from the student's weekly quota (`used_tests += 1`).
- **Retake Exemption:** Starting a retake (Attempt 2, Attempt 3, etc.) **DOES NOT** consume weekly test generation quota. Retakes only count against `max_attempts_per_test`.
- **Question Saves Exemption:** Saving individual MCQ options (`saveFormalTestQuestionProgress`) **NEVER** touches weekly quota counters.

### 2.2 Retake Rules & `max_attempts_per_test`
- `max_attempts_per_test` is inclusive of the initial attempt:
  - If `max_attempts_per_test = 1`: The student may complete exactly one attempt. Retakes are forbidden (`can_attempt = false`, `denial_reason = 'Retakes are not permitted for this test.'`).
  - If `max_attempts_per_test = 3`: The student may take the test up to 3 times total (Initial + 2 retakes).
  - For paid students: `max_attempts_per_test = null` (unlimited retakes).
- If `allow_retakes = false`: Any attempt beyond the initial one (`attempt_number > 1`) is strictly rejected with HTTP 403.

### 2.3 Highest Finalized Attempt Scoring Policy
The platform strictly maintains the approved academic policy:
- **Default Scoring:** MCQ retakes record and display the score of the **highest finalized attempt** across all completed attempts for that assessment.
- **Audit Preservation:** Every attempt creates its own immutable `TestAttempt` record with individual question choices, timings, and marks. No attempt history is overwritten.
- **Unweighted Raw Marks:** Gradebook reporting defaults to unweighted raw marks, with optional teacher-configured weighted categories. Missing assignments remain excluded until explicitly finalized as zero.

---

## 3. Storage Loss Boundary & In-Progress Attempt Recovery

- **Existing Backend Reality:** The backend records individual answers in `test_attempt_questions`, but exposes **no student endpoint** to re-read saved answers or question IDs once browser `sessionStorage` is lost.
- **Frontend Safe Boundary:**
  - If `sessionStorage` is intact: Full attempt execution, progress saving, and submission operate normally.
  - If `sessionStorage` is lost mid-attempt: The runner checks `getTestEligibility(attemptId)`. If already submitted, it redirects to results. If in-progress, it shows an actionable storage-loss notice with a link back to Tests Hub, preventing attempt corruption or state fabrication.
