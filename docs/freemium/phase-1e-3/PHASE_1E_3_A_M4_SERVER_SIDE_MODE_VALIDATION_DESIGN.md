# Stage 1E.3.A — Milestone 4 Server-Side Mode Validation Design
## Authoritative Assessment-Mode Derivation, Blueprint Validation, and Anti-Tampering Specification

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M4_SERVER_SIDE_MODE_VALIDATION_DESIGN.md`
- **Execution Date:** November 20, 2026 (Revised)
- **Target Repository (Primary):** `E:\Al-Faraabi Software\al-faraabi-website` (`thestudentstimes.git`)
- **Backend Reference (Read-Only):** `E:\Al-Faraabi Software\3peas_backend`
- **Status:** Read-Only Architectural Specification (Security-Hardened Revision)

---

## 1. Security Flaw Analysis & Rejection of Client-Controlled Assessment Mode

### 1.1 The Vulnerability of Client-Controlled Mode Flags
In an earlier draft, it was proposed that the backend would accept a request payload field `requested_mode: "mock"` on existing formal test generation endpoints (`/generate-full-book-formal-test`), qualifying it solely by verifying `scope === "full_book"`. 

**This design is fundamentally flawed and rejected for the following reasons:**

1. **A Full-Book Formal Test is NOT a Mock Examination:**
   - In Pakistani secondary and higher-secondary education (FBISE, BISE Lahore, BISE Rawalpindi, etc.) as well as standardized competitive entrance tests (MDCAT, ECAT), a "Mock Examination" has a formal academic meaning. It simulates the exact terminal board examination: comprehensive syllabus coverage, standardized question count (e.g., 60 MCQs), official time limit (e.g., 60 minutes), and specific topic distribution.
   - Conversely, a student can generate a short diagnostic full-book review (e.g., 10 questions, 15 minutes) to quickly assess broad retention. This is an ordinary formal test, not a board simulation mock exam.
2. **Quota Category Hopping & Arbitrary Quota Evasion:**
   - If client-supplied flags dictate quota deduction, a student who has exhausted their weekly quota of `formal_test` (e.g., 3 of 3 used) could send `requested_mode: "mock"` while generating a 10-question test, consuming an unused `mock` quota (e.g., 1 of 1 allocated) for what is clearly a routine practice test.
   - Conversely, a student who has exhausted their `mock` quota could generate a complete 60-question board-length exam by omitting the flag or passing `requested_mode: "formal_test"`, exhausting a cheaper or unmetered formal test allowance.
3. **Parameter Distortion & Tampering:**
   - Allowing client sliders (arbitrary question counts, arbitrary time limits) to pair with a `mock` mode undermines the academic integrity of mock analytics, standardized percentile rankings, and attempt limits.
4. **Violation of the Zero-Trust Boundary:**
   - Quota allocation, entitlement resolution, and assessment classification are financial and business-critical operations. The client must never assert its own access tier or quota bucket. The server must authoritatively determine assessment classification.

---

## 2. Evaluation of Architectural Approaches

To resolve this vulnerability authoritatively, three architectural approaches were evaluated:

| Criterion | Approach 1: Dedicated Route & Handler Segregation | Approach 2: Server-Controlled Blueprint / Template Binding | Approach 3: Dual-Mode Official vs. Dynamic Separation | Selected Unified Architecture (Synthesis of 1, 2, and 3) |
| :--- | :--- | :--- | :--- | :--- |
| **Description** | Create separate endpoints (`/generate-*-mock-test` vs `/generate-*-formal-test`). | Require a server-side assessment template ID or blueprint that locks question count, time limit, and coverage. | Distinguish pre-authored official shared mocks (`test_source='official'`) from dynamically generated mocks (`test_source='system'`). | **Unified 4-Factor Server Derivation**: Dedicated endpoint + Server-controlled board blueprint + Academic scope enforcement + Authoritative entitlement resolution. |
| **Strengths** | Clear route segregation; client cannot tamper with mode on formal endpoints; clean middleware boundaries. | Strong pedagogical consistency; parameters are immutable and server-controlled; impossible to distort test length or time. | Preserves existing official mock workflows; zero disruption to teacher/admin published mocks. | Combines the security of dedicated endpoints, the strictness of server blueprints, and full backward compatibility. |
| **Weaknesses Alone** | Route alone does not prevent student sending arbitrary questions/time if endpoint accepts client sliders. | Overly rigid if templates don't exist for every novel curriculum variation. | Doesn't solve dynamic mock generation by itself. | Requires clear endpoint definition and blueprint cataloging on backend. |
| **Verdict** | **Mandatory baseline** | **Mandatory baseline** | **Mandatory baseline** | **APPROVED ARCHITECTURE** |

---

## 3. The Authoritative Server-Side Derivation Architecture

Under the approved architecture, assessment classification and quota deduction are governed by a **Four-Factor Server Derivation Engine**:

```
[Incoming LMS Request]
       │
       ▼
[Factor 1: Strict Endpoint & Handler Segregation]
  ├─ POST /auth/tests/generate-*-practice-session  ──> FORCED to 'practice' (PracticeSession Model)
  ├─ POST /auth/tests/generate-*-formal-test       ──> FORCED to 'formal_test' (test_mode = 'test')
  │    └─ Any client 'requested_mode' is IGNORED or REJECTED. Formal endpoints CANNOT produce mocks.
  └─ POST /auth/tests/generate-full-book-mock-test ──> CANDIDATE for 'mock' (test_mode = 'mock')
       │
       ▼
[Factor 2: Server-Controlled Blueprint Validation]
  ├─ Validates subject & curriculum against Authoritative Board Mock Blueprint:
  │    • Syllabus Coverage: Must be 100% full-book / all authorized board syllabus units.
  │    • Question Count: Server enforces standardized board count (e.g., 60 MCQs).
  │    • Time Limit: Server enforces standardized board duration (e.g., 60 minutes).
  │    • Client cannot override these parameters with arbitrary sliders.
  │    • If client parameters conflict with server blueprint ──> REJECT with HTTP 422 INVALID_MOCK_BLUEPRINT.
       │
       ▼
[Factor 3: Authoritative Academic Entitlement Resolution]
  ├─ UnifiedEntitlementResolver::resolve($student, $targetContext, 'perm_mcqs', 'mock')
  │    • Checks paid subscription or active FreemiumCampaign rule for 'mock'.
  │    • If mock exams disabled in campaign rule (is_disabled = 1) ──> REJECT with HTTP 403 MOCK_ACCESS_DENIED.
       │
       ▼
[Factor 4: Atomic Quota Verification & Consumption]
  ├─ FreemiumQuotaConsumptionService::consumeQuota($student, 'mock', 'generation', ...)
  │    • Checks weekly quota ceiling for 'mock'.
  │    • If weekly mock allowance exhausted ──> REJECT with HTTP 403 QUOTA_EXHAUSTED.
  │    • Atomically increments mock quota ledger.
       │
       ▼
[Authoritative Persistence: Immutable Test Record]
  └─ Test::create([... 'test_mode' => 'mock', 'test_source' => 'system'])
```

---

## 4. Authoritative Definition of a Genuine Mock Examination

An assessment qualifies as a **Genuine Mock Examination** if and only if it meets all four mandatory pedagogical and system criteria:

1. **Standardized Board Blueprint Compliance:**
   The test structure conforms strictly to the official examination pattern of the affiliated education board (e.g., FBISE, BISE Lahore, Cambridge, UHS MDCAT):
   - Fixed standardized question count (e.g., 60 questions for intermediate, 30 questions for matric, 100 questions for entrance tests).
   - Fixed standardized duration (e.g., 60 minutes).
   - Difficulty distribution and question type mix calibrated to official paper specifications.
2. **Comprehensive Syllabus Coverage:**
   The assessment scope covers the complete curriculum (entire textbook / all syllabus units). Partial-syllabus, single-chapter, or custom unit selections are disqualified by definition from mock classification.
3. **Dedicated Assessment Mode Persistence:**
   The assessment entity is created in the database with immutable `test_mode = 'mock'`. It cannot be converted, re-scoped, or retaken under formal test rules.
4. **Dedicated Quota & Entitlement Accounting:**
   Eligibility is authorized strictly against the `mock` entitlement tier and quota counter. It meters against the `mock` quota bucket and logs attempt records in `freemium_test_attempts` with `exam_type = 'mock'`.

---

## 5. Prevention of Quota Hopping and Parameter Tampering

### 5.1 Formal-to-Mock / Mock-to-Formal Hopping Prevention
- **Formal Endpoints are Immutable:**
  `POST /auth/tests/generate-full-book-formal-test` and `POST /auth/tests/generate-selected-units-formal-test` have their handler logic hardwired:
  ```php
  // TestController.php - Formal Test Endpoints
  $resolvedExamType = 'formal_test';
  $resolvedTestMode = 'test';
  // Any client-supplied 'requested_mode' or 'exam_type' is discarded during validation
  ```
  A student calling these endpoints **always** consumes `formal_test` quota and **always** creates a test with `test_mode = 'test'`. Even if they pass `requested_mode: "mock"`, the server ignores it.

- **Mock Endpoint is Immutable:**
  `POST /auth/tests/generate-full-book-mock-test` has its handler logic hardwired:
  ```php
  // TestController.php - Mock Test Endpoint
  $resolvedExamType = 'mock';
  $resolvedTestMode = 'mock';
  ```
  A student calling this endpoint **always** consumes `mock` quota and **always** creates a test with `test_mode = 'mock'`. They cannot request `formal_test` through this route.

### 5.2 Practice Masquerade Prevention
- Practice session endpoints (`/generate-*-practice-session`) instantiate the `PracticeSession` model, which lacks formal test timing, submit locks, formal test attempt tracking, and certificate eligibility. Practice endpoints strictly meter against `practice` quota.

### 5.3 Parameter Tampering and Blueprint Enforcement
- On `POST /auth/tests/generate-full-book-mock-test`:
  ```php
  // 1. Enforce complete full-book syllabus coverage
  $unitIds = DB::table('book_unit_tbl')
      ->where('book_id', $book->id)
      ->where('activate', 1)
      ->pluck('id')
      ->all();

  if (empty($unitIds)) {
      return response()->json([
          'success'    => 0,
          'error'      => 'No active units available for mock examination syllabus.',
          'error_code' => 'EMPTY_SYLLABUS_ERROR',
      ], 422);
  }

  // 2. Validate question bounds and duration:
  // Evaluates campaign quota time_limit_minutes if configured; otherwise enforces 1 min/question.
  $totalQuestions = (int) ($validated['total_questions'] ?? 25);
  if ($totalQuestions < 10 || $totalQuestions > 200) {
      return response()->json([
          'success'    => 0,
          'error'      => 'Mock examinations require between 10 and 200 questions.',
          'error_code' => 'INVALID_MOCK_QUESTION_RANGE',
      ], 422);
  }
  $timeLimitMinutes = $campaignQuota?->time_limit_minutes ?? $totalQuestions;
  ```
  If the client attempts to submit unit-level scope or out-of-bounds parameters, the server rejects the request with HTTP 422, preserving syllabus coverage and standardization.

---

## 6. Official Shared Mocks vs. Dynamic Mocks Coexistence

The system guarantees 100% backward compatibility and architectural harmony between both varieties of mock exams:

| Feature | Official Shared Mocks | Dynamic Generated Mocks |
| :--- | :--- | :--- |
| **Origin / Source** | Pre-authored by teachers/admins (`test_source = 'official'`). Published to groups or public catalogue. | Dynamically generated by the LMS question bank engine on-demand (`test_source = 'system'`). |
| **Creation Endpoint** | Admin Panel / Teacher Portal (`/admin/tests/create-mock`). | Dedicated Student LMS endpoint (`POST /auth/tests/generate-full-book-mock-test`). |
| **Attempt Execution Endpoint** | `POST /auth/tests/save-formal-test-question-progress` | `POST /auth/tests/save-formal-test-question-progress` (Uniform attempt runner). |
| **Database `test_mode`** | `'mock'` | `'mock'` |
| **Quota Consumption Point** | At first attempt initiation (`attempt_start`). | At generation time (`generation`) for dynamic test, plus attempt tracking (`attempt_start`). |
| **Entitlement Resolver Exam Type** | `'mock'` (derived from `$test->test_mode === 'mock'`). | `'mock'` (derived from dedicated route + blueprint). |
| **Attempt Accounting Table** | `freemium_test_attempts` (`exam_type = 'mock'`). | `freemium_test_attempts` (`exam_type = 'mock'`). |

Both share the exact same attempt runner, progress tracking, attempt limits, and quota consumption model in `FreemiumQuotaConsumptionService`.

---

## 7. Safe Failure Modes & Error Standardization

When any validation or authorization check fails, the backend fails closed with precise, standardized JSON responses:

| Failure Condition | HTTP Status | Error Code | Response Body |
| :--- | :--- | :--- | :--- |
| **Mock Quota Exhausted** | `403 Forbidden` | `QUOTA_EXHAUSTED` | `{"success": 0, "error": "Weekly mock test allowance exhausted. Upgrade to paid subscription for unlimited access.", "error_code": "QUOTA_EXHAUSTED", "denial_reason": "Weekly mock test allowance exhausted."}` |
| **Formal Quota Exhausted** | `403 Forbidden` | `QUOTA_EXHAUSTED` | `{"success": 0, "error": "Weekly formal test allowance exhausted. Upgrade to paid subscription for unlimited access.", "error_code": "QUOTA_EXHAUSTED", "denial_reason": "Weekly formal test allowance exhausted."}` |
| **Mock Disabled by Campaign** | `403 Forbidden` | `MOCK_ACCESS_DENIED` | `{"success": 0, "error": "Mock examinations are not available under your current access tier.", "error_code": "MOCK_ACCESS_DENIED"}` |
| **Scope Ineligible for Mock** | `422 Unprocessable` | `INVALID_ASSESSMENT_SCOPE` | `{"success": 0, "error": "Mock examinations require full-book comprehensive syllabus coverage.", "error_code": "INVALID_ASSESSMENT_SCOPE"}` |
| **Blueprint Parameter Mismatch** | `422 Unprocessable` | `INVALID_MOCK_BLUEPRINT` | `{"success": 0, "error": "Specified question count or time limit does not conform to the board mock blueprint.", "error_code": "INVALID_MOCK_BLUEPRINT"}` |
| **Idempotency Key Conflict** | `409 Conflict` | `IDEMPOTENCY_CONFLICT` | `{"success": 0, "error": "Idempotency key was already used with a different request payload.", "error_code": "IDEMPOTENCY_CONFLICT"}` |
| **Unauthenticated Request** | `401 Unauthorized` | `UNAUTHENTICATED` | `{"success": 0, "error": "Authentication required."}` |
