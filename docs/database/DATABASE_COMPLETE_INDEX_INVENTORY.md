# Complete Database Index Inventory — The Students Times LMS

**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Databases Audited:** `3peas_db` (primary local development) and `3peas_db_subscription_test` (local automated testing)  
**Database Engine:** MySQL 8.0.43 (InnoDB)  
**Audit Timestamp:** 2026-11-19  
**Coverage:** 100% (194 tables/views: 181 Base Tables, 13 Views)  

---

## Executive Summary & System Overview

An exhaustive, read-only inspection of every table and view in both authorized databases was conducted directly against MySQL `information_schema` and `SHOW INDEX`. Both `3peas_db` and `3peas_db_subscription_test` exhibit identical schemas, identical index sets, and identical foreign key definitions across all 194 entities.

### Schema Health Statistics

| Metric | Count | Details |
| :--- | :---: | :--- |
| **Total Database Entities** | **194** | 181 InnoDB Base Tables, 13 SQL Views |
| **Total Defined Indexes** | **518** | Including Primary Keys, Unique Constraints, and Secondary B-Tree Indexes |
| **Total Foreign Key Constraints** | **178** | Enforced at MySQL InnoDB level |
| **Foreign Keys Lacking Index** | **0** | All 178 formal FK constraints have an index with the FK column as leading column |
| **Legacy Unconstrained FK Columns** | **31** | Unindexed relational ID columns in legacy tables lacking formal foreign key constraints |
| **Identical Duplicate Indexes** | **1** | `freemium_student_grants` (`uk_campaign_user` vs `idx_fsg_campaign_user`) |
| **Redundant Prefix-Overlapping Indexes** | **10** | Redundant single/shorter column indexes that are strict prefixes of larger composite indexes |
| **Tables with ONLY Primary Key** | **36** | Tables lacking secondary indexes for queries and foreign lookups |

---

## Domain Table Directory

| Domain | Entity Count | Primary Responsibilities |
| :--- | :---: | :--- |
| **Student & User Management** | 7 | Student accounts, web users, user profiles, selected subjects, activities, difficulty tracking, and reset tokens. |
| **Teacher Profiles & Teacher Portal** | 40 | Teacher portal classes, assignments, attendance, gradebooks, discussions, calendar, settlements, tests, and subscriptions. |
| **Subscriptions & Entitlements** | 8 | Student class subscriptions, offered programs, program subjects, study plans, and payment verification. |
| **Payments, Transactions & Financial** | 5 | Accounts registers, bank accounts, payment slips, bonuses, and payment gateway account configurations. |
| **Curriculum & Academic Structure** | 18 | Academic hierarchy: boards, curriculum boards, classes, subjects, books, units, chapters, topics, and SLOs. |
| **Questions & Educational Content** | 16 | Question bank, answer options, explanations, question scenarios, statistics, reporting, and AI generation metadata. |
| **Past Papers & Model Papers** | 5 | Catalog past papers, structured past papers, past paper pages, and board exam question mappings. |
| **Assessments, Tests & Practice Sessions** | 13 | LMS tests, test scopes, test questions, test attempts, attempt answers, practice sessions, and mastery summaries. |
| **Freemium Subsystem & Idempotency** | 9 | Freemium campaigns, access rules, quotas, grants, counters, ledger consumptions, audit logs, and assessment idempotency. |
| **Authentication, RBAC & Activity Logs** | 11 | Roles, permissions, permission scopes, personal access tokens, login logs, activity audit trails, and background queue jobs. |
| **CMS, Communications, Website & System** | 49 | News, blogs, workshops, feedback, administration user profiles, cache, migrations, and layout sections. |
| **Database Views** | 13 | Database SQL views joining classes, subjects, boards, years, books, units, questions, and reports. |

---

## Student & User Management (7 Entities)

> Student accounts, web users, user profiles, selected subjects, activities, difficulty tracking, and reset tokens.

### Table: `student_activities`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 534  
- **Data Size:** 240.0 KB  
- **Index Size:** 160.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 462 |
| `student_activities_offered_program_id_foreign` | BTREE | NO | `offered_program_id` | 3 |
| `student_activities_subject_id_foreign` | BTREE | NO | `subject_id` | 8 |
| `student_activities_unit_id_foreign` | BTREE | NO | `unit_id` | 39 |
| `stu_act_user_time_idx` | BTREE | NO | `user_id`, `activity_at` | 5, 123 |
| `stu_act_user_type_idx` | BTREE | NO | `user_id`, `activity_type` | 5, 8 |
| `stu_act_ref_idx` | BTREE | NO | `reference_type`, `reference_id` | 2, 271 |
| `stu_act_user_program` | BTREE | NO | `user_id`, `offered_program_id`, `activity_at` | 5, 8, 123 |
| `stu_act_time_idx` | BTREE | NO | `activity_at` | 123 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `student_activities_offered_program_id_foreign` | `offered_program_id` | `offered_programs.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`student_activities_offered_program_id_foreign (offered_program_id)`) |
| `student_activities_subject_id_foreign` | `subject_id` | `subject_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`student_activities_subject_id_foreign (subject_id)`) |
| `student_activities_unit_id_foreign` | `unit_id` | `book_unit_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`student_activities_unit_id_foreign (unit_id)`) |
| `student_activities_user_id_foreign` | `user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`stu_act_user_time_idx (user_id, activity_at)`, `stu_act_user_type_idx (user_id, activity_type)`, `stu_act_user_program (user_id, offered_program_id, activity_at)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `student_question_difficulty_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `user_profile_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 6  
- **Data Size:** 16.0 KB  
- **Index Size:** 64.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 6 |
| `user_profile_tbl_user_id_foreign` | BTREE | NO | `user_id` | 6 |
| `user_profile_tbl_city_id_foreign` | BTREE | NO | `city_id` | 1 |
| `user_profile_tbl_institute_id_foreign` | BTREE | NO | `institute_id` | 1 |
| `user_profile_tbl_heard_about_id_foreign` | BTREE | NO | `heard_about_id` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `user_profile_tbl_city_id_foreign` | `city_id` | `city_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`user_profile_tbl_city_id_foreign (city_id)`) |
| `user_profile_tbl_heard_about_id_foreign` | `heard_about_id` | `heard_about_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`user_profile_tbl_heard_about_id_foreign (heard_about_id)`) |
| `user_profile_tbl_institute_id_foreign` | `institute_id` | `institute_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`user_profile_tbl_institute_id_foreign (institute_id)`) |
| `user_profile_tbl_user_id_foreign` | `user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`user_profile_tbl_user_id_foreign (user_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `user_selected_subject_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 388  
- **Data Size:** 48.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 388 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `users`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 541  
- **Data Size:** 128.0 KB  
- **Index Size:** 64.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 489 |
| `users_email_unique` | BTREE | **YES** | `email` | 489 |
| `users_role_id_foreign` | BTREE | NO | `role_id` | 454 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `users_role_id_foreign` | `role_id` | `roles.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`users_role_id_foreign (role_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `web_users`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 341  
- **Data Size:** 96.0 KB  
- **Index Size:** 80.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 341 |
| `web_users_email_unique` | BTREE | **YES** | `email` | 341 |
| `web_users_phone_unique` | BTREE | **YES** | `phone` | 337 |
| `web_users_google_id_unique` | BTREE | **YES** | `google_id` | 5 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `web_users_password_reset_tokens`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 6  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(email)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `email` | 6 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

## Teacher Profiles & Teacher Portal (40 Entities)

> Teacher portal classes, assignments, attendance, gradebooks, discussions, calendar, settlements, tests, and subscriptions.

### Table: `teacher_class_announcement_reads`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 32.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `uk_tcar_announcement_student` | BTREE | **YES** | `announcement_id`, `student_web_user_id` | 0, 0 |
| `idx_tcar_student_read` | BTREE | NO | `student_web_user_id`, `read_at` | 0, 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tcar_announcement_fk` | `announcement_id` | `teacher_class_announcements.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`uk_tcar_announcement_student (announcement_id, student_web_user_id)`) |
| `tcar_student_fk` | `student_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tcar_student_read (student_web_user_id, read_at)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_announcements`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 64.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `idx_tca_class_status_pub` | BTREE | NO | `teacher_class_id`, `status`, `is_pinned`, `published_at` | 0, 0, 0, 0 |
| `idx_tca_scheduled` | BTREE | NO | `status`, `scheduled_at` | 0, 0 |
| `tca_profile_fk` | BTREE | NO | `teacher_profile_id` | 0 |
| `tca_creator_fk` | BTREE | NO | `created_by_web_user_id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tca_class_fk` | `teacher_class_id` | `teacher_classes.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tca_class_status_pub (teacher_class_id, status, is_pinned, published_at)`) |
| `tca_creator_fk` | `created_by_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tca_creator_fk (created_by_web_user_id)`) |
| `tca_profile_fk` | `teacher_profile_id` | `teacher_profiles.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tca_profile_fk (teacher_profile_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_assignment_attachments`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 32.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `idx_tcaa_assign` | BTREE | NO | `assignment_id` | 0 |
| `tcaa_uploader_fk` | BTREE | NO | `uploaded_by_web_user_id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tcaa_assign_fk` | `assignment_id` | `teacher_class_assignments.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tcaa_assign (assignment_id)`) |
| `tcaa_uploader_fk` | `uploaded_by_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcaa_uploader_fk (uploaded_by_web_user_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_assignment_evaluation_histories`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 80.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `idx_tcaeh_eval_created` | BTREE | NO | `evaluation_id`, `created_at` | 0, 0 |
| `idx_tcaeh_assign_student` | BTREE | NO | `assignment_id`, `student_web_user_id` | 0, 0 |
| `tcaeh_class_fk` | BTREE | NO | `teacher_class_id` | 0 |
| `tcaeh_student_fk` | BTREE | NO | `student_web_user_id` | 0 |
| `tcaeh_actor_fk` | BTREE | NO | `actor_web_user_id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tcaeh_actor_fk` | `actor_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcaeh_actor_fk (actor_web_user_id)`) |
| `tcaeh_assign_fk` | `assignment_id` | `teacher_class_assignments.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tcaeh_assign_student (assignment_id, student_web_user_id)`) |
| `tcaeh_class_fk` | `teacher_class_id` | `teacher_classes.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcaeh_class_fk (teacher_class_id)`) |
| `tcaeh_eval_fk` | `evaluation_id` | `teacher_class_assignment_evaluations.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tcaeh_eval_created (evaluation_id, created_at)`) |
| `tcaeh_student_fk` | `student_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcaeh_student_fk (student_web_user_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_assignment_evaluations`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 80.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `uk_tcae_assign_student` | BTREE | **YES** | `assignment_id`, `student_web_user_id` | 0, 0 |
| `idx_tcae_class_status` | BTREE | NO | `teacher_class_id`, `status` | 0, 0 |
| `idx_tcae_student_status` | BTREE | NO | `student_web_user_id`, `status` | 0, 0 |
| `tcae_sub_fk` | BTREE | NO | `submission_id` | 0 |
| `tcae_evaluator_fk` | BTREE | NO | `evaluated_by_web_user_id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tcae_assign_fk` | `assignment_id` | `teacher_class_assignments.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`uk_tcae_assign_student (assignment_id, student_web_user_id)`) |
| `tcae_class_fk` | `teacher_class_id` | `teacher_classes.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tcae_class_status (teacher_class_id, status)`) |
| `tcae_evaluator_fk` | `evaluated_by_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcae_evaluator_fk (evaluated_by_web_user_id)`) |
| `tcae_student_fk` | `student_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tcae_student_status (student_web_user_id, status)`) |
| `tcae_sub_fk` | `submission_id` | `teacher_class_assignment_submissions.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcae_sub_fk (submission_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_assignment_recipients`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 1 |
| `uk_tcar_assign_student` | BTREE | **YES** | `assignment_id`, `student_web_user_id` | 1, 1 |
| `idx_tcar_student_assign` | BTREE | NO | `student_web_user_id`, `assignment_id` | 1, 1 |
| `tcar7f_assigned_by_fk` | BTREE | NO | `assigned_by_web_user_id` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tcar7f_assign_fk` | `assignment_id` | `teacher_class_assignments.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`uk_tcar_assign_student (assignment_id, student_web_user_id)`) |
| `tcar7f_assigned_by_fk` | `assigned_by_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcar7f_assigned_by_fk (assigned_by_web_user_id)`) |
| `tcar7f_student_fk` | `student_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tcar_student_assign (student_web_user_id, assignment_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_assignment_submission_versions`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `uk_tcasv_sub_ver` | BTREE | **YES** | `submission_id`, `version_number` | 0, 0 |
| `idx_tcasv_assign_student` | BTREE | NO | `assignment_id`, `student_web_user_id` | 0, 0 |
| `tcasv_student_fk` | BTREE | NO | `student_web_user_id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tcasv_assign_fk` | `assignment_id` | `teacher_class_assignments.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tcasv_assign_student (assignment_id, student_web_user_id)`) |
| `tcasv_student_fk` | `student_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcasv_student_fk (student_web_user_id)`) |
| `tcasv_submission_fk` | `submission_id` | `teacher_class_assignment_submissions.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`uk_tcasv_sub_ver (submission_id, version_number)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_assignment_submissions`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 1  
- **Data Size:** 16.0 KB  
- **Index Size:** 64.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 1 |
| `uk_tcas_assign_student` | BTREE | **YES** | `assignment_id`, `student_web_user_id` | 1, 1 |
| `idx_tcas_assign_status` | BTREE | NO | `assignment_id`, `status`, `is_late` | 1, 1, 1 |
| `tcas_class_fk` | BTREE | NO | `teacher_class_id` | 1 |
| `tcas_student_fk` | BTREE | NO | `student_web_user_id` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tcas_assign_fk` | `assignment_id` | `teacher_class_assignments.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`uk_tcas_assign_student (assignment_id, student_web_user_id)`, `idx_tcas_assign_status (assignment_id, status, is_late)`) |
| `tcas_class_fk` | `teacher_class_id` | `teacher_classes.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcas_class_fk (teacher_class_id)`) |
| `tcas_student_fk` | `student_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcas_student_fk (student_web_user_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_assignments`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 1  
- **Data Size:** 16.0 KB  
- **Index Size:** 64.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 1 |
| `idx_tca7f_class_status_due` | BTREE | NO | `teacher_class_id`, `status`, `due_at` | 1, 1, 1 |
| `idx_tca7f_sched` | BTREE | NO | `status`, `scheduled_publish_at` | 1, 1 |
| `tca7f_profile_fk` | BTREE | NO | `teacher_profile_id` | 1 |
| `tca7f_creator_fk` | BTREE | NO | `created_by_web_user_id` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tca7f_class_fk` | `teacher_class_id` | `teacher_classes.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tca7f_class_status_due (teacher_class_id, status, due_at)`) |
| `tca7f_creator_fk` | `created_by_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tca7f_creator_fk (created_by_web_user_id)`) |
| `tca7f_profile_fk` | `teacher_profile_id` | `teacher_profiles.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tca7f_profile_fk (teacher_profile_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_attendance_audit_logs`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `idx_tcaal_session_created` | BTREE | NO | `session_id`, `created_at` | 0, 0 |
| `tcaal_record_fk` | BTREE | NO | `record_id` | 0 |
| `tcaal_actor_fk` | BTREE | NO | `acted_by_web_user_id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tcaal_actor_fk` | `acted_by_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcaal_actor_fk (acted_by_web_user_id)`) |
| `tcaal_record_fk` | `record_id` | `teacher_class_attendance_records.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcaal_record_fk (record_id)`) |
| `tcaal_session_fk` | `session_id` | `teacher_class_attendance_sessions.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tcaal_session_created (session_id, created_at)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_attendance_records`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 64.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `uk_tcar_session_student` | BTREE | **YES** | `session_id`, `student_web_user_id` | 0, 0 |
| `idx_tcatr_student_class` | BTREE | NO | `student_web_user_id`, `teacher_class_id` | 0, 0 |
| `tcatr_class_fk` | BTREE | NO | `teacher_class_id` | 0 |
| `tcatr_marker_fk` | BTREE | NO | `marked_by_web_user_id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tcatr_class_fk` | `teacher_class_id` | `teacher_classes.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcatr_class_fk (teacher_class_id)`) |
| `tcatr_marker_fk` | `marked_by_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcatr_marker_fk (marked_by_web_user_id)`) |
| `tcatr_session_fk` | `session_id` | `teacher_class_attendance_sessions.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`uk_tcar_session_student (session_id, student_web_user_id)`) |
| `tcatr_student_fk` | `student_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tcatr_student_class (student_web_user_id, teacher_class_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_attendance_sessions`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 96.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `uk_tcas_class_date_slot` | BTREE | **YES** | `teacher_class_id`, `session_date`, `slot_key` | 0, 0, 0 |
| `idx_tcas_class_date_status` | BTREE | NO | `teacher_class_id`, `session_date`, `status` | 0, 0, 0 |
| `tcats_profile_fk` | BTREE | NO | `teacher_profile_id` | 0 |
| `tcats_creator_fk` | BTREE | NO | `created_by_web_user_id` | 0 |
| `tcats_finalizer_fk` | BTREE | NO | `finalized_by_web_user_id` | 0 |
| `tcats_reopener_fk` | BTREE | NO | `reopened_by_web_user_id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tcats_class_fk` | `teacher_class_id` | `teacher_classes.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`uk_tcas_class_date_slot (teacher_class_id, session_date, slot_key)`, `idx_tcas_class_date_status (teacher_class_id, session_date, status)`) |
| `tcats_creator_fk` | `created_by_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcats_creator_fk (created_by_web_user_id)`) |
| `tcats_finalizer_fk` | `finalized_by_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcats_finalizer_fk (finalized_by_web_user_id)`) |
| `tcats_profile_fk` | `teacher_profile_id` | `teacher_profiles.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcats_profile_fk (teacher_profile_id)`) |
| `tcats_reopener_fk` | `reopened_by_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcats_reopener_fk (reopened_by_web_user_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_calendar_events`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 1  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 1 |
| `idx_tcce_class_status_start` | BTREE | NO | `teacher_class_id`, `status`, `starts_at` | 1, 1, 1 |
| `tcce_profile_fk` | BTREE | NO | `teacher_profile_id` | 1 |
| `tcce_creator_fk` | BTREE | NO | `created_by_web_user_id` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tcce_class_fk` | `teacher_class_id` | `teacher_classes.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tcce_class_status_start (teacher_class_id, status, starts_at)`) |
| `tcce_creator_fk` | `created_by_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcce_creator_fk (created_by_web_user_id)`) |
| `tcce_profile_fk` | `teacher_profile_id` | `teacher_profiles.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcce_profile_fk (teacher_profile_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_discussion_replies`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 1  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 1 |
| `idx_tcdr_disc_status_created` | BTREE | NO | `discussion_id`, `status`, `created_at` | 1, 1, 1 |
| `tcdr_author_fk` | BTREE | NO | `author_web_user_id` | 1 |
| `tcdr_moderated_by_fk` | BTREE | NO | `moderated_by_web_user_id` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tcdr_author_fk` | `author_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcdr_author_fk (author_web_user_id)`) |
| `tcdr_discussion_fk` | `discussion_id` | `teacher_class_discussions.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tcdr_disc_status_created (discussion_id, status, created_at)`) |
| `tcdr_moderated_by_fk` | `moderated_by_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcdr_moderated_by_fk (moderated_by_web_user_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_discussion_reports`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 80.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `idx_tcdrep_class_status` | BTREE | NO | `teacher_class_id`, `status` | 0, 0 |
| `idx_tcdrep_reporter_target` | BTREE | NO | `reporter_web_user_id`, `discussion_id`, `reply_id` | 0, 0, 0 |
| `tcdrep_discussion_fk` | BTREE | NO | `discussion_id` | 0 |
| `tcdrep_reply_fk` | BTREE | NO | `reply_id` | 0 |
| `tcdrep_reviewer_fk` | BTREE | NO | `reviewed_by_web_user_id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tcdrep_class_fk` | `teacher_class_id` | `teacher_classes.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tcdrep_class_status (teacher_class_id, status)`) |
| `tcdrep_discussion_fk` | `discussion_id` | `teacher_class_discussions.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcdrep_discussion_fk (discussion_id)`) |
| `tcdrep_reply_fk` | `reply_id` | `teacher_class_discussion_replies.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcdrep_reply_fk (reply_id)`) |
| `tcdrep_reporter_fk` | `reporter_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tcdrep_reporter_target (reporter_web_user_id, discussion_id, reply_id)`) |
| `tcdrep_reviewer_fk` | `reviewed_by_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcdrep_reviewer_fk (reviewed_by_web_user_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_discussions`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 1  
- **Data Size:** 16.0 KB  
- **Index Size:** 64.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 1 |
| `idx_tcd_class_status_pin` | BTREE | NO | `teacher_class_id`, `status`, `is_pinned`, `last_activity_at` | 1, 1, 1, 1 |
| `idx_tcd_author_class` | BTREE | NO | `author_web_user_id`, `teacher_class_id` | 1, 1 |
| `tcd_closed_by_fk` | BTREE | NO | `closed_by_web_user_id` | 1 |
| `tcd_moderated_by_fk` | BTREE | NO | `moderated_by_web_user_id` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tcd_author_fk` | `author_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tcd_author_class (author_web_user_id, teacher_class_id)`) |
| `tcd_class_fk` | `teacher_class_id` | `teacher_classes.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tcd_class_status_pin (teacher_class_id, status, is_pinned, last_activity_at)`) |
| `tcd_closed_by_fk` | `closed_by_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcd_closed_by_fk (closed_by_web_user_id)`) |
| `tcd_moderated_by_fk` | `moderated_by_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcd_moderated_by_fk (moderated_by_web_user_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_enrollment_events`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 2  
- **Data Size:** 16.0 KB  
- **Index Size:** 64.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 2 |
| `idx_tcee_enrollment_created` | BTREE | NO | `teacher_class_enrollment_id`, `created_at` | 1, 2 |
| `idx_tcee_class_created` | BTREE | NO | `teacher_class_id`, `created_at` | 1, 2 |
| `tcee_student_fk` | BTREE | NO | `student_web_user_id` | 1 |
| `tcee_actor_fk` | BTREE | NO | `actor_web_user_id` | 2 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tcee_actor_fk` | `actor_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcee_actor_fk (actor_web_user_id)`) |
| `tcee_class_fk` | `teacher_class_id` | `teacher_classes.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tcee_class_created (teacher_class_id, created_at)`) |
| `tcee_enrollment_fk` | `teacher_class_enrollment_id` | `teacher_class_enrollments.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tcee_enrollment_created (teacher_class_enrollment_id, created_at)`) |
| `tcee_student_fk` | `student_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcee_student_fk (student_web_user_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_enrollments`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 2  
- **Data Size:** 16.0 KB  
- **Index Size:** 80.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 2 |
| `uk_teacher_class_student` | BTREE | **YES** | `teacher_class_id`, `student_web_user_id` | 2, 2 |
| `idx_tce_class_status_created` | BTREE | NO | `teacher_class_id`, `status`, `created_at` | 2, 2, 2 |
| `idx_tce_student_status_created` | BTREE | NO | `student_web_user_id`, `status`, `created_at` | 2, 2, 2 |
| `tce_reviewed_by_fk` | BTREE | NO | `reviewed_by_web_user_id` | 2 |
| `tce_removed_by_fk` | BTREE | NO | `removed_by_web_user_id` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tce_class_fk` | `teacher_class_id` | `teacher_classes.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`uk_teacher_class_student (teacher_class_id, student_web_user_id)`, `idx_tce_class_status_created (teacher_class_id, status, created_at)`) |
| `tce_removed_by_fk` | `removed_by_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tce_removed_by_fk (removed_by_web_user_id)`) |
| `tce_reviewed_by_fk` | `reviewed_by_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tce_reviewed_by_fk (reviewed_by_web_user_id)`) |
| `tce_student_fk` | `student_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tce_student_status_created (student_web_user_id, status, created_at)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_gradebook_assessment_exclusions`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 1 |
| `uk_tcgae_class_src` | BTREE | **YES** | `teacher_class_id`, `assessment_type`, `source_id` | 1, 1, 1 |
| `idx_tcgae_class_excl` | BTREE | NO | `teacher_class_id`, `is_excluded` | 1, 1 |
| `tcgae_updater_fk` | BTREE | NO | `updated_by_web_user_id` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tcgae_class_fk` | `teacher_class_id` | `teacher_classes.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`uk_tcgae_class_src (teacher_class_id, assessment_type, source_id)`, `idx_tcgae_class_excl (teacher_class_id, is_excluded)`) |
| `tcgae_updater_fk` | `updated_by_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcgae_updater_fk (updated_by_web_user_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_gradebook_category_rules`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 32.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 1 |
| `uk_tcgcr_class_cat` | BTREE | **YES** | `teacher_class_id`, `category_key` | 1, 1 |
| `tcgcr_config_fk` | BTREE | NO | `gradebook_config_id` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tcgcr_class_fk` | `teacher_class_id` | `teacher_classes.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`uk_tcgcr_class_cat (teacher_class_id, category_key)`) |
| `tcgcr_config_fk` | `gradebook_config_id` | `teacher_class_gradebook_configs.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcgcr_config_fk (gradebook_config_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_gradebook_configs`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 1 |
| `uk_tcgc_class` | BTREE | **YES** | `teacher_class_id` | 1 |
| `tcgc_session_fk` | BTREE | NO | `study_session_id` | 1 |
| `tcgc_updater_fk` | BTREE | NO | `updated_by_web_user_id` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tcgc_class_fk` | `teacher_class_id` | `teacher_classes.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`uk_tcgc_class (teacher_class_id)`) |
| `tcgc_session_fk` | `study_session_id` | `study_session_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcgc_session_fk (study_session_id)`) |
| `tcgc_updater_fk` | `updated_by_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcgc_updater_fk (updated_by_web_user_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_submission_attachments`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 32.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `idx_tcsa_sub_active` | BTREE | NO | `submission_id`, `is_active`, `version_number` | 0, 0, 0 |
| `tcsa_uploader_fk` | BTREE | NO | `uploaded_by_web_user_id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tcsa_sub_fk` | `submission_id` | `teacher_class_assignment_submissions.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tcsa_sub_active (submission_id, is_active, version_number)`) |
| `tcsa_uploader_fk` | `uploaded_by_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcsa_uploader_fk (uploaded_by_web_user_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_test_assignments`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 64.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `idx_tcta_test_status` | BTREE | NO | `teacher_class_test_id`, `status` | 0, 0 |
| `idx_tcta_class_window` | BTREE | NO | `teacher_class_id`, `starts_at`, `ends_at` | 0, 0, 0 |
| `tcta_profile_fk` | BTREE | NO | `teacher_profile_id` | 0 |
| `tcta_assigned_by_fk` | BTREE | NO | `assigned_by_web_user_id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tcta_assigned_by_fk` | `assigned_by_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcta_assigned_by_fk (assigned_by_web_user_id)`) |
| `tcta_class_fk` | `teacher_class_id` | `teacher_classes.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tcta_class_window (teacher_class_id, starts_at, ends_at)`) |
| `tcta_profile_fk` | `teacher_profile_id` | `teacher_profiles.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tcta_profile_fk (teacher_profile_id)`) |
| `tcta_test_fk` | `teacher_class_test_id` | `teacher_class_tests.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tcta_test_status (teacher_class_test_id, status)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_test_attempt_answers`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `uk_tctaa_attempt_question` | BTREE | **YES** | `attempt_id`, `teacher_class_test_question_id` | 0, 0 |
| `idx_tctaa_attempt_order` | BTREE | NO | `attempt_id`, `question_order` | 0, 0 |
| `tctaa_test_question_fk` | BTREE | NO | `teacher_class_test_question_id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tctaa_attempt_fk` | `attempt_id` | `teacher_class_test_attempts.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`uk_tctaa_attempt_question (attempt_id, teacher_class_test_question_id)`, `idx_tctaa_attempt_order (attempt_id, question_order)`) |
| `tctaa_test_question_fk` | `teacher_class_test_question_id` | `teacher_class_test_questions.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tctaa_test_question_fk (teacher_class_test_question_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_test_attempts`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 96.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `uk_tctatt_recipient_attempt_no` | BTREE | **YES** | `teacher_class_test_recipient_id`, `attempt_number` | 0, 0 |
| `uk_tctatt_active_recipient` | BTREE | **YES** | `active_recipient_guard` | 0 |
| `idx_tctatt_assignment_status` | BTREE | NO | `teacher_class_test_assignment_id`, `status` | 0, 0 |
| `idx_tctatt_student_status` | BTREE | NO | `student_web_user_id`, `status` | 0, 0 |
| `tctatt_test_fk` | BTREE | NO | `teacher_class_test_id` | 0 |
| `tctatt_class_fk` | BTREE | NO | `teacher_class_id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tctatt_assignment_fk` | `teacher_class_test_assignment_id` | `teacher_class_test_assignments.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tctatt_assignment_status (teacher_class_test_assignment_id, status)`) |
| `tctatt_class_fk` | `teacher_class_id` | `teacher_classes.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tctatt_class_fk (teacher_class_id)`) |
| `tctatt_recipient_fk` | `teacher_class_test_recipient_id` | `teacher_class_test_recipients.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`uk_tctatt_recipient_attempt_no (teacher_class_test_recipient_id, attempt_number)`) |
| `tctatt_student_fk` | `student_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tctatt_student_status (student_web_user_id, status)`) |
| `tctatt_test_fk` | `teacher_class_test_id` | `teacher_class_tests.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tctatt_test_fk (teacher_class_test_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_test_questions`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `uk_tctq_test_question` | BTREE | **YES** | `teacher_class_test_id`, `question_id` | 0, 0 |
| `uk_tctq_test_order` | BTREE | **YES** | `teacher_class_test_id`, `question_order` | 0, 0 |
| `idx_tctq_question` | BTREE | NO | `question_id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tctq_bank_question_fk` | `question_id` | `exam_question_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tctq_question (question_id)`) |
| `tctq_test_fk` | `teacher_class_test_id` | `teacher_class_tests.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`uk_tctq_test_question (teacher_class_test_id, question_id)`, `uk_tctq_test_order (teacher_class_test_id, question_order)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_test_recipients`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 80.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 1 |
| `uk_tctr_assignment_student` | BTREE | **YES** | `teacher_class_test_assignment_id`, `student_web_user_id` | 1, 1 |
| `idx_tctr_student_status` | BTREE | NO | `student_web_user_id`, `assignment_status` | 1, 1 |
| `idx_tctr_test_student` | BTREE | NO | `teacher_class_test_id`, `student_web_user_id` | 1, 1 |
| `tctr_class_fk` | BTREE | NO | `teacher_class_id` | 1 |
| `tctr_enrollment_fk` | BTREE | NO | `teacher_class_enrollment_id` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tctr_assignment_fk` | `teacher_class_test_assignment_id` | `teacher_class_test_assignments.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`uk_tctr_assignment_student (teacher_class_test_assignment_id, student_web_user_id)`) |
| `tctr_class_fk` | `teacher_class_id` | `teacher_classes.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tctr_class_fk (teacher_class_id)`) |
| `tctr_enrollment_fk` | `teacher_class_enrollment_id` | `teacher_class_enrollments.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tctr_enrollment_fk (teacher_class_enrollment_id)`) |
| `tctr_student_fk` | `student_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tctr_student_status (student_web_user_id, assignment_status)`) |
| `tctr_test_fk` | `teacher_class_test_id` | `teacher_class_tests.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tctr_test_student (teacher_class_test_id, student_web_user_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_class_tests`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 32.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `idx_tct_class_status_created` | BTREE | NO | `teacher_class_id`, `status`, `created_at` | 0, 0, 0 |
| `idx_tct_teacher_status` | BTREE | NO | `teacher_profile_id`, `status` | 0, 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tct_class_fk` | `teacher_class_id` | `teacher_classes.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tct_class_status_created (teacher_class_id, status, created_at)`) |
| `tct_teacher_profile_fk` | `teacher_profile_id` | `teacher_profiles.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tct_teacher_status (teacher_profile_id, status)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_classes`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 3  
- **Data Size:** 16.0 KB  
- **Index Size:** 96.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 3 |
| `teacher_classes_unique_offering` | BTREE | **YES** | `teacher_profile_id`, `class_id`, `subject_id`, `curriculum_board_id`, `section` | 3, 3, 3, 3, 3 |
| `teacher_classes_class_code_unique` | BTREE | **YES** | `class_code` | 3 |
| `teacher_classes_class_id_foreign` | BTREE | NO | `class_id` | 1 |
| `teacher_classes_subject_id_foreign` | BTREE | NO | `subject_id` | 2 |
| `teacher_classes_curriculum_board_id_foreign` | BTREE | NO | `curriculum_board_id` | 2 |
| `teacher_classes_teacher_active_idx` | BTREE | NO | `teacher_profile_id`, `is_active` | 3, 3 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `teacher_classes_class_id_foreign` | `class_id` | `class_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_classes_class_id_foreign (class_id)`) |
| `teacher_classes_curriculum_board_id_foreign` | `curriculum_board_id` | `curriculum_board_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_classes_curriculum_board_id_foreign (curriculum_board_id)`) |
| `teacher_classes_subject_id_foreign` | `subject_id` | `subject_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_classes_subject_id_foreign (subject_id)`) |
| `teacher_classes_teacher_profile_id_foreign` | `teacher_profile_id` | `teacher_profiles.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_classes_unique_offering (teacher_profile_id, class_id, subject_id, curriculum_board_id, section)`, `teacher_classes_teacher_active_idx (teacher_profile_id, is_active)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_profile_events`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 2  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 2 |
| `teacher_profile_events_collection_code_unique` | BTREE | **YES** | `collection_code` | 2 |
| `teacher_profile_events_teacher_profile_id_foreign` | BTREE | NO | `teacher_profile_id` | 1 |
| `teacher_profile_events_admin_id_foreign` | BTREE | NO | `admin_id` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `teacher_profile_events_admin_id_foreign` | `admin_id` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_profile_events_admin_id_foreign (admin_id)`) |
| `teacher_profile_events_teacher_profile_id_foreign` | `teacher_profile_id` | `teacher_profiles.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_profile_events_teacher_profile_id_foreign (teacher_profile_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_profiles`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 3  
- **Data Size:** 16.0 KB  
- **Index Size:** 128.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 3 |
| `teacher_profiles_web_user_id_unique` | BTREE | **YES** | `web_user_id` | 3 |
| `teacher_profiles_teacher_code_unique` | BTREE | **YES** | `teacher_code` | 3 |
| `teacher_profiles_collection_code_unique` | BTREE | **YES** | `collection_code` | 2 |
| `teacher_profiles_institute_id_foreign` | BTREE | NO | `institute_id` | 1 |
| `teacher_profiles_city_id_foreign` | BTREE | NO | `city_id` | 1 |
| `teacher_profiles_approved_by_foreign` | BTREE | NO | `approved_by` | 2 |
| `teacher_profiles_status_changed_by_foreign` | BTREE | NO | `status_changed_by` | 2 |
| `teacher_profiles_status_index` | BTREE | NO | `status` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `teacher_profiles_approved_by_foreign` | `approved_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_profiles_approved_by_foreign (approved_by)`) |
| `teacher_profiles_city_id_foreign` | `city_id` | `city_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_profiles_city_id_foreign (city_id)`) |
| `teacher_profiles_institute_id_foreign` | `institute_id` | `institute_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_profiles_institute_id_foreign (institute_id)`) |
| `teacher_profiles_status_changed_by_foreign` | `status_changed_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_profiles_status_changed_by_foreign (status_changed_by)`) |
| `teacher_profiles_web_user_id_foreign` | `web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_profiles_web_user_id_unique (web_user_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_settlement_allocations`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 32.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `teacher_allocation_unique` | BTREE | **YES** | `teacher_settlement_id`, `payment_request_id` | 0, 0 |
| `teacher_settlement_allocations_payment_request_id_foreign` | BTREE | NO | `payment_request_id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `teacher_settlement_allocations_payment_request_id_foreign` | `payment_request_id` | `subscription_payment_requests.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_settlement_allocations_payment_request_id_foreign (payment_request_id)`) |
| `teacher_settlement_allocations_teacher_settlement_id_foreign` | `teacher_settlement_id` | `teacher_settlements.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_allocation_unique (teacher_settlement_id, payment_request_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_settlements`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 64.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `teacher_settlements_teacher_profile_id_submission_key_unique` | BTREE | **YES** | `teacher_profile_id`, `submission_key` | 0, 0 |
| `teacher_settlement_transfer_unique` | BTREE | **YES** | `payment_account_id`, `transaction_reference` | 0, 0 |
| `teacher_settlements_reviewed_by_foreign` | BTREE | NO | `reviewed_by` | 0 |
| `teacher_settlements_teacher_profile_id_status_index` | BTREE | NO | `teacher_profile_id`, `status` | 0, 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `teacher_settlements_payment_account_id_foreign` | `payment_account_id` | `payment_accounts.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_settlement_transfer_unique (payment_account_id, transaction_reference)`) |
| `teacher_settlements_reviewed_by_foreign` | `reviewed_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_settlements_reviewed_by_foreign (reviewed_by)`) |
| `teacher_settlements_teacher_profile_id_foreign` | `teacher_profile_id` | `teacher_profiles.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_settlements_teacher_profile_id_submission_key_unique (teacher_profile_id, submission_key)`, `teacher_settlements_teacher_profile_id_status_index (teacher_profile_id, status)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_student_conversations`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 1  
- **Data Size:** 16.0 KB  
- **Index Size:** 80.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 1 |
| `uk_tsc_teacher_student` | BTREE | **YES** | `teacher_profile_id`, `student_web_user_id` | 1, 1 |
| `idx_tsc_teacher_last` | BTREE | NO | `teacher_profile_id`, `last_message_at` | 1, 1 |
| `idx_tsc_student_last` | BTREE | NO | `student_web_user_id`, `last_message_at` | 1, 1 |
| `tsc_teacher_user_fk` | BTREE | NO | `teacher_web_user_id` | 1 |
| `tsc_class_fk` | BTREE | NO | `context_teacher_class_id` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tsc_class_fk` | `context_teacher_class_id` | `teacher_classes.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tsc_class_fk (context_teacher_class_id)`) |
| `tsc_profile_fk` | `teacher_profile_id` | `teacher_profiles.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`uk_tsc_teacher_student (teacher_profile_id, student_web_user_id)`, `idx_tsc_teacher_last (teacher_profile_id, last_message_at)`) |
| `tsc_student_user_fk` | `student_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tsc_student_last (student_web_user_id, last_message_at)`) |
| `tsc_teacher_user_fk` | `teacher_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tsc_teacher_user_fk (teacher_web_user_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_student_messages`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 2  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 2 |
| `idx_tsm_conv_id` | BTREE | NO | `conversation_id`, `id` | 1, 2 |
| `idx_tsm_conv_unread` | BTREE | NO | `conversation_id`, `sender_web_user_id`, `read_at` | 1, 2, 2 |
| `tsm_sender_fk` | BTREE | NO | `sender_web_user_id` | 2 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tsm_conversation_fk` | `conversation_id` | `teacher_student_conversations.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_tsm_conv_id (conversation_id, id)`, `idx_tsm_conv_unread (conversation_id, sender_web_user_id, read_at)`) |
| `tsm_sender_fk` | `sender_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tsm_sender_fk (sender_web_user_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_subscription_features`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 8  
- **Data Size:** 16.0 KB  
- **Index Size:** 32.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 8 |
| `teacher_subscription_feature_unique` | BTREE | **YES** | `teacher_subscription_plan_id`, `feature_key` | 1, 8 |
| `teacher_subscription_features_feature_key_is_enabled_index` | BTREE | NO | `feature_key`, `is_enabled` | 8, 8 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `teacher_sub_features_plan_fk` | `teacher_subscription_plan_id` | `teacher_subscription_plans.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_subscription_feature_unique (teacher_subscription_plan_id, feature_key)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_subscription_history`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 11  
- **Data Size:** 16.0 KB  
- **Index Size:** 80.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 11 |
| `teacher_subscription_history_previous_plan_id_foreign` | BTREE | NO | `previous_plan_id` | 1 |
| `teacher_subscription_history_new_plan_id_foreign` | BTREE | NO | `new_plan_id` | 1 |
| `teacher_subscription_history_assigned_by_foreign` | BTREE | NO | `assigned_by` | 1 |
| `teacher_subscription_history_teacher_idx` | BTREE | NO | `teacher_profile_id`, `created_at` | 11, 11 |
| `teacher_subscription_history_subscription_idx` | BTREE | NO | `teacher_subscription_id`, `created_at` | 11, 11 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `teacher_sub_history_profile_fk` | `teacher_profile_id` | `teacher_profiles.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_subscription_history_teacher_idx (teacher_profile_id, created_at)`) |
| `teacher_sub_history_sub_fk` | `teacher_subscription_id` | `teacher_subscriptions.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_subscription_history_subscription_idx (teacher_subscription_id, created_at)`) |
| `teacher_subscription_history_assigned_by_foreign` | `assigned_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_subscription_history_assigned_by_foreign (assigned_by)`) |
| `teacher_subscription_history_new_plan_id_foreign` | `new_plan_id` | `teacher_subscription_plans.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_subscription_history_new_plan_id_foreign (new_plan_id)`) |
| `teacher_subscription_history_previous_plan_id_foreign` | `previous_plan_id` | `teacher_subscription_plans.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_subscription_history_previous_plan_id_foreign (previous_plan_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_subscription_plan_history`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 32.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `teacher_sub_plan_history_plan_idx` | BTREE | NO | `teacher_subscription_plan_id`, `created_at` | 0, 0 |
| `teacher_sub_plan_history_admin_idx` | BTREE | NO | `admin_user_id`, `created_at` | 0, 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `teacher_sub_plan_history_admin_fk` | `admin_user_id` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_sub_plan_history_admin_idx (admin_user_id, created_at)`) |
| `teacher_sub_plan_history_plan_fk` | `teacher_subscription_plan_id` | `teacher_subscription_plans.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_sub_plan_history_plan_idx (teacher_subscription_plan_id, created_at)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_subscription_plans`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 1  
- **Data Size:** 16.0 KB  
- **Index Size:** 80.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 1 |
| `teacher_subscription_plans_code_unique` | BTREE | **YES** | `code` | 1 |
| `teacher_subscription_one_active_default` | BTREE | **YES** | `active_default_guard` | 1 |
| `teacher_subscription_plans_created_by_foreign` | BTREE | NO | `created_by` | 1 |
| `teacher_subscription_plans_updated_by_foreign` | BTREE | NO | `updated_by` | 1 |
| `teacher_subscription_plans_is_active_billing_interval_index` | BTREE | NO | `is_active`, `billing_interval` | 1, 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `teacher_subscription_plans_created_by_foreign` | `created_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_subscription_plans_created_by_foreign (created_by)`) |
| `teacher_subscription_plans_updated_by_foreign` | `updated_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_subscription_plans_updated_by_foreign (updated_by)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `teacher_subscriptions`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 1  
- **Data Size:** 16.0 KB  
- **Index Size:** 80.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 1 |
| `teacher_subscription_one_active_per_teacher` | BTREE | **YES** | `active_teacher_profile_id` | 1 |
| `teacher_subs_plan_fk` | BTREE | NO | `teacher_subscription_plan_id` | 1 |
| `teacher_subscriptions_assigned_by_foreign` | BTREE | NO | `assigned_by` | 1 |
| `teacher_subscription_teacher_status_expiry_idx` | BTREE | NO | `teacher_profile_id`, `status`, `expires_at` | 1, 1, 1 |
| `teacher_subscription_expiry_idx` | BTREE | NO | `status`, `expires_at` | 1, 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `teacher_subs_plan_fk` | `teacher_subscription_plan_id` | `teacher_subscription_plans.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_subs_plan_fk (teacher_subscription_plan_id)`) |
| `teacher_subs_profile_fk` | `teacher_profile_id` | `teacher_profiles.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_subscription_teacher_status_expiry_idx (teacher_profile_id, status, expires_at)`) |
| `teacher_subscriptions_assigned_by_foreign` | `assigned_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`teacher_subscriptions_assigned_by_foreign (assigned_by)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

## Subscriptions & Entitlements (8 Entities)

> Student class subscriptions, offered programs, program subjects, study plans, and payment verification.

### Table: `offered_programs`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 4  
- **Data Size:** 16.0 KB  
- **Index Size:** 16.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 4 |
| `offered_programs_offered_class_id_foreign` | BTREE | NO | `offered_class_id` | 2 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `offered_programs_offered_class_id_foreign` | `offered_class_id` | `offered_classes.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`offered_programs_offered_class_id_foreign (offered_class_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `program_subjects`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 28  
- **Data Size:** 16.0 KB  
- **Index Size:** 32.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 28 |
| `program_subjects_offered_program_id_foreign` | BTREE | NO | `offered_program_id` | 4 |
| `program_subjects_subject_id_foreign` | BTREE | NO | `subject_id` | 8 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `program_subjects_offered_program_id_foreign` | `offered_program_id` | `offered_programs.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`program_subjects_offered_program_id_foreign (offered_program_id)`) |
| `program_subjects_subject_id_foreign` | `subject_id` | `subject_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`program_subjects_subject_id_foreign (subject_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `study_plan_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 3  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 3 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `study_subjects_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 19  
- **Data Size:** 16.0 KB  
- **Index Size:** 16.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 19 |
| `unique_subject_class_curriculum` | BTREE | **YES** | `subject_id`, `class_id`, `curriculum_board_id` | 10, 19, 19 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `subscription_payment_request_events`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 1  
- **Data Size:** 16.0 KB  
- **Index Size:** 32.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 1 |
| `payment_request_events_request_created_idx` | BTREE | NO | `subscription_payment_request_id`, `created_at` | 1, 1 |
| `payment_request_events_reviewer_idx` | BTREE | NO | `reviewer_type`, `reviewer_id`, `created_at` | 1, 1, 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `spr_events_request_fk` | `subscription_payment_request_id` | `subscription_payment_requests.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`payment_request_events_request_created_idx (subscription_payment_request_id, created_at)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `subscription_payment_requests`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 1  
- **Data Size:** 16.0 KB  
- **Index Size:** 208.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 1 |
| `subscription_payment_requests_transaction_id_unique` | BTREE | **YES** | `transaction_id` | 1 |
| `subscription_payment_requests_receipt_number_unique` | BTREE | **YES** | `receipt_number` | 1 |
| `subscription_payment_requests_subscription_id_foreign` | BTREE | NO | `subscription_id` | 1 |
| `subscription_payment_requests_payment_account_id_foreign` | BTREE | NO | `payment_account_id` | 1 |
| `subscription_payment_requests_approved_by_foreign` | BTREE | NO | `approved_by` | 1 |
| `subscription_payment_requests_rejected_by_foreign` | BTREE | NO | `rejected_by` | 1 |
| `subscription_payment_requests_user_id_status_index` | BTREE | NO | `user_id`, `status` | 1, 1 |
| `subscription_payment_requests_offered_program_id_status_index` | BTREE | NO | `offered_program_id`, `status` | 1, 1 |
| `subscription_payment_requests_user_id_offered_program_id_index` | BTREE | NO | `user_id`, `offered_program_id` | 1, 1 |
| `subscription_payment_requests_created_at_index` | BTREE | NO | `created_at` | 1 |
| `subscription_payment_requests_confirmed_by_web_user_id_foreign` | BTREE | NO | `confirmed_by_web_user_id` | 1 |
| `subscription_payment_requests_rejected_by_web_user_id_foreign` | BTREE | NO | `rejected_by_web_user_id` | 1 |
| `subscription_payment_requests_teacher_profile_id_status_index` | BTREE | NO | `teacher_profile_id`, `status` | 1, 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `subscription_payment_requests_approved_by_foreign` | `approved_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`subscription_payment_requests_approved_by_foreign (approved_by)`) |
| `subscription_payment_requests_confirmed_by_web_user_id_foreign` | `confirmed_by_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`subscription_payment_requests_confirmed_by_web_user_id_foreign (confirmed_by_web_user_id)`) |
| `subscription_payment_requests_offered_program_id_foreign` | `offered_program_id` | `offered_programs.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`subscription_payment_requests_offered_program_id_status_index (offered_program_id, status)`) |
| `subscription_payment_requests_payment_account_id_foreign` | `payment_account_id` | `payment_accounts.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`subscription_payment_requests_payment_account_id_foreign (payment_account_id)`) |
| `subscription_payment_requests_rejected_by_foreign` | `rejected_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`subscription_payment_requests_rejected_by_foreign (rejected_by)`) |
| `subscription_payment_requests_rejected_by_web_user_id_foreign` | `rejected_by_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`subscription_payment_requests_rejected_by_web_user_id_foreign (rejected_by_web_user_id)`) |
| `subscription_payment_requests_subscription_id_foreign` | `subscription_id` | `user_subscriptions.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`subscription_payment_requests_subscription_id_foreign (subscription_id)`) |
| `subscription_payment_requests_teacher_profile_id_foreign` | `teacher_profile_id` | `teacher_profiles.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`subscription_payment_requests_teacher_profile_id_status_index (teacher_profile_id, status)`) |
| `subscription_payment_requests_user_id_foreign` | `user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`subscription_payment_requests_user_id_status_index (user_id, status)`, `subscription_payment_requests_user_id_offered_program_id_index (user_id, offered_program_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `user_study_plan_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 32.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `user_study_plan_tbl_user_id_foreign` | BTREE | NO | `user_id` | 0 |
| `user_study_plan_tbl_study_plan_id_foreign` | BTREE | NO | `study_plan_id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `user_study_plan_tbl_study_plan_id_foreign` | `study_plan_id` | `study_plan_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`user_study_plan_tbl_study_plan_id_foreign (study_plan_id)`) |
| `user_study_plan_tbl_user_id_foreign` | `user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`user_study_plan_tbl_user_id_foreign (user_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `user_subscriptions`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 8  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 8 |
| `user_subscriptions_user_id_foreign` | BTREE | NO | `user_id` | 5 |
| `user_subscriptions_offered_program_id_foreign` | BTREE | NO | `offered_program_id` | 3 |
| `user_subscriptions_approved_by_foreign` | BTREE | NO | `approved_by` | 3 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `user_subscriptions_approved_by_foreign` | `approved_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`user_subscriptions_approved_by_foreign (approved_by)`) |
| `user_subscriptions_offered_program_id_foreign` | `offered_program_id` | `offered_programs.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`user_subscriptions_offered_program_id_foreign (offered_program_id)`) |
| `user_subscriptions_user_id_foreign` | `user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`user_subscriptions_user_id_foreign (user_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

## Payments, Transactions & Financial (5 Entities)

> Accounts registers, bank accounts, payment slips, bonuses, and payment gateway account configurations.

### Table: `account_registers`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 1,201  
- **Data Size:** 160.0 KB  
- **Index Size:** 128.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 1201 |
| `account_registers_voucher_id_foreign` | BTREE | NO | `voucher_id` | 570 |
| `account_registers_account_id_foreign` | BTREE | NO | `account_id` | 56 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `account_registers_account_id_foreign` | `account_id` | `chart_of_accounts.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`account_registers_account_id_foreign (account_id)`) |
| `account_registers_voucher_id_foreign` | `voucher_id` | `vouchers.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`account_registers_voucher_id_foreign (voucher_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `bank_accounts_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 1  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `bonuses`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 3  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 3 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `payment_accounts`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 2  
- **Data Size:** 16.0 KB  
- **Index Size:** 16.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 2 |
| `payment_accounts_method_is_active_index` | BTREE | NO | `method`, `is_active` | 2, 2 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `user_payment_slip_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 7  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 7 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

## Curriculum & Academic Structure (18 Entities)

> Academic hierarchy: boards, curriculum boards, classes, subjects, books, units, chapters, topics, and SLOs.

### Table: `board_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 10  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 10 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `book_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 167  
- **Data Size:** 48.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 164 |
| `book_tbl_class_id_foreign` | BTREE | NO | `class_id` | 4 |
| `book_tbl_subject_id_foreign` | BTREE | NO | `subject_id` | 9 |
| `book_tbl_curriculum_board_id_foreign` | BTREE | NO | `curriculum_board_id` | 3 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `book_tbl_class_id_foreign` | `class_id` | `class_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`book_tbl_class_id_foreign (class_id)`) |
| `book_tbl_curriculum_board_id_foreign` | `curriculum_board_id` | `curriculum_board_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`book_tbl_curriculum_board_id_foreign (curriculum_board_id)`) |
| `book_tbl_subject_id_foreign` | `subject_id` | `subject_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`book_tbl_subject_id_foreign (subject_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `book_unit_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 452  
- **Data Size:** 64.0 KB  
- **Index Size:** 16.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 439 |
| `book_unit_tbl_book_id_foreign` | BTREE | NO | `book_id` | 154 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `book_unit_tbl_book_id_foreign` | `book_id` | `book_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`book_unit_tbl_book_id_foreign (book_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `book_unit_topic_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 1,766  
- **Data Size:** 160.0 KB  
- **Index Size:** 96.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 1609 |
| `book_unit_topic_tbl_unit_id_foreign` | BTREE | NO | `unit_id` | 301 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `book_unit_topic_tbl_unit_id_foreign` | `unit_id` | `book_unit_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`book_unit_topic_tbl_unit_id_foreign (unit_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `city_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 19  
- **Data Size:** 16.0 KB  
- **Index Size:** 16.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 19 |
| `cities_district_id_foreign_idx` | BTREE | NO | `district_id` | 15 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `cities_district_id_foreign` | `district_id` | `districts.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`cities_district_id_foreign_idx (district_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `class_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 5  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 4 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `cognitive_domain_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 4  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 4 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `curriculum_board_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 3  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 2 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `exam_session_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 2  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 2 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `institute_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 17  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 17 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `provinces`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 1  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `slo_topic_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 276  
- **Data Size:** 96.0 KB  
- **Index Size:** 128.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 276 |
| `slo_topic_tbl_class_id_foreign` | BTREE | NO | `class_id` | 1 |
| `slo_topic_tbl_created_by_foreign` | BTREE | NO | `created_by` | 1 |
| `slo_topic_tbl_updated_by_foreign` | BTREE | NO | `updated_by` | 1 |
| `idx_slo_topic_code` | BTREE | NO | `slo_code` | 276 |
| `idx_slo_topic_domain_class` | BTREE | NO | `slo_domain_id`, `class_id` | 10, 10 |
| `slo_topic_tbl_assessment_type_index` | BTREE | NO | `assessment_type` | 3 |
| `slo_topic_tbl_cognitive_level_index` | BTREE | NO | `cognitive_level` | 4 |
| `slo_topic_tbl_activate_index` | BTREE | NO | `activate` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `slo_topic_tbl_class_id_foreign` | `class_id` | `class_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`slo_topic_tbl_class_id_foreign (class_id)`) |
| `slo_topic_tbl_created_by_foreign` | `created_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`slo_topic_tbl_created_by_foreign (created_by)`) |
| `slo_topic_tbl_slo_domain_id_foreign` | `slo_domain_id` | `slo_domain_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_slo_topic_domain_class (slo_domain_id, class_id)`) |
| `slo_topic_tbl_updated_by_foreign` | `updated_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`slo_topic_tbl_updated_by_foreign (updated_by)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `study_session_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 3  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 3 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `subject_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 31  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 30 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `topic_content_structure_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 1,920  
- **Data Size:** 144.0 KB  
- **Index Size:** 160.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 1920 |
| `topic_content_structure_tbl_topic_id_foreign` | BTREE | NO | `topic_id` | 618 |
| `topic_content_structure_tbl_topic_content_type_id_foreign` | BTREE | NO | `topic_content_type_id` | 90 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `topic_content_structure_tbl_topic_content_type_id_foreign` | `topic_content_type_id` | `topic_content_type_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`topic_content_structure_tbl_topic_content_type_id_foreign (topic_content_type_id)`) |
| `topic_content_structure_tbl_topic_id_foreign` | `topic_id` | `book_unit_topic_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`topic_content_structure_tbl_topic_id_foreign (topic_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `topic_content_type_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 108  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 108 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `topic_slo_topic_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 33  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 33 |
| `uq_topic_slo_topic` | BTREE | **YES** | `topic_id`, `slo_topic_id` | 17, 33 |
| `topic_slo_topic_tbl_topic_id_index` | BTREE | NO | `topic_id` | 17 |
| `topic_slo_topic_tbl_slo_topic_id_index` | BTREE | NO | `slo_topic_id` | 30 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `topic_slo_topic_tbl_slo_topic_id_foreign` | `slo_topic_id` | `slo_topic_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`topic_slo_topic_tbl_slo_topic_id_index (slo_topic_id)`) |
| `topic_slo_topic_tbl_topic_id_foreign` | `topic_id` | `book_unit_topic_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`uq_topic_slo_topic (topic_id, slo_topic_id)`, `topic_slo_topic_tbl_topic_id_index (topic_id)`) |

#### Index Redundancy & Efficiency Assessment

- ⚠️ **PREFIX REDUNDANCY:** Shorter index `topic_slo_topic_tbl_topic_id_index` (`topic_id`) is an exact leftmost prefix of longer index `uq_topic_slo_topic` (`topic_id, slo_topic_id`).

### Table: `unit_slo_topic_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 272  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 272 |
| `uq_unit_slo_topic` | BTREE | **YES** | `unit_id`, `slo_topic_id` | 10, 272 |
| `unit_slo_topic_tbl_unit_id_index` | BTREE | NO | `unit_id` | 10 |
| `unit_slo_topic_tbl_slo_topic_id_index` | BTREE | NO | `slo_topic_id` | 182 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `unit_slo_topic_tbl_slo_topic_id_foreign` | `slo_topic_id` | `slo_topic_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`unit_slo_topic_tbl_slo_topic_id_index (slo_topic_id)`) |
| `unit_slo_topic_tbl_unit_id_foreign` | `unit_id` | `book_unit_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`uq_unit_slo_topic (unit_id, slo_topic_id)`, `unit_slo_topic_tbl_unit_id_index (unit_id)`) |

#### Index Redundancy & Efficiency Assessment

- ⚠️ **PREFIX REDUNDANCY:** Shorter index `unit_slo_topic_tbl_unit_id_index` (`unit_id`) is an exact leftmost prefix of longer index `uq_unit_slo_topic` (`unit_id, slo_topic_id`).

## Questions & Educational Content (16 Entities)

> Question bank, answer options, explanations, question scenarios, statistics, reporting, and AI generation metadata.

### Table: `ai_question_generations`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 10  
- **Data Size:** 64.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 10 |
| `ai_question_generations_ai_request_id_foreign` | BTREE | NO | `ai_request_id` | 10 |
| `ai_question_generations_scenario_group_id_foreign` | BTREE | NO | `scenario_group_id` | 1 |
| `ai_question_generations_user_id_created_at_index` | BTREE | NO | `user_id`, `created_at` | 1, 10 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `ai_question_generations_ai_request_id_foreign` | `ai_request_id` | `ai_requests.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`ai_question_generations_ai_request_id_foreign (ai_request_id)`) |
| `ai_question_generations_scenario_group_id_foreign` | `scenario_group_id` | `question_scenario_groups_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`ai_question_generations_scenario_group_id_foreign (scenario_group_id)`) |
| `ai_question_generations_user_id_foreign` | `user_id` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`ai_question_generations_user_id_created_at_index (user_id, created_at)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `exam_answer_rating_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 1  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `exam_answer_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 9,467  
- **Data Size:** 19,984.0 KB  
- **Index Size:** 352.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 9900 |
| `exam_answer_tbl_question_id_foreign` | BTREE | NO | `question_id` | 9367 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `exam_answer_tbl_question_id_foreign` | `question_id` | `exam_question_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`exam_answer_tbl_question_id_foreign (question_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `exam_question_options_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 59,969  
- **Data Size:** 7,696.0 KB  
- **Index Size:** 1,552.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 50548 |
| `exam_question_options_tbl_question_id_foreign` | BTREE | NO | `question_id` | 12885 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `exam_question_options_tbl_question_id_foreign` | `question_id` | `exam_question_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`exam_question_options_tbl_question_id_foreign (question_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `exam_question_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 23,904  
- **Data Size:** 8,720.0 KB  
- **Index Size:** 12,768.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 24753 |
| `exam_question_tbl_topic_id_foreign` | BTREE | NO | `topic_id` | 1166 |
| `exam_question_tbl_question_type_foreign` | BTREE | NO | `question_type` | 40 |
| `exam_question_tbl_created_by_foreign` | BTREE | NO | `created_by` | 7 |
| `exam_question_tbl_updated_by_foreign` | BTREE | NO | `updated_by` | 8 |
| `exam_question_tbl_reviewed_by_foreign` | BTREE | NO | `reviewed_by` | 1 |
| `exam_question_tbl_unit_id_foreign` | BTREE | NO | `unit_id` | 132 |
| `exam_question_tbl_difficulty_index` | BTREE | NO | `difficulty` | 4 |
| `exam_question_tbl_status_index` | BTREE | NO | `status` | 1 |
| `idx_questions_book_unit` | BTREE | NO | `book_id`, `unit_id` | 18, 132 |
| `idx_questions_hierarchy` | BTREE | NO | `book_id`, `unit_id`, `topic_id` | 18, 132, 1525 |
| `exam_question_tbl_question_presentation_type_id_foreign` | BTREE | NO | `question_presentation_type_id` | 3 |
| `idx_questions_scenario_order` | BTREE | NO | `scenario_group_id`, `scenario_question_order` | 564, 2411 |
| `exam_question_tbl_ai_question_generation_id_foreign` | BTREE | NO | `ai_question_generation_id` | 1 |
| `exam_question_tbl_source_index` | BTREE | NO | `source` | 2 |
| `exam_question_tbl_ai_model_id_foreign` | BTREE | NO | `ai_model_id` | 1 |
| `exam_question_tbl_ai_provider_id_ai_model_id_index` | BTREE | NO | `ai_provider_id`, `ai_model_id` | 1, 1 |
| `exam_question_tbl_slo_topic_id_index` | BTREE | NO | `slo_topic_id` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `exam_question_tbl_ai_model_id_foreign` | `ai_model_id` | `ai_models.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`exam_question_tbl_ai_model_id_foreign (ai_model_id)`) |
| `exam_question_tbl_ai_provider_id_foreign` | `ai_provider_id` | `ai_providers.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`exam_question_tbl_ai_provider_id_ai_model_id_index (ai_provider_id, ai_model_id)`) |
| `exam_question_tbl_ai_question_generation_id_foreign` | `ai_question_generation_id` | `ai_question_generations.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`exam_question_tbl_ai_question_generation_id_foreign (ai_question_generation_id)`) |
| `exam_question_tbl_book_id_foreign` | `book_id` | `book_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_questions_book_unit (book_id, unit_id)`, `idx_questions_hierarchy (book_id, unit_id, topic_id)`) |
| `exam_question_tbl_created_by_foreign` | `created_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`exam_question_tbl_created_by_foreign (created_by)`) |
| `exam_question_tbl_question_presentation_type_id_foreign` | `question_presentation_type_id` | `question_presentation_type_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`exam_question_tbl_question_presentation_type_id_foreign (question_presentation_type_id)`) |
| `exam_question_tbl_question_type_foreign` | `question_type` | `question_type_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`exam_question_tbl_question_type_foreign (question_type)`) |
| `exam_question_tbl_reviewed_by_foreign` | `reviewed_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`exam_question_tbl_reviewed_by_foreign (reviewed_by)`) |
| `exam_question_tbl_scenario_group_id_foreign` | `scenario_group_id` | `question_scenario_groups_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_questions_scenario_order (scenario_group_id, scenario_question_order)`) |
| `exam_question_tbl_slo_topic_id_foreign` | `slo_topic_id` | `slo_topic_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`exam_question_tbl_slo_topic_id_index (slo_topic_id)`) |
| `exam_question_tbl_topic_id_foreign` | `topic_id` | `book_unit_topic_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`exam_question_tbl_topic_id_foreign (topic_id)`) |
| `exam_question_tbl_unit_id_foreign` | `unit_id` | `book_unit_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`exam_question_tbl_unit_id_foreign (unit_id)`) |
| `exam_question_tbl_updated_by_foreign` | `updated_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`exam_question_tbl_updated_by_foreign (updated_by)`) |

#### Index Redundancy & Efficiency Assessment

- ⚠️ **PREFIX REDUNDANCY:** Shorter index `idx_questions_book_unit` (`book_id, unit_id`) is an exact leftmost prefix of longer index `idx_questions_hierarchy` (`book_id, unit_id, topic_id`).

### Table: `paper_part_section_questions`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 48  
- **Data Size:** 16.0 KB  
- **Index Size:** 16.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 48 |
| `paper_part_section_questions_section_id_foreign` | BTREE | NO | `section_id` | 17 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `paper_part_section_questions_section_id_foreign` | `section_id` | `paper_part_sections.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`paper_part_section_questions_section_id_foreign (section_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `paper_question_section_sub_sections`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 87  
- **Data Size:** 16.0 KB  
- **Index Size:** 32.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 87 |
| `paper_question_section_sub_sections_section_id_foreign` | BTREE | NO | `section_id` | 48 |
| `paper_question_section_sub_sections_question_type_id_foreign` | BTREE | NO | `question_type_id` | 29 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `paper_question_section_sub_sections_question_type_id_foreign` | `question_type_id` | `question_type_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`paper_question_section_sub_sections_question_type_id_foreign (question_type_id)`) |
| `paper_question_section_sub_sections_section_id_foreign` | `section_id` | `paper_question_sections.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`paper_question_section_sub_sections_section_id_foreign (section_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `paper_question_sections`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 48  
- **Data Size:** 16.0 KB  
- **Index Size:** 16.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 48 |
| `paper_question_sections_question_id_foreign` | BTREE | NO | `question_id` | 48 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `paper_question_sections_question_id_foreign` | `question_id` | `paper_part_section_questions.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`paper_question_sections_question_id_foreign (question_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `question_option_statistics`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 22  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 22 |
| `question_option_statistics_question_id_option_id_unique` | BTREE | **YES** | `question_id`, `option_id` | 19, 22 |
| `question_option_statistics_option_id_foreign` | BTREE | NO | `option_id` | 22 |
| `question_option_statistics_selection_count_index` | BTREE | NO | `selection_count` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `question_option_statistics_option_id_foreign` | `option_id` | `exam_question_options_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`question_option_statistics_option_id_foreign (option_id)`) |
| `question_option_statistics_question_id_foreign` | `question_id` | `exam_question_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`question_option_statistics_question_id_option_id_unique (question_id, option_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `question_pairing_schemes`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 240  
- **Data Size:** 16.0 KB  
- **Index Size:** 32.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 240 |
| `question_pairing_schemes_sub_section_id_foreign` | BTREE | NO | `sub_section_id` | 85 |
| `question_pairing_schemes_unit_id_foreign` | BTREE | NO | `unit_id` | 84 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `question_pairing_schemes_sub_section_id_foreign` | `sub_section_id` | `paper_question_section_sub_sections.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`question_pairing_schemes_sub_section_id_foreign (sub_section_id)`) |
| `question_pairing_schemes_unit_id_foreign` | `unit_id` | `book_unit_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`question_pairing_schemes_unit_id_foreign (unit_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `question_presentation_type_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 2  
- **Data Size:** 16.0 KB  
- **Index Size:** 32.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 2 |
| `question_presentation_type_tbl_type_name_unique` | BTREE | **YES** | `type_name` | 2 |
| `question_presentation_type_tbl_code_unique` | BTREE | **YES** | `code` | 2 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `question_report_audit_logs`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 40  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 38 |
| `question_report_audit_logs_question_report_id_index` | BTREE | NO | `question_report_id` | 38 |
| `question_report_audit_logs_admin_user_id_index` | BTREE | NO | `admin_user_id` | 38 |
| `question_report_audit_logs_question_report_id_created_at_index` | BTREE | NO | `question_report_id`, `created_at` | 38, 38 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **PREFIX REDUNDANCY:** Shorter index `question_report_audit_logs_question_report_id_index` (`question_report_id`) is an exact leftmost prefix of longer index `question_report_audit_logs_question_report_id_created_at_index` (`question_report_id, created_at`).

### Table: `question_reports`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 215  
- **Data Size:** 64.0 KB  
- **Index Size:** 144.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 195 |
| `question_reports_question_id_index` | BTREE | NO | `question_id` | 95 |
| `question_reports_reporter_web_user_id_index` | BTREE | NO | `reporter_web_user_id` | 106 |
| `question_reports_status_index` | BTREE | NO | `status` | 3 |
| `question_reports_report_category_index` | BTREE | NO | `report_category` | 12 |
| `question_reports_reporter_role_index` | BTREE | NO | `reporter_role` | 2 |
| `question_reports_curriculum_board_id_class_id_subject_id_index` | BTREE | NO | `curriculum_board_id`, `class_id`, `subject_id` | 3, 3, 3 |
| `question_reports_question_id_status_index` | BTREE | NO | `question_id`, `status` | 95, 98 |
| `question_reports_reporter_web_user_id_status_index` | BTREE | NO | `reporter_web_user_id`, `status` | 106, 106 |
| `question_reports_status_created_at_index` | BTREE | NO | `status`, `created_at` | 3, 50 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **PREFIX REDUNDANCY:** Shorter index `question_reports_question_id_index` (`question_id`) is an exact leftmost prefix of longer index `question_reports_question_id_status_index` (`question_id, status`).
- ⚠️ **PREFIX REDUNDANCY:** Shorter index `question_reports_reporter_web_user_id_index` (`reporter_web_user_id`) is an exact leftmost prefix of longer index `question_reports_reporter_web_user_id_status_index` (`reporter_web_user_id, status`).
- ⚠️ **PREFIX REDUNDANCY:** Shorter index `question_reports_status_index` (`status`) is an exact leftmost prefix of longer index `question_reports_status_created_at_index` (`status, created_at`).

### Table: `question_scenario_groups_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 715  
- **Data Size:** 368.0 KB  
- **Index Size:** 192.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 677 |
| `qsg_topic_fk` | BTREE | NO | `topic_id` | 29 |
| `qsg_unit_fk` | BTREE | NO | `unit_id` | 28 |
| `qsg_created_by_fk` | BTREE | NO | `created_by` | 3 |
| `qsg_updated_by_fk` | BTREE | NO | `updated_by` | 3 |
| `idx_scenario_groups_hierarchy` | BTREE | NO | `book_id`, `unit_id`, `topic_id` | 6, 28, 29 |
| `idx_scenario_groups_presentation_type` | BTREE | NO | `question_presentation_type_id` | 2 |
| `idx_scenario_groups_activate` | BTREE | NO | `activate` | 1 |
| `question_scenario_groups_tbl_ai_model_id_foreign` | BTREE | NO | `ai_model_id` | 2 |
| `question_scenario_groups_tbl_ai_provider_id_ai_model_id_index` | BTREE | NO | `ai_provider_id`, `ai_model_id` | 2, 2 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `qsg_book_fk` | `book_id` | `book_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_scenario_groups_hierarchy (book_id, unit_id, topic_id)`) |
| `qsg_created_by_fk` | `created_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`qsg_created_by_fk (created_by)`) |
| `qsg_presentation_type_fk` | `question_presentation_type_id` | `question_presentation_type_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_scenario_groups_presentation_type (question_presentation_type_id)`) |
| `qsg_topic_fk` | `topic_id` | `book_unit_topic_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`qsg_topic_fk (topic_id)`) |
| `qsg_unit_fk` | `unit_id` | `book_unit_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`qsg_unit_fk (unit_id)`) |
| `qsg_updated_by_fk` | `updated_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`qsg_updated_by_fk (updated_by)`) |
| `question_scenario_groups_tbl_ai_model_id_foreign` | `ai_model_id` | `ai_models.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`question_scenario_groups_tbl_ai_model_id_foreign (ai_model_id)`) |
| `question_scenario_groups_tbl_ai_provider_id_foreign` | `ai_provider_id` | `ai_providers.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`question_scenario_groups_tbl_ai_provider_id_ai_model_id_index (ai_provider_id, ai_model_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `question_statistics`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 158  
- **Data Size:** 16.0 KB  
- **Index Size:** 64.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 158 |
| `question_statistics_question_id_unique` | BTREE | **YES** | `question_id` | 158 |
| `question_statistics_computed_difficulty_band_index` | BTREE | NO | `computed_difficulty_band` | 4 |
| `question_statistics_is_calibrated_index` | BTREE | NO | `is_calibrated` | 1 |
| `question_statistics_last_calculated_at_index` | BTREE | NO | `last_calculated_at` | 7 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `question_statistics_question_id_foreign` | `question_id` | `exam_question_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`question_statistics_question_id_unique (question_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `question_type_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 41  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 41 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

## Past Papers & Model Papers (5 Entities)

> Catalog past papers, structured past papers, past paper pages, and board exam question mappings.

### Table: `exam_question_board_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 4,324  
- **Data Size:** 288.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 4324 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `past_paper_curriculum_audit_logs`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 64.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `pp_curr_audit_source_paper_idx` | BTREE | NO | `paper_source`, `paper_id`, `created_at` | 0, 0, 0 |
| `pp_curr_audit_admin_idx` | BTREE | NO | `admin_user_id`, `created_at` | 0, 0 |
| `pp_curr_audit_board_idx` | BTREE | NO | `new_curriculum_board_id`, `created_at` | 0, 0 |
| `pp_curr_audit_prev_cb_fk` | BTREE | NO | `previous_curriculum_board_id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `pp_curr_audit_admin_fk` | `admin_user_id` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`pp_curr_audit_admin_idx (admin_user_id, created_at)`) |
| `pp_curr_audit_new_cb_fk` | `new_curriculum_board_id` | `curriculum_board_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`pp_curr_audit_board_idx (new_curriculum_board_id, created_at)`) |
| `pp_curr_audit_prev_cb_fk` | `previous_curriculum_board_id` | `curriculum_board_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`pp_curr_audit_prev_cb_fk (previous_curriculum_board_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `past_paper_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 5,125  
- **Data Size:** 1,552.0 KB  
- **Index Size:** 2,656.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 5076 |
| `past_paper_tbl_subject_id_foreign` | BTREE | NO | `subject_id` | 30 |
| `past_paper_tbl_class_id_foreign` | BTREE | NO | `class_id` | 4 |
| `past_paper_tbl_board_id_foreign` | BTREE | NO | `board_id` | 10 |
| `past_paper_tbl_session_id_foreign` | BTREE | NO | `session_id` | 2 |
| `past_paper_tbl_paper_slug_index` | BTREE | NO | `paper_slug` | 2028 |
| `past_paper_tbl_curriculum_board_id_foreign` | BTREE | NO | `curriculum_board_id` | 1 |
| `pp_tbl_curriculum_scope_idx` | BTREE | NO | `activate`, `curriculum_board_id`, `class_id`, `subject_id`, `year`, `board_id` | 1, 1, 4, 62, 347, 1088 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `past_paper_tbl_board_id_foreign` | `board_id` | `board_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`past_paper_tbl_board_id_foreign (board_id)`) |
| `past_paper_tbl_class_id_foreign` | `class_id` | `class_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`past_paper_tbl_class_id_foreign (class_id)`) |
| `past_paper_tbl_curriculum_board_id_foreign` | `curriculum_board_id` | `curriculum_board_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`past_paper_tbl_curriculum_board_id_foreign (curriculum_board_id)`) |
| `past_paper_tbl_session_id_foreign` | `session_id` | `exam_session_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`past_paper_tbl_session_id_foreign (session_id)`) |
| `past_paper_tbl_subject_id_foreign` | `subject_id` | `subject_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`past_paper_tbl_subject_id_foreign (subject_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `tst_past_paper_pages`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 162  
- **Data Size:** 64.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 162 |
| `tst_pp_pages_unique` | BTREE | **YES** | `past_paper_id`, `paper_type`, `page_no` | 78, 156, 162 |
| `tst_pp_pages_order_idx` | BTREE | NO | `past_paper_id`, `page_no` | 78, 85 |
| `tst_past_paper_pages_is_active_index` | BTREE | NO | `is_active` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tst_past_paper_pages_past_paper_id_foreign` | `past_paper_id` | `tst_past_papers.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tst_pp_pages_unique (past_paper_id, paper_type, page_no)`, `tst_pp_pages_order_idx (past_paper_id, page_no)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `tst_past_papers`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 78  
- **Data Size:** 48.0 KB  
- **Index Size:** 192.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 78 |
| `tst_past_papers_paper_title_unique` | BTREE | **YES** | `paper_title` | 78 |
| `tst_past_papers_paper_slug_unique` | BTREE | **YES** | `paper_slug` | 78 |
| `tst_past_papers_board_id_foreign` | BTREE | NO | `board_id` | 4 |
| `tst_past_papers_subject_id_foreign` | BTREE | NO | `subject_id` | 23 |
| `tst_past_papers_class_id_foreign` | BTREE | NO | `class_id` | 2 |
| `tst_past_papers_session_id_foreign` | BTREE | NO | `session_id` | 1 |
| `tst_past_papers_created_by_foreign` | BTREE | NO | `created_by` | 2 |
| `tst_past_papers_updated_by_foreign` | BTREE | NO | `updated_by` | 2 |
| `tst_past_papers_is_active_index` | BTREE | NO | `is_active` | 1 |
| `tst_pp_search_idx` | BTREE | NO | `is_active`, `class_id`, `subject_id`, `board_id`, `year`, `session_id`, `group` | 1, 2, 39, 49, 49, 49, 78 |
| `tst_past_papers_curriculum_board_id_foreign` | BTREE | NO | `curriculum_board_id` | 1 |
| `tst_pp_curriculum_scope_idx` | BTREE | NO | `is_active`, `curriculum_board_id`, `class_id`, `subject_id`, `year`, `board_id` | 1, 1, 2, 39, 39, 49 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tst_past_papers_board_id_foreign` | `board_id` | `board_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tst_past_papers_board_id_foreign (board_id)`) |
| `tst_past_papers_class_id_foreign` | `class_id` | `class_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tst_past_papers_class_id_foreign (class_id)`) |
| `tst_past_papers_created_by_foreign` | `created_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tst_past_papers_created_by_foreign (created_by)`) |
| `tst_past_papers_curriculum_board_id_foreign` | `curriculum_board_id` | `curriculum_board_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tst_past_papers_curriculum_board_id_foreign (curriculum_board_id)`) |
| `tst_past_papers_session_id_foreign` | `session_id` | `exam_session_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tst_past_papers_session_id_foreign (session_id)`) |
| `tst_past_papers_subject_id_foreign` | `subject_id` | `subject_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tst_past_papers_subject_id_foreign (subject_id)`) |
| `tst_past_papers_updated_by_foreign` | `updated_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tst_past_papers_updated_by_foreign (updated_by)`) |

#### Index Redundancy & Efficiency Assessment

- ⚠️ **PREFIX REDUNDANCY:** Shorter index `tst_past_papers_is_active_index` (`is_active`) is an exact leftmost prefix of longer index `tst_pp_search_idx` (`is_active, class_id, subject_id, board_id, year, session_id, group`).
- ⚠️ **PREFIX REDUNDANCY:** Shorter index `tst_past_papers_is_active_index` (`is_active`) is an exact leftmost prefix of longer index `tst_pp_curriculum_scope_idx` (`is_active, curriculum_board_id, class_id, subject_id, year, board_id`).

## Assessments, Tests & Practice Sessions (13 Entities)

> LMS tests, test scopes, test questions, test attempts, attempt answers, practice sessions, and mastery summaries.

### Table: `practice_session_questions`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 567  
- **Data Size:** 64.0 KB  
- **Index Size:** 112.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 507 |
| `practice_session_questions_session_id_question_id_unique` | BTREE | **YES** | `session_id`, `question_id` | 30, 507 |
| `practice_session_questions_selected_option_id_foreign` | BTREE | NO | `selected_option_id` | 30 |
| `practice_session_questions_session_id_question_order_index` | BTREE | NO | `session_id`, `question_order` | 30, 507 |
| `practice_session_questions_session_id_is_attempted_index` | BTREE | NO | `session_id`, `is_attempted` | 30, 39 |
| `practice_session_questions_session_id_is_correct_index` | BTREE | NO | `session_id`, `is_correct` | 30, 41 |
| `practice_session_questions_question_id_is_correct_index` | BTREE | NO | `question_id`, `is_correct` | 443, 455 |
| `practice_session_questions_practiced_at_index` | BTREE | NO | `practiced_at` | 11 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `practice_session_questions_question_id_foreign` | `question_id` | `exam_question_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`practice_session_questions_question_id_is_correct_index (question_id, is_correct)`) |
| `practice_session_questions_selected_option_id_foreign` | `selected_option_id` | `exam_question_options_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`practice_session_questions_selected_option_id_foreign (selected_option_id)`) |
| `practice_session_questions_session_id_foreign` | `session_id` | `practice_sessions.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`practice_session_questions_session_id_question_id_unique (session_id, question_id)`, `practice_session_questions_session_id_question_order_index (session_id, question_order)`, `practice_session_questions_session_id_is_attempted_index (session_id, is_attempted)`, `practice_session_questions_session_id_is_correct_index (session_id, is_correct)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `practice_session_scopes`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 67  
- **Data Size:** 16.0 KB  
- **Index Size:** 32.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 62 |
| `practice_session_scopes_session_id_unit_id_unique` | BTREE | **YES** | `session_id`, `unit_id` | 28, 62 |
| `practice_session_scopes_unit_id_index` | BTREE | NO | `unit_id` | 32 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `practice_session_scopes_session_id_foreign` | `session_id` | `practice_sessions.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`practice_session_scopes_session_id_unit_id_unique (session_id, unit_id)`) |
| `practice_session_scopes_unit_id_foreign` | `unit_id` | `book_unit_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`practice_session_scopes_unit_id_index (unit_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `practice_sessions`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 33  
- **Data Size:** 16.0 KB  
- **Index Size:** 112.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 32 |
| `practice_sessions_offered_program_id_foreign` | BTREE | NO | `offered_program_id` | 3 |
| `practice_sessions_subject_id_foreign` | BTREE | NO | `subject_id` | 5 |
| `practice_sessions_user_id_status_index` | BTREE | NO | `user_id`, `status` | 5, 8 |
| `practice_sessions_user_id_submitted_at_index` | BTREE | NO | `user_id`, `submitted_at` | 5, 12 |
| `practice_sessions_status_submitted_at_index` | BTREE | NO | `status`, `submitted_at` | 2, 8 |
| `practice_sessions_started_at_index` | BTREE | NO | `started_at` | 32 |
| `practice_sessions_submitted_at_index` | BTREE | NO | `submitted_at` | 8 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `practice_sessions_offered_program_id_foreign` | `offered_program_id` | `offered_programs.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`practice_sessions_offered_program_id_foreign (offered_program_id)`) |
| `practice_sessions_subject_id_foreign` | `subject_id` | `subject_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`practice_sessions_subject_id_foreign (subject_id)`) |
| `practice_sessions_user_id_foreign` | `user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`practice_sessions_user_id_status_index (user_id, status)`, `practice_sessions_user_id_submitted_at_index (user_id, submitted_at)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `student_performance_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 1,646  
- **Data Size:** 96.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 1646 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `student_question_progress_summaries`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 159  
- **Data Size:** 48.0 KB  
- **Index Size:** 176.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 159 |
| `student_question_progress_summaries_user_id_question_id_unique` | BTREE | **YES** | `user_id`, `question_id` | 3, 159 |
| `student_question_progress_summaries_question_id_foreign` | BTREE | NO | `question_id` | 158 |
| `student_question_progress_summaries_offered_program_id_foreign` | BTREE | NO | `offered_program_id` | 2 |
| `student_question_progress_summaries_subject_id_foreign` | BTREE | NO | `subject_id` | 3 |
| `student_question_progress_summaries_unit_id_foreign` | BTREE | NO | `unit_id` | 4 |
| `student_question_progress_summaries_user_id_subject_id_index` | BTREE | NO | `user_id`, `subject_id` | 3, 4 |
| `student_question_progress_summaries_user_id_unit_id_index` | BTREE | NO | `user_id`, `unit_id` | 3, 5 |
| `sqps_user_program_idx` | BTREE | NO | `user_id`, `offered_program_id` | 3, 3 |
| `student_question_progress_summaries_is_mastered_index` | BTREE | NO | `is_mastered` | 1 |
| `student_question_progress_summaries_last_practiced_at_index` | BTREE | NO | `last_practiced_at` | 7 |
| `student_question_progress_summaries_last_tested_at_index` | BTREE | NO | `last_tested_at` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `student_question_progress_summaries_offered_program_id_foreign` | `offered_program_id` | `offered_programs.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`student_question_progress_summaries_offered_program_id_foreign (offered_program_id)`) |
| `student_question_progress_summaries_question_id_foreign` | `question_id` | `exam_question_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`student_question_progress_summaries_question_id_foreign (question_id)`) |
| `student_question_progress_summaries_subject_id_foreign` | `subject_id` | `subject_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`student_question_progress_summaries_subject_id_foreign (subject_id)`) |
| `student_question_progress_summaries_unit_id_foreign` | `unit_id` | `book_unit_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`student_question_progress_summaries_unit_id_foreign (unit_id)`) |
| `student_question_progress_summaries_user_id_foreign` | `user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`student_question_progress_summaries_user_id_question_id_unique (user_id, question_id)`, `student_question_progress_summaries_user_id_subject_id_index (user_id, subject_id)`, `student_question_progress_summaries_user_id_unit_id_index (user_id, unit_id)`, `sqps_user_program_idx (user_id, offered_program_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `student_subject_progress_summaries`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 4  
- **Data Size:** 16.0 KB  
- **Index Size:** 112.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 3 |
| `ssps_user_program_subj_idx` | BTREE | **YES** | `user_id`, `offered_program_id`, `subject_id` | 3, 3, 3 |
| `student_subject_progress_summaries_offered_program_id_foreign` | BTREE | NO | `offered_program_id` | 2 |
| `student_subject_progress_summaries_subject_id_foreign` | BTREE | NO | `subject_id` | 2 |
| `student_subject_progress_summaries_user_id_subject_id_index` | BTREE | NO | `user_id`, `subject_id` | 3, 3 |
| `ssps_user_program_idx` | BTREE | NO | `user_id`, `offered_program_id` | 3, 3 |
| `student_subject_progress_summaries_last_practiced_at_index` | BTREE | NO | `last_practiced_at` | 3 |
| `student_subject_progress_summaries_last_tested_at_index` | BTREE | NO | `last_tested_at` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `student_subject_progress_summaries_offered_program_id_foreign` | `offered_program_id` | `offered_programs.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`student_subject_progress_summaries_offered_program_id_foreign (offered_program_id)`) |
| `student_subject_progress_summaries_subject_id_foreign` | `subject_id` | `subject_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`student_subject_progress_summaries_subject_id_foreign (subject_id)`) |
| `student_subject_progress_summaries_user_id_foreign` | `user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`ssps_user_program_subj_idx (user_id, offered_program_id, subject_id)`, `student_subject_progress_summaries_user_id_subject_id_index (user_id, subject_id)`, `ssps_user_program_idx (user_id, offered_program_id)`) |

#### Index Redundancy & Efficiency Assessment

- ⚠️ **PREFIX REDUNDANCY:** Shorter index `ssps_user_program_idx` (`user_id, offered_program_id`) is an exact leftmost prefix of longer index `ssps_user_program_subj_idx` (`user_id, offered_program_id, subject_id`).

### Table: `student_test_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 94  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 94 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `student_unit_progress_summaries`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 5  
- **Data Size:** 16.0 KB  
- **Index Size:** 112.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 4 |
| `sups_user_program_unit_idx` | BTREE | **YES** | `user_id`, `offered_program_id`, `unit_id` | 3, 3, 4 |
| `student_unit_progress_summaries_offered_program_id_foreign` | BTREE | NO | `offered_program_id` | 2 |
| `student_unit_progress_summaries_unit_id_foreign` | BTREE | NO | `unit_id` | 3 |
| `student_unit_progress_summaries_user_id_unit_id_index` | BTREE | NO | `user_id`, `unit_id` | 3, 4 |
| `sups_user_program_idx` | BTREE | NO | `user_id`, `offered_program_id` | 3, 3 |
| `student_unit_progress_summaries_last_practiced_at_index` | BTREE | NO | `last_practiced_at` | 3 |
| `student_unit_progress_summaries_last_tested_at_index` | BTREE | NO | `last_tested_at` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `student_unit_progress_summaries_offered_program_id_foreign` | `offered_program_id` | `offered_programs.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`student_unit_progress_summaries_offered_program_id_foreign (offered_program_id)`) |
| `student_unit_progress_summaries_unit_id_foreign` | `unit_id` | `book_unit_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`student_unit_progress_summaries_unit_id_foreign (unit_id)`) |
| `student_unit_progress_summaries_user_id_foreign` | `user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`sups_user_program_unit_idx (user_id, offered_program_id, unit_id)`, `student_unit_progress_summaries_user_id_unit_id_index (user_id, unit_id)`, `sups_user_program_idx (user_id, offered_program_id)`) |

#### Index Redundancy & Efficiency Assessment

- ⚠️ **PREFIX REDUNDANCY:** Shorter index `sups_user_program_idx` (`user_id, offered_program_id`) is an exact leftmost prefix of longer index `sups_user_program_unit_idx` (`user_id, offered_program_id, unit_id`).

### Table: `test_attempt_questions`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 96.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `test_attempt_questions_attempt_id_question_id_unique` | BTREE | **YES** | `attempt_id`, `question_id` | 0, 0 |
| `test_attempt_questions_selected_option_id_foreign` | BTREE | NO | `selected_option_id` | 0 |
| `test_attempt_questions_attempt_id_question_order_index` | BTREE | NO | `attempt_id`, `question_order` | 0, 0 |
| `test_attempt_questions_attempt_id_is_attempted_index` | BTREE | NO | `attempt_id`, `is_attempted` | 0, 0 |
| `test_attempt_questions_attempt_id_is_correct_index` | BTREE | NO | `attempt_id`, `is_correct` | 0, 0 |
| `test_attempt_questions_question_id_index` | BTREE | NO | `question_id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `test_attempt_questions_attempt_id_foreign` | `attempt_id` | `test_attempts.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`test_attempt_questions_attempt_id_question_id_unique (attempt_id, question_id)`, `test_attempt_questions_attempt_id_question_order_index (attempt_id, question_order)`, `test_attempt_questions_attempt_id_is_attempted_index (attempt_id, is_attempted)`, `test_attempt_questions_attempt_id_is_correct_index (attempt_id, is_correct)`) |
| `test_attempt_questions_question_id_foreign` | `question_id` | `exam_question_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`test_attempt_questions_question_id_index (question_id)`) |
| `test_attempt_questions_selected_option_id_foreign` | `selected_option_id` | `exam_question_options_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`test_attempt_questions_selected_option_id_foreign (selected_option_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `test_attempts`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 112.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `test_attempts_user_id_status_index` | BTREE | NO | `user_id`, `status` | 0, 0 |
| `test_attempts_user_id_test_id_index` | BTREE | NO | `user_id`, `test_id` | 0, 0 |
| `test_attempts_test_id_status_index` | BTREE | NO | `test_id`, `status` | 0, 0 |
| `test_attempts_user_id_submitted_at_index` | BTREE | NO | `user_id`, `submitted_at` | 0, 0 |
| `test_attempts_status_submitted_at_index` | BTREE | NO | `status`, `submitted_at` | 0, 0 |
| `test_attempts_started_at_index` | BTREE | NO | `started_at` | 0 |
| `test_attempts_submitted_at_index` | BTREE | NO | `submitted_at` | 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `test_attempts_test_id_foreign` | `test_id` | `tests.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`test_attempts_test_id_status_index (test_id, status)`) |
| `test_attempts_user_id_foreign` | `user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`test_attempts_user_id_status_index (user_id, status)`, `test_attempts_user_id_test_id_index (user_id, test_id)`, `test_attempts_user_id_submitted_at_index (user_id, submitted_at)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `test_questions`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `test_questions_test_id_question_id_unique` | BTREE | **YES** | `test_id`, `question_id` | 0, 0 |
| `test_questions_question_id_foreign` | BTREE | NO | `question_id` | 0 |
| `test_questions_test_id_question_order_index` | BTREE | NO | `test_id`, `question_order` | 0, 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `test_questions_question_id_foreign` | `question_id` | `exam_question_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`test_questions_question_id_foreign (question_id)`) |
| `test_questions_test_id_foreign` | `test_id` | `tests.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`test_questions_test_id_question_id_unique (test_id, question_id)`, `test_questions_test_id_question_order_index (test_id, question_order)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `test_scopes`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 32.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `test_scopes_test_id_unit_id_unique` | BTREE | **YES** | `test_id`, `unit_id` | 0, 0 |
| `test_scopes_unit_id_index` | BTREE | NO | `unit_id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `test_scopes_test_id_foreign` | `test_id` | `tests.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`test_scopes_test_id_unit_id_unique (test_id, unit_id)`) |
| `test_scopes_unit_id_foreign` | `unit_id` | `book_unit_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`test_scopes_unit_id_index (unit_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `tests`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 1  
- **Data Size:** 16.0 KB  
- **Index Size:** 96.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 1 |
| `tests_offered_program_id_subject_id_index` | BTREE | NO | `offered_program_id`, `subject_id` | 1, 1 |
| `tests_test_source_test_mode_index` | BTREE | NO | `test_source`, `test_mode` | 1, 1 |
| `tests_is_published_published_at_index` | BTREE | NO | `is_published`, `published_at` | 1, 1 |
| `tests_created_by_test_source_index` | BTREE | NO | `created_by`, `test_source` | 1, 1 |
| `tests_subject_id_test_mode_index` | BTREE | NO | `subject_id`, `test_mode` | 1, 1 |
| `tests_offered_program_id_is_published_index` | BTREE | NO | `offered_program_id`, `is_published` | 1, 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `tests_created_by_foreign` | `created_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tests_created_by_test_source_index (created_by, test_source)`) |
| `tests_offered_program_id_foreign` | `offered_program_id` | `offered_programs.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tests_offered_program_id_subject_id_index (offered_program_id, subject_id)`, `tests_offered_program_id_is_published_index (offered_program_id, is_published)`) |
| `tests_subject_id_foreign` | `subject_id` | `subject_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`tests_subject_id_test_mode_index (subject_id, test_mode)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

## Freemium Subsystem & Idempotency (9 Entities)

> Freemium campaigns, access rules, quotas, grants, counters, ledger consumptions, audit logs, and assessment idempotency.

### Table: `assessment_idempotency_records`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 16.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `uk_user_op_idempotency` | BTREE | **YES** | `user_id`, `operation`, `idempotency_key` | 0, 0, 0 |
| `idx_user_op_created` | BTREE | NO | `user_id`, `operation`, `created_at` | 0, 0, 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `assessment_idempotency_records_user_id_foreign` | `user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`uk_user_op_idempotency (user_id, operation, idempotency_key)`, `idx_user_op_created (user_id, operation, created_at)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `freemium_access_rules`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 160.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `freemium_access_rules_class_id_foreign` | BTREE | NO | `class_id` | 0 |
| `freemium_access_rules_subject_id_foreign` | BTREE | NO | `subject_id` | 0 |
| `freemium_access_rules_book_id_foreign` | BTREE | NO | `book_id` | 0 |
| `freemium_access_rules_chapter_id_foreign` | BTREE | NO | `chapter_id` | 0 |
| `freemium_access_rules_topic_id_foreign` | BTREE | NO | `topic_id` | 0 |
| `freemium_access_rules_model_paper_id_foreign` | BTREE | NO | `model_paper_id` | 0 |
| `freemium_access_rules_past_paper_id_foreign` | BTREE | NO | `past_paper_id` | 0 |
| `idx_far_campaign` | BTREE | NO | `campaign_id` | 0 |
| `idx_far_target_scope` | BTREE | NO | `target_scope`, `target_status` | 0, 0 |
| `idx_far_hierarchy_lookup` | BTREE | NO | `curriculum_board_id`, `class_id`, `subject_id`, `book_id`, `chapter_id`, `topic_id` | 0, 0, 0, 0, 0, 0 |
| `idx_far_leaf_lookup` | BTREE | NO | `question_id`, `model_paper_id`, `past_paper_id` | 0, 0, 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `freemium_access_rules_book_id_foreign` | `book_id` | `book_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`freemium_access_rules_book_id_foreign (book_id)`) |
| `freemium_access_rules_campaign_id_foreign` | `campaign_id` | `freemium_campaigns.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_far_campaign (campaign_id)`) |
| `freemium_access_rules_chapter_id_foreign` | `chapter_id` | `book_unit_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`freemium_access_rules_chapter_id_foreign (chapter_id)`) |
| `freemium_access_rules_class_id_foreign` | `class_id` | `class_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`freemium_access_rules_class_id_foreign (class_id)`) |
| `freemium_access_rules_curriculum_board_id_foreign` | `curriculum_board_id` | `curriculum_board_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_far_hierarchy_lookup (curriculum_board_id, class_id, subject_id, book_id, chapter_id, topic_id)`) |
| `freemium_access_rules_model_paper_id_foreign` | `model_paper_id` | `model_papers.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`freemium_access_rules_model_paper_id_foreign (model_paper_id)`) |
| `freemium_access_rules_past_paper_id_foreign` | `past_paper_id` | `tst_past_papers.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`freemium_access_rules_past_paper_id_foreign (past_paper_id)`) |
| `freemium_access_rules_question_id_foreign` | `question_id` | `exam_question_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_far_leaf_lookup (question_id, model_paper_id, past_paper_id)`) |
| `freemium_access_rules_subject_id_foreign` | `subject_id` | `subject_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`freemium_access_rules_subject_id_foreign (subject_id)`) |
| `freemium_access_rules_topic_id_foreign` | `topic_id` | `book_unit_topic_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`freemium_access_rules_topic_id_foreign (topic_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `freemium_audit_logs`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 16.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `idx_entity` | BTREE | NO | `entity_type`, `entity_id` | 0, 0 |
| `idx_admin_action` | BTREE | NO | `admin_id`, `action` | 0, 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `freemium_audit_logs_admin_id_foreign` | `admin_id` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_admin_action (admin_id, action)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `freemium_campaigns`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 64.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `freemium_campaigns_created_by_foreign` | BTREE | NO | `created_by` | 0 |
| `freemium_campaigns_updated_by_foreign` | BTREE | NO | `updated_by` | 0 |
| `idx_fc_status_dates` | BTREE | NO | `status`, `start_at`, `end_at` | 0, 0, 0 |
| `idx_fc_priority` | BTREE | NO | `priority` | 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `freemium_campaigns_created_by_foreign` | `created_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`freemium_campaigns_created_by_foreign (created_by)`) |
| `freemium_campaigns_updated_by_foreign` | `updated_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`freemium_campaigns_updated_by_foreign (updated_by)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `freemium_exam_quotas`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 16.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `uk_campaign_exam_window` | BTREE | **YES** | `campaign_id`, `exam_type`, `window_type` | 0, 0, 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `freemium_exam_quotas_campaign_id_foreign` | `campaign_id` | `freemium_campaigns.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`uk_campaign_exam_window (campaign_id, exam_type, window_type)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `freemium_quota_consumption_envelopes`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 64.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `uk_consumption_envelope` | BTREE | **YES** | `consumption_id`, `campaign_id`, `quota_id`, `window_key` | 0, 0, 0, 0 |
| `freemium_quota_consumption_envelopes_quota_id_foreign` | BTREE | NO | `quota_id` | 0 |
| `idx_campaign_window_audit` | BTREE | NO | `campaign_id`, `window_type`, `window_key`, `allocated_at` | 0, 0, 0, 0 |
| `idx_grant_audit` | BTREE | NO | `grant_id`, `allocated_at` | 0, 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `freemium_quota_consumption_envelopes_campaign_id_foreign` | `campaign_id` | `freemium_campaigns.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_campaign_window_audit (campaign_id, window_type, window_key, allocated_at)`) |
| `freemium_quota_consumption_envelopes_consumption_id_foreign` | `consumption_id` | `freemium_quota_consumptions.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`uk_consumption_envelope (consumption_id, campaign_id, quota_id, window_key)`) |
| `freemium_quota_consumption_envelopes_grant_id_foreign` | `grant_id` | `freemium_student_grants.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_grant_audit (grant_id, allocated_at)`) |
| `freemium_quota_consumption_envelopes_quota_id_foreign` | `quota_id` | `freemium_exam_quotas.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`freemium_quota_consumption_envelopes_quota_id_foreign (quota_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `freemium_quota_consumptions`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 144.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `uk_practice_attempt_event` | BTREE | **YES** | `practice_session_id`, `attempt_number`, `event_type` | 0, 0, 0 |
| `uk_user_idempotency` | BTREE | **YES** | `user_id`, `idempotency_key` | 0, 0 |
| `uk_test_user_attempt_event` | BTREE | **YES** | `test_id`, `user_id`, `attempt_number`, `event_type` | 0, 0, 0, 0 |
| `freemium_quota_consumptions_primary_campaign_id_foreign` | BTREE | NO | `primary_campaign_id` | 0 |
| `freemium_quota_consumptions_primary_quota_id_foreign` | BTREE | NO | `primary_quota_id` | 0 |
| `freemium_quota_consumptions_primary_grant_id_foreign` | BTREE | NO | `primary_grant_id` | 0 |
| `freemium_quota_consumptions_test_attempt_id_foreign` | BTREE | NO | `test_attempt_id` | 0 |
| `idx_user_exam_consumed` | BTREE | NO | `user_id`, `exam_type`, `consumed_at` | 0, 0, 0 |
| `idx_entity_lookup` | BTREE | NO | `entity_type`, `entity_id` | 0, 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `freemium_quota_consumptions_practice_session_id_foreign` | `practice_session_id` | `practice_sessions.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`uk_practice_attempt_event (practice_session_id, attempt_number, event_type)`) |
| `freemium_quota_consumptions_primary_campaign_id_foreign` | `primary_campaign_id` | `freemium_campaigns.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`freemium_quota_consumptions_primary_campaign_id_foreign (primary_campaign_id)`) |
| `freemium_quota_consumptions_primary_grant_id_foreign` | `primary_grant_id` | `freemium_student_grants.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`freemium_quota_consumptions_primary_grant_id_foreign (primary_grant_id)`) |
| `freemium_quota_consumptions_primary_quota_id_foreign` | `primary_quota_id` | `freemium_exam_quotas.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`freemium_quota_consumptions_primary_quota_id_foreign (primary_quota_id)`) |
| `freemium_quota_consumptions_test_attempt_id_foreign` | `test_attempt_id` | `test_attempts.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`freemium_quota_consumptions_test_attempt_id_foreign (test_attempt_id)`) |
| `freemium_quota_consumptions_test_id_foreign` | `test_id` | `tests.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`uk_test_user_attempt_event (test_id, user_id, attempt_number, event_type)`) |
| `freemium_quota_consumptions_user_id_foreign` | `user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`uk_user_idempotency (user_id, idempotency_key)`, `idx_user_exam_consumed (user_id, exam_type, consumed_at)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `freemium_quota_counters`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 16.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `uk_user_exam_window` | BTREE | **YES** | `user_id`, `exam_type`, `window_type`, `window_key` | 0, 0, 0, 0 |
| `idx_window_lookup` | BTREE | NO | `window_type`, `window_key` | 0, 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `freemium_quota_counters_user_id_foreign` | `user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`uk_user_exam_window (user_id, exam_type, window_type, window_key)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `freemium_student_grants`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `uk_campaign_user` | BTREE | **YES** | `campaign_id`, `user_id` | 0, 0 |
| `freemium_student_grants_created_by_foreign` | BTREE | NO | `created_by` | 0 |
| `idx_fsg_user_grant_status` | BTREE | NO | `user_id`, `status`, `expires_at` | 0, 0, 0 |
| `idx_fsg_campaign_user` | BTREE | NO | `campaign_id`, `user_id` | 0, 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `freemium_student_grants_campaign_id_foreign` | `campaign_id` | `freemium_campaigns.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`uk_campaign_user (campaign_id, user_id)`, `idx_fsg_campaign_user (campaign_id, user_id)`) |
| `freemium_student_grants_created_by_foreign` | `created_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`freemium_student_grants_created_by_foreign (created_by)`) |
| `freemium_student_grants_user_id_foreign` | `user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_fsg_user_grant_status (user_id, status, expires_at)`) |

#### Index Redundancy & Efficiency Assessment

- ❌ **EXACT DUPLICATE:** Index `uk_campaign_user` and `idx_fsg_campaign_user` index identical column sequence `(campaign_id, user_id)`. One of these should be safely dropped.

## Authentication, RBAC & Activity Logs (11 Entities)

> Roles, permissions, permission scopes, personal access tokens, login logs, activity audit trails, and background queue jobs.

### Table: `admin_activity_logs`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 14,179  
- **Data Size:** 3,600.0 KB  
- **Index Size:** 7,136.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 13792 |
| `admin_activity_logs_admin_user_id_index` | BTREE | NO | `admin_user_id` | 8 |
| `admin_activity_logs_action_index` | BTREE | NO | `action` | 11 |
| `admin_activity_logs_module_index` | BTREE | NO | `module` | 18 |
| `admin_activity_logs_module_action_index` | BTREE | NO | `module`, `action` | 18, 37 |
| `admin_activity_logs_reference_type_reference_id_index` | BTREE | NO | `reference_type`, `reference_id` | 19, 1590 |
| `admin_activity_logs_ip_address_index` | BTREE | NO | `ip_address` | 647 |
| `admin_activity_logs_created_at_index` | BTREE | NO | `created_at` | 13504 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `admin_activity_logs_admin_user_id_foreign` | `admin_user_id` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`admin_activity_logs_admin_user_id_index (admin_user_id)`) |

#### Index Redundancy & Efficiency Assessment

- ⚠️ **PREFIX REDUNDANCY:** Shorter index `admin_activity_logs_module_index` (`module`) is an exact leftmost prefix of longer index `admin_activity_logs_module_action_index` (`module, action`).

### Table: `admin_login_logs`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 327  
- **Data Size:** 112.0 KB  
- **Index Size:** 80.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 302 |
| `admin_login_logs_user_id_status_login_at_index` | BTREE | NO | `user_id`, `status`, `login_at` | 9, 13, 302 |
| `admin_login_logs_email_index` | BTREE | NO | `email` | 10 |
| `admin_login_logs_status_index` | BTREE | NO | `status` | 3 |
| `admin_login_logs_token_jti_index` | BTREE | NO | `token_jti` | 295 |
| `admin_login_logs_login_at_index` | BTREE | NO | `login_at` | 302 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `admin_login_logs_user_id_foreign` | `user_id` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`admin_login_logs_user_id_status_login_at_index (user_id, status, login_at)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `failed_jobs`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 16.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `failed_jobs_uuid_unique` | BTREE | **YES** | `uuid` | 0 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `job_batches`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `jobs`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 16.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `jobs_queue_index` | BTREE | NO | `queue` | 0 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `password_reset_tokens`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(email)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `email` | 0 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `permissions`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 214  
- **Data Size:** 16.0 KB  
- **Index Size:** 16.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 189 |
| `permissions_name_unique` | BTREE | **YES** | `name` | 189 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `personal_access_tokens`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 32.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `personal_access_tokens_token_unique` | BTREE | **YES** | `token` | 0 |
| `personal_access_tokens_tokenable_type_tokenable_id_index` | BTREE | NO | `tokenable_type`, `tokenable_id` | 0, 0 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `role_permission_scopes`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 15  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 15 |
| `role_permission_scopes_unique` | BTREE | **YES** | `role_id`, `permission_id`, `scope_type`, `scope_id` | 3, 9, 9, 15 |
| `role_permission_scopes_permission_id_foreign` | BTREE | NO | `permission_id` | 3 |
| `role_permission_scopes_scope_type_scope_id_index` | BTREE | NO | `scope_type`, `scope_id` | 1, 5 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `role_permission_scopes_permission_id_foreign` | `permission_id` | `permissions.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`role_permission_scopes_permission_id_foreign (permission_id)`) |
| `role_permission_scopes_role_id_foreign` | `role_id` | `roles.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`role_permission_scopes_unique (role_id, permission_id, scope_type, scope_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `role_permissions`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 819  
- **Data Size:** 64.0 KB  
- **Index Size:** 96.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 764 |
| `role_permissions_role_id_permission_id_unique` | BTREE | **YES** | `role_id`, `permission_id` | 306, 764 |
| `role_permissions_permission_id_foreign` | BTREE | NO | `permission_id` | 209 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `role_permissions_permission_id_foreign` | `permission_id` | `permissions.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`role_permissions_permission_id_foreign (permission_id)`) |
| `role_permissions_role_id_foreign` | `role_id` | `roles.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`role_permissions_role_id_permission_id_unique (role_id, permission_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `roles`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 508  
- **Data Size:** 64.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 482 |
| `roles_name_unique` | BTREE | **YES** | `name` | 482 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

## CMS, Communications, Website & System (49 Entities)

> News, blogs, workshops, feedback, administration user profiles, cache, migrations, and layout sections.

### Table: `admin_notifications`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 32.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `admin_notifications_admin_user_id_is_read_index` | BTREE | NO | `admin_user_id`, `is_read` | 0, 0 |
| `admin_notifications_type_index` | BTREE | NO | `type` | 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `admin_notifications_admin_user_id_foreign` | `admin_user_id` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`admin_notifications_admin_user_id_is_read_index (admin_user_id, is_read)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `admin_user_preferences`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 5  
- **Data Size:** 16.0 KB  
- **Index Size:** 80.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 5 |
| `admin_user_preferences_user_id_unique` | BTREE | **YES** | `user_id` | 5 |
| `admin_user_preferences_theme_mode_index` | BTREE | NO | `theme_mode` | 1 |
| `admin_user_preferences_sidebar_state_index` | BTREE | NO | `sidebar_state` | 1 |
| `admin_user_preferences_language_index` | BTREE | NO | `language` | 1 |
| `admin_user_preferences_timezone_index` | BTREE | NO | `timezone` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `admin_user_preferences_user_id_foreign` | `user_id` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`admin_user_preferences_user_id_unique (user_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `admin_user_profiles`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 6  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 6 |
| `admin_user_profiles_user_id_unique` | BTREE | **YES** | `user_id` | 6 |
| `admin_user_profiles_created_by_foreign` | BTREE | NO | `created_by` | 1 |
| `admin_user_profiles_updated_by_foreign` | BTREE | NO | `updated_by` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `admin_user_profiles_created_by_foreign` | `created_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`admin_user_profiles_created_by_foreign (created_by)`) |
| `admin_user_profiles_updated_by_foreign` | `updated_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`admin_user_profiles_updated_by_foreign (updated_by)`) |
| `admin_user_profiles_user_id_foreign` | `user_id` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`admin_user_profiles_user_id_unique (user_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `advance_salaries`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 40  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 40 |
| `advance_salaries_employee_id_foreign` | BTREE | NO | `employee_id` | 14 |
| `advance_salaries_voucher_id_foreign` | BTREE | NO | `voucher_id` | 40 |
| `advance_salaries_account_id_foreign` | BTREE | NO | `account_id` | 2 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `advance_salaries_account_id_foreign` | `account_id` | `chart_of_accounts.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`advance_salaries_account_id_foreign (account_id)`) |
| `advance_salaries_employee_id_foreign` | `employee_id` | `employees.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`advance_salaries_employee_id_foreign (employee_id)`) |
| `advance_salaries_voucher_id_foreign` | `voucher_id` | `vouchers.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`advance_salaries_voucher_id_foreign (voucher_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `advance_salary_entries`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 74  
- **Data Size:** 16.0 KB  
- **Index Size:** 32.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 74 |
| `advance_salary_entries_advance_id_foreign` | BTREE | NO | `advance_id` | 40 |
| `advance_salary_entries_voucher_id_foreign` | BTREE | NO | `voucher_id` | 72 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `advance_salary_entries_advance_id_foreign` | `advance_id` | `advance_salaries.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`advance_salary_entries_advance_id_foreign (advance_id)`) |
| `advance_salary_entries_voucher_id_foreign` | `voucher_id` | `vouchers.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`advance_salary_entries_voucher_id_foreign (voucher_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `ai_models`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 11  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 11 |
| `ai_models_ai_provider_id_model_key_unique` | BTREE | **YES** | `ai_provider_id`, `model_key` | 2, 11 |
| `ai_models_default_slot_unique` | BTREE | **YES** | `default_slot` | 2 |
| `ai_models_is_active_index` | BTREE | NO | `is_active` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `ai_models_ai_provider_id_foreign` | `ai_provider_id` | `ai_providers.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`ai_models_ai_provider_id_model_key_unique (ai_provider_id, model_key)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `ai_providers`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 2  
- **Data Size:** 16.0 KB  
- **Index Size:** 16.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 2 |
| `ai_providers_key_unique` | BTREE | **YES** | `key` | 2 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `ai_requests`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 12  
- **Data Size:** 160.0 KB  
- **Index Size:** 144.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 12 |
| `ai_requests_operation_id_attempt_number_unique` | BTREE | **YES** | `operation_id`, `attempt_number` | 12, 12 |
| `ai_requests_subject_type_subject_id_index` | BTREE | NO | `subject_type`, `subject_id` | 2, 12 |
| `ai_requests_created_at_index` | BTREE | NO | `created_at` | 12 |
| `ai_requests_user_id_created_at_index` | BTREE | NO | `user_id`, `created_at` | 1, 12 |
| `ai_requests_ai_model_id_created_at_index` | BTREE | NO | `ai_model_id`, `created_at` | 3, 12 |
| `ai_requests_purpose_index` | BTREE | NO | `purpose` | 2 |
| `ai_requests_status_index` | BTREE | NO | `status` | 2 |
| `ai_requests_provider_request_id_index` | BTREE | NO | `provider_request_id` | 5 |
| `ai_requests_provider_response_id_index` | BTREE | NO | `provider_response_id` | 12 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `ai_requests_ai_model_id_foreign` | `ai_model_id` | `ai_models.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`ai_requests_ai_model_id_created_at_index (ai_model_id, created_at)`) |
| `ai_requests_user_id_foreign` | `user_id` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`ai_requests_user_id_created_at_index (user_id, created_at)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `blog_category_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 1  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `blog_comment_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `blog_files_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 1  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `blog_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 1  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `branches`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 2  
- **Data Size:** 16.0 KB  
- **Index Size:** 144.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 2 |
| `branches_created_by_foreign` | BTREE | NO | `created_by` | 1 |
| `branches_updated_by_foreign` | BTREE | NO | `updated_by` | 1 |
| `branches_salary_account_foreign` | BTREE | NO | `salary_account` | 2 |
| `branches_salary_advance_account_foreign` | BTREE | NO | `salary_advance_account` | 2 |
| `branches_employee_loan_account_foreign` | BTREE | NO | `employee_loan_account` | 2 |
| `branches_bonus_account_foreign` | BTREE | NO | `bonus_account` | 2 |
| `branches_overtime_account_foreign` | BTREE | NO | `overtime_account` | 2 |
| `branches_other_allowance_account_foreign` | BTREE | NO | `other_allowance_account` | 2 |
| `branches_city_id_foreign` | BTREE | NO | `city_id` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `branches_bonus_account_foreign` | `bonus_account` | `chart_of_accounts.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`branches_bonus_account_foreign (bonus_account)`) |
| `branches_city_id_foreign` | `city_id` | `cities.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`branches_city_id_foreign (city_id)`) |
| `branches_created_by_foreign` | `created_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`branches_created_by_foreign (created_by)`) |
| `branches_employee_loan_account_foreign` | `employee_loan_account` | `chart_of_accounts.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`branches_employee_loan_account_foreign (employee_loan_account)`) |
| `branches_other_allowance_account_foreign` | `other_allowance_account` | `chart_of_accounts.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`branches_other_allowance_account_foreign (other_allowance_account)`) |
| `branches_overtime_account_foreign` | `overtime_account` | `chart_of_accounts.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`branches_overtime_account_foreign (overtime_account)`) |
| `branches_salary_account_foreign` | `salary_account` | `chart_of_accounts.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`branches_salary_account_foreign (salary_account)`) |
| `branches_salary_advance_account_foreign` | `salary_advance_account` | `chart_of_accounts.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`branches_salary_advance_account_foreign (salary_advance_account)`) |
| `branches_updated_by_foreign` | `updated_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`branches_updated_by_foreign (updated_by)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `cache`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 3,060  
- **Data Size:** 416.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(key)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `key` | 2802 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `cache_locks`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(key)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `key` | 0 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `chart_of_accounts`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 86  
- **Data Size:** 16.0 KB  
- **Index Size:** 64.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 86 |
| `chart_of_accounts_code_unique` | BTREE | **YES** | `code` | 86 |
| `chart_of_accounts_parent_id_foreign` | BTREE | NO | `parent_id` | 25 |
| `chart_of_accounts_created_by_foreign` | BTREE | NO | `created_by` | 2 |
| `chart_of_accounts_updated_by_foreign` | BTREE | NO | `updated_by` | 2 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `chart_of_accounts_created_by_foreign` | `created_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`chart_of_accounts_created_by_foreign (created_by)`) |
| `chart_of_accounts_parent_id_foreign` | `parent_id` | `chart_of_accounts.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`chart_of_accounts_parent_id_foreign (parent_id)`) |
| `chart_of_accounts_updated_by_foreign` | `updated_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`chart_of_accounts_updated_by_foreign (updated_by)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `dashboard_items`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 38  
- **Data Size:** 16.0 KB  
- **Index Size:** 96.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 38 |
| `dashboard_items_code_unique` | BTREE | **YES** | `code` | 38 |
| `dashboard_items_category_index` | BTREE | NO | `category` | 9 |
| `dashboard_items_widget_type_index` | BTREE | NO | `widget_type` | 2 |
| `dashboard_items_permission_name_index` | BTREE | NO | `permission_name` | 19 |
| `dashboard_items_is_active_index` | BTREE | NO | `is_active` | 1 |
| `dashboard_items_sort_order_index` | BTREE | NO | `sort_order` | 38 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `data_entry_assignment_items`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 71  
- **Data Size:** 16.0 KB  
- **Index Size:** 128.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 71 |
| `data_entry_assignment_items_created_by_foreign` | BTREE | NO | `created_by` | 1 |
| `data_entry_assignment_items_updated_by_foreign` | BTREE | NO | `updated_by` | 1 |
| `data_entry_assignment_items_assignment_id_index` | BTREE | NO | `assignment_id` | 66 |
| `data_entry_assignment_items_submitted_by_index` | BTREE | NO | `submitted_by` | 4 |
| `data_entry_assignment_items_module_type_index` | BTREE | NO | `module_type` | 2 |
| `data_entry_assignment_items_module_type_reference_id_index` | BTREE | NO | `module_type`, `reference_id` | 2, 2 |
| `data_entry_assignment_items_status_index` | BTREE | NO | `status` | 2 |
| `data_entry_assignment_items_reviewed_by_index` | BTREE | NO | `reviewed_by` | 2 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `data_entry_assignment_items_assignment_id_foreign` | `assignment_id` | `data_entry_assignments.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`data_entry_assignment_items_assignment_id_index (assignment_id)`) |
| `data_entry_assignment_items_created_by_foreign` | `created_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`data_entry_assignment_items_created_by_foreign (created_by)`) |
| `data_entry_assignment_items_reviewed_by_foreign` | `reviewed_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`data_entry_assignment_items_reviewed_by_index (reviewed_by)`) |
| `data_entry_assignment_items_submitted_by_foreign` | `submitted_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`data_entry_assignment_items_submitted_by_index (submitted_by)`) |
| `data_entry_assignment_items_updated_by_foreign` | `updated_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`data_entry_assignment_items_updated_by_foreign (updated_by)`) |

#### Index Redundancy & Efficiency Assessment

- ⚠️ **PREFIX REDUNDANCY:** Shorter index `data_entry_assignment_items_module_type_index` (`module_type`) is an exact leftmost prefix of longer index `data_entry_assignment_items_module_type_reference_id_index` (`module_type, reference_id`).

### Table: `data_entry_assignment_payments`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 65  
- **Data Size:** 16.0 KB  
- **Index Size:** 64.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 65 |
| `data_entry_assignment_payments_assignment_id_index` | BTREE | NO | `assignment_id` | 65 |
| `data_entry_assignment_payments_paid_by_index` | BTREE | NO | `paid_by` | 1 |
| `data_entry_assignment_payments_payment_status_index` | BTREE | NO | `payment_status` | 1 |
| `data_entry_assignment_payments_paid_at_index` | BTREE | NO | `paid_at` | 30 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `data_entry_assignment_payments_assignment_id_foreign` | `assignment_id` | `data_entry_assignments.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`data_entry_assignment_payments_assignment_id_index (assignment_id)`) |
| `data_entry_assignment_payments_paid_by_foreign` | `paid_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`data_entry_assignment_payments_paid_by_index (paid_by)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `data_entry_assignments`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 69  
- **Data Size:** 16.0 KB  
- **Index Size:** 128.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 69 |
| `data_entry_assignments_created_by_foreign` | BTREE | NO | `created_by` | 1 |
| `data_entry_assignments_updated_by_foreign` | BTREE | NO | `updated_by` | 1 |
| `data_entry_assignments_assigned_to_index` | BTREE | NO | `assigned_to` | 4 |
| `data_entry_assignments_assigned_by_index` | BTREE | NO | `assigned_by` | 1 |
| `data_entry_assignments_module_type_index` | BTREE | NO | `module_type` | 2 |
| `data_entry_assignments_payment_type_index` | BTREE | NO | `payment_type` | 3 |
| `data_entry_assignments_status_index` | BTREE | NO | `status` | 3 |
| `data_entry_assignments_due_date_index` | BTREE | NO | `due_date` | 46 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `data_entry_assignments_assigned_by_foreign` | `assigned_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`data_entry_assignments_assigned_by_index (assigned_by)`) |
| `data_entry_assignments_assigned_to_foreign` | `assigned_to` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`data_entry_assignments_assigned_to_index (assigned_to)`) |
| `data_entry_assignments_created_by_foreign` | `created_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`data_entry_assignments_created_by_foreign (created_by)`) |
| `data_entry_assignments_updated_by_foreign` | `updated_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`data_entry_assignments_updated_by_foreign (updated_by)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `districts`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 18  
- **Data Size:** 16.0 KB  
- **Index Size:** 16.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 18 |
| `districts_division_id_foreign` | BTREE | NO | `division_id` | 10 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `districts_division_id_foreign` | `division_id` | `divisions.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`districts_division_id_foreign (division_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `divisions`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 10  
- **Data Size:** 16.0 KB  
- **Index Size:** 16.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 10 |
| `divisions_province_id_foreign` | BTREE | NO | `province_id` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `divisions_province_id_foreign` | `province_id` | `provinces.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`divisions_province_id_foreign (province_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `feedback_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 1  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `heard_about_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 1  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `home_section_items`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `home_sections`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `migrations`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 224  
- **Data Size:** 48.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 209 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

### Table: `model_papers`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 6  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 6 |
| `model_papers_class_id_foreign` | BTREE | NO | `class_id` | 1 |
| `model_papers_subject_id_foreign` | BTREE | NO | `subject_id` | 6 |
| `model_papers_curriculum_board_id_foreign` | BTREE | NO | `curriculum_board_id` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `model_papers_class_id_foreign` | `class_id` | `class_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`model_papers_class_id_foreign (class_id)`) |
| `model_papers_curriculum_board_id_foreign` | `curriculum_board_id` | `curriculum_board_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`model_papers_curriculum_board_id_foreign (curriculum_board_id)`) |
| `model_papers_subject_id_foreign` | `subject_id` | `subject_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`model_papers_subject_id_foreign (subject_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `news`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 5  
- **Data Size:** 48.0 KB  
- **Index Size:** 192.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 5 |
| `news_slug_unique` | BTREE | **YES** | `slug` | 5 |
| `news_is_published_published_at_index` | BTREE | NO | `is_published`, `published_at` | 1, 5 |
| `news_category_id_is_published_published_at_index` | BTREE | NO | `category_id`, `is_published`, `published_at` | 2, 2, 5 |
| `news_news_type_is_published_published_at_index` | BTREE | NO | `news_type`, `is_published`, `published_at` | 3, 3, 5 |
| `news_language_is_published_published_at_index` | BTREE | NO | `language`, `is_published`, `published_at` | 1, 1, 5 |
| `news_is_featured_is_published_published_at_index` | BTREE | NO | `is_featured`, `is_published`, `published_at` | 1, 1, 5 |
| `news_is_breaking_is_published_published_at_index` | BTREE | NO | `is_breaking`, `is_published`, `published_at` | 2, 2, 5 |
| `news_institute_id_is_published_published_at_index` | BTREE | NO | `institute_id`, `is_published`, `published_at` | 1, 1, 5 |
| `news_expires_at_index` | BTREE | NO | `expires_at` | 1 |
| `news_display_order_index` | BTREE | NO | `display_order` | 1 |
| `news_created_by_index` | BTREE | NO | `created_by` | 1 |
| `news_updated_by_index` | BTREE | NO | `updated_by` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `news_category_id_foreign` | `category_id` | `news_categories.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`news_category_id_is_published_published_at_index (category_id, is_published, published_at)`) |
| `news_created_by_foreign` | `created_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`news_created_by_index (created_by)`) |
| `news_institute_id_foreign` | `institute_id` | `institute_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`news_institute_id_is_published_published_at_index (institute_id, is_published, published_at)`) |
| `news_updated_by_foreign` | `updated_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`news_updated_by_index (updated_by)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `news_attachments`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 16.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `news_attachments_news_id_foreign` | BTREE | NO | `news_id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `news_attachments_news_id_foreign` | `news_id` | `news_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`news_attachments_news_id_foreign (news_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `news_categories`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 2  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 2 |
| `news_categories_slug_unique` | BTREE | **YES** | `slug` | 2 |
| `news_categories_created_by_foreign` | BTREE | NO | `created_by` | 1 |
| `news_categories_updated_by_foreign` | BTREE | NO | `updated_by` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `news_categories_created_by_foreign` | `created_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`news_categories_created_by_foreign (created_by)`) |
| `news_categories_updated_by_foreign` | `updated_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`news_categories_updated_by_foreign (updated_by)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `news_category_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 1  
- **Data Size:** 16.0 KB  
- **Index Size:** 32.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `news_category_tbl_slug_unique` | BTREE | **YES** | `slug` | 0 |
| `news_category_tbl_priority_score_index` | BTREE | NO | `priority_score` | 0 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `news_images`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 16.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `news_images_news_id_index` | BTREE | NO | `news_id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `news_images_news_id_foreign` | `news_id` | `news_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`news_images_news_id_index (news_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `news_media`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 149  
- **Data Size:** 48.0 KB  
- **Index Size:** 64.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 149 |
| `news_media_created_by_foreign` | BTREE | NO | `created_by` | 1 |
| `news_media_updated_by_foreign` | BTREE | NO | `updated_by` | 1 |
| `news_media_news_id_is_active_display_order_index` | BTREE | NO | `news_id`, `is_active`, `display_order` | 2, 2, 149 |
| `news_media_media_type_index` | BTREE | NO | `media_type` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `news_media_created_by_foreign` | `created_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`news_media_created_by_foreign (created_by)`) |
| `news_media_news_id_foreign` | `news_id` | `news.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`news_media_news_id_is_active_display_order_index (news_id, is_active, display_order)`) |
| `news_media_updated_by_foreign` | `updated_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`news_media_updated_by_foreign (updated_by)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `news_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 5  
- **Data Size:** 48.0 KB  
- **Index Size:** 128.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 5 |
| `news_tbl_slug_unique` | BTREE | **YES** | `slug` | 5 |
| `news_tbl_language_index` | BTREE | NO | `language` | 1 |
| `news_tbl_activate_index` | BTREE | NO | `activate` | 1 |
| `news_tbl_published_at_index` | BTREE | NO | `published_at` | 5 |
| `news_tbl_category_id_foreign` | BTREE | NO | `category_id` | 1 |
| `news_tbl_priority_score_index` | BTREE | NO | `priority_score` | 1 |
| `news_tbl_expires_at_index` | BTREE | NO | `expires_at` | 4 |
| `news_tbl_status_index` | BTREE | NO | `status` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `news_tbl_category_id_foreign` | `category_id` | `news_category_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`news_tbl_category_id_foreign (category_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `news_tickers`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 4  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 4 |
| `news_tickers_news_id_foreign` | BTREE | NO | `news_id` | 4 |
| `news_tickers_created_by_foreign` | BTREE | NO | `created_by` | 1 |
| `news_tickers_updated_by_foreign` | BTREE | NO | `updated_by` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `news_tickers_created_by_foreign` | `created_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`news_tickers_created_by_foreign (created_by)`) |
| `news_tickers_news_id_foreign` | `news_id` | `news.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`news_tickers_news_id_foreign (news_id)`) |
| `news_tickers_updated_by_foreign` | `updated_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`news_tickers_updated_by_foreign (updated_by)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `offered_classes`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 2  
- **Data Size:** 16.0 KB  
- **Index Size:** 32.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 2 |
| `offered_classes_class_id_foreign` | BTREE | NO | `class_id` | 1 |
| `offered_classes_curriculum_board_id_foreign` | BTREE | NO | `curriculum_board_id` | 2 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `offered_classes_class_id_foreign` | `class_id` | `class_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`offered_classes_class_id_foreign (class_id)`) |
| `offered_classes_curriculum_board_id_foreign` | `curriculum_board_id` | `curriculum_board_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`offered_classes_curriculum_board_id_foreign (curriculum_board_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `paper_part_sections`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 17  
- **Data Size:** 16.0 KB  
- **Index Size:** 16.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 17 |
| `paper_part_sections_paper_part_id_foreign` | BTREE | NO | `paper_part_id` | 12 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `paper_part_sections_paper_part_id_foreign` | `paper_part_id` | `paper_parts.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`paper_part_sections_paper_part_id_foreign (paper_part_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `paper_parts`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 12  
- **Data Size:** 16.0 KB  
- **Index Size:** 16.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 12 |
| `paper_parts_model_paper_id_foreign` | BTREE | NO | `model_paper_id` | 6 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `paper_parts_model_paper_id_foreign` | `model_paper_id` | `model_papers.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`paper_parts_model_paper_id_foreign (model_paper_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `role_dashboard_items`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 48  
- **Data Size:** 16.0 KB  
- **Index Size:** 64.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 48 |
| `role_dashboard_items_role_id_dashboard_item_id_unique` | BTREE | **YES** | `role_id`, `dashboard_item_id` | 3, 48 |
| `role_dashboard_items_dashboard_item_id_foreign` | BTREE | NO | `dashboard_item_id` | 38 |
| `role_dashboard_items_is_visible_index` | BTREE | NO | `is_visible` | 1 |
| `role_dashboard_items_sort_order_index` | BTREE | NO | `sort_order` | 38 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `role_dashboard_items_dashboard_item_id_foreign` | `dashboard_item_id` | `dashboard_items.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`role_dashboard_items_dashboard_item_id_foreign (dashboard_item_id)`) |
| `role_dashboard_items_role_id_foreign` | `role_id` | `roles.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`role_dashboard_items_role_id_dashboard_item_id_unique (role_id, dashboard_item_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `sessions`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 21  
- **Data Size:** 16.0 KB  
- **Index Size:** 32.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 17 |
| `sessions_user_id_index` | BTREE | NO | `user_id` | 1 |
| `sessions_last_activity_index` | BTREE | NO | `last_activity` | 17 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `slo_domain_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 24  
- **Data Size:** 16.0 KB  
- **Index Size:** 80.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 21 |
| `slo_domain_tbl_curriculum_board_id_foreign` | BTREE | NO | `curriculum_board_id` | 1 |
| `slo_domain_tbl_created_by_foreign` | BTREE | NO | `created_by` | 1 |
| `slo_domain_tbl_updated_by_foreign` | BTREE | NO | `updated_by` | 1 |
| `idx_slo_domain_subj_code` | BTREE | NO | `subject_id`, `domain_code` | 1, 21 |
| `slo_domain_tbl_activate_index` | BTREE | NO | `activate` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `slo_domain_tbl_created_by_foreign` | `created_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`slo_domain_tbl_created_by_foreign (created_by)`) |
| `slo_domain_tbl_curriculum_board_id_foreign` | `curriculum_board_id` | `curriculum_board_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`slo_domain_tbl_curriculum_board_id_foreign (curriculum_board_id)`) |
| `slo_domain_tbl_subject_id_foreign` | `subject_id` | `subject_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_slo_domain_subj_code (subject_id, domain_code)`) |
| `slo_domain_tbl_updated_by_foreign` | `updated_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`slo_domain_tbl_updated_by_foreign (updated_by)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `study_group_detail_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 34  
- **Data Size:** 16.0 KB  
- **Index Size:** 32.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 34 |
| `study_group_detail_tbl_study_group_id_foreign` | BTREE | NO | `study_group_id` | 4 |
| `study_group_detail_tbl_subject_id_foreign` | BTREE | NO | `subject_id` | 10 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `study_group_detail_tbl_study_group_id_foreign` | `study_group_id` | `study_group_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`study_group_detail_tbl_study_group_id_foreign (study_group_id)`) |
| `study_group_detail_tbl_subject_id_foreign` | `subject_id` | `subject_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`study_group_detail_tbl_subject_id_foreign (subject_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `study_group_tbl`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 4  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 4 |
| `unique_name_class_curriculum` | BTREE | **YES** | `name`, `class_id`, `curriculum_board_id` | 2, 4, 4 |
| `study_group_tbl_class_id_foreign` | BTREE | NO | `class_id` | 2 |
| `study_group_tbl_curriculum_board_id_foreign` | BTREE | NO | `curriculum_board_id` | 1 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `study_group_tbl_class_id_foreign` | `class_id` | `class_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`study_group_tbl_class_id_foreign (class_id)`) |
| `study_group_tbl_curriculum_board_id_foreign` | `curriculum_board_id` | `curriculum_board_tbl.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`study_group_tbl_curriculum_board_id_foreign (curriculum_board_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `user_dashboard_items`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 38  
- **Data Size:** 16.0 KB  
- **Index Size:** 64.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 38 |
| `user_dashboard_items_user_id_dashboard_item_id_unique` | BTREE | **YES** | `user_id`, `dashboard_item_id` | 1, 38 |
| `user_dashboard_items_dashboard_item_id_foreign` | BTREE | NO | `dashboard_item_id` | 38 |
| `user_dashboard_items_is_visible_index` | BTREE | NO | `is_visible` | 2 |
| `user_dashboard_items_sort_order_index` | BTREE | NO | `sort_order` | 38 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `user_dashboard_items_dashboard_item_id_foreign` | `dashboard_item_id` | `dashboard_items.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`user_dashboard_items_dashboard_item_id_foreign (dashboard_item_id)`) |
| `user_dashboard_items_user_id_foreign` | `user_id` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`user_dashboard_items_user_id_dashboard_item_id_unique (user_id, dashboard_item_id)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `web_user_notifications`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 7  
- **Data Size:** 16.0 KB  
- **Index Size:** 64.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 7 |
| `idx_wun_recipient_read_created` | BTREE | NO | `recipient_web_user_id`, `is_read`, `created_at` | 2, 2, 7 |
| `wun_actor_fk` | BTREE | NO | `actor_web_user_id` | 2 |
| `wun_class_fk` | BTREE | NO | `teacher_class_id` | 1 |
| `wun_enrollment_fk` | BTREE | NO | `teacher_class_enrollment_id` | 2 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `wun_actor_fk` | `actor_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`wun_actor_fk (actor_web_user_id)`) |
| `wun_class_fk` | `teacher_class_id` | `teacher_classes.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`wun_class_fk (teacher_class_id)`) |
| `wun_enrollment_fk` | `teacher_class_enrollment_id` | `teacher_class_enrollments.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`wun_enrollment_fk (teacher_class_enrollment_id)`) |
| `wun_recipient_fk` | `recipient_web_user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`idx_wun_recipient_read_created (recipient_web_user_id, is_read, created_at)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `workshop_prerequisites`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 16.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `wrk_pre_req_order_idx` | BTREE | NO | `workshop_id`, `display_order` | 0, 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `workshop_prerequisites_workshop_id_foreign` | `workshop_id` | `workshops.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`wrk_pre_req_order_idx (workshop_id, display_order)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `workshop_registrations`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 48.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |
| `wrk_reg_unique` | BTREE | **YES** | `workshop_id`, `user_id` | 0, 0 |
| `workshop_registrations_attendance_marked_by_foreign` | BTREE | NO | `attendance_marked_by` | 0 |
| `wrk_reg_user_status_idx` | BTREE | NO | `user_id`, `status` | 0, 0 |
| `wrk_reg_workshop_status_idx` | BTREE | NO | `workshop_id`, `status` | 0, 0 |
| `wrk_reg_time_idx` | BTREE | NO | `registered_at` | 0 |

#### Foreign Key Constraints & Supporting Index Status

| Constraint Name | Foreign Key Column | Referenced Table & Column | On Delete / Update | Covered by Index? |
| :--- | :--- | :--- | :--- | :---: |
| `workshop_registrations_attendance_marked_by_foreign` | `attendance_marked_by` | `users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`workshop_registrations_attendance_marked_by_foreign (attendance_marked_by)`) |
| `workshop_registrations_user_id_foreign` | `user_id` | `web_users.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`wrk_reg_user_status_idx (user_id, status)`) |
| `workshop_registrations_workshop_id_foreign` | `workshop_id` | `workshops.id` | DEL: RESTRICT / UPD: RESTRICT | ✅ Yes (`wrk_reg_unique (workshop_id, user_id)`, `wrk_reg_workshop_status_idx (workshop_id, status)`) |

#### Index Redundancy & Efficiency Assessment

- ✅ **Clean:** No redundant, duplicate, or prefix-overlapping indexes detected.

### Table: `workshops`

- **Entity Type:** InnoDB Base Table  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 16.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** `(id)`  

#### Indexes Defined

| Index Name | Type | Unique | Column Sequence (Leading to Trailing) | Cardinality |
| :--- | :---: | :---: | :--- | :---: |
| `PRIMARY` | BTREE | **YES** | `id` | 0 |

#### Foreign Key Constraints & Supporting Index Status

*No formal database-level foreign key constraints defined.*

#### Index Redundancy & Efficiency Assessment

- ⚠️ **Single Index Warning:** Table possesses only a `PRIMARY KEY`. Any lookup on non-PK columns causes a full table scan.

## Database Views (13 Entities)

> Database SQL views joining classes, subjects, boards, years, books, units, questions, and reports.

### Table: `blog_view`

- **Entity Type:** SQL View  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 0.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** *None*  

*Note: SQL View does not hold storage or individual index structures; query performance depends on underlying base tables.*

### Table: `boards_of_subjects_of_classes_view`

- **Entity Type:** SQL View  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 0.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** *None*  

*Note: SQL View does not hold storage or individual index structures; query performance depends on underlying base tables.*

### Table: `book_unit_topic_view`

- **Entity Type:** SQL View  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 0.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** *None*  

*Note: SQL View does not hold storage or individual index structures; query performance depends on underlying base tables.*

### Table: `book_unit_view`

- **Entity Type:** SQL View  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 0.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** *None*  

*Note: SQL View does not hold storage or individual index structures; query performance depends on underlying base tables.*

### Table: `book_view`

- **Entity Type:** SQL View  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 0.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** *None*  

*Note: SQL View does not hold storage or individual index structures; query performance depends on underlying base tables.*

### Table: `exam_question_board_view`

- **Entity Type:** SQL View  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 0.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** *None*  

*Note: SQL View does not hold storage or individual index structures; query performance depends on underlying base tables.*

### Table: `exam_questions_view`

- **Entity Type:** SQL View  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 0.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** *None*  

*Note: SQL View does not hold storage or individual index structures; query performance depends on underlying base tables.*

### Table: `getemployeesloanledgersummaryreport`

- **Entity Type:** SQL View  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 0.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** *None*  

*Note: SQL View does not hold storage or individual index structures; query performance depends on underlying base tables.*

### Table: `news_view`

- **Entity Type:** SQL View  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 0.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** *None*  

*Note: SQL View does not hold storage or individual index structures; query performance depends on underlying base tables.*

### Table: `papers_of_years_of_boards_of_subjects_of_classes_view`

- **Entity Type:** SQL View  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 0.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** *None*  

*Note: SQL View does not hold storage or individual index structures; query performance depends on underlying base tables.*

### Table: `past_paper_view`

- **Entity Type:** SQL View  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 0.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** *None*  

*Note: SQL View does not hold storage or individual index structures; query performance depends on underlying base tables.*

### Table: `subjects_of_classes_view`

- **Entity Type:** SQL View  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 0.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** *None*  

*Note: SQL View does not hold storage or individual index structures; query performance depends on underlying base tables.*

### Table: `years_of_boards_of_subjects_of_classes_view`

- **Entity Type:** SQL View  
- **Approximate / Exact Rows:** 0  
- **Data Size:** 0.0 KB  
- **Index Size:** 0.0 KB  
- **Primary Key:** *None*  

*Note: SQL View does not hold storage or individual index structures; query performance depends on underlying base tables.*

