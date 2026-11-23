# Stage 1E.3.A — Milestone 4 Testing & Acceptance Plan
## Test Launch, Mode Validation, Idempotency, and Attempt Runner Verification

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M4_TESTING_PLAN.md`
- **Execution Date:** November 20, 2026 (Revised)
- **Target Repository (Primary):** `E:\Al-Faraabi Software\al-faraabi-website` (`thestudentstimes.git`)
- **Backend Reference (Read-Only):** `E:\Al-Faraabi Software\3peas_backend`
- **Status:** Read-Only Architectural Specification (Security-Hardened Revision)

---

## 1. Testing Strategy

Milestone 4 verification requires rigorous end-to-end testing across both the Next.js LMS frontend and the Laravel backend, ensuring that:
1. Assessment mode determination is strictly server-authoritative and impervious to client tampering.
2. Idempotency guarantees prevent duplicate quota deduction and entity creation.
3. Quota categories (`formal_test`, `mock`, `practice`) remain strictly isolated.
4. Pre-flight eligibility and in-runner attempt accounting accurately manage attempt limits and retakes.

---

## 2. Core Security & Mode Validation Test Scenarios (10 Mandatory Cases)

The following 10 test scenarios must be implemented and verified in the automated testing suite:

| Test ID | Test Scenario | Input / Test Conditions | Expected Backend & System Behavior | Invariant Checked |
| :---: | :--- | :--- | :--- | :--- |
| **SEC-01** | **Full-Book Formal Cannot Become Mock via Client Payload** | Client calls `POST /auth/tests/generate-full-book-formal-test` with malicious payload injecting `requested_mode: "mock"`, `mode: "mock"`, or `exam_type: "mock"`. | • Server completely discards or rejects mode parameter.<br>• Generates test with `test_mode: 'test'`.<br>• Strictly consumes `formal_test` quota.<br>• Mock quota counter remains completely untouched. | Anti-Tampering / Formal Route Immutability |
| **SEC-02** | **Chapter/Unit-Level Test Cannot Become Mock** | Client attempts to invoke mock generation for partial chapter scope (e.g. calls `/generate-selected-units-formal-test` with mock flag or sends unit IDs to mock endpoint). | • Server strictly rejects request with HTTP 422 `INVALID_ASSESSMENT_SCOPE`.<br>• Zero quota consumed.<br>• No test record created. | Scope Invariant Enforcement |
| **SEC-03** | **Authorized Mock Generation Consumes Mock Quota Only** | Eligible freemium student invokes dedicated mock endpoint `POST /auth/tests/generate-full-book-mock-test` with valid board blueprint parameters. | • Server validates syllabus coverage and blueprint.<br>• `UnifiedEntitlementResolver` resolves with `'mock'`.<br>• Strictly consumes 1 unit of `mock` quota.<br>• Persists `Test` with `test_mode = 'mock'`.<br>• `formal_test` and `practice` quotas remain unchanged. | Mock Quota Isolation |
| **SEC-04** | **Formal Test Generation Consumes Formal Quota Only** | Eligible freemium student generates chapter or full-book formal test. | • Server calls `UnifiedEntitlementResolver` with `'formal_test'`.<br>• Strictly consumes 1 unit of `formal_test` quota.<br>• Persists `Test` with `test_mode = 'test'`.<br>• `mock` quota counter is completely unchanged. | Formal Quota Isolation |
| **SEC-05** | **Practice Session Creation Consumes Practice Quota Only** | Student creates full-book or chapter practice session. | • Handled by `PracticeSessionController`.<br>• Creates `PracticeSession` record.<br>• Deducts from `practice` quota (or unmetered).<br>• Formal and mock test quotas are 100% untouched. | Practice Quota Isolation |
| **SEC-06** | **Manipulated Mode/Scope Parameters Fail Safely** | Client sends unexpected types, nulls, or invalid strings for mode/scope parameters. | • Laravel validation fails safely with HTTP 422.<br>• Returns standardized error JSON without system stack trace.<br>• Database state and quota counters remain completely unaltered. | Safe Failure Modes |
| **SEC-07** | **Blueprint Mismatch Parameter Rejection** | Client calls mock generation endpoint with arbitrary non-blueprint parameters (e.g. `total_questions: 7`, `time_limit_minutes: 5` when board template requires 60/60). | • Server rejects request with HTTP 422 `INVALID_MOCK_BLUEPRINT`.<br>• Error message clearly states required blueprint parameters.<br>• No test generated; zero quota deducted. | Board Blueprint Integrity |
| **SEC-08** | **Idempotent Replay Prevents Double Quota Deduction** | Network retry re-transmits identical generation request with matching `X-Idempotency-Key` and matching payload hash. | • Server detects existing valid key in `freemium_idempotency_keys`.<br>• Returns cached 200 OK response with original `test_id`.<br>• Quota is NOT deducted a second time (usage remains 1).<br>• No duplicate test record created. | At-Most-Once Execution |
| **SEC-09** | **Official Shared Mocks Use Mock Quota on Attempt** | Student opens and starts an attempt on a pre-authored official mock (`test_source = 'official'`, `test_mode = 'mock'`). | • In `saveFormalTestQuestionProgressForLms`, server derives `$examType = 'mock'`.<br>• Logs attempt in `freemium_test_attempts` under `exam_type = 'mock'`.<br>• Does NOT touch formal test attempts. | Official Mock Uniformity |
| **SEC-10** | **Cross-Category Quota Exhaustion Isolation** | Student has 0 remaining `formal_test` quota, but 1 remaining `mock` quota. Student launches full-book mock exam. | • Mock generation succeeds with HTTP 200 OK.<br>• Subsequent formal test generation fails with HTTP 403 `QUOTA_EXHAUSTED`.<br>• Exhausting one category does not compromise or lock the other category. | Category Orthogonality |

---

## 3. Frontend LMS Runner & UI Test Matrix

| Test ID | Scenario | Input Conditions | Expected Frontend Behavior |
| :---: | :--- | :--- | :--- |
| **FE-01** | **Synthetic ID Sanitization on Launch** | Freemium catalog resource selected with negative ID (`programId = -11`). | Outgoing launch payload completely OMITS `offered_program_id`. Does not transmit negative number or 0. |
| **FE-02** | **UUIDv4 Idempotency Key Generation** | User clicks "Start Test". | Request includes `X-Idempotency-Key` header matching regex `^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`. |
| **FE-03** | **Quota Exhaustion Modal Display** | Server returns HTTP 403 `QUOTA_EXHAUSTED`. | `TestLaunchScreen` catches error, passes normalized payload to `QuotaExhaustedDialog`, displaying upgrade CTA without page crash. |
| **FE-04** | **Attempt Numbering in Runner** | Student initiates attempt #1 on formal test. | Header displays `AttemptNumberBadge` rendering `"Attempt #1"`. Retake displays `"Retake #2"`. |
| **FE-05** | **Pre-Flight Retake Restriction** | Test has `remaining_attempts: 0`. | Pre-flight check disables launch button and displays "Maximum attempts reached". |
| **FE-06** | **Interrupted Runner State Recovery** | Browser refreshed midway through formal or practice test. | Component recovers test state from local storage or server session without incrementing attempt count or consuming new quota. |

---

## 4. Test Execution Commands & Acceptance Protocol

```bash
# 1. Run Milestone 4 Launch & Runner Automated Tests (Frontend)
node --test tests/freemiumLaunchRunner.test.mjs

# 2. Run Complete LMS Regression Test Suite
node --test tests/freemiumTestsHub.test.mjs
node --test tests/freemiumComponents.test.mjs
node --test tests/freemiumClient.test.mjs
node --test tests/freemiumIdempotency.test.mjs
node --test tests/*.test.mjs

# 3. Targeted ESLint Verification (Zero Errors, Zero Warnings)
npx eslint src/components/LMS/tests/TestLaunchScreen.jsx src/components/LMS/tests/TestTakingScreen.jsx tests/freemiumLaunchRunner.test.mjs

# 4. Run Backend Security & Mode Validation Feature Tests (PHPUnit)
php artisan test tests/Feature/Freemium/AssessmentModeSecurityTest.php
php artisan test tests/Feature/Freemium/AssessmentIdempotencyServiceTest.php
php artisan test tests/Feature/Freemium/Student/StudentMockTestAttemptAccountingTest.php
```
