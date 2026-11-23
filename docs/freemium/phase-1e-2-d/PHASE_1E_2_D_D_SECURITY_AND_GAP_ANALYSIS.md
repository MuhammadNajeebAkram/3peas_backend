# Stage 1E.2.D.D — Ownership & Security Gap Analysis

**Document ID:** `PHASE_1E_2_D_D_SECURITY_AND_GAP_ANALYSIS`  
**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Stage:** 1E.2.D.D — Planning and Source Inspection ONLY  
**Timestamp:** 2026-11-19  

---

## 1. Security Architecture & Threat Model

The assessment lifecycle involves sensitive student grading data, examination attempts, and restricted commercial content.  
The threat model evaluates the following attack vectors:
1. **Vertical Privilege Escalation:** Non-student users (teachers, admins, visitors) executing student attempt and history endpoints.
2. **Horizontal Information Disclosure:** Student A querying or accessing Student B's attempt records, answers, or scores.
3. **Attempt Limit Bypass:** Submitting rapid concurrent attempt-starts to bypass campaign retake limits on shared tests.
4. **Immutability Tampering:** Modifying answers or scores on already-submitted assessments.
5. **Session Hijacking:** Resuming or saving answers into another student's in-progress test or practice session.

---

## 2. Identified Vulnerabilities & Gaps in Current Source

### Gap 1: Missing Student Role & Active Status Check in `getRecentAttemptsForLms`
- **Location:** `app/Http/Controllers/Tests/TestController.php:879-904`
- **Finding:** The endpoint uses `$user = $request->user();` without verifying that `$user->role === 'student'` and `$user->status === 'active'`. A logged-in teacher, institute admin, or suspended student can query this route. While the query filters by `attempts.user_id = $user->id`, allowing non-students or inactive students violates platform role-boundary principles and causes unexpected database executions.
- **Severity:** High (Role Boundary Violation).
- **Remediation:** Introduce standard guard:
  ```php
  if (!$user || $user->role !== 'student' || $user->status !== 'active') {
      return response()->json([
          'success' => 0,
          'message' => 'Active student account required.',
      ], 403);
  }
  ```

### Gap 2: Omission of `attempt_number` in Recent Attempts Output
- **Location:** `app/Http/Controllers/Tests/TestController.php:904-925, 977-999`
- **Finding:** `attempts.attempt_number` is not selected from the `test_attempts` table. As a result, the student portal UI cannot display sequential attempt numbers (`Attempt 1`, `Attempt 2`) for retaken tests or shared official exams.
- **Severity:** Medium (Functional / UI Inconsistency).
- **Remediation:** Add `attempts.attempt_number` to `selectRaw` and include `'attempt_number' => (int) ($attempt->attempt_number ?? 1)` in mapped output.

### Gap 3: Mock Test Display Title Distinction in Recent Attempts
- **Location:** `app/Http/Controllers/Tests/TestController.php:907-910, 981`
- **Finding:** Formal tests and mock tests share the `test_attempts` table. In `getRecentAttemptsForLms`, tests with `test_mode = 'mock'` are labeled `'attempt_type' => 'formal_test'`. Although `test_mode` is returned as `'mock'`, the display title remains identical to regular tests, without clear distinction for frontend students.
- **Severity:** Low (Usability).
- **Remediation:** Enhance display title logic: if `test_mode === 'mock'`, append or format title as `Mock Exam: {title}`.

### Gap 4: Practice Session Eligibility Discovery
- **Location:** `StudentFreemiumQuotaController@testEligibility`
- **Finding:** The endpoint `/freemium/tests/{testId}/eligibility` accepts only test IDs from the `tests` table. Practice sessions are generated dynamically and stored in `practice_sessions`. Students discovering their remaining practice session allowance must call `/freemium/quota-allowances` instead.
- **Severity:** Low (Documentation & Discovery Clarity).
- **Remediation:** Clarify in API documentation and frontend guidelines that `/freemium/quota-allowances?exam_type=practice` is the canonical endpoint for practice allowances, while `/freemium/tests/{testId}/eligibility` is strictly for formal tests and mock tests.

---

## 3. Verified Security Strengths (No Gaps Found)

1. **Student Isolation in Progress & Submission:**
   - Both `saveFormalTestQuestionProgressForLms` and `submitFormalTestForLms` rigorously verify ownership:
     `$isStudentOwned = ((int) $test->created_by === (int) $user->id || ...);`
   - Unowned student tests fail closed with HTTP 404 (`Test not found or access denied.`).
2. **Shared Official Test Multi-Student Protection:**
   - On official tests (`test_source = 'official'`), `(test_id, user_id, attempt_number, event_type)` ensures complete database isolation.
3. **Practice Session Ownership:**
   - `savePracticeSessionQuestionProgressForLms` and `submitPracticeSessionForLms` enforce `(int) $session->user_id === (int) $user->id`.
4. **Submitted Attempt Immutability:**
   - Re-saving answers to submitted attempts is blocked with HTTP 422/409.
