<?php

namespace App\Http\Services\ReceiptExtraction;

use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ReceiptExtractionCatalogService
{
    public function __construct(
        private readonly ReceiptExtractionProviderFactory $providerFactory,
        private readonly ReceiptExtractionCredentialResolver $credentialResolver,
    ) {}

    public function isCatalogAvailable(): bool
    {
        return Schema::hasTable('ai_providers') && Schema::hasTable('ai_models');
    }

    /**
     * Active AI providers from `ai_providers` that are supported by the receipt extraction adapter registry.
     * Never exposes API keys or raw provider settings.
     *
     * @return array<int, array{id:int,key:string,label:string,is_active:bool,credential_configured:bool}>
     */
    public function availableProviders(): array
    {
        if (! Schema::hasTable('ai_providers')) {
            return [];
        }

        $supportedKeys = $this->providerFactory->supportedProviderKeys();

        return AiProvider::query()
            ->where('is_active', true)
            ->whereIn('key', $supportedKeys)
            ->orderBy('id')
            ->get(['id', 'key', 'name', 'is_active'])
            ->map(fn (AiProvider $provider) => [
                'id' => (int) $provider->id,
                'key' => (string) $provider->key,
                'label' => (string) $provider->name,
                'is_active' => (bool) $provider->is_active,
                'credential_configured' => $this->credentialResolver->hasCredential((string) $provider->key),
            ])
            ->values()
            ->all();
    }

    /**
     * Active, non-deleted models from `ai_models` belonging to an active supported provider.
     *
     * @return array<int, array{id:int,ai_provider_id:int,provider_key:string,name:string,model_key:string,is_default:bool}>
     */
    public function availableModelsForProvider(string $providerKey): array
    {
        if (! $this->isCatalogAvailable()) {
            return [];
        }

        $normalizedKey = strtolower(trim($providerKey));
        if (! in_array($normalizedKey, $this->providerFactory->supportedProviderKeys(), true)) {
            return [];
        }

        $provider = AiProvider::query()
            ->where('key', $normalizedKey)
            ->where('is_active', true)
            ->first(['id', 'key']);

        if (! $provider) {
            return [];
        }

        return AiModel::query()
            ->where('ai_provider_id', $provider->id)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'ai_provider_id', 'name', 'model_key', 'is_default'])
            ->map(fn (AiModel $model) => [
                'id' => (int) $model->id,
                'ai_provider_id' => (int) $model->ai_provider_id,
                'provider_key' => (string) $provider->key,
                'name' => (string) $model->name,
                'model_key' => (string) $model->model_key,
                'is_default' => (bool) $model->is_default,
            ])
            ->values()
            ->all();
    }

    /**
     * Validates that provider & model exist, are active, are linked via ai_provider_id,
     * and are supported by the receipt extraction adapter registry.
     *
     * @return array{provider:AiProvider,model:AiModel}
     * @throws ValidationException
     */
    public function validateActiveProviderAndModel(string $providerKey, string $modelKey): array
    {
        $normalizedProvider = strtolower(trim($providerKey));
        $normalizedModel = trim($modelKey);

        if (! in_array($normalizedProvider, $this->providerFactory->supportedProviderKeys(), true)) {
            throw ValidationException::withMessages([
                'provider' => ['The selected AI provider is not supported for receipt extraction.'],
            ]);
        }

        if (! $this->isCatalogAvailable()) {
            throw ValidationException::withMessages([
                'provider' => ['AI provider and model catalog tables are not available.'],
            ]);
        }

        $provider = AiProvider::query()
            ->where('key', $normalizedProvider)
            ->where('is_active', true)
            ->first();

        if (! $provider) {
            throw ValidationException::withMessages([
                'provider' => ['The selected AI provider does not exist or is currently inactive.'],
            ]);
        }

        $candidateKeys = [$normalizedModel];
        if ($normalizedProvider === 'gemini') {
            $stripped = preg_replace('/^models\//i', '', $normalizedModel) ?? $normalizedModel;
            if ($stripped !== $normalizedModel) {
                $candidateKeys[] = $stripped;
            }
        }

        $model = AiModel::query()
            ->where('ai_provider_id', $provider->id)
            ->where('is_active', true)
            ->whereIn('model_key', $candidateKeys)
            ->first();

        if (! $model) {
            throw ValidationException::withMessages([
                'model' => ['The selected model is inactive, unavailable, or does not belong to the selected AI provider.'],
            ]);
        }

        return [
            'provider' => $provider,
            'model' => $model,
        ];
    }

    public function isActiveProviderAndModel(string $providerKey, string $modelKey): bool
    {
        try {
            $this->validateActiveProviderAndModel($providerKey, $modelKey);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }
}
