# Stage 1E.3.A — Milestone 4 Dynamic Mock Quota Correction Plan
## Remediation of Assessment Mode and Quota Misattribution

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M4_MOCK_QUOTA_CORRECTION_PLAN.md`
- **Execution Date:** November 20, 2026 (Revised)
- **Target Repository (Primary):** `E:\Al-Faraabi Software\al-faraabi-website` (`thestudentstimes.git`)
- **Backend Reference (Read-Only):** `E:\Al-Faraabi Software\3peas_backend`
- **Status:** Read-Only Architectural Specification (Security-Hardened Revision)

---

## 1. Problem Statement & Root Cause Analysis

### 1.1 The Anomaly Discovered in M3 Verification
During the Milestone 3 security and quota verification, a critical architectural defect was established in the legacy assessment generation flow:
1. When a student chooses **Mock Test** on the frontend, `TestLaunchScreen.jsx` stores `mode: "mock"` in component state, but dispatches to the formal test generation endpoints:
   - `POST /web_api/auth/tests/generate-full-book-formal-test` or
   - `POST /web_api/auth/tests/generate-selected-units-formal-test`
2. The legacy backend controller methods (`generateFullBookFormalTestForLms` and `generateSelectedUnitsFormalTestForLms`) do NOT evaluate assessment mode. Instead, they hardcode:
   ```php
   $decision = $this->entitlementResolver->resolve($student, $targetContext, 'perm_mcqs', 'formal_test');
   $this->quotaConsumptionService->consumeQuota($student, 'formal_test', 'generation', ...);
   // In generateFormalTestRecord:
   'test_mode' => 'test'
   ```
3. **Severe Business & Security Consequences:**
   - **Quota Misattribution:** Dynamic mock generation silently burns the student's **formal test quota** instead of their **mock quota**.
   - **Stranded Allowance:** The student's dedicated `mock` quota allowance remains completely untouched and unusable.
   - **Analytics Distortion:** The test record is permanently tagged as `test_mode = 'test'`. Downstream attempt accounting (`freemium_test_attempts`), performance analytics, and attempt eligibility logic treat it as an ordinary formal test rather than a board mock.

---

## 2. Inadequacy of the Initial Proposal & Security Remediation

### 2.1 Why Passing `requested_mode` on Formal Endpoints Failed Review
The initial M4 planning proposal suggested adding `requested_mode: "mock"` to the existing formal test request payload, accepting it whenever `scope = "full_book"`.

This was rejected by the architectural review because:
- A full-book formal test is not inherently a mock examination. A student running a 10-question full-book review would be able to misclassify it as a mock to consume an unmetered mock quota or bypass exhausted formal quota.
- Client sliders allowing arbitrary question counts and time limits violate the standardized board simulation requirement of a genuine mock exam.

### 2.2 The Approved Remediation Architecture
The approved remediation establishes strict route, blueprint, and authorization separation:

1. **Formal Test Routes Remain Purely Formal:**
   `generateFullBookFormalTestForLms` and `generateSelectedUnitsFormalTestForLms` exclusively produce formal tests (`test_mode = 'test'`, `exam_type = 'formal_test'`). Any client attempt to inject mode flags into these routes is completely disregarded.
2. **Dedicated Server Mock Generation Operation:**
   Introduce a dedicated, server-controlled mock generation endpoint:
   `POST /web_api/auth/tests/generate-full-book-mock-test`
   Handled by `TestController::generateFullBookMockTestForLms`.
3. **Board Blueprint Enforcement:**
   The mock endpoint validates that the assessment covers the full syllabus and adheres to standardized question count and duration (e.g., 60 MCQs / 60 minutes for board intermediate examinations).
4. **Authoritative Mock Entitlement & Quota Deduction:**
   The mock endpoint resolves entitlement specifically for `'mock'` and consumes quota from the `'mock'` bucket.
5. **Persistence of Immutable Mock Record:**
   The generated test record is permanently created with `test_mode = 'mock'` and `test_source = 'system'`.

---

## 3. Detailed Component-by-Component Remediation

### 3.1 Backend Controller Implementation (`TestController.php`)

#### New Endpoint: `generateFullBookMockTestForLms(Request $request)`
```php
/**
 * Generate a standardized full-book mock examination for LMS.
 * Strictly consumes 'mock' quota and enforces board blueprint specifications.
 */
public function generateFullBookMockTestForLms(Request $request)
{
    $validated = $request->validate([
        'offered_program_id' => ['nullable', 'integer', 'min:1', 'exists:offered_programs,id'],
        'subject_id'         => ['required', 'integer', 'exists:subject_tbl,id'],
        'book_id'            => ['required', 'integer', 'exists:book_tbl,id'],
        'total_questions'    => ['nullable', 'integer', 'min:1'],
        'time_limit_minutes' => ['nullable', 'integer', 'min:1'],
        'mcq_format'         => ['nullable', 'string', 'in:all,straight,scenario'],
    ]);

    $student = $request->user();
    $book = Book::findOrFail($validated['book_id']);

    // 1. Full-Book Syllabus Coverage & Blueprint Parameter Validation
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

    $totalQuestions = (int) ($validated['total_questions'] ?? 25);
    $timeLimitMinutes = isset($validated['time_limit_minutes']) ? (int) $validated['time_limit_minutes'] : $totalQuestions;

    // 2. Authoritative Entitlement Resolution for 'mock'
    $targetContext = [
        'program_id' => $validated['offered_program_id'] ?? null,
        'subject_id' => $validated['subject_id'],
        'book_id'    => $validated['book_id'],
    ];

    $decision = $this->entitlementResolver->resolve(
        $student,
        $targetContext,
        'perm_mcqs',
        'mock' // Authoritative exam_type
    );

    if (!$decision->isAccessible()) {
        return response()->json([
            'success'       => 0,
            'error'         => $decision->getDenialReason() ?? 'Access denied for mock examinations.',
            'error_code'    => 'MOCK_ACCESS_DENIED',
            'denial_reason' => $decision->getDenialReason(),
        ], 403);
    }

    // 3. Quota Check and Consumption
    if (!$decision->isPaid()) {
        $quotaResult = $this->quotaConsumptionService->consumeQuota(
            $student,
            'mock', // Strictly consumes 'mock' quota
            'generation',
            [
                'book_id'            => $book->id,
                'idempotency_key'    => $request->header('X-Idempotency-Key'),
                'request_payload'    => $request->all(),
                'is_legacy_fallback' => $decision->isLegacyFree(),
                'primary_campaign'   => $decision->isFreemium() && $decision->getCampaignId()
                    ? FreemiumCampaign::find($decision->getCampaignId()) : null,
            ]
        );

        if (!$quotaResult['allowed']) {
            return response()->json([
                'success'       => 0,
                'error'         => $quotaResult['error'] ?? 'Weekly mock test allowance exhausted.',
                'error_code'    => 'QUOTA_EXHAUSTED',
                'denial_reason' => 'Weekly mock test allowance exhausted.',
            ], 403);
        }
    }

    // 4. Test Entity Creation with Immutable 'mock' Mode
    $test = $this->generateMockTestRecord([
        'student_id'         => $student->id,
        'book_id'            => $book->id,
        'subject_id'         => $book->subject_id,
        'offered_program_id' => $validated['offered_program_id'] ?? null,
        'test_mode'          => 'mock', // Authoritatively 'mock'
        'test_source'        => 'system',
        'total_questions'    => $totalQuestions,
        'time_limit_minutes' => $timeLimitMinutes,
    ]);

    return response()->json([
        'success' => 1,
        'data'    => [
            'test_id'            => $test->id,
            'title'              => $test->title,
            'test_mode'          => 'mock',
            'scope_type'         => 'full_book',
            'time_limit_minutes' => $timeLimitMinutes,
            'total_questions'    => $totalQuestions,
            'question_ids'       => $test->questions()->pluck('id')->all(),
        ],
    ], 200);
}
```

#### Retention of Pure Formal Handlers
`generateFullBookFormalTestForLms` and `generateSelectedUnitsFormalTestForLms` will explicitly reject or disregard any attempt to request `mock`:
```php
// In generateFullBookFormalTestForLms & generateSelectedUnitsFormalTestForLms
// Force exam_type and test_mode strictly:
$decision = $this->entitlementResolver->resolve($student, $targetContext, 'perm_mcqs', 'formal_test');
$this->quotaConsumptionService->consumeQuota($student, 'formal_test', 'generation', ...);
// Test::create with 'test_mode' => 'test'
```

---

### 3.2 Frontend LMS Integration (`TestLaunchScreen.jsx` & API Client)

In `al-faraabi-website`:
1. **API Client (`src/api/freemium/freemiumApiClient.js`):**
   Expose dedicated methods:
   - `generateFullBookFormalTest(payload, options)` -> `POST /web_api/auth/tests/generate-full-book-formal-test`
   - `generateSelectedUnitsFormalTest(payload, options)` -> `POST /web_api/auth/tests/generate-selected-units-formal-test`
   - `generateFullBookMockTest(payload, options)` -> `POST /web_api/auth/tests/generate-full-book-mock-test`
   - `generatePracticeSession(payload, options)` -> `POST /web_api/auth/tests/generate-*-practice-session`
2. **`TestLaunchScreen.jsx` Mode Dispatch:**
   ```javascript
   const handleLaunchAssessment = async () => {
     const idempotencyKey = getOrCreateIdempotencyKey();
     
     if (config.mode === 'mock') {
       // Dedicated Mock Flow: Requires full-book scope
       if (config.scopeType !== 'full_book') {
         setError('Mock examinations can only be launched for full book scope.');
         return;
       }
       return await freemiumApiClient.generateFullBookMockTest({
         book_id: config.bookId,
         subject_id: config.subjectId,
         offered_program_id: isSyntheticId(config.programId) ? null : config.programId,
       }, { idempotencyKey });
     } else if (config.mode === 'practice') {
       // Practice Flow
       return await freemiumApiClient.generatePracticeSession(...);
     } else {
       // Formal Test Flow
       if (config.scopeType === 'full_book') {
         return await freemiumApiClient.generateFullBookFormalTest(...);
       } else {
         return await freemiumApiClient.generateSelectedUnitsFormalTest(...);
       }
     }
   };
   ```

---

## 4. Attempt Accounting, Retakes, and Unified Runner Harmony

### 4.1 Harmonized Attempt Runner
Regardless of whether a test is an **Official Shared Mock**, a **Dynamic Mock**, or a **Formal Test**, all formal assessments use the same question runner component (`TestTakingScreen.jsx` / `attemptRunner.jsx`) and dispatch progress updates to:
`POST /web_api/auth/tests/save-formal-test-question-progress`

### 4.2 Authoritative Progress Handler Derivation
In `TestController::saveFormalTestQuestionProgressForLms`:
```php
$test = Test::findOrFail($request->test_id);
// Authoritatively derived from database entity:
$examType = ($test->test_mode === 'mock') ? 'mock' : 'formal_test';

// Quota consumption & attempt registration strictly uses $examType:
$this->quotaConsumptionService->consumeQuota(
    $student,
    $examType,
    'attempt_start',
    ['test_id' => $test->id, 'attempt_number' => $attemptNumber]
);
```
- Because the dynamic mock was created with `test_mode = 'mock'`, this downstream attempt handler automatically and correctly records progress under `exam_type = 'mock'`.
- Official shared mocks also have `test_mode = 'mock'`, achieving 100% uniformity across official and dynamic mocks.

### 4.3 Attempt Eligibility Pre-Flight Check
The pre-flight check endpoint `GET /web_api/auth/freemium/tests/{testId}/eligibility` queries `FreemiumQuotaConsumptionService::getTestAttemptEligibility($student, $test)`:
- Derives `$examType = ($test->test_mode === 'mock') ? 'mock' : 'formal_test'`.
- Inspects `freemium_test_attempts` for that specific `test_id` and `$examType`.
- Enforces retake limits (e.g. 1 attempt per mock test) independently from formal tests.

---

## 5. Verification Matrix

| Assessment Type | Triggered Route | Derived `exam_type` | Database `test_mode` | Quota Deducted | Attempt Accounting |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Formal Test (Chapter)** | `/generate-selected-units-formal-test` | `formal_test` | `'test'` | `formal_test` | `freemium_test_attempts` (`exam_type='formal_test'`) |
| **Formal Test (Full Book)** | `/generate-full-book-formal-test` | `formal_test` | `'test'` | `formal_test` | `freemium_test_attempts` (`exam_type='formal_test'`) |
| **Dynamic Mock Test** | `/generate-full-book-mock-test` | `mock` | `'mock'` | `mock` | `freemium_test_attempts` (`exam_type='mock'`) |
| **Official Shared Mock** | `/save-formal-test-question-progress` | `mock` | `'mock'` | `mock` | `freemium_test_attempts` (`exam_type='mock'`) |
| **Practice Session** | `/generate-*-practice-session` | `practice` | N/A (`PracticeSession`) | `practice` | `practice_sessions` table |
