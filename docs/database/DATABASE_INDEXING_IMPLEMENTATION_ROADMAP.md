# Database Indexing Implementation Roadmap — The Students Times LMS

**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Target Environments:** Local Development (`3peas_db`), Local Automated Test (`3peas_db_subscription_test`), Future Staging & Production  
**Database Engine:** MySQL 8.0.43 InnoDB  
**Date:** 2026-11-19  

---

## Executive Summary & Phased Implementation Strategy

To ensure zero service downtime, zero data corruption risk, and complete transaction safety, index optimization must be executed in disciplined, dependency-aware phases. Each phase groups changes by operational risk, tables affected, and application criticality.

### Implementation Phase Overview

| Phase | Focus | Scope | Risk Level | Downtime / Lock Risk |
| :---: | :--- | :--- | :---: | :--- |
| **Phase 1** | **Critical Table Scan Elimination** | `exam_question_board_tbl`, `student_performance_tbl` | Medium | Low (Online DDL `ALGORITHM=INPLACE, LOCK=NONE`) |
| **Phase 2** | **Entitlement & Progress Optimization** | `user_subscriptions`, `test_attempts`, `admin_activity_logs` | Medium | Low (Online DDL `ALGORITHM=INPLACE, LOCK=NONE`) |
| **Phase 3** | **Academic Scope & Student Dashboard** | `user_selected_subject_tbl`, `past_paper_tbl`, `teacher_class_tests`, `student_test_tbl` | Low | Negligible |
| **Phase 4** | **Redundant Index Removal** | `freemium_student_grants`, `exam_question_tbl`, `admin_activity_logs`, `question_reports`, progress summaries | Low | Instantaneous metadata lock only |
| **Phase 5** | **System Maintenance & Pruning** | `cache` table expiration index | Very Low | Negligible |

---

## Phase 1: Critical Table Scan Elimination

**Objective:** Eliminate severe full table scans on question board associations (4,324 rows) and student performance evaluation records (1,646 rows).

### Planned Migrations

#### Migration 1.1: `add_indexes_to_exam_question_board_tbl`
```php
public function up(): void
{
    Schema::table('exam_question_board_tbl', function (Blueprint $table) {
        $table->index('question_id', 'idx_eqb_question_id');
        $table->index(['board_id', 'year', 'session_id', 'group_id'], 'idx_eqb_filter_scope');
    });
}

public function down(): void
{
    Schema::table('exam_question_board_tbl', function (Blueprint $table) {
        $table->dropIndex('idx_eqb_question_id');
        $table->dropIndex('idx_eqb_filter_scope');
    });
}
```

#### Migration 1.2: `add_indexes_to_student_performance_tbl`
```php
public function up(): void
{
    Schema::table('student_performance_tbl', function (Blueprint $table) {
        $table->index(['test_id', 'question_id'], 'idx_sp_test_question');
    });
}

public function down(): void
{
    Schema::table('student_performance_tbl', function (Blueprint $table) {
        $table->dropIndex('idx_sp_test_question');
    });
}
```

---

## Phase 2: Core Entitlement & Assessment Optimization

**Objective:** Accelerate subscription validation on every request, optimize active test attempt resolution during autosave, and eliminate filesorts on admin activity trails.

### Planned Migrations

#### Migration 2.1: `add_performance_indexes_to_entitlement_and_attempts`
```php
public function up(): void
{
    Schema::table('user_subscriptions', function (Blueprint $table) {
        $table->index(['user_id', 'status', 'offered_program_id', 'expires_at'], 'idx_us_user_status_prog_exp');
    });

    Schema::table('test_attempts', function (Blueprint $table) {
        $table->index(['user_id', 'test_id', 'status', 'id'], 'idx_ta_user_test_status_id');
    });

    Schema::table('admin_activity_logs', function (Blueprint $table) {
        $table->index(['module', 'action', 'created_at'], 'idx_aal_module_action_created');
    });
}

public function down(): void
{
    Schema::table('user_subscriptions', function (Blueprint $table) {
        $table->dropIndex('idx_us_user_status_prog_exp');
    });

    Schema::table('test_attempts', function (Blueprint $table) {
        $table->dropIndex('idx_ta_user_test_status_id');
    });

    Schema::table('admin_activity_logs', function (Blueprint $table) {
        $table->dropIndex('idx_aal_module_action_created');
    });
}
```

---

## Phase 3: Academic Scope & Student Dashboard Optimization

**Objective:** Enable index seeking on student subject selection, catalog past paper search, and teacher test multi-class scopes.

### Planned Migrations

#### Migration 3.1: `add_scope_and_dashboard_indexes`
```php
public function up(): void
{
    Schema::table('user_selected_subject_tbl', function (Blueprint $table) {
        $table->index(['user_id', 'subject_id'], 'idx_uss_user_subject');
    });

    Schema::table('past_paper_tbl', function (Blueprint $table) {
        $table->index(['activate', 'class_id', 'subject_id', 'board_id', 'year'], 'idx_pp_scope_lookup');
    });

    Schema::table('teacher_class_tests', function (Blueprint $table) {
        $table->index(['curriculum_board_id', 'class_id', 'subject_id'], 'idx_tct_board_class_subject');
    });

    Schema::table('student_test_tbl', function (Blueprint $table) {
        $table->index('user_id', 'idx_st_user_id');
    });
}

public function down(): void
{
    Schema::table('user_selected_subject_tbl', function (Blueprint $table) {
        $table->dropIndex('idx_uss_user_subject');
    });

    Schema::table('past_paper_tbl', function (Blueprint $table) {
        $table->dropIndex('idx_pp_scope_lookup');
    });

    Schema::table('teacher_class_tests', function (Blueprint $table) {
        $table->dropIndex('idx_tct_board_class_subject');
    });

    Schema::table('student_test_tbl', function (Blueprint $table) {
        $table->dropIndex('idx_st_user_id');
    });
}
```

---

## Phase 4: Redundant & Duplicate Index Clean-Up

**Objective:** Drop 10 redundant indexes to reclaim InnoDB buffer pool memory, reduce index write amplification, and eliminate duplicate constraint evaluation.

> [!IMPORTANT]
> **Prerequisite Safety Rule:** Phase 4 must ONLY be executed after verifying that all covering composite indexes (e.g. `uk_campaign_user`, `idx_questions_hierarchy`, `idx_aal_module_action_created`) are active and validated in production.

#### Migration 4.1: `drop_redundant_database_indexes`
```php
public function up(): void
{
    // 1. Duplicate index on Freemium grants
    Schema::table('freemium_student_grants', function (Blueprint $table) {
        $table->dropIndex('idx_fsg_campaign_user');
    });

    // 2. Prefix redundancy on 23,904-row question bank
    Schema::table('exam_question_tbl', function (Blueprint $table) {
        $table->dropIndex('idx_questions_book_unit');
    });

    // 3. Prefix redundancy on admin logs
    Schema::table('admin_activity_logs', function (Blueprint $table) {
        $table->dropIndex('admin_activity_logs_module_index');
    });

    // 4. Prefix redundancies on SLO tables
    Schema::table('unit_slo_topic_tbl', function (Blueprint $table) {
        $table->dropIndex('unit_slo_topic_tbl_unit_id_index');
    });
    Schema::table('topic_slo_topic_tbl', function (Blueprint $table) {
        $table->dropIndex('topic_slo_topic_tbl_topic_id_index');
    });

    // 5. Prefix redundancies on student progress summaries
    Schema::table('student_subject_progress_summaries', function (Blueprint $table) {
        $table->dropIndex('ssps_user_program_idx');
    });
    Schema::table('student_unit_progress_summaries', function (Blueprint $table) {
        $table->dropIndex('sups_user_program_idx');
    });

    // 6. Prefix redundancies on question reports
    Schema::table('question_reports', function (Blueprint $table) {
        $table->dropIndex('question_reports_question_id_index');
        $table->dropIndex('question_reports_reporter_web_user_id_index');
        $table->dropIndex('question_reports_status_index');
    });
}

public function down(): void
{
    Schema::table('freemium_student_grants', function (Blueprint $table) {
        $table->index(['campaign_id', 'user_id'], 'idx_fsg_campaign_user');
    });
    Schema::table('exam_question_tbl', function (Blueprint $table) {
        $table->index(['book_id', 'unit_id'], 'idx_questions_book_unit');
    });
    Schema::table('admin_activity_logs', function (Blueprint $table) {
        $table->index('module', 'admin_activity_logs_module_index');
    });
    Schema::table('unit_slo_topic_tbl', function (Blueprint $table) {
        $table->index('unit_id', 'unit_slo_topic_tbl_unit_id_index');
    });
    Schema::table('topic_slo_topic_tbl', function (Blueprint $table) {
        $table->index('topic_id', 'topic_slo_topic_tbl_topic_id_index');
    });
    Schema::table('student_subject_progress_summaries', function (Blueprint $table) {
        $table->index(['user_id', 'offered_program_id'], 'ssps_user_program_idx');
    });
    Schema::table('student_unit_progress_summaries', function (Blueprint $table) {
        $table->index(['user_id', 'offered_program_id'], 'sups_user_program_idx');
    });
    Schema::table('question_reports', function (Blueprint $table) {
        $table->index('question_id', 'question_reports_question_id_index');
        $table->index('reporter_web_user_id', 'question_reports_reporter_web_user_id_index');
        $table->index('status', 'question_reports_status_index');
    });
}
```

---

## Phase 5: System Maintenance & Pruning Optimization

#### Migration 5.1: `add_cache_expiration_index`
```php
public function up(): void
{
    Schema::table('cache', function (Blueprint $table) {
        $table->index('expiration', 'idx_cache_expiration');
    });
}

public function down(): void
{
    Schema::table('cache', function (Blueprint $table) {
        $table->dropIndex('idx_cache_expiration');
    });
}
```

---

## Online DDL, Rollback & Compatibility Safeguards

1. **Online DDL Support:**
   - MySQL 8.0.43 supports online non-blocking index additions and index drops using `ALGORITHM=INPLACE, LOCK=NONE` for secondary B-Tree indexes on InnoDB tables.
   - Concurrent reads and writes (`INSERT`, `UPDATE`, `DELETE`) proceed without blocking while the index is being built.
2. **Metadata Lock Timeout Prevention:**
   - In production, set `SESSION lock_wait_timeout = 10` before executing DDL to prevent long-running read queries from waiting on metadata locks.
3. **Strict Rollback Symmetry:**
   - Every proposed migration has a tested, symmetrical `down()` method that restores the previous schema state without data loss.
4. **Foreign Key Integrity Check:**
   - None of the 10 redundant indexes scheduled for dropping serve as the sole supporting index for a foreign key constraint.

