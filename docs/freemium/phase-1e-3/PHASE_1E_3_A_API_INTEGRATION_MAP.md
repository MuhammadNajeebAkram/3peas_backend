# Stage 1E.3.A — Freemium API Integration Map

**Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_API_INTEGRATION_MAP.md`  
**Execution Date:** 2026-11-20  
**Target Systems:** Laravel Backend (`3peas_backend`) $\longleftrightarrow$ Student LMS (`al-faraabi-website`)  
**Base URL:** `${NEXT_PUBLIC_LMS_API_BASE_URL}` (`http://localhost:8000/web_api` or `https://api.thestudentstimes.com/web_api`)  
**Status:** Planning & Specification  

---

## 1. Overview & Authentication Specification

All student-facing Freemium APIs operate under the `auth:web_api` middleware guard in `routes/web_api.php`.  
The Next.js client interacts with these endpoints via `apiFetch(path, options)` in `src/lib/api.js`, which automatically includes cookies (`credentials: "include"`) and sets `Accept: application/json`.

---

## 2. Freemium Endpoints Integration Map

### 2.1 Student Entitlements Discovery
- **Endpoint:** `GET /web_api/auth/freemium/entitlements`
- **Frontend Path:** `/auth/freemium/entitlements`
- **HTTP Method:** `GET`
- **Request Parameters:** None
- **Response Structure (200 OK):**
  ```json
  {
    "success": 1,
    "data": {
      "student": {
        "id": 1234,
        "name": "Ahmad Ali"
      },
      "active_paid_subscriptions": [
        {
          "program_id": 10,
          "program_title": "FSC Pre-Medical Part 1",
          "program_slug": "fsc-pre-medical-part-1"
        }
      ],
      "freemium_access": {
        "has_active_campaign": true,
        "campaign_display_name": "Autumn 2026 National Free Access",
        "valid_until": "2026-12-31T23:59:59Z"
      },
      "academic_scopes": {
        "covered_program_ids": [10]
      }
    }
  }
  ```
- **Error Codes:** `401 Unauthorized` (unauthenticated), `403 Forbidden` (non-student / inactive).
- **LMS Usage:** Injected into `AuthContext` and user profile/dashboard to display active account tier badge ("Paid Subscriber", "Active Campaign: Autumn 2026", or "Freemium User").

---

### 2.2 Free Academic Resources Discovery
- **Endpoint:** `GET /web_api/auth/freemium/free-resources`
- **Frontend Path:** `/auth/freemium/free-resources`
- **HTTP Method:** `GET`
- **Query Parameters:**
  - `class_id` (optional integer)
  - `curriculum_board_id` (optional integer)
  - `subject_id` (optional integer)
  - `dimension` (optional string, default `'perm_view'`)
  - `per_page` (optional integer, default `15`, max `50`)
- **Response Structure (200 OK):**
  ```json
  {
    "success": 1,
    "data": [
      {
        "book_id": 42,
        "book_name": "Biology Grade 11",
        "class_id": 1,
        "class_name": "Grade 11",
        "subject_id": 5,
        "subject_name": "Biology",
        "is_accessible": true,
        "access_tier": "tier2_freemium",
        "upgrade_required": false,
        "subscription_url": null
      }
    ],
    "meta": {
      "current_page": 1,
      "per_page": 15,
      "total": 48,
      "last_page": 4
    }
  }
  ```
- **LMS Usage:** Merged with paid subscriptions in `tests/page.jsx` to populate program and subject dropdowns when a student has no paid subscription.

---

### 2.3 Quota Allowances & Reset Discovery
- **Endpoint:** `GET /web_api/auth/freemium/quota-allowances`
- **Frontend Path:** `/auth/freemium/quota-allowances`
- **HTTP Method:** `GET`
- **Query Parameters:**
  - `exam_type` (optional: `'formal_test'`, `'mock'`, `'practice'`)
- **Response Structure (200 OK):**
  ```json
  {
    "success": 1,
    "data": {
      "quotas": [
        {
          "exam_type": "formal_test",
          "window_type": "weekly",
          "window_key": "PK:legacy:weekly:2026-W47",
          "is_unlimited": false,
          "max_tests": 1,
          "used_tests": 0,
          "remaining_tests": 1,
          "allow_retakes": false,
          "max_attempts_per_test": 1,
          "resets_at": "2026-11-23T19:00:00.000000Z",
          "provider": "legacy_fallback"
        },
        {
          "exam_type": "mock",
          "window_type": "weekly",
          "window_key": "PK:legacy:weekly:2026-W47",
          "is_unlimited": false,
          "max_tests": 1,
          "used_tests": 0,
          "remaining_tests": 1,
          "allow_retakes": false,
          "max_attempts_per_test": 1,
          "resets_at": "2026-11-23T19:00:00.000000Z",
          "provider": "legacy_fallback"
        },
        {
          "exam_type": "practice",
          "window_type": "weekly",
          "window_key": "PK:legacy:weekly:2026-W47",
          "is_unlimited": false,
          "max_tests": 2,
          "used_tests": 1,
          "remaining_tests": 1,
          "allow_retakes": false,
          "max_attempts_per_test": 1,
          "resets_at": "2026-11-23T19:00:00.000000Z",
          "provider": "legacy_fallback"
        }
      ]
    }
  }
  ```
- **LMS Usage:** Rendered in `FreemiumQuotaBanner` across the tests hub, launch screen, and practice workspace. Displays remaining tests, quota provider, and a live countdown to `resets_at`.

---

### 2.4 Test Attempt & Retake Eligibility Check
- **Endpoint:** `GET /web_api/auth/freemium/tests/{testId}/eligibility`
- **Frontend Path:** `/auth/freemium/tests/${testId}/eligibility`
- **HTTP Method:** `GET`
- **Response Structure (200 OK — Allowed):**
  ```json
  {
    "success": 1,
    "data": {
      "test_id": 999,
      "test_mode": "test",
      "can_attempt": true,
      "eligible": true,
      "is_retake": true,
      "attempt_number": 2,
      "max_attempts_per_test": 2,
      "remaining_attempts": 1,
      "allow_retakes": true,
      "is_paid": false,
      "has_active_attempt": false,
      "active_attempt_id": null,
      "test_source": "official",
      "is_published": true,
      "denial_reason": null
    }
  }
  ```
- **Response Structure (200 OK — Denied):**
  ```json
  {
    "success": 1,
    "data": {
      "test_id": 999,
      "test_mode": "test",
      "can_attempt": false,
      "eligible": false,
      "is_retake": true,
      "attempt_number": 3,
      "max_attempts_per_test": 2,
      "remaining_attempts": 0,
      "allow_retakes": true,
      "denial_reason": "Maximum permitted attempts (2) reached for this test."
    }
  }
  ```
- **Error Codes:** `404 Not Found` (`code: 'test_not_found'` when test does not exist or belongs to another student).
- **LMS Usage:** Invoked when student clicks "Try Again" or opens a shared official test link. Preflights the attempt without mutating quota.

---

### 2.5 Preflight Access Evaluation
- **Endpoint:** `POST /web_api/auth/freemium/evaluate-access`
- **Frontend Path:** `/auth/freemium/evaluate-access`
- **HTTP Method:** `POST`
- **Payload:**
  ```json
  {
    "offered_program_id": 10,
    "subject_id": 5,
    "dimension": "perm_mcqs",
    "exam_type": "formal_test"
  }
  ```
- **Response Structure (200 OK):**
  ```json
  {
    "success": 1,
    "data": {
      "granted": true,
      "tier": "tier1_paid",
      "dimension": "perm_mcqs",
      "action": "allow",
      "requires_quota_evaluation": false,
      "exam_type": "formal_test",
      "upgrade_required": false,
      "subscription_url": null,
      "message": "Access granted."
    }
  }
  ```
- **LMS Usage:** Used when navigating into locked study units or topic question banks.

---

## 3. Assessment Lifecycle Endpoints & Idempotency Specification

### 3.1 Idempotency Key Standard
- **Format:** Canonical UUIDv4 (lowercase hexadecimal, 8-4-4-4-12 characters, e.g. `e3d4c5b6-a789-4012-b345-6789abcdef01`).
- **Transmission:** Both:
  1. HTTP Header: `Idempotency-Key: <uuidv4>`
  2. JSON Body Field: `"idempotency_key": "<uuidv4>"`
- **Lifecycle:** Generated when the user initiates generation or clicks submit. Reused if the request is retried on network failure. Replaced if the user cancels and starts a new test.

### 3.2 Formal Test Generation
- **Endpoints:**
  - `POST /web_api/auth/tests/generate-selected-units-formal-test`
  - `POST /web_api/auth/tests/generate-full-book-formal-test`
- **Payload:**
  ```json
  {
    "offered_program_id": 10,
    "subject_id": 5,
    "unit_ids": [101, 102],
    "total_questions": 15,
    "time_limit_minutes": 15,
    "scope_type": "multiple_chapters",
    "idempotency_key": "3f8e6c12-9a3b-4c5d-ae67-123456789abc"
  }
  ```
- **Error Codes:**
  - `403 Forbidden`: Quota exhausted (`"Weekly formal test quota (1) exhausted for this window."`).
  - `409 Conflict`: Idempotency collision with different payload.
  - `422 Unprocessable Entity`: Validation failure or insufficient questions.

### 3.3 Test Question Progress Persistence
- **Endpoint:** `POST /web_api/auth/tests/save-formal-test-question-progress`
- **Payload:**
  ```json
  {
    "test_id": 999,
    "question_id": 501,
    "selected_option_id": 2004,
    "time_spent_seconds": 32,
    "started_at": "2026-11-20T07:15:00Z"
  }
  ```
- **Behavior:**
  - Automatically initializes `TestAttempt` on first question save if not present.
  - Dedicates quota counter atomically inside retry-protected transaction.
  - Subsequent question saves consume 0 quota.

### 3.4 Test Submission
- **Endpoint:** `POST /web_api/auth/tests/submit-formal-test`
- **Headers:** `Idempotency-Key: <uuidv4>`
- **Payload:**
  ```json
  {
    "test_id": 999,
    "started_at": "2026-11-20T07:15:00Z",
    "submitted_at": "2026-11-20T07:30:00Z",
    "questions": [
      { "question_id": 501, "selected_option_id": 2004 }
    ],
    "idempotency_key": "<uuidv4>"
  }
  ```
- **Behavior:** Finalizes attempt, grades MCQs, records audit envelope. Repeated submissions with the same key safely replay the stored result.

### 3.5 Practice Session Generation & Submission
- **Endpoints:**
  - `POST /web_api/auth/tests/generate-selected-units-practice-session`
  - `POST /web_api/auth/tests/generate-full-book-practice-session`
  - `POST /web_api/auth/tests/save-practice-session-question-progress`
  - `POST /web_api/auth/tests/submit-practice-session`
- **Payloads:** Send `session_id`, `question_id`, `selected_option_id`, `answer_shown: boolean`, `idempotency_key: <uuidv4>`.
- **Behavior:** Practice generation consumes practice quota. Resumption and repeated progress saves consume 0 quota.

### 3.6 Recent Attempts Retrieval
- **Endpoint:** `GET /web_api/auth/tests/get-recent-attempts`
- **Query Parameters:** `offered_program_id`, `subject_id`, `limit` (default `10`).
- **Response Structure:**
  ```json
  {
    "success": 1,
    "data": [
      {
        "id": 105,
        "test_id": 999,
        "attempt_number": 2,
        "is_retake": true,
        "attempt_type": "formal_test",
        "test_mode": "test",
        "title": "Grade 11 Biology Test",
        "scope_type": "multiple_chapters",
        "total_questions": 15,
        "attempted_questions": 15,
        "score": 13,
        "accuracy": 86.67,
        "submitted_at": "2026-11-20T07:30:00Z"
      }
    ]
  }
  ```
- **LMS Mapping:** Maps `attempt_number`, `is_retake`, and `test_mode` directly to table columns and badges.
