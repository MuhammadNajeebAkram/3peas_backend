# Stage 1E.2.D.1 — Quota Consumption Mapping

**Document ID:** `PHASE_1E_2_D_1_QUOTA_CONSUMPTION_MAPPING`  
**Repository:** `E:\Al-Faraabi Software\3peas_backend`  
**Phase:** 1E.2.D.1 (Planning and Source Audit — Final Review Corrections)  
**Timestamp:** 2026-11-19

---

## 1. Objective & Scope

This document defines the authoritative accounting and consumption mapping between assessment lifecycle events and the Freemium quota ledger. It details:

1. The approved legacy-free assessment quota policy and its ledger integration architecture.
2. Legacy quota configuration validation (missing, malformed, zero, negative, and caching behavior).
3. The reconciled practice session repeat and resume policy.
4. Forward migration specification and safe, fail-closed rollback strategy.

---

## 2. Approved Assessment Entitlement Policies

The product owner has formally approved the four-tier assessment entitlement policy:

| Entitlement Tier                 | Identification Rule                                             | Formal Test Generation Quota                             | Practice Session Generation Quota                        | Retake & Repeat Rules                                                                   |
| -------------------------------- | --------------------------------------------------------------- | -------------------------------------------------------- | -------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| **Tier 1: Paid Subscribers**     | Active, non-expired subscription covering target scope          | **Unlimited** within paid scope                          | **Unlimited** within paid scope                          | Quota ledger bypassed entirely; unlimited attempts.                                     |
| **Tier 2: Explicit Freemium**    | Explicit allow rule in active Freemium campaign                 | **Campaign-defined quota** (from `freemium_exam_quotas`) | **Campaign-defined quota** (from `freemium_exam_quotas`) | Bounded by campaign `allow_retakes` and `max_attempts_per_test`.                        |
| **Tier 3: Legacy Free Fallback** | `program_subjects.is_free = 1` OR `offered_classes.is_free = 1` | **1 test per week** (System default, configurable)       | **2 sessions per week** (System default, configurable)   | Weekly windows reset under `Asia/Karachi` timezone; retakes governed by default policy. |
| **Tier 4: Default Deny**         | No subscription, rule, or legacy flag                           | **Denied** (HTTP 403 `ACCESS_DENIED`)                    | **Denied** (HTTP 403 `ACCESS_DENIED`)                    | Zero access.                                                                            |

---

## 3. Reconciled Practice Session Repeat & Resume Policy

### 3.1 The Resolved Retake Contradiction

The previous planning draft contained conflicting settings: `allow_retakes = true` and `max_attempts_per_test = 1`. In assessment quota semantics, `max_attempts_per_test = 1` prohibits retakes regardless of the boolean flag.

### 3.2 Practice Session Lifecycle Semantics

Source code inspection of `practice_sessions` confirms:

1. **Single Session Run:** Each `practice_sessions` record represents a single continuous practice run with status `in_progress` -> `submitted` or `abandoned`.
2. **No In-Place Retakes:** Unlike formal tests, practice sessions do **not** have multi-attempt retakes attached to a single session ID. Once a practice session is submitted, it is immutable.
3. **Resuming an Ongoing Session:**
    - Resuming an `in_progress` session via `POST /save-practice-session-question-progress` consumes **0 quota credits**.
    - Multiple progress updates or reconnects do not create new sessions.
4. **Repeating Practice on the Same Units:**
    - If a student wishes to practice the same chapter or book again after completing a session, they generate a **NEW practice session** via `POST /generate-selected-units-practice-session`.
    - Creating that new practice session consumes **1 credit** from their weekly allowance of **2 practice sessions per week**.
5. **Reconciled Practice Quota Settings:**
    ```php
    'practice' => [
        'window_type'           => 'weekly',
        'max_tests'             => 2,
        'allow_retakes'         => false,
        'max_attempts_per_test' => 1,
    ],
    ```
    This resolves the contradiction completely while strictly preserving the approved allowance of two practice sessions per week.

---

## 4. Legacy Quota Configuration Validation

### 4.1 Configuration Definition (`config/freemium.php`)

All environment access is encapsulated strictly within the configuration file, enabling full compatibility with `php artisan config:cache`:

```php
'legacy_free' => [
    'formal_test' => [
        'window_type'           => 'weekly',
        'max_tests'             => env('FREEMIUM_LEGACY_WEEKLY_TESTS', '1'),
        'allow_retakes'         => env('FREEMIUM_LEGACY_ALLOW_RETAKES', 'false'),
        'max_attempts_per_test' => env('FREEMIUM_LEGACY_MAX_ATTEMPTS', '1'),
    ],
    'practice' => [
        'window_type'           => 'weekly',
        'max_tests'             => env('FREEMIUM_LEGACY_WEEKLY_PRACTICE', '2'),
        'allow_retakes'         => 'false',
        'max_attempts_per_test' => '1',
    ],
],
```

### 4.2 Explicit Validation & Parsing Rules in Quota Service

The application service avoids naive `(int) env(...)` or `(int) config(...)` casting, which would incorrectly convert malformed strings (e.g. `"abc"`) into `0` (which is a valid business value meaning disabled) and would fail to reject negative values or decimals.

#### Quota Value Verification Matrix (Independent for Formal-Test & Practice Quotas)

The quota resolver verifies the following cases independently for both formal-test (`fallbackDefault = 1`) and practice quotas (`fallbackDefault = 2`):

| Test Case                               | Formal-Test Input          | Practice Input           | Parsed Result              | Action / Invariant                                                                                              |
| --------------------------------------- | -------------------------- | ------------------------ | -------------------------- | --------------------------------------------------------------------------------------------------------------- |
| **1. Missing variable**                 | `null`                     | `null`                   | `1` (Test), `2` (Practice) | Fall back to safe system default.                                                                               |
| **2. Empty string**                     | `""` or `"   "`            | `""` or `"   "`          | `1` (Test), `2` (Practice) | Whitespace trimmed; falls back to safe system default.                                                          |
| **3. Non-numeric string**               | `"abc"`, `"foo"`           | `"xyz"`, `"test"`        | `1` (Test), `2` (Practice) | Non-digit characters rejected; logs warning; falls back to safe default.                                        |
| **4. Decimal string**                   | `"1.5"`, `"2.0"`, `"0.5"`  | `"2.5"`, `"1.0"`         | `1` (Test), `2` (Practice) | Integer-only values enforced. Periods/decimals rejected; falls back to default.                                 |
| **5. Negative integers (including -1)** | `-1`, `"-1"`, `-5`, `"-5"` | `-1`, `"-1"`, `-10`      | `1` (Test), `2` (Practice) | **ALL negative values (including -1) are rejected.** No unbounded access permitted; falls back to safe default. |
| **6. Zero**                             | `0`, `"0"`                 | `0`, `"0"`               | `0` (Both)                 | **Preserve zero as intentional quota disablement.** Generation fails closed with 403 `QUOTA_EXHAUSTED`.         |
| **7. Positive integers**                | `1`, `5`, `"1"`, `"5"`     | `2`, `10`, `"2"`, `"10"` | Integer value accepted     | Parsed to strict integer ceiling.                                                                               |

#### Implementation Specification: `resolveConfiguredQuota`

```php
protected function resolveConfiguredQuota(string $configKey, int $fallbackDefault): int
{
    $val = config($configKey);

    // 1. Missing / null
    if ($val === null) {
        return $fallbackDefault;
    }

    // 2. Native integer
    if (is_int($val)) {
        if ($val >= 0) {
            return $val; // 0 preserved (disabled), > 0 accepted
        }
        // ALL negative integers (including -1) rejected
        Log::warning("Negative quota rejected for [{$configKey}]: {$val}. Falling back to default: {$fallbackDefault}");
        return $fallbackDefault;
    }

    // 3. String validation: strictly match non-negative whole integer digits (no decimals, negatives, or letters)
    if (is_string($val)) {
        $trimmed = trim($val);
        if ($trimmed === '') {
            return $fallbackDefault;
        }
        // Strictly matches non-negative integer digits only: e.g. "0", "1", "10"
        // Rejects "-1", "-5", "1.5", "abc"
        if (preg_match('/^[0-9]+$/', $trimmed)) {
            return (int) $trimmed;
        }
        Log::warning("Invalid non-integer quota string for [{$configKey}]: '{$trimmed}'. Falling back to default: {$fallbackDefault}");
        return $fallbackDefault;
    }

    // 4. Any other non-integer types (arrays, objects)
    return $fallbackDefault;
}
```

#### Safe Boolean Parsing for `FREEMIUM_LEGACY_ALLOW_RETAKES`

Relying on PHP's native `(bool) $val` casting is unsafe:

- `(bool) "false"` evaluates to `true` (any non-empty string in PHP casts to `true`).
- `(bool) "no"` evaluates to `true`.
- `(bool) "0"` evaluates to `false`.

To eliminate this vulnerability, `resolveConfiguredBoolean` uses strict token validation and `FILTER_VALIDATE_BOOLEAN` with `FILTER_NULL_ON_FAILURE`:

```php
protected function resolveConfiguredBoolean(string $configKey, bool $fallbackDefault = false): bool
{
    $val = config($configKey);

    if ($val === null) {
        return $fallbackDefault;
    }

    if (is_bool($val)) {
        return $val;
    }

    if (is_int($val)) {
        if ($val === 1) return true;
        if ($val === 0) return false;
        Log::warning("Ambiguous integer for boolean config [{$configKey}]: {$val}. Falling back to default.");
        return $fallbackDefault;
    }

    if (is_string($val)) {
        $trimmed = strtolower(trim($val));
        if (in_array($trimmed, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($trimmed, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        Log::warning("Invalid boolean string for [{$configKey}]: '{$trimmed}'. Falling back to safe default: " . ($fallbackDefault ? 'true' : 'false'));
        return $fallbackDefault;
    }

    return $fallbackDefault;
}
```

This guarantees that:

- `"false"`, `false`, `"0"`, `0`, `"no"`, `"off"` $\rightarrow$ strictly `false`.
- `"true"`, `true`, `"1"`, `1`, `"yes"`, `"on"` $\rightarrow$ strictly `true`.
- Any invalid string (`"abc"`, `"-1"`, `"2"`) or null $\rightarrow$ safely returns `fallbackDefault` (`false`).

---

## 5. Forward Migration & Safe Rollback Strategy

### 5.1 Current Database State

- Migration `2026_12_01_000006_create_freemium_quota_consumptions_table.php` has **already been applied in batch 140** in both `3peas_db` and `3peas_db_subscription_test`.
- Current unique constraint: `uk_test_attempt_event (test_id, attempt_number, event_type)`.
- Original migration `000006` must remain untouched.

### 5.2 Planned Forward Migration

**File:** `database/migrations/2026_12_01_000009_update_freemium_test_attempt_unique_index.php`

```php
public function up(): void
{
    Schema::table('freemium_quota_consumptions', function (Blueprint $table) {
        // 1. Drop existing 3-column constraint
        $table->dropUnique('uk_test_attempt_event');

        // 2. Add student-scoped 4-column constraint
        $table->unique(['test_id', 'user_id', 'attempt_number', 'event_type'], 'uk_test_user_attempt_event');

        // 3. Make campaign foreign keys nullable for legacy-free fallback support
        $table->foreignId('primary_campaign_id')->nullable()->change();
        $table->foreignId('primary_quota_id')->nullable()->change();
    });
}
```

### 5.3 Fail-Closed Safe Rollback Strategy

If multiple students attempt the same shared official test while Migration `000009` is active, multiple rows will exist with identical `(test_id, attempt_number, event_type)` for different `user_id`s. Restoring `uk_test_attempt_event` unconditionally would trigger an unhandled MySQL Duplicate Key error (`1062`).

The rollback method implements a **fail-closed pre-check**:

```php
public function down(): void
{
    // 1. Check for conflicting multi-student rows that violate the old 3-column index
    $conflicts = DB::table('freemium_quota_consumptions')
        ->select('test_id', 'attempt_number', 'event_type', DB::raw('COUNT(*) as total'))
        ->whereNotNull('test_id')
        ->groupBy('test_id', 'attempt_number', 'event_type')
        ->having('total', '>', 1)
        ->get();

    if ($conflicts->isNotEmpty()) {
        $samples = $conflicts->take(3)->map(fn ($c) => "test_id {$c->test_id}, attempt {$c->attempt_number}, event '{$c->event_type}' ({$c->total} rows)")->implode('; ');
        throw new \RuntimeException(
            "Cannot roll back migration 000009: Existing multi-student attempts violate the old 3-column unique constraint uk_test_attempt_event. Conflicts: [{$samples}]. Automatic rollback aborted to preserve student records."
        );
    }

    // 2. If no conflicts exist, safe to restore original constraint
    Schema::table('freemium_quota_consumptions', function (Blueprint $table) {
        $table->dropUnique('uk_test_user_attempt_event');
        $table->unique(['test_id', 'attempt_number', 'event_type'], 'uk_test_attempt_event');
        $table->foreignId('primary_campaign_id')->nullable(false)->change();
        $table->foreignId('primary_quota_id')->nullable(false)->change();
    });
}
```

- **Operational Rule:** Rollback is permitted only if no conflicting multi-student records exist. Under no circumstances will student records be altered or deleted to force a rollback.
