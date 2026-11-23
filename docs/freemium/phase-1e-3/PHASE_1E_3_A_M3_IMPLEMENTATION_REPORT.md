# Stage 1E.3.A — Milestone 3 Pre-Implementation Planning Report
## Tests Hub & Free Resource Discovery Integration

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M3_IMPLEMENTATION_REPORT.md`
- **Execution Date:** November 20, 2026 (Final Verification & Acceptance Report)
- **Target Repository:** `E:\Al-Faraabi Software\al-faraabi-website` (`thestudentstimes.git`)
- **Backend Reference (Read-Only):** `E:\Al-Faraabi Software\3peas_backend`
- **Admin Panel Reference (Read-Only):** `E:\Al-Faraabi Software\tst-admin`
- **Final Planning Verdict:** **ACCEPT WITH CONDITIONS**

---

## 1. Executive Summary & Verification of Acceptance Conditions

Milestone 3 plans the integration of Freemium resource discovery into the Student LMS **Tests Hub** (`/lms/tests`).

Following the final planning review, three remaining matters were subjected to rigorous read-only source code inspection:
1. **Entitlement fields authenticity and origin** (`program.is_paid`, `subject.is_paid`, `program.access_tier`, `subject.access_tier`).
2. **Book-based unit discovery endpoint authorization and security** (`GET /get_units_of_book/{book_id}`).
3. **Scope-specific paid presentation and M2 component compatibility**.

### Verdict: ACCEPT WITH CONDITIONS

The Milestone 3 plan is **accepted with conditions**. The architecture is sound, safe, and ready for implementation provided the three specific operational conditions documented below are enforced during Milestone 3 implementation.

---

## 2. Condition 1 Verification: Entitlement Fields Truth Table & Origin Trace

### 2.1 Backend Source Trace
We inspected the raw backend controllers in `3peas_backend`:
1. `OfferedProgramController::getUserSubscribedOfferedProgramsForLMS`:
   - Returns ONLY `{ id, name, slug, description, offered_class_id, subjects: [{ id, name, slug, subject, score: 0 }] }`.
   - **Does NOT return `is_paid` or `access_tier`.**
2. `StudentFreemiumAccessController::freeResources`:
   - Returns grouped resources with `'access_tier' => $decision->getProvider()` (`'paid'`, `'freemium'`, `'legacy'`, `'none'`).
3. `StudentFreemiumAccessController::entitlements`:
   - Returns top-level summary with `is_paid: bool`, `covered_program_ids: int[]`.
4. `mergeFreemiumPrograms()` in `src/lib/freemiumNormalizers.mjs`:
   - **Synthesizes** `is_paid: true` and `access_tier: "tier1_paid"` for all items sourced from `paidPrograms` (which queried active `UserSubscription` records).
   - **Normalizes** `is_paid: false` and `access_tier: subject.access_tier || "tier3_legacy"` for free catalog resources.

### 2.2 Entitlement Fields Mapping & Authenticity Table

| Field | Exists in Backend API? | Origin in Frontend | Meaning & Semantics | Missing Value Behavior |
| :--- | :---: | :--- | :--- | :--- |
| `program.is_paid` | **No** (Synthesized) | `mergeFreemiumPrograms()` | `true` if container originates from active paid subscription; `false` if synthetic container. | Defaults to `false` (fail-closed). |
| `subject.is_paid` | **No** (Synthesized) | `mergeFreemiumPrograms()` | `true` if subject is inside paid program; `false` if from free catalog. | Defaults to `false` (fail-closed). |
| `program.access_tier` | **No** (Synthesized) | `mergeFreemiumPrograms()` | Set to `"tier1_paid"` for paid programs; `null` for synthetic containers. | Evaluates to `null`. |
| `subject.access_tier` | **Yes** (Authoritative) | Backend `freeResources` API mapped by normalizer | Authoritative access tier: `"tier1_paid"`, `"tier2_freemium"`, `"tier3_legacy"`. | **Strict Fail-Closed Rule:** If missing/null/unrecognized, MUST evaluate to `"unavailable"` (NEVER default to `tier3_legacy`). |

### 2.3 Strict Fail-Closed Safety Rule
Missing or unverified entitlement information must **NEVER** default to active free access (`tier3_legacy`). If `access_tier` is missing or unrecognized:
- Evaluates to `"unavailable"`.
- `FreemiumBadge` displays warning status (`"Access Unavailable"`).
- "Generate Test" button is disabled.

---

## 3. Condition 2 Verification: Book-Based Unit Endpoint Security Trace

### 3.1 Trace of `GET /get_units_of_book/{book_id}`
We performed a full code trace of the unit discovery endpoint in `3peas_backend`:
- **Route Registration:** `routes/web_api.php#L516`:
  ```php
  Route::get('/get_units_of_book/{book_id}', [UnitsController::class, 'getUnitsOfBook']);
  ```
- **Middleware & Group:** This route is outside the `auth` middleware group. It has **NO authentication middleware** and **NO authorization checks**.
- **Controller Implementation:** `App\Http\Controllers\UnitsController::getUnitsOfBook`:
  ```php
  public function getUnitsOfBook($book_id)
  {
      $units = DB::table('book_unit_tbl')
          ->where('book_id', $book_id)
          ->where('activate', 1)
          ->select(['id', 'unit_name'])
          ->get();
      return response()->json(['success' => 1, 'units' => $units]);
  }
  ```

### 3.2 Security Evaluation & Architectural Boundary
1. **Authorization Status:** This endpoint has **ZERO resource-level authorization** and **ZERO authentication enforcement**. Any request with a numeric `book_id` returns the chapter list.
2. **Data Exposure:** The endpoint returns only structural outline metadata (`id`, `unit_name`). It exposes zero assessment questions, answers, or student test data.
3. **Freemium Assessment Generation:** Actual assessment generation and question retrieval downstream ALWAYS execute through `UnifiedEntitlementResolver` and `StudentFreemiumAccessController::evaluateAccess`, strictly enforcing quota and resource entitlements.
4. **Documented Backend Dependency:**
   - For complete end-to-end authorization, a protected endpoint should be provided: `GET /web_api/auth/freemium/books/{book_id}/units` guarded by `UnifiedEntitlementResolver`.
   - **Milestone 3 Frontend Mitigation:** The frontend treats `/get_units_of_book/{book_id}` strictly as structural catalog outline metadata (display-only). If the endpoint fails, returns 404, or is empty, the UI gracefully falls back to "Full Book" practice mode without crashing.

---

## 4. Condition 3 Verification: Scope-Specific Paid Presentation & M2 Interface

### 4.1 Safe Scope Derivation
In `TestsPageContent.jsx`, paid status is computed strictly for the currently selected resource:
```javascript
const coveredProgramIds = entitlements?.coveredProgramIds || [];
const isCurrentScopePaid = Boolean(
  selectedProgram &&
  selectedProgram.is_paid &&
  selectedSubject &&
  selectedSubject.is_paid &&
  (selectedProgram.id > 0 ? coveredProgramIds.includes(Number(selectedProgram.id)) : false)
);
```
- **Null Safety:** All object accesses use optional chaining (`?.`).
- **Synthetic ID Rejection:** Synthetic IDs (`<= 0`) immediately evaluate to `isCurrentScopePaid = false`.
- **Cross-Curriculum Isolation:** A student with paid FSC Part 1 selecting Matric Biology receives `isCurrentScopePaid = false`.

### 4.2 M2 `FreemiumQuotaBanner` Interface Verification
The M2 component interface was verified:
```jsx
<FreemiumQuotaBanner
  quotas={quotas}
  isPaid={isCurrentScopePaid}
  loading={quotasLoading}
  onRefresh={handleRefreshQuotas}
  sx={{ mb: 3 }}
/>
```
- When `isCurrentScopePaid === true`: Displays purple "⭐ Unlimited Student Access" banner. Quota cards hidden.
- When `isCurrentScopePaid === false`: Displays 3-column quota cards (`formal_test`, `mock`, `practice`) with weekly reset timer.
- **Verdict:** The M2 interface is 100% adequate; zero component code modifications required.

---

## 5. Summary of Acceptance Conditions for Milestone 3 Implementation

To execute Milestone 3 safely, the implementation must adhere to the following 5 conditions:

1. **Condition A (Synthetic ID & Zero Omission):** Never send negative numbers or `0` as `offered_program_id` / `offeredProgramId` in any network query, body, or URL param. Omit the property entirely when no positive backend ID exists.
2. **Condition B (Scope-Specific Paid Derivation):** Paid presentation must be strictly evaluated per selected resource (`isCurrentScopePaid`). Never use a global student-wide boolean.
3. **Condition C (Fail-Closed Entitlement Fallback):** Missing or invalid entitlement metadata must evaluate strictly to `"unavailable"` and disable test generation.
4. **Condition D (Display-Only Unit Discovery):** Treat `/get_units_of_book/{book_id}` as structural catalog outline only; downstream assessment generation must enforce backend authorization.
5. **Condition E (Statistics Call Bypass):** When `selectedProgram.id <= 0`, completely bypass POST calls to `loadWeakAreas`, `loadPerformanceOverview`, and `loadStats`, displaying clean baseline fallbacks.

---

## 6. Exact Files to Modify & Create (Milestone 3 Scope)

### 2.1 Files to Modify (2 files in `al-faraabi-website`)

1. **`src/app/(lms)/lms/(protected)/tests/page.jsx`:**
   - Import `getEntitlements`, `getFreeResources`, `getQuotaAllowances`, and `mergeFreemiumPrograms` from `@/lib/freemiumApi`.
   - Update `getTestsPageData()` to execute parallel `Promise.allSettled` across paid programs, free resources, entitlements, and quota allowances.
   - Combine programs via `mergeFreemiumPrograms()`.
   - Compute initial scope-specific paid flag: `isInitialScopePaid = Boolean(selectedProgram?.is_paid && selectedSubject?.is_paid)`.
   - Apply synthetic ID guard to `get-recent-attempts` query params: include `offered_program_id` ONLY if `Number(selectedProgram.id) > 0`. Never send negative IDs; never send 0; omit entirely otherwise.
   - Pass `quotas`, `entitlements`, and `isPaid: isInitialScopePaid` to `<TestsPageContent data={data} />`.

2. **`src/components/LMS/tests/TestsPageContent.jsx`:**
   - Import `FreemiumQuotaBanner` and `FreemiumBadge` from `@/components/LMS/freemium`.
   - Dynamically compute `isCurrentScopePaid = Boolean(selectedProgram?.is_paid && selectedSubject?.is_paid)`.
   - Mount `<FreemiumQuotaBanner quotas={quotas} isPaid={isCurrentScopePaid} loading={quotasLoading} onRefresh={handleRefreshQuotas} />`.
   - Mount `<FreemiumBadge tier={currentAccessTier} />` adjacent to Class / Program selector.
   - Update client `recoverPrograms()` to fetch and merge free resources if initial programs are empty.
   - Update `loadRecentAttempts`: omit `offered_program_id` if `selectedProgram.id <= 0`.
   - Update `loadChapters`: if `selectedProgram.id > 0`, call `/auth/my-classes/get-units/...`; if `selectedProgram.id <= 0`, call verified endpoint `GET /get_units_of_book/${selectedSubject.book_id}`.
   - Guard statistics endpoints (`loadWeakAreas`, `loadPerformanceOverview`, `loadStats`): bypass POST calls when `selectedProgram.id <= 0` and display clean baseline fallbacks.
   - Update `handleLaunch`: omit `offeredProgramId` entirely when `selectedProgram.id <= 0` (never send 0 or negative numbers), while forwarding authentic `bookId`.
   - Manage quota exhaustion: disable "Generate Test" button with tooltip when `!isCurrentScopePaid` and selected mode quota is exhausted.

### 2.2 New Test File to Create (1 file in `al-faraabi-website`)

3. **`tests/freemiumTestsHub.test.mjs`:**
   - 15 automated unit tests verifying the data pipeline, merging behavior, scope-specific paid evaluation, zero-substitution prevention, synthetic ID omission, chapter discovery diversion, and error degradation.

---

## 3. Implementation Sequence (Upon Authorization)

```
Step 1: Update Server Page Controller
        src/app/(lms)/lms/(protected)/tests/page.jsx
        • Integrate Promise.allSettled for paid + freemium APIs
        • Merge programs via mergeFreemiumPrograms
        • Derive initial scope-specific isPaid
        • Sanitize recent attempts query (omit offered_program_id when <= 0)
                     │
                     ▼
Step 2: Update Client Tests Hub Controller
        src/components/LMS/tests/TestsPageContent.jsx
        • Mount FreemiumQuotaBanner with dynamic isPaid={isCurrentScopePaid}
        • Mount FreemiumBadge with currentAccessTier
        • Apply synthetic ID omission to stats & recent attempts
        • Route free unit discovery to /get_units_of_book/{bookId}
        • Add dynamic quota exhaustion button disabling for free resources
                     │
                     ▼
Step 3: Author Automated Milestone 3 Test Suite
        tests/freemiumTestsHub.test.mjs
        • 15 test scenarios verifying scope-specific paid access,
          zero-substitution safety, book-based units, and edge cases
                     │
                     ▼
Step 4: Execute Verification Protocol
        • node --test tests/freemiumTestsHub.test.mjs
        • node --test tests/freemiumComponents.test.mjs
        • node --test tests/freemiumClient.test.mjs
        • node --test tests/*.test.mjs (full suite)
        • npx eslint (targeted)
                     │
                     ▼
Step 5: Document Completion & Prepare Final Audit
```

---

## 4. Verification & Acceptance Criteria

Milestone 3 will be considered complete when:
1. **Scope-Specific Paid Presentation:** A student with paid access to one curriculum and Freemium access to another sees:
   - "Unlimited Student Access" when selecting their paid curriculum.
   - The 3-column quota allowance grid when selecting their free catalog curriculum.
2. **Zero-Substitution & Synthetic ID Prevention:** Neither negative numbers nor `0` appear as `offered_program_id` / `offeredProgramId` in any network query, body, or URL param.
3. **Unit Discovery:** Chapters for free catalog resources are retrieved successfully from `/get_units_of_book/{bookId}`.
4. **Automated Testing:** All 15 tests in `tests/freemiumTestsHub.test.mjs` pass.
5. **Full Regression:** All existing project test suites continue passing with 100% success.
6. **Code Quality:** ESLint reports 0 errors and 0 warnings.

---

## 5. Strict Authorization Boundary & Stop Condition

This planning correction phase is complete.

In strict adherence to instructions:
- **No application code, tests, routes, or components were modified.**
- **No changes have been made to `3peas_backend` or `tst-admin`.**
- **Existing uncommitted work across both repositories has been preserved intact.**
- **Execution has stopped; awaiting explicit user authorization before beginning Milestone 3 implementation.**
