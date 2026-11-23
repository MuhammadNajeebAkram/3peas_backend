<?php

namespace Tests\Unit\Freemium;

use App\Http\Services\Freemium\FreemiumAccessRuleEvaluator;
use App\Http\Services\Freemium\FreemiumQuotaConsumptionService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class FreemiumLegacyQuotaConfigurationTest extends TestCase
{
    private FreemiumQuotaConsumptionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $ruleEvaluator = $this->createMock(FreemiumAccessRuleEvaluator::class);
        $this->service = new FreemiumQuotaConsumptionService($ruleEvaluator);
    }

    public function test_missing_and_null_config_uses_safe_default(): void
    {
        Config::set('freemium.fallback_quotas.formal_test', null);
        Config::set('freemium.fallback_quotas.practice_session', null);

        $formalLimit = $this->service->resolveConfiguredQuota('freemium.fallback_quotas.formal_test', 1);
        $practiceLimit = $this->service->resolveConfiguredQuota('freemium.fallback_quotas.practice_session', 2);

        $this->assertSame(1, $formalLimit);
        $this->assertSame(2, $practiceLimit);
    }

    public function test_empty_and_whitespace_strings_use_safe_default(): void
    {
        foreach (['', '   ', "\t", "\n"] as $emptyVal) {
            Config::set('freemium.fallback_quotas.formal_test', $emptyVal);
            Config::set('freemium.fallback_quotas.practice_session', $emptyVal);

            $this->assertSame(1, $this->service->resolveConfiguredQuota('freemium.fallback_quotas.formal_test', 1));
            $this->assertSame(2, $this->service->resolveConfiguredQuota('freemium.fallback_quotas.practice_session', 2));
        }
    }

    public function test_non_numeric_strings_use_safe_default(): void
    {
        foreach (['abc', 'invalid', 'foo-bar', 'two', '12abc', 'abc12'] as $invalidVal) {
            Config::set('freemium.fallback_quotas.formal_test', $invalidVal);
            Config::set('freemium.fallback_quotas.practice_session', $invalidVal);

            $this->assertSame(1, $this->service->resolveConfiguredQuota('freemium.fallback_quotas.formal_test', 1));
            $this->assertSame(2, $this->service->resolveConfiguredQuota('freemium.fallback_quotas.practice_session', 2));
        }
    }

    public function test_decimal_strings_rejected_and_use_safe_default(): void
    {
        foreach (['1.5', '2.0', '0.5', '3.14', '0.0'] as $decimalVal) {
            Config::set('freemium.fallback_quotas.formal_test', $decimalVal);
            Config::set('freemium.fallback_quotas.practice_session', $decimalVal);

            $this->assertSame(1, $this->service->resolveConfiguredQuota('freemium.fallback_quotas.formal_test', 1));
            $this->assertSame(2, $this->service->resolveConfiguredQuota('freemium.fallback_quotas.practice_session', 2));
        }
    }

    public function test_negative_integers_including_minus_one_rejected_and_use_safe_default(): void
    {
        // BOTH integers and strings representing negative numbers, including -1, must be rejected
        foreach ([-1, '-1', -5, '-5', -100, '-100'] as $negativeVal) {
            Config::set('freemium.fallback_quotas.formal_test', $negativeVal);
            Config::set('freemium.fallback_quotas.practice_session', $negativeVal);

            $this->assertSame(1, $this->service->resolveConfiguredQuota('freemium.fallback_quotas.formal_test', 1), "Failed for formal_test value: {$negativeVal}");
            $this->assertSame(2, $this->service->resolveConfiguredQuota('freemium.fallback_quotas.practice_session', 2), "Failed for practice_session value: {$negativeVal}");
        }
    }

    public function test_zero_quota_preserved_as_intentional_disablement(): void
    {
        foreach ([0, '0', '  0  '] as $zeroVal) {
            Config::set('freemium.fallback_quotas.formal_test', $zeroVal);
            Config::set('freemium.fallback_quotas.practice_session', $zeroVal);

            $this->assertSame(0, $this->service->resolveConfiguredQuota('freemium.fallback_quotas.formal_test', 1));
            $this->assertSame(0, $this->service->resolveConfiguredQuota('freemium.fallback_quotas.practice_session', 2));
        }
    }

    public function test_positive_integers_accepted(): void
    {
        $cases = [
            [1, '1', 1],
            [3, '3', 3],
            [10, '10', 10],
            ['  5  ', '  5  ', 5],
        ];

        foreach ($cases as [$valTest, $valPractice, $expected]) {
            Config::set('freemium.fallback_quotas.formal_test', $valTest);
            Config::set('freemium.fallback_quotas.practice_session', $valPractice);

            $this->assertSame($expected, $this->service->resolveConfiguredQuota('freemium.fallback_quotas.formal_test', 1));
            $this->assertSame($expected, $this->service->resolveConfiguredQuota('freemium.fallback_quotas.practice_session', 2));
        }
    }

    public function test_boolean_true_tokens_accepted(): void
    {
        foreach ([true, 1, '1', 'true', 'TRUE', 'True', 'yes', 'YES', 'on', 'ON', '  true  '] as $truthyVal) {
            Config::set('freemium.policy.allow_retakes', $truthyVal);
            $this->assertTrue(
                $this->service->resolveConfiguredBoolean('freemium.policy.allow_retakes', false),
                "Failed asserting true for token: " . var_export($truthyVal, true)
            );
        }
    }

    public function test_boolean_false_tokens_accepted(): void
    {
        foreach ([false, 0, '0', 'false', 'FALSE', 'False', 'no', 'NO', 'off', 'OFF', '  false  '] as $falsyVal) {
            Config::set('freemium.policy.allow_retakes', $falsyVal);
            $this->assertFalse(
                $this->service->resolveConfiguredBoolean('freemium.policy.allow_retakes', true),
                "Failed asserting false for token: " . var_export($falsyVal, true)
            );
        }
    }

    public function test_invalid_boolean_strings_fallback_to_safe_default(): void
    {
        foreach (['abc', 'invalid', '2', '-1', 'null', ''] as $invalidVal) {
            Config::set('freemium.policy.allow_retakes', $invalidVal);
            // Must return safe default (false)
            $this->assertFalse(
                $this->service->resolveConfiguredBoolean('freemium.policy.allow_retakes', false),
                "Failed asserting fallback false for token: " . var_export($invalidVal, true)
            );
        }
    }
}
