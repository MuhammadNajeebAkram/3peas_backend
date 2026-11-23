# Stage 1E.2.D.D — Risk Register & Mitigation Strategy

**Document ID:** `PHASE_1E_2_D_D_RISK_REGISTER`  
**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Stage:** 1E.2.D.D — Planning and Source Inspection ONLY  
**Timestamp:** 2026-11-19  

---

## 1. Risk Matrix

| Risk ID | Risk Description | Severity | Probability | Impact | Mitigation Strategy |
|---|---|---|---|---|---|
| **RSK-DD-01** | **Performance Degradation in Recent Attempts Query:** Large datasets in `test_attempts` and `practice_sessions` causing slow query response or high CPU usage. | High | Low | Medium | Ensure existing indexes on `(user_id, status)` and `(user_id, test_id)` are utilized. Limit query bounds (`min:1, max:20`). Exclude unbounded full-table scans. |
| **RSK-DD-02** | **Mock Test Quota Confusion:** Students expecting mock tests to share formal test weekly quotas or vice versa. | Medium | Medium | Low | Distinct `exam_type` (`'mock'` vs `'formal_test'`) in schema and quota tables. Explicit error messages specifying exam type when quota is exhausted. |
| **RSK-DD-03** | **Classroom Test Boundary Breach:** Teacher-assigned tests inadvertently querying or consuming student freemium quotas. | Critical | Very Low | High | Strict physical separation of code paths: `StudentClassTestController` / `StudentClassTestService` operate solely on `TeacherClassTestAssignment` tables and have zero calls to `FreemiumQuotaConsumptionService`. |
| **RSK-DD-04** | **Timezone Discrepancies in Recent Attempt Sorting:** Mixing local server timestamps and UTC ISO-8601 strings causing incorrect chronological order. | Medium | Low | Low | Standardize all timestamp parsing and comparison via `Carbon::parse(...)->utc()->timestamp`. |
| **RSK-DD-05** | **Regression to Pre-existing Features:** Changes to `TestController` breaking existing D.A, D.B, or D.C test suites. | High | Low | High | Automated continuous testing of all 263 existing unit and feature tests before and after any code modification. |
| **RSK-DD-06** | **Unauthenticated or Cross-Role Information Disclosure:** Non-students querying recent attempts. | High | Medium | Medium | Add strict `assertActiveStudent` guard in `getRecentAttemptsForLms` returning HTTP 403. |
