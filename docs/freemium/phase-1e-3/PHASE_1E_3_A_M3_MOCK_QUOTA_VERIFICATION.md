# Stage 1E.3.A — Milestone 3 Mock Test Quota Enforcement Verification
## Source Code Trace & Architectural Analysis of Mock Exam Quota Enforcement

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M3_MOCK_QUOTA_VERIFICATION.md`
- **Execution Date:** November 20, 2026
- **Target Repositories:**
  - Backend Reference: `E:\Al-Faraabi Software\3peas_backend`
  - LMS Frontend: `E:\Al-Faraabi Software\al-faraabi-website`
- **Audit Type:** Strictly Read-Only Source Trace & Security Verification
- **Status:** **DISCREPANCY IDENTIFIED & DOCUMENTED (BACKEND DEPENDENCY FOR M4)**

---

## 1. Executive Summary & Verdict

Following the security acceptance check, an exhaustive, read-only code trace was performed across the entire test generation, execution, and quota consumption pipeline to verify whether **mock test generation** correctly consumes the `mock` quota rather than the `formal_test` quota.

### Key Findings:
1. **The Discrepancy:**
   For student-generated assessments created via the LMS dynamic test generator (`TestLaunchScreen.jsx`), **the backend currently hardcodes `formal_test` for both formal tests and mock tests**.
   - `TestController::generateSelectedUnitsFormalTestForLms` and `generateFullBookFormalTestForLms` hardcode `'formal_test'` when calling `UnifiedEntitlementResolver::resolve()` and `FreemiumQuotaConsumptionService::consumeQuota()`.
   - The controllers hardcode `'test_mode' => 'test'` on the created `Test` record in `tests` table.
   - Neither generation endpoint validates or accepts a `test_mode` or `exam_type` parameter from the client.
   - As a consequence, generating a mock test in the LMS currently decrements the student's **`formal_test`** quota, leaving the **`mock`** quota untouched.
2. **Pre-Created / Official Mock Exams vs Dynamic Mocks:**
   - For **pre-existing or official shared mock exams** where `tests.test_mode = 'mock'` in the database, attempt and retake accounting is **100% correct**: `saveFormalTestQuestionProgressForLms` and `consumeAttemptQuota` evaluate `$test->test_mode === 'mock'` and correctly consume the `'mock'` attempt quota while enforcing configured retake limits.
   - However, for **dynamically generated mock tests** requested by students through the LMS Tests Hub, dynamic generation lacks `test_mode` persistence.
3. **Attempt Restrictions & Idempotency:**
   - Retakes **do not** consume generation quota: `$consumesGenerationQuota` is strictly restricted to `generation` and `practice_generation`.
   - Idempotent request replay via `X-Idempotency-Key` correctly prevents double-consumption.
   - Attempt limits (`max_attempts_per_test`) and `allow_retakes` policies are strictly enforced for tests identified as `mock`.
4. **Milestone 3 Impact & Resolution:**
   - **Milestone 3 is NOT blocked.** Milestone 3 is strictly **Tests Hub / Resource Discovery** (`tests/page.jsx` and `TestsPageContent.jsx`). It displays quota cards and merges catalog resources; it does not execute test generation.
   - Dynamic test generation occurs in **Milestone 4** (`TestLaunchScreen.jsx`).
   - The required backend correction has been documented in detail and is scheduled as a prerequisite for Milestone 4.

---

## 2. Complete Request Flow Trace: From `TestLaunchScreen.jsx` to Database

We traced the execution path step-by-step for a student generating a mock test:

### Step 1: Frontend Request Dispatch (`TestLaunchScreen.jsx`)
In `al-faraabi-website/src/components/LMS/tests/TestLaunchScreen.jsx`:
```javascript
// Lines 78-84
const isPracticeMode = config.mode === "practice";
const modeLabel = config.mode === "mock"
  ? "Mock Test"
  : isPracticeMode
    ? "Practice Session"
    : "Formal Test";

// Lines 98-105
const endpoint = isPracticeMode
  ? isFullBook
    ? "/auth/tests/generate-full-book-practice-session"
    : "/auth/tests/generate-selected-units-practice-session"
  : isFullBook
    ? "/auth/tests/generate-full-book-formal-test"
    : "/auth/tests/generate-selected-units-formal-test";

// Lines 106-121
const payload = isFullBook
  ? {
      offered_program_id: config.offeredProgramId,
      subject_id: config.subjectId,
      book_id: config.bookId,
      total_questions: config.questionCount,
      time_limit_minutes: config.timeLimit,
    }
  : {
      offered_program_id: config.offeredProgramId,
      subject_id: config.subjectId,
      unit_ids: config.unitIds,
      total_questions: config.questionCount,
      time_limit_minutes: config.timeLimit,
      scope_type: config.scope,
    };
```
- **Observation:** When `config.mode === "mock"`, `isPracticeMode` is `false`. The LMS selects the formal test generation endpoint.
- **Payload Inspection:** The payload sent by the LMS contains `offered_program_id`, `subject_id`, `book_id` or `unit_ids`, `total_questions`, and `time_limit_minutes`.
- **Finding:** The payload **does not include `test_mode`, `exam_type`, or `mode`**.

---

### Step 2: Laravel Routing (`routes/web_api.php`)
- **Route:** `POST /web_api/auth/tests/generate-selected-units-formal-test` (Line 369)
- **Route:** `POST /web_api/auth/tests/generate-full-book-formal-test` (Line 370)
- Both routes point to `TestController`.

---

### Step 3: Controller Request Validation (`TestController.php`)
In `App\Http\Controllers\Tests\TestController.php`:
```php
// Lines 83-95 (generateSelectedUnitsFormalTestForLms)
$validated = $request->validate([
    'offered_program_id' => ['required', 'integer', 'exists:offered_programs,id'],
    'subject_id'         => ['required', 'integer', 'exists:subject_tbl,id'],
    'unit_ids'           => ['required', 'array', 'min:1'],
    'unit_ids.*'         => ['integer', 'exists:book_unit_tbl,id'],
    'total_questions'    => ['required', 'integer', 'min:1'],
    'mcq_format'         => ['nullable', 'string', 'in:all,straight,scenario'],
    'time_limit_minutes' => ['nullable', 'integer', 'min:1'],
    'title'              => ['nullable', 'string', 'max:255'],
    'description'        => ['nullable', 'string', 'max:255'],
    'marks_per_question' => ['nullable', 'numeric', 'min:0'],
    'scope_type'         => ['nullable', 'string', 'in:chapter,multiple_chapters,full_book'],
]);
```
- **Finding:** Neither controller method validates or extracts `test_mode` or `exam_type`. If a client sends `'test_mode' => 'mock'`, it is discarded by `$request->validate()`.

---

### Step 4: Entitlement Resolution (`TestController.php`)
```php
// Lines 108-113 & Lines 300-305
$decision = $this->entitlementResolver->resolve(
    $student,
    $targetContext,
    'perm_mcqs',
    'formal_test' // <--- HARDCODED
);
```
- **Finding:** The exam type passed to `UnifiedEntitlementResolver::resolve()` is **hardcoded to `'formal_test'`**.
- The resolver evaluates permissions under `'formal_test'`, not `'mock'`.

---

### Step 5: Quota Consumption (`TestController.php`)
```php
// Lines 184-197 & Lines 375-385
if (!$decision->isPaid()) {
    $this->quotaConsumptionService->consumeQuota(
        $student,
        'formal_test', // <--- HARDCODED
        'generation',
        [
            'test_id'            => $test->id,
            'attempt_number'     => 0,
            'idempotency_key'    => $validatedKey,
            'request_payload'    => $fingerprintPayload,
            'is_legacy_fallback' => $decision->isLegacyFree(),
            'primary_campaign'   => $decision->isFreemium() && $decision->getCampaignId() ? FreemiumCampaign::find($decision->getCampaignId()) : null,
        ]
    );
}
```
- **Finding:** The exam type passed to `FreemiumQuotaConsumptionService::consumeQuota()` is **hardcoded to `'formal_test'`**.
- The service increments `FreemiumQuotaCounter` where `exam_type = 'formal_test'`.
- The student's `mock` quota counter is **never touched**.

---

### Step 6: Test Record Creation & Persistence (`TestController.php`)
In `generateFormalTestRecord()`:
```php
// Lines 459-473
$test = Test::create([
    'offered_program_id' => $offeredProgramId,
    'subject_id'         => $subjectId,
    'created_by'         => $createdBy,
    'title'              => $generatedTitle,
    'description'        => $description,
    'test_source'        => 'system',
    'test_mode'          => 'test', // <--- HARDCODED
    'scope_type'         => $scopeType,
    'question_type'      => 'mcq',
    'time_limit_minutes' => $timeLimitMinutes,
    'total_questions'    => $totalQuestions,
    'is_published'       => true,
    'published_at'       => now(),
]);
```
- **Finding:** The generated `Test` record is persisted with `'test_mode' => 'test'`.
- It is **never persisted with `test_mode = 'mock'`**.

---

### Step 7: Subsequent Attempt & Retake Execution
When the student takes the generated test, `TestController::saveFormalTestQuestionProgressForLms` checks:
```php
// Line 701
$decision = $this->entitlementResolver->resolve(
    $user,
    $targetContext,
    'perm_mcqs',
    $test->test_mode === 'mock' ? 'mock' : 'formal_test'
);

// Lines 790-800
$this->quotaConsumptionService->consumeAttemptQuota(
    $user,
    $test,
    $attemptNumber,
    $eventType,
    ...
);
```
And inside `FreemiumQuotaConsumptionService::consumeAttemptQuota` (Line 811):
```php
$examType = ($test->test_mode === 'mock') ? 'mock' : 'formal_test';
```
- **Finding:** Because the test was saved with `test_mode = 'test'`, the attempt accounting logic evaluates `$test->test_mode === 'mock'` as `false`, and continues to record all attempt consumptions against `'formal_test'`.

---

## 3. Case-by-Case Verification Matrix

| Assessment Operation | Client Target | Actual Value Passed to `consumeQuota()` | Stored `test_mode` in DB | Quota Counter Incremented | Correct? |
| :--- | :--- | :---: | :---: | :---: | :---: |
| **Formal Test Generation** | `/auth/tests/generate-*-formal-test` | `'formal_test'` | `'test'` | `formal_test` | **YES (Correct)** |
| **Mock Test Generation** | `/auth/tests/generate-*-formal-test` | `'formal_test'` | `'test'` | `formal_test` | **NO (Discrepancy)** |
| **Practice Session Creation**| `/auth/tests/generate-*-practice-session` | `'practice'` | N/A (`practice_sessions`) | `practice` | **YES (Correct)** |
| **Official Shared Mock Exam**| `/auth/tests/save-*-progress` | `'mock'` | `'mock'` | `mock` | **YES (Correct)** |

---

## 4. Verification of Attempt Restrictions & Ledger Invariants

We independently verified the internal ledger rules in `FreemiumQuotaConsumptionService.php`:

### 4.1 Retakes Do Not Consume Generation Quota
- **Verified in Code:** Lines 230 & 451:
  ```php
  $consumesGenerationQuota = in_array($eventType, ['generation', 'practice_generation']);
  ```
  When `$eventType` is `'attempt_start'` or `'retake_start'`, `$consumesGenerationQuota` is `false`.
  `FreemiumQuotaCounter::counter` is **not incremented** during retakes.

### 4.2 Single-Attempt and Configured Limits for Mock Tests
- **Verified in Code:** Lines 437-447:
  ```php
  $isRetake = ($eventType === 'retake_start' || $attemptNumber > 1);
  if ($isRetake) {
      if (!$primaryQuota->allow_retakes) {
          throw new QuotaLimitExceededException("Retakes are not permitted for this test quota.");
      }
      if ($attemptNumber > $primaryQuota->max_attempts_per_test) {
          throw new QuotaLimitExceededException(
              "Maximum permitted attempts ({$primaryQuota->max_attempts_per_test}) exceeded for this test."
          );
      }
  }
  ```
  If a test has `test_mode = 'mock'` and the campaign policy has `allow_retakes = false` or `max_attempts_per_test = 1`, retakes are rejected with `403 QUOTA_EXHAUSTED`.

### 4.3 Idempotency & Replay Protection
- **Verified in Code:** Lines 203-219:
  ```php
  if ($idempotencyKey !== null) {
      $existing = FreemiumQuotaConsumption::where('user_id', $student->id)
          ->where('idempotency_key', $idempotencyKey)
          ->first();
      if ($existing) {
          if ($fingerprint !== null && $existing->request_fingerprint !== $fingerprint) {
              throw new IdempotencyConflictException(...);
          }
          return $existing; // Replays existing consumption without re-incrementing counter
      }
  }
  ```
  Duplicate requests with the same `X-Idempotency-Key` return the existing record without double-decrementing quota.

### 4.4 Correct Quota Period & Counter
- **Verified in Code:** Lines 238-245 & 421:
  - Weekly legacy fallback counters use Karachi Monday-anchored ISO window:
    `$windowKey = 'PK:legacy:weekly:' . $pkt->format('o-\WW');`
  - Campaign quotas resolve via `FreemiumQuotaWindow::resolveWindowKey($quota->window_type, $campaign->id, $now)`.
  - Serialized via `FreemiumQuotaCounter::where(...)->lockForUpdate()`.

---

## 5. Required Backend Correction for Milestone 4

To ensure dynamic mock test generation consumes the `mock` quota and enforces mock attempt limits, the following targeted backend enhancement is required prior to Milestone 4:

### 5.1 Updates to `TestController.php`:
1. **Accept `test_mode` in Validation:**
   In both `generateSelectedUnitsFormalTestForLms` and `generateFullBookFormalTestForLms`:
   ```php
   $validated = $request->validate([
       ...
       'test_mode' => ['nullable', 'string', 'in:test,mock'],
   ]);
   $testMode = $validated['test_mode'] ?? 'test';
   $examType = ($testMode === 'mock') ? 'mock' : 'formal_test';
   ```
2. **Pass Dynamic `$examType` to Entitlement Resolver:**
   ```php
   $decision = $this->entitlementResolver->resolve(
       $student,
       $targetContext,
       'perm_mcqs',
       $examType
   );
   ```
3. **Pass Dynamic `$examType` to Quota Consumption:**
   ```php
   $this->quotaConsumptionService->consumeQuota(
       $student,
       $examType,
       'generation',
       [ ... ]
   );
   ```
4. **Persist `$testMode` on `Test` Model:**
   In `generateFormalTestRecord()`:
   ```php
   $test = Test::create([
       ...
       'test_mode' => $testMode,
   ]);
   ```

### 5.2 Updates to `TestLaunchScreen.jsx` (Milestone 4):
When `config.mode === 'mock'`, include `'test_mode': 'mock'` in the generation request payload.

---

## 6. Milestone 3 Operational Impact & Final Verdict

### Is Milestone 3 Blocked?
**NO.**

- Milestone 3 is strictly **Tests Hub / Free Resource Discovery** (`src/app/(lms)/lms/(protected)/tests/page.jsx` and `src/components/LMS/tests/TestsPageContent.jsx`).
- Milestone 3 is responsible for:
  1. Fetching `/auth/freemium/quota-allowances` (which correctly reports `mock`, `formal_test`, and `practice` allowances).
  2. Mounting `<FreemiumQuotaBanner />` to display all 3 quota cards.
  3. Merging free resources via `mergeFreemiumPrograms()`.
  4. Setting up filters on `/lms/tests`.
- **Milestone 3 does NOT execute test generation.** Test generation takes place on the launch screen (`/lms/tests/launch`), which is the sole focus of **Milestone 4**.
- Therefore, the identified mock test backend dependency is a **Milestone 4 prerequisite** and does not prevent Milestone 3 from proceeding safely.

### Verdict:
**Milestone 3 can proceed immediately.** The backend dependency for mock quota generation has been thoroughly documented and logged for resolution in Milestone 4.
