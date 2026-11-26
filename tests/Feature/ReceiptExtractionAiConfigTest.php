<?php

namespace Tests\Feature;

use App\Exceptions\ReceiptExtractionException;
use App\Http\Middleware\AttachJwtFromCookie;
use App\Http\Middleware\AuthenticateJwtCookieGuard;
use App\Http\Services\PaymentReceiptOcrService;
use App\Http\Services\ReceiptExtraction\GeminiReceiptExtractionProvider;
use App\Http\Services\ReceiptExtraction\ReceiptExtractionCredentialResolver;
use App\Http\Services\ReceiptExtraction\ReceiptExtractionProviderFactory;
use App\Models\AiFeatureSetting;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WebUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class ReceiptExtractionAiConfigTest extends TestCase
{
    private User $superAdmin;

    private User $authorizedAdmin;

    private User $readOnlyAdmin;

    private User $unauthorizedAdmin;

    private WebUser $student;

    private AiProvider $openaiProvider;

    private AiProvider $geminiProvider;

    private AiProvider $qwenProvider;

    private AiProvider $inactiveProvider;

    private AiProvider $unsupportedProvider;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.env' => 'local',
            'database.default' => 'receipt_ai_config_test',
            'database.connections.receipt_ai_config_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'services.ai_credentials' => [
                'AI_OPENAI_DEV_KEY' => 'dev-openai-secret-key',
                'AI_GEMINI_DEV_KEY' => 'dev-gemini-secret-key',
                'AI_QWEN_DEV_KEY' => 'dev-qwen-secret-key',
                'AI_OPENAI_RECEIPT_EXTRACTION_STAGING_KEY' => 'staging-openai-receipt-secret-key',
                'AI_GEMINI_RECEIPT_EXTRACTION_STAGING_KEY' => 'staging-gemini-receipt-secret-key',
                'AI_QWEN_RECEIPT_EXTRACTION_STAGING_KEY' => 'staging-qwen-receipt-secret-key',
                'AI_OPENAI_RECEIPT_EXTRACTION_PROD_KEY' => 'prod-openai-receipt-secret-key',
                'AI_GEMINI_RECEIPT_EXTRACTION_PROD_KEY' => 'prod-gemini-receipt-secret-key',
                'AI_QWEN_RECEIPT_EXTRACTION_PROD_KEY' => 'prod-qwen-receipt-secret-key',
            ],
            'services.receipt_extraction_ai' => [
                'default_provider' => 'openai',
                'default_model' => 'gpt-6-luna',
                'default_enabled' => true,
                'default_timeout' => 25,
                'default_max_output_tokens' => 400,
                'providers' => [
                    'openai' => [
                        'label' => 'OpenAI',
                        'base_url' => 'https://api.openai.com/v1',
                    ],
                    'gemini' => [
                        'label' => 'Google Gemini',
                        'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
                    ],
                    'qwen' => [
                        'label' => 'Qwen',
                        'base_url' => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1',
                    ],
                ],
            ],
        ]);

        DB::purge('receipt_ai_config_test');

        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });

        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $t) {
            $t->unsignedBigInteger('role_id');
            $t->unsignedBigInteger('permission_id');
            $t->primary(['role_id', 'permission_id']);
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password')->nullable();
            $t->unsignedBigInteger('role_id')->nullable();
            $t->timestamps();
        });

        Schema::create('web_users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('role');
            $t->string('status');
            $t->string('phone')->nullable();
            $t->string('last_token')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('ai_providers', function (Blueprint $t) {
            $t->id();
            $t->string('key', 50)->unique();
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->json('settings')->nullable();
            $t->timestamps();
        });

        Schema::create('ai_models', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ai_provider_id')->constrained('ai_providers');
            $t->string('name');
            $t->string('model_key', 150);
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_default')->default(false);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['ai_provider_id', 'model_key']);
        });

        Schema::create('subscription_payment_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('status')->default('pending');
            $t->timestamps();
        });

        Schema::create('financial_transactions', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->nullable();
            $t->timestamps();
        });

        (require database_path('migrations/2026_11_26_000002_create_ai_feature_settings_table.php'))->up();

        $this->openaiProvider = AiProvider::create(['key' => 'openai', 'name' => 'OpenAI', 'is_active' => true]);
        $this->geminiProvider = AiProvider::create(['key' => 'gemini', 'name' => 'Google Gemini', 'is_active' => true]);
        $this->qwenProvider = AiProvider::create(['key' => 'qwen', 'name' => 'Qwen', 'is_active' => true]);
        $this->inactiveProvider = AiProvider::create(['key' => 'openai_disabled', 'name' => 'Disabled Provider', 'is_active' => false]);
        $this->unsupportedProvider = AiProvider::create(['key' => 'anthropic', 'name' => 'Anthropic', 'is_active' => true]);

        AiModel::create(['ai_provider_id' => $this->openaiProvider->id, 'name' => 'GPT-6 Luna', 'model_key' => 'gpt-6-luna', 'is_active' => true, 'is_default' => true]);
        AiModel::create(['ai_provider_id' => $this->openaiProvider->id, 'name' => 'GPT-4o Inactive', 'model_key' => 'gpt-4o-old', 'is_active' => false]);
        AiModel::create(['ai_provider_id' => $this->geminiProvider->id, 'name' => 'Gemini 2.5 Flash', 'model_key' => 'gemini-2.5-flash', 'is_active' => true]);
        AiModel::create(['ai_provider_id' => $this->qwenProvider->id, 'name' => 'Qwen Plus', 'model_key' => 'qwen-plus', 'is_active' => true]);
        AiModel::create(['ai_provider_id' => $this->unsupportedProvider->id, 'name' => 'Claude 3', 'model_key' => 'claude-3', 'is_active' => true]);

        $viewPerm = Permission::create(['name' => 'receipt-extraction-ai.view']);
        $updatePerm = Permission::create(['name' => 'receipt-extraction-ai.update']);
        $testPerm = Permission::create(['name' => 'receipt-extraction-ai.test']);

        $superRole = Role::create(['name' => 'super_admin']);
        $managerRole = Role::create(['name' => 'ai_settings_manager']);
        $managerRole->permissions()->attach([$viewPerm->id, $updatePerm->id, $testPerm->id]);

        $viewerRole = Role::create(['name' => 'ai_settings_viewer']);
        $viewerRole->permissions()->attach([$viewPerm->id]);

        $noPermRole = Role::create(['name' => 'basic_staff']);

        $this->superAdmin = User::create(['name' => 'Super Admin', 'email' => 'super@test.local', 'role_id' => $superRole->id]);
        $this->authorizedAdmin = User::create(['name' => 'AI Manager', 'email' => 'manager@test.local', 'role_id' => $managerRole->id]);
        $this->readOnlyAdmin = User::create(['name' => 'AI Viewer', 'email' => 'viewer@test.local', 'role_id' => $viewerRole->id]);
        $this->unauthorizedAdmin = User::create(['name' => 'Basic Staff', 'email' => 'staff@test.local', 'role_id' => $noPermRole->id]);

        $this->student = WebUser::create([
            'name' => 'Student One',
            'role' => 'student',
            'status' => 'active',
        ]);
    }

    private function actingAsAdmin(User $admin): void
    {
        $this->withoutMiddleware([AttachJwtFromCookie::class, AuthenticateJwtCookieGuard::class]);
        $this->actingAs($admin, 'api');
    }

    private function actingAsStudent(): void
    {
        $this->withoutMiddleware([AttachJwtFromCookie::class, AuthenticateJwtCookieGuard::class]);
        $this->actingAs($this->student, 'web_api');
    }

    private function fakeJpegUpload(): UploadedFile
    {
        $jpegBytes = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";

        return UploadedFile::fake()->createWithContent('receipt.jpg', $jpegBytes);
    }

    private function mockOcrReturns(string $text): void
    {
        $mock = Mockery::mock(PaymentReceiptOcrService::class);
        $mock->shouldReceive('extractText')->andReturn($text);
        $this->app->instance(PaymentReceiptOcrService::class, $mock);
    }

    public function test_credential_resolver_handles_development_staging_production_and_fails_closed_on_unknown_env(): void
    {
        $resolver = app(ReceiptExtractionCredentialResolver::class);

        foreach (['local', 'development', 'testing'] as $devEnv) {
            config(['app.env' => $devEnv]);
            $this->assertSame('development', $resolver->currentEnvironment());
            $this->assertSame('AI_OPENAI_DEV_KEY', $resolver->buildEnvVariableName('openai'));
            $this->assertSame('AI_GEMINI_DEV_KEY', $resolver->buildEnvVariableName('gemini'));
            $this->assertSame('AI_QWEN_DEV_KEY', $resolver->buildEnvVariableName('qwen'));
            $this->assertSame('dev-openai-secret-key', $resolver->resolveApiKey('openai'));
            $this->assertSame('dev-gemini-secret-key', $resolver->resolveApiKey('gemini'));
            $this->assertSame('dev-qwen-secret-key', $resolver->resolveApiKey('qwen'));
        }

        config(['app.env' => 'staging']);
        $this->assertSame('staging', $resolver->currentEnvironment());
        $this->assertSame('AI_OPENAI_RECEIPT_EXTRACTION_STAGING_KEY', $resolver->buildEnvVariableName('openai'));
        $this->assertSame('AI_GEMINI_RECEIPT_EXTRACTION_STAGING_KEY', $resolver->buildEnvVariableName('gemini'));
        $this->assertSame('AI_QWEN_RECEIPT_EXTRACTION_STAGING_KEY', $resolver->buildEnvVariableName('qwen'));
        $this->assertSame('staging-openai-receipt-secret-key', $resolver->resolveApiKey('openai'));
        $this->assertSame('staging-gemini-receipt-secret-key', $resolver->resolveApiKey('gemini'));
        $this->assertSame('staging-qwen-receipt-secret-key', $resolver->resolveApiKey('qwen'));

        config(['app.env' => 'production']);
        $this->assertSame('production', $resolver->currentEnvironment());
        $this->assertSame('AI_OPENAI_RECEIPT_EXTRACTION_PROD_KEY', $resolver->buildEnvVariableName('openai'));
        $this->assertSame('AI_GEMINI_RECEIPT_EXTRACTION_PROD_KEY', $resolver->buildEnvVariableName('gemini'));
        $this->assertSame('AI_QWEN_RECEIPT_EXTRACTION_PROD_KEY', $resolver->buildEnvVariableName('qwen'));
        $this->assertSame('prod-openai-receipt-secret-key', $resolver->resolveApiKey('openai'));
        $this->assertSame('prod-gemini-receipt-secret-key', $resolver->resolveApiKey('gemini'));
        $this->assertSame('prod-qwen-receipt-secret-key', $resolver->resolveApiKey('qwen'));

        // Unexpected / typo APP_ENV must fail closed (never fall back to development!)
        foreach (['prod', 'uat', 'qa', ''] as $unknownEnv) {
            config(['app.env' => $unknownEnv]);
            try {
                $resolver->currentEnvironment();
                $this->fail("Expected UNSUPPORTED_ENVIRONMENT for APP_ENV='{$unknownEnv}'.");
            } catch (ReceiptExtractionException $e) {
                $this->assertSame('UNSUPPORTED_ENVIRONMENT', $e->errorCode());
                $this->assertSame(503, $e->statusCode());
            }
        }
    }

    public function test_dynamic_credential_resolver_rejects_unsafe_providers_and_unregistered_features(): void
    {
        $resolver = app(ReceiptExtractionCredentialResolver::class);
        config(['app.env' => 'local']);

        foreach (['../bad', 'open ai', 'openai-bad', '123invalid', 'openai;rm', ''] as $unsafeProvider) {
            try {
                $resolver->buildEnvVariableName($unsafeProvider);
                $this->fail("Expected UNSUPPORTED_PROVIDER for unsafe provider '{$unsafeProvider}'.");
            } catch (ReceiptExtractionException $e) {
                $this->assertSame('UNSUPPORTED_PROVIDER', $e->errorCode());
            }
        }

        foreach (['custom_feature', '../hack', 'admin_override', ''] as $invalidFeature) {
            try {
                $resolver->resolveApiKey('openai', $invalidFeature);
                $this->fail("Expected UNSUPPORTED_FEATURE for feature '{$invalidFeature}'.");
            } catch (ReceiptExtractionException $e) {
                $this->assertSame('UNSUPPORTED_FEATURE', $e->errorCode());
            }
        }
    }

    public function test_credential_resolver_enforces_strict_environment_isolation_and_fails_safely_when_missing(): void
    {
        $resolver = app(ReceiptExtractionCredentialResolver::class);

        // Production must NEVER fall back to dev or staging key
        config([
            'app.env' => 'production',
            'services.ai_credentials.AI_OPENAI_DEV_KEY' => 'dev-openai-secret-key',
            'services.ai_credentials.AI_OPENAI_RECEIPT_EXTRACTION_STAGING_KEY' => 'staging-openai-receipt-secret-key',
            'services.ai_credentials.AI_OPENAI_RECEIPT_EXTRACTION_PROD_KEY' => '',
        ]);

        $this->assertFalse($resolver->hasCredential('openai'));

        try {
            $resolver->resolveApiKey('openai');
            $this->fail('Expected ReceiptExtractionException for missing production key.');
        } catch (ReceiptExtractionException $e) {
            $this->assertSame('PROVIDER_CREDENTIAL_MISSING', $e->errorCode());
            $this->assertStringNotContainsString('dev-openai-secret-key', $e->getMessage());
            $this->assertStringNotContainsString('staging-openai-receipt-secret-key', $e->getMessage());
        }

        // Staging must NEVER fall back to dev or production key
        config([
            'app.env' => 'staging',
            'services.ai_credentials.AI_OPENAI_DEV_KEY' => 'dev-openai-secret-key',
            'services.ai_credentials.AI_OPENAI_RECEIPT_EXTRACTION_STAGING_KEY' => '',
            'services.ai_credentials.AI_OPENAI_RECEIPT_EXTRACTION_PROD_KEY' => 'prod-openai-receipt-secret-key',
        ]);
        $this->assertFalse($resolver->hasCredential('openai'));

        // Development must NEVER fall back to staging or production key
        config([
            'app.env' => 'local',
            'services.ai_credentials.AI_OPENAI_DEV_KEY' => '',
            'services.ai_credentials.AI_OPENAI_RECEIPT_EXTRACTION_STAGING_KEY' => 'staging-openai-receipt-secret-key',
            'services.ai_credentials.AI_OPENAI_RECEIPT_EXTRACTION_PROD_KEY' => 'prod-openai-receipt-secret-key',
        ]);
        $this->assertFalse($resolver->hasCredential('openai'));

        // Unsupported provider fails safely
        $factory = app(ReceiptExtractionProviderFactory::class);
        try {
            $factory->resolve('unknown_provider');
            $this->fail('Expected ReceiptExtractionException for unsupported provider.');
        } catch (ReceiptExtractionException $e) {
            $this->assertSame('UNSUPPORTED_PROVIDER', $e->errorCode());
        }
    }

    public function test_db_driven_provider_and_model_list_apis_filter_inactive_and_unsupported_records(): void
    {
        $this->actingAsAdmin($this->readOnlyAdmin);

        // Providers endpoint returns ONLY active + supported DB providers (excludes inactive and anthropic)
        $response = $this->getJson('/api/admin/auth/receipt-extraction-ai')->assertOk();
        $providerKeys = collect($response->json('providers'))->pluck('key')->all();
        $this->assertSame(['openai', 'gemini', 'qwen'], $providerKeys);

        // Initial models list matches active models for openai (excludes gpt-4o-old)
        $initialModels = collect($response->json('models'))->pluck('model_key')->all();
        $this->assertSame(['gpt-6-luna'], $initialModels);

        // Provider-specific model list endpoint
        $this->getJson('/api/admin/auth/receipt-extraction-ai/models?provider=gemini')
            ->assertOk()
            ->assertJsonPath('provider', 'gemini')
            ->assertJsonCount(1, 'models')
            ->assertJsonPath('models.0.model_key', 'gemini-2.5-flash');

        // Unsupported or inactive provider returns empty models list
        $this->getJson('/api/admin/auth/receipt-extraction-ai/models?provider=anthropic')
            ->assertOk()
            ->assertJsonCount(0, 'models');

        // Empty catalog handled gracefully
        AiProvider::query()->update(['is_active' => false]);
        $this->getJson('/api/admin/auth/receipt-extraction-ai')
            ->assertOk()
            ->assertJsonCount(0, 'providers')
            ->assertJsonCount(0, 'models');
    }

    public function test_admin_permissions_and_settings_api_never_expose_api_keys(): void
    {
        $this->getJson('/api/admin/auth/receipt-extraction-ai')->assertUnauthorized();

        $this->actingAsAdmin($this->unauthorizedAdmin);
        $this->getJson('/api/admin/auth/receipt-extraction-ai')->assertForbidden();
        $this->getJson('/api/admin/auth/receipt-extraction-ai/models?provider=openai')->assertForbidden();
        $this->putJson('/api/admin/auth/receipt-extraction-ai', [])->assertForbidden();
        $this->postJson('/api/admin/auth/receipt-extraction-ai/test-connection', [])->assertForbidden();

        $this->actingAsAdmin($this->readOnlyAdmin);
        $viewResponse = $this->getJson('/api/admin/auth/receipt-extraction-ai')->assertOk();
        $rawBody = $viewResponse->getContent();
        $this->assertStringNotContainsString('dev-openai-secret-key', $rawBody);
        $this->assertStringNotContainsString('staging-openai-receipt-secret-key', $rawBody);
        $this->assertStringNotContainsString('prod-openai-receipt-secret-key', $rawBody);

        $this->putJson('/api/admin/auth/receipt-extraction-ai', [
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'is_enabled' => true,
            'timeout' => 20,
            'max_output_tokens' => 350,
        ])->assertForbidden();

        $this->postJson('/api/admin/auth/receipt-extraction-ai/test-connection', [
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
        ])->assertForbidden();

        $this->actingAsAdmin($this->superAdmin);
        $this->getJson('/api/admin/auth/receipt-extraction-ai')->assertOk();
    }

    public function test_backend_validates_db_provider_model_relationship_rejects_mismatches_and_audits_saves(): void
    {
        $this->actingAsAdmin($this->authorizedAdmin);

        // Reject environment & api_key injection
        $this->putJson('/api/admin/auth/receipt-extraction-ai', [
            'environment' => 'production',
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'is_enabled' => true,
            'timeout' => 20,
            'max_output_tokens' => 400,
        ])->assertUnprocessable()->assertJsonValidationErrors(['environment']);

        $this->putJson('/api/admin/auth/receipt-extraction-ai', [
            'api_key' => 'sk-injected',
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'is_enabled' => true,
            'timeout' => 20,
            'max_output_tokens' => 400,
        ])->assertUnprocessable()->assertJsonValidationErrors(['api_key']);

        // Reject unsupported provider
        $this->putJson('/api/admin/auth/receipt-extraction-ai', [
            'provider' => 'anthropic',
            'model' => 'claude-3',
            'is_enabled' => true,
            'timeout' => 20,
            'max_output_tokens' => 400,
        ])->assertUnprocessable()->assertJsonValidationErrors(['provider']);

        // Reject provider/model mismatch (OpenAI model under Gemini)
        $this->putJson('/api/admin/auth/receipt-extraction-ai', [
            'provider' => 'gemini',
            'model' => 'gpt-6-luna',
            'is_enabled' => true,
            'timeout' => 20,
            'max_output_tokens' => 400,
        ])->assertUnprocessable()->assertJsonValidationErrors(['model']);

        // Reject inactive model
        $this->putJson('/api/admin/auth/receipt-extraction-ai', [
            'provider' => 'openai',
            'model' => 'gpt-4o-old',
            'is_enabled' => true,
            'timeout' => 20,
            'max_output_tokens' => 400,
        ])->assertUnprocessable()->assertJsonValidationErrors(['model']);

        // Reject inactive provider
        $this->qwenProvider->update(['is_active' => false]);
        $this->putJson('/api/admin/auth/receipt-extraction-ai', [
            'provider' => 'qwen',
            'model' => 'qwen-plus',
            'is_enabled' => true,
            'timeout' => 20,
            'max_output_tokens' => 400,
        ])->assertUnprocessable()->assertJsonValidationErrors(['provider']);
        $this->qwenProvider->update(['is_active' => true]);

        // Reject enabling provider when server key is missing
        config(['services.ai_credentials.AI_QWEN_DEV_KEY' => '']);
        $this->putJson('/api/admin/auth/receipt-extraction-ai', [
            'provider' => 'qwen',
            'model' => 'qwen-plus',
            'is_enabled' => true,
            'timeout' => 20,
            'max_output_tokens' => 400,
        ])->assertUnprocessable()->assertJsonPath('code', 'PROVIDER_CREDENTIAL_MISSING');

        // Valid DB-backed save succeeds and writes audit
        $this->putJson('/api/admin/auth/receipt-extraction-ai', [
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'is_enabled' => true,
            'timeout' => 20,
            'max_output_tokens' => 350,
        ])->assertOk()
            ->assertJsonPath('settings.provider', 'gemini')
            ->assertJsonPath('settings.model', 'gemini-2.5-flash')
            ->assertJsonPath('recent_audits.0.new_provider', 'gemini');

        $this->assertDatabaseHas('ai_feature_setting_audits', [
            'feature' => 'receipt_extraction',
            'environment' => 'development',
            'admin_user_id' => $this->authorizedAdmin->id,
            'new_provider' => 'gemini',
            'new_model' => 'gemini-2.5-flash',
        ]);
    }

    public function test_connection_test_clarifies_metadata_vs_generation_and_refines_qwen_400_classification(): void
    {
        $this->actingAsAdmin($this->authorizedAdmin);

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash' => Http::response(['name' => 'models/gemini-2.5-flash'], 200),
            'https://api.openai.com/v1/models/gpt-6-luna' => Http::response(['id' => 'gpt-6-luna'], 200),
            'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions' => Http::sequence()
                ->push(['error' => ['code' => 'InvalidParameter', 'message' => 'Bad request format']], 400)
                ->push(['error' => ['code' => 'ModelNotFound', 'message' => 'Model does not exist']], 400)
                ->push(['choices' => [['message' => ['content' => 'ok']]]], 200),
        ]);

        // Gemini metadata check clarifies non-quota scope
        $geminiRes = $this->postJson('/api/admin/auth/receipt-extraction-ai/test-connection', [
            'provider' => 'gemini',
            'model' => 'models/gemini-2.5-flash',
        ])->assertOk()->assertJsonPath('ok', true);
        $this->assertStringContainsString('not generation quota', (string) $geminiRes->json('message'));

        // OpenAI metadata check clarifies non-quota scope
        $openAiRes = $this->postJson('/api/admin/auth/receipt-extraction-ai/test-connection', [
            'provider' => 'openai',
            'model' => 'gpt-6-luna',
        ])->assertOk()->assertJsonPath('ok', true);
        $this->assertStringContainsString('not generation quota', (string) $openAiRes->json('message'));

        // Qwen generic 400 -> PROVIDER_BAD_REQUEST (not misclassified as MODEL_NOT_FOUND)
        $this->postJson('/api/admin/auth/receipt-extraction-ai/test-connection', [
            'provider' => 'qwen',
            'model' => 'qwen-plus',
        ])->assertUnprocessable()->assertJsonPath('code', 'PROVIDER_BAD_REQUEST');

        // Qwen model-specific 400 -> MODEL_NOT_FOUND
        $this->postJson('/api/admin/auth/receipt-extraction-ai/test-connection', [
            'provider' => 'qwen',
            'model' => 'qwen-plus',
        ])->assertUnprocessable()->assertJsonPath('code', 'MODEL_NOT_FOUND');

        // Qwen success -> 1-token probe message
        $qwenRes = $this->postJson('/api/admin/auth/receipt-extraction-ai/test-connection', [
            'provider' => 'qwen',
            'model' => 'qwen-plus',
        ])->assertOk()->assertJsonPath('ok', true);
        $this->assertStringContainsString('1-token probe', (string) $qwenRes->json('message'));

        // Test connection never persists settings or financial records
        $this->assertDatabaseCount('ai_feature_settings', 0);
        $this->assertDatabaseCount('subscription_payment_requests', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_gemini_prefix_normalization_safety_refusal_and_error_handling(): void
    {
        $adapter = app(GeminiReceiptExtractionProvider::class);
        $this->assertSame('gemini-2.5-flash', $adapter->normalizeModelKey('models/gemini-2.5-flash'));
        $this->assertSame('gemini-2.5-flash', $adapter->normalizeModelKey('gemini-2.5-flash'));

        AiFeatureSetting::create([
            'feature' => 'receipt_extraction',
            'environment' => 'development',
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'is_enabled' => true,
            'timeout' => 20,
            'max_output_tokens' => 400,
            'updated_by' => $this->authorizedAdmin->id,
        ]);

        $this->actingAsStudent();
        $this->mockOcrReturns('Easypaisa TID 123456 Rs 1000');

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent' => Http::sequence()
                ->push(['candidates' => [['finishReason' => 'SAFETY', 'content' => ['parts' => [['text' => '{}']]]]]], 200)
                ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => '{invalid-json']]]]]], 200)
                ->push(['error' => ['message' => 'rate limited']], 429)
                ->push(['error' => ['message' => 'internal secret']], 500),
        ]);

        // Safety refusal -> 502 AI_INVALID_RESPONSE
        $this->post('/web_api/auth/payments/extract-receipt', ['proof_file' => $this->fakeJpegUpload()], ['Accept' => 'application/json'])
            ->assertStatus(502)->assertJsonPath('code', 'AI_INVALID_RESPONSE');

        // Malformed JSON -> 502 AI_INVALID_RESPONSE
        $this->post('/web_api/auth/payments/extract-receipt', ['proof_file' => $this->fakeJpegUpload()], ['Accept' => 'application/json'])
            ->assertStatus(502)->assertJsonPath('code', 'AI_INVALID_RESPONSE');

        // 429 -> AI_RATE_LIMITED
        $this->post('/web_api/auth/payments/extract-receipt', ['proof_file' => $this->fakeJpegUpload()], ['Accept' => 'application/json'])
            ->assertStatus(429)->assertJsonPath('code', 'AI_RATE_LIMITED');

        // 500 -> AI_UNAVAILABLE without leaking provider message
        $errRes = $this->post('/web_api/auth/payments/extract-receipt', ['proof_file' => $this->fakeJpegUpload()], ['Accept' => 'application/json'])
            ->assertStatus(503)->assertJsonPath('code', 'AI_UNAVAILABLE');
        $this->assertStringNotContainsString('internal secret', (string) $errRes->json('message'));
    }

    public function test_qwen_production_extraction_error_handling_and_runtime_inactive_model_rejection(): void
    {
        config(['app.env' => 'production']);

        AiFeatureSetting::create([
            'feature' => 'receipt_extraction',
            'environment' => 'production',
            'provider' => 'qwen',
            'model' => 'qwen-plus',
            'is_enabled' => true,
            'timeout' => 25,
            'max_output_tokens' => 400,
            'updated_by' => $this->authorizedAdmin->id,
        ]);

        $this->actingAsStudent();
        $this->mockOcrReturns("Meezan Bank\nFT12345678\nSender: Usman Ali\nPKR 1500\n2026-11-26");

        Http::fake([
            'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => 'not-json']]]], 200)
                ->push(['error' => ['message' => 'qwen rate limit']], 429)
                ->push([
                    'choices' => [
                        [
                            'message' => [
                                'content' => "```json\n".json_encode([
                                    'transaction_id' => 'FT12345678',
                                    'sender_name' => 'Usman Ali',
                                    'amount' => 1500,
                                    'transaction_date' => '2026-11-26',
                                    'payment_method' => 'Bank Transfer',
                                    'confidence' => [
                                        'transaction_id' => 0.94,
                                        'sender_name' => 0.90,
                                        'amount' => 0.98,
                                        'transaction_date' => 0.95,
                                        'payment_method' => 0.92,
                                    ],
                                ], JSON_THROW_ON_ERROR)."\n```",
                            ],
                        ],
                    ],
                ], 200),
        ]);

        // Malformed JSON -> 502
        $this->post('/web_api/auth/payments/extract-receipt', ['proof_file' => $this->fakeJpegUpload()], ['Accept' => 'application/json'])
            ->assertStatus(502)->assertJsonPath('code', 'AI_INVALID_RESPONSE');

        // 429 -> AI_RATE_LIMITED
        $this->post('/web_api/auth/payments/extract-receipt', ['proof_file' => $this->fakeJpegUpload()], ['Accept' => 'application/json'])
            ->assertStatus(429)->assertJsonPath('code', 'AI_RATE_LIMITED');

        // Valid Qwen extraction using production feature key
        $this->post('/web_api/auth/payments/extract-receipt', ['proof_file' => $this->fakeJpegUpload()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.transaction_id', 'FT12345678');

        Http::assertSent(fn (HttpClientRequest $req) => $req->hasHeader('Authorization', 'Bearer prod-qwen-receipt-secret-key')
            && ($req->data()['model'] ?? null) === 'qwen-plus');

        // Deactivating model in DB immediately blocks runtime extraction safely
        AiModel::where('model_key', 'qwen-plus')->update(['is_active' => false]);
        $this->post('/web_api/auth/payments/extract-receipt', ['proof_file' => $this->fakeJpegUpload()], ['Accept' => 'application/json'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'AI_CONFIGURATION_INVALID');
    }

    public function test_disabled_ai_setting_returns_safe_error_without_calling_provider(): void
    {
        AiFeatureSetting::create([
            'feature' => 'receipt_extraction',
            'environment' => 'development',
            'provider' => 'openai',
            'model' => 'gpt-6-luna',
            'is_enabled' => false,
            'timeout' => 25,
            'max_output_tokens' => 400,
            'updated_by' => $this->authorizedAdmin->id,
        ]);

        $this->actingAsStudent();
        Http::fake();

        $this->post('/web_api/auth/payments/extract-receipt', [
            'proof_file' => $this->fakeJpegUpload(),
        ], ['Accept' => 'application/json'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'AI_DISABLED');

        Http::assertNothingSent();
    }
}
