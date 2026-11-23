# Stage 1E.3.A — Milestone 4 Implementation Breakdown
## Phased Implementation Sequence & Deliverables

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M4_MILESTONE_BREAKDOWN.md`
- **Execution Date:** November 20, 2026 (Revised)
- **Target Repository (Primary):** `E:\Al-Faraabi Software\al-faraabi-website` (`thestudentstimes.git`)
- **Backend Reference (Read-Only):** `E:\Al-Faraabi Software\3peas_backend`
- **Status:** Read-Only Architectural Specification (Security-Hardened Revision)

---

## 1. Phased Execution Roadmap

Implementation must proceed in five tightly controlled phases upon authorization:

```
[Phase 4A: Backend Dedicated Mock Endpoint, Blueprint Validation & Synthetic ID Fixes]
       │
       ▼
[Phase 4B: Frontend Idempotency & Multi-Mode Launch Integration (TestLaunchScreen)]
       │
       ▼
[Phase 4C: Frontend Attempt Runner & Eligibility Integration (TestAttemptScreen)]
       │
       ▼
[Phase 4D: Results Screen & Retake Flow Integration (TestResultScreen)]
       │
       ▼
[Phase 4E: Comprehensive Security, Runner & Regression Verification]
```

---

## 2. Detailed Work Packages

### Phase 4A: Backend Dedicated Mock Endpoint, Blueprint Validation & Synthetic ID Fixes
- **Target:** `3peas_backend/app/Http/Controllers/Tests/TestController.php` & `PracticeSessionController.php`
- **Tasks:**
  1. **Synthetic ID Validation Fix:**
     - Update validation in `generateFullBookFormalTestForLms`, `generateSelectedUnitsFormalTestForLms`, and `generateFullBookPracticeSession`:
     - Change `'offered_program_id' => ['required', ...]` to `'offered_program_id' => ['nullable', 'integer', 'min:1', 'exists:offered_programs,id']`.
  2. **Dedicated Mock Endpoint Creation:**
     - Implement `POST /web_api/auth/tests/generate-full-book-mock-test` handled by `TestController::generateFullBookMockTestForLms`.
     - Resolve standardized Board Mock Blueprint (e.g. 60 questions, 60 minutes for board exams).
     - Authoritatively resolve entitlement via `UnifiedEntitlementResolver::resolve($student, $context, 'perm_mcqs', 'mock')`.
     - Authoritatively consume quota via `FreemiumQuotaConsumptionService::consumeQuota($student, 'mock', 'generation', ...)`.
     - Persist `Test` record with immutable `test_mode = 'mock'` and `test_source = 'system'`.
  3. **Hardened Formal Endpoints:**
     - Ensure `generateFullBookFormalTestForLms` and `generateSelectedUnitsFormalTestForLms` strictly enforce `exam_type = 'formal_test'` and `test_mode = 'test'`.
     - Any client-supplied mode parameter is ignored or rejected, preventing formal-to-mock quota hopping.
  4. **Practice Session Nullable Program Fix:**
     - Ensure `PracticeSessionController` accepts nullable `offered_program_id`.

### Phase 4B: Frontend Idempotency & Multi-Mode Launch Integration
- **Target:** `al-faraabi-website/src/components/LMS/tests/TestLaunchScreen.jsx` & `src/api/freemium/freemiumApiClient.js`
- **Tasks:**
  1. Add `generateFullBookMockTest` to `freemiumApiClient.js`.
  2. Initialize `createIdempotencyTracker()` on mount in `TestLaunchScreen.jsx`.
  3. Attach `X-Idempotency-Key` header to all generation requests.
  4. Sanitize outgoing payload with `sanitizeAccessPayload(payload)` (omitting `offered_program_id` for synthetic IDs).
  5. Route assessment generation by mode:
     - If `config.mode === 'mock'`: invoke `generateFullBookMockTest` (enforcing full-book scope).
     - If `config.mode === 'test'`: invoke `generateFullBookFormalTest` or `generateSelectedUnitsFormalTest`.
     - If `config.mode === 'practice'`: invoke `generatePracticeSession`.
  6. Mount `<FreemiumBadge />` and `<AttemptNumberBadge />`.
  7. Intercept 403 `QUOTA_EXHAUSTED` and mount `<QuotaExhaustedDialog />`.
  8. Handle 409 `IDEMPOTENCY_CONFLICT` by resetting key and informing student.

### Phase 4C: Frontend Attempt Runner & Eligibility Integration
- **Target:** `al-faraabi-website/src/components/LMS/tests/TestAttemptScreen.jsx`
- **Tasks:**
  1. Display `<AttemptNumberBadge />` alongside test timer.
  2. For mock tests (`test_mode === 'mock'`), display "Board Mock Exam" header and badge.
  3. Execute pre-flight check via `getTestEligibility(testId)` on mount.
  4. Graceful handling of duplicate submissions (HTTP 409).
  5. Prevent question retakes from incrementing weekly test counters.

### Phase 4D: Results Screen & Retake Flow Integration
- **Target:** `al-faraabi-website/src/components/LMS/tests/TestResultScreen.jsx`
- **Tasks:**
  1. Mount `<AttemptNumberBadge />` on results summary.
  2. Conditionally display "Retake Test" button based on `allow_retakes` and `remaining_attempts`.
  3. Retake action navigates to `/lms/tests/launch?retake=true` or initiates retake attempt.

### Phase 4E: Comprehensive Testing & Acceptance Verification
- **Tasks:**
  1. Author `tests/freemiumLaunchRunner.test.mjs` verifying all 10 security test scenarios (`SEC-01` through `SEC-10`) and frontend runner tests (`FE-01` through `FE-06`).
  2. Run PHPUnit backend tests for mode validation and idempotency.
  3. Run full frontend regression suite (183+ tests).
  4. Run targeted ESLint on touched components.
  5. Author Milestone 4 Implementation & Audit Report.
