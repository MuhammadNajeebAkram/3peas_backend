# Stage 1E.2.D.D — Paid / Freemium / Legacy-Free Entitlement Behavior Matrix

**Document ID:** `PHASE_1E_2_D_D_ENTITLEMENT_BEHAVIOR_MATRIX`  
**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Stage:** 1E.2.D.D — Planning and Source Inspection ONLY  
**Timestamp:** 2026-11-19  

---

## 1. 4-Tier Entitlement Hierarchy

The Students Times platform evaluates student entitlement in the following strict precedence:
1. **Tier 1: Paid Active Subscription:** Student holds an active paid subscription covering the requested program or subject.
2. **Tier 2: Explicit Freemium Campaign Grant:** Student has an active grant under a running Freemium campaign with defined exam quotas.
3. **Tier 3: Legacy-Free Fallback Policy:** Student has no active campaign grant, but system fallback configuration permits basic free access.
4. **Tier 4: Denied / Unauthorized:** Student account is inactive, suspended, has expired subscriptions, or fallback access is disabled.

---

## 2. Entitlement Matrix Across Assessment Types & History

| Assessment & Feature | Tier 1: Paid Subscription | Tier 2: Freemium Campaign | Tier 3: Legacy-Free Fallback | Tier 4: Denied / Inactive |
|---|---|---|---|---|
| **Mock Tests (`test_mode = 'mock'`)** | **Unlimited** generation and attempts within active paid program/subject scope. 0 freemium quota consumed. | Governed by campaign `exam_type = 'mock'` weekly quota. Retakes governed by campaign `allow_retakes` and `max_attempts_per_test`. | Governed by `config('freemium.fallback_quotas.mock', 1)`. Retakes denied by default unless configured. | **DENIED (HTTP 403)** with `QUOTA_EXHAUSTED` or `FORBIDDEN`. |
| **Official Shared Tests (`test_source = 'official'`)** | **Unlimited** attempts and retakes. 0 freemium quota consumed. | 0 generation quota consumed. Retakes governed by campaign `allow_retakes` and `max_attempts_per_test`. | 0 generation quota consumed. 1 initial attempt allowed. Retakes denied by default. | **DENIED (HTTP 403)** if attempt limit exceeded. |
| **Student Formal Tests (`test_source = 'student'`)** | **Unlimited** generation and attempts within paid scope. | Generation consumes 1 weekly `formal_test` quota. Retakes governed by campaign policy. | Generation consumes 1 weekly fallback `formal_test` quota. 1 attempt allowed. | **DENIED (HTTP 403)** if generation quota or attempt limit exhausted. |
| **Practice Sessions (`practice_sessions`)** | **Unlimited** generation and sessions within paid scope. 0 freemium quota consumed. | Generation consumes 1 weekly `practice` quota. Active session can be resumed without quota consumption. | Generation consumes 1 weekly fallback `practice` quota. Resumption allowed. | **DENIED (HTTP 403)** if practice quota exhausted. |
| **Recent Attempts API (`/tests/get-recent-attempts`)** | **Full Access:** Returns all submitted formal tests and practice sessions for the student. | **Full Access:** Returns all submitted formal tests and practice sessions for the student. | **Full Access:** Returns all submitted formal tests and practice sessions for the student. | **DENIED (HTTP 403)** if not active student (`role !== 'student'`). |
| **Test Eligibility API (`/freemium/tests/{id}/eligibility`)** | Returns `can_attempt: true`, `is_paid: true`, `remaining_attempts: null`. | Returns `can_attempt: bool`, campaign retake rules, and remaining attempts. | Returns `can_attempt: bool`, fallback retake rules, and remaining attempts. | Returns `can_attempt: false` or HTTP 404 for unowned tests. |
| **Teacher Class Tests (`/student/tests`)** | **Full Access** if enrolled in teacher class. 0 freemium quota consumed. | **Full Access** if enrolled in teacher class. 0 freemium quota consumed. | **Full Access** if enrolled in teacher class. 0 freemium quota consumed. | **DENIED (HTTP 403)** if not enrolled or teacher test closed. |

---

## 3. Paid Student Boundary Isolation

1. **Scoped Exemption:**
   - A paid subscription only exempts assessments within the subscribed `offered_program_id` or `subject_id`.
   - If a student attempts an assessment outside their subscribed program or subject, they automatically fall back to **Tier 2 (Freemium Campaign)** or **Tier 3 (Legacy-Free Fallback)**.
2. **Zero Quota Ledger Pollution:**
   - When a paid student generates or attempts a test in their paid scope, no rows are inserted into `freemium_quota_consumptions` or `freemium_quota_counters`.
   - Their score, answers, and analytics are persisted exclusively in `test_attempts` and `practice_sessions`.
3. **Seamless Shared Assessment Support:**
   - On official shared tests (`test_source = 'official'`), paid students can start attempts and retakes without depleting shared or freemium quotas.
   - Sequential attempt numbers (`1, 2, 3...`) are tracked directly via `COUNT(*) FROM test_attempts WHERE test_id = ? AND user_id = ?`.
