# Stage 1E.3.A — Milestone 3 Source Inventory & Architecture Analysis
## Tests Hub & Free Resource Discovery Integration

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M3_SOURCE_INVENTORY.md`
- **Execution Date:** November 20, 2026
- **Target Repository:** `E:\Al-Faraabi Software\al-faraabi-website` (`thestudentstimes.git`)
- **Backend Reference (Read-Only):** `E:\Al-Faraabi Software\3peas_backend`
- **Framework Stack:** Next.js 16.0.6 (App Router), React 19.2.0, MUI v7, Node Native Test Runner
- **Status:** Read-Only Planning & Architectural Specification

---

## 1. Executive Summary

Milestone 3 focuses on integrating Freemium resource discovery into the Student LMS **Tests Hub** (`/lms/tests`). Prior to this milestone, unsubscribed students navigating to `/lms/tests` encountered an empty state (`"No subscribed program found"`) with disabled selectors, preventing discovery and utilization of their free allowances.

This document inventories all routes, page controllers, client components, API services, and shared data normalizers that participate in or are affected by Milestone 3. It establishes exact file paths, line ranges, current behavior, proposed modifications, and reusability assessments.

---

## 2. Inventory of Target Files & Components

### 2.1 Route & Page Controllers

| Component / File | Type | Current Behavior | Milestone 3 Role & Modifications |
| :--- | :---: | :--- | :--- |
| `src/app/(lms)/lms/(protected)/tests/page.jsx` | Server Component (SSR) | • Fetches only `/auth/get-user-subscribed-programs`.<br>• If empty, returns empty programs and default fallback data.<br>• If programs exist, fetches `/auth/tests/get-recent-attempts?offered_program_id=...&subject_id=...`. | **Primary Server Integration Point:**<br>1. Fetch paid programs via `/auth/get-user-subscribed-programs`.<br>2. Fetch free catalog resources (`/auth/freemium/free-resources?per_page=50`).<br>3. Fetch entitlements (`/auth/freemium/entitlements`).<br>4. Fetch quota allowances (`/auth/freemium/quota-allowances`).<br>5. Combine via `mergeFreemiumPrograms()`.<br>6. Apply synthetic ID protection to recent attempts query.<br>7. Supply `quotas`, `entitlements`, and `isPaid` to client page content. |
| `src/components/LMS/tests/TestsPageContent.jsx` | Client Component (`"use client"`) | • Manages filter state (`classProgram`, `subject`, `scope`, `unitIds`, `mode`).<br>• Calls `/auth/get-user-subscribed-programs` in `recoverPrograms()` if `programs` is empty.<br>• Calls statistics APIs (`weak-topics`, `weak-units`, `performance-overview`, `mcq-stats`).<br>• Fetches units via `/auth/my-classes/get-units/${programId}/${subjectId}`.<br>• Navigates to `/lms/tests/launch?params` on launch. | **Primary Client Orchestrator:**<br>1. Mount `<FreemiumQuotaBanner />` at container apex.<br>2. Update `recoverPrograms()` to fetch and merge free resources if paid programs are empty.<br>3. Apply synthetic ID guard: skip statistics endpoints when `selectedProgram.id <= 0`.<br>4. Route unit fetching to `/get_units_of_book/${bookId}` when `selectedProgram.id <= 0`.<br>5. In `handleLaunch()`, pass `offeredProgramId: 0` and pass explicit `bookId` when launching free resources.<br>6. Provide dynamic quota exhaustion feedback on launch button. |

### 2.2 Reusable Presentation Components

| Component / File | Source Status | Existing Props & Capabilities | Milestone 3 Integration Plan |
| :--- | :---: | :--- | :--- |
| `src/components/LMS/freemium/FreemiumQuotaBanner.jsx` | Accepted in M2 | Props: `quotas`, `isPaid`, `loading`, `onRefresh`, `sx`.<br>Displays 3-column quota grid, paid unlimited card, PKT reset time, countdown. | Mount at top of `TestsPageContent.jsx`. Pass normalized quotas and `isPaid` boolean. Pass client `handleRefreshQuotas` callback. |
| `src/components/LMS/freemium/FreemiumBadge.jsx` | Accepted in M2 | Props: `tier`, `isPaid`, `label`, `size`, `showIcon`, `sx`.<br>Formats `tier1_paid` (indigo), `tier2_freemium` (emerald), `tier3_legacy` (slate). | Render in `TestsFilterBar` or adjacent to Program/Subject selectors to inform student of active access tier for currently selected curriculum. |
| `src/components/LMS/freemium/QuotaExhaustedDialog.jsx` | Accepted in M2 | Props: `open`, `onClose`, `examType`, `resetsAt`, `denialReason`, `upgradeUrl`, `onUpgrade`. | Prepare modal state in `TestsPageContent` to trigger if a student attempts to generate an assessment with zero remaining quota. |
| `src/components/LMS/freemium/AttemptNumberBadge.jsx` | Accepted in M2 | Props: `attemptNumber`, `maxAttempts`, `isRetake`, `mode`, `size`, `sx`. | Reusable in `RecentAttemptsSection` in M3/M6. |

### 2.3 Secondary Tests Hub Child Components

| Component / File | Type | Current Behavior | Milestone 3 Assessment |
| :--- | :---: | :--- | :--- |
| `src/components/LMS/tests/TestsFilterBar.jsx` | Client Component | Dropdowns for Class/Program, Subject, Scope, Unit, Question Type, Mode. | **Zero Redesign:** Reusable as-is. Program and Subject dropdown options are populated automatically from `programs` and `subjectOptions` state managed by `TestsPageContent`. |
| `src/components/LMS/tests/GenerateTestSection.jsx` | Client Component | Input for question count, time limit, and "Generate Test" action button. | **Minor Enhancement:** Add quota balance indicator label and enforce `launchDisabled` when selected mode quota is exhausted. |
| `src/components/LMS/tests/QuestionBankSummary.jsx` | Client Component | Displays accuracy, attempted, correct, wrong stats cards. | **Preserved:** Consumes `summary` object; gracefully displays zeros for free students who have no prior statistics. |
| `src/components/LMS/tests/PerformanceOverviewCard.jsx` | Client Component | Displays 7-day trend, overall accuracy, practice vs test accuracy. | **Preserved:** Displays fallback 0% stats when statistics endpoints are bypassed for free students. |
| `src/components/LMS/tests/WeakAreasCard.jsx` | Client Component | Lists weak topics or units. | **Preserved:** Displays empty list message when weak areas endpoint is bypassed. |
| `src/components/LMS/tests/RecentAttemptsSection.jsx` | Client Component | Displays table of recent attempts. | **Preserved:** In M3, receives recent attempts filtered by `subject_id` without synthetic ID errors. |

### 2.4 API Client & Normalization Layer

| Service / Module | File Location | Accepted M1 Exports | Milestone 3 Role |
| :--- | :--- | :--- | :--- |
| `src/lib/freemiumApi.js` | `src/lib/freemiumApi.js` | `getEntitlements`, `getFreeResources`, `evaluateAccess`, `getQuotaAllowances`, `getTestEligibility`, `mergeFreemiumPrograms`, `sanitizeAccessPayload`. | Consumed by `tests/page.jsx` (server) and `TestsPageContent.jsx` (client) for data fetching and normalization. |
| `src/lib/freemiumNormalizers.mjs` | `src/lib/freemiumNormalizers.mjs` | `normalizeEntitlements`, `normalizeFreeResources`, `normalizeQuotaAllowances`, `normalizeTestEligibility`, `mergeFreemiumPrograms`, `sanitizeAccessPayload`. | Normalizes backend payloads, constructs synthetic program groupings, and isolates negative IDs. |
| `src/lib/lmsData.js` | `src/lib/lmsData.js` | `normalizeSubscribedPrograms` | Preserved intact for paid programs normalization. |

---

## 3. Existing Code Flow Analysis

### 3.1 Unsubscribed Student Experience (Current State)
```
User navigates to /lms/tests
       │
       ▼
tests/page.jsx (SSR)
       │
       ▼
apiFetch("/auth/get-user-subscribed-programs") ──► Returns { success: 1, data: [] }
       │
       ▼
programs = [] ──► selectedProgram = null
       │
       ▼
TestsPageContent.jsx receives empty data
       │
       ▼
Displays: "No subscribed program found", all dropdowns empty, "Generate Test" disabled
```

### 3.2 Target Experience with Milestone 3
```
User navigates to /lms/tests
       │
       ▼
tests/page.jsx (SSR)
       │
       ├─► apiFetch("/auth/get-user-subscribed-programs") ──► []
       ├─► getFreeResources({ per_page: 50 })             ──► [{ book_id: 42, class_name: "Grade 11", subject_name: "Biology" }]
       ├─► getEntitlements()                              ──► { isPaid: false, hasCampaign: false }
       └─► getQuotaAllowances()                           ──► { formalTest: 1, mock: 1, practice: 2 }
       │
       ▼
mergeFreemiumPrograms([], freeResources)
       │
       ▼
programs = [
  {
    id: -1,
    offered_program_id: -1,
    name: "Grade 11 (Free Access)",
    is_paid: false,
    access_tier: "tier3_legacy",
    subjects: [{ id: 5, name: "Biology", book_id: 42, is_paid: false }]
  }
]
       │
       ▼
TestsPageContent.jsx
       │
       ├─► Mounts <FreemiumQuotaBanner quotas={...} isPaid={false} />
       ├─► Populates Class / Program dropdown with "Grade 11 (Free Access)"
       ├─► Populates Subject dropdown with "Biology"
       ├─► Fetches units using /get_units_of_book/42 (bypassing synthetic ID)
       └─► "Generate Test" button is enabled with active quota!
```

---

## 4. Reusability & Non-Destructive Principles

1. **Zero Route Alterations:** The existing protected URL `/lms/tests` remains the canonical route. No new routes or redirection hops are introduced.
2. **Selector Compatibility:** `TestsFilterBar.jsx` operates without schema mutations because `mergeFreemiumPrograms()` produces identical object shapes (`{ id, name, subjects: [{ id, name }] }`).
3. **No Database Schema or Backend Changes:** The backend repository (`3peas_backend`) remains untouched. All integration consumes documented, verified endpoints.
4. **Paid Subscription Invariance:** If a student holds active paid subscriptions, their paid programs take precedence and render identically to current behavior with the addition of the paid unlimited banner.
