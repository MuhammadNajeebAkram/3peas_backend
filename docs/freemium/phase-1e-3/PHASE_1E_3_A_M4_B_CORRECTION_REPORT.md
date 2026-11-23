# Stage 1E.3.A — Milestone 4 / Phase 4B Correction Report
## Idempotency Lifecycle and Configuration Fingerprint Corrections

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M4_B_CORRECTION_REPORT.md`
- **Execution Date:** November 20, 2026
- **Target Repository (Primary):** `E:\Al-Faraabi Software\al-faraabi-website` (`thestudentstimes.git`)
- **Backend Reference (Read-Only):** `E:\Al-Faraabi Software\3peas_backend` (`3peas_backend.git`)
- **Status:** Complete & Verified — All Conditions Resolved

---

## 1. Executive Summary

In response to the Phase 4B Independent Read-Only Audit (`PHASE_1E_3_A_M4_B_INDEPENDENT_AUDIT_REPORT.md`), two targeted corrections were implemented in `E:\Al-Faraabi Software\al-faraabi-website` to resolve Condition 1 (HTTP 409 Idempotency Lifecycle) and Condition 2 (Configuration Fingerprint Completeness).

Both corrections were successfully implemented, verified with ESLint (0 errors, 0 warnings), and validated with 82 passing frontend tests (including 29 tests in `freemiumLaunchScreen.test.mjs` explicitly covering both correction behaviors).

All backend source files, routes, configs, migrations, and database records remained strictly read-only and untouched.

---

## 2. Scope & Repository Boundaries

### 2.1 File Changes
- **Modified in `al-faraabi-website`:**
  - `src/lib/freemiumNormalizers.mjs`:
    - Added `computeAssessmentFingerprint(config)` for complete, order-independent configuration fingerprinting.
    - Enhanced `normalizeFreemiumError(error)` to parse and categorize conflict sub-types: `isProcessingConflict` vs `isPayloadMismatchConflict`.
  - `src/lib/freemiumApi.js`:
    - Re-exported `computeAssessmentFingerprint`.
  - `src/components/LMS/tests/TestLaunchScreen.jsx`:
    - Wired `computeAssessmentFingerprint` to `useEffect` drift detector.
    - Updated catch handler to distinguish between in-flight conflicts (preserve key) and payload mismatches (block auto-retry, require deliberate user action).
    - Rendered explicit `"Start Fresh Session (Generate New Key)"` button on payload mismatch.
  - `tests/freemiumLaunchScreen.test.mjs`:
    - Expanded from 20 to 29 automated tests covering all 10 focused test requirements.
- **Read-Only Repositories:**
  - `3peas_backend`: Zero application code or database modifications.
  - `tst-admin`: Clean and untouched.
  - Flutter Mobile Apps: Excluded from scope.

---

## 3. Detailed Implementation of Corrections

### 3.1 Correction A: HTTP 409 Idempotency Lifecycle

#### Background & Vulnerability Addressed
The backend (`AssessmentIdempotencyService.php`) produces HTTP 409 `IDEMPOTENCY_CONFLICT` under two distinct conditions:
1. **In-Flight Processing Lock:** Another request with the same idempotency key is currently executing (`status === 'processing'`).
2. **Payload Mismatch:** The key was previously used with a different request payload fingerprint.

In the previous implementation, any 409 response automatically reset the tracker key. If a student experienced a network timeout on an in-flight request and received 409, resetting the key meant that a subsequent click sent a *new* key while the first operation was still executing on the backend, creating a risk of duplicate test generation and duplicate quota consumption.

#### Solution Implemented
1. **Error Categorization (`freemiumNormalizers.mjs`):**
   ```javascript
   const isConflict = status === 409 || code === "IDEMPOTENCY_CONFLICT";

   const isProcessingConflict =
     isConflict &&
     (/currently being processed/i.test(message) ||
       /in progress/i.test(message) ||
       /processing/i.test(message));

   const isPayloadMismatchConflict =
     isConflict &&
     (/different request payload/i.test(message) ||
       /payload mismatch/i.test(message));
   ```
2. **Lifecycle Differentiation (`TestLaunchScreen.jsx`):**
   - **Case A (In-Flight Processing Conflict):**
     - Preserves the existing idempotency key (`tracker.resetKey()` is NOT called).
     - Does not retry automatically.
     - Displays an informational alert:
       > *"Your test is currently being generated on the server. Please wait a moment and press 'Start Test' again to retrieve your test."*
     - When the student clicks "Start Test" again, the exact same key and identical payload are resent. The backend detects the completed record (`status === 'completed'`) and safely replays the test result (`replayed: true`, HTTP 200) without deducting quota.
   - **Case B (Payload Mismatch Conflict):**
     - Does NOT silently generate a new key and does NOT retry automatically.
     - Sets `conflictType = "payload_mismatch"`.
     - Disables the "Start Test" button with the conflicting key.
     - Displays an error alert:
       > *"This assessment session's key was already used with different settings. To begin a new test, please start a fresh session or return to setup."*
     - Requires the user to deliberately click the `"Start Fresh Session (Generate New Key)"` button or navigate `"Back To Setup"`. Clicking `"Start Fresh Session"` explicitly resets the tracker key, clears the conflict state, and allows clean submission.
   - **Fail-Safe Behavior:**
     - If an unrecognized 409 conflict message is returned, the handler defaults to Case A (preserves key safely rather than guessing).

---

### 3.2 Correction B: Complete Assessment Configuration Fingerprint

#### Background & Drift Addressed
The previous launch screen `useEffect` hook tracked only:
`${config.mode}:${config.scope}:${config.subjectId}:${config.bookId}:${config.questionCount}`
It omitted `config.timeLimit` and `config.unitIds`. If a student modified the time limit or altered the selected chapter units, the fingerprint did not change, and the key was not refreshed. Resubmission then caused a payload hash mismatch on the backend.

#### Solution Implemented
Implemented `computeAssessmentFingerprint(config)` in `freemiumNormalizers.mjs`:
```javascript
export function computeAssessmentFingerprint(config = {}) {
  if (!config || typeof config !== "object") {
    return "";
  }

  const mode = String(config.mode || "practice").trim().toLowerCase();
  const scope = String(config.scope || "full_book").trim().toLowerCase();
  const subjectId = Number(config.subjectId) || 0;
  const bookId = Number(config.bookId) || 0;
  const questionCount = Number(config.questionCount) || 0;
  // In mock mode, time limit is server-authoritative and omitted from payload.
  // In formal/practice mode, client time limit is part of the request payload.
  const timeLimit = mode === "mock" ? 0 : (Number(config.timeLimit) || 0);
  const questionType = String(config.questionType || "mcq").trim().toLowerCase();
  const rawProgId = Number(config.offeredProgramId);
  const progId = Number.isInteger(rawProgId) && rawProgId > 0 ? rawProgId : 0;

  const rawUnits = Array.isArray(config.unitIds) ? config.unitIds : [];
  const stableUnitIds = rawUnits
    .map((u) => Number(u?.id ?? u))
    .filter((id) => Number.isInteger(id) && id > 0)
    .sort((a, b) => a - b)
    .join(",");

  return [
    `m:${mode}`,
    `s:${scope}`,
    `p:${progId}`,
    `sub:${subjectId}`,
    `book:${bookId}`,
    `q:${questionCount}`,
    `t:${timeLimit}`,
    `qt:${questionType}`,
    `u:${stableUnitIds}`,
  ].join("|");
}
```

#### Properties Verified
1. **Time Limit Invariance:** Changing `timeLimit` in formal/practice mode changes `t:30` to `t:45`, changing the fingerprint and generating a new key.
2. **Unit Selection Sensitivity:** Adding or removing chapter units alters `u:101` to `u:101,102`, changing the fingerprint and generating a new key.
3. **Unit Order Independence:** Reordering unit IDs (`[102, 101]` vs `[101, 102]`) sorts numerically to `101,102`, keeping the fingerprint identical and preserving the key.
4. **UI State Isolation:** Toggling checkboxes (`confirmed`), submission states, or modals does not affect the fingerprint and preserves the key.
5. **Retry Stability:** When retrying without parameter changes, the fingerprint remains identical and the same key is reused.

---

## 4. Test Verification & Results

### 4.1 Focused Correction Tests (`tests/freemiumLaunchScreen.test.mjs`)
Expanded test suite contains 29 tests, with dedicated tests for all 10 prompt requirements:

| Test ID | Category | Description | Status |
| :--- | :--- | :--- | :--- |
| **LS-14a** | Correction A | In-flight HTTP 409 preserves the existing key | Pass |
| **LS-14b** | Correction A | Retry after an in-flight 409 reuses the same key and payload | Pass |
| **LS-14c** | Correction A | Payload-mismatch 409 does not automatically retry with a new key | Pass |
| **LS-14d** | Correction A | Payload-mismatch conflict is surfaced to the user with actionable message | Pass |
| **LS-14e** | Correction A | A deliberate new operation receives a fresh UUIDv4 key | Pass |
| **LS-21** | Correction B | Changing `timeLimit` in formal mode changes configuration fingerprint | Pass |
| **LS-22** | Correction B | Changing selected `unitIds` changes configuration fingerprint | Pass |
| **LS-23** | Correction B | Reordering unit IDs produces identical fingerprint (order-independent) | Pass |
| **LS-24** | Correction B | Changing unrelated UI state does not change fingerprint | Pass |
| **LS-25** | Correction B | Existing retry and successful-completion behavior remains correct | Pass |

### 4.2 Full Frontend Regression Suite
Command:
```bash
node --test tests/freemiumClient.test.mjs tests/freemiumComponents.test.mjs tests/freemiumTestsHub.test.mjs tests/freemiumLaunchScreen.test.mjs
```
- **Total Tests:** 82
- **Passed:** 82
- **Failed:** 0
- **Duration:** 179ms

### 4.3 Navigation & Student Tests Non-Regression
Command:
```bash
node --test tests/studentTests.test.mjs tests/lmsNavigation.test.mjs
```
- **Total Tests:** 6
- **Passed:** 6
- **Failed:** 0
- **Duration:** 91ms

### 4.4 Static Analysis / ESLint
Command:
```bash
npx eslint src/components/LMS/tests/TestLaunchScreen.jsx src/lib/freemiumApi.js src/lib/freemiumNormalizers.mjs
```
- **Result:** 0 errors, 0 warnings.

---

## 5. Git Status and Repository Boundaries

### 5.1 `al-faraabi-website` Working Tree Status
```
 M src/app/(lms)/lms/(protected)/tests/page.jsx (M3)
 M src/components/LMS/tests/TestLaunchScreen.jsx (Phase 4B + Corrections)
 M src/components/LMS/tests/TestsPageContent.jsx (M3)
?? src/components/LMS/freemium/ (M2)
?? src/components/LMS/tests/testsHubHelpers.mjs (M3 + 4B)
?? src/components/freemium/ (M2)
?? src/lib/freemiumApi.js (M1 + 4B)
?? src/lib/freemiumNormalizers.mjs (M1 + 4B + Corrections)
?? tests/freemiumClient.test.mjs (M1)
?? tests/freemiumComponents.test.mjs (M2)
?? tests/freemiumLaunchScreen.test.mjs (Phase 4B + Corrections)
?? tests/freemiumTestsHub.test.mjs (M3)
```

### 5.2 Preservation Confirmation
- `3peas_backend`: Application code, routes, configurations, migrations, and database records remain strictly untouched (0 diffs).
- `tst-admin`: Clean and untouched.
- Flutter: Untouched.
- No packages installed, no migrations run, no commits or pushes performed.

---

## 6. Conclusion

Both findings from the Phase 4B independent audit have been completely resolved:
1. In-flight 409 concurrency locks preserve the idempotency key and enable safe, duplicate-free replay on retry.
2. Payload mismatches block automatic submission and require explicit user action.
3. The configuration fingerprint comprehensively covers mode, scope, subjects, books, question count, time limits, and order-independent unit selections.

Phase 4B corrections are complete. Execution is stopped awaiting authorization before beginning Phase 4C.
