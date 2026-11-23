# Stage 1E.2.D.B — Current Attempt Lifecycle Map

**Document ID:** `PHASE_1E_2_D_B_CURRENT_ATTEMPT_LIFECYCLE_MAP`  
**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Stage:** 1E.2.D.B — Shared Official-Test Uniqueness, Attempt & Retake Accounting (Planning Mode)  
**Timestamp:** 2026-11-19  

---

## 1. End-to-End Attempt Lifecycle in Current Code

The assessment workflow spans three phases: Generation, Progress Autosave, and Final Submission.

```mermaid
sequenceDiagram
    autonumber
    actor Student
    participant API as TestController
    participant Entitle as UnifiedEntitlementResolver
    participant Idemp as AssessmentIdempotencyService
    participant Quota as FreemiumQuotaConsumptionService
    participant DB as MySQL InnoDB

    Note over Student, DB: Phase 1: Test Generation (Stage 1E.2.D.A)
    Student->>API: POST /generate-selected-units-formal-test (X-Idempotency-Key)
    API->>Entitle: resolve(student, targetContext, 'perm_mcqs', 'formal_test')
    API->>Idemp: execute(student, 'formal_test_generation', key, callback)
    Idemp->>DB: DB::transaction: WebUser::lockForUpdate()
    Idemp->>DB: INSERT tests, test_scopes, test_questions
    alt Non-Paid Student
        Idemp->>Quota: consumeQuota(student, 'formal_test', 'generation', attempt_number=0)
        Quota->>DB: INSERT freemium_quota_consumptions, UPDATE freemium_quota_counters
    end
    API-->>Student: HTTP 200 { test_id, questions }

    Note over Student, DB: Phase 2: Progress Saving & Attempt Creation
    Student->>API: POST /save-formal-test-question-progress (test_id, question_id, selected_option_id)
    API->>DB: SELECT * FROM test_attempts WHERE test_id = X AND user_id = Y AND status = 'in_progress'
    alt No active in_progress attempt
        Note over API, DB: LAZY ATTEMPT CREATION OCCURS HERE!
        API->>DB: INSERT INTO test_attempts (status = 'in_progress', started_at = now())
    end
    API->>DB: TestAttemptQuestion::updateOrCreate([attempt_id, question_id], [selected_option_id, ...])
    API->>API: refreshTestAttemptProgress(attempt)
    API-->>Student: HTTP 200 { attempt_id, score, status: 'in_progress' }

    Note over Student, DB: Phase 3: Final Submission
    Student->>API: POST /submit-formal-test (test_id, attempt_id)
    API->>DB: SELECT * FROM test_attempts WHERE id = attempt_id AND status = 'in_progress'
    API->>DB: Grade all questions, calculate total score & accuracy
    API->>DB: UPDATE test_attempts SET status = 'submitted', submitted_at = now()
    API->>DB: Update student statistics & progress summaries
    API-->>Student: HTTP 200 { score, accuracy, status: 'submitted' }
```

---

## 2. Key Architectural Observations

1. **Attempt Creation is Lazy:**
   - Neither `generateSelectedUnitsFormalTestForLms` nor `generateFullBookFormalTestForLms` creates a `TestAttempt` record.
   - The `TestAttempt` is created lazily on the student's **first progress save** (`saveFormalTestQuestionProgressForLms`, lines 639–652), or on direct submission if no progress was autosaved.
2. **Missing Quota & Eligibility Enforcement on Progress Save:**
   - In current `saveFormalTestQuestionProgressForLms` (lines 617–653), `TestAttempt::create` is executed **without** checking whether the student has quota remaining, whether the test allows retakes, or whether the attempt cap was reached.
   - Any student can currently trigger unmetered attempts and retakes simply by calling `/save-formal-test-question-progress`.
3. **No Ledger Event Recorded for Attempts:**
   - When `TestAttempt::create` executes, it does **not** call `FreemiumQuotaConsumptionService::consumeQuota()` with `attempt_start` or `retake_start`.
   - The ledger only contains the initial `generation` event (`attempt_number = 0`).
4. **Missing Ownership Check on Progress Save & Submission:**
   - `saveFormalTestQuestionProgressForLms` calls `Test::findOrFail($validated['test_id'])` but fails to verify that the test belongs to `$user->id` or is an official published shared test (`test_source === 'official' && is_published = 1`).
   - A student can attempt another student's generated test if they possess the `test_id`.
5. **Autosave Reuses Active Attempt:**
   - When `TestAttempt::where('test_id', $test->id)->where('user_id', $user->id)->where('status', 'in_progress')->first()` finds an existing attempt, it reuses it.
   - Subsequent question answers and modifications do not create new attempts or consume credits.
