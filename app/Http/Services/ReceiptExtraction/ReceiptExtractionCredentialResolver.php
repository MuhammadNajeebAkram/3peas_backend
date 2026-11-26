<?php

namespace App\Http\Services\ReceiptExtraction;

use App\Exceptions\ReceiptExtractionException;

class ReceiptExtractionCredentialResolver
{
    public const FEATURE_RECEIPT_EXTRACTION = 'receipt_extraction';

    public const ENV_DEVELOPMENT = 'development';

    public const ENV_STAGING = 'staging';

    public const ENV_PRODUCTION = 'production';

    private const DEVELOPMENT_APP_ENVS = ['local', 'development', 'testing'];

    /**
     * Centrally registered AI features mapped to their stable environment-variable segments.
     * Arbitrary feature names are rejected to prevent environment variable injection.
     *
     * @var array<string, string>
     */
    private const REGISTERED_FEATURES = [
        self::FEATURE_RECEIPT_EXTRACTION => 'RECEIPT_EXTRACTION',
    ];

    public function __construct(
        private readonly ReceiptExtractionProviderFactory $providerFactory,
    ) {}

    /**
     * Strictly resolves the active environment from Laravel APP_ENV using an explicit allowlist.
     * Fails closed on unrecognized or misconfigured APP_ENV values; never accepts user/Admin input.
     *
     * @throws ReceiptExtractionException
     */
    public function currentEnvironment(): string
    {
        $appEnv = strtolower(trim((string) config('app.env', '')));

        if ($appEnv === 'production') {
            return self::ENV_PRODUCTION;
        }

        if ($appEnv === 'staging') {
            return self::ENV_STAGING;
        }

        if (in_array($appEnv, self::DEVELOPMENT_APP_ENVS, true)) {
            return self::ENV_DEVELOPMENT;
        }

        throw new ReceiptExtractionException(
            'UNSUPPORTED_ENVIRONMENT',
            'Receipt extraction AI cannot run because the backend environment configuration is unrecognized.',
            503
        );
    }

    public function rawAppEnv(): string
    {
        return (string) config('app.env', '');
    }

    /**
     * Normalizes a database provider key to a safe uppercase environment-variable segment.
     *
     * @throws ReceiptExtractionException
     */
    public function normalizeProviderSegment(string $providerKey): string
    {
        $normalized = strtolower(trim($providerKey));

        if (! preg_match('/^[a-z][a-z0-9_]{1,49}$/', $normalized)) {
            throw new ReceiptExtractionException(
                'UNSUPPORTED_PROVIDER',
                'The selected AI provider identifier is invalid or unsupported.',
                503
            );
        }

        return strtoupper($normalized);
    }

    /**
     * Resolves a centrally registered AI feature key to its environment-variable segment.
     *
     * @throws ReceiptExtractionException
     */
    public function resolveFeatureSegment(string $feature): string
    {
        $normalized = strtolower(trim($feature));
        $segment = self::REGISTERED_FEATURES[$normalized] ?? null;

        if ($segment === null) {
            throw new ReceiptExtractionException(
                'UNSUPPORTED_FEATURE',
                'The requested AI feature is not registered for credential resolution.',
                503
            );
        }

        return $segment;
    }

    /**
     * Dynamically constructs the standardized API key environment variable name:
     * - Development: AI_{PROVIDER}_DEV_KEY
     * - Staging:     AI_{PROVIDER}_{FEATURE}_STAGING_KEY
     * - Production:  AI_{PROVIDER}_{FEATURE}_PROD_KEY
     *
     * @throws ReceiptExtractionException
     */
    public function buildEnvVariableName(string $providerKey, string $feature = self::FEATURE_RECEIPT_EXTRACTION): string
    {
        $providerSegment = $this->normalizeProviderSegment($providerKey);
        $featureSegment = $this->resolveFeatureSegment($feature);
        $environment = $this->currentEnvironment();

        return match ($environment) {
            self::ENV_DEVELOPMENT => "AI_{$providerSegment}_DEV_KEY",
            self::ENV_STAGING => "AI_{$providerSegment}_{$featureSegment}_STAGING_KEY",
            self::ENV_PRODUCTION => "AI_{$providerSegment}_{$featureSegment}_PROD_KEY",
        };
    }

    public function resolveApiKey(string $provider, string $feature = self::FEATURE_RECEIPT_EXTRACTION): string
    {
        $providerKey = strtolower(trim($provider));
        $this->normalizeProviderSegment($providerKey);

        if ($feature === self::FEATURE_RECEIPT_EXTRACTION
            && ! in_array($providerKey, $this->providerFactory->supportedProviderKeys(), true)) {
            throw new ReceiptExtractionException(
                'UNSUPPORTED_PROVIDER',
                'The selected AI provider is not supported.',
                503
            );
        }

        $envVariableName = $this->buildEnvVariableName($providerKey, $feature);
        $apiKey = $this->readCredentialValue($envVariableName);

        if ($apiKey === '') {
            throw new ReceiptExtractionException(
                'PROVIDER_CREDENTIAL_MISSING',
                'API credentials for the selected AI provider are not configured for the current environment.',
                503
            );
        }

        return $apiKey;
    }

    public function hasCredential(string $provider, string $feature = self::FEATURE_RECEIPT_EXTRACTION): bool
    {
        try {
            $this->resolveApiKey($provider, $feature);

            return true;
        } catch (ReceiptExtractionException) {
            return false;
        }
    }

    private const PLACEHOLDER_VALUES = [
        'null',
        '(null)',
        'empty',
        '(empty)',
        'false',
        '(false)',
    ];

    private function readCredentialValue(string $envVariableName): string
    {
        $cachedCredentials = config('services.ai_credentials', []);
        if (is_array($cachedCredentials) && array_key_exists($envVariableName, $cachedCredentials)) {
            return $this->sanitizeCredentialValue($cachedCredentials[$envVariableName]);
        }

        if (! app()->configurationIsCached()) {
            return $this->sanitizeCredentialValue(env($envVariableName));
        }

        return '';
    }

    private function sanitizeCredentialValue(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $trimmed = trim($value);
        if ($trimmed === '' || in_array(strtolower($trimmed), self::PLACEHOLDER_VALUES, true)) {
            return '';
        }

        return $trimmed;
    }
}
