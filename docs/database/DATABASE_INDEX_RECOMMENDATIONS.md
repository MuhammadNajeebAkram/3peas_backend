# Complete Database Index Optimization Recommendations — The Students Times LMS

**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Databases Audited:** `3peas_db` & `3peas_db_subscription_test`  
**Audit Standard:** MySQL 8.0.43 InnoDB Indexing Best Practices  
**Date:** 2026-11-19  

---

## Executive Summary

This document details the complete index optimization recommendations derived from the application-wide query performance audit and database schema inspection. The recommendations are divided into two distinct categories:

1. **Proposed New Indexes:** 11 targeted indexes designed to resolve measured full table scans, eliminate filesorts, and accelerate high-volume authorization, test progress, and question retrieval workflows.
2. **Redundant & Duplicate Indexes for Removal:** 10 indexes that represent exact duplicates or leftmost prefix redundancies, consuming unnecessary buffer pool memory and imposing write overhead during bulk data operations.

---

## Part 1: Proposed New Indexes

### `exam_question_board_tbl`: `idx_eqb_question_id`

| Property | Specification |
| :--- | :--- |
| **Table Name** | `exam_question_board_tbl` |
| **Proposed Index Name** | `idx_eqb_question_id` |
| **Column Sequence** | `question_id` |
| **Index Type** | NON-UNIQUE B-TREE |
| **Priority** | **CRITICAL** |
| **Supported Query / Workflow** | Joins between exam_question_tbl and board question links; cascading deletions. |
| **Source Code References** | `app/Http/Controllers/QuestionsController.php:319, 2304; app/Http/Services/TeacherSubjectResourceService.php:193` |
| **Why Existing Indexes Are Insufficient** | exam_question_board_tbl has 4,324 rows and ZERO secondary indexes (only PRIMARY KEY id). Every join or bulk delete by question_id forces a 100% full table scan. |
| **Expected Performance Improvement** | Reduces join cost from 4,324 row scan to O(1) point ref lookup per question. |
| **Write Overhead** | Low. Board exam links are created during content ingestion and infrequently modified. |
| **Storage Overhead** | ~120 KB InnoDB index leaf pages. |

### `exam_question_board_tbl`: `idx_eqb_filter_scope`

| Property | Specification |
| :--- | :--- |
| **Table Name** | `exam_question_board_tbl` |
| **Proposed Index Name** | `idx_eqb_filter_scope` |
| **Column Sequence** | `board_id`, `year`, `session_id`, `group_id` |
| **Index Type** | NON-UNIQUE B-TREE |
| **Priority** | **CRITICAL** |
| **Supported Query / Workflow** | Filtering board questions by exam board, academic year, exam session, and group. |
| **Source Code References** | `app/Http/Controllers/QuestionsController.php:325-328, 409-412` |
| **Why Existing Indexes Are Insufficient** | Zero secondary indexes. Multi-condition WHERE clause causes full table scan of 4,324 rows. |
| **Expected Performance Improvement** | Exact composite index range scan returning matching question_ids directly from index leaf nodes. |
| **Write Overhead** | Low (primarily read-heavy table). |
| **Storage Overhead** | ~160 KB. |

### `student_performance_tbl`: `idx_sp_test_question`

| Property | Specification |
| :--- | :--- |
| **Table Name** | `student_performance_tbl` |
| **Proposed Index Name** | `idx_sp_test_question` |
| **Column Sequence** | `test_id`, `question_id` |
| **Index Type** | NON-UNIQUE B-TREE |
| **Priority** | **CRITICAL** |
| **Supported Query / Workflow** | Student test performance retrieval and question grading evaluation. |
| **Source Code References** | `app/Http/Controllers/ExamTestController.php:103` |
| **Why Existing Indexes Are Insufficient** | Table has 1,646 rows and ONLY a PRIMARY KEY. WHERE test_id = ? performs a full table scan. |
| **Expected Performance Improvement** | Replaces O(N) full scan with O(log N) composite seek. Essential as table grows to 500,000+ rows. |
| **Write Overhead** | Moderate. Added on test submission; reads dominate review sessions. |
| **Storage Overhead** | ~64 KB. |

### `user_subscriptions`: `idx_us_user_status_prog_exp`

| Property | Specification |
| :--- | :--- |
| **Table Name** | `user_subscriptions` |
| **Proposed Index Name** | `idx_us_user_status_prog_exp` |
| **Column Sequence** | `user_id`, `status`, `offered_program_id`, `expires_at` |
| **Index Type** | NON-UNIQUE B-TREE |
| **Priority** | **HIGH** |
| **Supported Query / Workflow** | Active student subscription entitlement verification across LMS and Freemium tiers. |
| **Source Code References** | `app/Http/Services/StudentClassAccessService.php:86, 110; app/Http/Services/StudentSubscriptionPaymentService.php:237` |
| **Why Existing Indexes Are Insufficient** | Only single-column FK index user_subscriptions_user_id_foreign exists. Multi-column filter forces post-retrieval row inspection. |
| **Expected Performance Improvement** | Enables index-only covering lookup for student access checks without reading table rows. |
| **Write Overhead** | Minimal. Subscriptions are created/updated only on purchase, renewal, or approval. |
| **Storage Overhead** | ~32 KB. |

### `test_attempts`: `idx_ta_user_test_status_id`

| Property | Specification |
| :--- | :--- |
| **Table Name** | `test_attempts` |
| **Proposed Index Name** | `idx_ta_user_test_status_id` |
| **Column Sequence** | `user_id`, `test_id`, `status`, `id` |
| **Index Type** | NON-UNIQUE B-TREE |
| **Priority** | **HIGH** |
| **Supported Query / Workflow** | Retrieving active in-progress test attempt during progress autosave and test submission. |
| **Source Code References** | `app/Http/Controllers/Tests/TestController.php:725, 1092` |
| **Why Existing Indexes Are Insufficient** | Existing indexes cover (user_id, status) or (user_id, test_id). MySQL must do Backward index scan and filter test_id rows. |
| **Expected Performance Improvement** | Provides exact 3-column composite seek and satisfies ORDER BY id DESC directly from index order. |
| **Write Overhead** | Low. Only inserted at attempt start and updated at submit. |
| **Storage Overhead** | ~48 KB. |

### `admin_activity_logs`: `idx_aal_module_action_created`

| Property | Specification |
| :--- | :--- |
| **Table Name** | `admin_activity_logs` |
| **Proposed Index Name** | `idx_aal_module_action_created` |
| **Column Sequence** | `module`, `action`, `created_at` |
| **Index Type** | NON-UNIQUE B-TREE |
| **Priority** | **HIGH** |
| **Supported Query / Workflow** | Admin activity audit log filtering by module, action, and chronologically paginated. |
| **Source Code References** | `app/Http/Controllers/AdminActivityLogController.php` |
| **Why Existing Indexes Are Insufficient** | Existing index admin_activity_logs_module_action_index(module, action) forces Using filesort on 14,179 rows. |
| **Expected Performance Improvement** | Completely eliminates filesort. Result set is streamed directly from index in reverse created_at order. |
| **Write Overhead** | Low to moderate on admin actions. Significantly improves admin log dashboard responsiveness. |
| **Storage Overhead** | ~550 KB. |

### `user_selected_subject_tbl`: `idx_uss_user_subject`

| Property | Specification |
| :--- | :--- |
| **Table Name** | `user_selected_subject_tbl` |
| **Proposed Index Name** | `idx_uss_user_subject` |
| **Column Sequence** | `user_id`, `subject_id` |
| **Index Type** | NON-UNIQUE B-TREE |
| **Priority** | **MEDIUM** |
| **Supported Query / Workflow** | Fetching student chosen subjects for curriculum navigation and dashboard loading. |
| **Source Code References** | `app/Http/Controllers/QuestionsController.php:2613; app/Http/Controllers/ExamTestController.php` |
| **Why Existing Indexes Are Insufficient** | Table has 388 rows and ONLY PRIMARY KEY. Every student portal load scans entire table. |
| **Expected Performance Improvement** | Enables instant index seek per authenticated student. |
| **Write Overhead** | Negligible. |
| **Storage Overhead** | ~24 KB. |

### `past_paper_tbl`: `idx_pp_scope_lookup`

| Property | Specification |
| :--- | :--- |
| **Table Name** | `past_paper_tbl` |
| **Proposed Index Name** | `idx_pp_scope_lookup` |
| **Column Sequence** | `activate`, `class_id`, `subject_id`, `board_id`, `year` |
| **Index Type** | NON-UNIQUE B-TREE |
| **Priority** | **MEDIUM** |
| **Supported Query / Workflow** | Filtering catalog past papers when curriculum_board_id is null or unassigned. |
| **Source Code References** | `app/Http/Controllers/LegacyPastPaperController.php:54-66` |
| **Why Existing Indexes Are Insufficient** | Existing composite index pp_tbl_curriculum_scope_idx requires curriculum_board_id as column 2. When null, index cannot be used for class, subject, board, year. |
| **Expected Performance Improvement** | Allows direct composite filtering for non-curriculum past papers across 5,125 rows. |
| **Write Overhead** | Low (read-heavy table). |
| **Storage Overhead** | ~180 KB. |

### `teacher_class_tests`: `idx_tct_board_class_subject`

| Property | Specification |
| :--- | :--- |
| **Table Name** | `teacher_class_tests` |
| **Proposed Index Name** | `idx_tct_board_class_subject` |
| **Column Sequence** | `curriculum_board_id`, `class_id`, `subject_id` |
| **Index Type** | NON-UNIQUE B-TREE |
| **Priority** | **MEDIUM** |
| **Supported Query / Workflow** | Teacher test library filtering by academic curriculum board, class, and subject. |
| **Source Code References** | `app/Http/Controllers/TeacherPortal/TeacherClassTestController.php` |
| **Why Existing Indexes Are Insufficient** | Existing indexes only cover (teacher_class_id, status) and (teacher_profile_id, status). Searching tests across classes scans all rows. |
| **Expected Performance Improvement** | Enables instant multi-class academic scope filtering. |
| **Write Overhead** | Low. |
| **Storage Overhead** | ~32 KB. |

### `student_test_tbl`: `idx_st_user_id`

| Property | Specification |
| :--- | :--- |
| **Table Name** | `student_test_tbl` |
| **Proposed Index Name** | `idx_st_user_id` |
| **Column Sequence** | `user_id` |
| **Index Type** | NON-UNIQUE B-TREE |
| **Priority** | **MEDIUM** |
| **Supported Query / Workflow** | Legacy student test history and attempt review. |
| **Source Code References** | `app/Http/Controllers/ExamTestController.php:103` |
| **Why Existing Indexes Are Insufficient** | Table has ONLY PRIMARY KEY. user_id lookups cause full table scan. |
| **Expected Performance Improvement** | Eliminates full table scan for student test records. |
| **Write Overhead** | Low. |
| **Storage Overhead** | ~16 KB. |

### `cache`: `idx_cache_expiration`

| Property | Specification |
| :--- | :--- |
| **Table Name** | `cache` |
| **Proposed Index Name** | `idx_cache_expiration` |
| **Column Sequence** | `expiration` |
| **Index Type** | NON-UNIQUE B-TREE |
| **Priority** | **LOW** |
| **Supported Query / Workflow** | Laravel automated cache pruning: DELETE FROM cache WHERE expiration <= ? |
| **Source Code References** | `Illuminate/Cache/DatabaseStore.php` |
| **Why Existing Indexes Are Insufficient** | Table has 3,060 rows and ONLY key (PK). Pruning expired cache scans all rows. |
| **Expected Performance Improvement** | Index range delete, eliminating table locks during cache cleanup. |
| **Write Overhead** | Low (cache writes already write to disk). |
| **Storage Overhead** | ~48 KB. |

---

## Part 2: Redundant & Duplicate Indexes for Safe Removal

MySQL InnoDB automatically utilizes the leftmost prefix of composite indexes for lookups. Maintaining separate single-column or shorter composite indexes on the exact same leading columns wastes InnoDB buffer pool memory, bloats backup sizes, and imposes CPU/disk I/O overhead on every `INSERT`, `UPDATE`, and `DELETE`.

| Table | Redundant Index | Columns | Overlapping Composite Index | Redundancy Type | Safe to Drop? |
| :--- | :--- | :--- | :--- | :---: | :---: |
| `freemium_student_grants` | `idx_fsg_campaign_user` | `(campaign_id, user_id)` | `uk_campaign_user UNIQUE (campaign_id, user_id)` | EXACT DUPLICATE | **YES** |
| `exam_question_tbl` | `idx_questions_book_unit` | `(book_id, unit_id)` | `idx_questions_hierarchy (book_id, unit_id, topic_id)` | LEFTMOST PREFIX REDUNDANCY | **YES** |
| `admin_activity_logs` | `admin_activity_logs_module_index` | `(module)` | `admin_activity_logs_module_action_index (module, action)` | LEFTMOST PREFIX REDUNDANCY | **YES** |
| `unit_slo_topic_tbl` | `unit_slo_topic_tbl_unit_id_index` | `(unit_id)` | `uq_unit_slo_topic UNIQUE (unit_id, slo_topic_id)` | LEFTMOST PREFIX REDUNDANCY | **YES** |
| `topic_slo_topic_tbl` | `topic_slo_topic_tbl_topic_id_index` | `(topic_id)` | `uq_topic_slo_topic UNIQUE (topic_id, slo_topic_id)` | LEFTMOST PREFIX REDUNDANCY | **YES** |
| `student_subject_progress_summaries` | `ssps_user_program_idx` | `(user_id, offered_program_id)` | `ssps_user_program_subj_idx UNIQUE (user_id, offered_program_id, subject_id)` | LEFTMOST PREFIX REDUNDANCY | **YES** |
| `student_unit_progress_summaries` | `sups_user_program_idx` | `(user_id, offered_program_id)` | `sups_user_program_unit_idx UNIQUE (user_id, offered_program_id, unit_id)` | LEFTMOST PREFIX REDUNDANCY | **YES** |
| `question_reports` | `question_reports_question_id_index` | `(question_id)` | `question_reports_question_id_status_index (question_id, status)` | LEFTMOST PREFIX REDUNDANCY | **YES** |
| `question_reports` | `question_reports_reporter_web_user_id_index` | `(reporter_web_user_id)` | `question_reports_reporter_web_user_id_status_index (reporter_web_user_id, status)` | LEFTMOST PREFIX REDUNDANCY | **YES** |
| `question_reports` | `question_reports_status_index` | `(status)` | `question_reports_status_created_at_index (status, created_at)` | LEFTMOST PREFIX REDUNDANCY | **YES** |

### `freemium_student_grants.idx_fsg_campaign_user`

- **Columns:** `(campaign_id, user_id)`
- **Overlapped By:** `uk_campaign_user UNIQUE (campaign_id, user_id)`
- **Technical Justification:** uk_campaign_user is a UNIQUE index on (campaign_id, user_id). idx_fsg_campaign_user is a non-unique index on the exact same columns. The unique index serves 100% of read queries with identical performance. Dropping idx_fsg_campaign_user is safe and eliminates duplicate write penalty.

### `exam_question_tbl.idx_questions_book_unit`

- **Columns:** `(book_id, unit_id)`
- **Overlapped By:** `idx_questions_hierarchy (book_id, unit_id, topic_id)`
- **Technical Justification:** idx_questions_hierarchy covers (book_id, unit_id, topic_id). Any query filtering on WHERE book_id = ? AND unit_id = ? uses the leftmost prefix of idx_questions_hierarchy with identical cardinality and performance. Dropping idx_questions_book_unit saves ~500 KB on the largest application table (23,904 rows).

### `admin_activity_logs.admin_activity_logs_module_index`

- **Columns:** `(module)`
- **Overlapped By:** `admin_activity_logs_module_action_index (module, action)`
- **Technical Justification:** admin_activity_logs_module_action_index covers (module, action). Any query filtering WHERE module = ? utilizes column 1 of the composite index. Dropping the single-column index saves index leaf storage on 14,179 rows.

### `unit_slo_topic_tbl.unit_slo_topic_tbl_unit_id_index`

- **Columns:** `(unit_id)`
- **Overlapped By:** `uq_unit_slo_topic UNIQUE (unit_id, slo_topic_id)`
- **Technical Justification:** uq_unit_slo_topic is unique on (unit_id, slo_topic_id). Single-column unit_id lookups are covered by column 1. Safe to drop.

### `topic_slo_topic_tbl.topic_slo_topic_tbl_topic_id_index`

- **Columns:** `(topic_id)`
- **Overlapped By:** `uq_topic_slo_topic UNIQUE (topic_id, slo_topic_id)`
- **Technical Justification:** uq_topic_slo_topic is unique on (topic_id, slo_topic_id). Single-column topic_id lookups are covered by column 1. Safe to drop.

### `student_subject_progress_summaries.ssps_user_program_idx`

- **Columns:** `(user_id, offered_program_id)`
- **Overlapped By:** `ssps_user_program_subj_idx UNIQUE (user_id, offered_program_id, subject_id)`
- **Technical Justification:** ssps_user_program_subj_idx covers (user_id, offered_program_id, subject_id). Query filtering on (user_id, offered_program_id) uses the first two columns. Safe to drop.

### `student_unit_progress_summaries.sups_user_program_idx`

- **Columns:** `(user_id, offered_program_id)`
- **Overlapped By:** `sups_user_program_unit_idx UNIQUE (user_id, offered_program_id, unit_id)`
- **Technical Justification:** sups_user_program_unit_idx covers (user_id, offered_program_id, unit_id). Query filtering on (user_id, offered_program_id) uses the first two columns. Safe to drop.

### `question_reports.question_reports_question_id_index`

- **Columns:** `(question_id)`
- **Overlapped By:** `question_reports_question_id_status_index (question_id, status)`
- **Technical Justification:** question_reports_question_id_status_index has question_id as leading column. Single-column index is redundant. Safe to drop.

### `question_reports.question_reports_reporter_web_user_id_index`

- **Columns:** `(reporter_web_user_id)`
- **Overlapped By:** `question_reports_reporter_web_user_id_status_index (reporter_web_user_id, status)`
- **Technical Justification:** question_reports_reporter_web_user_id_status_index has reporter_web_user_id as leading column. Single-column index is redundant. Safe to drop.

### `question_reports.question_reports_status_index`

- **Columns:** `(status)`
- **Overlapped By:** `question_reports_status_created_at_index (status, created_at)`
- **Technical Justification:** question_reports_status_created_at_index has status as leading column. Single-column status index has very low cardinality and is fully covered. Safe to drop.

