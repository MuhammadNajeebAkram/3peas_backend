# Stage 1E.2.D.1 — Assessment & Practice Source Inventory

**Document ID:** `PHASE_1E_2_D_1_ASSESSMENT_SOURCE_INVENTORY`  
**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Phase:** 1E.2.D.1 (Planning and Source Audit — Final Migration & Integration Review)  
**Timestamp:** 2026-11-19  

---

## 1. Executive Summary

This document establishes the exhaustive source code inventory for all assessment, practice, and test-taking components in the LMS backend. It inspects controllers, traits, models, database schemas, foreign keys, and existing Freemium quota services to ground the Stage 1E.2.D implementation in verified source code.

---

## 2. Controller & Trait Inventory

### 2.1 Core LMS Assessment Controllers

| Controller | Location | Responsibility | Guard / Auth |
|---|---|---|---|
| `TestController` | `app/Http/Controllers/Tests/TestController.php` (1,174 lines) | Generates formal unit & full-book tests, tracks question progress, records test submission, aggregates recent student test/practice history. | `web_api` (`AttachJwtFromCookie:lms`, `AuthenticateJwtCookieGuard:lms`) |
| `PracticeSessionController` | `app/Http/Controllers/Tests/PracticeSessionController.php` (924 lines) | Generates unit & full-book practice sessions, saves instant practice feedback progress, finalizes practice sessions. | `web_api` (`AttachJwtFromCookie:lms`, `AuthenticateJwtCookieGuard:lms`) |
| `SelectsLmsMcqQuestions` | `app/Http/Controllers/Tests/Concerns/SelectsLmsMcqQuestions.php` (131 lines) | Shared trait for random MCQ selection across units; handles straight MCQs and scenario group clusters. | N/A (PHP Trait) |

### 2.2 Adjacent / Classroom Assessment Controllers (Out of Scope for Freemium Self-Study)

| Controller | Location | Scope & Status |
|---|---|---|
| `StudentClassTestController` | `app/Http/Controllers/Student/StudentClassTestController.php` | Phase 7D Teacher-assigned classroom tests (`teacher_classes`, `teacher_class_tests`). Scoped to enrolled classrooms. Not self-study LMS assessments. |
| `TeacherClassTestController` | `app/Http/Controllers/Teacher/TeacherClassTestController.php` | Phase 7D Teacher test creation and management. |
| `TeacherClassTestDeliveryController` | `app/Http/Controllers/Teacher/TeacherClassTestDeliveryController.php` | Phase 7D Teacher test assignment and results publishing. |
| `ExamTestController` | `app/Http/Controllers/ExamTestController.php` | Legacy unauthenticated stored procedure endpoints (`getPracticeMCQsTestOfUnits`, `getRandomMCQsTestOfUnits`). Unused in modern authenticated LMS. |

---

## 3. Database Schema & Model Inventory

### 3.1 Tests Table (`tests`)
- **Migration:** `database/migrations/2026_04_17_193021_create_test_attempts_table.php` (lines 14-49)
- **Model:** `App\Models\Test`
- **Columns & Data Types:**
  - `id`: `bigint unsigned auto_increment primary key`
  - `offered_program_id`: `bigint unsigned nullable foreign key -> offered_programs.id (nullOnDelete)`
  - `subject_id`: `bigint unsigned nullable foreign key -> subject_tbl.id (nullOnDelete)`
  - `created_by`: `bigint unsigned nullable foreign key -> users.id (nullOnDelete)` (Admin user; null for student/system generated)
  - `title`: `varchar(255)`
  - `description`: `varchar(255) nullable`
  - `test_source`: `enum('system', 'teacher', 'official') default 'system'`
  - `test_mode`: `enum('test', 'mock')`
  - `scope_type`: `enum('chapter', 'multiple_chapters', 'full_book')`
  - `question_type`: `enum('mcq') default 'mcq'`
  - `time_limit_minutes`: `int unsigned nullable`
  - `total_questions`: `int unsigned default 0`
  - `is_published`: `boolean default false`
  - `published_at`: `timestamp nullable`
  - `created_at`, `updated_at`: `timestamps`

### 3.2 Test Scopes Table (`test_scopes`)
- **Model:** `App\Models\TestScope`
- **Columns:** `id`, `test_id` (`fk -> tests.id cascade`), `unit_id` (`fk -> book_unit_tbl.id cascade`), `timestamps`.

### 3.3 Test Questions Table (`test_questions`)
- **Model:** `App\Models\TestQuestion`
- **Columns:** `id`, `test_id` (`fk -> tests.id cascade`), `question_id` (`fk -> exam_question_tbl.id cascade`), `question_order` (`int unsigned`), `marks` (`decimal 5,2 default 1.00`), `timestamps`.

### 3.4 Test Attempts Table (`test_attempts`)
- **Migration:** `database/migrations/2026_04_17_193021_create_test_attempts_table.php` (lines 51-82)
- **Model:** `App\Models\TestAttempt`
- **Columns & Data Types:**
  - `id`: `bigint unsigned auto_increment primary key`
  - `test_id`: `bigint unsigned foreign key -> tests.id cascade`
  - `user_id`: `bigint unsigned foreign key -> web_users.id cascade` (Authenticated student)
  - `attempted_questions`: `int unsigned default 0`
  - `correct_answers`: `int unsigned default 0`
  - `wrong_answers`: `int unsigned default 0`
  - `not_attempted_questions`: `int unsigned default 0`
  - `score`: `decimal(8, 2) default 0.00`
  - `accuracy`: `decimal(5, 2) default 0.00`
  - `started_at`: `timestamp nullable`
  - `submitted_at`: `timestamp nullable`
  - `status`: `enum('in_progress', 'submitted', 'expired', 'cancelled') default 'in_progress'`
  - `created_at`, `updated_at`: `timestamps`
- **Critical Architectural Note:** `test_attempts` does **NOT** contain an `attempt_number` column. Attempt sequencing is tracked authoritatively in `freemium_quota_consumptions.attempt_number`.

### 3.5 Test Attempt Questions Table (`test_attempt_questions`)
- **Model:** `App\Models\TestAttemptQuestion`
- **Columns:** `id`, `attempt_id` (`fk -> test_attempts.id`), `question_id` (`fk -> exam_question_tbl.id`), `question_order`, `selected_option_id`, `is_attempted`, `is_correct`, `marks`, `obtained_marks`, `time_spent_seconds`, `timestamps`.

### 3.6 Practice Sessions Table (`practice_sessions`)
- **Migration:** `database/migrations/2026_04_17_203610_create_practice_sessions_table.php`
- **Model:** `App\Models\PracticeSession`
- **Columns & Data Types:**
  - `id`: `bigint unsigned auto_increment primary key`
  - `user_id`: `bigint unsigned foreign key -> web_users.id cascade` (Authenticated student)
  - `offered_program_id`: `bigint unsigned nullable fk -> offered_programs.id (nullOnDelete)`
  - `subject_id`: `bigint unsigned nullable fk -> subject_tbl.id (nullOnDelete)`
  - `scope_type`: `enum('chapter', 'multiple_chapters', 'full_book')`
  - `question_type`: `enum('mcq') default 'mcq'`
  - `time_limit_minutes`: `int unsigned nullable`
  - `total_questions`: `int unsigned default 0`
  - `attempted_questions`, `correct_answers`, `wrong_answers`, `not_attempted_questions`: `int unsigned default 0`
  - `score`: `decimal(8, 2) default 0.00`
  - `accuracy`: `decimal(5, 2) default 0.00`
  - `started_at`, `submitted_at`: `timestamp nullable`
  - `status`: `enum('in_progress', 'submitted', 'abandoned') default 'in_progress'`
  - `created_at`, `updated_at`: `timestamps`

---

## 4. Freemium Subsystem Services & Models

### 4.1 Freemium Core Services
1. `UnifiedEntitlementResolver` (`app/Http/Services/Freemium/UnifiedEntitlementResolver.php`): Authoritative 4-tier entitlement resolver (Paid -> Explicit Freemium -> Legacy Free Fallback -> Default Deny).
2. `FreemiumQuotaConsumptionService` (`app/Http/Services/Freemium/FreemiumQuotaConsumptionService.php`):
   - `consumeQuota(WebUser $student, string $examType, string $eventType, array $options)`: Atomic transaction with parent-row locking on `WebUser` and pessimistic counter lock on `FreemiumQuotaCounter`.
   - `getStudentQuotaAllowances(WebUser $student, ?string $filterExamType, ?Carbon $now)`: Purely read-only quota definition and balance calculation.
   - `getTestAttemptEligibility(WebUser $student, int $testId, ?Carbon $now)`: Read-only attempt and retake eligibility check with fail-closed ownership verification.
   - `canonicalFingerprint(array $payload)`: SHA-256 fingerprint generator with sorted keys.
3. `FreemiumQuotaWindow` (`app/Http/Services/Freemium/FreemiumQuotaWindow.php`): Resolves window keys and UTC boundary timestamps for calendar windows under `Asia/Karachi`.

### 4.2 Verified Database Schema Constraints & Migration Findings

| Table | Constraint | Current Applied Definition | Planned Forward Migration Definition |
|---|---|---|---|
| `freemium_quota_consumptions` | `uk_user_idempotency` | `UNIQUE (user_id, idempotency_key)` | Preserved. |
| `freemium_quota_consumptions` | `uk_test_attempt_event` | `UNIQUE (test_id, attempt_number, event_type)` | Forward migration `000009` drops this and creates `uk_test_user_attempt_event (test_id, user_id, attempt_number, event_type)`. |
| `freemium_quota_consumptions` | `chk_exam_entity_mutex` | `CHECK ((test_id IS NOT NULL AND practice_session_id IS NULL) OR ...)` | Preserved. |
| `freemium_quota_counters` | `uk_quota_counter` | `UNIQUE (user_id, exam_type, window_type, window_key)` | Preserved. |
