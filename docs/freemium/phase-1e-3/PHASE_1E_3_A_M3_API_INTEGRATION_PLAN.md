# Stage 1E.3.A — Milestone 3 API Integration Plan
## Tests Hub Freemium API Orchestration

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M3_API_INTEGRATION_PLAN.md`
- **Execution Date:** November 20, 2026 (Final Verification Update)
- **Target Repository:** `E:\Al-Faraabi Software\al-faraabi-website` (`thestudentstimes.git`)
- **Backend Reference (Read-Only):** `E:\Al-Faraabi Software\3peas_backend`
- **Status:** Read-Only Planning & Architectural Specification

---

## 1. Overview & Architectural Strategy

Milestone 3 orchestrates four backend APIs on the server side (`tests/page.jsx`) and client side (`TestsPageContent.jsx`) to produce a unified, resilient learning catalog for both paid subscribers and free students.

### Authoritative Architecture Decisions:
1. **Verified Field Mapping:** Neither `program.is_paid` nor `subject.is_paid` exist in raw backend responses. They are derived in `mergeFreemiumPrograms()` based on whether items originated from active `UserSubscription` records. For free catalog resources, `subject.access_tier` originates authoritatively from the backend `GET /web_api/auth/freemium/free-resources` (`access_tier` = `$decision->getProvider()`).
2. **Missing Entitlement Fail-Closed Safe State:** Missing, null, or invalid entitlement information must **never** default to `tier3_legacy`. It defaults strictly to an explicit `"unavailable"` state where the UI disables test generation and warns the user.
3. **Scope-Specific Paid Entitlement:** Paid subscription status is strictly scoped to the academic resource covered. A student-wide boolean is never used to imply unlimited paid access across all selected programs or subjects.
4. **Strict Synthetic ID Omission:** Negative synthetic program IDs (`id <= 0`) are frontend grouping artifacts only. They are NEVER transmitted to backend endpoints, and `0` is NEVER substituted for a missing program ID. When no valid backend program ID exists, `offered_program_id` is strictly omitted. Genuine identifiers (`book_id`, `subject_id`, `class_id`) are preserved.
5. **Unit Discovery Authorization Dependency:** Backend inspection of `GET /web_api/get_units_of_book/{book_id}` confirms it lacks resource-level authorization. It queries `book_unit_tbl` without verifying student entitlement. A protected backend unit endpoint is documented as a dependency.

---

## 2. Entitlement Fields Verification & Mapping Matrix

The following table provides the authoritative mapping of all fields utilized in Milestone 3, tracing their exact origins from backend database entities to frontend components:

| Field Name | Authoritative Origin in Backend | Representation in Raw API Response | Frontend Normalization Point | Classification | Meaning & Behavioral Impact |
| :--- | :--- | :--- | :--- | :---: | :--- |
| `program.id` | `offered_programs.id` (positive integer) | Present in `GET /auth/get-user-subscribed-programs` | `normalizeSubscribedPrograms()` preserves raw ID. In `mergeFreemiumPrograms()`, free containers receive synthetic `-classId` or `-9999`. | **Authoritative (Paid)** / **Synthetic (Free)** | Genuine positive integer indicates enrolled paid program; negative indicates frontend grouping container only. |
| `program.is_paid` | Non-existent on `offered_programs` table. Implied by presence of active `UserSubscription` record. | **Unavailable** in raw `GET /auth/get-user-subscribed-programs`. | **Derived** in `mergeFreemiumPrograms()`: assigned `true` for items originating from `paidPrograms`, `false` for free containers. | **Derived** | Used by `TestsPageContent` to determine if container originates from a paid subscription. |
| `subject.is_paid` | Implied by `program_subjects` belonging to an active subscribed `OfferedProgram`. | **Unavailable** in raw `GET /auth/get-user-subscribed-programs` and raw `/auth/freemium/free-resources`. | **Derived** in `mergeFreemiumPrograms()`: assigned `true` for subjects in `paidPrograms`, `false` for subjects in free catalog. | **Derived** | Used to derive `isCurrentScopePaid`. Never defaults to true for free resources. |
| `program.access_tier`| Non-existent on `offered_programs` table. | **Unavailable** in raw responses. | **Derived** in `mergeFreemiumPrograms()`: assigned `"tier1_paid"` for paid programs, or highest tier of free group. | **Derived** | UI category label for program container. |
| `subject.access_tier`| Emitted authoritatively by `UnifiedEntitlementResolver::resolve()` as `$decision->getProvider()` (`'paid'`, `'freemium'`, `'legacy'`, `'none'`). | Present in `GET /auth/freemium/free-resources` as `access_tier`. | **Authoritative (Free)**: Mapped in `normalizeFreeResources()`. If missing or invalid, mapped strictly to `"unavailable"`. | **Authoritative (Free)** / **Derived (Paid)** | Dictates badge presentation and quota requirements. |

### 2.1 Missing Entitlement Information Handling
When `access_tier` or entitlement data is missing, incomplete, or invalid:
- **Strict Rule:** It must **NEVER** default to `"tier3_legacy"` or `"tier2_freemium"`.
- **Safe Fallback State:** Mapped to `"unavailable"`.
- **UI Behavior:**
  - `isCurrentScopePaid` evaluates to `false`.
  - Quota banner displays `"Allocation Pending"` or `"Unavailable"`.
  - `GenerateTestSection` disables test launch (`launchDisabled = true`).
  - Helper warning renders: `"Access tier details unavailable. Verification pending."`.

---

## 3. Scope-Specific Paid Access & Quota Authority

### 3.1 Backend Authority Verification
Inspection of `3peas_backend` controllers (`TestController.php#L160-L197`, `PracticeSessionController.php#L153-L205`) verifies that:
1. In the backend, `$decision->isPaid()` bypasses quota consumption (`if (!$decision->isPaid()) { consumeQuota(...) }`).
2. Paid status does NOT mean a student has unlimited access to everything: `UnifiedEntitlementResolver` evaluates `$student` against `$targetContext`. If the target subject is NOT covered by the student's active paid subscription, `$decision->isPaid()` is FALSE, and quota enforcement is strictly applied.
3. Therefore, on the frontend, paid presentation (`isPaid={true}`) must be activated **only** when the currently selected resource is genuinely covered by the student's active paid subscription.

### 3.2 Robust Scope-Specific Derivation (`isCurrentScopePaid`)
In `TestsPageContent.jsx`:
```javascript
// Authoritative entitlement data from getEntitlements():
const coveredProgramIds = entitlements?.coveredProgramIds || [];
const activePaidSubscriptions = entitlements?.activePaidSubscriptions || [];

const selectedProgram = programs.find((p) => p.name === filters.classProgram) || null;
const selectedSubject = selectedProgram?.subjects?.find((s) => s.name === filters.subject) || null;

// Safe, scope-isolated derivation:
const isProgramPaid = Boolean(
  selectedProgram &&
  Number(selectedProgram.id) > 0 &&
  selectedProgram.is_paid === true &&
  (coveredProgramIds.includes(Number(selectedProgram.id)) ||
   activePaidSubscriptions.some((sub) => Number(sub.program_id) === Number(selectedProgram.id)))
);

const isSubjectPaid = Boolean(
  isProgramPaid &&
  selectedSubject &&
  selectedSubject.is_paid === true
);

const isCurrentScopePaid = Boolean(isProgramPaid && isSubjectPaid);
```
- **Scenario A (Paid Program & Subject Selected):** `isCurrentScopePaid === true` $\implies$ `<FreemiumQuotaBanner isPaid={true} quotas={quotas} />` displays "Unlimited Student Access".
- **Scenario B (Freemium Catalog Resource Selected by Paid Student):** `isCurrentScopePaid === false` $\implies$ `<FreemiumQuotaBanner isPaid={false} quotas={quotas} />` displays the 3-column quota cards (`formal_test`, `mock`, `practice`).
- **Scenario C (Missing Selection or Incomplete Data):** `isCurrentScopePaid === false` $\implies$ displays safe quota cards or pending state; "Generate Test" is disabled.

---

## 4. Book-Based Unit Discovery Endpoint Authorization Audit

### 4.1 Detailed Code Trace of `GET /get_units_of_book/{book_id}`
- **Route Registration:**
  ```php
  // routes/web_api.php (Line 516)
  Route::get('/get_units_of_book/{book_id}', [UnitsController::class, 'getUnitsOfBook']);
  ```
- **Enclosing Route Groups & Middleware:**
  - Registered as a top-level route outside the `Route::prefix('auth')->group(...)` block.
  - **Middleware:** None (no JWT auth, no session validation, no role check).
- **Controller Implementation:**
  ```php
  // app/Http/Controllers/UnitsController.php (Lines 43-66)
  public function getUnitsOfBook($book_id){
      try{
          $units = DB::table('book_unit_tbl')
              ->where('book_id', '=', $book_id)
              ->where('activate', '=', 1)
              ->select(['id', 'unit_name'])
              ->get();

          return response()->json([
              'success' => 1,
              'units' => $units
          ]);
      } catch(\Exception $e){
          return response()->json(['success' => 0, 'units' => 'Failed to retrieve units'], 500);
      }
  }
  ```
- **Service/Policy Level Authorization:** None.
- **Resource Entitlement Check:** None. Does not invoke `UnifiedEntitlementResolver`.

### 4.2 Security & Architectural Classification
> [!WARNING]
> **INSUFFICIENTLY PROTECTED FOR FREEMIUM RESOURCE DISCOVERY.**
> Any client can submit an arbitrary `book_id` and receive chapter listings without an entitlement check. While this endpoint exposes only structural chapter titles and not assessment questions, relying on frontend preflight checks (`getFreeResources()`) violates backend authority.

### 4.3 Existing Protected Endpoints vs Required Backend Enhancement
1. **Existing Protected Endpoint:**
   `GET /web_api/auth/my-classes/get-units/{program_id}/{subject_id}` (`UnitsController.php#L282-L355`) enforces full `UnifiedEntitlementResolver` checks, but it strictly requires an existing `OfferedProgram` record. It returns 404 for Freemium catalog resources that lack an `offered_program_id`.
2. **Required Backend Authorization Enhancement (Documented Dependency):**
   A dedicated, protected Freemium unit discovery route is required in the backend:
   ```php
   Route::get('/auth/freemium/books/{book_id}/units', [StudentFreemiumAccessController::class, 'bookUnits']);
   ```
   which resolves `UnifiedEntitlementResolver::resolve($student, ['book_id' => $book_id], 'perm_view')` before returning `BookUnit` records.
3. **Milestone 3 Safe Operational Strategy:**
   - For Milestone 3 planning, until this backend endpoint is implemented:
     - Free catalog resources default to **"Full Book"** scope where chapter selection is not required.
     - If chapter discovery is invoked, client calls `GET /get_units_of_book/{book_id}` as a non-sensitive catalog title listing, with the explicit understanding that authoritative assessment authorization occurs downstream at `TestController::generateFormalTest()` via `UnifiedEntitlementResolver`.

---

## 5. Server-Side Data Fetching Architecture (`tests/page.jsx`)

```javascript
// src/app/(lms)/lms/(protected)/tests/page.jsx

import TestsPageContent from "@/components/LMS/tests/TestsPageContent";
import { apiFetch } from "@/lib/api";
import { normalizeSubscribedPrograms } from "@/lib/lmsData";
import {
  getEntitlements,
  getFreeResources,
  getQuotaAllowances,
  mergeFreemiumPrograms,
} from "@/lib/freemiumApi";
import { cookies } from "next/headers";

export const dynamic = "force-dynamic";

async function getTestsPageData() {
  const fallbackData = buildDefaultData();

  try {
    const cookieHeader = (await cookies()).toString();
    const fetchOptions = { cookieHeader, cache: "no-store" };

    // Parallel fault-tolerant execution
    const [paidSettled, freeSettled, entitlementsSettled, quotasSettled] =
      await Promise.allSettled([
        apiFetch("/auth/get-user-subscribed-programs", fetchOptions),
        getFreeResources({ per_page: 50 }, fetchOptions),
        getEntitlements(fetchOptions),
        getQuotaAllowances(null, fetchOptions),
      ]);

    const paidPrograms =
      paidSettled.status === "fulfilled"
        ? normalizeSubscribedPrograms(paidSettled.value)
        : [];

    const freeResources =
      freeSettled.status === "fulfilled"
        ? freeSettled.value?.items || []
        : [];

    const entitlements =
      entitlementsSettled.status === "fulfilled"
        ? entitlementsSettled.value
        : null;

    const quotas =
      quotasSettled.status === "fulfilled"
        ? quotasSettled.value
        : null;

    // Merge paid and free resources into unified hierarchy
    const programs = mergeFreemiumPrograms(paidPrograms, freeResources);
    const selectedProgram = programs[0] || null;
    const selectedSubject = selectedProgram?.subjects?.[0] || null;

    // Scope-Specific Paid Status for initial selection
    const isInitialScopePaid = Boolean(
      selectedProgram?.is_paid &&
      (selectedSubject ? selectedSubject.is_paid : true)
    );

    let recentAttempts = fallbackData.recentAttempts;

    // Query recent attempts ONLY if genuine positive program ID exists
    if (selectedSubject?.id) {
      try {
        const queryParams = new URLSearchParams({
          subject_id: String(selectedSubject.id),
        });

        // Strict Synthetic ID Rule: include offered_program_id ONLY if positive integer.
        // Never send negative numbers; never send 0. Omit entirely otherwise.
        if (selectedProgram?.id && Number(selectedProgram.id) > 0) {
          queryParams.set("offered_program_id", String(selectedProgram.id));
        }

        const recentAttemptsResponse = await apiFetch(
          `/auth/tests/get-recent-attempts?${queryParams.toString()}`,
          fetchOptions
        );
        recentAttempts = normalizeRecentAttempts(recentAttemptsResponse);
      } catch (err) {
        console.error("Failed to load recent attempts for tests page", err);
      }
    }

    return {
      ...fallbackData,
      programs,
      quotas,
      entitlements,
      isPaid: isInitialScopePaid,
      filters: {
        ...fallbackData.filters,
        classProgram: selectedProgram?.name || "",
        subject: selectedSubject?.name || "",
      },
      options: {
        ...fallbackData.options,
        classPrograms: programs.map((p) => p.name),
        subjects: selectedProgram?.subjects?.map((s) => s.name) || [],
      },
      summary: {
        ...fallbackData.summary,
        title: selectedProgram?.name || fallbackData.summary.title,
        subtitle: selectedSubject?.name || fallbackData.summary.subtitle,
      },
      recentAttempts,
    };
  } catch (error) {
    console.error("Critical failure loading tests page data", error);
    return fallbackData;
  }
}
```
