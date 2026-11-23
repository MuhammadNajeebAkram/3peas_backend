# Stage 1E.2.D.B — Assessment and Attempt Source Inventory

**Document ID:** `PHASE_1E_2_D_B_ASSESSMENT_AND_ATTEMPT_SOURCE_INVENTORY`  
**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Stage:** 1E.2.D.B — Shared Official-Test Uniqueness, Attempt & Retake Accounting (Planning Mode)  
**Timestamp:** 2026-11-19  

---

## 1. Executive Summary

This inventory documents all source code, models, controllers, traits, and database schemas in `E:\Al-Faraabi Software\3peas_backend` that participate in student formal-test generation, attempt lifecycle management, progress autosaving, attempt finalization, and Freemium quota ledger accounting.

---

## 2. Controller & Service Inventory

### 2.1 Primary LMS Assessment Controllers
1. **`TestController`**
   - **Path:** [`app/Http/Controllers/Tests/TestController.php`](file:///e:/Al-Faraabi%20Software/3peas_backend/app/Http/Controllers/Tests/TestController.php) (1,500 lines)
   - **Role:** Central assessment orchestrator for self-study students.
   - **Key Methods:**
     - `generateSelectedUnitsFormalTestForLms(Request $request)` (L44–229): Generates unit-scoped formal test, consumes generation quota (Stage 1E.2.D.A).
     - `generateFullBookFormalTestForLms(Request $request)` (L231–402): Generates full-book formal test, consumes generation quota (Stage 1E.2.D.A).
     - `generateFormalTestRecord(...)` (L422–522): Private entity builder creating `Test`, `TestScope`, and `TestQuestion` rows.
     - `saveFormalTestQuestionProgressForLms(Request $request)` (L607–709): Autosaves answer progress; creates lazy `TestAttempt` on first save if absent.
     - `getRecentAttemptsForLms(Request $request)` (L711–843): Fetches recent submitted attempts across formal tests and practice sessions.
     - `submitFormalTestForLms(Request $request)` (L845–1085): Finalizes attempt, evaluates correctness, updates statistics, marks status `submitted`.
     - `refreshTestAttemptProgress(TestAttempt $attempt)` (L1126–1172): Recalculates in-flight score, accuracy, and attempted question count.

2. **`StudentFreemiumQuotaController`**
   - **Path:** [`app/Http/Controllers/Api/Student/Freemium/StudentFreemiumQuotaController.php`](file:///e:/Al-Faraabi%20Software/3peas_backend/app/Http/Controllers/Api/Student/Freemium/StudentFreemiumQuotaController.php) (87 lines)
   - **Role:** Student-facing read-only quota and eligibility queries.
   - **Key Methods:**
     - `quotaAllowances(Request $request)` (L30–57): Returns remaining generation quotas and reset windows.
     - `testEligibility(Request $request, int $testId)` (L62–86): Returns attempt and retake eligibility, attempt number, and remaining attempts.

3. **`FreemiumQuotaConsumptionService`**
   - **Path:** [`app/Http/Services/Freemium/FreemiumQuotaConsumptionService.php`](file:///e:/Al-Faraabi%20Software/3peas_backend/app/Http/Services/Freemium/FreemiumQuotaConsumptionService.php) (788 lines)
   - **Role:** Authoritative Freemium quota consumption and eligibility engine.
   - **Key Methods:**
     - `consumeQuota(...)` (L80–270): Validates allowances, increments counters, and appends to `freemium_quota_consumptions`.
     - `getStudentQuotaAllowances(...)` (L480–655): Resolves active quotas across explicit campaigns and legacy-free fallback.
     - `getTestAttemptEligibility(...)` (L660–787): Authoritative evaluator for student test ownership, attempt counts, retake permission, and attempt caps.

4. **`AssessmentIdempotencyService`**
   - **Path:** [`app/Http/Services/Freemium/AssessmentIdempotencyService.php`](file:///e:/Al-Faraabi%20Software/3peas_backend/app/Http/Services/Freemium/AssessmentIdempotencyService.php) (180 lines)
   - **Role:** Idempotency guard backed by `assessment_idempotency_records` table and MySQL InnoDB constraints.

---

## 3. Database Schema Inventory

### 3.1 Tests Table (`tests`)
- **Primary Key:** `id` (`bigint unsigned`)
- **Key Columns:**
  - `offered_program_id`: `bigint unsigned nullable`
  - `subject_id`: `bigint unsigned nullable`
  - `created_by`: `bigint unsigned nullable` (references `web_users.id` for student-generated tests)
  - `title`: `varchar(255)`
  - `test_source`: `enum('system', 'teacher', 'official')` default `'system'`
  - `test_mode`: `enum('test', 'mock')`
  - `scope_type`: `enum('chapter', 'multiple_chapters', 'full_book')`
  - `total_questions`: `int unsigned`
  - `is_published`: `tinyint(1)` default `0`
  - `published_at`: `timestamp nullable`

### 3.2 Test Attempts Table (`test_attempts`)
- **Primary Key:** `id` (`bigint unsigned`)
- **Columns (Verified):**
  - `test_id`: `bigint unsigned`
  - `user_id`: `bigint unsigned`
  - `attempted_questions`: `int unsigned` default `0`
  - `correct_answers`: `int unsigned` default `0`
  - `wrong_answers`: `int unsigned` default `0`
  - `not_attempted_questions`: `int unsigned`
  - `score`: `decimal(8,2)` default `0.00`
  - `accuracy`: `decimal(5,2)` default `0.00`
  - `started_at`: `timestamp nullable`
  - `submitted_at`: `timestamp nullable`
  - `status`: `enum('in_progress', 'submitted', 'expired', 'cancelled')`
  - `created_at`, `updated_at`: `timestamps`
- **Critical Finding:** `test_attempts` **does NOT have an `attempt_number` column**. Attempt numbers are derived ordinally from chronological order or from `freemium_quota_consumptions.attempt_number`.

### 3.3 Test Attempt Questions Table (`test_attempt_questions`)
- **Primary Key:** `id` (`bigint unsigned`)
- **Columns:** `attempt_id`, `question_id`, `question_order`, `selected_option_id`, `is_attempted`, `is_correct`, `marks`, `obtained_marks`, `time_spent_seconds`.
- **Unique Constraint:** `(attempt_id, question_id)` enables safe in-flight answer updates via `updateOrCreate`.

### 3.4 Freemium Quota Consumptions Table (`freemium_quota_consumptions`)
- **Primary Key:** `id` (`bigint unsigned`)
- **Foreign Keys:**
  - `user_id` -> `web_users(id)` (`NOT NULL`)
  - `primary_campaign_id` -> `freemium_campaigns(id)` (Nullable via 000009)
  - `primary_quota_id` -> `freemium_exam_quotas(id)` (Nullable via 000009)
  - `test_id` -> `tests(id)` (Nullable)
  - `test_attempt_id` -> `test_attempts(id)` (Nullable)
  - `practice_session_id` -> `practice_sessions(id)` (Nullable)
- **Attempt Tracking Columns:**
  - `event_type`: `enum('generation', 'attempt_start', 'retake_start', 'practice_generation', 'practice_completion')`
  - `attempt_number`: `unsignedInteger` (0 for generation, 1..N for attempts/retakes)
- **Current Problematic Index:**
  - `uk_test_attempt_event`: `UNIQUE (test_id, attempt_number, event_type)` — **Lacks `user_id`**, causing multi-student collision on shared official tests.
