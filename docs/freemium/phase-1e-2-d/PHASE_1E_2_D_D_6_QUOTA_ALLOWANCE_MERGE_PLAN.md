# Stage 1E.2.D.D.6 Technical Planning Document — Quota Allowance API Campaign & Legacy Fallback Merging

**Document Reference:** `docs/freemium/phase-1e-2-d/PHASE_1E_2_D_D_6_QUOTA_ALLOWANCE_MERGE_PLAN.md`  
**Creation Date:** 2026-11-19T21:35:00+05:00 (UTC 16:35:00)  
**Target Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Target Branch:** `main`  
**Stage:** 1E.2.D.D.6 — Planning and Source Inspection ONLY  
**Status:** PROPOSED & READY FOR IMPLEMENTATION

---

## 1. Executive Summary & Objective

In Stage 1E.2.D.D.4 (Final Independent Security and Regression Audit), the auditor identified an omission in the student quota allowance API:
> **Finding (Condition 2):** When a student participates in an active Freemium campaign that specifies a quota for a subset of assessment types (e.g., `formal_test` only), an unfiltered query to `GET /web_api/auth/freemium/quota-allowances` returns only `formal_test`. Uncovered assessment types (`mock`, `practice`) are omitted from the array rather than supplemented with legacy-free fallback allowances. However, querying explicitly with `?exam_type=mock` correctly returns the legacy fallback.

Stage 1E.2.D.D.6 designs a clean, backwards-compatible, and tamper-resistant merge mechanism within `FreemiumQuotaConsumptionService::getStudentQuotaAllowances()` to ensure that:
1. Every unfiltered request to `GET /web_api/auth/freemium/quota-allowances` returns complete coverage across all three supported assessment types: `formal_test`, `mock`, and `practice`.
2. Campaign-based quotas take strict precedence for the assessment types they cover.
3. Uncovered assessment types seamlessly fall back to legacy-free allowances without double-counting.
4. Legacy allowances are clearly distinguished via `'provider' => 'legacy_fallback'` and never masquerade as campaign grants.
5. The API contract, response wrappers, and filtering capabilities remain fully compatible with existing and future frontend consumers.

---

## 2. Current Architecture & Exact Source Locations

### A. Endpoint & Routing
- **Route:** `GET /web_api/auth/freemium/quota-allowances`
- **Definition:** `routes/web_api.php:321`
- **Middleware:** `auth:web_api`, requiring an authenticated `WebUser` with `role === 'student'` and `status === 'active'`.

### B. Controller
- **File:** `app/Http/Controllers/Api/Student/Freemium/StudentFreemiumQuotaController.php`
- **Method:** `quotaAllowances(Request $request): JsonResponse` (lines 30–57)
- **Role:**
  - Validates active student session (HTTP 403 on invalid role/status).
  - Validates optional `exam_type` query parameter (`'practice'`, `'formal_test'`, `'mock'`, returning HTTP 422 on invalid value).
  - Delegates directly to `FreemiumQuotaConsumptionService::getStudentQuotaAllowances($student, $examType)`.
  - Wraps result in standard envelope: `['success' => 1, 'data' => ['quotas' => $quotas]]`.

### C. Quota Consumption Service
- **File:** `app/Http/Services/Freemium/FreemiumQuotaConsumptionService.php`
- **Methods:**
  - `getStudentQuotaAllowances(WebUser $student, ?string $filterExamType = null, ?Carbon $now = null): array` (lines 596–704)
  - `getLegacyFallbackAllowances(WebUser $student, ?string $filterExamType = null, ?Carbon $now = null): array` (lines 714–765)

### D. Configuration
- **File:** `config/freemium.php`
  - `fallback_quotas.formal_test`: `env('FREEMIUM_LEGACY_WEEKLY_TESTS', '1')`
  - `fallback_quotas.mock`: `env('FREEMIUM_LEGACY_WEEKLY_MOCKS', '1')`
  - `fallback_quotas.practice`: `env('FREEMIUM_LEGACY_WEEKLY_PRACTICE', '2')`
  - `policy.allow_retakes`: `env('FREEMIUM_LEGACY_ALLOW_RETAKES', 'false')`
  - `policy.max_attempts_per_test`: `env('FREEMIUM_LEGACY_MAX_ATTEMPTS', '1')`

---

## 3. Root Cause of Missing Merge

In `FreemiumQuotaConsumptionService::getStudentQuotaAllowances()`:

```php
// Lines 614-619
$quotaQuery = FreemiumExamQuota::whereIn('campaign_id', $campaignIds);
if ($filterExamType !== null) {
    $quotaQuery->where('exam_type', $filterExamType);
}
$quotas = $quotaQuery->get();

if ($quotas->isEmpty()) {
    return $this->getLegacyFallbackAllowances($student, $filterExamType, $now);
}

// Lines 631-634
$allowances = [];
$examTypes = $quotas->pluck('exam_type')->unique();

foreach ($examTypes as $eType) {
    // Computes campaign allowance for $eType ...
    $allowances[] = [ ... ];
}

return $allowances;
```

### The Bug Mechanism:
1. When a campaign defines quotas for only a subset of exam types (e.g., Campaign A has quotas for `practice` and `formal_test`, but no quota for `mock`):
   - `$quotas` contains records for `practice` and `formal_test`.
   - `$quotas->isEmpty()` evaluates to `false`.
   - `$examTypes` becomes `['practice', 'formal_test']`.
   - The loop generates allowances **only** for `practice` and `formal_test`.
   - `mock` is completely skipped and omitted from the returned array!
2. However, when the client explicitly requests `GET /quota-allowances?exam_type=mock`:
   - `$quotaQuery->where('exam_type', 'mock')` executes.
   - `$quotas` is empty for `mock`.
   - Line 621 triggers: `if ($quotas->isEmpty()) return $this->getLegacyFallbackAllowances($student, 'mock', $now);`.
   - A single-item array with legacy fallback for `mock` is returned.
3. This creates a severe behavioral inconsistency:
   - Unfiltered call (`GET /quota-allowances`): `mock` is **absent**.
   - Filtered call (`GET /quota-allowances?exam_type=mock`): `mock` is **present**.

---

## 4. Proposed Merged Response Structure & JSON Examples

### A. Item Schema Specification
Each item in `data.quotas` will maintain strict schema compatibility:

| Field | Type | Description |
| :--- | :--- | :--- |
| `exam_type` | `string` | Assessment type: `'formal_test'`, `'mock'`, or `'practice'` |
| `window_type` | `string` | Quota window type: e.g. `'weekly'`, `'daily'`, `'monthly'`, `'campaign_lifetime'` |
| `window_key` | `string` | Unique window tracking key (e.g. `'PK:2026-W47'` or `'PK:legacy:weekly:2026-W47'`) |
| `is_unlimited` | `boolean` | `true` if quota has no cap (`max_tests === null` or `-1`) |
| `max_tests` | `integer\|null` | Allowed tests in window (`null` if unlimited, `0` if disabled) |
| `used_tests` | `integer` | Tests consumed in current window |
| `remaining_tests` | `integer\|null` | Available tests (`max(0, max_tests - used_tests)`) |
| `allow_retakes` | `boolean` | Whether retakes are permitted |
| `max_attempts_per_test` | `integer` | Inclusive attempt ceiling (initial attempt + retakes) |
| `resets_at` | `string (ISO-8601)` | Timestamp when the quota window resets (UTC) |
| `provider` | `string` | Origin of allowance: `'campaign'` or `'legacy_fallback'` |

---

### B. Concrete JSON Scenarios

#### Scenario 1: Unfiltered Query with Campaign Covering Subset
*Context:* Student belongs to Campaign "STEM Excellence" which defines `formal_test` (5 weekly) and `practice` (10 daily). `mock` is uncovered.
*Request:* `GET /web_api/auth/freemium/quota-allowances`
*Response:*
```json
{
  "success": 1,
  "data": {
    "quotas": [
      {
        "exam_type": "formal_test",
        "window_type": "weekly",
        "window_key": "PK:2026-W47",
        "is_unlimited": false,
        "max_tests": 5,
        "used_tests": 1,
        "remaining_tests": 4,
        "allow_retakes": true,
        "max_attempts_per_test": 3,
        "resets_at": "2026-11-23T19:00:00Z",
        "provider": "campaign"
      },
      {
        "exam_type": "mock",
        "window_type": "weekly",
        "window_key": "PK:legacy:weekly:2026-W47",
        "is_unlimited": false,
        "max_tests": 1,
        "used_tests": 0,
        "remaining_tests": 1,
        "allow_retakes": false,
        "max_attempts_per_test": 1,
        "resets_at": "2026-11-23T19:00:00Z",
        "provider": "legacy_fallback"
      },
      {
        "exam_type": "practice",
        "window_type": "daily",
        "window_key": "PK:2026-11-19",
        "is_unlimited": false,
        "max_tests": 10,
        "used_tests": 2,
        "remaining_tests": 8,
        "allow_retakes": true,
        "max_attempts_per_test": 5,
        "resets_at": "2026-11-19T19:00:00Z",
        "provider": "campaign"
      }
    ]
  }
}
```

#### Scenario 2: Unfiltered Query with Pure Legacy Fallback (No Campaigns)
*Context:* Student has no active campaigns or grants.
*Request:* `GET /web_api/auth/freemium/quota-allowances`
*Response:*
```json
{
  "success": 1,
  "data": {
    "quotas": [
      {
        "exam_type": "formal_test",
        "window_type": "weekly",
        "window_key": "PK:legacy:weekly:2026-W47",
        "is_unlimited": false,
        "max_tests": 1,
        "used_tests": 0,
        "remaining_tests": 1,
        "allow_retakes": false,
        "max_attempts_per_test": 1,
        "resets_at": "2026-11-23T19:00:00Z",
        "provider": "legacy_fallback"
      },
      {
        "exam_type": "mock",
        "window_type": "weekly",
        "window_key": "PK:legacy:weekly:2026-W47",
        "is_unlimited": false,
        "max_tests": 1,
        "used_tests": 0,
        "remaining_tests": 1,
        "allow_retakes": false,
        "max_attempts_per_test": 1,
        "resets_at": "2026-11-23T19:00:00Z",
        "provider": "legacy_fallback"
      },
      {
        "exam_type": "practice",
        "window_type": "weekly",
        "window_key": "PK:legacy:weekly:2026-W47",
        "is_unlimited": false,
        "max_tests": 2,
        "used_tests": 1,
        "remaining_tests": 1,
        "allow_retakes": false,
        "max_attempts_per_test": 1,
        "resets_at": "2026-11-23T19:00:00Z",
        "provider": "legacy_fallback"
      }
    ]
  }
}
```

#### Scenario 3: Filtered Query for an Uncovered Assessment Type
*Context:* Student in Campaign covering `formal_test` only.
*Request:* `GET /web_api/auth/freemium/quota-allowances?exam_type=mock`
*Response:*
```json
{
  "success": 1,
  "data": {
    "quotas": [
      {
        "exam_type": "mock",
        "window_type": "weekly",
        "window_key": "PK:legacy:weekly:2026-W47",
        "is_unlimited": false,
        "max_tests": 1,
        "used_tests": 0,
        "remaining_tests": 1,
        "allow_retakes": false,
        "max_attempts_per_test": 1,
        "resets_at": "2026-11-23T19:00:00Z",
        "provider": "legacy_fallback"
      }
    ]
  }
}
```

---

## 5. Rules for Combining Campaign & Legacy Allowances (No Double-Counting)

1. **Authoritative Standard Assessment Types:**
   The set of standard assessment types is `['formal_test', 'mock', 'practice']`.
2. **Deterministic Precedence per Exam Type:**
   For each target exam type:
   $$\text{Allowance}(e) = \begin{cases} \text{CampaignAllowance}(e) & \text{if } e \in \text{ParticipatingCampaignQuotas} \\ \text{LegacyFallbackAllowance}(e) & \text{otherwise} \end{cases}$$
3. **Strict Mutual Exclusivity:**
   A student **never** receives both a campaign allowance and a legacy allowance for the same exam type in the same response.
4. **No Fabricated Entities:**
   Legacy fallback allowances must never contain fictitious campaign IDs, fake rule IDs, or fake grant IDs. They are clearly identified by `'provider' => 'legacy_fallback'`.
5. **Deterministic Ordering:**
   Allowances in `data.quotas` must be ordered consistently: `formal_test` (1), `mock` (2), `practice` (3).

---

## 6. Paid Students & Overlapping Campaign Representation

### A. Paid Students
- Paid subscriptions operate at Tier 1 of the entitlement hierarchy.
- Inside covered academic scopes (programs/subjects/units where the student holds an active paid subscription), the student bypasses Freemium quotas entirely.
- In `GET /web_api/auth/freemium/entitlements`, active paid subscriptions are reported under `data.paid_subscriptions`.
- In `GET /web_api/auth/freemium/quota-allowances`, the API returns the student's Freemium quota ledger allowances. This allows paid students to inspect their remaining free allowances for non-subscribed subjects/programs.
- Paid status does not decrement or distort Freemium quotas.

### B. Overlapping Campaign Coverage
- When a student qualifies for multiple participating campaigns, existing priority resolution in `FreemiumRuleEvaluationService` selects the primary campaign (lowest priority integer).
- For an exam type with multiple campaign quotas, the primary campaign's quota takes precedence, followed by lowest priority number.
- Exactly one campaign quota is selected per exam type.

---

## 7. Zero, Unlimited, Exhausted, & Missing Configurations

| Scenario | Condition | `is_unlimited` | `max_tests` | `remaining_tests` | Behavioral Meaning |
| :--- | :--- | :---: | :---: | :---: | :--- |
| **Unlimited** | Campaign quota `max_tests === null` or `-1` | `true` | `null` | `null` | Student may generate unconstrained tests |
| **Zero / Disabled** | Config `fallback_quotas.* = 0` or campaign quota `max_tests = 0` | `false` | `0` | `0` | Assessment generation disabled by policy |
| **Exhausted** | `used_tests >= max_tests` | `false` | `N` | `0` | Quota reached; further generation denied |
| **Active Balance** | `used_tests < max_tests` | `false` | `N` | `N - used_tests` | Positive balance remaining |
| **Missing Config** | Config key missing or null | `false` | Default | `Default - used` | Defaults: formal: 1, mock: 1, practice: 2 |

---

## 8. Compatibility Impact on API Consumers

1. **Backwards Compatibility:**
   - Every existing key in `data.quotas.*` is preserved.
   - Filtered calls with `?exam_type=formal_test` continue returning an array with exactly 1 item.
2. **Schema Extensibility:**
   - The addition of `'provider' => 'campaign'` on campaign allowances complements `'provider' => 'legacy_fallback'`, allowing frontend clients to render "Sponsored by Campaign" vs "Standard Free Tier" badges.
3. **Test Suite Compatibility:**
   - In `StudentFreemiumQuotaControllerTest.php`, line 174 previously asserted `$this->assertCount(2, $quotas)` when a campaign defined only `practice` and `formal_test`. With the merge implemented, the test will be updated to assert `$this->assertCount(3, $quotas)` and assert that `mock` is supplied via `legacy_fallback`.

---

## 9. Required Implementation Files & Proposed Changes

### File 1: `app/Http/Services/Freemium/FreemiumQuotaConsumptionService.php`

**Method:** `getStudentQuotaAllowances(WebUser $student, ?string $filterExamType = null, ?Carbon $now = null): array`

#### Proposed Implementation:
```php
        // Resolve participating campaigns for student
        $resolved = $this->ruleEvaluator->resolveParticipatingCampaigns($student, $now);
        $participating = $resolved['participating'];
        $primaryCampaign = $resolved['primary'];

        $allStandardTypes = ['formal_test', 'mock', 'practice'];
        $targetExamTypes = $filterExamType !== null ? [$filterExamType] : $allStandardTypes;

        // If no active campaigns, return complete legacy fallback for target types
        if (empty($participating) || !$primaryCampaign) {
            return $this->getLegacyFallbackAllowances($student, $filterExamType, $now);
        }

        $campaignIds = array_map(fn($c) => $c->id, $participating);

        // Fetch quotas for participating campaigns
        $quotaQuery = FreemiumExamQuota::whereIn('campaign_id', $campaignIds);
        if ($filterExamType !== null) {
            $quotaQuery->where('exam_type', $filterExamType);
        }
        $quotas = $quotaQuery->get();

        // If no campaign quotas exist at all for target types, return legacy fallback
        if ($quotas->isEmpty()) {
            return $this->getLegacyFallbackAllowances($student, $filterExamType, $now);
        }

        $campaignMap = [];
        foreach ($participating as $c) {
            $campaignMap[$c->id] = $c;
        }

        $allowances = [];
        $campaignExamTypes = $quotas->pluck('exam_type')->unique();

        foreach ($campaignExamTypes as $eType) {
            $matchingQuotas = $quotas->where('exam_type', $eType)->sort(function ($a, $b) use ($campaignMap, $primaryCampaign) {
                if ($a->campaign_id === $primaryCampaign->id) return -1;
                if ($b->campaign_id === $primaryCampaign->id) return 1;
                $cpA = $campaignMap[$a->campaign_id]?->priority ?? 1000;
                $cpB = $campaignMap[$b->campaign_id]?->priority ?? 1000;
                return $cpA <=> $cpB;
            });

            $primaryQuota = $matchingQuotas->first();
            if (!$primaryQuota) {
                continue;
            }

            $campaign = $campaignMap[$primaryQuota->campaign_id] ?? $primaryCampaign;

            // Check if grant-bounded window applies
            $grant = FreemiumStudentGrant::where('campaign_id', $campaign->id)
                ->where('user_id', $student->id)
                ->where('status', 'active')
                ->first();

            if ($grant) {
                $grantEffectiveStart = $grant->granted_at->gt($campaign->start_at)
                    ? $grant->granted_at
                    : $campaign->start_at;

                $grantEffectiveEnd = $grant->expires_at && $grant->expires_at->lt($campaign->end_at)
                    ? $grant->expires_at
                    : $campaign->end_at;

                if ($now->lt($grantEffectiveStart) || $now->gte($grantEffectiveEnd)) {
                    $requiresGrant = ($campaign->eligibility_criteria['require_grant'] ?? false);
                    if ($requiresGrant) {
                        continue;
                    }
                }
            }

            $windowKey = FreemiumQuotaWindow::resolveWindowKey($primaryQuota->window_type, $campaign->id, $now);
            [$windowStartUtc, $windowEndUtc] = FreemiumQuotaWindow::resolveWindowUtcBounds($primaryQuota->window_type, $campaign, $now);

            $usedTests = (int) FreemiumQuotaCounter::where('user_id', $student->id)
                ->where('exam_type', $eType)
                ->where('window_type', $primaryQuota->window_type)
                ->where('window_key', $windowKey)
                ->value('counter');

            $isUnlimited = ($primaryQuota->max_tests === null || $primaryQuota->max_tests === -1);
            $maxTests = $isUnlimited ? null : (int) $primaryQuota->max_tests;
            $remainingTests = $isUnlimited ? null : max(0, $maxTests - $usedTests);

            $allowances[] = [
                'exam_type'             => $eType,
                'window_type'           => $primaryQuota->window_type,
                'window_key'            => $windowKey,
                'is_unlimited'          => $isUnlimited,
                'max_tests'             => $maxTests,
                'used_tests'            => $usedTests,
                'remaining_tests'       => $remainingTests,
                'allow_retakes'         => (bool) $primaryQuota->allow_retakes,
                'max_attempts_per_test' => (int) $primaryQuota->max_attempts_per_test,
                'resets_at'             => $windowEndUtc?->toIso8601String(),
                'provider'              => 'campaign',
            ];
        }

        // STAGE 1E.2.D.D.6: MERGE UNCOVERED EXAM TYPES WITH LEGACY FALLBACK
        $coveredTypes = array_column($allowances, 'exam_type');
        $missingTypes = array_diff($targetExamTypes, $coveredTypes);

        if (!empty($missingTypes)) {
            foreach ($missingTypes as $missingType) {
                $legacy = $this->getLegacyFallbackAllowances($student, $missingType, $now);
                if (!empty($legacy)) {
                    $allowances[] = $legacy[0];
                }
            }
        }

        // Deterministic ordering: formal_test -> mock -> practice
        $order = ['formal_test' => 1, 'mock' => 2, 'practice' => 3];
        usort($allowances, fn($a, $b) => ($order[$a['exam_type']] ?? 99) <=> ($order[$b['exam_type']] ?? 99));

        return $allowances;
```

---

### File 2: `tests/Feature/Freemium/Student/StudentFreemiumQuotaControllerTest.php`

1. Update `test_quota_allowances_returns_active_quotas_and_balances`:
   - Change `$this->assertCount(2, $quotas)` to `$this->assertCount(3, $quotas)`.
   - Assert `mock` is present, with `max_tests === 1` and `provider === 'legacy_fallback'`.
2. Add dedicated test methods:
   - `test_unfiltered_allowances_merges_campaign_coverage_with_legacy_fallback`:
     Sets up campaign with only `formal_test`, verifies unfiltered call returns all 3 types with `formal_test` as campaign and `mock`/`practice` as legacy.
   - `test_zero_quota_configuration_is_represented_accurately`:
     Configures `FREEMIUM_LEGACY_WEEKLY_MOCKS = 0`, asserts `max_tests === 0` and `remaining_tests === 0`.
   - `test_filtered_query_returns_legacy_fallback_when_campaign_omits_type`:
     Verifies `?exam_type=mock` returns exactly 1 item with provider `'legacy_fallback'`.
   - `test_deterministic_ordering_of_merged_allowances`:
     Verifies items always appear in order `['formal_test', 'mock', 'practice']`.

---

## 10. Test Matrix & Verification Plan

| Test ID | Test Description | Input State | Expected Outcome |
| :---: | :--- | :--- | :--- |
| **T1** | Unfiltered merge with partial campaign | Campaign has `formal_test` (5) only | Response has 3 items: `formal_test` (5, campaign), `mock` (1, legacy), `practice` (2, legacy) |
| **T2** | Unfiltered merge with pure campaign | Campaign has all 3 types | Response has 3 items, all with `provider: 'campaign'` |
| **T3** | Unfiltered merge with pure legacy | No campaigns exist | Response has 3 items, all with `provider: 'legacy_fallback'` |
| **T4** | Filtered query for campaign type | Campaign has `formal_test`; filter `?exam_type=formal_test` | Response has 1 item: `formal_test` (campaign) |
| **T5** | Filtered query for legacy type | Campaign has `formal_test`; filter `?exam_type=mock` | Response has 1 item: `mock` (legacy) |
| **T6** | Zero-quota disabled representation | `fallback_quotas.mock = 0` | `max_tests: 0`, `remaining_tests: 0`, `is_unlimited: false` |
| **T7** | Unlimited quota representation | Campaign `max_tests = -1` | `is_unlimited: true`, `max_tests: null`, `remaining_tests: null` |
| **T8** | Exhausted quota representation | Counter equals `max_tests` | `remaining_tests: 0` |
| **T9** | Deterministic ordering | Any merged response | Elements strictly ordered: `formal_test`, `mock`, `practice` |
| **T10** | Read-only guarantee | Unfiltered query invoked 5x | Counter and consumption tables have 0 writes |

---

## 11. Risks & Rollback Considerations

1. **Risk of Double-Counting:**
   - *Mitigation:* `$missingTypes = array_diff($targetExamTypes, $coveredTypes)` guarantees that legacy fallback is queried **only** for assessment types not present in `$coveredTypes`.
2. **Risk of Breaking Existing Frontend Clients:**
   - *Mitigation:* The response root envelope `{ "success": 1, "data": { "quotas": [...] } }` and existing item keys are untouched. New field `'provider'` is strictly additive.
3. **Rollback Strategy:**
   - If unexpected issues arise, `getStudentQuotaAllowances()` can be reverted instantly with zero database migrations or state repair required.
