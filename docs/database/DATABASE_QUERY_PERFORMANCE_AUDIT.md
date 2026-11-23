# Application-Wide Query Performance Audit — The Students Times LMS

**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Audited Databases:** `3peas_db` (primary local development) & `3peas_db_subscription_test` (local test)  
**MySQL Version:** 8.0.43 (InnoDB)  
**Audit Date:** 2026-11-19  
**Execution Mode:** READ-ONLY Source & Profiling Inspection  

---

## Executive Summary

A comprehensive, application-wide performance audit was conducted across the Laravel 11 backend codebase (379 PHP files, 141 Eloquent models, 428 relationships, 24 explicit SQL JOINs, and over 1,500 Query Builder calls) and its connected MySQL database schema. The audit evaluated query efficiency, indexing coverage, execution plans (via read-only `EXPLAIN`), and scalability bottlenecks across all major LMS modules.

### Key Performance Audit Findings

1. **Critical Unindexed Table Scans in High-Frequency Workflows:**
   - `exam_question_board_tbl` (4,324 rows) contains zero secondary indexes. Joins on `question_id` and filters on `(board_id, session_id, year, group_id)` perform 100% full table scans across 4,324 rows.
   - `student_performance_tbl` (1,646 rows) contains zero secondary indexes. Queries on `test_id` and `question_id` perform full table scans.
   - `user_selected_subject_tbl` (388 rows) contains zero secondary indexes. Lookups on `user_id` require full table scans on student portal loads.
   - `user_subscriptions` (5 rows in dev, but core entitlement table): Lacks composite indexes on `(user_id, status, expires_at)` or `(user_id, offered_program_id, status)`. Every subscription entitlement verification scans user rows with unindexed post-filtering.
2. **Filesorts on High-Volume Audit and Activity Tables:**
   - `admin_activity_logs` (14,179 rows): Filtered lookups on `WHERE module = ? AND action = ? ORDER BY created_at DESC` execute with `Using filesort` because existing indexes do not cover the sort column.
   - `test_attempts`: Lookups for active in-progress attempts `WHERE user_id = ? AND test_id = ? AND status = 'in_progress' ORDER BY id DESC LIMIT 1` select `test_attempts_user_id_status_index` with `Using where; Backward index scan`, scanning across all user tests instead of a direct 3-column point lookup.
3. **Redundant Index Overhead on High-Write Tables:**
   - `freemium_student_grants` contains an exact duplicate index `idx_fsg_campaign_user` mirroring the unique constraint `uk_campaign_user` on `(campaign_id, user_id)`.
   - `exam_question_tbl` (23,904 rows, 12.7 MB index size): `idx_questions_book_unit` on `(book_id, unit_id)` is completely redundant with `idx_questions_hierarchy` on `(book_id, unit_id, topic_id)`.
   - `question_reports` has 3 single-column indexes (`question_id`, `reporter_web_user_id`, `status`) that are exact leftmost prefixes of composite indexes.

---

## Module-by-Module Query Analysis & Database Profiling

### 1. Questions & Educational Content Module

**Source Files:**
- `app/Http/Controllers/QuestionsController.php`
- `app/Http/Services/TeacherMcqTestService.php`
- `app/Http/Services/TeacherSubjectResourceService.php`
- `app/Models/ExamQuestion.php`

#### Query Pattern 1: Question Selection by Curriculum Hierarchy
```sql
SELECT q.id, q.question, q.marks
FROM exam_question_tbl q
WHERE q.book_id = ? AND q.unit_id = ? AND q.topic_id = ?
  AND q.activate = 1 AND q.is_mcq = 1
ORDER BY q.id ASC;
```
**Existing Index:** `idx_questions_hierarchy` on `(book_id, unit_id, topic_id)`  
**Profiling Result (EXPLAIN):**
- `type`: `ref`
- `key`: `idx_questions_hierarchy`
- `key_len`: `15` (covers 3 columns)
- `Extra`: `Using where`
**Analysis:** The existing index effectively filters down to the topic level. However, `activate` and `is_mcq` require reading row data from disk/buffer pool to evaluate conditions. Because nearly all student test and practice queries filter `activate = 1 AND is_mcq = 1`, appending these flags or ordering can optimize high-volume question generation.

#### Query Pattern 2: Board Exam Question Filtering & Deletion (CRITICAL BOTTLENECK)
```sql
SELECT q.id, q.question
FROM exam_question_tbl q
JOIN exam_question_board_tbl b ON q.id = b.question_id
WHERE b.board_id = ? AND b.session_id = ? AND b.year = ? AND b.group_id = ?;
```
**Existing Indexes on `exam_question_board_tbl`:** `PRIMARY KEY (id)` ONLY!  
**Measured Execution Plan (EXPLAIN):**
```
+----+-------------+-------+--------+---------------+---------+---------+--------------------------+------+----------+-------------+
| id | select_type | table | type   | possible_keys | key     | key_len | ref                      | rows | filtered | Extra       |
+----+-------------+-------+--------+---------------+---------+---------+--------------------------+------+----------+-------------+
|  1 | SIMPLE      | b     | ALL    | NULL          | NULL    | NULL    | NULL                     | 4324 |     0.02 | Using where |
|  1 | SIMPLE      | q     | eq_ref | PRIMARY       | PRIMARY | 8       | 3peas_db.b.question_id   |    1 |   100.00 | Using where |
+----+-------------+-------+--------+---------------+---------+---------+--------------------------+------+----------+-------------+
```
**Diagnosis:** `exam_question_board_tbl` has 4,324 rows and ZERO secondary indexes. MySQL must perform a **FULL TABLE SCAN** (`type: ALL`) across all 4,324 rows. Furthermore, in `QuestionsController.php:2304`:
```php
DB::table('exam_question_board_tbl')->whereIn('question_id', $questionIds)->delete();
```
Without an index on `question_id`, question bulk deletions or cascades must scan all 4,324 rows. As board questions grow past 50,000 records, this will lead to severe transaction lock contention and database CPU spikes.

---

### 2. Assessment, Test Attempts & LMS Progress Module

**Source Files:**
- `app/Http/Controllers/Tests/TestController.php`
- `app/Http/Controllers/ExamTestController.php`
- `app/Models/TestAttempt.php`
- `app/Models/Test.php`

#### Query Pattern 3: Active Test Attempt Retrieval During Progress & Submit
```sql
SELECT *
FROM test_attempts
WHERE user_id = ? AND test_id = ? AND status = 'in_progress'
ORDER BY id DESC
LIMIT 1;
```
**Existing Indexes on `test_attempts`:**
- `test_attempts_user_id_status_index (user_id, status)`
- `test_attempts_user_id_test_id_index (user_id, test_id)`
**Measured Execution Plan (EXPLAIN):**
- `key`: `test_attempts_user_id_status_index`
- `type`: `ref`
- `Extra`: `Using where; Backward index scan`
**Analysis:** MySQL is forced to pick between `(user_id, status)` or `(user_id, test_id)`. Picking `(user_id, status)` requires row filtering on `test_id`. As an active student completes dozens of tests, finding their active attempt on a specific test scans all in-progress attempts for that student. A targeted composite index `(user_id, test_id, status, id)` enables a direct 3-column index seek without post-filtering.

#### Query Pattern 4: Legacy Student Performance Tracking
```sql
SELECT question_id, is_correct, is_attempted
FROM student_performance_tbl
WHERE test_id = ?;
```
**Existing Indexes:** `PRIMARY KEY (id)` ONLY!  
**Measured Execution Plan (EXPLAIN):**
- `type`: `ALL` (Full Table Scan across 1,646 rows)
- `possible_keys`: `NULL`
- `rows`: `1646`
**Diagnosis:** `student_performance_tbl` stores historical student performance metrics. Having zero indexes on `test_id` means every test review, score calculation, or analytics query performs a full table scan. In production, this table will quickly exceed 500,000 rows.

---

### 3. Subscriptions & Entitlements Module

**Source Files:**
- `app/Http/Services/StudentClassAccessService.php`
- `app/Http/Services/StudentSubscriptionPaymentService.php`
- `app/Http/Controllers/OfferedClassesController.php`
- `app/Models/UserSubscription.php`

#### Query Pattern 5: Active Student Subscription Verification
```sql
SELECT id, user_id, offered_program_id, status, expires_at
FROM user_subscriptions
WHERE user_id = ? AND offered_program_id = ? AND status = 'active'
  AND (expires_at IS NULL OR expires_at >= CURDATE())
LIMIT 1;
```
**Existing Indexes on `user_subscriptions`:**
- `user_subscriptions_user_id_foreign (user_id)`
- `user_subscriptions_offered_program_id_foreign (offered_program_id)`
**Analysis:** The existing index only indexes `user_id`. When verifying student entitlement, MySQL retrieves all subscription rows for the student and performs sequential evaluation of `offered_program_id`, `status`, and `expires_at`. Adding a composite index on `(user_id, status, offered_program_id, expires_at)` eliminates row filtering and enables index-only entitlement lookups.

---

### 4. Freemium Subsystem & Quota Enforcement Module

**Source Files:**
- `app/Http/Services/Freemium/FreemiumQuotaConsumptionService.php`
- `app/Http/Services/Freemium/FreemiumEntitlementService.php`
- `app/Models/Freemium/FreemiumQuotaConsumption.php`
- `app/Models/Freemium/FreemiumQuotaCounter.php`
- `app/Models/Freemium/FreemiumStudentGrant.php`

#### Query Pattern 6: Quota Window Consumption Aggregation
```sql
SELECT COUNT(*) as total
FROM freemium_quota_consumptions
WHERE user_id = ? AND exam_type = 'formal_test'
  AND consumed_at >= ? AND consumed_at <= ?;
```
**Existing Indexes on `freemium_quota_consumptions`:**
- `idx_user_exam_consumed (user_id, exam_type, consumed_at)`
- `uk_user_idempotency (user_id, idempotency_key)`
**Measured Execution Plan (EXPLAIN):**
- `key`: `uk_user_idempotency` or `idx_user_exam_consumed`
- `type`: `ref`
- `Extra`: `Using where`
**Analysis:** In current tests, MySQL chose `uk_user_idempotency` because table is nearly empty. Under production data distributions where `user_id` has many records, MySQL's cost optimizer will select `idx_user_exam_consumed (user_id, exam_type, consumed_at)`, performing an efficient range scan. The index design for Freemium quota consumptions is well-architected.

#### Query Pattern 7: Duplicate Index in `freemium_student_grants`
- `uk_campaign_user`: `UNIQUE (campaign_id, user_id)`
- `idx_fsg_campaign_user`: `INDEX (campaign_id, user_id)`
**Finding:** `idx_fsg_campaign_user` is a 100% duplicate of `uk_campaign_user`. Every insert, update, or grant issuance must update both B-Tree structures. `idx_fsg_campaign_user` is completely redundant and should be safely removed.

---

### 5. Past Papers & Model Papers Module

**Source Files:**
- `app/Http/Controllers/LegacyPastPaperController.php`
- `app/Http/Services/TeacherSubjectResourceService.php`
- `app/Models/PastPaper.php`
- `app/Models/TstPastPaper.php`

#### Query Pattern 8: Past Paper Search & Filtering
```sql
SELECT pp.id, pp.paper_name, pp.paper_slug, pp.paper_path
FROM past_paper_tbl pp
WHERE pp.class_id = ? AND pp.subject_id = ? AND pp.board_id = ? AND pp.year = ?
  AND pp.activate = 1
ORDER BY pp.id DESC;
```
**Existing Indexes on `past_paper_tbl` (5,125 rows):**
- `pp_tbl_curriculum_scope_idx (activate, curriculum_board_id, class_id, subject_id, year, board_id)`
- Single-column foreign keys: `class_id`, `subject_id`, `board_id`, `session_id`, `curriculum_board_id`
**Analysis:** When searching legacy past papers where `curriculum_board_id IS NULL`, `pp_tbl_curriculum_scope_idx` cannot be utilized past column 2 (`curriculum_board_id`). MySQL falls back to a single-column index on `subject_id` or `class_id` and filters the remaining 5,000+ rows. A composite index `(activate, class_id, subject_id, board_id, year)` supports both curriculum and non-curriculum past papers.

---

### 6. Administration, Activity Logging & Audit Trails

**Source Files:**
- `app/Http/Controllers/AdminActivityLogController.php`
- `app/Models/AdminActivityLog.php`
- `app/Models/StudentActivity.php`

#### Query Pattern 9: Admin Activity Audit Trail Filtering
```sql
SELECT id, admin_user_id, action, module, created_at
FROM admin_activity_logs
WHERE module = 'Questions' AND action = 'create'
ORDER BY created_at DESC
LIMIT 20;
```
**Existing Indexes on `admin_activity_logs` (14,179 rows):**
- `admin_activity_logs_module_index (module)` (Redundant prefix)
- `admin_activity_logs_module_action_index (module, action)`
- `admin_activity_logs_created_at_index (created_at)`
**Measured Execution Plan (EXPLAIN):**
- `key`: `admin_activity_logs_module_action_index`
- `type`: `ref`
- `Extra`: `Using where; Using filesort`
**Diagnosis:** MySQL uses `(module, action)` to filter matching records, but must then sort all matching results in memory or on disk (`Using filesort`) because `created_at` is not part of the composite index. In an active LMS where admin activity exceeds 100,000 rows, this query will cause latency on admin dashboard views. Extending the index to `(module, action, created_at)` eliminates the filesort.

---

### 7. Teacher Portal & Class Management Module

**Source Files:**
- `app/Http/Controllers/TeacherPortal/TeacherClassAssignmentController.php`
- `app/Http/Controllers/TeacherPortal/TeacherClassTestController.php`
- `app/Models/TeacherClassAssignment.php`
- `app/Models/TeacherClassTest.php`

#### Query Pattern 10: Teacher Class Assignments Listing
```sql
SELECT id, title, due_at, status
FROM teacher_class_assignments
WHERE teacher_class_id = ? AND status = 'published'
ORDER BY due_at ASC;
```
**Existing Index:** `idx_tca7f_class_status_due (teacher_class_id, status, due_at)`  
**Analysis:** Highly optimized composite index. Filters on `teacher_class_id` and `status` using an exact seek, while satisfying the `ORDER BY due_at ASC` clause directly from the index leaf nodes without `Using filesort`.

---

## Eloquent Relationship & N+1 Query Risk Audit

Inspection of 428 Eloquent relationship definitions and controller usages identified three critical areas of N+1 query vulnerability:

1. **Question Options and Answers Eager Loading:**
   - `ExamQuestion` has `hasMany(ExamQuestionOption::class)` and `hasOne(ExamAnswer::class)`.
   - In `ExamTestController.php` and `QuestionsController.php`, several endpoints iterate over questions and call `$question->options` or `$question->answer` inside loops without eager loading `load(['options', 'answers'])`.
   - While `exam_question_options_tbl` has an index on `question_id`, executing 50 separate queries for a 50-question test introduces unnecessary network roundtrips.
2. **Student Class Enrollment & User Profile Resolution:**
   - In teacher class student rosters, accessing student profile information from `web_users` and `user_profile_tbl` without eager loading triggers multiple single-row lookups.
3. **Teacher Settlement Allocations:**
   - Generating settlement statements queries `teacher_settlement_allocations` and loops through `payment_request_id` without eager loading `subscription_payment_requests`.

---

## Summary of Query Optimization Priorities

| Priority | Module | Table & Columns | Performance Risk | Remediation |
| :---: | :--- | :--- | :--- | :--- |
| **CRITICAL** | Past Papers & Questions | `exam_question_board_tbl` (`question_id`, `board_id`, `year`) | Full table scan on 4,324 rows during question filtering & joins | Add composite index `(board_id, year, session_id, group_id)` and single index `(question_id)` |
| **CRITICAL** | Assessment Tracking | `student_performance_tbl` (`test_id`, `question_id`) | Full table scan on 1,646 rows during test evaluation | Add composite index `(test_id, question_id)` |
| **HIGH** | Student Subscriptions | `user_subscriptions` (`user_id`, `status`, `expires_at`, `offered_program_id`) | Row post-filtering on every authorization check | Add composite index `(user_id, status, offered_program_id, expires_at)` |
| **HIGH** | LMS Test Attempts | `test_attempts` (`user_id`, `test_id`, `status`, `id`) | Backward index scan & post-filtering during active attempt lookup | Add composite index `(user_id, test_id, status, id)` |
| **HIGH** | Admin Logs | `admin_activity_logs` (`module`, `action`, `created_at`) | Disk/memory filesort across 14,000+ log records | Extend index to `(module, action, created_at)` |
| **MEDIUM** | Student Profile | `user_selected_subject_tbl` (`user_id`, `subject_id`) | Full table scan on student dashboard loads | Add index `(user_id, subject_id)` |
| **LOW** | Freemium Subsystem | `freemium_student_grants` (`campaign_id`, `user_id`) | Duplicate B-Tree write overhead | Drop redundant index `idx_fsg_campaign_user` |

