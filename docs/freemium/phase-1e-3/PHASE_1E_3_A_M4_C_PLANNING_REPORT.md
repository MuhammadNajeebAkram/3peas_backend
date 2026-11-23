# Stage 1E.3.A — Milestone 4 Phase 4C Planning Report
## Frontend Attempt Runner & Eligibility Integration Architecture and Plan (Clarified Revision)

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M4_C_PLANNING_REPORT.md`
- **Revision Date:** November 20, 2026 (Clarified Revision)
- **Primary Repository:** `E:\Al-Faraabi Software\al-faraabi-website` (Student LMS Frontend)
- **Reference Repository (Read-Only):** `E:\Al-Faraabi Software\3peas_backend` (Laravel Backend)
- **Admin Reference (Read-Only):** `E:\Al-Faraabi Software\tst-admin`
- **Author:** Antigravity Architecture & Security Lead
- **Status:** Planning & Inspection Complete (Awaiting Implementation Authorization)

---

## 1. Executive Summary

Milestone 4 Phase 4C integrates the Assessment Attempt Runner (`TestAttemptScreen.jsx`) with the backend's authoritative Freemium eligibility gate, retake accounting system, and official Board Mock Exam presentation rules.

Following deep source inspection across both repositories (`al-faraabi-website` and `3peas_backend`), four critical architecture and security clarifications were formally investigated and resolved:
1. **In-Progress Attempt Recovery Capability:** Evaluated whether an active attempt can be restored from the server after losing browser `sessionStorage`. Identified that while eligibility and active attempt status are retrievable, question IDs and saved answers cannot be restored without a new backend endpoint. A safe frontend degradation strategy is specified, and full recovery is documented as a backend dependency.
2. **Attempt ID Security & Scoping:** Confirmed that `saveFormalTestQuestionProgressForLms` accepts `attempt_id`, verifies student ownership (`user_id`), enforces cross-test isolation (`test_id`), and rejects submitted attempts with HTTP 409 `ATTEMPT_ALREADY_SUBMITTED`.
3. **Unified Paid & Freemium Eligibility:** Established that `GET /freemium/tests/{testId}/eligibility` provides a completely uniform contract across all student tiers, enabling a clean, single preflight gate.
4. **Authoritative Assessment Source Values:** Inspected migrations and models, confirming that `test_source` strictly allows `'system'`, `'official'`, and `'teacher'`. The informal planning term `'custom'` is formally replaced with `'system'`.

Zero application source code, tests, configuration, or database records were modified.

---

## 2. In-Progress Attempt Recovery Investigation Findings

An exhaustive search of all routes and controllers in `3peas_backend` established the exact state of attempt recovery:

| Recovery Item | Endpoint Available? | Details / Response Fields |
|---|---|---|
| **Attempt ID & Status** | **YES** | `GET /web_api/auth/freemium/tests/{testId}/eligibility` returns `has_active_attempt: bool` and `active_attempt_id: int`. |
| **Attempt Number** | **YES** | `GET /web_api/auth/freemium/tests/{testId}/eligibility` returns `attempt_number: int`. |
| **Saved Answers** | **NO** | No endpoint returns `selected_option_id` per question for student self-generated tests. |
| **Question Sequence & IDs** | **NO** | `responseData.question_ids` is only returned upon initial test generation. |
| **Current Question Position** | **NO** | Not stored on server. |
| **Remaining Time** | **NO** | `started_at` is stored in DB but not returned in `testEligibility`. |

### Architectural Boundary:
- **Backend Dependency:** Full attempt resumption across storage wipes requires a new backend endpoint (`GET /web_api/auth/tests/{testId}/active-attempt`).
- **Frontend Safe Boundary for Phase 4C:**
  - If `sessionStorage` is intact: Full attempt execution operates normally.
  - If `sessionStorage` is wiped: The runner queries `getTestEligibility(attemptId)`. If already submitted, it redirects to results. If active, it displays an actionable message advising the user to return to Tests Hub rather than crashing or faking state.

---

## 3. Attempt ID Security Findings

Inspection of `TestController::saveFormalTestQuestionProgressForLms` ([lines 1005–1240](file:///E:/Al-Faraabi%20Software/3peas_backend/app/Http/Controllers/Tests/TestController.php#L1005)) verified:

1. **Parameter Acceptance:** Validated as `'attempt_id' => ['nullable', 'integer', 'exists:test_attempts,id']`.
2. **Student Ownership Verification:** Queries `->where('id', $validated['attempt_id'])->where('user_id', $user->id)`. Returns 404 if the attempt does not belong to the authenticated student.
3. **Cross-Test Isolation:** Queries `->where('test_id', $test->id)`. Returns 404 if the attempt belongs to a different assessment.
4. **Already-Submitted Rejection:** If `$attemptCheck->status === 'submitted'`, returns HTTP 409 with `error_code: 'ATTEMPT_ALREADY_SUBMITTED'`.
5. **Atomic Progress Linkage:** Updates or creates `TestAttemptQuestion` tied directly to `$attempt->id`.
6. **First Save Dynamic Capture:** On the first question answered (when `attempt_id` is omitted), the server creates the attempt and returns `data.attempt_id` in the response, allowing the client to dynamically bind the ID for all subsequent saves.

---

## 4. Eligibility Behavior across Student Tiers

`GET /web_api/auth/freemium/tests/{testId}/eligibility` behaves consistently across all six student states:

1. **Paid Students:** `can_attempt: true, is_paid: true, max_attempts_per_test: null, allow_retakes: true`.
2. **Freemium Students (Available Quota):** `can_attempt: true, is_paid: false, max_attempts_per_test: N, remaining_attempts: R`.
3. **Freemium Students (Exhausted Allowance):** `can_attempt: false, denial_reason: 'Maximum permitted attempts (N) reached...'`.
4. **Students with Active Attempt:** `has_active_attempt: true, active_attempt_id: <id>`.
5. **Students with Retake Eligibility:** `is_retake: true, can_attempt: true, remaining_attempts: R`.
6. **Expired / Invalid Entitlement:** `can_attempt: false, denial_reason: '...'`.

**Conclusion:** The frontend uses the exact same preflight flow for both paid and Freemium students safely.

---

## 5. Assessment Source Enum Values

Inspection of `database/migrations/2026_04_18_093707_create_tests_table.php` and `database/migrations/2026_04_17_193021_create_test_attempts_table.php` established the exact valid database enum values:

```sql
test_source ENUM('system', 'teacher', 'official') DEFAULT 'system'
```

- `'system'`: Dynamically generated assessments created by students via `TestController::generateFullBookFormalTestForLms` and `generateFullBookMockTestForLms`.
- `'official'`: Official, shared assessments created by staff or administrators (`is_published = true`).
- `'teacher'`: Teacher-created class tests assigned to classes.
- *Resolution:* The string `'custom'` does not exist in the database or backend code; all references are corrected to `'system'`.

---

## 6. Revised Milestone Sequence & Dependencies

```
┌────────────────────────────────────────────────────────────────────────┐
│ Phase 4C.1: Preflight Eligibility Hook & Denial UI Integration        │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│ Phase 4C.2: Attempt Badging & Mode Branding (Board Mock Exam)          │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│ Phase 4C.3: Attempt ID Propagation & Safe Storage Resiliency          │
│ (Note: Full attempt recovery is documented as a backend dependency)   │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│ Phase 4C.4: Concurrency & 409 Already-Submitted Exception Handling     │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│ Phase 4C.5: Verification Suite (14 Tests) & Implementation Report      │
└────────────────────────────────────────────────────────────────────────┘
```

---

## 7. Remaining Blockers & Repository Status

- **Blockers:** Zero blockers for Phase 4C frontend runner integration. Full server-side attempt question/answer recovery across storage wipes is logged as a backend enhancement for future milestones.
- **Repository Preservation:**
  - `al-faraabi-website`: 0 code changes; all 82 existing Freemium tests pass.
  - `3peas_backend`: Strictly read-only; 0 application changes; all 324 PHPUnit tests pass.
  - `tst-admin`: Working tree clean.

**STOPPED.** Awaiting authorization before beginning implementation of Phase 4C.
