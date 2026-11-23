# Stage 1E.2.D.D — Assessment Source Inventory

**Document ID:** `PHASE_1E_2_D_D_SOURCE_INVENTORY`  
**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Stage:** 1E.2.D.D — Planning and Source Inspection ONLY  
**Timestamp:** 2026-11-19  

---

## 1. Executive Scope & Objective

Stage 1E.2.D.D covers the remaining assessment lifecycle paths across The Students Times LMS backend.  
This source inventory catalogs all remaining endpoints, controllers, services, database models, and middleware involved in:
1. **Mock Test Generation & Attempt Lifecycle:** Mock exams vs. formal unit/full-book tests.
2. **Recent Attempt History & Aggregation:** `GET /tests/get-recent-attempts` across formal tests and practice sessions.
3. **Test Eligibility & Quota Inquiries:** `GET /freemium/tests/{testId}/eligibility` and `GET /freemium/quota-allowances`.
4. **Official / Shared Test Distinctions:** Pre-published institution tests vs. dynamic student-generated tests.
5. **Classroom Assigned Tests (Phase 7D):** Boundaries between teacher-assigned classroom examinations and student Freemium assessments.
6. **Legacy Assessment Routes:** Pre-LMS payment-gated legacy endpoints.

---

## 2. Exhaustive Endpoint & Controller Inventory

### 2.1 Remaining LMS Assessment Endpoints

| Endpoint Route | HTTP Method | Controller Action | Target Entity | Core Purpose | Auth Middleware |
|---|---|---|---|---|---|
| `/tests/get-recent-attempts` | `GET` | `TestController@getRecentAttemptsForLms` | `test_attempts`, `practice_sessions` | Retrieve unified chronological list of submitted formal tests and practice sessions. | `auth:sanctum` |
| `/freemium/tests/{testId}/eligibility` | `GET` | `StudentFreemiumQuotaController@testEligibility` | `tests`, `test_attempts`, `freemium_quota_consumptions` | Query student attempt and retake eligibility, current attempt count, and retake caps. | `auth:sanctum` |
| `/freemium/quota-allowances` | `GET` | `StudentFreemiumQuotaController@quotaAllowances` | `freemium_exam_quotas`, `freemium_quota_counters` | Query student remaining weekly quota balances across exam types (`practice`, `formal_test`, `mock`). | `auth:sanctum` |

### 2.2 Already Hardened Assessment Endpoints (Stages D.A, D.B, D.C)

| Endpoint Route | HTTP Method | Controller Action | Target Entity | Hardened In Stage |
|---|---|---|---|---|
| `/tests/generate-selected-units-formal-test` | `POST` | `TestController@generateSelectedUnitsFormalTestForLms` | `tests`, `freemium_quota_consumptions` | Stage 1E.2.D.A |
| `/tests/generate-full-book-formal-test` | `POST` | `TestController@generateFullBookFormalTestForLms` | `tests`, `freemium_quota_consumptions` | Stage 1E.2.D.A |
| `/tests/save-formal-test-question-progress` | `POST` | `TestController@saveFormalTestQuestionProgressForLms` | `test_attempts`, `freemium_quota_consumptions` | Stage 1E.2.D.B.2 |
| `/tests/submit-formal-test` | `POST` | `TestController@submitFormalTestForLms` | `test_attempts`, `student_activity_tbl` | Stage 1E.2.D.B.2 |
| `/tests/generate-selected-units-practice-session` | `POST` | `PracticeSessionController@generateSelectedUnitsPracticeSessionForLms` | `practice_sessions`, `freemium_quota_consumptions` | Stage 1E.2.D.C |
| `/tests/generate-full-book-practice-session` | `POST` | `PracticeSessionController@generateFullBookPracticeSessionForLms` | `practice_sessions`, `freemium_quota_consumptions` | Stage 1E.2.D.C |
| `/tests/save-practice-session-question-progress` | `POST` | `PracticeSessionController@savePracticeSessionQuestionProgressForLms` | `practice_sessions`, `practice_session_answers` | Stage 1E.2.D.C |
| `/tests/submit-practice-session` | `POST` | `PracticeSessionController@submitPracticeSessionForLms` | `practice_sessions`, `student_activity_tbl` | Stage 1E.2.D.C |

### 2.3 Teacher Classroom Test Endpoints (Phase 7D Architectural Boundary)

| Endpoint Route | HTTP Method | Controller Action | Target Entity | Quota Gating Status |
|---|---|---|---|---|
| `/student/tests` | `GET` | `StudentClassTestController@index` | `teacher_class_test_recipients` | **EXEMPT** (Gated by classroom enrollment). |
| `/student/tests/{assignmentId}` | `GET` | `StudentClassTestController@show` | `teacher_class_test_assignments` | **EXEMPT** (Gated by classroom enrollment). |
| `/student/tests/{assignmentId}/start` | `POST` | `StudentClassTestController@start` | `teacher_class_test_attempts` | **EXEMPT** (Gated by classroom enrollment). |
| `/student/tests/{assignmentId}/attempts/{attemptId}` | `GET` | `StudentClassTestController@attemptDetail` | `teacher_class_test_attempts` | **EXEMPT** (Gated by classroom enrollment). |
| `/student/tests/{assignmentId}/attempts/{attemptId}/answers` | `PUT` | `StudentClassTestController@saveAnswers` | `teacher_class_test_attempt_answers` | **EXEMPT** (Gated by classroom enrollment). |
| `/student/tests/{assignmentId}/attempts/{attemptId}/submit` | `POST` | `StudentClassTestController@submit` | `teacher_class_test_attempts` | **EXEMPT** (Gated by classroom enrollment). |

### 2.4 Legacy Pre-LMS Endpoints (Deprecated / Payment-Gated)

| Endpoint Route | HTTP Method | Controller Action | Target Entity | Protection Mechanism |
|---|---|---|---|---|
| `/get_practice_test_mcqs_by_units` | `POST` | `ExamTestController@getPracticeMCQsTestOfUnits` | `student_test_tbl`, `student_performance_tbl` | `EnsurePaymentVerified` middleware |
| `/get_random_test_mcqs_by_units` | `POST` | `ExamTestController@getRandomMCQsTestOfUnits` | `student_test_tbl`, `student_performance_tbl` | `EnsurePaymentVerified` middleware |
| `/save-exam-test` | `POST` | `ExamTestController@saveTestResult` | `student_test_tbl`, `student_performance_tbl` | `EnsurePaymentVerified` middleware |

---

## 3. Database Models & Schema Grounding

1. **`App\Models\Test` (`tests` table):**
   - Key Columns: `id`, `offered_program_id`, `subject_id`, `created_by`, `title`, `test_source` (`'system'`, `'student'`, `'official'`), `test_mode` (`'test'`, `'mock'`), `scope_type`, `time_limit_minutes`, `total_questions`, `is_published`, `published_at`.
2. **`App\Models\TestAttempt` (`test_attempts` table):**
   - Key Columns: `id`, `test_id`, `user_id`, `attempt_number`, `status` (`'in_progress'`, `'submitted'`, `'abandoned'`), `score`, `accuracy`, `submitted_at`, `created_at`.
3. **`App\Models\PracticeSession` (`practice_sessions` table):**
   - Key Columns: `id`, `user_id`, `offered_program_id`, `subject_id`, `scope_type`, `status` (`'in_progress'`, `'submitted'`), `score`, `accuracy`, `submitted_at`, `created_at`.
4. **`App\Models\Freemium\FreemiumQuotaConsumption` (`freemium_quota_consumptions` table):**
   - Key Columns: `id`, `user_id`, `campaign_id`, `quota_id`, `exam_type` (`'formal_test'`, `'practice'`, `'mock'`), `event_type` (`'generation'`, `'attempt_start'`, `'retake_start'`), `test_id`, `attempt_number`.
   - Unique Composite Index: `(test_id, user_id, attempt_number, event_type)` via Migration `000011`.
5. **`App\Models\AssessmentIdempotencyRecord` (`assessment_idempotency_records` table):**
   - Key Columns: `idempotency_key`, `action_type`, `user_id`, `request_payload_hash`, `response_status`, `response_body`.
