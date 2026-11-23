# Stage 1E.3.A — Milestone 4 API Contract & Request/Response Mapping
## Test Generation, Execution, Idempotency, and Attempt Eligibility Specification

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M4_API_CONTRACT_MAPPING.md`
- **Execution Date:** November 20, 2026 (Revised)
- **Target Repository (Primary):** `E:\Al-Faraabi Software\al-faraabi-website` (`thestudentstimes.git`)
- **Backend Reference (Read-Only):** `E:\Al-Faraabi Software\3peas_backend`
- **Status:** Read-Only Architectural Specification (Security-Hardened Revision)

---

## 1. Authoritative API Inventory

All endpoints run on the `3peas_backend` under the `web_api` route group with JWT authentication cookies (`AttachJwtFromCookie` + `AuthenticateJwtCookieGuard`). All requests mutating state or consuming quota must include the `X-Idempotency-Key` header with a valid UUIDv4.

| Operation | Method | Route | Controller & Action | Quota Bucket |
| :--- | :--- | :--- | :--- | :--- |
| **Formal Test (Full Book)** | `POST` | `/web_api/auth/tests/generate-full-book-formal-test` | `TestController::generateFullBookFormalTestForLms` | `formal_test` |
| **Formal Test (Selected Units)** | `POST` | `/web_api/auth/tests/generate-selected-units-formal-test` | `TestController::generateSelectedUnitsFormalTestForLms` | `formal_test` |
| **Dynamic Mock Exam (Full Book)** | `POST` | `/web_api/auth/tests/generate-full-book-mock-test` | `TestController::generateFullBookMockTestForLms` | `mock` |
| **Practice Session (Full Book)** | `POST` | `/web_api/auth/tests/generate-full-book-practice-session` | `PracticeSessionController::generateFullBookPracticeSession` | `practice` |
| **Practice Session (Selected Units)**| `POST` | `/web_api/auth/tests/generate-selected-units-practice-session` | `PracticeSessionController::generateSelectedUnitsPracticeSession` | `practice` |
| **Pre-Flight Attempt Eligibility** | `GET` | `/web_api/auth/freemium/tests/{testId}/eligibility` | `StudentFreemiumAccessController::testEligibility` | Read-Only |
| **Attempt Progress & Start** | `POST` | `/web_api/auth/tests/save-formal-test-question-progress` | `TestController::saveFormalTestQuestionProgressForLms` | Derived (`$test->test_mode`) |
| **Attempt Finalization** | `POST` | `/web_api/auth/tests/submit-formal-test-for-lms` | `TestController::submitFormalTestForLms` | Read-Only / Complete |

---

## 2. Detailed Contract Specifications

### 2.1 Formal Test Generation (Full Book)
Generates a formal test evaluating the entire textbook. Strictly consumes `formal_test` quota.

- **Endpoint:** `POST /web_api/auth/tests/generate-full-book-formal-test`
- **Headers:**
  - `Accept: application/json`
  - `Content-Type: application/json`
  - `X-Idempotency-Key: <UUIDv4>` *(Mandatory for deduplication)*
- **Request Payload:**
  ```json
  {
    "offered_program_id": 101,          // int, nullable, min:1 (OMITTED for synthetic IDs)
    "subject_id": 501,                  // int, required, exists:subject_tbl,id
    "book_id": 42,                      // int, required, exists:book_tbl,id
    "total_questions": 25,              // int, required, min:1
    "time_limit_minutes": 35,           // int, nullable, min:1
    "mcq_format": "all"                 // string, nullable, in:all,straight,scenario
  }
  ```
- **Response Contracts:**
  - **200 OK (Created or Replayed):**
    ```json
    {
      "success": 1,
      "data": {
        "test_id": 789,
        "title": "Full Book Formal Test - Physics 11",
        "test_mode": "test",
        "scope_type": "full_book",
        "time_limit_minutes": 35,
        "total_questions": 25,
        "question_ids": [4501, 4502, 4503],
        "is_published": true
      }
    }
    ```
  - **403 Forbidden (Formal Quota Exhausted):**
    ```json
    {
      "success": 0,
      "error": "Weekly formal test allowance exhausted. Upgrade to paid subscription for unlimited access.",
      "error_code": "QUOTA_EXHAUSTED",
      "denial_reason": "Weekly formal test allowance exhausted."
    }
    ```
  - **409 Conflict (Idempotency Payload Mismatch):**
    ```json
    {
      "success": 0,
      "error": "Idempotency key 'a1b2c3d4...' was already used with a different request payload.",
      "error_code": "IDEMPOTENCY_CONFLICT"
    }
    ```

---

### 2.2 Formal Test Generation (Selected Units)
Generates a formal test for one or more specific chapters. Strictly consumes `formal_test` quota.

- **Endpoint:** `POST /web_api/auth/tests/generate-selected-units-formal-test`
- **Headers:** Same as 2.1.
- **Request Payload:**
  ```json
  {
    "offered_program_id": 101,          // int, nullable, min:1 (OMITTED for synthetic IDs)
    "subject_id": 501,                  // int, required, exists:subject_tbl,id
    "unit_ids": [101, 102],             // array of int, required, min:1
    "total_questions": 15,              // int, required, min:1
    "time_limit_minutes": 20,           // int, nullable, min:1
    "scope_type": "multiple_chapters"   // string, required, in:chapter,multiple_chapters
  }
  ```
- **Response Contracts:** Same error codes as 2.1. If client sends non-existent `unit_ids`, returns 422 Unprocessable Entity.

---

### 2.3 Dynamic Mock Exam Generation (Dedicated Route)
Generates a full-book board mock examination complying with server blueprints. Strictly consumes `mock` quota.

- **Endpoint:** `POST /web_api/auth/tests/generate-full-book-mock-test`
- **Headers:**
  - `Accept: application/json`
  - `Content-Type: application/json`
  - `X-Idempotency-Key: <UUIDv4>` *(Mandatory)*
- **Request Payload:**
  ```json
  {
    "offered_program_id": 101,          // int, nullable, min:1 (OMITTED for synthetic IDs)
    "subject_id": 501,                  // int, required, exists:subject_tbl,id
    "book_id": 42,                      // int, required, exists:book_tbl,id
    "total_questions": 60,              // int, optional - must match server board blueprint if provided
    "time_limit_minutes": 60,           // int, optional - must match server board blueprint if provided
    "mcq_format": "all"                 // string, nullable, in:all,straight,scenario
  }
  ```
- **Response Contracts:**
  - **200 OK (Created or Replayed):**
    ```json
    {
      "success": 1,
      "data": {
        "test_id": 901,
        "title": "Board Mock Examination - Physics 11",
        "test_mode": "mock",
        "scope_type": "full_book",
        "time_limit_minutes": 60,
        "total_questions": 60,
        "question_ids": [5001, 5002, 5003],
        "is_published": true
      }
    }
    ```
  - **403 Forbidden (Mock Quota Exhausted):**
    ```json
    {
      "success": 0,
      "error": "Weekly mock test allowance exhausted. Upgrade to paid subscription for unlimited access.",
      "error_code": "QUOTA_EXHAUSTED",
      "denial_reason": "Weekly mock test allowance exhausted."
    }
    ```
  - **403 Forbidden (Mock Disabled by Campaign Rule):**
    ```json
    {
      "success": 0,
      "error": "Mock examinations are not enabled for your current access tier.",
      "error_code": "MOCK_ACCESS_DENIED",
      "denial_reason": "Mock examinations are disabled for this curriculum under current rules."
    }
    ```
  - **422 Unprocessable Entity (Blueprint Mismatch):**
    ```json
    {
      "success": 0,
      "error": "Specified parameters do not match board mock blueprint: expected 60 questions, received 10.",
      "error_code": "INVALID_MOCK_BLUEPRINT"
    }
    ```

---

### 2.4 Practice Session Generation
Generates an unmetered/practice-metered interactive study session.

- **Endpoints:**
  - `POST /web_api/auth/tests/generate-full-book-practice-session`
  - `POST /web_api/auth/tests/generate-selected-units-practice-session`
- **Request Payload:** Same parameters as formal tests.
- **Response Contract (200 OK):**
  ```json
  {
    "success": 1,
    "data": {
      "session_id": 432,
      "total_questions": 20,
      "question_ids": [301, 302, 303],
      "session_mode": "practice"
    }
  }
  ```

---

### 2.5 Pre-Flight Attempt Eligibility Check
Enables the frontend to verify whether a test can be launched or retaken before mounting the runner.

- **Endpoint:** `GET /web_api/auth/freemium/tests/{testId}/eligibility`
- **Controller Action:** `StudentFreemiumAccessController::testEligibility`
- **Response Contract (200 OK):**
  ```json
  {
    "success": 1,
    "data": {
      "test_id": 901,
      "test_mode": "mock",
      "can_attempt": true,
      "eligible": true,
      "is_retake": false,
      "attempt_number": 1,
      "max_attempts_per_test": 1,
      "remaining_attempts": 1,
      "access_tier": "freemium",
      "quota_exhausted": false,
      "denial_reason": null
    }
  }
  ```
  - If attempt limit exceeded (e.g. mock exam already taken once):
    ```json
    {
      "success": 1,
      "data": {
        "test_id": 901,
        "test_mode": "mock",
        "can_attempt": false,
        "eligible": false,
        "is_retake": true,
        "attempt_number": 2,
        "max_attempts_per_test": 1,
        "remaining_attempts": 0,
        "access_tier": "freemium",
        "quota_exhausted": false,
        "denial_reason": "Maximum attempts reached for this mock examination (1 allowed)."
      }
    }
    ```

---

### 2.6 Attempt Progress & Start Handler
Called when the student begins answering questions in the runner. Records progress and logs attempt start.

- **Endpoint:** `POST /web_api/auth/tests/save-formal-test-question-progress`
- **Headers:** `X-Idempotency-Key: <UUIDv4>`
- **Request Payload:**
  ```json
  {
    "test_id": 901,
    "question_id": 5001,
    "selected_option_id": 20042,
    "time_spent_seconds": 25,
    "attempt_number": 1
  }
  ```
- **Authoritative Derivation:**
  Server inspects `Test::find($request->test_id)->test_mode`. If `'mock'`, derives `$examType = 'mock'`. If `'test'`, derives `$examType = 'formal_test'`.

---

## 3. Idempotency Key Specification and Headers

1. **Header Name:** `X-Idempotency-Key`
2. **Format:** Standard UUIDv4 string (e.g., `d3b07384-d113-46a2-9721-e93237e8c3b6`).
3. **Storage Table:** `freemium_idempotency_keys`
   - Columns: `idempotency_key` (VARCHAR 64, UNIQUE), `student_id` (BIGINT), `request_hash` (CHAR 64), `response_code` (INT), `response_body` (LONGTEXT), `created_at`, `expires_at`.
4. **Behavior:**
   - **First Arrival:** Server processes request in atomic database transaction, executes quota consumption, persists test record, saves response in cache, returns 200/201.
   - **Identical Replay:** Server detects existing valid key with identical payload hash (`sha256(payload)`). Returns cached 200 response immediately without consuming additional quota or duplicating entities.
   - **Mismatched Payload Conflict:** Server detects existing key with different payload hash. Returns HTTP 409 `IDEMPOTENCY_CONFLICT`.

---

## 4. Backward Compatibility and Migration Safety

1. **Legacy Web LMS & Mobile Apps:**
   - Calls to `/generate-full-book-formal-test` without `X-Idempotency-Key` continue to succeed (backward compatible; backend generates a server-side idempotency hash or skips replay cache).
   - Calls to `/generate-full-book-formal-test` always yield formal tests (`test_mode = 'test'`), exactly preserving legacy behavior.
2. **Admin Panel Compatibility:**
   - Admin mock creation (`test_source = 'official'`) is untouched and continues to write `test_mode = 'mock'` and publish mocks.
3. **No Breaking Route Removals:**
   - All existing routes are strictly preserved; the new dynamic mock route is an additive extension.
