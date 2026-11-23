# Stage 1E.2.D.D.6 Planning Report — Quota Allowance API Merging

**Document Reference:** `docs/freemium/phase-1e-2-d/PHASE_1E_2_D_D_6_PLANNING_REPORT.md`  
**Execution Timestamp:** 2026-11-19T21:35:00+05:00 (UTC 16:35:00)  
**Target Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Target Branch:** `main`  
**Stage:** 1E.2.D.D.6 — Planning and Source Inspection ONLY  
**Status:** COMPLETE (Planning Only — No Modifications Applied)

---

## 1. Objective & Mandate

This planning exercise addresses the second mandatory condition established in the Stage 1E.2.D.D.4 independent audit:
> **Condition 2:** The student quota allowance API (`GET /web_api/auth/freemium/quota-allowances`) must merge campaign-based quota coverage with legacy-free fallback allowances for any assessment types not covered by active campaigns.

In accordance with strict prompt instructions, this stage is **read-only inspection and technical planning only**. No application code, database records, tests, routes, or configurations have been altered.

---

## 2. Source Inspection Findings

1. **Endpoint & Controller:**
   - Route `GET /web_api/auth/freemium/quota-allowances` is defined in `routes/web_api.php:321`.
   - Handled by `StudentFreemiumQuotaController::quotaAllowances` in `app/Http/Controllers/Api/Student/Freemium/StudentFreemiumQuotaController.php:30-57`.
   - Strictly validates `role === 'student'` and `status === 'active'`.
   - Delegates directly to `FreemiumQuotaConsumptionService::getStudentQuotaAllowances($student, $examType)`.
2. **Current Service Logic:**
   - In `FreemiumQuotaConsumptionService.php:596-704`:
     - Resolves participating campaigns.
     - Fetches campaign exam quotas.
     - If no campaigns or quotas exist, calls `getLegacyFallbackAllowances(...)`.
     - **Defect Location:** If campaign quotas exist for a subset of exam types (e.g., `formal_test` only), it loops **only** over the exam types in `$quotas` (`$examTypes = $quotas->pluck('exam_type')->unique();`).
     - It completely skips unrepresented exam types (`mock`, `practice`), leaving them omitted from the response array.
3. **Legacy Fallback Logic:**
   - In `FreemiumQuotaConsumptionService.php:714-765`:
     - `getLegacyFallbackAllowances()` computes weekly quotas for `formal_test` (1), `mock` (1), and `practice` (2).
     - Accurately uses Pakistan weekly time boundaries (`Asia/Karachi`, `PK:legacy:weekly:YYYY-WW`).
     - Correctly queries `FreemiumQuotaCounter`.
     - Marks `'provider' => 'legacy_fallback'`.

---

## 3. Key Design Decisions

1. **Strict Mutual Exclusivity (No Double-Counting):**
   - For each of the three standard assessment types (`formal_test`, `mock`, `practice`):
     - If the student's active campaign covers it $\rightarrow$ return the campaign allowance (`provider: 'campaign'`).
     - If no active campaign covers it $\rightarrow$ return the legacy fallback allowance (`provider: 'legacy_fallback'`).
   - A student never receives both campaign and legacy allowances for the same exam type.
2. **Deterministic Ordering:**
   - The returned array is sorted in fixed canonical order: `formal_test` (1), `mock` (2), `practice` (3).
3. **Filtering Consistency:**
   - `?exam_type=formal_test` returns a single-item array with `formal_test` allowance.
   - `?exam_type=mock` returns a single-item array with `mock` allowance (from campaign if covered, else from legacy fallback).
   - Unfiltered request returns all 3 assessment types with merged coverage.
4. **Independent Paid Representation:**
   - Paid subscriptions grant access at Tier 1 without consuming Freemium quotas.
   - Freemium quota allowances remain independent of paid subscriptions, accurately reporting the student's available free-tier ledger.
5. **No Entity Fabrication:**
   - Legacy allowances do not invent campaign IDs, rule IDs, or grant IDs. They are clearly identified by `'provider' => 'legacy_fallback'`.

---

## 4. Deliverables Produced

The following focused planning documents have been produced:
1. [PHASE_1E_2_D_D_6_QUOTA_ALLOWANCE_MERGE_PLAN.md](file:///E:/Al-Faraabi%20Software/3peas_backend/docs/freemium/phase-1e-2-d/PHASE_1E_2_D_D_6_QUOTA_ALLOWANCE_MERGE_PLAN.md) — Complete architectural and technical plan.
2. [PHASE_1E_2_D_D_6_PLANNING_REPORT.md](file:///E:/Al-Faraabi%20Software/3peas_backend/docs/freemium/phase-1e-2-d/PHASE_1E_2_D_D_6_PLANNING_REPORT.md) — High-level summary report.

Corresponding brain artifacts:
1. `PHASE_1E_2_D_D_6_QUOTA_ALLOWANCE_MERGE_PLAN.md`
2. `PHASE_1E_2_D_D_6_PLANNING_REPORT.md`

---

## 5. Scope & Strict Constraint Compliance

- [x] Read-only inspection only.
- [x] No modifications to application code, tests, migrations, routes, or configs.
- [x] No database writes, schema alterations, or seeders.
- [x] All existing uncommitted changes in Git working tree preserved intact.
- [x] Clear implementation roadmap defined for authorization.
- [x] Implementation paused. Awaiting explicit user direction.
