# Stage 1E.2.D.1 — Implementation Roadmap

**Document ID:** `PHASE_1E_2_D_1_IMPLEMENTATION_ROADMAP`  
**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Phase:** 1E.2.D.1 (Planning and Source Audit — Final Review Corrections)  
**Timestamp:** 2026-11-19

---

## 1. Overview & Strategy

This roadmap structures the execution of Stage 1E.2.D (Assessment & Practice Quota Enforcement) into four tightly scoped, independently reviewable, and auditable stages. It incorporates:

1. The safe forward migration `2026_12_01_000009_update_freemium_test_attempt_unique_index.php` with fail-closed rollback checks.
2. The approved legacy-free quota defaults (1 formal test / 2 practice sessions per week) with strict parser validation.
3. The reconciled practice repeat policy (`allow_retakes = false`, `max_attempts = 1`, repeat consumes from weekly 2).
4. Multi-process concurrency verification across 6 discrete scenarios.

---

## 2. Proposed Implementation Stages

### Stage 1E.2.D.A — Formal Test Generation Quota Enforcement & Legacy-Free Configuration

- **Objective:** Enforce paid entitlement bypass, explicit Freemium campaign quota consumption, and approved legacy-free defaults (1 test/week) during formal test generation. Protect against concurrent quota bypasses and duplicate requests via idempotency keys.
- **Endpoints Covered:**
    - `POST /web_api/auth/tests/generate-selected-units-formal-test`
    - `POST /web_api/auth/tests/generate-full-book-formal-test`
- **Files Expected to Change:**
    - `config/freemium.php` (configurable legacy-free weekly limits)
    - `app/Http/Controllers/Tests/TestController.php` (integrate entitlement check and quota consumption)
    - `app/Http/Services/Freemium/FreemiumQuotaConsumptionService.php` (support legacy-free default quota envelope with strict parser)
- **Dependencies:**
    - `UnifiedEntitlementResolver`
    - `FreemiumQuotaConsumptionService`
- **Tests Created:**
    - `tests/Unit/Freemium/FreemiumLegacyQuotaConfigurationTest.php`
    - `tests/Feature/Freemium/Student/StudentFormalTestGenerationQuotaTest.php`
- **Acceptance Criteria:**
    - Paid subscribers generate formal tests without quota consumption.
    - Freemium students consume 1 `formal_test` quota per generation.
    - Legacy-free students receive 1 formal test per week; second generation in same weekly window fails with 403 `QUOTA_EXHAUSTED`.
    - Zero quota explicitly disables generation (fails closed with 403).
    - Same idempotency key + payload replays identical test without double consumption.
    - Same key + different payload returns HTTP 409 Conflict.
- **Rollback:** Revert changes in `TestController.php` and `config/freemium.php`.

---

### Stage 1E.2.D.B — Forward Migration, Shared-Test Ledger Uniqueness & Formal Test Attempts

- **Objective:** Create and run forward migration `2026_12_01_000009_update_freemium_test_attempt_unique_index.php` to drop `uk_test_attempt_event`, establish `uk_test_user_attempt_event` on `(test_id, user_id, attempt_number, event_type)`, and make campaign foreign keys nullable. Enforce attempt caps and retake rules when a student starts or retakes a formal test.
- **Endpoints Covered:**
    - `POST /web_api/auth/tests/save-formal-test-question-progress`
    - `POST /web_api/auth/tests/submit-formal-test`
- **Files Expected to Change:**
    - `database/migrations/2026_12_01_000009_update_freemium_test_attempt_unique_index.php` (new forward migration with fail-closed rollback pre-check)
    - `app/Http/Controllers/Tests/TestController.php`
    - `app/Http/Services/Freemium/FreemiumQuotaConsumptionService.php`
- **Dependencies:**
    - Stage 1E.2.D.A
    - `FreemiumQuotaConsumptionService::getTestAttemptEligibility`
- **Tests Created:**
    - `tests/Feature/Freemium/FreemiumMigrationRollbackSafetyTest.php`
    - `tests/Feature/Freemium/Student/StudentFormalTestAttemptQuotaTest.php`
- **Acceptance Criteria:**
    - Forward migration executes cleanly; fail-closed rollback pre-check aborts if conflicting data exists.
    - Multiple students can simultaneously take Attempt #1 on the same official test without collision.
    - The same student cannot create duplicate attempts for the same attempt number.
    - Attempt 1 succeeds without consuming generation quota.
    - If `allow_retakes = false`, subsequent attempts fail closed with HTTP 403.
    - In-flight answer saving (Op-4) remains non-chargeable and accepts multiple updates without idempotency conflicts.
    - Test submission finalization is idempotent (Op-5).
- **Rollback:** Roll back migration `000009` (if safe) and revert attempt logic in `TestController.php`.

---

### Stage 1E.2.D.C — Practice Session Generation, Resume & Submission Enforcement

- **Objective:** Enforce practice quota consumption upon generation (including legacy-free 2 sessions/week default) while guaranteeing that resuming an active practice session consumes zero quota. Practice sessions strictly enforce `allow_retakes = false` and `max_attempts = 1`; repeat practice on the same units consumes 1 of the 2 weekly session allowances.
- **Endpoints Covered:**
    - `POST /web_api/auth/tests/generate-selected-units-practice-session`
    - `POST /web_api/auth/tests/generate-full-book-practice-session`
    - `POST /web_api/auth/tests/save-practice-session-question-progress`
    - `POST /web_api/auth/tests/submit-practice-session`
- **Files Expected to Change:**
    - `app/Http/Controllers/Tests/PracticeSessionController.php`
- **Dependencies:**
    - `UnifiedEntitlementResolver`
    - `FreemiumQuotaConsumptionService`
- **Tests Created:**
    - `tests/Feature/Freemium/Student/StudentPracticeSessionQuotaTest.php`
- **Acceptance Criteria:**
    - Practice generation consumes 1 `practice` quota credit.
    - Legacy-free students receive exactly 2 practice sessions per week.
    - Resuming an in-progress practice session consumes zero quota.
    - Repeating practice on completed units generates a new session and consumes 1 weekly session credit.
    - Submitted sessions cannot be resumed or modified (HTTP 422).
- **Rollback:** Revert changes in `PracticeSessionController.php`.

---

### Stage 1E.2.D.D — Multi-Process Concurrency Hardening & Independent Verification Audit

- **Objective:** Execute automated multi-process concurrency tests covering all 6 concurrency scenarios against local MySQL InnoDB. Conduct final independent verification audit.
- **Files Expected to Change:**
    - `tests/Feature/Freemium/Concurrency/AssessmentQuotaConcurrencyTest.php` (new test suite)
- **Dependencies:**
    - Stages 1E.2.D.A, 1E.2.D.B, 1E.2.D.C
- **Acceptance Criteria:**
    - All 6 multi-process concurrency scenarios pass against MySQL InnoDB with zero race conditions.
    - All existing Freemium tests (166+) and subscription regression tests (59) pass 100%.
    - Zero uncommitted code deviations.
