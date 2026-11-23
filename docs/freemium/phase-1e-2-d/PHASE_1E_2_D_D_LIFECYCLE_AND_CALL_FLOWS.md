# Stage 1E.2.D.D — Assessment Lifecycle and Call Flows

**Document ID:** `PHASE_1E_2_D_D_LIFECYCLE_AND_CALL_FLOWS`  
**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Stage:** 1E.2.D.D — Planning and Source Inspection ONLY  
**Timestamp:** 2026-11-19  

---

## 1. Unified Assessment Architecture & Entity Boundaries

```mermaid
graph TD
    User["Student WebUser (role=student)"] --> Router{"LMS API Router"}

    Router -->|"/tests/get-recent-attempts"| RecentAttempts["TestController@getRecentAttemptsForLms"]
    Router -->|"/freemium/tests/{id}/eligibility"| TestEligibility["StudentFreemiumQuotaController@testEligibility"]
    Router -->|"/freemium/quota-allowances"| QuotaAllowances["StudentFreemiumQuotaController@quotaAllowances"]

    RecentAttempts --> QueryFormal["Query test_attempts (status=submitted)"]
    RecentAttempts --> QueryPractice["Query practice_sessions (status=submitted)"]
    RecentAttempts --> MergeSort["Merge & Sort by submitted_at DESC"]

    TestEligibility --> QuotaService["FreemiumQuotaConsumptionService@getTestAttemptEligibility"]
    QuotaService --> CheckPaid["Check Paid Subscription Scope"]
    QuotaService --> CheckCampaign["Check Freemium Campaign Quota"]
    QuotaService --> CheckFallback["Check Legacy-Free Fallback Policy"]
```

---

## 2. Call Flows & Sequence Details

### 2.1 Recent Attempts Query Flow (`getRecentAttemptsForLms`)

```mermaid
sequenceDiagram
    autonumber
    actor Student
    participant API as TestController
    participant DB as MySQL Database

    Student->>API: GET /tests/get-recent-attempts?limit=10&subject_id=5
    Note over API: Security Gap: Currently lacks role='student' check
    API->>API: Validate input (limit: 1-20, program_id, subject_id)
    API->>DB: SELECT test_attempts JOIN tests (user_id = student.id, status = 'submitted')
    API->>DB: SELECT practice_sessions (user_id = student.id, status = 'submitted')
    Note over API: Gaps: attempt_number not selected; mock test title not formatted
    API->>API: Map practice display title, concat formal & practice, sort DESC by submitted_at
    API->>API: Slice top :limit records
    API-->>Student: HTTP 200 OK (data: recent_attempts array)
```

### 2.2 Test Eligibility Flow (`getTestAttemptEligibility`)

```mermaid
sequenceDiagram
    autonumber
    actor Student
    participant Controller as StudentFreemiumQuotaController
    participant Service as FreemiumQuotaConsumptionService
    participant DB as MySQL Database

    Student->>Controller: GET /freemium/tests/{testId}/eligibility
    Controller->>Controller: Verify auth user is active student
    Controller->>Service: getTestAttemptEligibility(student, testId)
    Service->>DB: SELECT * FROM tests WHERE id = testId
    alt Test not found OR (!isStudentOwned && !isOfficialShared)
        Service-->>Controller: [eligible: false, code: 'test_not_found']
        Controller-->>Student: HTTP 404 Not Found (safe fail-closed)
    else Test accessible
        Service->>DB: Count existing attempts for student on testId
        alt Student has active Paid subscription covering test
            Service-->>Controller: [can_attempt: true, is_paid: true, remaining: null]
        else Student on Freemium or Legacy-Free
            Service->>Service: Evaluate allow_retakes & max_attempts_per_test
            Service-->>Controller: [can_attempt: bool, attempt_number: N, remaining_attempts: R]
        end
        Controller-->>Student: HTTP 200 OK (eligibility payload)
    end
```

### 2.3 Mock Test Execution Lifecycle Flow

```mermaid
sequenceDiagram
    autonumber
    actor Student
    participant Controller as TestController
    participant QuotaService as FreemiumQuotaConsumptionService
    participant DB as MySQL Database

    Note over Student,Controller: Mock tests use test_mode = 'mock' in tests table
    Student->>Controller: POST /tests/save-formal-test-question-progress (test_id, question_id, option_id)
    Controller->>Controller: Verify active student role & test accessibility
    Controller->>DB: Lock test_attempts FOR UPDATE (test_id, user_id)
    alt Active in_progress attempt exists
        Controller->>DB: Save/update test_attempt_answers (No quota consumed)
    else No active attempt (First progress or retake start)
        Controller->>QuotaService: consumeAttemptStartOrRetake(student, testId, examType='mock')
        QuotaService->>DB: Check quota balance & record attempt_start / retake_start in ledger
        Controller->>DB: INSERT INTO test_attempts (status='in_progress', attempt_number=N)
        Controller->>DB: INSERT INTO test_attempt_answers
    end
    Controller-->>Student: HTTP 200 OK (attempt_id, question_id, progress)

    Student->>Controller: POST /tests/submit-formal-test (test_id, attempt_id)
    Controller->>DB: Lock test_attempts FOR UPDATE
    Controller->>DB: UPDATE test_attempts SET status='submitted', score=..., submitted_at=NOW()
    Controller->>DB: INSERT INTO student_activity_tbl (activity_type='mock_completed')
    Controller-->>Student: HTTP 200 OK (score, accuracy, summary)
```

---

## 3. Teacher Classroom Tests vs. Freemium Assessment Boundary

Teacher-created classroom tests (Phase 7D) operate completely outside the student freemium quota ledger:
1. **Separation of Tables:** Teacher tests use `teacher_class_test_assignments`, `teacher_class_test_recipients`, and `teacher_class_test_attempts`. They never write to `tests`, `test_attempts`, or `freemium_quota_consumptions`.
2. **Access Control:** Gated strictly by classroom enrollment (`TeacherClassEnrollment`), teacher active status, and class schedule window (`starts_at`, `due_at`, `cutoff_at`).
3. **Attempt Accounting:** Attempt counting is managed by `TeacherClassTestAssignment::max_attempts` configured by the assigning teacher, completely independent of Freemium campaign policies.
4. **Zero Quota Burn:** Enrolled students taking teacher classroom tests burn **0** Freemium weekly quotas.
