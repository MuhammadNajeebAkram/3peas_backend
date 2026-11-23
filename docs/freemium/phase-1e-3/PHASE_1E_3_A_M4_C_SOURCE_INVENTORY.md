# Stage 1E.3.A — Milestone 4 Phase 4C Source Inventory
## Frontend Attempt Runner & Eligibility Integration Source Map (Clarified Revision)

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M4_C_SOURCE_INVENTORY.md`
- **Revision Date:** November 20, 2026 (Clarified Revision)
- **Primary Repository:** `E:\Al-Faraabi Software\al-faraabi-website` (Student LMS Frontend)
- **Reference Repository (Read-Only):** `E:\Al-Faraabi Software\3peas_backend` (Laravel Backend)
- **Admin Reference (Read-Only):** `E:\Al-Faraabi Software\tst-admin`
- **Scope:** Phase 4C Planning & Source Inspection ONLY

---

## 1. Primary Frontend Source Files (`al-faraabi-website`)

### 1.1 Direct Implementation Target
| File Path | Lines | Current Role | Phase 4C Modification Scope |
|---|---|---|---|
| `src/components/LMS/tests/TestAttemptScreen.jsx` | 1427 | Main assessment attempt runner for formal tests, board mocks, and practice sessions. Manages timer, question clustering, progress persistence, and submission. | **Primary target:** Add preflight `getTestEligibility` check, integrate `<AttemptNumberBadge>`, display "Board Mock Exam" header, capture and propagate `attempt_id`, handle 409 `ATTEMPT_ALREADY_SUBMITTED`, handle missing `sessionStorage` safely without fabricating state. |

### 1.2 Route & Entry Pages
| File Path | Lines | Role | Reusability / Impact |
|---|---|---|---|
| `src/app/(lms)/lms/(protected)/tests/test/[testId]/page.jsx` | 7 | Next.js Server Component route for formal & mock tests. Renders `<TestAttemptScreen attemptType="test" attemptId={testId} />`. | Reused as-is. Provides `testId` prop. |
| `src/app/(lms)/lms/(protected)/tests/practice/[sessionId]/page.jsx` | 7 | Next.js Server Component route for practice sessions. Renders `<TestAttemptScreen attemptType="practice" attemptId={sessionId} />`. | Reused as-is. Provides `sessionId` prop. |
| `src/app/(lms)/lms/(protected)/tests/launch/page.jsx` | 7 | Next.js route for Test Launch screen. Mounts `TestLaunchScreen`. | Upstream caller: writes `lms-attempt:${type}:${id}` to `sessionStorage` and navigates to runner. |
| `src/components/LMS/tests/TestLaunchScreen.jsx` | 577 | Launch setup component with mode validation, idempotency key creation, and multi-mode API routing. | Verified in Phase 4B. Feeds initial attempt context. |

### 1.3 Freemium UI & Client Modules
| File Path | Lines | Exported Members | Phase 4C Usage |
|---|---|---|---|
| `src/components/LMS/freemium/AttemptNumberBadge.jsx` | 82 | Default export `AttemptNumberBadge` | Reused directly: renders attempt sequence (`Attempt 1 of 1`, `Retake 2 of 3`, `Board Mock Exam`) alongside countdown timer. |
| `src/components/LMS/freemium/freemiumUiHelpers.mjs` | 134 | `getAttemptBadgeDetails`, `formatQuotaCountdown`, `formatTimeRemaining` | Reused directly: generates badge labels, accessible ARIA descriptions, and color tokens. |
| `src/components/LMS/freemium/QuotaExhaustedDialog.jsx` | 114 | Default export `QuotaExhaustedDialog` | Reused directly: mounted in runner when preflight eligibility returns `can_attempt: false` or quota exhausted. |
| `src/components/LMS/freemium/FreemiumBadge.jsx` | 65 | Default export `FreemiumBadge` | Reused directly: displays student access tier (`Paid`, `Freemium`, `Free Access`). |
| `src/lib/freemiumApi.js` | 260 | `getTestEligibility`, `generateFullBookMockTest`, `apiFetch` | Reused directly: provides typed preflight eligibility check and error normalizer. |
| `src/lib/freemiumNormalizers.mjs` | 531 | `normalizeTestEligibility`, `normalizeFreemiumError` | Reused directly: transforms raw backend eligibility response and maps error codes. |

---

## 2. Backend Reference Source Files (`3peas_backend` — Read-Only)

### 2.1 Route Definitions
| File Path | Lines | Relevant Routes | Notes |
|---|---|---|---|
| `routes/web_api.php` | 322 | `GET /web_api/auth/freemium/tests/{testId}/eligibility` | Maps to `StudentFreemiumQuotaController::testEligibility`. |
| `routes/web_api.php` | 369–378 | `POST /web_api/auth/tests/generate-*`, `POST /web_api/auth/tests/save-formal-test-question-progress`, `POST /web_api/auth/tests/submit-formal-test`, practice routes | Core test execution and progress persistence group. |

### 2.2 Controllers
| File Path | Lines | Action Methods | Key Behavior |
|---|---|---|---|
| `app/Http/Controllers/Api/Student/Freemium/StudentFreemiumQuotaController.php` | 73–86 | `testEligibility(Request $request, $testId)` | Invokes `quotaService->getTestAttemptEligibility($student, $testId)`. Returns HTTP 200 with eligibility payload or 404 `test_not_found`. |
| `app/Http/Controllers/Tests/TestController.php` | 1005–1260 | `saveFormalTestQuestionProgressForLms(Request $request)` | Accepts optional `attempt_id`. Verifies attempt ownership and test match. Rejects submitted attempts with 409 `ATTEMPT_ALREADY_SUBMITTED`. Saves answer, time spent. Creates attempt record and logs quota event on first answer. |
| `app/Http/Controllers/Tests/TestController.php` | 1468–1640 | `submitFormalTestForLms(Request $request)` | Finalizes attempt, calculates final score, replays 200 if already submitted. |
| `app/Http/Controllers/Tests/PracticeSessionController.php` | 717–846 | `savePracticeSessionQuestionProgressForLms`, `submitPracticeSessionForLms` | Manages unmetered/practice study progress and finalization. |
| `app/Http/Controllers/TopicsController.php` | 377–510 | `getTopicsByUnitForLMS(Request $request, $unit_id)` | Resolves topics for question loading. Enforces `UnifiedEntitlementResolver`. |
| `app/Http/Controllers/QuestionsController.php` | 2928–3000 | `getQuestionsByTopicForLMS(Request $request, $topic_id)` | Resolves questions by topic. Enforces `UnifiedEntitlementResolver`. |

### 2.3 Database Schema & Valid Enum Constants
Inspection of `database/migrations/2026_04_18_093707_create_tests_table.php` (line 31) and `2026_04_17_193021_create_test_attempts_table.php` (line 31) establishes the exact valid enum values:

- **`test_source` ENUM:** `['system', 'teacher', 'official']` (default: `'system'`).
  - `'system'`: Dynamically generated assessments created via student LMS generation endpoints (`generateFullBookFormalTest`, `generateFullBookMockTest`).
  - `'official'`: Official, shared assessments created by administrators or curriculum staff, published globally (`is_published = true`).
  - `'teacher'`: Teacher-created class tests assigned to specific classes.
  - *Note:* The string `'custom'` does NOT exist in the database or backend code; all references must use `'system'`.
- **`test_mode` ENUM:** `['test', 'mock']`.

---

## 3. Backend Dependency Clarification: Attempt Recovery

The source inspection revealed that the backend provides **no endpoint to fetch in-progress attempt state** (question list, saved answers, question cursor, or remaining timer) once `sessionStorage` is lost.

| Recovery Item | Endpoint Available? | Details |
|---|---|---|
| **Attempt ID & Status** | YES | `GET /freemium/tests/{id}/eligibility` returns `has_active_attempt` and `active_attempt_id`. |
| **Attempt Number** | YES | `GET /freemium/tests/{id}/eligibility` returns `attempt_number`. |
| **Saved Answers** | **NO** | No endpoint returns `selected_option_id` per question for student formal/mock tests. |
| **Question Sequence & IDs** | **NO** | No endpoint returns `question_ids` or `unit_ids` for an existing self-generated test. |
| **Current Question Position** | **NO** | Not stored on server. |
| **Remaining Time** | **NO** | Server stores `started_at`, but does not expose it via student eligibility or test API. |

**Frontend Architectural Boundary:**
The frontend MUST NOT fabricate missing questions or answers. If `attemptData` is missing from `sessionStorage` during an active attempt, the runner will safely display an actionable error alert advising the student to return to the Tests Hub or view their completed results, rather than crashing or faking state.
Full attempt reconstruction from server state is documented as a future backend enhancement.
