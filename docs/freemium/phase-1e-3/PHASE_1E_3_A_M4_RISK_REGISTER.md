# Stage 1E.3.A — Milestone 4 Risk Register & Mitigations
## Risk Assessment for Launch Screen, Server-Side Mode Validation, Idempotency, and Attempt Runners

- **Document Reference:** `docs/freemium/phase-1e-3/PHASE_1E_3_A_M4_RISK_REGISTER.md`
- **Execution Date:** November 20, 2026 (Revised)
- **Target Repository (Primary):** `E:\Al-Faraabi Software\al-faraabi-website` (`thestudentstimes.git`)
- **Backend Reference (Read-Only):** `E:\Al-Faraabi Software\3peas_backend`
- **Status:** Read-Only Architectural Specification (Security-Hardened Revision)

---

## 1. Risk Matrix

| Risk ID | Description | Likelihood | Impact | Severity | Mitigation Strategy |
| :---: | :--- | :---: | :---: | :---: | :--- |
| **R4-01** | **Backend 422 on Synthetic IDs:** `offered_program_id` required rule fails for catalog resources. | High | Critical | **CRITICAL** | Update backend request validation to `'offered_program_id' => ['nullable', 'integer', 'min:1', 'exists:offered_programs,id']`. Frontend sanitizes payloads via `sanitizeAccessPayload`, omitting the field for synthetic IDs. |
| **R4-02** | **Client Mode Tampering / Quota Category Hopping:** Malicious client attempts to pass `requested_mode: "mock"` to formal test endpoints to burn mock quota or evade exhausted formal quota. | High | Critical | **CRITICAL** | Strict endpoint immutability: formal test endpoints (`/generate-*-formal-test`) completely ignore or reject client mode flags and strictly consume `formal_test` quota. Mock generation is restricted to dedicated endpoint (`/generate-full-book-mock-test`). |
| **R4-03** | **Mock Blueprint Distortion:** Student manipulates sliders to create a 5-question or 10-minute "mock exam", corrupting board analytics. | Medium | High | **HIGH** | Server validates all mock generation requests against authoritative Board Mock Blueprints (e.g. 60 questions, 60 minutes). Non-conforming parameters fail with HTTP 422 `INVALID_MOCK_BLUEPRINT`. |
| **R4-04** | **Chapter Mock Injection:** Student attempts to create a mock exam for a single chapter. | Medium | High | **HIGH** | Server enforces comprehensive full-book syllabus coverage for mock tests. Single-chapter requests are rejected with HTTP 422 `INVALID_ASSESSMENT_SCOPE`. |
| **R4-05** | **Double Quota Consumption on Network Retry:** Flaky connectivity causes retry during generation. | High | High | **HIGH** | Backend `AssessmentIdempotencyService` verifies `X-Idempotency-Key` and matching request payload hash, returning cached response without duplicate `consumeQuota()` execution. |
| **R4-06** | **Stale Idempotency Key Conflict (409):** Student modifies questions or time limit, but browser reuses key. | Medium | Medium | **MEDIUM** | Frontend invokes `tracker.resetKey()` on any user parameter change in `TestLaunchScreen`. If 409 is received, tracker resets key and informs user. |
| **R4-07** | **Retake Counting Leakage:** Retakes accidentally consume weekly generation counters. | Medium | High | **HIGH** | In `FreemiumQuotaConsumptionService`, `eventType === 'retake_start'` checks attempt ceilings but skips `used_tests` counter incrementation. |
| **R4-08** | **Race Condition on Parallel Attempts:** Multiple browser tabs start attempts simultaneously. | Medium | High | **HIGH** | Database unique constraint `uk_user_test_attempt` on `(user_id, test_id, attempt_number)` and `WebUser::lockForUpdate()` serialize execution. |
| **R4-09** | **Mobile Session Loss:** Mobile browser unloads tab during active test, losing local progress. | Medium | Medium | **MEDIUM** | Attempt runner queries `getTestEligibility(testId)` and server progress on mount, cleanly restoring active `in_progress` attempt state. |
| **R4-10** | **Legacy Client Regression:** Mobile app or legacy web callers that do not transmit `X-Idempotency-Key` or dedicated mock routes break. | Low | High | **HIGH** | All legacy formal test routes remain fully backward compatible. Idempotency header is treated as optional for legacy callers (auto-generating hash or skipping cache). |

---

## 2. Monitoring & Diagnostic Guidelines

1. **Audit Logs:**
   Every quota consumption, eligibility check, and attempt event writes to `freemium_audit_logs` with `user_id`, `exam_type`, `event_type`, `idempotency_key`, and diagnostic context.
2. **Idempotency Telemetry:**
   `AssessmentIdempotencyRecord` tracks `replayed` events and HTTP status codes, enabling real-time monitoring of retry rates and conflict spikes.
3. **Mode Anomaly Alerting:**
   Any request attempting to inject `requested_mode` into formal endpoints or mismatching blueprints triggers a warning log in Laravel log channel `freemium_security`.
