# Stage 1E.3.A — Milestone 4 Frontend UI Integration Plan
## Launch Screen, Attempt Runner, and Results Presentation

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M4_UI_INTEGRATION_PLAN.md`
- **Execution Date:** November 20, 2026
- **Target Repository (Primary):** `E:\Al-Faraabi Software\al-faraabi-website` (`thestudentstimes.git`)
- **Backend Reference (Read-Only):** `E:\Al-Faraabi Software\3peas_backend`
- **Status:** Read-Only Architectural Specification

---

## 1. Overview & UI Touchpoints

Milestone 4 integrates the M1 API client, M1 idempotency trackers, and M2 UI components into the actual assessment screens:
1. `TestLaunchScreen.jsx` (Launch screen)
2. `TestAttemptScreen.jsx` (Attempt runner)
3. `TestResultScreen.jsx` (Results screen)

---

## 2. Detailed Screen Specifications

### 2.1 `TestLaunchScreen.jsx` Integration

#### Component Hierarchy & Mounting
```jsx
<Box>
  {/* Header with Title and Badges */}
  <Stack direction="row" justifyContent="space-between" alignItems="center">
    <Typography variant="h4">{modeLabel} Launch</Typography>
    <Stack direction="row" spacing={1}>
      <FreemiumBadge tier={accessTier} />
      {isRetakeFlow && (
        <AttemptNumberBadge
          attemptNumber={eligibility?.attempt_number || 1}
          isRetake={eligibility?.is_retake || false}
        />
      )}
    </Stack>
  </Stack>

  {/* Summary Cards, Scope, Instructions */}
  ...

  {/* Action Section */}
  <Button
    onClick={handleStart}
    disabled={!confirmed || isSubmitting || (eligibility && !eligibility.can_attempt)}
  >
    {isSubmitting ? "Starting..." : "Start Test"}
  </Button>

  {/* Quota Exhausted Modal Dialog */}
  <QuotaExhaustedDialog
    open={quotaDialogOpen}
    onClose={() => setQuotaDialogOpen(false)}
    quota={currentQuota}
    examType={resolvedExamType}
  />
</Box>
```

#### State & Integration Logic:
1. **Idempotency Tracker:**
   ```javascript
   const [idempotencyTracker] = useState(() => createIdempotencyTracker());
   ```
2. **Synthetic ID Sanitization:**
   ```javascript
   const rawPayload = {
     offered_program_id: config.offeredProgramId > 0 ? config.offeredProgramId : undefined,
     subject_id: config.subjectId,
     book_id: config.bookId > 0 ? config.bookId : undefined,
     total_questions: config.questionCount,
     time_limit_minutes: config.timeLimit,
     requested_mode: config.mode,
     mcq_format: config.questionType,
   };
   const payload = sanitizeAccessPayload(rawPayload);
   ```
3. **Execution with Idempotency Key Header:**
   ```javascript
   const response = await apiFetch(endpoint, {
     method: "POST",
     headers: {
       "X-Idempotency-Key": idempotencyTracker.getKey(),
     },
     body: JSON.stringify(payload),
   });
   ```
4. **Error Interception:**
   ```javascript
   try {
     ...
   } catch (err) {
     const norm = normalizeFreemiumError(err);
     if (norm.isQuotaExhausted) {
       setQuotaDialogOpen(true);
     } else if (norm.isConflict) {
       idempotencyTracker.resetKey();
       setError("A transaction conflict occurred. Please retry.");
     } else {
       setError(norm.message);
     }
   }
   ```

---

### 2.2 `TestAttemptScreen.jsx` Integration

#### Component Enhancements:
1. **Header Status Badges:**
   - Display `<AttemptNumberBadge attemptNumber={attemptNumber} isRetake={isRetake} />` directly beside the countdown timer.
   - For practice mode: display "Practice Mode" chip.
   - For mock mode: display "Mock Exam" chip with badge tone `warning`.
2. **Session Storage Fallback & Hydration:**
   - If `attemptData` is missing from `sessionStorage`, rather than immediately redirecting, check for an active attempt via `getTestEligibility(testId)`. If an active attempt exists, resume it.
3. **Graceful Submission Conflict Handling:**
   - When calling `/submit-formal-test`, if the backend returns 409 `ATTEMPT_ALREADY_SUBMITTED`, treat as successful submission and proceed to `/result` without erroring.

---

### 2.3 `TestResultScreen.jsx` Integration

#### Component Enhancements:
1. **Attempt Summary Header:**
   - Mount `<AttemptNumberBadge attemptNumber={result?.attempt_number || 1} />` alongside accuracy and score metrics.
2. **Retake Action Button:**
   - If `eligibility?.allow_retakes && eligibility?.remaining_attempts > 0`:
     - Render "Retake Test" button with `AttemptNumberBadge`.
     - Clicking "Retake Test" navigates to `/lms/tests/launch?mode=...&retake=true`.
   - If retakes not allowed or attempts exhausted:
     - "Retake Test" button is hidden or disabled with informative tooltip.
3. **Return to Tests Hub:**
   - Preserves "Back To Tests" button returning cleanly to `/lms/tests`.
