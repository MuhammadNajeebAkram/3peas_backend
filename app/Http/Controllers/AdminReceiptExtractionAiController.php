<?php

namespace App\Http\Controllers;

use App\Exceptions\ReceiptExtractionException;
use App\Http\Services\ReceiptExtraction\ReceiptExtractionCatalogService;
use App\Http\Services\ReceiptExtraction\ReceiptExtractionConfigService;
use App\Http\Services\ReceiptExtraction\ReceiptExtractionCredentialResolver;
use App\Http\Services\ReceiptExtraction\ReceiptExtractionProviderFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminReceiptExtractionAiController extends Controller
{
    public function __construct(
        private readonly ReceiptExtractionConfigService $configService,
        private readonly ReceiptExtractionCredentialResolver $credentialResolver,
        private readonly ReceiptExtractionProviderFactory $providerFactory,
        private readonly ReceiptExtractionCatalogService $catalogService,
    ) {}

    public function show(): JsonResponse
    {
        try {
            $environment = $this->credentialResolver->currentEnvironment();
            $config = $this->configService->getConfig();
            $providers = $this->catalogService->availableProviders();
            $models = $this->catalogService->availableModelsForProvider($config['provider']);
        } catch (ReceiptExtractionException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode(),
            ], $exception->statusCode());
        }

        return response()->json([
            'environment' => $environment,
            'app_env' => $this->credentialResolver->rawAppEnv(),
            'settings' => $config,
            'providers' => $providers,
            'models' => $models,
            'credential_configured' => $this->credentialResolver->hasCredential($config['provider']),
            'recent_audits' => $this->configService->recentAudits(10),
        ]);
    }

    public function models(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', 'max:50'],
        ]);

        try {
            $environment = $this->credentialResolver->currentEnvironment();
            $providerKey = strtolower(trim($validated['provider']));
            $models = $this->catalogService->availableModelsForProvider($providerKey);
        } catch (ReceiptExtractionException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode(),
            ], $exception->statusCode());
        }

        return response()->json([
            'environment' => $environment,
            'provider' => $providerKey,
            'models' => $models,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'environment' => ['prohibited'],
            'app_env' => ['prohibited'],
            'api_key' => ['prohibited'],
            'provider' => ['required', 'string', 'max:50'],
            'model' => ['required', 'string', 'max:150'],
            'is_enabled' => ['required', 'boolean'],
            'timeout' => ['required', 'integer', 'min:5', 'max:60'],
            'max_output_tokens' => ['required', 'integer', 'min:150', 'max:1000'],
        ], [
            'environment.prohibited' => 'The backend environment cannot be changed from the Admin Panel.',
            'app_env.prohibited' => 'The backend environment cannot be changed from the Admin Panel.',
            'api_key.prohibited' => 'API keys must be configured in server environment variables, not submitted via API.',
        ]);

        $resolved = $this->catalogService->validateActiveProviderAndModel(
            $validated['provider'],
            $validated['model']
        );

        $canonicalProvider = (string) $resolved['provider']->key;
        $canonicalModel = (string) $resolved['model']->model_key;

        try {
            $environment = $this->credentialResolver->currentEnvironment();

            if ((bool) $validated['is_enabled'] && ! $this->credentialResolver->hasCredential($canonicalProvider)) {
                return response()->json([
                    'message' => 'Cannot enable receipt extraction for this provider because server API credentials are not configured for the current environment.',
                    'code' => 'PROVIDER_CREDENTIAL_MISSING',
                    'errors' => [
                        'provider' => ['Server API credentials are not configured for this provider in the current environment.'],
                    ],
                ], 422);
            }

            $adminId = (int) (auth('api')->id() ?: $request->user()?->id);
            $this->configService->updateConfig([
                'provider' => $canonicalProvider,
                'model' => $canonicalModel,
                'is_enabled' => (bool) $validated['is_enabled'],
                'timeout' => (int) $validated['timeout'],
                'max_output_tokens' => (int) $validated['max_output_tokens'],
            ], $adminId);

            $config = $this->configService->getConfig();
            $providers = $this->catalogService->availableProviders();
            $models = $this->catalogService->availableModelsForProvider($config['provider']);
        } catch (ReceiptExtractionException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode(),
            ], $exception->statusCode());
        }

        return response()->json([
            'message' => 'Receipt extraction AI settings saved successfully.',
            'environment' => $environment,
            'app_env' => $this->credentialResolver->rawAppEnv(),
            'settings' => $config,
            'providers' => $providers,
            'models' => $models,
            'credential_configured' => $this->credentialResolver->hasCredential($config['provider']),
            'recent_audits' => $this->configService->recentAudits(10),
        ]);
    }

    public function testConnection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'environment' => ['prohibited'],
            'app_env' => ['prohibited'],
            'api_key' => ['prohibited'],
            'provider' => ['required', 'string', 'max:50'],
            'model' => ['required', 'string', 'max:150'],
            'timeout' => ['sometimes', 'integer', 'min:5', 'max:60'],
        ], [
            'environment.prohibited' => 'The backend environment cannot be changed from the Admin Panel.',
            'app_env.prohibited' => 'The backend environment cannot be changed from the Admin Panel.',
            'api_key.prohibited' => 'API keys must be configured in server environment variables, not submitted via API.',
        ]);

        $resolved = $this->catalogService->validateActiveProviderAndModel(
            $validated['provider'],
            $validated['model']
        );

        $canonicalProvider = (string) $resolved['provider']->key;
        $canonicalModel = (string) $resolved['model']->model_key;

        try {
            $environment = $this->credentialResolver->currentEnvironment();
            $adapter = $this->providerFactory->resolve($canonicalProvider);
            $apiKey = $this->credentialResolver->resolveApiKey($canonicalProvider);
            $result = $adapter->testConnection($canonicalModel, $apiKey, [
                'timeout' => (int) ($validated['timeout'] ?? 15),
            ]);
        } catch (ReceiptExtractionException $exception) {
            $envLabel = null;
            try {
                $envLabel = $this->credentialResolver->currentEnvironment();
            } catch (ReceiptExtractionException) {
                $envLabel = null;
            }

            return response()->json([
                'ok' => false,
                'environment' => $envLabel,
                'provider' => $canonicalProvider,
                'model' => $canonicalModel,
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ], $exception->statusCode());
        }

        return response()->json([
            'ok' => true,
            'environment' => $environment,
            'provider' => $canonicalProvider,
            'model' => $canonicalModel,
            'message' => $result['message'],
            'latency_ms' => $result['latency_ms'],
        ]);
    }
}
