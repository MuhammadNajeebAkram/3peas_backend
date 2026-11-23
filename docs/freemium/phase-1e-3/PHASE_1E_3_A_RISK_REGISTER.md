# Stage 1E.3.A — Risk Register & Mitigation Strategy

**Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_RISK_REGISTER.md`  
**Execution Date:** 2026-11-20  
**Target Systems:** Student LMS Next.js (`al-faraabi-website`) & Laravel Backend (`3peas_backend`)  
**Status:** Planning & Operational Safeguards  

---

## 1. Risk Analysis Matrix

| Risk ID | Risk Description | Severity | Likelihood | Impact Area | Mitigation Strategy | Contingency Plan |
| :---: | :--- | :---: | :---: | :---: | :--- | :--- |
| **R-01** | **Data Normalization Mismatch:** Free resource catalog books lack direct `offered_program_id` required by legacy selectors. | **High** | **Medium** | Program / Subject Dropdowns | `mergeFreemiumPrograms()` creates a synthetic program container grouping free books by Class and Board (e.g., *"Free Catalogue: Grade 11 (Federal Board)"*) with valid subject objects. | If catalog grouping fails, provide a dedicated "Free Catalog" selector tab. |
| **R-02** | **Client/Server Quota Desync:** Student consumes quota in Tab A, then attempts to launch in Tab B before local state updates. | **Medium** | **High** | Test Launch Screen | Client-side quota balance check is strictly UX guidance. The backend is the authoritative enforcement layer. When backend returns `403 Forbidden`, client catches error and opens `QuotaExhaustedDialog`. | On any `403` response, trigger background cache invalidation and re-fetch `/auth/freemium/quota-allowances`. |
| **R-03** | **Timezone Drift:** Student device clock is inaccurate or in a non-Pakistan timezone, causing reset timer discrepancies. | **Medium** | **Medium** | Reset Countdown Timer | Backend computes resets using `Asia/Karachi` and emits canonical UTC ISO-8601 strings (`resets_at`). Client renders relative time countdown (*"Resets in 2 days, 4 hours"*) using server delta, rather than local midnight. | Display explicit notice: *"Quotas reset every Monday at 00:00 Pakistan Standard Time (PKT)"*. |
| **R-04** | **Rapid Double-Submission:** Network latency causes student to click "Submit Test" multiple times, potentially triggering savepoint collisions. | **High** | **Medium** | Test Attempt Submission | 1. Button immediately enters `disabled` state with spinner upon first click.<br>2. Client attaches UUIDv4 `Idempotency-Key` header and payload field. Backend safely replays stored score on repeated requests. | Backend Stage 1E.2.D.D.5 transaction flattening and retry logic already handles concurrent attempts gracefully. |
| **R-05** | **Paid Subscriber UI Regression:** Adding Freemium banners degrades or alters the premium experience for paying subscribers. | **Critical** | **Low** | Paid Student Experience | Check `isPaid` / active paid subscriptions first. If student has paid subscriptions covering the scope, hide Freemium quota counters and display the sleek "Unlimited Student Access" badge. | Run full regression suite `StudentClassSubscriptionAccessTest` against all changes. |
| **R-06** | **Hydration Errors:** Client-side countdown timer or date formatting differs between server render and client hydration. | **Low** | **Medium** | Next.js Page Hydration | Render timer only after component mounts using `useEffect()` and a `mounted` boolean flag, or use dynamic rendering with `ssr: false` for the live timer widget. | Suppress hydration warnings on date elements if unavoidable. |

---

## 2. Rollback & Disaster Recovery Procedures

1. **Zero Backend Modifications:** Stage 1E.3.A touches only `al-faraabi-website`. The backend repository (`3peas_backend`) remains untouched and unmodified.
2. **Git Working Tree Isolation:** All frontend work will be developed in a dedicated Git branch (`feature/freemium-lms-integration`).
3. **Emergency Rollback:** If unexpected regressions occur in production or staging, reverting the feature branch instantly restores the pre-integration state with zero database cleanup or migration rollbacks required.
4. **Kill-Switch Configuration:** An optional environment flag `NEXT_PUBLIC_ENABLE_FREEMIUM_LMS="true"` can be introduced to instantly hide Freemium UI components and fall back to legacy paid-only behavior if needed.
