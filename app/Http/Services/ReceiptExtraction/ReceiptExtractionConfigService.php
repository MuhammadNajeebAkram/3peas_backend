<?php

namespace App\Http\Services\ReceiptExtraction;

use App\Models\AiFeatureSetting;
use App\Models\AiFeatureSettingAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReceiptExtractionConfigService
{
    public function __construct(
        private readonly ReceiptExtractionCredentialResolver $credentialResolver,
    ) {}

    /**
     * Returns the effective configuration for receipt extraction in the current APP_ENV.
     * Falls back cleanly to config/services.php defaults when no DB row exists yet.
     *
     * @return array{
     *   feature:string,
     *   environment:string,
     *   app_env:string,
     *   provider:string,
     *   model:string,
     *   is_enabled:bool,
     *   timeout:int,
     *   max_output_tokens:int,
     *   source:string,
     *   updated_by:int|null,
     *   updated_by_name:string|null,
     *   updated_at:string|null
     * }
     */
    public function getConfig(): array
    {
        $environment = $this->credentialResolver->currentEnvironment();
        $appEnv = $this->credentialResolver->rawAppEnv();
        $defaults = $this->defaults();

        if (! Schema::hasTable('ai_feature_settings')) {
            return array_merge($defaults, [
                'feature' => ReceiptExtractionCredentialResolver::FEATURE_RECEIPT_EXTRACTION,
                'environment' => $environment,
                'app_env' => $appEnv,
                'source' => 'default',
                'updated_by' => null,
                'updated_by_name' => null,
                'updated_at' => null,
            ]);
        }

        $record = AiFeatureSetting::query()
            ->with('updatedByAdmin:id,name')
            ->where('feature', ReceiptExtractionCredentialResolver::FEATURE_RECEIPT_EXTRACTION)
            ->where('environment', $environment)
            ->first();

        if (! $record) {
            return array_merge($defaults, [
                'feature' => ReceiptExtractionCredentialResolver::FEATURE_RECEIPT_EXTRACTION,
                'environment' => $environment,
                'app_env' => $appEnv,
                'source' => 'default',
                'updated_by' => null,
                'updated_by_name' => null,
                'updated_at' => null,
            ]);
        }

        return [
            'feature' => $record->feature,
            'environment' => $record->environment,
            'app_env' => $appEnv,
            'provider' => $record->provider,
            'model' => $record->model,
            'is_enabled' => (bool) $record->is_enabled,
            'timeout' => (int) $record->timeout,
            'max_output_tokens' => (int) $record->max_output_tokens,
            'source' => 'database',
            'updated_by' => $record->updated_by,
            'updated_by_name' => $record->updatedByAdmin?->name,
            'updated_at' => $record->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Persists settings strictly for the current backend APP_ENV and writes an audit record.
     *
     * @param  array{provider:string,model:string,is_enabled:bool,timeout:int,max_output_tokens:int}  $validated
     */
    public function updateConfig(array $validated, int $adminUserId): AiFeatureSetting
    {
        $environment = $this->credentialResolver->currentEnvironment();

        return DB::transaction(function () use ($validated, $adminUserId, $environment): AiFeatureSetting {
            $existing = AiFeatureSetting::query()
                ->where('feature', ReceiptExtractionCredentialResolver::FEATURE_RECEIPT_EXTRACTION)
                ->where('environment', $environment)
                ->lockForUpdate()
                ->first();

            $previous = $existing ? [
                'provider' => $existing->provider,
                'model' => $existing->model,
                'is_enabled' => (bool) $existing->is_enabled,
                'timeout' => (int) $existing->timeout,
                'max_output_tokens' => (int) $existing->max_output_tokens,
            ] : null;

            $setting = $existing ?? new AiFeatureSetting([
                'feature' => ReceiptExtractionCredentialResolver::FEATURE_RECEIPT_EXTRACTION,
                'environment' => $environment,
            ]);

            $setting->fill([
                'provider' => strtolower(trim($validated['provider'])),
                'model' => trim($validated['model']),
                'is_enabled' => (bool) $validated['is_enabled'],
                'timeout' => (int) $validated['timeout'],
                'max_output_tokens' => (int) $validated['max_output_tokens'],
                'updated_by' => $adminUserId,
            ]);
            $setting->save();

            if (Schema::hasTable('ai_feature_setting_audits')) {
                AiFeatureSettingAudit::create([
                    'feature' => ReceiptExtractionCredentialResolver::FEATURE_RECEIPT_EXTRACTION,
                    'environment' => $environment,
                    'admin_user_id' => $adminUserId,
                    'previous_provider' => $previous['provider'] ?? null,
                    'new_provider' => $setting->provider,
                    'previous_model' => $previous['model'] ?? null,
                    'new_model' => $setting->model,
                    'previous_is_enabled' => $previous['is_enabled'] ?? null,
                    'new_is_enabled' => (bool) $setting->is_enabled,
                    'previous_timeout' => $previous['timeout'] ?? null,
                    'new_timeout' => (int) $setting->timeout,
                    'previous_max_output_tokens' => $previous['max_output_tokens'] ?? null,
                    'new_max_output_tokens' => (int) $setting->max_output_tokens,
                    'created_at' => now(),
                ]);
            }

            return $setting->fresh(['updatedByAdmin:id,name']);
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentAudits(int $limit = 10): array
    {
        if (! Schema::hasTable('ai_feature_setting_audits')) {
            return [];
        }

        $environment = $this->credentialResolver->currentEnvironment();

        return AiFeatureSettingAudit::query()
            ->with('adminUser:id,name')
            ->where('feature', ReceiptExtractionCredentialResolver::FEATURE_RECEIPT_EXTRACTION)
            ->where('environment', $environment)
            ->orderByDesc('id')
            ->limit(max(1, min(50, $limit)))
            ->get()
            ->map(fn (AiFeatureSettingAudit $audit) => [
                'id' => $audit->id,
                'environment' => $audit->environment,
                'admin_user_id' => $audit->admin_user_id,
                'admin_name' => $audit->adminUser?->name,
                'previous_provider' => $audit->previous_provider,
                'new_provider' => $audit->new_provider,
                'previous_model' => $audit->previous_model,
                'new_model' => $audit->new_model,
                'previous_is_enabled' => $audit->previous_is_enabled,
                'new_is_enabled' => (bool) $audit->new_is_enabled,
                'previous_timeout' => $audit->previous_timeout,
                'new_timeout' => $audit->new_timeout,
                'previous_max_output_tokens' => $audit->previous_max_output_tokens,
                'new_max_output_tokens' => $audit->new_max_output_tokens,
                'created_at' => $audit->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{provider:string,model:string,is_enabled:bool,timeout:int,max_output_tokens:int}
     */
    private function defaults(): array
    {
        return [
            'provider' => strtolower(trim((string) config('services.receipt_extraction_ai.default_provider', 'openai'))),
            'model' => trim((string) config('services.receipt_extraction_ai.default_model', 'gpt-6-luna')),
            'is_enabled' => (bool) config('services.receipt_extraction_ai.default_enabled', true),
            'timeout' => max(5, min(60, (int) config('services.receipt_extraction_ai.default_timeout', 25))),
            'max_output_tokens' => max(150, min(1000, (int) config('services.receipt_extraction_ai.default_max_output_tokens', 400))),
        ];
    }
}
