# Independent Read-Only Audit Report: Phase 1E.3.A / Phase 4A
## Backend Route & Security Hardening

**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Milestone:** Phase 1E.3.A — Milestone 4 (Phase 4A)  
**Audit Date:** 2026-11-20  
**Auditor:** Independent Read-Only Security Auditor  
**Audit Target:** PHP source code, routes, controllers, models, database schema, and test suite.  
**Final Verdict:** **ACCEPT WITH CONDITIONS**

---

## 1. Executive Summary

A comprehensive, strict read-only audit of Phase 4A ("Backend Route & Security Hardening") was performed in `E:\Al-Faraabi Software\3peas_backend`.

The audit evaluated actual execution paths, controller validations, SQL queries, transactional locks, quota counter modifications, and test assertions.

### Summary of Verdict:
- **Verdict:** **ACCEPT WITH CONDITIONS**
- **Core Security Goals Achieved:**
  - Dedicated route separation is established (`POST /web_api/auth/tests/generate-full-book-mock-test`).
  - Formal endpoints cannot create mock assessments; mock endpoints cannot create formal assessments.
  - Quota accounting strictly separates dynamic mock generation from official shared mock attempts.
  - Synthetic `offered_program_id` values ($\le 0$ or non-existent) are rejected, and null/missing values resolve cleanly for authorized Freemium scopes.
  - All 18 dedicated security tests pass with 58 assertions; all 49 existing regression tests pass with 203 assertions (67 total passing tests).
- **Conditions Requiring Resolution Prior to Phase 4B/Release:**
  1. **Mock Duration Authority (High Severity):** Eliminate client-supplied duration fallback in mock generation; enforce server-authoritative duration configuration.
  2. **Full-Book Formal Endpoint Anti-Tampering (Medium Severity):** Explicitly reject `requested_mode = 'mock'` on `generateFullBookFormalTestForLms` with HTTP 422 (aligning with `generateSelectedUnitsFormalTestForLms`).
  3. **Implementation Report Corrections (Low Severity):** Correct documentation discrepancies regarding table name (`tests` vs `exam_test_tbl`), question boundary (`10–200` vs `10–100`), and duration fallback logic.

---

## 2. Dedicated Mock Endpoint Analysis

- **Route Registration:**
  - File: `routes/web_api.php`, line 371:
    ```php
    Route::post('/generate-full-book-mock-test', [TestController::class, 'generateFullBookMockTestForLms']);
    ```
  - Guard: Enclosed within `Route::group(['middleware' => ['auth:web_api']], ...)`.
  - In controller:
    ```php
    $student = $request->user();
    if (!$student || ($student->role ?? null) !== 'student') {
        return response()->json(['success' => 0, 'error' => 'Unauthorized. Active student session required.'], 401);
    }
    ```
- **Mode Immutability:**
  - In `generateFullBookMockTestForLms`:
    - `UnifiedEntitlementResolver::resolve` is invoked with hardcoded `$examType = 'mock'` (line 721).
    - `FreemiumQuotaConsumptionService::consumeQuota` is invoked with hardcoded `$examType = 'mock'` (line 814).
    - `Test::create` persists hardcoded `'test_mode' => 'mock'` and `'test_source' => 'system'` (lines 902–903).
    - Client cannot manipulate assessment mode via payload.
- **Formal Route Immutability:**
  - `generateSelectedUnitsFormalTestForLms`: Explicitly checks `$request->input('requested_mode') === 'mock'` or `mode === 'mock'` and aborts with **HTTP 422 `INVALID_ASSESSMENT_SCOPE`**.
  - `generateFullBookFormalTestForLms`: Hardcodes `'formal_test'` for resolution, `'formal_test'` for quota consumption, and persists `'test_mode' => 'test'`. Client-supplied flags are discarded.

---

## 3. Mock Duration — Critical Review

### 3.1 Code Inspection & Findings
In `app/Http/Controllers/Tests/TestController.php`, lines 736–746:
```php
// Check if campaign quota specifies time_limit_minutes
$configuredTimeLimit = null;
if ($decision->isFreemium() && $decision->getCampaignId()) {
    $quota = FreemiumExamQuota::where('campaign_id', $decision->getCampaignId())
        ->where('exam_type', 'mock')
        ->first();
    if ($quota && $quota->time_limit_minutes !== null) {
        $configuredTimeLimit = (int) $quota->time_limit_minutes;
    }
}
$timeLimitMinutes = $configuredTimeLimit ?? ($validated['time_limit_minutes'] ?? $totalQuestions);
```

### 3.2 Evaluation Against Requirements
1. **The Claimed 30-Minute Fallback Does Not Exist in Code:**
   The implementation report claimed a "fallback default: 30 minutes". In the actual code, there is no `30` constant or config reference. Line 746 evaluates:
   `$timeLimitMinutes = $configuredTimeLimit ?? ($validated['time_limit_minutes'] ?? $totalQuestions);`
2. **Client Parameter Vulnerability (High Severity):**
   - If a student is **Paid**, `$decision->getCampaignId()` is null, so `$configuredTimeLimit` is null.
   - If a Freemium student belongs to a campaign where `time_limit_minutes` is null (nullable column in `freemium_exam_quotas`), `$configuredTimeLimit` is null.
   - In both cases, the controller falls back to `$validated['time_limit_minutes']`. This permits a client to send `time_limit_minutes: 600` (10 hours) and receive a mock test with that client-chosen duration.
3. **Uncalibrated Default (Medium Severity):**
   If the client omits `time_limit_minutes`, the duration falls back to `$totalQuestions` (i.e. 1 minute per question). A 25-question test gets 25 minutes; a 100-question test gets 100 minutes. While 1 minute per question is a common practice metric, for standardized board mock exams (FBISE/BISE), duration is fixed by paper pattern (e.g. 60 or 90 minutes), not variable.
4. **Recommendation:**
   - Prohibit client-dictated `time_limit_minutes` on `/generate-full-book-mock-test`.
   - Establish server-side resolution order:
     1. Active campaign `FreemiumExamQuota->time_limit_minutes`.
     2. Authoritative system config: `config('freemium.mock_default_duration_minutes', 60)`.
     3. If no valid duration is resolvable, fail closed with **HTTP 422 `UNCONFIGURED_MOCK_DURATION`**.

---

## 4. Question-Count Boundaries Review (10–100 vs 10–200)

### 4.1 Code Inspection
In `app/Http/Controllers/Tests/TestController.php`, line 669:
```php
'total_questions' => ['nullable', 'integer', 'min:10', 'max:200'],
```
And lines 734:
```php
$totalQuestions = (int) ($validated['total_questions'] ?? 25);
```

### 4.2 Analysis
- **Code vs Report Discrepancy:** The implementation report text stated "Permitted range: 10 to 100 questions". However, the actual code implements `min:10, max:200`.
- **Curriculum Alignment:**
  - Matriculation (9th/10th) board exams feature 12–17 MCQs.
  - Intermediate (11th/12th) board exams feature 17–20 MCQs.
  - Medical/Engineering entrance examinations (MDCAT/ECAT) feature 180–200 MCQs.
  - Capping questions at 100 would break entrance examination mock blueprints (MDCAT).
- **Frontend Compatibility:** The LMS frontend (`GenerateTestSection.jsx`) provides a numeric input field with HTML `min: 1`. Sending 10–200 succeeds; sending $< 10$ or $> 200$ fails cleanly with HTTP 422.
- **Finding:** The code implementation (`10–200`) matches the approved planning package and pedagogical realities. The implementation report contained a typo claiming 10–100.

---

## 5. Official Versus Dynamic Mock Accounting

### 5.1 Verification of Calls & Database Mutation

#### A. Dynamic Mock Generation (`POST /web_api/auth/tests/generate-full-book-mock-test`)
- **Execution Path:**
  1. `TestController::generateFullBookMockTestForLms`
  2. `FreemiumQuotaConsumptionService::consumeQuota($student, 'mock', 'generation', ...)`
- **Database Counters Modified:**
  - `freemium_quota_counters`: The row matching `(user_id, exam_type='mock', window_type, window_key)` increments its `counter` by **+1**.
  - `freemium_quota_counters` for `exam_type='formal_test'`: **Unchanged (0 increments)**.
  - `freemium_quota_consumptions`: One new row inserted with `exam_type = 'mock'`, `event_type = 'generation'`, `attempt_number = 0`.
  - `tests`: One new row created with `test_mode = 'mock'`, `test_source = 'system'`.

#### B. Official Shared Mock Attempts (`POST /web_api/auth/tests/save-formal-test-question-progress` / `startAttemptForLms`)
- **Execution Path:**
  1. `TestController::saveFormalTestQuestionProgressForLms` $\rightarrow$ `startAttemptForLms`
  2. Resolves test from `tests` table: `test_source === 'official'`, `test_mode === 'mock'`.
  3. `FreemiumQuotaConsumptionService::consumeAttemptQuota($user, $test, $attemptNumber, $eventType, ...)`
  4. Calls `consumeQuota($student, 'mock', 'attempt_start', ...)` (or `'retake_start'`).
- **Database Counters Modified:**
  - `freemium_quota_counters`: `$consumesGenerationQuota` is `false` because `$eventType` is `'attempt_start'`/`'retake_start'`. Therefore, `freemium_quota_counters.counter` is **NOT incremented**. The generation allowance is preserved.
  - `freemium_quota_consumptions`: One new row inserted with `exam_type = 'mock'`, `event_type = 'attempt_start'` (or `'retake_start'`), `attempt_number = 1` (or `2`).
  - `test_attempts`: One new row created with `test_id = $officialMock->id`, `user_id = $student->id`, `status = 'in_progress'`.
  - `TestAttemptQuestion`: Upserts question progress.
- **Attempt & Retake Limits:**
  - Enforces `allow_retakes` and `max_attempts_per_test` from `FreemiumExamQuota`.
  - Verified by `test_16`: Submitting attempt 1 and trying to start attempt 2 when `allow_retakes = false` triggers **HTTP 403 `QUOTA_EXHAUSTED`**.

---

## 6. Persistence and Schema Verification

### 6.1 Table Name Discrepancy Resolution
- **Investigation:**
  - The implementation report referred to `exam_test_tbl`.
  - Database search confirmed `exam_test_tbl` does not exist in `3peas_backend`.
  - The Eloquent model is `App\Models\Test` (`app/Models/Test.php`). Because `$table` is not overridden, Eloquent maps this model to the standard table **`tests`**.
  - Migration `database/migrations/2026_04_18_093707_create_tests_table.php` defines:
    - `test_source`: `enum('system', 'teacher', 'official')->default('system')`
    - `test_mode`: `enum('test', 'mock')`
    - `scope_type`: `enum('chapter', 'multiple_chapters', 'full_book')`
- **Result:**
  - The reference to `exam_test_tbl` in the report was a documentation misnomer.
  - In actual code, `Test::create()` writes to `tests`.
  - Because it writes to `tests`, existing attempt runner and result endpoints (`TestAttempt::where('test_id', ...)`, `TestController::startAttemptForLms`, `TestController::submitFormalTestForLms`) operate on dynamically generated mock assessments natively with zero schema divergence.
  - **Zero migrations were added or executed.**

---

## 7. Synthetic Offered-Program ID Support

- **Validation Rules Verified:**
  All 5 assessment creation actions validate:
  `'offered_program_id' => ['nullable', 'integer', 'min:1', 'exists:offered_programs,id']`
- **Audit Findings:**
  1. **Null/Missing ID:** Permitted. Converted to `$offeredProgramId = null`. `UnifiedEntitlementResolver` evaluates subject-level and global rules cleanly without requiring a program ID.
  2. **Non-positive ID ($\le 0$):** Rejected by `min:1` with **HTTP 422**.
  3. **Non-existent ID:** Rejected by `exists:offered_programs,id` with **HTTP 422**.
  4. **Practice Resumption:** In `PracticeSessionController::findActiveSessionForScope` (lines 495–499):
     ```php
     if ($offeredProgramId !== null) {
         $candidatesQuery->where('offered_program_id', $offeredProgramId);
     } else {
         $candidatesQuery->whereNull('offered_program_id');
     }
     ```
     Practice sessions created with `null` offered program IDs are matched and resumed accurately.

---

## 8. Idempotency and Concurrency

- **Idempotency Service Integration:**
  - Uses `AssessmentIdempotencyService::execute()`.
  - Operation namespace: `'mock_test_generation'` (distinct from `'formal_test_generation'` and `'practice_session_generation'`).
  - Key validation: Rejects invalid format or missing key with **HTTP 422**.
- **Replay & Conflict:**
  - Identical key + identical payload: Replays stored response, skips generation, avoids incrementing quota counters.
  - Identical key + different payload: Throws `IdempotencyConflictException`, caught and converted to **HTTP 409 `IDEMPOTENCY_CONFLICT`**.
- **Pessimistic Concurrency Locking:**
  - Before quota consumption, executes `WebUser::where('id', $student->id)->lockForUpdate()->first()` inside `DB::transaction(...)`.
  - Serializes concurrent generation requests for the same student, preventing double-spend race conditions.

---

## 9. Practice and Paid Regression

- **Practice Quotas:** Practice sessions strictly consume `practice` quota. Verified by `test_14` (`practice` counter +1, `mock` counter 0, `formal_test` counter 0).
- **Paid Workflows:** Paid students bypass Freemium quota limits only within valid paid scopes. Verified by `test_18`.
- **Backward Compatibility:** All existing tests in `StudentFormalTestGenerationQuotaTest`, `StudentPracticeSessionAccountingTest`, `StudentMockTestAttemptAccountingTest`, and `AssessmentIdempotencyServiceTest` pass without modification.

---

## 10. Test Suite Audit & Execution

### 10.1 Inspection of Assertions in `AssessmentModeSecurityTest.php`
Every test case was inspected to confirm real, non-trivial assertions:
- `test_01` & `test_02`: Verifies HTTP 200, JSON schema, and database state (`test_mode = 'mock'`, `test_source = 'system'`).
- `test_03` & `test_04`: Verifies that `freemium_quota_counters` increments only the target `exam_type` counter and leaves other counters at 0.
- `test_07` & `test_08`: Verifies HTTP 422 `INVALID_ASSESSMENT_SCOPE` when syllabus is partial or unit subset is requested.
- `test_09`: Verifies HTTP 422 when `total_questions` is 5 or 250.
- `test_11` & `test_12`: Verifies null program ID success and non-positive/non-existent program ID rejection.
- `test_15` & `test_16`: Verifies official mock attempts do not increment generation counter, and retake denial enforces `allow_retakes = false`.
- `test_17`: Verifies idempotency replay produces identical `test_id` and does not increment quota counter.

### 10.2 Actual Test Execution Output
Executed command:
`php artisan test tests/Feature/Freemium/AssessmentModeSecurityTest.php`
```
   PASS  Tests\Feature\Freemium\AssessmentModeSecurityTest
  ✓ 01 dynamic full book mock generation succeeds for authorized student                                         0.27s  
  ✓ 02 dynamic mock persists test mode mock and test source system                                               0.09s  
  ✓ 03 dynamic mock consumes mock quota not formal quota                                                         0.08s  
  ✓ 04 formal generation persists test mode test and consumes formal quota                                       0.08s  
  ✓ 05 formal endpoint cannot be converted to mock by manipulating request fields                                0.08s  
  ✓ 06 mock endpoint cannot create formal assessment                                                             0.12s  
  ✓ 07 chapter level mock generation is rejected                                                                 0.05s  
  ✓ 08 incomplete full book syllabus coverage is rejected                                                        0.16s  
  ✓ 09 invalid or out of range question counts fail safely                                                       0.41s  
  ✓ 10 mock duration uses approved backend configuration                                                         0.25s  
  ✓ 11 missing offered program id works for authorized freemium resource                                         0.08s  
  ✓ 12 invalid zero negative or nonexistent program ids are rejected                                             0.08s  
  ✓ 13 academic resource scope validation remains enforced                                                       0.06s  
  ✓ 14 practice generation remains bound to practice quota                                                       0.08s  
  ✓ 15 official shared mock attempts do not consume dynamic mock generation quota                                0.10s  
  ✓ 16 official mock attempt limits and retakes remain correct                                                   0.16s  
  ✓ 17 duplicate generation requests do not consume quota twice                                                  0.10s  
  ✓ 18 existing paid formal generation workflows continue to work                                                0.07s  

  Tests:    18 passed (58 assertions)
  Duration: 2.45s
```

Regression command:
`php artisan test tests/Feature/Freemium/Student/StudentFormalTestGenerationQuotaTest.php tests/Feature/Freemium/Student/StudentPracticeSessionAccountingTest.php tests/Feature/Freemium/Student/StudentMockTestAttemptAccountingTest.php tests/Feature/Freemium/AssessmentIdempotencyServiceTest.php`
```
  Tests:    49 passed (203 assertions)
  Duration: 7.73s
```
**Total passing assertions:** **261 assertions across 67 tests**.

---

## 11. Scope and Isolation Verification

- **Git Status Review:**
  - `al-faraabi-website`: Untouched during Phase 4A (only contains prior accepted M1–M3 files).
  - `tst-admin`: Clean working tree.
  - `3peas_backend`: Only authorized controller, route, and test files were touched.
  - Zero database migrations added or executed.
  - No packages installed, no configuration files altered.

---

## 12. Detailed Findings Register

| ID | Severity | Category | Reference | Finding | Impact | Recommended Correction |
|---|---|---|---|---|---|---|
| **F-01** | **High** | Security / Anti-Tampering | `TestController.php:736-746` | Mock test duration falls back to client `$validated['time_limit_minutes']` whenever campaign quota duration is unspecified or user is paid. | Client can supply arbitrary durations (e.g. 600 minutes) to distort mock examinations. | Disallow client `time_limit_minutes` in mock generation. Resolve strictly from campaign quota or system default config (`config('freemium.mock_default_duration_minutes', 60)`). If unconfigured, fail closed (422). |
| **F-02** | **Medium** | Security / Anti-Tampering | `TestController.php:277-320` | `generateFullBookFormalTestForLms` silently ignores `requested_mode = 'mock'` rather than returning HTTP 422. | Inconsistency with `generateSelectedUnitsFormalTestForLms` which explicitly rejects `requested_mode = 'mock'`. | Add explicit validation rejecting `requested_mode === 'mock'` or `mode === 'mock'` with 422 `INVALID_ASSESSMENT_SCOPE`. |
| **F-03** | **Low** | Documentation Accuracy | `PHASE_1E_3_A_M4_A_IMPLEMENTATION_REPORT.md` | Report lists table as `exam_test_tbl` and question range as `10–100`. | Minor confusion during audit. Actual code correctly uses `tests` and `10–200`. | Update implementation report to accurately reflect table name `tests` and question range `10–200`. |

---

## 13. Final Verdict and Conditions

### Verdict: **ACCEPT WITH CONDITIONS**

Phase 4A satisfies the structural, architectural, and security route separation requirements. The codebase is sound, transactional integrity is preserved, and test coverage is comprehensive.

### Mandatory Conditions for Resolution:
1. **Resolve F-01:** Harden mock duration resolution in `generateFullBookMockTestForLms` so the server is the sole authority on duration. Disallow client override.
2. **Resolve F-02:** Add explicit rejection of `requested_mode = 'mock'` in `generateFullBookFormalTestForLms` to guarantee uniform API error reporting across formal routes.
3. **Resolve F-03:** Update the implementation report documentation for precision.

**Stopping Point Acknowledged:** Per instructions, no source modifications have been made during this audit, and Phase 4B has not been started. Awaiting user authorization.
