# Stage 1E.3.A — Milestone 4 Source Inventory
## Test Launch, Idempotency and Attempt Runner

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M4_SOURCE_INVENTORY.md`
- **Execution Date:** November 20, 2026
- **Target Repository (Primary):** `E:\Al-Faraabi Software\al-faraabi-website` (`thestudentstimes.git`)
- **Backend Reference (Read-Only):** `E:\Al-Faraabi Software\3peas_backend`
- **Status:** Read-Only Architectural Specification

---

## 1. Overview & Scope Definition

Milestone 4 governs the transition from free curriculum resource discovery (Milestone 3) to test launch, execution, attempt tracking, and quota consumption. This document provides a complete inventory of all frontend and backend source files participating in Milestone 4.

---

## 2. Frontend Source Inventory (`al-faraabi-website`)

| File Path | Component / Module | Responsibility in Milestone 4 | State / Lifecycle Role |
| :--- | :--- | :--- | :--- |
| `src/app/(lms)/lms/(protected)/tests/launch/page.jsx` | Next.js Page Route | Server component hosting `TestLaunchScreen`. Enforces route-level authentication. | Mounts `TestLaunchScreen`. |
| `src/components/LMS/tests/TestLaunchScreen.jsx` | Client Component | Reads query params from M3 Tests Hub, validates readiness, enforces pre-flight eligibility, attaches `X-Idempotency-Key`, dispatches generation request, sanitizes synthetic IDs, handles 403/409 errors with `QuotaExhaustedDialog`. | Pre-attempt configuration, launch orchestration, error trapping. |
| `src/app/(lms)/lms/(protected)/tests/test/[testId]/page.jsx` | Next.js Page Route | Route wrapper for Formal and Mock test execution. | Mounts `TestAttemptScreen` with `attemptType="test"`. |
| `src/app/(lms)/lms/(protected)/tests/practice/[sessionId]/page.jsx` | Next.js Page Route | Route wrapper for Practice Session execution. | Mounts `TestAttemptScreen` with `attemptType="practice"`. |
| `src/components/LMS/tests/TestAttemptScreen.jsx` | Client Component | Attempt runner: fetches ordered MCQs, clusters scenarios, saves incremental progress, tracks timing, enforces timer, submits attempt. | Active attempt runner & session state. |
| `src/app/(lms)/lms/(protected)/tests/test/[testId]/result/page.jsx` | Next.js Page Route | Route wrapper for Formal/Mock test results. | Mounts `TestResultScreen`. |
| `src/app/(lms)/lms/(protected)/tests/practice/[sessionId]/result/page.jsx` | Next.js Page Route | Route wrapper for Practice session results. | Mounts `TestResultScreen`. |
| `src/components/LMS/tests/TestResultScreen.jsx` | Client Component | Results presentation: displays score, accuracy, question breakdown. | Post-submission evaluation & retake action. |
| `src/lib/freemiumApi.js` | API Client | Verified client wrappers: `getTestEligibility()`, `evaluateAccess()`, `getQuotaAllowances()`. | Network interface. |
| `src/lib/freemiumNormalizers.mjs` | Utilities & Normalizers | `createIdempotencyTracker()`, `generateIdempotencyKey()`, `sanitizeAccessPayload()`, `normalizeTestEligibility()`, `normalizeFreemiumError()`. | Request sanitization, idempotency tracking, response normalization. |
| `src/components/LMS/freemium/AttemptNumberBadge.jsx` | UI Component | Renders localized attempt badges ("Attempt #1", "Retake #2", "Mock Exam"). | Visual status indicator. |
| `src/components/LMS/freemium/QuotaExhaustedDialog.jsx` | UI Component | Displays structured quota limit dialog on HTTP 403 `QUOTA_EXHAUSTED` responses. | Modal guidance & upgrade CTA. |
| `src/components/LMS/freemium/FreemiumBadge.jsx` | UI Component | Displays current resource tier (`tier1_paid`, `tier2_freemium`, `tier3_legacy`, `unavailable`). | Visual tier indicator. |

---

## 3. Backend Source Inventory (`3peas_backend`)

| File Path | Class / Controller | Method / Responsibility in Milestone 4 | Backend Subsystem |
| :--- | :--- | :--- | :--- |
| `app/Http/Controllers/Tests/TestController.php` | `TestController` | • `generateFullBookFormalTestForLms()`: Validates idempotency, authorizes entitlement, consumes formal/mock quota, creates `Test`.<br>• `generateSelectedUnitsFormalTestForLms()`: Chapter-wise formal test generation.<br>• `saveFormalTestQuestionProgressForLms()`: Creates `TestAttempt` (`attempt_start` / `retake_start`), saves answers.<br>• `submitFormalTestForLms()`: Finalizes attempt, scores test, records completed status. | Formal & Mock Test Management |
| `app/Http/Controllers/Tests/PracticeSessionController.php` | `PracticeSessionController` | • `generateFullBookPracticeSessionForLms()`: Validates idempotency, consumes practice quota, creates session.<br>• `generateSelectedUnitsPracticeSessionForLms()`: Unit practice generation.<br>• `savePracticeSessionQuestionProgressForLms()`: Saves incremental practice progress.<br>• `submitPracticeSessionForLms()`: Finalizes practice session. | Practice Session Management |
| `app/Http/Controllers/Api/Student/Freemium/StudentFreemiumAccessController.php` | `StudentFreemiumAccessController` | • `testEligibility()`: Resolves attempt accounting, remaining attempts, retake allowance.<br>• `quotaAllowances()`: Returns remaining weekly test, mock, and practice quotas.<br>• `evaluateAccess()`: Real-time 4-tier access check. | Student Freemium API Gateway |
| `app/Http/Services/Freemium/AssessmentIdempotencyService.php` | `AssessmentIdempotencyService` | Validates `X-Idempotency-Key` format, computes canonical payload fingerprint, locks processing state, replays cached response, detects 409 conflict. | Concurrency & Idempotency Engine |
| `app/Http/Services/Freemium/FreemiumQuotaConsumptionService.php` | `FreemiumQuotaConsumptionService` | Serializes student consumption with `WebUser` lock, enforces quota ceilings, creates `FreemiumQuotaConsumption` records, tracks attempt numbers. | Quota Accounting & Enforcement |
| `app/Http/Services/Freemium/UnifiedEntitlementResolver.php` | `UnifiedEntitlementResolver` | Authoritative 4-tier resolution (`tier1_paid`, `tier2_freemium`, `tier3_legacy`, `tier4_denied`). | Entitlement Authority |
| `app/Models/AssessmentIdempotencyRecord.php` | Eloquent Model | Table `assessment_idempotency_records`: persists request fingerprint, execution status, response payload, HTTP code. | Persistence Layer |
| `app/Models/Test.php` | Eloquent Model | Table `tests`: persists generated tests, `test_mode` (`test` / `mock`), `test_source`, `time_limit_minutes`, `total_questions`. | Domain Entity |
| `app/Models/TestAttempt.php` | Eloquent Model | Table `test_attempts`: persists student attempts, attempt counts, scores, accuracy, status (`in_progress` / `submitted`). | Domain Entity |
| `app/Models/PracticeSession.php` | Eloquent Model | Table `practice_sessions`: persists student practice sessions and progress. | Domain Entity |
| `routes/web_api.php` | Route Definitions | Prefix `auth/tests`: routes for generation, question progress, submission, and recent attempts. | Routing Layer |
