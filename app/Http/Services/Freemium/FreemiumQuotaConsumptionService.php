<?php

namespace App\Http\Services\Freemium;

use App\Http\Services\Freemium\Exceptions\IdempotencyConflictException;
use App\Http\Services\Freemium\Exceptions\QuotaLimitExceededException;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumExamQuota;
use App\Models\Freemium\FreemiumQuotaConsumption;
use App\Models\Freemium\FreemiumQuotaConsumptionEnvelope;
use App\Models\Freemium\FreemiumQuotaCounter;
use App\Models\Freemium\FreemiumStudentGrant;
use App\Models\PracticeSession;
use App\Models\Test;
use App\Models\TestAttempt;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class FreemiumQuotaConsumptionService
{
    public function __construct(
        protected FreemiumAccessRuleEvaluator $ruleEvaluator,
        protected ?UnifiedEntitlementResolver $entitlementResolver = null
    ) {
    }

    protected function getEntitlementResolver(): UnifiedEntitlementResolver
    {
        return $this->entitlementResolver ?? app(UnifiedEntitlementResolver::class);
    }

    /**
     * Generate canonical fingerprint from request payload.
     */
    public static function canonicalFingerprint(array $payload): string
    {
        ksort($payload);
        return hash('sha256', json_encode($payload));
    }

    /**
     * Strict integer-only configuration parser for legacy freemium quotas.
     *
     * Rules:
     * - Missing / null: fallbackDefault
     * - Empty string or whitespace: fallbackDefault
     * - Non-numeric string ("abc"): fallbackDefault
     * - Decimal string ("1.5", "2.0"): fallbackDefault (integer-only enforced)
     * - Negative integers (including -1): fallbackDefault (ALL negative values rejected)
     * - Zero (0, "0"): preserved as 0 (intentional disablement)
     * - Positive integers: accepted as integer
     *
     * @param string $configKey
     * @param int $fallbackDefault
     * @return int
     */
    public function resolveConfiguredQuota(string $configKey, int $fallbackDefault): int
    {
        $val = config($configKey);

        if ($val === null) {
            return $fallbackDefault;
        }

        if (is_int($val)) {
            if ($val >= 0) {
                return $val;
            }
            Log::warning("Negative quota rejected for [{$configKey}]: {$val}. Falling back to default: {$fallbackDefault}");
            return $fallbackDefault;
        }

        if (is_string($val)) {
            $trimmed = trim($val);
            if ($trimmed === '') {
                return $fallbackDefault;
            }
            if (preg_match('/^[0-9]+$/', $trimmed)) {
                return (int) $trimmed;
            }
            Log::warning("Invalid non-integer quota string for [{$configKey}]: '{$trimmed}'. Falling back to default: {$fallbackDefault}");
            return $fallbackDefault;
        }

        return $fallbackDefault;
    }

    /**
     * Strict boolean parser eliminating unsafe (bool) casting.
     *
     * Rules:
     * - True tokens: 1, "1", true, "true", "yes", "on"
     * - False tokens: 0, "0", false, "false", "no", "off"
     * - Missing, empty, or invalid: fallbackDefault
     *
     * @param string $configKey
     * @param bool $fallbackDefault
     * @return bool
     */
    public function resolveConfiguredBoolean(string $configKey, bool $fallbackDefault = false): bool
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

    /**
     * Consume quota for a test generation, practice generation, attempt start, or retake start.
     *
     * @param WebUser $student Trusted authenticated student
     * @param string $examType One of 'practice', 'formal_test', 'mock'
     * @param string $eventType One of 'generation', 'attempt_start', 'retake_start', 'practice_generation', 'practice_completion'
     * @param array $options Options array containing:
     *                       - test_id: ?int
     *                       - test_attempt_id: ?int
     *                       - practice_session_id: ?int
     *                       - attempt_number: int (default 0 for generation, 1..N for attempts)
     *                       - idempotency_key: ?string
     *                       - request_payload: ?array
     *                       - primary_campaign: ?FreemiumCampaign
     *                       - participating_campaigns: FreemiumCampaign[]
     *                       - primary_grant: ?FreemiumStudentGrant
     *                       - now: ?Carbon
     */
    public function consumeQuota(
        WebUser $student,
        string $examType,
        string $eventType,
        array $options = []
    ): FreemiumQuotaConsumption {
        $now = isset($options['now']) && $options['now'] instanceof Carbon
            ? $options['now']->copy()->setTimezone('UTC')
            : Carbon::now('UTC');

        $testId = $options['test_id'] ?? null;
        $practiceSessionId = $options['practice_session_id'] ?? null;
        $testAttemptId = $options['test_attempt_id'] ?? null;
        $attemptNumber = (int) ($options['attempt_number'] ?? ($eventType === 'generation' || $eventType === 'practice_generation' ? 0 : 1));
        $idempotencyKey = $options['idempotency_key'] ?? null;
        $requestPayload = $options['request_payload'] ?? [];
        $fingerprint = !empty($requestPayload) ? self::canonicalFingerprint($requestPayload) : null;

        // Mutual exclusivity check on entity targets
        if ($testId !== null && $practiceSessionId !== null) {
            throw new InvalidArgumentException("Dual target violation: test_id and practice_session_id are mutually exclusive.");
        }
        if ($testId === null && $practiceSessionId === null) {
            throw new InvalidArgumentException("Missing target entity: either test_id or practice_session_id must be provided.");
        }

        // Execute inside concurrency-safe database transaction with parent-row locking.
        // Stage 1E.2.D.D.5: Flatten nested transactions to eliminate SAVEPOINT trans2 failures.
        // If an outer transaction is already active, participate in it directly without savepoints.
        $execute = function () use (
            $student,
            $examType,
            $eventType,
            $testId,
            $testAttemptId,
            $practiceSessionId,
            $attemptNumber,
            $idempotencyKey,
            $requestPayload,
            $fingerprint,
            $options,
            $now
        ) {
            // 1. Stable parent-row lock on WebUser to serialize requests for this student
            WebUser::where('id', $student->id)->lockForUpdate()->first();

            // 2. Concurrency-safe Idempotency Check
            if ($idempotencyKey !== null) {
                $existing = FreemiumQuotaConsumption::where('user_id', $student->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    if ($fingerprint !== null && $existing->request_fingerprint !== null) {
                        if ($existing->request_fingerprint !== $fingerprint) {
                            throw new IdempotencyConflictException(
                                "Idempotency key '{$idempotencyKey}' reused with conflicting request payload."
                            );
                        }
                    }
                    // Same key + same payload: replay original result
                    return $existing;
                }
            }

            // 3. Check if this is a Tier 3 legacy-free fallback consumption
            $isLegacyFallback = $options['is_legacy_fallback'] ?? false;

            if ($isLegacyFallback) {
                // Legacy-free fallback quota evaluation
                $configKey = 'freemium.fallback_quotas.' . $examType;
                $defaultCeiling = ($examType === 'formal_test' || $examType === 'mock' ? 1 : 2);
                $quotaCeiling = $this->resolveConfiguredQuota($configKey, $defaultCeiling);

                $consumesGenerationQuota = in_array($eventType, ['generation', 'practice_generation']);

                if ($consumesGenerationQuota && $quotaCeiling === 0) {
                    throw new QuotaLimitExceededException(
                        "Legacy-free {$examType} generation is currently disabled by system policy."
                    );
                }

                $pkt = $now->copy()->setTimezone('Asia/Karachi');
                $windowKey = 'PK:legacy:weekly:' . $pkt->format('o-\WW');
                $windowType = 'weekly';

                $counterRow = FreemiumQuotaCounter::where('user_id', $student->id)
                    ->where('exam_type', $examType)
                    ->where('window_type', $windowType)
                    ->where('window_key', $windowKey)
                    ->lockForUpdate()
                    ->first();

                if (!$counterRow) {
                    try {
                        $counterRow = FreemiumQuotaCounter::create([
                            'user_id'     => $student->id,
                            'exam_type'   => $examType,
                            'window_type' => $windowType,
                            'window_key'  => $windowKey,
                            'counter'     => 0,
                        ]);
                    } catch (QueryException $e) {
                        // If it's a deadlock or concurrency error, rethrow immediately for transaction retry
                        if (str_contains($e->getMessage(), '1213') || str_contains($e->getMessage(), '1205') || str_contains($e->getMessage(), 'Deadlock')) {
                            throw $e;
                        }

                        // Unique constraint race: re-fetch with lock
                        $counterRow = FreemiumQuotaCounter::where('user_id', $student->id)
                            ->where('exam_type', $examType)
                            ->where('window_type', $windowType)
                            ->where('window_key', $windowKey)
                            ->lockForUpdate()
                            ->first();

                        if (!$counterRow) {
                            throw $e;
                        }
                    }
                }

                $currentCount = $counterRow->counter;

                if ($consumesGenerationQuota) {
                    if ($currentCount >= $quotaCeiling) {
                        throw new QuotaLimitExceededException(
                            "Weekly {$examType} quota limit ({$quotaCeiling}) reached for legacy-free access."
                        );
                    }
                    $currentCount++;
                    $counterRow->counter = $currentCount;
                    $counterRow->save();
                }

                // Evaluate Attempt & Retake Semantics for legacy fallback
                $isRetake = ($eventType === 'retake_start' || $attemptNumber > 1);
                if ($isRetake) {
                    $allowRetakes = config('freemium.policy.allow_retakes') !== null
                        ? $this->resolveConfiguredBoolean('freemium.policy.allow_retakes', false)
                        : $this->resolveConfiguredBoolean('freemium.fallback_quotas.allow_retakes', false);
                    $maxAttempts = config('freemium.policy.max_attempts_per_test') !== null
                        ? $this->resolveConfiguredQuota('freemium.policy.max_attempts_per_test', 1)
                        : $this->resolveConfiguredQuota('freemium.fallback_quotas.max_attempts_per_test', 1);

                    if (!$allowRetakes) {
                        throw new QuotaLimitExceededException("Retakes are not permitted for this test quota.");
                    }
                    if ($attemptNumber > $maxAttempts) {
                        throw new QuotaLimitExceededException(
                            "Maximum permitted attempts ({$maxAttempts}) exceeded for this test."
                        );
                    }
                }

                $decisionSnapshot = [
                    'granted'          => true,
                    'provider'         => 'legacy',
                    'origin'           => 'legacy_fallback',
                    'event_type'       => $eventType,
                    'exam_type'        => $examType,
                    'attempt_number'   => $attemptNumber,
                    'quota_ceiling'    => $quotaCeiling,
                    'window_key'       => $windowKey,
                    'effective_config' => [
                        'config_key'       => $configKey,
                        'resolved_ceiling' => $quotaCeiling,
                        'default'          => $defaultCeiling,
                    ],
                    'consumed_at_utc'  => $now->toIso8601String(),
                ];

                try {
                    return FreemiumQuotaConsumption::create([
                        'user_id'             => $student->id,
                        'exam_type'           => $examType,
                        'event_type'          => $eventType,
                        'primary_campaign_id' => null,
                        'primary_quota_id'    => null,
                        'primary_grant_id'    => null,
                        'test_id'             => $testId,
                        'test_attempt_id'     => $testAttemptId,
                        'practice_session_id' => $practiceSessionId,
                        'attempt_number'      => $attemptNumber,
                        'idempotency_key'     => $idempotencyKey,
                        'request_fingerprint' => $fingerprint,
                        'decision_snapshot'   => $decisionSnapshot,
                        'consumed_at'         => $now,
                    ]);
                } catch (QueryException $e) {
                    if ($idempotencyKey !== null && str_contains($e->getMessage(), 'uk_user_idempotency')) {
                        $existing = FreemiumQuotaConsumption::where('user_id', $student->id)
                            ->where('idempotency_key', $idempotencyKey)
                            ->first();
                        if ($existing) {
                            return $existing;
                        }
                    }
                    throw $e;
                }
            }

            // 4. Resolve campaigns participating in this consumption (Tier 2 Explicit Freemium)
            $primaryCampaign = $options['primary_campaign'] ?? null;
            $participatingCampaigns = $options['participating_campaigns'] ?? [];

            if ($primaryCampaign && empty($participatingCampaigns)) {
                $participatingCampaigns = [$primaryCampaign];
            }

            if (!$primaryCampaign) {
                $resolved = $this->ruleEvaluator->resolveParticipatingCampaigns($student, $now);
                $primaryCampaign = $resolved['primary'];
                $participatingCampaigns = $resolved['participating'];
            }

            if (!$primaryCampaign || empty($participatingCampaigns)) {
                throw new QuotaLimitExceededException("No active freemium campaign available for quota consumption.");
            }

            // 4. Resolve quotas for each participating campaign
            $campaignQuotaEnvelopes = [];
            $primaryQuota = null;

            foreach ($participatingCampaigns as $campaign) {
                $quota = FreemiumExamQuota::where('campaign_id', $campaign->id)
                    ->where('exam_type', $examType)
                    ->first();

                if (!$quota) {
                    continue;
                }

                if ($campaign->id === $primaryCampaign->id) {
                    $primaryQuota = $quota;
                }

                // Check student grant for this campaign
                $grant = FreemiumStudentGrant::where('campaign_id', $campaign->id)
                    ->where('user_id', $student->id)
                    ->where('status', 'active')
                    ->first();

                // If grant exists, enforce strict grant-bounded interval:
                // [ max(campaign.start_at, grant.granted_at), min(campaign.end_at, grant.expires_at) )
                if ($grant) {
                    $grantEffectiveStart = $grant->granted_at->gt($campaign->start_at)
                        ? $grant->granted_at
                        : $campaign->start_at;

                    $grantEffectiveEnd = $grant->expires_at && $grant->expires_at->lt($campaign->end_at)
                        ? $grant->expires_at
                        : $campaign->end_at;

                    if ($now->lt($grantEffectiveStart) || $now->gte($grantEffectiveEnd)) {
                        // Current time is outside grant-bounded interval
                        $requiresGrant = ($campaign->eligibility_criteria['require_grant'] ?? false);
                        if ($requiresGrant) {
                            continue;
                        }
                        // For an open campaign, pre-grant/post-grant isolation means the grant does NOT fund this consumption
                        $grant = null;
                    }
                }

                $windowKey = FreemiumQuotaWindow::resolveWindowKey($quota->window_type, $campaign->id, $now);

                $campaignQuotaEnvelopes[] = [
                    'campaign'   => $campaign,
                    'quota'      => $quota,
                    'grant'      => $grant,
                    'window_key' => $windowKey,
                ];
            }

            if (!$primaryQuota || empty($campaignQuotaEnvelopes)) {
                throw new QuotaLimitExceededException("No applicable exam quota defined for exam type '{$examType}'.");
            }

            // 5. Evaluate Attempt Semantics:
            // max_attempts_per_test includes initial attempt (e.g. max 2 = attempt 1 and 2 allowed)
            $isRetake = ($eventType === 'retake_start' || $attemptNumber > 1);
            if ($isRetake) {
                if (!$primaryQuota->allow_retakes) {
                    throw new QuotaLimitExceededException("Retakes are not permitted for this test quota.");
                }
                if ($attemptNumber > $primaryQuota->max_attempts_per_test) {
                    throw new QuotaLimitExceededException(
                        "Maximum permitted attempts ({$primaryQuota->max_attempts_per_test}) exceeded for this test."
                    );
                }
            }

            // 6. Evaluate and update quota counters for test generation
            // Retakes do NOT consume test-generation quota; generation events DO consume quota
            $consumesGenerationQuota = in_array($eventType, ['generation', 'practice_generation']);
            $envelopesToCreate = [];

            foreach ($campaignQuotaEnvelopes as $envelopeSpec) {
                /** @var FreemiumExamQuota $q */
                $q = $envelopeSpec['quota'];
                $wKey = $envelopeSpec['window_key'];

                // Pessimistic lock on counter row
                $counterRow = FreemiumQuotaCounter::where('user_id', $student->id)
                    ->where('exam_type', $examType)
                    ->where('window_type', $q->window_type)
                    ->where('window_key', $wKey)
                    ->lockForUpdate()
                    ->first();

                if (!$counterRow) {
                    try {
                        $counterRow = FreemiumQuotaCounter::create([
                            'user_id'     => $student->id,
                            'exam_type'   => $examType,
                            'window_type' => $q->window_type,
                            'window_key'  => $wKey,
                            'counter'     => 0,
                        ]);
                    } catch (QueryException $e) {
                        // If it's a deadlock or concurrency error, rethrow immediately for transaction retry
                        if (str_contains($e->getMessage(), '1213') || str_contains($e->getMessage(), '1205') || str_contains($e->getMessage(), 'Deadlock')) {
                            throw $e;
                        }

                        // Unique constraint race: re-fetch with lock
                        $counterRow = FreemiumQuotaCounter::where('user_id', $student->id)
                            ->where('exam_type', $examType)
                            ->where('window_type', $q->window_type)
                            ->where('window_key', $wKey)
                            ->lockForUpdate()
                            ->first();

                        if (!$counterRow) {
                            throw $e;
                        }
                    }
                }

                $currentCount = $counterRow->counter;

                if ($consumesGenerationQuota) {
                    // Check limit
                    if ($q->max_tests !== null && $q->max_tests !== -1) {
                        if ($currentCount >= $q->max_tests) {
                            throw new QuotaLimitExceededException(
                                "Quota limit ({$q->max_tests}) reached for {$q->window_type} window '{$wKey}' in campaign '{$envelopeSpec['campaign']->name}'."
                            );
                        }
                    }

                    $currentCount++;
                    $counterRow->counter = $currentCount;
                    $counterRow->save();
                }

                $envelopesToCreate[] = [
                    'campaign_id'                     => $envelopeSpec['campaign']->id,
                    'quota_id'                        => $q->id,
                    'grant_id'                        => $envelopeSpec['grant']?->id,
                    'window_type'                     => $q->window_type,
                    'window_key'                      => $wKey,
                    'counter_value_after_consumption' => $currentCount,
                ];
            }

            // 7. Write master consumption ledger row
            $primaryGrant = $options['primary_grant'] ?? $campaignQuotaEnvelopes[0]['grant'] ?? null;

            $decisionSnapshot = [
                'granted'                  => true,
                'event_type'               => $eventType,
                'exam_type'                => $examType,
                'attempt_number'           => $attemptNumber,
                'primary_campaign_id'      => $primaryCampaign->id,
                'primary_quota_id'         => $primaryQuota->id,
                'participating_campaigns'  => array_map(fn($e) => $e['campaign']->id, $campaignQuotaEnvelopes),
                'envelopes'                => $envelopesToCreate,
                'consumed_at_utc'          => $now->toIso8601String(),
            ];

            try {
                $consumption = FreemiumQuotaConsumption::create([
                    'user_id'             => $student->id,
                    'exam_type'           => $examType,
                    'event_type'          => $eventType,
                    'primary_campaign_id' => $primaryCampaign->id,
                    'primary_quota_id'    => $primaryQuota->id,
                    'primary_grant_id'    => $primaryGrant?->id,
                    'test_id'             => $testId,
                    'test_attempt_id'     => $testAttemptId,
                    'practice_session_id' => $practiceSessionId,
                    'attempt_number'      => $attemptNumber,
                    'idempotency_key'     => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                    'decision_snapshot'   => $decisionSnapshot,
                    'consumed_at'         => $now,
                ]);
            } catch (QueryException $e) {
                // If unique constraint uk_user_idempotency tripped concurrently:
                if ($idempotencyKey !== null && str_contains($e->getMessage(), 'uk_user_idempotency')) {
                    $existing = FreemiumQuotaConsumption::where('user_id', $student->id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();
                    if ($existing) {
                        return $existing;
                    }
                }
                throw $e;
            }

            // 8. Write normalized consumption envelopes
            foreach ($envelopesToCreate as $envData) {
                FreemiumQuotaConsumptionEnvelope::create(array_merge($envData, [
                    'consumption_id' => $consumption->id,
                    'allocated_at'   => $now,
                ]));
            }

            return $consumption;
        };

        return DB::transactionLevel() > 0 ? $execute() : DB::transaction($execute);
    }

    /**
     * Read-only inspection of active quota allowances and remaining balances for an authenticated student.
     *
     * Invariants:
     * - Must not create quota consumption records.
     * - Must not increment counters.
     * - Must not reserve quota.
     * - Must not change grants or campaign state.
     *
     * @param WebUser $student Authenticated student
     * @param string|null $filterExamType Optional filter ('practice', 'formal_test', 'mock')
     * @param Carbon|null $now Reference timestamp (UTC)
     * @return array Array of quota allowance definitions and current balance calculations
     */
    public function getStudentQuotaAllowances(
        WebUser $student,
        ?string $filterExamType = null,
        ?Carbon $now = null
    ): array {
        $now = $now ? $now->copy()->setTimezone('UTC') : Carbon::now('UTC');

        // Resolve participating campaigns for student
        $resolved = $this->ruleEvaluator->resolveParticipatingCampaigns($student, $now);
        $participating = $resolved['participating'];
        $primaryCampaign = $resolved['primary'];

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

        if ($quotas->isEmpty()) {
            return $this->getLegacyFallbackAllowances($student, $filterExamType, $now);
        }

        // Group quotas by exam_type, taking the primary campaign's quota or lowest priority
        $campaignMap = [];
        foreach ($participating as $c) {
            $campaignMap[$c->id] = $c;
        }

        $allowances = [];
        $examTypes = $quotas->pluck('exam_type')->unique();

        foreach ($examTypes as $eType) {
            // Find primary quota for this exam_type
            $matchingQuotas = $quotas->where('exam_type', $eType)->sort(function ($a, $b) use ($campaignMap, $primaryCampaign) {
                // Primary campaign first
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
                        continue; // Student grant is inactive or out of bounds
                    }
                }
            }

            $windowKey = FreemiumQuotaWindow::resolveWindowKey($primaryQuota->window_type, $campaign->id, $now);
            [$windowStartUtc, $windowEndUtc] = FreemiumQuotaWindow::resolveWindowUtcBounds($primaryQuota->window_type, $campaign, $now);

            // Read-only counter lookup
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

        // Stage 1E.2.D.D.6: Merge uncovered assessment types with legacy fallback allowances
        $allStandardTypes = ['formal_test', 'mock', 'practice'];
        $targetExamTypes = $filterExamType !== null
            ? (in_array($filterExamType, $allStandardTypes, true) ? [$filterExamType] : [])
            : $allStandardTypes;

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

        // Deterministic canonical ordering: formal_test -> mock -> practice
        $order = ['formal_test' => 1, 'mock' => 2, 'practice' => 3];
        usort($allowances, fn($a, $b) => ($order[$a['exam_type']] ?? 99) <=> ($order[$b['exam_type']] ?? 99));

        return $allowances;
    }

    /**
     * Compute legacy fallback quota allowances for an authenticated student.
     *
     * @param WebUser $student
     * @param string|null $filterExamType
     * @param Carbon|null $now
     * @return array
     */
    public function getLegacyFallbackAllowances(
        WebUser $student,
        ?string $filterExamType = null,
        ?Carbon $now = null
    ): array {
        $now = $now ? $now->copy()->setTimezone('UTC') : Carbon::now('UTC');
        $pkt = $now->copy()->setTimezone('Asia/Karachi');
        $windowKey = 'PK:legacy:weekly:' . $pkt->format('o-\WW');
        $windowType = 'weekly';
        $resetsAt = $pkt->copy()->startOfWeek()->addWeek()->setTimezone('UTC')->toIso8601String();

        $examTypes = ['formal_test', 'mock', 'practice'];
        if ($filterExamType !== null) {
            $examTypes = in_array($filterExamType, $examTypes, true) ? [$filterExamType] : [];
        }

        $allowRetakes = config('freemium.policy.allow_retakes') !== null
            ? $this->resolveConfiguredBoolean('freemium.policy.allow_retakes', false)
            : $this->resolveConfiguredBoolean('freemium.fallback_quotas.allow_retakes', false);
        $maxAttempts = config('freemium.policy.max_attempts_per_test') !== null
            ? $this->resolveConfiguredQuota('freemium.policy.max_attempts_per_test', 1)
            : $this->resolveConfiguredQuota('freemium.fallback_quotas.max_attempts_per_test', 1);

        $allowances = [];
        foreach ($examTypes as $eType) {
            $configKey = 'freemium.fallback_quotas.' . $eType;
            $defaultCeiling = ($eType === 'formal_test' || $eType === 'mock' ? 1 : 2);
            $quotaCeiling = $this->resolveConfiguredQuota($configKey, $defaultCeiling);

            $used = (int) FreemiumQuotaCounter::where('user_id', $student->id)
                ->where('exam_type', $eType)
                ->where('window_type', $windowType)
                ->where('window_key', $windowKey)
                ->value('counter');

            $allowances[] = [
                'exam_type'             => $eType,
                'window_type'           => $windowType,
                'window_key'            => $windowKey,
                'is_unlimited'          => false,
                'max_tests'             => $quotaCeiling,
                'used_tests'            => $used,
                'remaining_tests'       => max(0, $quotaCeiling - $used),
                'allow_retakes'         => $allowRetakes,
                'max_attempts_per_test' => $maxAttempts,
                'resets_at'             => $resetsAt,
                'provider'              => 'legacy_fallback',
            ];
        }

        // Deterministic canonical ordering: formal_test -> mock -> practice
        $order = ['formal_test' => 1, 'mock' => 2, 'practice' => 3];
        usort($allowances, fn($a, $b) => ($order[$a['exam_type']] ?? 99) <=> ($order[$b['exam_type']] ?? 99));

        return $allowances;
    }

    /**
     * Consume attempt quota (initial attempt or retake) for a formal test or mock.
     *
     * @param WebUser $student
     * @param Test $test
     * @param int $attemptNumber
     * @param string $eventType 'attempt_start' or 'retake_start'
     * @param array $options
     * @return FreemiumQuotaConsumption
     */
    public function consumeAttemptQuota(
        WebUser $student,
        Test $test,
        int $attemptNumber,
        string $eventType,
        array $options = []
    ): FreemiumQuotaConsumption {
        $examType = ($test->test_mode === 'mock') ? 'mock' : 'formal_test';

        $mergedOptions = array_merge([
            'test_id'        => $test->id,
            'attempt_number' => $attemptNumber,
        ], $options);

        return $this->consumeQuota($student, $examType, $eventType, $mergedOptions);
    }

    /**
     * Check attempt and retake eligibility for an existing test.
     * Purely read-only check.
     */
    public function getTestAttemptEligibility(
        WebUser $student,
        int $testId,
        ?Carbon $now = null
    ): array {
        $now = $now ? $now->copy()->setTimezone('UTC') : Carbon::now('UTC');

        // Resolve test and establish ownership / accessibility
        $test = Test::find($testId);

        // 1. If test does not exist in tests table, fail closed immediately
        if (!$test) {
            return [
                'eligible'      => false,
                'can_attempt'   => false,
                'code'          => 'test_not_found',
                'denial_reason' => 'Test not found or access denied.',
            ];
        }

        // 2. Check if student directly owns or has participated in this test
        $hasConsumption = FreemiumQuotaConsumption::where('test_id', $testId)
            ->where('user_id', $student->id)
            ->exists();
        $hasAttempt = TestAttempt::where('test_id', $testId)
            ->where('user_id', $student->id)
            ->exists();

        $isStudentOwned = ((int) $test->created_by === (int) $student->id || $hasConsumption || $hasAttempt);

        // 3. Check if test is an official, published shared assessment
        $isOfficialShared = ($test->test_source === 'official' && (bool) $test->is_published);

        // 4. Access enforcement:
        if (!$isStudentOwned && !$isOfficialShared) {
            return [
                'eligible'      => false,
                'can_attempt'   => false,
                'code'          => 'test_not_found',
                'denial_reason' => 'Test not found or access denied.',
            ];
        }

        // Determine exam_type (mock or formal_test)
        $examType = ($test && $test->test_mode === 'mock') ? 'mock' : 'formal_test';

        // 5. Active in-progress attempt check
        $activeAttempt = TestAttempt::where('test_id', $testId)
            ->where('user_id', $student->id)
            ->where('status', 'in_progress')
            ->latest('id')
            ->first();
        $hasActiveAttempt = ($activeAttempt !== null);

        // Count existing attempts from ledger
        $ledgerAttemptCount = FreemiumQuotaConsumption::where('test_id', $testId)
            ->where('user_id', $student->id)
            ->where(function ($q) {
                $q->whereIn('event_type', ['attempt_start', 'retake_start'])
                  ->orWhere('attempt_number', '>', 0);
            })
            ->count();

        // Also check TestAttempt table
        $directAttemptCount = TestAttempt::where('test_id', $testId)
            ->where('user_id', $student->id)
            ->count();

        $attemptCount = max($ledgerAttemptCount, $directAttemptCount);

        // 6. Check if student has valid active paid subscription covering this test
        if ($test->offered_program_id || $test->subject_id) {
            try {
                $paidAccessService = app(\App\Http\Services\StudentClassAccessService::class);
                $activeProgramIds = $paidAccessService->getActiveProgramIdsForStudent((int) $student->id);
                $isPaidForTest = false;
                if (!empty($activeProgramIds)) {
                    if ($test->offered_program_id && in_array((int) $test->offered_program_id, $activeProgramIds, true)) {
                        $isPaidForTest = true;
                    } elseif ($test->subject_id && $paidAccessService->hasStudentActiveSubjectAccess((int) $student->id, (int) $test->subject_id)) {
                        $isPaidForTest = true;
                    }
                }
                if ($isPaidForTest) {
                    return [
                        'test_id'               => $testId,
                        'test_mode'             => $test?->test_mode ?? 'test',
                        'can_attempt'           => true,
                        'eligible'              => true,
                        'is_retake'             => ($attemptCount > 0),
                        'attempt_number'        => $attemptCount + 1,
                        'max_attempts_per_test' => null,
                        'remaining_attempts'    => null,
                        'allow_retakes'         => true,
                        'is_paid'               => true,
                        'has_active_attempt'    => $hasActiveAttempt,
                        'active_attempt_id'     => $activeAttempt?->id,
                        'test_source'           => $test->test_source ?? 'official',
                        'is_published'          => (bool) ($test->is_published ?? false),
                        'denial_reason'         => null,
                    ];
                }
            } catch (\Throwable) {
                // Fall through to freemium/legacy evaluation
            }
        }

        // 7. Authoritative 4-Tier Entitlement Resolution for non-paid students
        $targetContext = [
            'offered_program_id' => $test->offered_program_id ? (int) $test->offered_program_id : null,
            'subject_id'         => $test->subject_id ? (int) $test->subject_id : null,
        ];
        $unitIds = $test->scopes()->pluck('unit_id')->filter()->values()->all();
        if (!empty($unitIds)) {
            $targetContext['unit_ids'] = $unitIds;
        }

        $hasAcademicContext = ($test->offered_program_id || $test->subject_id || !empty($unitIds));

        if ($hasAcademicContext) {
            $decision = $this->getEntitlementResolver()->resolve(
                $student,
                $targetContext,
                'perm_mcqs',
                $examType
            );

            // Default Deny or explicit deny fails closed
            if ($decision->isDenied()) {
                return [
                    'test_id'               => $testId,
                    'test_mode'             => $test->test_mode ?? 'test',
                    'can_attempt'           => false,
                    'eligible'              => false,
                    'is_retake'             => ($attemptCount > 0),
                    'attempt_number'        => $attemptCount + 1,
                    'max_attempts_per_test' => 0,
                    'remaining_attempts'    => 0,
                    'allow_retakes'         => false,
                    'is_paid'               => false,
                    'has_active_attempt'    => $hasActiveAttempt,
                    'active_attempt_id'     => $activeAttempt?->id,
                    'test_source'           => $test->test_source ?? 'official',
                    'is_published'          => (bool) ($test->is_published ?? false),
                    'denial_reason'         => $decision->getDenialReason() ?? 'You do not have permission to attempt this assessment.',
                ];
            }
        }

        // Tier 2 Campaign / Tier 3 Legacy Fallback Quota Resolution
        $allowances = $this->getStudentQuotaAllowances($student, $examType, $now);
        $quota = $allowances[0] ?? null;

        if ($quota) {
            $allowRetakes = (bool) $quota['allow_retakes'];
            $maxAttempts = (int) $quota['max_attempts_per_test'];
        } else {
            // Resolve legacy fallback configuration
            $allowRetakes = config('freemium.policy.allow_retakes') !== null
                ? $this->resolveConfiguredBoolean('freemium.policy.allow_retakes', false)
                : $this->resolveConfiguredBoolean('freemium.fallback_quotas.allow_retakes', false);
            $maxAttempts = config('freemium.policy.max_attempts_per_test') !== null
                ? $this->resolveConfiguredQuota('freemium.policy.max_attempts_per_test', 1)
                : $this->resolveConfiguredQuota('freemium.fallback_quotas.max_attempts_per_test', 1);
        }

        if ($attemptCount === 0) {
            if ($maxAttempts < 1) {
                return [
                    'test_id'               => $testId,
                    'test_mode'             => $test->test_mode ?? 'test',
                    'can_attempt'           => false,
                    'eligible'              => false,
                    'is_retake'             => false,
                    'attempt_number'        => 1,
                    'max_attempts_per_test' => $maxAttempts,
                    'remaining_attempts'    => 0,
                    'allow_retakes'         => $allowRetakes,
                    'is_paid'               => false,
                    'has_active_attempt'    => false,
                    'active_attempt_id'     => null,
                    'test_source'           => $test->test_source ?? 'official',
                    'is_published'          => (bool) ($test->is_published ?? false),
                    'denial_reason'         => "Maximum permitted attempts ({$maxAttempts}) reached for this test.",
                ];
            }

            return [
                'test_id'               => $testId,
                'test_mode'             => $test->test_mode ?? 'test',
                'can_attempt'           => true,
                'eligible'              => true,
                'is_retake'             => false,
                'attempt_number'        => 1,
                'max_attempts_per_test' => $maxAttempts,
                'remaining_attempts'    => max(0, $maxAttempts - 1),
                'allow_retakes'         => $allowRetakes,
                'is_paid'               => false,
                'has_active_attempt'    => false,
                'active_attempt_id'     => null,
                'test_source'           => $test->test_source ?? 'official',
                'is_published'          => (bool) ($test->is_published ?? false),
                'denial_reason'         => null,
            ];
        }

        // Prior attempts >= 1: This is a retake!
        if (!$allowRetakes) {
            return [
                'test_id'               => $testId,
                'test_mode'             => $test->test_mode ?? 'test',
                'can_attempt'           => false,
                'eligible'              => false,
                'is_retake'             => true,
                'attempt_number'        => $attemptCount + 1,
                'max_attempts_per_test' => $maxAttempts,
                'remaining_attempts'    => 0,
                'allow_retakes'         => false,
                'is_paid'               => false,
                'has_active_attempt'    => $hasActiveAttempt,
                'active_attempt_id'     => $activeAttempt?->id,
                'test_source'           => $test->test_source ?? 'official',
                'is_published'          => (bool) ($test->is_published ?? false),
                'denial_reason'         => 'Retakes are not permitted for this test.',
            ];
        }

        if ($attemptCount >= $maxAttempts) {
            return [
                'test_id'               => $testId,
                'test_mode'             => $test->test_mode ?? 'test',
                'can_attempt'           => false,
                'eligible'              => false,
                'is_retake'             => true,
                'attempt_number'        => $attemptCount + 1,
                'max_attempts_per_test' => $maxAttempts,
                'remaining_attempts'    => 0,
                'allow_retakes'         => true,
                'is_paid'               => false,
                'has_active_attempt'    => $hasActiveAttempt,
                'active_attempt_id'     => $activeAttempt?->id,
                'test_source'           => $test->test_source ?? 'official',
                'is_published'          => (bool) ($test->is_published ?? false),
                'denial_reason'         => "Maximum permitted attempts ({$maxAttempts}) reached for this test.",
            ];
        }

        return [
            'test_id'               => $testId,
            'test_mode'             => $test->test_mode ?? 'test',
            'can_attempt'           => true,
            'eligible'              => true,
            'is_retake'             => true,
            'attempt_number'        => $attemptCount + 1,
            'max_attempts_per_test' => $maxAttempts,
            'remaining_attempts'    => max(0, $maxAttempts - $attemptCount),
            'allow_retakes'         => true,
            'is_paid'               => false,
            'has_active_attempt'    => $hasActiveAttempt,
            'active_attempt_id'     => $activeAttempt?->id,
            'test_source'           => $test->test_source ?? 'official',
            'is_published'          => (bool) ($test->is_published ?? false),
            'denial_reason'         => null,
        ];
    }
}
