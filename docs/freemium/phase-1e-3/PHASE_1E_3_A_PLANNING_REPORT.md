# Stage 1E.3.A — Planning Report: Student LMS Freemium Integration

**Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_PLANNING_REPORT.md`  
**Execution Date:** 2026-11-20  
**Target Repository:** `E:\Al-Faraabi Software\al-faraabi-website` (`thestudentstimes.git`)  
**Backend Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Status:** Planning Completed — Awaiting Implementation Authorization  

---

## 1. Executive Summary

Stage 1E.3.A defines the engineering and architectural plan to integrate the existing, verified Laravel Freemium backend APIs into the Student LMS (`al-faraabi-website`). 

The recent Cross-Project Audit identified that while the backend is 100% complete and hardened, the Student LMS currently blocks non-paying students because `tests/page.jsx` only loads paid subscriptions from `/auth/get-user-subscribed-programs`.

This planning phase specifies:
1. **Free Resource Merging:** Normalizing `GET /web_api/auth/freemium/free-resources` and `GET /web_api/auth/freemium/entitlements` into the existing program/subject selection hierarchy, unblocking free students without UI redesign.
2. **Quota Transparency:** Introducing `FreemiumQuotaBanner` consuming `GET /web_api/auth/freemium/quota-allowances` to display real-time formal, mock, and practice quotas with live Monday reset countdowns.
3. **Preflight Eligibility & Retake Protection:** Connecting "Try Again" to `GET /web_api/auth/freemium/tests/{testId}/eligibility` and rendering accurate `attempt_number` badges.
4. **Concurrency & Idempotency Hardening:** Injecting UUIDv4 `Idempotency-Key` headers into test launch and submission requests to prevent double consumption and savepoint collisions.
5. **Zero Disruption to Paid Students:** Preserving all existing paid subscriber workflows with seamless unlimited access badges.

---

## 2. Planning Deliverables Package

The complete planning documentation has been authored in `E:\Al-Faraabi Software\3peas_backend\docs\freemium\phase-1e-3/`:

1. [PHASE_1E_3_A_LMS_SOURCE_INVENTORY.md](file:///E:/Al-Faraabi%20Software/3peas_backend/docs/freemium/phase-1e-3/PHASE_1E_3_A_LMS_SOURCE_INVENTORY.md): Comprehensive inventory of existing routes, components, API clients, and reusability assessments.
2. [PHASE_1E_3_A_API_INTEGRATION_MAP.md](file:///E:/Al-Faraabi%20Software/3peas_backend/docs/freemium/phase-1e-3/PHASE_1E_3_A_API_INTEGRATION_MAP.md): Precise parameter, payload, and response mapping for all 5 Freemium APIs and assessment execution endpoints.
3. [PHASE_1E_3_A_UI_COMPONENT_PLAN.md](file:///E:/Al-Faraabi%20Software/3peas_backend/docs/freemium/phase-1e-3/PHASE_1E_3_A_UI_COMPONENT_PLAN.md): Visual design specifications for new components (`FreemiumQuotaBanner`, `QuotaExhaustedDialog`, `AttemptNumberBadge`) and enhancements to existing components.
4. [PHASE_1E_3_A_IMPLEMENTATION_ROADMAP.md](file:///E:/Al-Faraabi%20Software/3peas_backend/docs/freemium/phase-1e-3/PHASE_1E_3_A_IMPLEMENTATION_ROADMAP.md): 6 sequential, testable milestones with explicit acceptance criteria.
5. [PHASE_1E_3_A_TESTING_PLAN.md](file:///E:/Al-Faraabi%20Software/3peas_backend/docs/freemium/phase-1e-3/PHASE_1E_3_A_TESTING_PLAN.md): Quality assurance protocol covering free, paid, and expired student journeys, edge cases, and regression verifications.
6. [PHASE_1E_3_A_RISK_REGISTER.md](file:///E:/Al-Faraabi%20Software/3peas_backend/docs/freemium/phase-1e-3/PHASE_1E_3_A_RISK_REGISTER.md): Risk matrix, mitigation strategies, and zero-downtime rollback procedures.
7. [PHASE_1E_3_A_PLANNING_REPORT.md](file:///E:/Al-Faraabi%20Software/3peas_backend/docs/freemium/phase-1e-3/PHASE_1E_3_A_PLANNING_REPORT.md): Executive summary and architectural overview.

---

## 3. Implementation Scope & File Modification Summary

### Files to Create in `al-faraabi-website`:
- `src/lib/freemiumApi.js` (Freemium service client, UUIDv4 idempotency generator, program normalizer)
- `src/components/LMS/freemium/FreemiumQuotaBanner.jsx` (Quota cards and reset timer)
- `src/components/LMS/freemium/QuotaExhaustedDialog.jsx` (Modal for 403 quota exhaustion)
- `src/components/LMS/freemium/AttemptNumberBadge.jsx` (Attempt and retake badge)

### Files to Modify in `al-faraabi-website`:
- `src/app/(lms)/lms/(protected)/tests/page.jsx` (Server-side fetch of free resources & quotas)
- `src/components/LMS/tests/TestsPageContent.jsx` (Mount quota banner, handle quota exhaustion)
- `src/components/LMS/tests/GenerateTestSection.jsx` (Quota indicator, button disabled state)
- `src/components/LMS/tests/TestLaunchScreen.jsx` (Attach `Idempotency-Key`, handle 403 modal)
- `src/components/LMS/tests/TestAttemptScreen.jsx` (Render attempt badge, submit idempotency key)
- `src/components/LMS/tests/RecentAttemptsSection.jsx` (Render attempt numbers, retake preflight)
- `src/lib/lmsData.js` (Add `mergeFreemiumPrograms()`)

### Backend Scope:
- **Zero changes required.** The Laravel backend (`3peas_backend`) is complete, hardened, and verified.

---

## 4. Restrictions & Readiness

In accordance with strict read-only planning instructions:
- Zero application code, configurations, or tests were modified during this planning phase.
- All Git working trees remain preserved with existing uncommitted changes intact.
- The planning package is complete and ready for execution upon explicit authorization.
