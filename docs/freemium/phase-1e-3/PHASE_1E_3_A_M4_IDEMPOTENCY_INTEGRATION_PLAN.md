# Stage 1E.3.A — Milestone 4 Idempotency Integration Plan
## Request Lifecycle, Header Attachment, and Replay Guarantees

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M4_IDEMPOTENCY_INTEGRATION_PLAN.md`
- **Execution Date:** November 20, 2026
- **Target Repository (Primary):** `E:\Al-Faraabi Software\al-faraabi-website` (`thestudentstimes.git`)
- **Backend Reference (Read-Only):** `E:\Al-Faraabi Software\3peas_backend`
- **Status:** Read-Only Architectural Specification

---

## 1. Idempotency Architecture Overview

Idempotency guarantees that an operation can be executed repeatedly without side effects, preventing duplicate test creation, duplicate attempt generation, and duplicate quota deduction under network retries, double clicks, or flaky connectivity.

```
Frontend (TestLaunchScreen)                 Backend (AssessmentIdempotencyService)
       │                                                      │
1. Generate UUIDv4 (createIdempotencyTracker)                 │
       │                                                      │
2. POST /generate-* with X-Idempotency-Key ──────────────────>│
       │                                                      ├─ Validates key format (regex, len <= 100)
       │                                                      ├─ Computes SHA-256 canonical payload hash
       │                                                      ├─ Checks assessment_idempotency_records
       │                                                      │   ├─ Same Key + Same Hash (Completed): REPLAY
       │                                                      │   ├─ Same Key + Different Hash: 409 CONFLICT
       │                                                      │   └─ New Key: Lock 'processing' atomically
       │                                                      ├─ Generates Test & Deducts Quota
       │                                                      └─ Updates record status 'completed'
3. Receive 200 OK (New or Replayed) <─────────────────────────┤
```

---

## 2. Frontend Lifecycle & Header Management

### 2.1 M1 Utility Integration
In `src/lib/freemiumNormalizers.mjs`, Milestone 1 established:
```javascript
export function createIdempotencyTracker() {
  let currentKey = generateIdempotencyKey();
  return {
    getKey: () => currentKey,
    resetKey: () => {
      currentKey = generateIdempotencyKey();
      return currentKey;
    },
  };
}
```

### 2.2 Tracker Scope in `TestLaunchScreen.jsx`
1. **Component Initialization:**
   A single tracker instance is initialized per launch screen mount:
   ```javascript
   const [idempotencyTracker] = useState(() => createIdempotencyTracker());
   ```
2. **Network Request Dispatch:**
   The tracker key is passed as an HTTP header to `apiFetch`:
   ```javascript
   const headers = {
     "X-Idempotency-Key": idempotencyTracker.getKey(),
   };
   const response = await apiFetch(endpoint, {
     method: "POST",
     headers,
     body: JSON.stringify(payload),
   });
   ```
3. **Automatic Replay on Retry:**
   If a network request times out, drops connection, or fails transiently without the user altering parameters, pressing "Start Test" again **preserves the exact same key**.
   The backend recognizes the existing key, verifies the canonical fingerprint, and replays the original generated test record without deducting quota a second time.
4. **Key Reset on Parameter Mutation:**
   If the student changes parameters (e.g. adjusts number of questions, changes time limit, switches scope, or restarts setup), `idempotencyTracker.resetKey()` is immediately invoked. This generates a fresh UUIDv4, representing a distinct logical operation.

---

## 3. Backend Verification & Replay Guarantees

In `AssessmentIdempotencyService.php`:
1. **Format Enforcement:**
   `validateKey($key)` verifies string length `<= 100` and regex `^[A-Za-z0-9_\-:]+$`. UUIDv4 produced by `generateIdempotencyKey()` strictly satisfies this validation.
2. **Canonical Fingerprinting:**
   `canonicalFingerprint(array $payload)` recursively sorts keys via `ksort` and hashes the JSON string with `sha256`. Minor whitespace differences or key ordering in the request cannot alter the fingerprint.
3. **Transactional Atomic Locking:**
   Table `assessment_idempotency_records` enforces a unique composite index `uk_user_op_idempotency` on `(user_id, operation, idempotency_key)`.
   Concurrent requests trying to insert the same key collide at the database engine level (MySQL error `1062`), eliminating race conditions.
4. **Replay vs Conflict Logic:**
   - **Case A (Same Key + Same Payload + Completed):**
     Replays stored `response_payload` with HTTP 200 and `replayed: true`. Quota is NOT decremented.
   - **Case B (Same Key + Different Payload):**
     Throws `IdempotencyConflictException`, returning HTTP 409 `IDEMPOTENCY_CONFLICT`:
     `"Idempotency key '...' was already used with a different request payload."`
   - **Case C (Same Key + In Progress):**
     Returns HTTP 409: `"A request with idempotency key '...' is currently being processed. Please retry shortly."`
   - **Case D (Callback Error):**
     If generation fails (e.g. database error), the `'processing'` record is deleted inside `catch (\Throwable $t)`, ensuring that subsequent retries with that key are not locked out.

---

## 4. Frontend Conflict (409) Handling

In `TestLaunchScreen.jsx`:
```javascript
try {
  ...
} catch (error) {
  const normError = normalizeFreemiumError(error);
  if (normError.isConflict) {
    idempotencyTracker.resetKey();
    setError("A request conflict occurred. A fresh transaction key has been generated. Please try again.");
  } else if (normError.isQuotaExhausted) {
    setQuotaDialogOpen(true);
  } else {
    setError(normError.message);
  }
}
```
If a 409 occurs due to payload mutation or transient lock, the frontend automatically refreshes the key and provides actionable guidance.
