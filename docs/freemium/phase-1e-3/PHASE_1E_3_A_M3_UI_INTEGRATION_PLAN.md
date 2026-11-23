# Stage 1E.3.A — Milestone 3 UI Integration Plan
## Tests Hub User Experience & Presentation Architecture

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M3_UI_INTEGRATION_PLAN.md`
- **Execution Date:** November 20, 2026 (Updated with Corrections)
- **Target Repository:** `E:\Al-Faraabi Software\al-faraabi-website` (`thestudentstimes.git`)
- **Backend Reference (Read-Only):** `E:\Al-Faraabi Software\3peas_backend`
- **Status:** Read-Only Planning & Architectural Specification

---

## 1. Overview & Visual Hierarchy

Milestone 3 integrates the accepted Milestone 2 UI components into the Tests Hub (`/lms/tests`) to deliver complete access transparency.

A critical design requirement is **scope-specific entitlement presentation**: paid access applies only to the resources covered by that subscription. If a student who has an active FSC Part 1 subscription selects an uncovered Matric subject, the Tests Hub must not display the unlimited paid banner for that Matric subject; instead, it must render the active Freemium quota allowances.

### Target Tests Hub Layout:
```
┌──────────────────────────────────────────────────────────────────┐
│  Tests Hub Header                                                │
│  "Tests" - "Practice, test yourself and improve performance"     │
├──────────────────────────────────────────────────────────────────┤
│  <FreemiumQuotaBanner isPaid={isCurrentScopePaid} />             │
│  • If Selected Resource is PAID: "⭐ Unlimited Student Access"    │
│  • If Selected Resource is FREE: 3-column cards [Formal][Mock][Pr]│
│  • Reset Bar: "Weekly quotas reset on Monday at 00:00 PKT"        │
├──────────────────────────────────────────────────────────────────┤
│  <TestsFilterBar />                                              │
│  [Class / Program ▼] [Subject ▼] [Scope ▼] [Unit ▼] [Mode ▼]     │
│  • Displays <FreemiumBadge tier={currentAccessTier} />           │
├──────────────────────────────────────────────────────────────────┤
│  <QuestionBankSummary />                                         │
│  • Total MCQs | Attempted | Accuracy                             │
├──────────────────────────────────────────────────────────────────┤
│  <GenerateTestSection />                                         │
│  • Number of Questions | Time Limit | [Generate Test Button]     │
│  • Dynamic quota indicator & disabled tooltip when exhausted     │
├──────────────────────────────────────────────────────────────────┤
│  <PerformanceOverviewCard />  &  <WeakAreasCard />               │
├──────────────────────────────────────────────────────────────────┤
│  <RecentAttemptsSection />                                       │
│  • Displays attempt history filtered by selected subject         │
└──────────────────────────────────────────────────────────────────┘
```

---

## 2. Review of M2 `FreemiumQuotaBanner` Interface

### 2.1 Interface Evaluation
`FreemiumQuotaBanner` was verified in Milestone 2 with the following public interface:
```jsx
export default function FreemiumQuotaBanner({
  quotas = null,
  isPaid = false,
  loading = false,
  onRefresh = null,
  sx = {},
})
```
- **When `isPaid === true`:**
  Renders the high-contrast purple Unlimited card:
  *"Unlimited Student Access — All formal tests, mock exams, and practice sessions are completely unrestricted under your active subscription plan."*
- **When `isPaid === false`:**
  Renders the 3-column quota cards (`formal_test`, `mock`, `practice`) showing remaining weekly counts and the Pakistan Monday 00:00 PKT countdown.

### 2.2 Scope-Specific Interface Adequacy
- **Verdict:** The existing M2 public interface is **100% adequate** for scope-specific presentation and requires **NO code modifications**.
- **Usage Requirement:** The caller (`TestsPageContent.jsx`) must dynamically pass:
  ```jsx
  <FreemiumQuotaBanner
    quotas={quotas}
    isPaid={isCurrentScopePaid}
    loading={quotasLoading}
    onRefresh={handleRefreshQuotas}
    sx={{ mb: 3 }}
  />
  ```
  where `isCurrentScopePaid` is computed from the currently selected program and subject.
- **Result:**
  - Selecting a paid resource immediately toggles the banner to `"Unlimited Student Access"`.
  - Selecting an uncovered Freemium resource immediately toggles the banner to the 3-column quota card grid.
  - Zero false implications of unlimited access across uncovered curriculums.

---

## 3. Dynamic Access Tier Presentation (`FreemiumBadge`)

- **Placement:** Mounted inside `TestsFilterBar` adjacent to the Class/Program selector or in the section header.
- **Fail-Closed Derivation Logic (Mandatory Safety Rule):**
  Missing or invalid entitlement metadata must **NEVER** silently default to an active access tier (`tier3_legacy` or `tier2_freemium`). Doing so would falsely permit test generation on unverified or restricted academic resources. If entitlement metadata is missing, null, or unresolvable, the system must evaluate strictly to `"unavailable"`.
  ```javascript
  const selectedProgram = programs.find((p) => p.name === filters.classProgram);
  const selectedSubject = selectedProgram?.subjects?.find((s) => s.name === filters.subject);

  // Authoritative derivation with strict fail-closed fallback
  const rawTier =
    selectedSubject?.access_tier ||
    selectedProgram?.access_tier ||
    null;

  const currentAccessTier =
    rawTier === "tier1_paid" || rawTier === "tier2_freemium" || rawTier === "tier3_legacy"
      ? rawTier
      : "unavailable";
  ```
- **Tier Semantics:**
  - `tier1_paid`: Indigo chip `"Paid Subscriber"` with star icon.
  - `tier2_freemium`: Emerald chip `"Campaign Free"` with gift card icon.
  - `tier3_legacy`: Slate chip `"Free Access"` with spark icon.
  - `unavailable`: Rose/Neutral warning chip `"Access Unavailable"` with lock icon. When tier is `"unavailable"`, test generation is disabled and student is prompted to contact support or select an authorized resource.
- **Accessibility:** Declares `role="status"` and `aria-label="Access Tier: {label}"`.

---

## 4. Quota-Aware Test Generation & Navigation Safety

### 4.1 "Generate Test" Action Button Enforcing Quotas & Authorization
In `GenerateTestSection.jsx`:
- **When `currentAccessTier === "unavailable"`:**
  Button is strictly disabled: `disabled={true}`.
  Helper text displays: `"This resource is currently unavailable for test generation under your active entitlements."`
- **When `isCurrentScopePaid === true`:**
  Button is enabled (unless zero questions configured or zero chapters selected). No weekly quota constraints apply.
- **When `isCurrentScopePaid === false` (Freemium or Legacy tier):**
  Button inspects remaining allowance for `filters.mode`:
  ```javascript
  const currentQuota =
    filters.mode === "mock"
      ? quotas?.mock
      : filters.mode === "test"
        ? quotas?.formalTest
        : quotas?.practice;

  const isModeExhausted = Boolean(
    currentQuota &&
    !currentQuota.isUnlimited &&
    (currentQuota.isDisabled || (currentQuota.remainingTests !== null && currentQuota.remainingTests <= 0))
  );
  ```
  If `isModeExhausted === true`:
  - Button enters `disabled={true}`.
  - Displays assistive helper text: `"Weekly {formal test / mock} quota reached. Resets Monday at 00:00 PKT."`
  - Clicking triggers `QuotaExhaustedDialog`.

---

### 4.2 Test Launch Navigation (`handleLaunch`)
When transitioning to `/lms/tests/launch`:
```javascript
const handleLaunch = () => {
  const selectedProgram = programs.find((p) => p.name === filters.classProgram);
  const selectedSubject = selectedProgram?.subjects?.find((s) => s.name === filters.subject);
  const selectedUnits = chapterOptions.filter((u) => filters.unitIds.includes(u.id));

  const fallbackBookId = selectedSubject?.book_id || chapterOptions[0]?.bookId || 0;
  const bookId = Number(fallbackBookId);

  const params = new URLSearchParams({
    mode: filters.mode,
    scope: filters.scope,
    subjectId: String(selectedSubject.id),
    classProgram: filters.classProgram,
    subject: filters.subject,
    questionType: filters.questionType,
    numberOfQuestions: String(generator.numberOfQuestions),
    timeLimit: String(generator.timeLimit),
  });

  // Strict Synthetic ID Rule: include offeredProgramId ONLY if positive integer.
  // Never send negative numbers; never send 0. Omit entirely otherwise.
  if (selectedProgram?.id && Number(selectedProgram.id) > 0) {
    params.set("offeredProgramId", String(selectedProgram.id));
  }

  // Always supply genuine bookId when available
  if (bookId > 0) {
    params.set("bookId", String(bookId));
  }

  selectedUnits.forEach((unit) => {
    params.append("unitId", String(unit.id));
    params.append("unitLabel", unit.label);
  });

  router.push(`/lms/tests/launch?${params.toString()}`);
};
```

---

## 5. Responsive Layout & Accessibility Standards

- **Desktop (>= 1200px):** 3-column quota grid, horizontal filter bar.
- **Tablet (768px – 1199px):** 2-column stacked quota grid, 2-row filter bar.
- **Mobile (< 768px):** Single-column stacked quota cards (`xs: 12`), full-width stacked filter selectors.
- **Accessibility:**
  - High-contrast color tokens adhering to WCAG AAA standards.
  - Quota cards bind `role="region"` and descriptive `aria-label`.
  - Reset countdown chip declares `aria-live="polite"`.
  - Modal dialog traps focus and supports `Escape` key dismissal.
