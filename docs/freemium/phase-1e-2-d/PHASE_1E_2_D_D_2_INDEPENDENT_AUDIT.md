# Independent Read-Only Audit — Stage 1E.2.D.D.2: Mock-Test Attempt Accounting

**Date:** November 19, 2026  
**Auditor:** Independent Antigravity QA & Security Auditor  
**Scope:** Stage 1E.2.D.D.2 Implementation Only  
**Target Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Audit Status:** COMPLETE — ALL AREAS PASS  
**Final Recommendation:** ACCEPT STAGE 1E.2.D.D.2  

---

## Executive Summary

A comprehensive, strict read-only independent audit of **Stage 1E.2.D.D.2 (Mock-Test Attempt Accounting)** was conducted on `E:\Al-Faraabi Software\3peas_backend`.

The audit evaluated seven key dimensions:
1. Mock-test classification and separation from formal/practice tests.
2. Legacy quota configuration and fallback behavior.
3. Entitlement precedence (Paid > Campaign > Fallback > Denied).
4. Attempt and retake accounting lifecycle semantics.
5. Ownership protection, student isolation, and concurrency safety.
6. Test suite coverage and execution reconciliation.
7. Git working tree integrity and scope boundary adherence.

**Audit Verdict:** All seven evaluated areas have received a **PASS** verdict. No blocking defects, regression issues, or quota leakage risks were identified. Stage 1E.2.D.D.2 is fully verified and recommended for acceptance.

---

## Section 1: Mock-Test Classification

**Verdict:** **PASS**

### 1.1 Findings & Code Evidence
- **Authoritative Classification (`tests.test_mode = 'mock'`):**  
  In The Students Times LMS, mock tests are stored within the core `tests` table and differentiated exclusively by the column `test_mode = 'mock'` (compared to `'test'` for formal tests).
- **Quota Separation in Attempt Workflows:**  
  In `app/Http/Controllers/Tests/TestController.php`:
  - **Save Progress / Start Attempt (Lines 696–701):**
    ```php
    $decision = $this->entitlementResolver->resolve(
        $user,
        $targetContext,
        'perm_mcqs',
        $test->test_mode === 'mock' ? 'mock' : 'formal_test'
    );
    ```
  - **Submit Formal/Mock Test (Lines 1180–1185):**
    ```php
    $decision = $this->entitlementResolver->resolve(
        $user,
        $targetContext,
        'perm_mcqs',
        $test->test_mode === 'mock' ? 'mock' : 'formal_test'
    );
    ```
  - **Eligibility & Quota Consumption Service (`FreemiumQuotaConsumptionService.php:842–844`):**
    ```php
    public function consumeAttemptQuota(WebUser $student, Test $test, int $attemptNumber, string $eventType, array $options = []): FreemiumQuotaConsumption {
        $examType = ($test->test_mode === 'mock') ? 'mock' : 'formal_test';
        ...
        return $this->consumeQuota($student, $examType, $eventType, $mergedOptions);
    }
    ```
- **Formal Test & Practice Isolation:**  
  - Formal tests (`test_mode = 'test'`) strictly map to `exam_type = 'formal_test'`.
  - Practice sessions are orchestrated via `PracticeSessionController.php`, which strictly consumes `exam_type = 'practice'` and has zero references to or interactions with `mock`.
- **No Alternative Endpoint Bypass:**  
  Inspection of `routes/web_api.php` confirmed that all student assessment start, save progress, and submit actions flow through `TestController` (`/web_api/auth/tests/save-formal-test-question-progress` and `/web_api/auth/tests/submit-formal-test`). No unmonitored or unauthenticated legacy route permits starting or retaking a mock test without going through `UnifiedEntitlementResolver` and `FreemiumQuotaConsumptionService`.

---

## Section 2: Legacy Quota Configuration

**Verdict:** **PASS**

### 2.1 Findings & Code Evidence
- **Configuration Defaults (`config/freemium.php:17–22`):**
  ```php
  'fallback_quotas' => [
      'formal_test'      => env('FREEMIUM_LEGACY_WEEKLY_TESTS', '1'),
      'mock'             => env('FREEMIUM_LEGACY_WEEKLY_MOCKS', '1'),
      'practice'         => env('FREEMIUM_LEGACY_WEEKLY_PRACTICE', '2'),
      'practice_session' => env('FREEMIUM_LEGACY_WEEKLY_PRACTICE', '2'),
  ],
  ```
  - Default weekly mock quota is `1`.
  - Configurable at runtime via `FREEMIUM_LEGACY_WEEKLY_MOCKS`.
  - Existing formal-test default of `1` (`FREEMIUM_LEGACY_WEEKLY_TESTS`) is preserved without mutation.
- **Consumption Service Default Ceiling (`FreemiumQuotaConsumptionService.php:218–221`):**
  ```php
  $configKey = 'freemium.fallback_quotas.' . $examType;
  $defaultCeiling = ($examType === 'formal_test' || $examType === 'mock' ? 1 : 2);
  $quotaCeiling = $this->resolveConfiguredQuota($configKey, $defaultCeiling);
  ```
- **Strict Normalization (`FreemiumQuotaConsumptionService.php:55–84`):**
  - **Zero (`0`):** Accepted and preserved; disables legacy-free mock attempts (`$quotaCeiling === 0` throws `QuotaLimitExceededException` upon generation/attempt).
  - **Negative integers / decimals / non-numeric strings:** Safely rejected with a warning log and fall back to the safe default (`1`).
- **Campaign Quota Precedence:**  
  In `FreemiumQuotaConsumptionService.php:191–216`, if active campaign envelopes exist (`$options['primary_campaign']` or matching `FreemiumAccessRule`), the consumption ledger writes campaign-specific envelopes. Legacy fallback is evaluated only if `$options['is_legacy_fallback'] === true`.
- **Pakistan-Local Weekly Window:**  
  Window keys are generated using Asia/Karachi timezone (`$pkt = $now->copy()->setTimezone('Asia/Karachi'); $windowKey = 'PK:legacy:weekly:' . $pkt->format('o-\WW');`), ensuring consistent boundary resets every Monday at 00:00 PKT.

---

## Section 3: Entitlement Precedence

**Verdict:** **PASS**

### 3.1 Findings & Code Evidence
- **4-Tier Authoritative Resolution Hierarchy:**
  1. **Tier 1 (Paid):** Active `UserSubscription` with `status = 'active'`, `payment_status = 'paid'`, and unexpired `expires_at` covering the test's `offered_program_id`.
  2. **Tier 2 (Freemium Campaign):** Active `FreemiumCampaign` matching student status and scope with active `FreemiumAccessRule` granting `perm_mcqs = 'allow'`.
  3. **Tier 3 (Legacy Free):** Published mock test or class/subject marked `is_free = 1` falling back to weekly fallback quotas.
  4. **Tier 4 (Default Deny):** Fail-closed denial (`FORBIDDEN`, HTTP 403).
- **Paid Bypass Verification:**  
  In `TestController.php:747–754` & `785–797`:
  - When `$decision->isPaid() === true`, attempt counting is calculated from `TestAttempt::where('test_id', $test->id)->where('user_id', $user->id)->count()`.
  - Quota consumption call `$this->quotaConsumptionService->consumeAttemptQuota(...)` is explicitly wrapped in `if (!$decision->isPaid())`, ensuring zero Freemium quota ledger records or counter increments are created for paid students.
- **Out-of-Scope and Expired Subscriptions:**  
  If a student holds an active subscription for Program 994, but accesses a mock test belonging to Program 995, `UnifiedEntitlementResolver` falls through to Tier 2/3. Similarly, expired subscriptions (`expires_at < now()`) fall through. Verified by feature test `test_expired_or_out_of_scope_paid_access_falls_through_correctly`.

---

## Section 4: Attempt and Retake Accounting

**Verdict:** **PASS**

### 4.1 Findings & Code Evidence
- **Lifecycle Events:**
  - **Initial Attempt (`attemptNumber === 1`):** Recorded with `event_type = 'attempt_start'`, `attempt_number = 1`.
  - **Retake (`attemptNumber > 1`):** Recorded with `event_type = 'retake_start'`, `attempt_number = $attemptNumber`.
- **Inclusive Attempt Limits:**  
  `FreemiumQuotaConsumptionService.php:707–718`:
  ```php
  if ($attemptCount >= $maxAttempts) {
      return [
          'can_attempt'           => false,
          'denial_reason'         => "Maximum permitted attempts ({$maxAttempts}) reached for this test.",
      ];
  }
  ```
  The calculation counts all prior attempts (initial + previous retakes). When `max_attempts_per_test = 2`, attempt 1 succeeds, attempt 2 succeeds, and attempt 3 is rejected with HTTP 403 `QUOTA_EXHAUSTED`.
- **Enforcement of `allow_retakes = false`:**  
  When `allow_retakes = false` and `attemptCount >= 1`, `can_attempt` returns `false` with denial reason `'Retakes are not permitted for this test.'`.
- **Active Attempt Resumption:**  
  In `TestController.php:720–734`, if an active `in_progress` attempt exists for the authenticated user and test, `$attempt` is retrieved and reused. The `if (!$attempt)` creation block is bypassed, resulting in **zero additional quota consumptions**.
- **Answer Updates:**  
  Calls to `POST /web_api/auth/tests/save-formal-test-question-progress` with an existing `in_progress` attempt update `TestAttemptQuestion` and recalculate intermediate scores; **zero quota records** are written.
- **Abandoned Attempt Burn Policy:**  
  Once an attempt is started (recording `attempt_start` and creating a `TestAttempt`), the quota is consumed immediately. If the student abandons the attempt without submitting, that attempt counts against `max_attempts_per_test`. If `allow_retakes = false`, subsequent attempts are rejected.
- **Submission Replay:**  
  In `TestController.php:1158–1172`, re-submitting an already submitted attempt replays the original score and accuracy or returns 409 conflict, generating **zero new quota consumptions**.

---

## Section 5: Ownership and Concurrency

**Verdict:** **PASS**

### 5.1 Findings & Code Evidence
- **Strict Ownership Checks (`TestController.php:640–652`, `1135–1147`):**
  ```php
  $isOfficialShared = ($test->test_source === 'official' && (bool) $test->is_published);
  $isStudentOwned = (
      (int) $test->created_by === (int) $user->id ||
      FreemiumQuotaConsumption::where('test_id', $test->id)->where('user_id', $user->id)->exists() ||
      TestAttempt::where('test_id', $test->id)->where('user_id', $user->id)->exists()
  );

  if (!$isOfficialShared && !$isStudentOwned) {
      return response()->json([
          'success' => 0,
          'error'   => 'Test not found or access denied.',
      ], 404);
  }
  ```
  Private mock tests authored by or assigned to another student fail closed with a safe HTTP 404 (`Test not found or access denied.`).
- **Student Data Isolation:**  
  All `TestAttempt` queries are scoped with `where('user_id', $user->id)`. In `getRecentAttemptsForLms`, results are strictly filtered to the authenticated student's ID, preventing any cross-student data leakage on shared official mock tests.
- **Concurrency & Pessimistic Row Locking Order:**  
  Both `TestController` and `FreemiumQuotaConsumptionService` execute operations inside `DB::transaction()` and adhere strictly to the established deadlock-free lock order:
  1. `WebUser::where('id', $student->id)->lockForUpdate()->first();` (Parent row serialization)
  2. `TestAttempt::where(...)->lockForUpdate()->first();` (Attempt row serialization)
  3. `FreemiumQuotaCounter::where(...)->lockForUpdate()->first();` (Counter row serialization)
- **Multi-Process Concurrency Verification:**  
  `StudentMockTestAttemptAccountingTest::test_concurrent_attempts_cannot_exceed_allowance` executes two independent PHP CLI processes via `Symfony\Component\Process\Process`, bootstrapping the Laravel kernel in separate operating system processes. Concurrency execution against MySQL InnoDB confirmed that exactly 1 attempt and 1 quota consumption record were persisted.

---

## Section 6: Test Verification

**Verdict:** **PASS**

### 6.1 Findings & Evidence
The test suite was inspected and executed directly in the project environment.

#### 1. Focused Mock Suite
- **Command:** `php artisan test tests/Feature/Freemium/Student/StudentMockTestAttemptAccountingTest.php`
- **Results:** **15 passed, 0 failed, 0 skipped** (73 assertions)
- **Duration:** 4.92s
- **Scenarios Verified:**
  1. `test_mock_attempt_uses_mock_quota_category`: PASS
  2. `test_formal_test_attempts_continue_using_formal_test`: PASS
  3. `test_paid_mock_test_access_bypasses_freemium_consumption_only_within_valid_scope`: PASS
  4. `test_expired_or_out_of_scope_paid_access_falls_through_correctly`: PASS
  5. `test_initial_mock_attempt_consumes_one_allowance`: PASS
  6. `test_retake_consumes_the_correct_additional_allowance`: PASS
  7. `test_disabled_retakes_are_rejected`: PASS
  8. `test_maximum_attempt_count_is_enforced_inclusively`: PASS
  9. `test_resuming_an_active_mock_attempt_consumes_nothing_extra`: PASS
  10. `test_answer_updates_consume_nothing_extra`: PASS
  11. `test_submission_replay_does_not_consume_another_allowance`: PASS
  12. `test_abandoned_mock_attempts_follow_approved_policy`: PASS
  13. `test_shared_official_mock_tests_isolate_different_students`: PASS
  14. `test_unauthorized_private_mock_test_access_is_rejected`: PASS
  15. `test_concurrent_attempts_cannot_exceed_allowance`: PASS

#### 2. Full Freemium Regression Suite
- **Command:** `php artisan test tests/Feature/Freemium/ tests/Unit/Freemium/`
- **Results:** **271 passed, 0 failed, 0 skipped** (1,265 assertions)
- **Duration:** 47.23s

#### 3. Subscription Regression Suite
- **Command:** `php artisan test --filter=Subscription`
- **Results:** **158 passed, 0 failed, 0 skipped** (1,142 assertions)
- **Duration:** 58.77s

---

## Section 7: Git and Scope Review

**Verdict:** **PASS**

### 7.1 Working Tree Audit
- `git status` inspection confirms no commits have been made (`On branch main, Your branch is up to date with 'origin/main'`).
- Pre-existing uncommitted work from previous authorized stages (Phase 1E.2.C and Phase 1E.2.D.A/D.B/D.C/D.D.1) remains intact.
- D.D.2 modifications are strictly limited to:
  - `config/freemium.php`: Addition of `'mock'` fallback quota.
  - `app/Http/Services/Freemium/FreemiumQuotaConsumptionService.php`: Normalization of `$defaultCeiling` to 1 for `'mock'`.
  - `tests/Feature/Freemium/Student/StudentMockTestAttemptAccountingTest.php`: 15 focused tests.
  - `docs/freemium/phase-1e-2-d/PHASE_1E_2_D_D_2_COMPLETION_REPORT.md`: Completion report.
- No database migrations were created or modified.
- No production or remote environments were accessed.

---

## Final Recommendation & Next Steps

### Recommendation: **ACCEPT STAGE 1E.2.D.D.2**

Stage 1E.2.D.D.2 has satisfied all technical, security, idempotency, and testing requirements without defects or regressions.

### Boundary Enforcement
In accordance with instructions:
- **No changes or fixes were applied during this audit.**
- **Stage 1E.2.D.D.3 has NOT been started.**
- **The system is paused and awaiting user authorization for subsequent stages.**
