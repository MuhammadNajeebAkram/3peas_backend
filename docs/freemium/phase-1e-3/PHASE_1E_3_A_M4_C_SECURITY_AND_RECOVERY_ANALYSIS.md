# Stage 1E.3.A — Milestone 4 Phase 4C Security & Recovery Analysis
## Exception Handling, Network Resiliency, and Security Controls (Clarified Revision)

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M4_C_SECURITY_AND_RECOVERY_ANALYSIS.md`
- **Revision Date:** November 20, 2026 (Clarified Revision)
- **Primary Repository:** `E:\Al-Faraabi Software\al-faraabi-website` (Student LMS Frontend)
- **Reference Repository (Read-Only):** `E:\Al-Faraabi Software\3peas_backend` (Laravel Backend)
- **Scope:** Phase 4C Planning & Source Inspection ONLY

---

## 1. Attempt ID Security Verification

Detailed inspection of `TestController::saveFormalTestQuestionProgressForLms` in `3peas_backend` lines 1005–1240 established the following authoritative security controls:

### 1.1 Acceptance & Validation of `attempt_id`
- `attempt_id` is validated as: `'attempt_id' => ['nullable', 'integer', 'exists:test_attempts,id']`.
- If omitted (initial question answer), the server locates the active in-progress attempt for that student/test, or creates a new one, returning `attempt_id` in the 200 response.

### 1.2 Ownership & Cross-Test ID Substitution Prevention
```php
// TestController.php:1056-1068
if (!empty($validated['attempt_id'])) {
    $attemptCheck = TestAttempt::query()
        ->where('id', $validated['attempt_id'])
        ->where('test_id', $test->id)
        ->where('user_id', $user->id)
        ->first();

    if (!$attemptCheck) {
        return response()->json([
            'success' => 0,
            'error'   => 'Test attempt not found or access denied.',
        ], 404);
    }
}
```
- **Ownership Verification:** The query strictly includes `where('user_id', $user->id)`. A student cannot reference another student's attempt.
- **Cross-Test Isolation:** The query strictly includes `where('test_id', $test->id)`. A student cannot reference an attempt belonging to a different test.
- **Failure Mode:** Any cross-student or cross-test substitution returns HTTP 404 `'Test attempt not found or access denied.'`.

### 1.3 Submitted Attempt Rejection (HTTP 409)
```php
// TestController.php:1070-1076
if ($attemptCheck->status === 'submitted') {
    return response()->json([
        'success'    => 0,
        'error'      => 'Test attempt has already been submitted.',
        'error_code' => 'ATTEMPT_ALREADY_SUBMITTED',
    ], 409);
}
```
- An attempt that has already been submitted cannot receive further question progress.
- Catching this in `persistQuestionProgress` allows the frontend to suppress false error toasts when progress saves arrive late during finalization.

### 1.4 Progress Linkage
- Answers are updated or inserted via:
  ```php
  TestAttemptQuestion::updateOrCreate(
      ['attempt_id' => $attempt->id, 'question_id' => $testQuestionRow->question_id],
      [...]
  );
  ```
- Individual marks, answer selection, and time spent are atomically tied to the validated `$attempt->id`.

---

## 2. In-Progress Attempt Recovery Investigation

Inspection of all existing routes in `routes/web_api.php` and controllers (`TestController`, `StudentFreemiumQuotaController`, `PracticeSessionController`) evaluated the feasibility of full in-progress attempt recovery:

| Required Recovery Item | Existing API Endpoint | Exposes Item? | Limitations / Status |
|---|---|---|---|
| **Attempt ID & Status** | `GET /freemium/tests/{id}/eligibility` | **YES** | Returns `has_active_attempt: bool` and `active_attempt_id: int`. |
| **Attempt Number** | `GET /freemium/tests/{id}/eligibility` | **YES** | Returns `attempt_number: int`. |
| **Saved Answers** | None in `TestController` | **NO** | Backend exposes no endpoint returning `TestAttemptQuestion` rows for self-generated tests. |
| **Question Sequence & IDs** | None in `TestController` | **NO** | `responseData.question_ids` is only returned upon initial test generation. |
| **Current Question Position** | None | **NO** | Cursor position is not stored on server. |
| **Remaining Time** | None | **NO** | `started_at` is stored in DB but not returned in `testEligibility`. |

### Architectural Boundary & Backend Dependency:
- **Full Recovery Backend Dependency:** For a student to fully resume an attempt after losing `sessionStorage` (e.g., across devices or incognito sessions), the backend requires a dedicated endpoint: `GET /web_api/auth/tests/{testId}/active-attempt` returning `{ test, attempt, questions, saved_answers, started_at }`.
- **Frontend Safe Boundary:**
  - The frontend MUST NOT fabricate missing questions or answers.
  - If `sessionStorage` is lost on reload, the runner checks `getTestEligibility(attemptId)`.
  - If the test was already finalized, it redirects to the results page (`/lms/tests/test/${testId}/result`).
  - If the attempt is still in progress on the server, the runner surfaces a graceful storage-loss alert advising the student to return to the Tests Hub or complete their attempt from the original session, preventing attempt corruption.

---

## 3. Eligibility Verification across Student Tiers

The preflight endpoint `GET /web_api/auth/freemium/tests/{testId}/eligibility` provides a unified contract across all student tiers:

1. **Paid Students:** Returns `can_attempt: true, is_paid: true, max_attempts_per_test: null, allow_retakes: true`. The frontend displays `<AttemptNumberBadge isPaid={true} attemptNumber={res.attemptNumber} />`.
2. **Freemium Students (Permitted):** Returns `can_attempt: true, is_paid: false, max_attempts_per_test: N, remaining_attempts: R`. The frontend displays `<AttemptNumberBadge attemptNumber={res.attemptNumber} maxAttempts={N} />`.
3. **Freemium Students (Exhausted / Denied):** Returns `can_attempt: false, denial_reason: '...'`. The frontend halts question loading and mounts `<QuotaExhaustedDialog>`.
4. **Students with Active Attempt:** Returns `has_active_attempt: true, active_attempt_id: <id>`. The frontend adopts `active_attempt_id` for all progress saves.

---

## 4. Assessment Source Enum Values

The `tests.test_source` database column is strictly defined as:
```sql
test_source ENUM('system', 'teacher', 'official') DEFAULT 'system'
```
- All self-generated student formal and mock tests use `'system'`.
- All administrator/curriculum shared tests use `'official'`.
- The previously mentioned term `'custom'` was an informal planning term and is formally replaced with `'system'`.
