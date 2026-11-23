# Stage 1E.2.D.B — Risk Register

**Document ID:** `PHASE_1E_2_D_B_RISK_REGISTER`  
**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Stage:** 1E.2.D.B — Shared Official-Test Uniqueness, Attempt & Retake Accounting (Planning Mode)  
**Timestamp:** 2026-11-19  

---

## 1. Risk Matrix

| Risk ID | Risk Description | Severity | Likelihood | Impact | Mitigation Strategy |
|---|---|:---:|:---:|---|---|
| **RSK-B01** | Multi-student collision on shared official tests | **HIGH** | **HIGH** | Second student attempting any shared official test crashes with MySQL Error 1062 on `uk_test_attempt_event`. | Forward migration `000011` replacing 3-column index with student-scoped 4-column index `(test_id, user_id, attempt_number, event_type)`. |
| **RSK-B02** | Cross-student test progress tampering | **HIGH** | **MEDIUM** | Student A enumerates Student B's custom test ID and saves progress or grades questions on it. | Integrate authoritative test ownership check in `TestController`: reject unowned non-official tests with safe HTTP 404. |
| **RSK-B03** | Concurrent double-click creates duplicate attempts | **MEDIUM** | **MEDIUM** | Student double-clicks start, causing two parallel `TestAttempt` rows to be created. | Wrap attempt initialization in `DB::transaction()` with pessimistic student row lock `WebUser::lockForUpdate()`. |
| **RSK-B04** | Progress autosave consumes multiple attempt credits | **HIGH** | **MEDIUM** | Saving answers for multiple questions in a single test burns attempt allowances. | Explicitly check for active `status = 'in_progress'` attempt before creating a new attempt. Reuse active attempt without extra ledger rows. |
| **RSK-B05** | Late autosave corrupts submitted attempt | **MEDIUM** | **MEDIUM** | Autosave network packet arrives after student clicked submit, overwriting finalized answers and score. | In `saveFormalTestQuestionProgressForLms`, reject updates on attempts where `status === 'submitted'` with HTTP 409 Conflict. |
| **RSK-B06** | Rollback of migration 000011 causes data corruption | **HIGH** | **LOW** | Rolling back 000011 after multiple students attempted an official test fails or causes duplicate key errors. | Implement fail-closed `down()` method: verify count of multi-student official attempts; throw `RuntimeException` if any exist. |
| **RSK-B07** | Paid students burdened with Freemium attempt accounting | **MEDIUM** | **LOW** | Paid students get blocked when attempt limits are evaluated. | Explicit `if ($decision->isPaid())` branch: bypass Freemium quota evaluation and skip ledger row insertion for paid students. |
