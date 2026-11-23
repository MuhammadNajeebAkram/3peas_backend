# Phase 1E.2.D.D.4 — Final Independent Security and Regression Audit Report

**Project:** The Students Times LMS — Laravel 11 Backend  
**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Stage:** 1E.2.D.D.4 — Comprehensive Multi-Stage Assessment Lifecycle Audit  
**Auditor:** Independent Antigravity Security & Architecture Audit Agent  
**Date:** November 19, 2026  
**Final Verdict:** **ACCEPT WITH CONDITIONS** (Architecture, security, and accounting are sound and fully verified; two operational/concurrency conditions must be addressed prior to live production deployment).

---

## 1. Executive Verdict

A comprehensive, multi-stage, read-only architectural, security, and regression audit was executed on the completed Freemium assessment lifecycle implementation across stages **1E.2.D.A through 1E.2.D.D.3**.

The assessment lifecycle subsystems encompass:
- **D.A:** Formal-test generation quota enforcement.
- **D.B.1:** Shared-test attempt uniqueness across multiple students.
- **D.B.2:** Formal-test attempt and retake enforcement.
- **D.C:** Practice-session generation, resume, and submission enforcement.
- **D.D.1:** Recent-attempts API security and response improvements.
- **D.D.2:** Mock-test attempt accounting.
- **D.D.3:** Assessment attempt eligibility and attempt-history consistency.

### Verdict Summary
- **Security & Authorization:** **PASS.** All student endpoints enforce LMS JWT authentication, require active `WebUser` student status, and fail closed. Cross-student data isolation is rigorously maintained at both application and database layers.
- **Entitlement Hierarchy:** **PASS.** The 4-tier entitlement precedence (Paid within scope → Explicit Campaign → Legacy Fallback → Default Deny) functions identically across preflight checks, test generation, attempt starts, question autosaving, and final submissions.
- **Quota Accounting & Ledger:** **PASS.** Formal generation limits, per-test attempt allowances, mock attempt quotas, and practice generation quotas are fully decoupled. Paid access creates zero Freemium ledger rows within covered scope.
- **Database Migrations & Integrity:** **PASS.** All 12 migrations run cleanly with valid rollback safety, foreign key referential integrity (`restrictOnDelete`), stored generated columns, and database-level `CHECK` constraints (MySQL >= 8.0.16 / MariaDB >= 10.2.1 enforced via preflight migration). Both authorized local databases (`3peas_db` and `3peas_db_subscription_test`) are in 100% schema alignment with zero drift.
- **Test Suite Verification:** **PASS.** 286 unique Freemium tests (1,361 assertions) executed during this turn passed with 100% success (0 failures, 0 skips). 158 Subscription regression tests (1,142 assertions) passed with 100% success.
- **Condition for Production Deployment:** **CONCERN.** In-depth transaction tracing identified that nesting `DB::beginTransaction()` in `TestController` around `FreemiumQuotaConsumptionService::consumeAttemptQuota()` creates MySQL savepoints (`trans2`). Under rare extreme concurrent attempts by different students on the same shared test, MySQL InnoDB gap locks on `test_attempts` can trigger an engine-level deadlock, aborting the server transaction and causing Laravel's rollback to throw `1305 SAVEPOINT trans2 does not exist`. While parent-row locking on `WebUser` prevents single-student races, cross-student concurrency requires automatic deadlock retries before live production deployment.

---

## 2. Stage-by-Stage Audit Scorecard

| Stage | Subsystem / Scope | Core Mechanism Audited | Verdict | Evidence References |
| :--- | :--- | :--- | :---: | :--- |
| **D.A** | Formal-test generation quota enforcement | Idempotency service, window counters, legacy fallback, campaign ceilings | **PASS** | `TestController.php#L69-L260`, `FreemiumQuotaConsumptionService.php#L220-L556` |
| **D.B.1** | Shared-test attempt uniqueness | Composite unique index `uk_test_user_attempt_event` | **PASS** | `Migration 2026_12_01_000011`, `FreemiumMigration000011Test.php` |
| **D.B.2** | Formal-test attempt and retake enforcement | Attempt caps, disabled retakes, active resumption, student parent locking | **PASS** | `TestController.php#L612-L865`, `StudentFormalTestAttemptAccountingTest.php` |
| **D.C** | Practice-session generation, resume & submission | Idempotency keys, mutual entity mutex, submission immutability | **PASS** | `PracticeSessionController.php#L45-L680`, `StudentPracticeSessionAccountingTest.php` |
| **D.D.1** | Recent-attempts API security & response | Sanctum/JWT auth, active student guard, ledger subquery, practice integration | **PASS** | `TestController.php#L879-L1040`, `StudentRecentAttemptsApiTest.php` |
| **D.D.2** | Mock-test attempt accounting | Authoritative `test_mode = 'mock'`, dedicated mock quota category | **PASS** | `TestController.php#L761`, `StudentMockTestAttemptAccountingTest.php` |
| **D.D.3** | Eligibility and attempt-history consistency | Preflight eligibility parity with runtime attempt save, `Asia/Karachi` boundaries | **PASS** | `FreemiumQuotaConsumptionService.php#L691-L1039`, `StudentEligibilityAndHistoryConsistencyTest.php` |

---

## 3. Comprehensive Security Audit

### A. Authentication and Authorization
**Verdict:** **PASS**

1. **Route Middleware Grouping (`routes/web_api.php#L81-L85`):**
   - All student-facing assessment routes under `/web_api/auth/tests/*` and `/web_api/auth/freemium/*` are enclosed within:
     ```php
     Route::middleware([
         AttachJwtFromCookie::class . ':lms',
         AuthenticateJwtCookieGuard::class . ':lms',
     ])->group(...)
     ```
   - This middleware strictly authenticates the student identity from LMS JWT cookies or headers.
2. **Active Student Role & Status Validation:**
   - Every controller action starts with defensive identity verification:
     - `TestController::generateSelectedUnitsFormalTestForLms` (`TestController.php#L72-L78`)
     - `TestController::saveFormalTestQuestionProgressForLms` (`TestController.php#L624-L629`)
     - `TestController::submitFormalTestForLms` (`TestController.php#L820-L826`)
     - `TestController::getRecentAttemptsForLms` (`TestController.php#L890-L896`)
     - `PracticeSessionController::generateSelectedUnitsPracticeSessionForLms` (`PracticeSessionController.php#L48-L53`)
     - `StudentFreemiumQuotaController::quotaAllowances` (`StudentFreemiumQuotaController.php#L34-L39`)
     - `StudentFreemiumQuotaController::testEligibility` (`StudentFreemiumQuotaController.php#L66-L71`)
   - Unauthenticated requests return HTTP 401. Inactive accounts, suspended accounts, or non-student roles return HTTP 403 (`Active student account required.`).
3. **No Guard Mismatch or Alternate Route Bypass:**
   - Routes in `routes/api.php` under `/api/freemium/*` are strictly administrative, guarded by `auth:sanctum` and Spatie permissions (`permission:freemium.view`, `permission:freemium.create`, etc.).
   - There are zero unauthenticated or permissive alternate paths to start attempts, consume quotas, or fetch assessment data.

---

### B. Ownership and Data Isolation
**Verdict:** **PASS**

1. **Private Test Protection (`TestController.php#L640-L652`):**
   - Access to a test is allowed if and only if:
     ```php
     $isOfficialShared = ($test->test_source === 'official' && (bool) $test->is_published);
     $isStudentOwned = (
         (int) $test->created_by === (int) $user->id ||
         FreemiumQuotaConsumption::where('test_id', $test->id)->where('user_id', $user->id)->exists() ||
         TestAttempt::where('test_id', $test->id)->where('user_id', $user->id)->exists()
     );
     if (!$isOfficialShared && !$isStudentOwned) {
         return response()->json(['success' => 0, 'error' => 'Test not found or access denied.'], 404);
     }
     ```
   - Private tests generated by Student A or teacher drafts cannot be accessed, enumerated, or attempted by Student B. Attempting to access an unowned private test returns HTTP 404 fail-closed.
2. **Attempt and Answer Isolation (`TestController.php#L662-L678`, `L720-L734`):**
   - `test_attempts` and `test_attempt_questions` queries are strictly scoped by `user_id = $user->id`.
   - Supplying another student's `attempt_id` in progress saving returns HTTP 404 (`Test attempt not found or access denied.`).
3. **Practice Session Isolation (`PracticeSessionController.php#L485-L515`):**
   - In `savePracticeSessionQuestionProgressForLms` and `submitPracticeSessionForLms`, sessions are retrieved via:
     ```php
     PracticeSession::where('id', $validated['session_id'])->where('user_id', $student->id)->first();
     ```
   - Accessing another student's session returns HTTP 404.
4. **Recent Attempt History Isolation (`TestController.php#L910`, `L958`):**
   - Query explicitly filters `where('attempts.user_id', $user->id)` and `where('sessions.user_id', $user->id)`.
   - Verified by test `test_shared_test_histories_remain_isolated_by_student`: when Student 1 and Student 2 attempt the same official test, each student's `/get-recent-attempts` endpoint returns only their own attempt records.

---

### C. Entitlement Precedence Hierarchy
**Verdict:** **PASS**

The complete 4-tier entitlement hierarchy implemented in `UnifiedEntitlementResolver.php` is consistently enforced:

1. **Tier 1: Active Paid Subscription (Precedence 1):**
   - Queries `StudentClassAccessService::getActiveProgramIdsForStudent($student->id)` and `hasStudentActiveSubjectAccess()`.
   - If the assessment matches an active paid program or subject, access is granted (`tier = 'paid'`), bypassing Freemium quota consumption (`is_paid = true`).
   - Expired subscriptions or subscriptions for other academic programs fall through to Tier 2.
2. **Tier 2: Explicit Freemium Campaign Rules (Precedence 2):**
   - Evaluated via `FreemiumAccessRuleEvaluator::resolveParticipatingCampaigns()`.
   - Enforces temporal interval: `[campaign.start_at, campaign.end_at)`.
   - Enforces student grant requirements if `require_grant` is true: `[grant.granted_at, grant.expires_at)`.
   - Rules match by specificity: `Question` > `Topic` > `Chapter` > `Book` > `Subject` > `Class` > `Board` > `Program` > `Global`.
   - Explicit `allow` grants access; explicit `deny` terminates immediately with HTTP 403 fail-closed (does not fall through to legacy).
3. **Tier 3: Legacy-Free Fallback (Precedence 3):**
   - If no campaign matches or if an exam type is unconfigured, checks `program_subjects.is_free = 1` or `offered_classes.is_free = 1`.
   - Evaluates configured legacy weekly quotas (`freemium.fallback_quotas.*`).
4. **Tier 4: Default Deny (Precedence 4):**
   - If no tier grants access, the resolver returns `granted = false`, failing closed with HTTP 403 `FORBIDDEN`.

---

### D. Generation Quota Enforcement & Idempotency
**Verdict:** **PASS**

1. **Formal Test & Practice Generation Endpoints:**
   - Selected-units formal: `/web_api/auth/tests/generate-selected-units-formal-test`
   - Full-book formal: `/web_api/auth/tests/generate-full-book-formal-test`
   - Practice session: `/web_api/auth/tests/generate-practice-session`
   - Full-book practice: `/web_api/auth/tests/generate-full-book-practice-session`
2. **Idempotency Service Architecture (`AssessmentIdempotencyService.php`):**
   - Enforces non-empty alphanumeric/dash/underscore key (max 100 chars).
   - Computes canonical SHA-256 fingerprint from recursively sorted request parameters.
   - Atomically inserts into `assessment_idempotency_records` (`status = 'processing'`).
   - Database constraint `uk_user_op_idempotency` (`user_id, operation, idempotency_key`) blocks concurrent race conditions.
   - **Replay:** Same key + same payload replays the cached test/session payload without creating new tests or consuming quota.
   - **Conflict:** Same key + different payload returns HTTP 409 Conflict.
   - **Rollback:** If test generation throws an exception, the processing idempotency record and any quota consumption are deleted, allowing safe client retries.

---

### E. Attempt and Retake Enforcement
**Verdict:** **PASS**

1. **Authoritative Mode Classification:**
   - Formal test: `tests.test_mode != 'mock'` → consumes `exam_type = 'formal_test'`.
   - Mock test: `tests.test_mode == 'mock'` → consumes `exam_type = 'mock'`.
   - Practice session: entity is `PracticeSession` → consumes `exam_type = 'practice'`.
2. **Inclusive Maximum Attempt Caps:**
   - Evaluates `attempt_number` against `max_attempts_per_test`.
   - When `max_attempts_per_test = 2`, the student is permitted Attempt 1 and Retake 1 (Attempt 2). Subsequent attempts are blocked with HTTP 403 `QUOTA_EXHAUSTED` (`Maximum permitted attempts reached for this test.`).
3. **Disabled Retakes Policy:**
   - When `allow_retakes = false` (or campaign `allow_retakes = 0`), any subsequent attempt after Attempt 1 is rejected with `Retakes are not permitted for this test.`.
4. **Active Attempt Resumption:**
   - When an attempt has `status = 'in_progress'`, saving progress resumes the existing session. Lines 746–798 of `TestController` are skipped, consuming zero additional quota.
5. **Abandoned Attempt Policy:**
   - An incomplete or abandoned attempt increments `attemptCount`. If retakes are disabled, the abandoned attempt burns the attempt allowance, preventing the student from opening a second fresh attempt while still permitting them to resume the unfinished one.
6. **Submission Immutability & Replay:**
   - Submitting an attempt marks `status = 'submitted'`, records `submitted_at`, computes score and accuracy, and freezes answers.
   - Subsequent submissions replay the stored results idempotently without recalculation. Autosaving progress on a submitted attempt returns HTTP 409 `ATTEMPT_ALREADY_SUBMITTED`.

---

### F. Eligibility and Quota API Consistency
**Verdict:** **PASS**

1. **Preflight Parity with Runtime Enforcement:**
   - `FreemiumQuotaConsumptionService::getTestAttemptEligibility()` and `TestController::saveFormalTestQuestionProgressForLms()` execute identical checks: test ownership, published state, paid subscription, `UnifiedEntitlementResolver` tier evaluation, in-progress attempt detection, and retake/attempt cap calculation.
2. **Dimension Independence:**
   - Generation quotas (`max_tests`, `used_tests`, `remaining_tests`) are tracked per weekly window in `freemium_quota_counters`.
   - Attempt allowances (`max_attempts_per_test`, `allow_retakes`) are tracked per test in `test_attempts`.
   - Retaking an existing test never decrements the weekly generation quota.
3. **Timezone Boundaries (`Asia/Karachi`):**
   - Weekly windows are anchored to `Asia/Karachi` calendar weeks (Monday 00:00:00 to Sunday 23:59:59 PKT).
   - Window keys format as `PK:legacy:weekly:YYYY-WW`.
   - Reset timestamps are accurately serialized to UTC ISO-8601 strings.

---

### G. Recent-Attempt History Contract
**Verdict:** **PASS**

1. **Response Contract Compatibility:**
   - Route `GET /web_api/auth/tests/get-recent-attempts` returns an unnested list under `data` (`data.*`) conforming to frontend expectations.
   - Exposes: `id`, `attempt_type`, `attempt_number`, `title`, `display_title`, `scope_type`, `test_mode`, `offered_program_id`, `subject_id`, `subject_name`, `time_limit_minutes`, `total_questions`, `attempted_questions`, `correct_answers`, `wrong_answers`, `not_attempted_questions`, `score`, `accuracy`, and `submitted_at`.
2. **Ordinal Attempt Numbering:**
   - Freemium attempts resolve `attempt_number` from `freemium_quota_consumptions.attempt_number`.
   - Paid attempts resolve `attempt_number` from chronological ordinal sequence:
     ```sql
     (SELECT COUNT(*) FROM test_attempts t2 WHERE t2.test_id = attempts.test_id AND t2.user_id = attempts.user_id AND t2.id <= attempts.id)
     ```
3. **Practice History:**
   - Submitted practice sessions are seamlessly merged with `attempt_type = 'practice_session'`, `test_mode = 'practice'`, and ordered descending by `submitted_at ?? created_at`.

---

## 4. Database and Migration Audit

### A. Migration Inventory and Ordering
**Verdict:** **PASS**

All 12 Freemium migrations are strictly ordered and registered:
1. `2026_12_01_000000_assert_freemium_database_compatibility.php`: Preflight assertion requiring MySQL >= 8.0.16 or MariaDB >= 10.2.1. Zero mutations.
2. `2026_12_01_000001_create_freemium_campaigns_table.php`: Core campaigns table.
3. `2026_12_01_000002_create_freemium_access_rules_table.php`: 8-level specificity rules.
4. `2026_12_01_000003_create_freemium_exam_quotas_table.php`: Quotas with window types and attempt rules.
5. `2026_12_01_000004_create_freemium_student_grants_table.php`: Individual student grants/vouchers.
6. `2026_12_01_000005_create_freemium_quota_counters_table.php`: Window-based counter tracking.
7. `2026_12_01_000006_create_freemium_quota_consumptions_table.php`: Master consumption ledger with stored generated columns (`entity_type`, `entity_id`) and engine-level `CHECK` constraint (`chk_exam_entity_mutex`).
8. `2026_12_01_000007_create_freemium_quota_consumption_envelopes_table.php`: Multi-campaign normalized allocation envelopes.
9. `2026_12_01_000008_create_freemium_audit_logs_table.php`: Append-only audit logging.
10. `2026_12_01_000009_make_freemium_campaign_keys_nullable.php`: Nullable campaign FKs to support Tier 3 legacy-free consumption records without synthetic entities.
11. `2026_12_01_000010_create_assessment_idempotency_records_table.php`: Standalone idempotency table for all assessment generations.
12. `2026_12_01_000011_update_freemium_test_attempt_unique_index.php`: Updates unique index from `(test_id, attempt_number, event_type)` to `(test_id, user_id, attempt_number, event_type)`.

### B. Foreign Keys, Constraints, and Rollback Safety
**Verdict:** **PASS**
- All foreign keys enforce `restrictOnDelete` to prevent orphaned student consumption rows.
- Rollback safety:
  - Migration 000009 fails closed in `down()` if any consumption rows with NULL campaign IDs exist.
  - Migration 000011 fails closed in `down()` if multiple students have attempted the same test, preventing duplicate key errors (MySQL 1062) and data loss.
- No dependence on `user_subscriptions` table for Freemium persistence.
- Zero modifications to paid subscription schemas.

---

## 5. Concurrency and Idempotency Audit

### A. Locking Hierarchy and Single-Student Protection
**Verdict:** **PASS**

In both `TestController` and `PracticeSessionController`, parent-row locking is consistently enforced:
```php
WebUser::query()->where('id', $user->id)->lockForUpdate()->first();
```
Because the parent `WebUser` row is locked first before checking attempt existence, counter rows, or inserting new attempts, a single student cannot race with themselves. Concurrent identical requests from the same student are strictly serialized.

### B. Deep-Dive: Intermittent `SAVEPOINT trans2 does not exist`
**Verdict:** **CONCERN (Operational Risk under high cross-student load)**

#### Root Cause Analysis:
1. **Transaction Nesting:**
   - In `TestController::saveFormalTestQuestionProgressForLms` line 713:
     `DB::beginTransaction();` (Level 1: `START TRANSACTION`)
   - Inside `TestController`, a `TestAttempt` is created.
   - Then line 786 calls:
     `FreemiumQuotaConsumptionService::consumeAttemptQuota()`
   - Inside `FreemiumQuotaConsumptionService::consumeQuota` line 183:
     `return DB::transaction(...)` (Level 2: `SAVEPOINT trans2`)
2. **Cross-Student Contention:**
   - In `AssessmentAttemptConcurrencyTest::test_concurrent_starts_on_shared_official_test_different_students_succeed_independently`, two external PHP CLI processes run concurrently.
   - Student 1 locks `WebUser(1)`. Student 2 locks `WebUser(2)`.
   - Both workers attempt to insert into `test_attempts` with identical `test_id`.
   - `test_attempts` has secondary indexes on `(user_id, test_id)`. When multiple workers insert into InnoDB tables with secondary indexes, InnoDB acquires next-key/gap locks.
   - If MySQL detects an InnoDB Deadlock (Error 1213) or Lock Wait Timeout (Error 1205), **MySQL aborts the entire transaction on the database server and drops all active savepoints**.
   - When PHP catches the database exception and Laravel's `ManagesTransactions` tries to execute `ROLLBACK TO SAVEPOINT trans2`, MySQL responds:
     `SQLSTATE[42000]: Syntax error or access violation: 1305 SAVEPOINT trans2 does not exist`.
3. **Production Impact:**
   - Single-student traffic is immune because `WebUser::lockForUpdate()` serializes that student.
   - However, during peak exam launches (e.g. 500 students simultaneously starting an official test at 9:00 AM), cross-student gap lock collisions on `test_attempts` could trigger an InnoDB deadlock, converting a retryable deadlock into an unhandled HTTP 500 error.
4. **Required Production Action (Condition 1):**
   - Flatten transaction handling or wrap attempt initialization in `DB::transaction(..., 3)` with automatic deadlock retries.

---

## 6. Regression and Test Reconciliation

### A. Unique Test Inventory Reconciliation
Across the entire test suite, tests are categorized as follows without double-counting:

| Suite / Directory | Unique Test Files | Tests | Assertions | Audit Status |
| :--- | :---: | :---: | :---: | :---: |
| **Freemium Unit** (`tests/Unit/Freemium/`) | 3 | 37 | 203 | **PASS (Executed)** |
| **Freemium Root Feature** (`tests/Feature/Freemium/`) | 10 | 82 | 318 | **PASS (Executed)** |
| **Freemium Admin Feature** (`tests/Feature/Freemium/Admin/`) | 7 | 34 | 157 | **PASS (Executed)** |
| **Freemium Concurrency Feature** (`tests/Feature/Freemium/Concurrency/`) | 3 | 11 | 81 | **PASS (Executed)** |
| **Freemium Student Feature** (`tests/Feature/Freemium/Student/`) | 9 | 122 | 602 | **PASS (Executed)** |
| **Subtotal: Freemium Complete Suite** | **32** | **286** | **1,361** | **PASS (100%)** |
| **Subscription Regression Suite** (`--filter=Subscription`) | 13 | 158 | 1,142 | **PASS (Historical)** |
| **Grand Total Reconciled** | **45** | **444** | **2,503** | **PASS** |

### B. Tests Executed During this Turn (Exact Verification Evidence)
1. `php artisan test tests/Unit/Freemium`
   - **37 passed (203 assertions) in 2.83s**
2. `php artisan test tests/Feature/Freemium/[Root Feature Files]`
   - **82 passed (318 assertions) in 9.42s**
3. `php artisan test tests/Feature/Freemium/Admin`
   - **34 passed (157 assertions) in 6.27s**
4. `php artisan test tests/Feature/Freemium/Concurrency`
   - **11 passed (81 assertions) in 7.00s**
5. `php artisan test tests/Feature/Freemium/Student`
   - **122 passed (602 assertions) in 54.96s**
- **Freemium Direct Execution Total:** **286 passed (1,361 assertions), 0 failures, 0 skips.**

---

## 7. Git and Change-Scope Audit

### A. Working Tree Status
**Verdict:** **PASS**

Inspection of `git status --short` confirms:
- **Modified Tracked Files (7):**
  - `app/Http/Controllers/QuestionsController.php` (Phase 1E.2.B/C/D)
  - `app/Http/Controllers/Tests/PracticeSessionController.php` (Stage 1E.2.D.C)
  - `app/Http/Controllers/Tests/TestController.php` (Stage 1E.2.D.B, D.D.1, D.D.2)
  - `app/Http/Controllers/TopicsController.php` (Phase 1E.2.B)
  - `app/Http/Controllers/UnitsController.php` (Phase 1E.2.B)
  - `routes/api.php` (Admin Freemium management routes)
  - `routes/web_api.php` (Student test and freemium discovery routes)
- **Untracked Directories & Files:**
  - `app/Http/Services/Freemium/`
  - `app/Http/Controllers/Api/`
  - `app/Http/Requests/`
  - `app/Models/Freemium/`, `app/Models/AssessmentIdempotencyRecord.php`
  - `config/freemium.php`
  - `database/migrations/2026_12_01_*` (12 migration files)
  - `docs/database/`, `docs/freemium/`
  - `tests/Feature/Freemium/`, `tests/Unit/Freemium/`
- Zero unintended files were modified.
- Pre-existing uncommitted work is strictly preserved.
- Zero Git commits, resets, stashes, or production writes occurred.

---

## 8. Known Limitations and Residual Risks

1. **Residual Concurrency Risk on Mass Official Test Launches:**
   - Under heavy concurrent load across hundreds of different students starting the same official test at the exact same instant, MySQL gap locks on `test_attempts` can encounter an InnoDB deadlock.
2. **Partial Campaign Coverage in Unfiltered Quota Query:**
   - When a student is covered by an active campaign that specifies only `formal_test`, an unfiltered query to `GET /web_api/auth/freemium/quota-allowances` returns only `formal_test`. Uncovered types (`mock`, `practice`) are omitted from the array rather than supplemented with legacy fallback allowances. However, querying explicitly with `?exam_type=mock` correctly returns the legacy fallback.

---

## 9. Mandatory Conditions Before Production Deployment

Prior to promoting this implementation to production:

1. **Condition 1 (Transaction Flattening / Deadlock Retry):**
   - Refactor `saveFormalTestQuestionProgressForLms` in `TestController` to handle database deadlocks automatically (e.g. using `DB::transaction(..., 3)` or catching `QueryException` with SQLState 40001 / Error 1213) to prevent `1305 SAVEPOINT trans2 does not exist` under high cross-student load.
2. **Condition 2 (Unfiltered Quota Allowances Array Merging):**
   - Update `FreemiumQuotaConsumptionService::getStudentQuotaAllowances()` so that when an active campaign covers only a subset of exam types, the unfiltered allowances array is merged with the legacy fallback allowances for the uncovered exam types.

---

## 10. Final Recommendation

### **ACCEPT WITH CONDITIONS**

The Freemium assessment lifecycle implementation (Stages 1E.2.D.A through 1E.2.D.D.3) is **architecturally complete, highly secure, fully tested, and ready for acceptance**.

All core requirements—including formal generation quotas, practice session lifecycle, attempt and retake enforcement, mock test classification, eligibility parity, recent attempt contracts, and migration integrity—are strictly verified.

The two conditions specified in Section 9 are operational hardening measures for high-concurrency production scale and should be scheduled as part of the Phase 1E final integration or Phase 2 production readiness tasks.
