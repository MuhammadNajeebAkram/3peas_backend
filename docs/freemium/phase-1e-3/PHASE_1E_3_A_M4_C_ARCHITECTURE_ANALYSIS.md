# Stage 1E.3.A — Milestone 4 Phase 4C Architecture Analysis
## Frontend Assessment Runner Architecture & State Machine Design (Clarified Revision)

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M4_C_ARCHITECTURE_ANALYSIS.md`
- **Revision Date:** November 20, 2026 (Clarified Revision)
- **Primary Repository:** `E:\Al-Faraabi Software\al-faraabi-website` (Student LMS Frontend)
- **Target File:** `src/components/LMS/tests/TestAttemptScreen.jsx`
- **Scope:** Phase 4C Planning & Source Inspection ONLY

---

## 1. Architectural Overview & Context

`TestAttemptScreen.jsx` executes three distinct assessment modes:
1. **Formal Tests (`test`):** Timed chapter or full-book tests evaluating student academic performance.
2. **Board Mock Exams (`mock`):** High-stakes official board examination simulations with server-regulated duration and strict attempt limits.
3. **Practice Sessions (`practice`):** Interactive learning sessions with immediate answer reveals and explanations.

### Current Architectural Limitations in `TestAttemptScreen.jsx`:
1. **No Preflight Eligibility Gate:** The runner currently assumes that navigating to `/lms/tests/test/${id}` implies permission. If quota has expired or maximum attempts were reached, the user only discovers this on their first answer click.
2. **Missing Attempt Metadata Badging:** The runner does not display the attempt sequence (e.g. `Attempt 1 of 1`, `Retake 2 of 3`) or distinguish mock examination status in the timer header.
3. **Branding & Visual Hierarchy:** Mock examinations currently display generic "Mock Test" text and lack official board styling.
4. **Session Storage Fragility & Recovery Boundary:** If `sessionStorage` is lost (browser reload, direct link, or browser tab restore), line 215 immediately kicks the user back to `/lms/tests`.
   - *Investigation Finding:* The backend currently provides no endpoint to recover in-progress questions or saved answers. The frontend must fail safely with an actionable alert rather than attempting to fabricate questions or faking state.
5. **Submitted Attempt Concurrency:** While the backend handles idempotent submission replays, the frontend does not explicitly catch `ATTEMPT_ALREADY_SUBMITTED` (409) in `saveFormalTestQuestionProgress` to gracefully finalize local state.

---

## 2. Target Component Hierarchy & Data Flow

```
TestAttemptPage (/lms/tests/test/[testId])
  │
  ▼
<TestAttemptScreen attemptType="test" attemptId={testId} />
  │
  ├── 1. Preflight Eligibility Gate (useEffect on mount for attemptType === 'test')
  │      └── freemiumApi.getTestEligibility(attemptId)
  │            ├── Granted (can_attempt = true)
  │            │     ├── Set eligibility state (attemptNumber, isRetake, maxAttempts, isPaid, testMode)
  │            │     └── Store activeAttemptId if has_active_attempt === true
  │            ├── Denied (can_attempt = false OR QUOTA_EXHAUSTED)
  │            │     └── Halt interaction; mount <QuotaExhaustedDialog> or in-place denial alert
  │            └── 404 (Test not found / access denied)
  │                  └── Surface error with "Back to Tests" button
  │
  ├── 2. Sticky Assessment Header (<SectionCard>)
  │      ├── Left: Subject Title, Class Program, Mode Subtitle ("Board Mock Exam" / "Formal Test")
  │      └── Right: Badge Cluster (<Stack direction="row">)
  │            ├── <FreemiumBadge tier={accessTier} isPaid={isPaid} />
  │            ├── <AttemptNumberBadge attemptNumber={...} maxAttempts={...} isRetake={...} mode={...} />
  │            ├── <Chip label={scopeLabel} />
  │            └── <Chip icon={<AccessTimeRoundedIcon />} label={remainingTime} sx={modeColorTokens} />
  │
  ├── 3. Question Stream & Scenario Cluster (<SectionCard>)
  │      ├── Progress Bar (<LinearProgress value={progressPercent} />)
  │      ├── Scenario Passages (clusterScenarioQuestions)
  │      ├── MCQ Options & Radio Selection (handleSelectAnswer)
  │      │     └── persistQuestionProgress() [Forwards active attempt_id]
  │      │           └── On first answer 200 response: captures data.attempt_id for subsequent saves
  │      └── Navigation Controls (Prev, Next, Flag Question)
  │
  └── 4. Submission & Result Transition
         ├── Auto-submit on timer expiry (remainingSeconds === 0)
         ├── Manual submit via "Submit Test" button
         │     └── submitAttempt() -> apiFetch('/auth/tests/submit-formal-test')
         └── Redirection -> /lms/tests/test/${testId}/result
```

---

## 3. Unified Preflight Eligibility Gate (Paid & Freemium)

Inspection of `FreemiumQuotaConsumptionService::getTestAttemptEligibility` confirms that the eligibility response schema is completely uniform across paid and Freemium students.

### Unified Frontend Handler:
```javascript
const [eligibility, setEligibility] = useState(null);
const [isCheckingEligibility, setIsCheckingEligibility] = useState(attemptType === "test");
const [eligibilityDenied, setEligibilityDenied] = useState(false);
const [denialReason, setDenialReason] = useState(null);
const [activeAttemptId, setActiveAttemptId] = useState(null);

useEffect(() => {
  if (attemptType !== "test" || !attemptId) return;

  let active = true;
  async function verifyEligibility() {
    try {
      setIsCheckingEligibility(true);
      const res = await getTestEligibility(attemptId);
      if (!active) return;
      setEligibility(res);

      if (res.hasActiveAttempt && res.activeAttemptId) {
        setActiveAttemptId(res.activeAttemptId);
      }

      if (!res.canAttempt && !res.eligible) {
        setEligibilityDenied(true);
        setDenialReason(res.denialReason || "You have reached the maximum allowed attempts for this test.");
      }
    } catch (err) {
      if (!active) return;
      const norm = normalizeFreemiumError(err);
      if (norm.isQuotaExhausted || norm.status === 403) {
        setEligibilityDenied(true);
        setDenialReason(norm.denialReason || norm.message);
      }
    } finally {
      if (active) setIsCheckingEligibility(false);
    }
  }

  verifyEligibility();
  return () => { active = false; };
}, [attemptId, attemptType]);
```

---

## 4. Attempt ID Lifecycle & Propagation

1. **Preflight Identification:** If `getTestEligibility` returns `has_active_attempt = true` with `active_attempt_id`, the runner initializes `activeAttemptId`.
2. **First Question Save:** If `activeAttemptId` was null (initial start), `saveFormalTestQuestionProgress` creates the attempt and returns:
   ```json
   { "success": 1, "data": { "attempt_id": 4052, ... } }
   ```
   The runner dynamically captures `response.data.attempt_id` and sets `activeAttemptId = response.data.attempt_id`.
3. **Subsequent Question Saves:** All subsequent saves pass `attempt_id: activeAttemptId`, ensuring atomic progress updates to the same attempt.
4. **Final Submission:** `submitAttempt()` passes `attempt_id: activeAttemptId` to `POST /auth/tests/submit-formal-test`.

---

## 5. Storage Loss Boundary & Fail-Safe Handling

If a user reloads `/lms/tests/test/[testId]` and `attemptData` is missing from `sessionStorage`:
- **Current Behavior (Bug):** Blindly redirects to `/lms/tests`.
- **Phase 4C Safe Handling:**
  - Execute `getTestEligibility(attemptId)`.
  - If the test was already submitted (`has_active_attempt === false` and `can_attempt === false`), redirect to the results page: `/lms/tests/test/${testId}/result`.
  - If the attempt is active, but question IDs are missing from storage:
    - Because the backend does NOT provide an endpoint to re-fetch in-progress questions or saved answers, the frontend **must not guess or fake questions**.
    - The runner renders an actionable warning card:
      `"Your assessment attempt is active on the server, but question details were cleared from your browser session. Please return to the Tests Hub to launch a fresh attempt or review completed tests."`
      with a prominent button to `"Return to Tests Hub"`.
    - This provides a clean, graceful degradation path without fabricating state.
