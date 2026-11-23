# Stage 1E.2.D.D — Endpoint-by-Endpoint Enforcement Matrix

**Document ID:** `PHASE_1E_2_D_D_ENFORCEMENT_MATRIX`  
**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Stage:** 1E.2.D.D — Planning and Source Inspection ONLY  
**Timestamp:** 2026-11-19  

---

## 1. Complete Enforcement Matrix

| Endpoint Route & Method | Auth Middleware | Student Role & Status Check | Entitlement Scope Check | Quota Operation | Concurrency & Lock Mechanism | Immutability & Tamper Protection |
|---|---|---|---|---|---|---|
| `GET /tests/get-recent-attempts` | `auth:sanctum` | **GAP IDENTIFIED:** Currently missing `$user->role === 'student' && $user->status === 'active'`. Must be enforced. | Filtered strictly to `attempts.user_id = $student->id` and `sessions.user_id = $student->id`. | Read-Only (0 quota consumed, 0 counters updated). | Read-only query; no row lock needed. Protected against pagination injection (`min:1, max:20`). | Read-only projection of immutable submitted attempts (`status = 'submitted'`). |
| `GET /freemium/tests/{testId}/eligibility` | `auth:sanctum` | Enforced in `StudentFreemiumQuotaController@testEligibility`. | Strict check: `$isStudentOwned \|\| $isOfficialShared`. Unowned tests fail-closed with 404. | Read-Only (0 quota consumed, 0 counters updated). | Read-only query; reads existing ledger/attempts count. | Read-only query. |
| `GET /freemium/quota-allowances` | `auth:sanctum` | Enforced in `StudentFreemiumQuotaController@quotaAllowances`. | Evaluates active campaign grants, paid status, or fallback. | Read-Only (0 quota consumed, 0 counters updated). | Read-only query; no locks. | Read-only query. |
| `POST /tests/save-formal-test-question-progress` | `auth:sanctum` | Enforced via D.B.2 (`role === 'student' && status === 'active'`). | Strict check: `$isStudentOwned \|\| $isOfficialShared`. Paid students scoped to active program/subject. | First progress: `attempt_start` / `retake_start`. Subsequent: 0 quota consumed. | Serialized per `(user_id, test_id)` with `DB::transaction()` and `FOR UPDATE`. | Answers can only be written if attempt `status === 'in_progress'`. |
| `POST /tests/submit-formal-test` | `auth:sanctum` | Enforced via D.B.2 (`role === 'student' && status === 'active'`). | Strict check: `$isStudentOwned \|\| $isOfficialShared`. Paid students scoped. | 0 quota consumed (recorded at attempt start). | Serialized with `FOR UPDATE` on `test_attempts`. | Once `status === 'submitted'`, attempt is sealed. Re-submission returns identical cached result. |
| `POST /tests/generate-selected-units-practice-session` | `auth:sanctum` | Enforced via D.C (`role === 'student' && status === 'active'`). | Paid bypass or Freemium/Fallback practice quota. | Consumes 1 practice quota on new session creation. Replays active session without consuming quota. | `AssessmentIdempotencyRecord` + User-level lock. | Idempotent key replay protection. |
| `POST /tests/generate-full-book-practice-session` | `auth:sanctum` | Enforced via D.C (`role === 'student' && status === 'active'`). | Paid bypass or Freemium/Fallback practice quota. | Consumes 1 practice quota on new session creation. Replays active session without consuming quota. | `AssessmentIdempotencyRecord` + User-level lock. | Idempotent key replay protection. |
| `POST /tests/save-practice-session-question-progress` | `auth:sanctum` | Enforced via D.C (`role === 'student' && status === 'active'`). | Must own session (`user_id = student.id`). | Read/Write progress (0 quota consumed). | Checked against session `status === 'in_progress'`. | Modifiable only while in-progress. |
| `POST /tests/submit-practice-session` | `auth:sanctum` | Enforced via D.C (`role === 'student' && status === 'active'`). | Must own session (`user_id = student.id`). | 0 quota consumed. | Serialized with `FOR UPDATE` on `practice_sessions`. | Once `status === 'submitted'`, session is sealed. Re-submission returns identical cached result. |

---

## 2. Security Gap Remediation Required in Stage D.D

1. **`getRecentAttemptsForLms` Student Role Enforcement:**
   - Currently, `TestController@getRecentAttemptsForLms` only does `$user = $request->user();`. If a teacher or admin accesses this LMS route, it attempts to query their user ID against student attempt tables.
   - **Remediation:** Introduce `assertActiveStudent($user)` check returning HTTP 403 `Active student account required.` if the user is not an active student.
2. **Sequential Attempt Numbers Exposure:**
   - Currently, `getRecentAttemptsForLms` omits `attempts.attempt_number` from the `test_attempts` query.
   - **Remediation:** Select `attempts.attempt_number` and map it into the JSON output so frontend LMS dashboards can display `Attempt #1`, `Attempt #2`, etc.
3. **Display Title Formatting for Mock Tests:**
   - For formal tests, if `test_mode === 'mock'`, the title or display title should clearly identify it as a mock examination rather than standard formal test.
