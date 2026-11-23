# Stage 1E.3.A — Milestone 3 Risk Register & Mitigation Strategy
## Tests Hub & Free Resource Discovery

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M3_RISK_REGISTER.md`
- **Execution Date:** November 20, 2026 (Updated with Mandatory Corrections)
- **Target Systems:** Student LMS Next.js (`al-faraabi-website`) & Laravel Backend (`3peas_backend`)
- **Status:** Read-Only Planning & Operational Safeguards

---

## 1. Risk Analysis Matrix

| Risk ID | Risk Description | Severity | Likelihood | Impact Area | Mitigation Strategy | Contingency Plan |
| :---: | :--- | :---: | :---: | :---: | :--- | :--- |
| **M3-R01** | **Synthetic Negative ID or Zero Leakage:** Synthetic negative ID (`-11`) or zero (`0`) reaches backend validation (`exists:offered_programs,id` or `min:1`), triggering `422 Unprocessable Entity`. | **Critical** | **High** | Tests Hub, Recent Attempts, Launch Screen | Strictly omit `offered_program_id` / `offeredProgramId` when `id <= 0`. Never send negative IDs; never substitute zero. Bypass statistics POST requests. | Parameter sanitizers and automated unit tests assert zero and negative numbers are 100% absent from requests. |
| **M3-R02** | **Scope-Bleed of Paid Access:** Student with paid subscription for one curriculum accesses an uncovered Freemium resource, but UI displays unlimited paid access. | **Critical** | **High** | Entitlement Transparency & Quota Enforcement | Paid status is strictly evaluated on the currently selected academic scope (`selectedProgram.is_paid && selectedSubject.is_paid`). If uncovered, `isPaid=false` is passed to `<FreemiumQuotaBanner />`. | Backend `UnifiedEntitlementResolver` remains sole access authority; will reject exhausted attempts regardless of client presentation. |
| **M3-R03** | **Paid Subscription Degradation:** Merging free resources overrides or pollutes paid subjects with free restrictions. | **Critical** | **Low** | Paid Student Experience | `mergeFreemiumPrograms()` processes paid programs first with `is_paid: true`, and skips any free catalog subject that already exists in a paid program. | TC-PAID-01 and TC-PAID-02 regression tests enforce paid program preservation. |
| **M3-R04** | **SSR Network Fault White-Screen:** Network timeout or backend Freemium error crashes `tests/page.jsx` during server-side rendering. | **High** | **Medium** | Tests Hub SSR | All 4 external API calls are wrapped in `Promise.allSettled()`. If any call fails, fallback defaults are used without throwing unhandled exceptions. | Page renders clean fallback data; client recovery runs on mount. |
| **M3-R05** | **Free Resource Chapter Discovery Failure:** Free catalog subjects cannot load chapters because they lack an `offered_program_id`. | **High** | **Low** | Unit / Chapter Dropdown | When `program.id <= 0`, fetch units via verified endpoint `GET /web_api/get_units_of_book/${bookId}` using authentic `book_id`. | If unit fetch fails, allow "Full Book" practice mode. |
| **M3-R06** | **Statistics Endpoint Crash for Free Students:** `loadWeakAreas()` or `loadPerformanceOverview()` attempts to query student summaries for non-existent program. | **High** | **High** | Weak Areas, Performance Cards | Client controller checks `selectedProgram.id > 0`. If `<= 0`, network requests are bypassed; clean baseline zeros are displayed. | Catch blocks in client controller silently swallow any residual 422 errors and set fallback stats. |
| **M3-R07** | **Client/Server Quota Desync:** Student consumes quota in another tab, but Tests Hub displays stale remaining count. | **Medium** | **High** | Quota Display, Launch Screen | Mount interactive refresh button on `<FreemiumQuotaBanner onRefresh={...} />`. Authoritative enforcement remains on backend test generation in M4. | Backend rejects exhausted requests with 403; client opens `QuotaExhaustedDialog`. |
| **M3-R08** | **React 19 / Next.js Hydration Mismatch:** Server-rendered countdown or date string disagrees with client clock. | **Low** | **Medium** | Quota Banner Countdown | Initial render displays PKT weekly reset schedule string; dynamic countdown timer activates after client mount via `useEffect()`. | Suppress hydration warnings on date chip if necessary. |
| **M3-R09** | **Missing Entitlement Fail-Open Risk:** Entitlement fields (`access_tier`, `is_paid`) are missing, null, or unrecognized in API response, causing client to mistakenly assume free access. | **High** | **Medium** | Tests Hub, Badge, Action Button | Strict fail-closed policy: any unresolved tier defaults to `"unavailable"`. Generates warning chip and disables "Generate Test" button. | Student is blocked from generating test; must select verified resource. |
| **M3-R10** | **Book Unit Route Authorization Deficiency:** Route `GET /get_units_of_book/{book_id}` is unauthenticated and lacks resource-level authorization. | **High** | **High** | Unit Selector, Catalog Discovery | Frontend treats endpoint response strictly as structural display outline (chapter titles only, no questions). Downstream test generation enforces strict backend authorization via `UnifiedEntitlementResolver`. Documented backend dependency: `GET /auth/freemium/books/{book_id}/units`. | If endpoint fails or returns empty, UI gracefully defaults to Full Book mode without crashing. |

---

## 2. Rollback & Isolation Protocols

1. **Non-Destructive Frontend Edits:** All edits in M3 are confined to:
   - `src/app/(lms)/lms/(protected)/tests/page.jsx`
   - `src/components/LMS/tests/TestsPageContent.jsx`
   - `tests/freemiumTestsHub.test.mjs`
2. **Untouched Backend & Admin Repositories:** Neither `3peas_backend` nor `tst-admin` will receive code changes during Milestone 3.
3. **Safe Fallback Design:** If Freemium data is completely absent or unreachable, `mergeFreemiumPrograms([], [])` returns `[]`, preserving the exact existing behavior of `tests/page.jsx`.
