# Stage 1E.3.A — Milestone 3 Testing & Quality Assurance Plan
## Tests Hub & Free Resource Discovery Verification

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M3_TESTING_PLAN.md`
- **Execution Date:** November 20, 2026 (Updated with Mandatory Corrections)
- **Target Repository:** `E:\Al-Faraabi Software\al-faraabi-website` (`thestudentstimes.git`)
- **Backend Reference (Read-Only):** `E:\Al-Faraabi Software\3peas_backend`
- **Test Runner:** Node.js Native Test Runner (`node:test`, `node:assert/strict`)
- **Status:** Read-Only Planning & Architectural Specification

---

## 1. Overview & Testing Strategy

Milestone 3 verification validates that the Tests Hub (`/lms/tests`) correctly discovers, merges, and presents Freemium resources alongside paid subscriptions without regressions, synthetic ID leaks, zero-substitution violations, or scope-bleed errors.

Testing will be implemented in a dedicated test suite:
`tests/freemiumTestsHub.test.mjs`

---

## 2. Comprehensive Test Matrix

| Test ID | Test Scenario | Input Conditions | Expected Behavior | Invariant Checked |
| :---: | :--- | :--- | :--- | :--- |
| **TH-01** | **Paid-Only Student** | `paidPrograms` contains FSC Pre-Medical.<br>`freeResources` is empty.<br>`entitlements.isPaid = true`. | • Renders paid program and all subjects.<br>• Banner renders "⭐ Unlimited Student Access".<br>• Quota limits hidden.<br>• `offered_program_id` preserved for stats. | Paid workflow non-regression. |
| **TH-02** | **Freemium-Only Student** | `paidPrograms` is `[]`.<br>`freeResources` has Grade 11 Biology.<br>`entitlements.isPaid = false`. | • Grouped into "Grade 11 (Free Access)".<br>• Subject dropdown contains Biology.<br>• Banner displays 3 quota cards.<br>• Synthetic ID <= 0 never reaches backend. | Free discovery operates cleanly. |
| **TH-03** | **Coexistence: Student Selecting Paid Resource** | Student has paid access to FSC Part 1 and free access to Matric Biology.<br>User selects FSC Part 1. | • `isCurrentScopePaid` evaluates to `true`.<br>• Banner displays "⭐ Unlimited Student Access".<br>• Badge displays "Paid Subscriber".<br>• Quota limits are NOT applied to this test. | Scope-specific paid evaluation. |
| **TH-04** | **Coexistence: Student Selecting Free Resource** | Same dual-enrolled student selects Matric Biology (Free catalog). | • `isCurrentScopePaid` evaluates to `false`.<br>• Banner immediately displays 3-column quota grid.<br>• Badge displays "Free Access" or "Campaign Free".<br>• Does NOT imply unlimited paid access for this free resource. | No scope-bleed of paid access. |
| **TH-05** | **Zero Substitution Safety** | Free resource selected (`id = -11`). | • Query parameters to `/auth/tests/get-recent-attempts` omits `offered_program_id` entirely.<br>• **Does NOT send `offered_program_id: 0` or `-11`.**<br>• Sends authentic `subject_id`. | Strict synthetic ID & zero omission. |
| **TH-06** | **Launch Navigation Safety** | Free resource selected (`id = -11`). | • URL parameters to `/lms/tests/launch` omits `offeredProgramId` entirely.<br>• **Does NOT send `offeredProgramId=0` or `-11`.**<br>• Forwards authentic `bookId` and `subjectId`. | Launch parameter safety. |
| **TH-07** | **Verified Book-Based Unit Discovery** | Free resource selected (`id = -11`, `book_id = 42`). | • Calls verified route `/get_units_of_book/42`.<br>• Does NOT call `/auth/my-classes/get-units/-11/5`.<br>• Chapters populate in unit selector with valid `bookId`. | Book-based unit discovery. |
| **TH-08** | **Statistics Network Call Bypass** | Free resource selected (`id = -11`). | • `loadWeakAreas()`, `loadPerformanceOverview()`, and `loadStats()` are bypassed.<br>• Baseline fallbacks rendered without making network calls.<br>• Zero 422 validation errors. | Backend validation compliance. |
| **TH-09** | **Student with Zero Access** | `paidPrograms` is `[]`.<br>`freeResources` is `[]`.<br>`entitlements.isPaid = false`. | • Page renders clean empty state without crashing.<br>• "No subscribed program found" displayed.<br>• Banner displays empty-state callout.<br>• Selectors cleanly disabled. | Graceful empty-state degradation. |
| **TH-10** | **Freemium API Network Outage** | Paid API succeeds.<br>Freemium endpoints reject/timeout. | • `Promise.allSettled` catches error.<br>• Paid student workflows proceed 100% normally.<br>• Zero console crashes or white screens. | Fault tolerance & graceful degradation. |
| **TH-11** | **Paid API Network Outage** | Paid API returns 500 error.<br>Freemium API succeeds. | • Free student can still discover free subjects.<br>• Quota banner renders active allowances.<br>• Free practice generation enabled. | Isolated failure boundaries. |
| **TH-12** | **Quota Display & Exhaustion State** | Free resource selected.<br>Quota: `remaining_tests = 0`, `max_tests = 1`. | • Formal test card displays "0 / 1 remaining" (red).<br>• "Generate Test" button disabled with tooltip.<br>• Clicking triggers `QuotaExhaustedDialog`. | Quota enforcement UX. |
| **TH-13** | **Zero-Quota Disabled Feature State** | Free resource selected.<br>Quota: `max_tests = 0`, `isDisabled = true`. | • Card displays "0 / 0 allowed" ("Feature Disabled").<br>• Button disabled.<br>• Differentiated from consumed quota. | Correct disabled state handling. |
| **TH-14** | **Interactive Quota Refresh** | Student completes test in another tab.<br>Clicks refresh button on banner. | • Invokes `handleRefreshQuotas()`.<br>• Fetches `/auth/freemium/quota-allowances`.<br>• Card values update reactively. | Cache revalidation. |
| **TH-15** | **Pakistan Timezone Schedule Accuracy** | UTC timestamp `2026-11-23T19:00:00Z`. | • Localizes to "Mon, Nov 24, 12:00 AM PKT".<br>• Countdown string reflects server delta.<br>• Reset chip formatted correctly. | Asia/Karachi boundary fidelity. |
| **TH-16** | **Missing Entitlement Fail-Closed** | `subject.access_tier` is `null` or missing.<br>`program.access_tier` is `null` or missing. | • Tier evaluates strictly to `"unavailable"` (NOT `tier3_legacy`).<br>• `FreemiumBadge` renders warning chip.<br>• "Generate Test" button is disabled. | Fail-closed security rule. |
| **TH-17** | **Book Unit Authorization Boundary** | Free catalog resource selected.<br>`book_id = 42`. | • Calls `/get_units_of_book/42` for structural chapter outline.<br>• Treats outline as display-only metadata.<br>• If endpoint returns 404/500/empty, gracefully defaults to Full Book mode without crashing.<br>• Downstream assessment generation retains strict backend authorization. | Unit authorization boundary safety. |

---

## 3. Test Implementation Specifications (`tests/freemiumTestsHub.test.mjs`)

The test suite will use native Node.js assertions (`node:test`) to verify:
1. **Scope-Specific Paid Derivation:**
   - Evaluates `isScopePaid` across paid program + paid subject, paid program + free subject, free container + free subject.
   - Asserts that selecting an uncovered free subject sets `isPaid = false` on `<FreemiumQuotaBanner />`.
2. **Strict Synthetic ID & Zero Omission:**
   - Parameter builders assert:
     - If `id > 0`, `offered_program_id` / `offeredProgramId` is included.
     - If `id <= 0`, `offered_program_id` / `offeredProgramId` is STRICTLY ABSENT from query strings and objects (not `0`, not `null`, not negative).
3. **Unit Discovery Route Mapping:**
   - Asserts that when `id <= 0`, unit request URL resolves to `/get_units_of_book/${bookId}` and extracts valid chapters.
4. **Statistics Bypass:**
   - Asserts that when `id <= 0`, statistics dispatchers resolve with fallback objects and fire 0 network requests.

---

## 4. Verification Protocol

Upon implementation authorization, the verification protocol is:
```bash
# 1. Run Milestone 3 Tests Hub Suite
node --test tests/freemiumTestsHub.test.mjs

# 2. Run Milestone 2 Component Suite
node --test tests/freemiumComponents.test.mjs

# 3. Run Milestone 1 Client Suite
node --test tests/freemiumClient.test.mjs

# 4. Run Full Project Test Suite
node --test tests/*.test.mjs

# 5. Targeted ESLint Linting
npx eslint src/app/\(lms\)/lms/\(protected\)/tests/page.jsx src/components/LMS/tests/TestsPageContent.jsx tests/freemiumTestsHub.test.mjs
```
