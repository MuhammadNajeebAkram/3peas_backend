# Stage 1E.3.A — Milestone 4 Phase 4C API Contract Mapping
## Assessment Execution, Preflight Eligibility & Progress API Specification (Clarified Revision)

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M4_C_API_CONTRACT_MAPPING.md`
- **Revision Date:** November 20, 2026 (Clarified Revision)
- **Primary Repository:** `E:\Al-Faraabi Software\al-faraabi-website` (Student LMS Frontend)
- **Reference Repository (Read-Only):** `E:\Al-Faraabi Software\3peas_backend` (Laravel Backend)
- **Scope:** Phase 4C Planning & Source Inspection ONLY

---

## 1. Authoritative API Inventory for Attempt Runner

All endpoints operate on `3peas_backend` within the `web_api` route group with JWT authentication cookies (`AttachJwtFromCookie` + `AuthenticateJwtCookieGuard`).

| Operation | Method | Route | Controller & Action | Quota Impact | Idempotency Header |
|---|---|---|---|---|---|
| **Preflight Attempt Eligibility** | `GET` | `/web_api/auth/freemium/tests/{testId}/eligibility` | `StudentFreemiumQuotaController::testEligibility` | Read-only | N/A |
| **Save Formal/Mock Progress** | `POST` | `/web_api/auth/tests/save-formal-test-question-progress` | `TestController::saveFormalTestQuestionProgressForLms` | Creates `attempt_start` / `retake_start` on first question. **No weekly test decrement**. | Recommended / Optional |
| **Submit Formal/Mock Test** | `POST` | `/web_api/auth/tests/submit-formal-test` | `TestController::submitFormalTestForLms` | Finalizes `TestAttempt`. Native idempotency replay. | Recommended / Optional |
| **Save Practice Progress** | `POST` | `/web_api/auth/tests/save-practice-session-question-progress` | `PracticeSessionController::savePracticeSessionQuestionProgressForLms` | Read/write practice session. **No weekly test decrement**. | Optional |
| **Submit Practice Session** | `POST` | `/web_api/auth/tests/submit-practice-session` | `PracticeSessionController::submitPracticeSessionForLms` | Finalizes `PracticeSession`. | Optional |
| **Unit Topics Retrieval** | `GET` | `/web_api/auth/my-classes/get-topics-by-units/{unitId}/topics` | `TopicsController::getTopicsByUnitForLMS` | Read-only; checks `UnifiedEntitlementResolver`. | N/A |
| **Topic Questions Retrieval** | `GET` | `/web_api/auth/my-classes/get-questions-by-topic/{topicId}/questions` | `QuestionsController::getQuestionsByTopicForLMS` | Read-only; checks `UnifiedEntitlementResolver`. | N/A |

---

## 2. Detailed Endpoint Contracts

### 2.1 Preflight Attempt Eligibility Check
- **Endpoint:** `GET /web_api/auth/freemium/tests/{testId}/eligibility`
- **Controller Action:** `StudentFreemiumQuotaController::testEligibility`
- **Backend Service:** `FreemiumQuotaConsumptionService::getTestAttemptEligibility`
- **Route Constraints:** `whereNumber('testId')`
- **Authentication:** Student JWT Cookie (`role === 'student'`)

#### Request:
- Headers: `Accept: application/json`
- URL Parameters: `testId` (integer, required)

#### Response Contract across Student Tiers:

**Scenario A: Paid Student with Active Subscription**
```json
{
  "success": 1,
  "data": {
    "test_id": 789,
    "test_mode": "test",
    "can_attempt": true,
    "eligible": true,
    "is_retake": false,
    "attempt_number": 1,
    "max_attempts_per_test": null,
    "remaining_attempts": null,
    "allow_retakes": true,
    "is_paid": true,
    "has_active_attempt": false,
    "active_attempt_id": null,
    "test_source": "system",
    "is_published": true,
    "denial_reason": null
  }
}
```

**Scenario B: Freemium Student with Available Allowance (Initial Mock Attempt)**
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
    "remaining_attempts": 0,
    "allow_retakes": false,
    "is_paid": false,
    "has_active_attempt": false,
    "active_attempt_id": null,
    "test_source": "system",
    "is_published": true,
    "denial_reason": null
  }
}
```

**Scenario C: Freemium Student with Existing In-Progress Active Attempt**
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
    "remaining_attempts": 0,
    "allow_retakes": false,
    "is_paid": false,
    "has_active_attempt": true,
    "active_attempt_id": 4052,
    "test_source": "system",
    "is_published": true,
    "denial_reason": null
  }
}
```

**Scenario D: Freemium Student with Max Attempts Reached / Retakes Disallowed**
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
    "allow_retakes": false,
    "is_paid": false,
    "has_active_attempt": false,
    "active_attempt_id": null,
    "test_source": "system",
    "is_published": true,
    "denial_reason": "Retakes are not permitted for this test."
  }
}
```

**Scenario E: Student with Expired Campaign or Invalid Entitlement**
```json
{
  "success": 1,
  "data": {
    "test_id": 901,
    "test_mode": "mock",
    "can_attempt": false,
    "eligible": false,
    "is_retake": false,
    "attempt_number": 1,
    "max_attempts_per_test": 0,
    "remaining_attempts": 0,
    "allow_retakes": false,
    "is_paid": false,
    "has_active_attempt": false,
    "active_attempt_id": null,
    "test_source": "system",
    "is_published": true,
    "denial_reason": "Your free campaign access has expired. Please upgrade to continue."
  }
}
```

#### What `GET /tests/{testId}/eligibility` DOES NOT Return:
- **Saved answers:** Does not return selected MCQ options for questions answered so far.
- **Question sequence:** Does not return the array of `question_ids` or `unit_ids`.
- **Current question position:** Does not return the last viewed question index.
- **Remaining time:** Does not calculate or return remaining seconds.

---

### 2.2 Formal & Mock Question Progress Persistence
- **Endpoint:** `POST /web_api/auth/tests/save-formal-test-question-progress`
- **Controller Action:** `TestController::saveFormalTestQuestionProgressForLms`
- **Scope:** Used for BOTH formal tests (`test_mode = 'test'`) and mock exams (`test_mode = 'mock'`).

#### Request Validation & Fields:
```php
// TestController.php:1007-1014
$validated = $request->validate([
    'test_id'            => ['required', 'integer', 'exists:tests,id'],
    'question_id'        => ['required', 'integer', 'exists:exam_question_tbl,id'],
    'selected_option_id' => ['nullable', 'integer', 'exists:exam_question_options_tbl,id'],
    'time_spent_seconds' => ['nullable', 'integer', 'min:0'],
    'started_at'         => ['nullable', 'date'],
    'attempt_id'         => ['nullable', 'integer', 'exists:test_attempts,id'],
]);
```

#### Security & Validation Logic:
1. **Student Account Active:** Validates `$user->role === 'student' && $user->status === 'active'`. (403 if invalid).
2. **Test Ownership / Access:** Validates that `$test` is either official shared (`test_source === 'official' && is_published`) or student-owned (`created_by === $user->id`, or exists in quota consumption/attempt). (404 if denied).
3. **Question Association:** Validates question belongs to the test via `getTestQuestionRow($test->id, $question_id)`. (422 if invalid).
4. **Attempt ID Ownership & Cross-Test Isolation:**
   ```php
   // TestController.php:1057-1068
   $attemptCheck = TestAttempt::query()
       ->where('id', $validated['attempt_id'])
       ->where('test_id', $test->id)
       ->where('user_id', $user->id)
       ->first();
   if (!$attemptCheck) {
       return response()->json(['success' => 0, 'error' => 'Test attempt not found or access denied.'], 404);
   }
   ```
   **Prevents cross-student and cross-test ID substitution.**
5. **Submitted Attempt Rejection:**
   ```php
   if ($attemptCheck->status === 'submitted') {
       return response()->json([
           'success'    => 0,
           'error'      => 'Test attempt has already been submitted.',
           'error_code' => 'ATTEMPT_ALREADY_SUBMITTED',
       ], 409);
   }
   ```
6. **Entitlement Resolution:** Evaluates `UnifiedEntitlementResolver::resolve()`. (403 if denied).
7. **Attempt Initialization on First Answer:** If no active attempt exists, creates `TestAttempt` with `status: 'in_progress'` and logs quota consumption event (`attempt_start` or `retake_start`). **Does not decrement weekly test generation counter.**

#### Success Response (200 OK):
```json
{
  "success": 1,
  "data": {
    "attempt_id": 4052,
    "test_id": 901,
    "question_id": 5001,
    "is_attempted": true,
    "is_correct": true,
    "status": "in_progress",
    "attempted_questions": 1,
    "correct_answers": 1,
    "wrong_answers": 0,
    "not_attempted_questions": 59,
    "score": 1.0,
    "accuracy": 100.0
  }
}
```
*Note for Frontend:* The frontend captures `data.attempt_id` from the first question save response and passes it on all subsequent saves!

---

### 2.3 Formal & Mock Test Submission
- **Endpoint:** `POST /web_api/auth/tests/submit-formal-test`
- **Controller Action:** `TestController::submitFormalTestForLms`
- **Scope:** Used for BOTH formal tests and mock exams.

#### Request Payload:
```json
{
  "test_id": 901,
  "attempt_id": 4052,
  "started_at": "2026-11-20T14:30:00Z",
  "submitted_at": "2026-11-20T15:30:00Z",
  "questions": [
    {
      "question_id": 5001,
      "selected_option_id": 20042,
      "time_spent_seconds": 25
    }
  ]
}
```

#### Native Idempotent Submission Replay:
In `TestController.php` lines 1580–1597:
```php
if ($attempt && $attempt->status === 'submitted') {
    return response()->json([
        'success' => 1,
        'data'    => [
            'attempt_id'              => $attempt->id,
            'test_id'                 => $test->id,
            'attempted_questions'     => $attempt->attempted_questions,
            'correct_answers'         => $attempt->correct_answers,
            'wrong_answers'           => $attempt->wrong_answers,
            'not_attempted_questions' => $attempt->not_attempted_questions,
            'score'                   => (float) $attempt->score,
            'accuracy'                => (float) $attempt->accuracy,
            'submitted_at'            => $attempt->submitted_at ? Carbon::parse(...)->toISOString() : null,
            'status'                  => $attempt->status,
        ],
    ]);
}
```
If an attempt is already finalized, it returns the stored result with `success: 1` rather than returning an error.

---

## 3. Valid Database Enum Constants Verification

Inspection of `database/migrations/2026_04_18_093707_create_tests_table.php` and `database/migrations/2026_04_17_193021_create_test_attempts_table.php` confirms:

```sql
-- Valid test_source values:
test_source ENUM('system', 'teacher', 'official') DEFAULT 'system'

-- Valid test_mode values:
test_mode ENUM('test', 'mock')
```

| Enum Constant | Role in Platform | Generation Source |
|---|---|---|
| `'system'` | Self-generated student assessments | `TestController::generateFullBookFormalTestForLms`, `TestController::generateFullBookMockTestForLms` |
| `'official'` | Staff/Admin shared assessments | Created via Admin Panel or database seeders; published with `is_published = true` |
| `'teacher'` | Teacher-assigned class tests | Created via Teacher Portal for specific class assignments |

*Resolution of `'custom'` Discrepancy:* The string `'custom'` does **not** exist in the database enum or PHP models. It was used informally in earlier planning notes. All technical contracts and documentation must strictly use `'system'`.
