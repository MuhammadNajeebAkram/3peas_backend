# Independent Read-Only Audit — Stage 1E.2.D.C
## Practice Session Generation, Resume & Submission Enforcement

**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Stage Under Audit:** 1E.2.D.C  
**Audit Type:** Independent Read-Only Verification  
**Audit Timestamp:** 2026-11-19T16:15:00+05:00  
**Target Databases Inspected:** `3peas_db` (primary dev) and `3peas_db_subscription_test` (local test, MySQL 8.0.43 InnoDB)  
**Overall Audit Verdict:** **PASS** (Zero Failures, Zero Functional Blockers, Zero Regressions)

---

## 1. Executive Summary

This independent audit conducted a comprehensive, read-only verification of the Stage 1E.2.D.C implementation across `app/Http/Controllers/Tests/PracticeSessionController.php`, `config/freemium.php`, `app/Http/Services/Freemium/FreemiumQuotaConsumptionService.php`, and associated test suites.

All 12 audit mandates have been evaluated against source code, database constraints, multi-process concurrency execution, and automated test suites. The implementation fully enforces the four-tier entitlement architecture, canonical idempotency fingerprinting, pessimistic row-level locking on `web_users`, non-chargeable active session resumption, ownership fail-closed guards (HTTP 404), submitted session immutability (HTTP 422), and safe idempotent repeated submission replay (HTTP 200).

---

## 2. Detailed Findings by Audit Area

### Audit Area 1: Practice Generation Endpoints & Entitlement Precedence
- **Source References:**
  - `app/Http/Controllers/Tests/PracticeSessionController.php` (Lines 45–252, Lines 255–445)
  - `app/Http/Services/Freemium/UnifiedEntitlementResolver.php` (Lines 60–124)
- **Status:** **PASS**
- **Analysis:**
  Both generation endpoints (`generateSelectedUnitsPracticeSessionForLms` and `generateFullBookPracticeSessionForLms`) strictly implement authentication and role guards:
  ```php
  $student = $request->user();
  if (!$student || ($student->role ?? null) !== 'student') {
      return response()->json([
          'success' => 0,
          'error'   => 'Unauthorized. Active student session required.',
      ], 401);
  }
  ```
  `X-Idempotency-Key` is mandatory:
  ```php
  $idempotencyKey = $request->header('X-Idempotency-Key') ?? $request->input('idempotency_key');
  if (!$idempotencyKey) {
      return response()->json([
          'success' => 0,
          'error'   => 'Missing required X-Idempotency-Key header.',
      ], 422);
  }
  ```
  Entitlement is evaluated prior to generation via `UnifiedEntitlementResolver::resolve($student, $targetContext, 'perm_mcqs', 'practice')`. The four-tier precedence functions strictly in order:
  - **Tier 1 (Paid)**: Active paid subscription covering program/subject grants access without quota debit.
  - **Tier 2 (Freemium)**: Explicit `freemium_exam_quotas` rules evaluated per active campaign.
  - **Tier 3 (Legacy Free)**: Evaluated if `program_subjects.is_free = 1` or `offered_classes.is_free = 1`.
  - **Tier 4 (Default Deny)**: Unentitled students are rejected with HTTP 403 containing diagnostic and `upgrade_slug`.

---

### Audit Area 2: Legacy-Free Weekly Quota Configuration
- **Source References:**
  - `config/freemium.php` (Lines 17–21)
  - `app/Http/Services/Freemium/FreemiumQuotaConsumptionService.php` (Lines 55–84, Lines 218–228)
  - `tests/Unit/Freemium/FreemiumLegacyQuotaConfigurationTest.php`
- **Status:** **PASS**
- **Analysis:**
  - Configuration keys `'practice'` and `'practice_session'` are mapped to `env('FREEMIUM_LEGACY_WEEKLY_PRACTICE', '2')`.
  - `resolveConfiguredQuota()` parses integer and string tokens strictly using `/^[0-9]+$/`.
  - Negative values (e.g. `-1`) log warnings and fall back to the safe default (2).
  - Non-numeric or decimal strings (e.g. `"two"`, `"2.5"`) log warnings and fall back to safe default.
  - An explicit value of `0` is strictly preserved as intentional disablement:
    ```php
    if ($consumesGenerationQuota && $quotaCeiling === 0) {
        throw new QuotaLimitExceededException(
            "Legacy-free {$examType} generation is currently disabled by system policy."
        );
    }
    ```
    This triggers HTTP 403 with `error_code: 'QUOTA_EXHAUSTED'` and is verified by `test_legacy_free_zero_quota_denies_generation`.

---

### Audit Area 3: Explicit Campaign Quotas Override Legacy-Free Fallback
- **Source References:**
  - `app/Http/Services/Freemium/UnifiedEntitlementResolver.php` (Lines 81–107)
  - `app/Http/Services/Freemium/FreemiumQuotaConsumptionService.php` (Lines 340–420)
  - `tests/Feature/Freemium/Student/StudentPracticeSessionAccountingTest.php` (Lines 254–316)
- **Status:** **PASS**
- **Analysis:**
  When an explicit campaign rule matches, `UnifiedEntitlementResolver` returns `UnifiedAccessDecision::grantedFreemium` with `isLegacyFree() === false`. In `FreemiumQuotaConsumptionService`, execution branches away from legacy fallback into explicit campaign consumption:
  - Quotas are resolved from `freemium_exam_quotas` for `exam_type = 'practice'`.
  - Temporal quota envelopes are tracked against `FreemiumQuotaCounter` scoped to `campaign_id` and `quota_id`.
  - Verified by `test_explicit_freemium_campaign_quota_enforced`: A campaign allowing 1 weekly practice session rejects the second attempt with HTTP 403 (`QUOTA_EXHAUSTED`), proving it does NOT leak or fall through to the legacy fallback allowance of 2.

---

### Audit Area 4: Active-Session Resumption & Non-Chargeable Policy
- **Source References:**
  - `app/Http/Controllers/Tests/PracticeSessionController.php` (Lines 159–183, 372–396, 470–504, 509–553)
  - `tests/Feature/Freemium/Student/StudentPracticeSessionAccountingTest.php` (Lines 318–373)
  - `tests/Feature/Freemium/Concurrency/AssessmentPracticeConcurrencyTest.php` (Lines 275–387)
- **Status:** **PASS**
- **Analysis:**
  - `findActiveSessionForScope()` queries `practice_sessions` where `status = 'in_progress'` and checks exact equality of sorted unit IDs against `practice_session_scopes`.
  - When an active session is found, `buildActiveSessionResponse()` formats and returns the active session metadata with HTTP 200.
  - Quota consumption (`consumeQuota`) is completely bypassed on resumption.
  - In `AssessmentPracticeConcurrencyTest`, two simultaneous CLI worker processes attempting generation against an active scope both receive HTTP 200 referencing the active session ID, with 0 new sessions created and 0 quota consumed.

---

### Audit Area 5: Idempotency Protection, Payload Fingerprinting & Concurrency
- **Source References:**
  - `app/Http/Services/Freemium/AssessmentIdempotencyService.php` (Lines 79–179)
  - `database/migrations/2026_12_01_000010_create_assessment_idempotency_records_table.php`
  - `tests/Feature/Freemium/Student/StudentPracticeSessionAccountingTest.php` (Lines 375–443)
  - `tests/Feature/Freemium/Concurrency/AssessmentPracticeConcurrencyTest.php` (Lines 152–273)
- **Status:** **PASS**
- **Analysis:**
  - **Same key + same payload**: Replays original stored response (`status: 200, replayed: true`). Verified in `test_idempotency_same_key_and_payload_replays_original_session`.
  - **Same key + different payload**: Throws `IdempotencyConflictException`, caught and rendered as HTTP 409 (`error_code: 'IDEMPOTENCY_CONFLICT'`). Verified in `test_idempotency_same_key_different_payload_returns_409_conflict`.
  - **Concurrent identical requests**: Database unique index `uk_user_op_idempotency` (`user_id`, `operation`, `idempotency_key`) prevents duplicate insertions. In-flight requests catch `uk_user_op_idempotency` and safely poll or replay once completed. Verified in `test_two_simultaneous_generations_with_same_idempotency_key_and_same_payload`.
  - **Failed generation rollback**: On callback failure, `AssessmentIdempotencyService::execute()` catches `\Throwable`, explicitly executes `$record->delete()`, and re-throws, ensuring failed operations never leave orphaned `'processing'` records. Verified in `AssessmentIdempotencyServiceTest::test_failed_operation_does_not_leave_completed_or_blocking_record`.

---

### Audit Area 6: Paid Student Scope Boundaries & Bypass Verification
- **Source References:**
  - `app/Http/Services/Freemium/UnifiedEntitlementResolver.php` (Lines 60–76, Lines 127–160)
  - `app/Http/Controllers/Tests/PracticeSessionController.php` (Lines 153–156, Lines 200–214)
  - `tests/Feature/Freemium/Student/StudentPracticeSessionAccountingTest.php` (Lines 120–162)
- **Status:** **PASS**
- **Analysis:**
  - A student with an active subscription covering the requested program and subject receives `UnifiedAccessDecision::grantedPaid()`.
  - `PracticeSessionController` checks `if (!$decision->isPaid())` before invoking `consumeQuota()`. Paid students receive zero ledger records in `freemium_quota_consumptions`.
  - If a student's subscription is for program A, but they request resources in program B, `findCoveringPaidProgram()` returns null, causing evaluation to drop down to Freemium campaign, legacy free fallback, or default deny.
  - Subscriptions with `end_date < now()` are excluded by `getActiveSubscriptions()`.

---

### Audit Area 7: Ownership Protection for Progress Saving and Submission
- **Source References:**
  - `app/Http/Controllers/Tests/PracticeSessionController.php` (Lines 723–733, Lines 833–843)
  - `tests/Feature/Freemium/Student/StudentPracticeSessionAccountingTest.php` (Lines 445–523)
- **Status:** **PASS**
- **Analysis:**
  Both `savePracticeSessionQuestionProgressForLms` and `submitPracticeSessionForLms` query sessions with strict user constraints:
  ```php
  $session = PracticeSession::query()
      ->where('id', $validated['session_id'])
      ->where('user_id', $student->id)
      ->first();

  if (!$session) {
      return response()->json([
          'success' => 0,
          'error'   => 'Practice session not found or does not belong to the student.',
      ], 404);
  }
  ```
  - An unowned session ID or foreign student session ID fails closed with HTTP 404.
  - Verified by `test_save_progress_fails_closed_404_on_foreign_session` and `test_submit_practice_session_fails_closed_404_on_foreign_session`.

---

### Audit Area 8: Submitted-Session Immutability & Repeated Submission Behavior
- **Source References:**
  - `app/Http/Controllers/Tests/PracticeSessionController.php` (Lines 735–740, 755–763, 842–860, 866–883)
  - `tests/Feature/Freemium/Student/StudentPracticeSessionAccountingTest.php` (Lines 482–505, 525–585)
- **Status:** **PASS**
- **Analysis:**
  - **Autosave Immutability**: `savePracticeSessionQuestionProgressForLms` checks `if ($session->status === 'submitted')` outside and inside the row-locked transaction. Any attempt to modify a question in a submitted session returns HTTP 422 with `'Cannot update progress on an already submitted practice session.'`.
  - **Repeated Submission Replay**: `submitPracticeSessionForLms` detects `$session->status === 'submitted'` and immediately returns HTTP 200 with stored score, accuracy, and question totals. It bypasses grading loops, question statistics updates, and student activity logging, preventing metric duplication.

---

### Audit Area 9: Answer Updates, Grading, and LMS Metric Compatibility
- **Source References:**
  - `app/Http/Controllers/Tests/PracticeSessionController.php` (Lines 885–1065)
- **Status:** **PASS**
- **Analysis:**
  - The submission workflow preserves all existing LMS summary calculations:
    - `attempted_questions`, `correct_answers`, `wrong_answers`, `not_attempted_questions`.
    - `score = round($correctAnswers, 2)`.
    - `accuracy = round(($correctAnswers / $attemptedQuestions) * 100, 2)`.
  - Correctly cascades into LMS aggregate models:
    - `QuestionStatistic` (via `updateQuestionStatisticForPractice`).
    - `QuestionOptionStatistic` (via `updateQuestionOptionStatisticForPractice`).
    - `StudentQuestionProgressSummary` (via `updateStudentQuestionProgressSummaryForPractice`).
    - `StudentSubjectProgressSummary` (via `refreshStudentSubjectProgressSummaryForPractice`).
    - `StudentUnitProgressSummary` (via `refreshStudentUnitProgressSummaryForPractice`).
    - `StudentActivity` (recorded with context snapshot).
  - The output schema is 100% backward-compatible with frontend web/mobile apps.

---

### Audit Area 10: Multi-Process CLI Concurrency Suite Inspection
- **Source References:**
  - `tests/Feature/Freemium/Concurrency/AssessmentPracticeConcurrencyTest.php` (Lines 1–450)
- **Status:** **PASS**
- **Analysis:**
  All four tests in the concurrency suite utilize genuine `proc_open()` calls launching external PHP CLI worker processes bootstrapped through `bootstrap/app.php` and executed simultaneously against the local MySQL 8 InnoDB test database (`3peas_db_subscription_test`):
  1. `test_two_simultaneous_generations_with_one_credit_remaining`: Process 1 and Process 2 race for the last remaining credit with different unit scopes. Exactly 1 receives HTTP 200, exactly 1 receives HTTP 403 `QUOTA_EXHAUSTED`. Database asserts counter = 1, consumptions = 1, sessions = 1.
  2. `test_two_simultaneous_generations_with_same_idempotency_key_and_same_payload`: Two simultaneous processes pass identical idempotency keys. Exactly 1 session created, exactly 1 quota consumed, 1 completed idempotency record; both processes receive HTTP 200 referencing the identical `session_id`.
  3. `test_concurrent_active_session_resumptions_consume_zero_quota`: Two simultaneous processes request an active session scope. Both return HTTP 200 with the active session ID; 0 additional sessions created, 0 quota consumed.
  4. `test_concurrent_progress_saving_and_submission`: Simultaneous autosave and submit. Submit commits with HTTP 200; autosave safely merges or returns 422 if locked after status transition.

---

### Audit Area 11: Route Registrations, HTTP Contracts & Response Compatibility
- **Source References:**
  - `routes/web_api.php` (Lines 374–377)
- **Status:** **PASS**
- **Analysis:**
  All four routes remain intact within `routes/web_api.php`:
  ```php
  Route::post('/generate-selected-units-practice-session', [PracticeSessionController::class, 'generateSelectedUnitsPracticeSessionForLms']);
  Route::post('/generate-full-book-practice-session', [PracticeSessionController::class, 'generateFullBookPracticeSessionForLms']);
  Route::post('/save-practice-session-question-progress', [PracticeSessionController::class, 'savePracticeSessionQuestionProgressForLms']);
  Route::post('/submit-practice-session', [PracticeSessionController::class, 'submitPracticeSessionForLms']);
  ```
  Routes are protected under LMS JWT cookie authentication (`AttachJwtFromCookie:lms`, `AuthenticateJwtCookieGuard:lms`) and respond with standard JSON contracts.

---

### Audit Area 12: Git Diff Review & File Accounting
- **Status:** **PASS**
- **Detailed File Inventory:**
  - **Tracked Files Modified in D.C:**
    - `app/Http/Controllers/Tests/PracticeSessionController.php`: Complete 4-endpoint protection, resumption logic, and scoring hardening (+898 / -352 lines).
    - `app/Http/Controllers/Tests/TestController.php`: Constructor optional IoC defaults and null-safe `$student->role ?? null` guards (+7 / -4 lines).
  - **Untracked Files Created/Updated in D.C:**
    - `config/freemium.php`: Added `'practice'` key to `fallback_quotas`.
    - `tests/Feature/Freemium/Student/StudentPracticeSessionAccountingTest.php`: 15 automated feature tests.
    - `tests/Feature/Freemium/Concurrency/AssessmentPracticeConcurrencyTest.php`: 4 multi-process CLI concurrency tests.
  - **Pre-Existing Uncommitted Work Preserved:**
    - `app/Http/Controllers/QuestionsController.php` (D.C preserved)
    - `app/Http/Controllers/TopicsController.php` (D.C preserved)
    - `app/Http/Controllers/UnitsController.php` (D.C preserved)
    - `routes/api.php` (D.C preserved)
    - `routes/web_api.php` (D.C preserved)
    - All migrations `2026_12_01_000000` through `000011` remain untouched.

---

## 3. Comprehensive Test Accounting & Deduplication Reconciliation

### Test Inventory by Suite

| Test Suite File | Test Count | Type | Scope |
| :--- | :---: | :---: | :--- |
| `tests/Feature/Freemium/Admin/AdminFreemiumAnalyticsControllerTest.php` | 2 | Feature | Admin analytics APIs |
| `tests/Feature/Freemium/Admin/AdminFreemiumAuditControllerTest.php` | 3 | Feature | Admin audit log read APIs |
| `tests/Feature/Freemium/Admin/AdminFreemiumAuthAndPermissionTest.php` | 4 | Feature | Admin authentication & RBAC |
| `tests/Feature/Freemium/Admin/AdminFreemiumCampaignControllerTest.php` | 8 | Feature | Campaign CRUD & lifecycle |
| `tests/Feature/Freemium/Admin/AdminFreemiumGrantControllerTest.php` | 5 | Feature | Student grant management |
| `tests/Feature/Freemium/Admin/AdminFreemiumQuotaControllerTest.php` | 6 | Feature | Campaign quota management |
| `tests/Feature/Freemium/Admin/AdminFreemiumRuleControllerTest.php` | 6 | Feature | Access rule CRUD & specificity |
| `tests/Feature/Freemium/AssessmentIdempotencyServiceTest.php` | 5 | Feature | Idempotency service unit-integration |
| `tests/Feature/Freemium/Concurrency/AssessmentAttemptConcurrencyTest.php` | 4 | Concurrency | Test attempt & retake races |
| `tests/Feature/Freemium/Concurrency/AssessmentPracticeConcurrencyTest.php` | **4** | Concurrency | **Stage 1E.2.D.C practice races** |
| `tests/Feature/Freemium/Concurrency/AssessmentQuotaConcurrencyTest.php` | 3 | Concurrency | Test generation quota races |
| `tests/Feature/Freemium/DatabaseCompatibilityMigrationTest.php` | 3 | Feature | Migration 000000 assertions |
| `tests/Feature/Freemium/FreemiumAuditServiceTest.php` | 6 | Feature | Audit log immutability & redaction |
| `tests/Feature/Freemium/FreemiumCampaignLifecycleServiceTest.php` | 9 | Feature | Campaign state machine |
| `tests/Feature/Freemium/FreemiumDatabaseConstraintTest.php` | 11 | Feature | Schema foreign keys & checks |
| `tests/Feature/Freemium/FreemiumMigration000011Test.php` | 7 | Feature | D.B.1 composite unique index |
| `tests/Feature/Freemium/FreemiumMigrations000009And000010Test.php` | 6 | Feature | Nullable campaign keys & idemp table |
| `tests/Feature/Freemium/FreemiumModelRelationshipsTest.php` | 15 | Feature | Eloquent model relations |
| `tests/Feature/Freemium/FreemiumQuotaConsumptionServiceTest.php` | 10 | Feature | Quota consumption service logic |
| `tests/Feature/Freemium/FreemiumRuleEvaluationServiceTest.php` | 10 | Feature | Rule evaluator resolution |
| `tests/Feature/Freemium/Student/StudentFormalTestAttemptAccountingTest.php` | 13 | Feature | D.B.2 attempt & retake accounting |
| `tests/Feature/Freemium/Student/StudentFormalTestGenerationQuotaTest.php` | 14 | Feature | D.A formal test generation quotas |
| `tests/Feature/Freemium/Student/StudentFreemiumAccessControllerTest.php` | 11 | Feature | Student freemium access endpoints |
| `tests/Feature/Freemium/Student/StudentFreemiumQuotaControllerTest.php` | 15 | Feature | Student quota discovery endpoints |
| `tests/Feature/Freemium/Student/StudentLearningResourceAccessEnforcementTest.php` | 15 | Feature | Questions, units, topics gating |
| `tests/Feature/Freemium/Student/StudentPracticeSessionAccountingTest.php` | **15** | Feature | **Stage 1E.2.D.C practice accounting** |
| **Total Feature Freemium Tests** | **210** | **Feature** | **All Freemium feature tests** |
| `tests/Unit/Freemium/DatabaseCompatibilityCheckerTest.php` | 11 | Unit | Engine & version compatibility |
| `tests/Unit/Freemium/FreemiumLegacyQuotaConfigurationTest.php` | 10 | Unit | Strict integer & boolean parsing |
| `tests/Unit/Freemium/UnifiedEntitlementResolverTest.php` | 16 | Unit | Entitlement resolver 4 tiers |
| **Total Unit Freemium Tests** | **37** | **Unit** | **All Freemium unit tests** |
| `tests/Feature/StudentClassSubscriptionIntegrationTest.php` | **16** | Integration | Baseline LMS subscriptions |
| **Grand Total Unique Tests** | **263** | | **100% Passing, 0 Regressions** |

### Deduplication Reconciliation
- **Feature Freemium directory total**: 210 tests.
  - Stage 1E.2.D.C added 15 tests in `StudentPracticeSessionAccountingTest.php` and 4 tests in `AssessmentPracticeConcurrencyTest.php` (= 19 new tests).
  - Prior pre-existing Freemium feature tests: 191 tests.
  - `191 + 19 = 210 tests`.
- **Double Counting Clarification**:
  The 15 practice accounting tests and 4 practice concurrency tests are located inside `tests/Feature/Freemium/`. They are part of the 210 Feature test total, NOT in addition to it.
- **Grand Total Unique System Tests Verified**:
  `210 (Freemium Feature) + 37 (Freemium Unit) + 16 (Subscription Integration) = 263 unique tests`.
  Every single test passed with zero failures.

---

## 4. Risk Assessment & Unresolved Concerns Matrix

| Area | Risk Level | Assessment / Mitigation | Status |
| :--- | :---: | :--- | :---: |
| **Active Session Resumption** | LOW | Resumption queries match exact sorted unit IDs and `offered_program_id`. Non-matching scopes generate new sessions. | Mitigated |
| **Pessimistic Locking** | LOW | WebUser parent row locked (`lockForUpdate()`) ensures no double-generation bypass during concurrent requests. | Mitigated |
| **Submitted Immutability** | LOW | Both pre-transaction check and row-locked transaction check reject answer updates with HTTP 422 if submitted. | Mitigated |
| **Submission Idempotency** | LOW | Repeated submission replays stored accuracy/score and bypasses activity log and question statistics duplication. | Mitigated |
| **Scope Mismatch for Paid Students** | LOW | Entitlement resolver checks requested subject against subscribed programs; unentitled subjects drop to Freemium/fallback. | Mitigated |

---

## 5. Audit Conclusion

Stage 1E.2.D.C implementation satisfies all technical, architectural, and security requirements without defects or regressions. The codebase is fully verified and stable.

Per the audit instructions, all activities for Stage 1E.2.D.C are complete. **No Stage 1E.2.D.D implementation has been initiated.**
