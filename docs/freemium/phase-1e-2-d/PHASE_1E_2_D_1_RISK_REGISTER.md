# Stage 1E.2.D.1 — Risk Register & Technical Constraints

**Document ID:** `PHASE_1E_2_D_1_RISK_REGISTER`  
**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Phase:** 1E.2.D.1 (Planning and Source Audit — Final Review Corrections)  
**Timestamp:** 2026-11-19  

---

## 1. Overview

This document catalogs technical risks, constraint dependencies, architectural edge cases, and concrete failure scenarios identified during the Stage 1E.2.D.1 source audit and planning review.

---

## 2. Risk Matrix

| Risk ID | Category | Description | Likelihood | Impact | Concrete Failure Scenario & Mitigation |
|---|---|---|:---:|:---:|---|
| **R-01** | Schema Limitation | `test_attempts` table has no `attempt_number` column. | High | Medium | Attempt numbers are authoritatively derived and stored in `freemium_quota_consumptions.attempt_number`. Derived via $\max(\text{prior\_attempts}) + 1$. No column addition needed for self-study. |
| **R-02** | Concurrency Race | Simultaneous test generation requests exhaust quota concurrently. | High | High | Two parallel tabs submit generation concurrently. Mitigated by pessimistic locks on `WebUser` and `FreemiumQuotaCounter` within a single `DB::transaction()`. |
| **R-03** | Partial Failure | Test generation succeeds but quota ledger write fails (or vice versa). | Low | High | Enclosed in an atomic database transaction on the default connection. If ledger fails, test insertion rolls back. |
| **R-04** | Hardcoded Test Mode | `createFormalTestResponse()` hardcodes `'test_mode' => 'test'`, ignoring mock tests. | High | Low | Dynamic student tests are strictly formal tests. Mock tests are official/teacher created assessments. Quota service evaluates `test_mode === 'mock'` when taking existing mock tests. |
| **R-05** | Practice Resume Confusion | Routine progress saves mistaken for session creation. | Medium | High | Progress saving does NOT use generation quota; verified strictly by ensuring session status is `in_progress`. Resuming is non-chargeable; repeat practice consumes 1 of 2 weekly sessions. |
| **R-06** | Paid Subscriber Throttling | A paid subscriber is accidentally subjected to Freemium quota limits. | Low | Critical | `UnifiedEntitlementResolver::resolve()` evaluated first. Paid subscribers bypass Freemium quota consumption entirely. |
| **R-07** | Idempotency Key Reuse | Client sends conflicting request payload with previously used idempotency key. | Medium | Medium | Mitigated via SHA-256 canonical fingerprint matching. Mismatches trigger HTTP 409 Conflict (`IDEMPOTENCY_CONFLICT`). |
| **R-08** | Pre-Existing Failure | `ExampleTest` fails on `Route::has('login')`. | High | None | Pre-existing unrelated test failure. Documented as known and out of scope. |
| **R-09** | Forward Migration Rollback | Restoring original 3-column index `uk_test_attempt_event` fails if multiple students have taken the same shared official test. | High | Critical | The `down()` method must NOT promise unconditional rollback. It executes a fail-closed pre-check query for conflicting duplicate rows and aborts with a clear `RuntimeException` rather than altering or deleting student records. |
| **R-10** | Config Sanitation & Unsafe Casting | Naive `(int)` or `(bool)` casting converts non-numeric strings to `0` (disabling quotas) or `"false"` to `true` (enabling retakes). | Medium | High | Configuration parser strictly validates integer-only positive values, preserves `0` as intentional disablement, rejects ALL negative values (including `-1`), decimals, and non-numeric strings to fallback defaults, and uses strict token validation for boolean retakes. |
| **R-11** | Practice Retake Contradiction | Setting both `allow_retakes = true` and `max_attempts_per_test = 1` creates logical deadlock. | High | Medium | Practice sessions are strictly single-run entities (`allow_retakes = false`, `max_attempts_per_test = 1`). Repeating practice consumes a new session credit from the weekly allowance of 2. |

---

## 3. Product-Owner Policy Decisions (Approved Status)

### Decision 1: Legacy-Free Assessment Quota Policy
- **Status:** **APPROVED by Product Owner.**
- **Policy:**
  - Formal test generation: 1 per week (default, configurable via `config/freemium.php`).
  - Practice session generation: 2 per week (default, configurable via `config/freemium.php`).
  - Weekly window calculated under `Asia/Karachi` timezone with Monday 00:00:00 reset.
  - Zero generation quota consumed on retakes or practice resumption.
  - Practice sessions enforce `allow_retakes = false` and `max_attempts_per_test = 1`.

### Decision 2: Shared Official-Test Ledger Index Correction
- **Status:** **APPROVED by Product Owner.**
- **Policy:**
  - Execute a safe forward migration `2026_12_01_000009_update_freemium_test_attempt_unique_index.php`.
  - Drop `uk_test_attempt_event`.
  - Create `uk_test_user_attempt_event` on `(test_id, user_id, attempt_number, event_type)`.
  - Make `primary_campaign_id` and `primary_quota_id` nullable to support legacy-free consumption without artificial campaigns.
  - Rollback `down()` performs fail-closed conflict validation before attempting index restoration.
