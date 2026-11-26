<?php

namespace Tests\Unit;

use App\Exceptions\ReceiptExtractionException;
use App\Http\Services\ReceiptExtraction\ReceiptExtractionCredentialResolver;
use Illuminate\Support\Env;
use Tests\TestCase;

class AiCredentialDiscoveryTest extends TestCase
{
    /** @var array<string, array{getenv:string|false,env_exists:bool,env_val:mixed,server_exists:bool,server_val:mixed}> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $snapshot) {
            if ($snapshot['getenv'] === false) {
                putenv($name);
            } else {
                putenv($name.'='.$snapshot['getenv']);
            }

            if ($snapshot['env_exists']) {
                $_ENV[$name] = $snapshot['env_val'];
            } else {
                unset($_ENV[$name]);
            }

            if ($snapshot['server_exists']) {
                $_SERVER[$name] = $snapshot['server_val'];
            } else {
                unset($_SERVER[$name]);
            }
        }

        $this->savedEnv = [];
        Env::enablePutenv();

        parent::tearDown();
    }

    private function trackVar(string $name): void
    {
        if (array_key_exists($name, $this->savedEnv)) {
            return;
        }

        $this->savedEnv[$name] = [
            'getenv' => getenv($name),
            'env_exists' => array_key_exists($name, $_ENV),
            'env_val' => $_ENV[$name] ?? null,
            'server_exists' => array_key_exists($name, $_SERVER),
            'server_val' => $_SERVER[$name] ?? null,
        ];
    }

    private function setAppEnvRaw(string $appEnv): void
    {
        $this->trackVar('APP_ENV');
        putenv('APP_ENV='.$appEnv);
        $_ENV['APP_ENV'] = $appEnv;
        $_SERVER['APP_ENV'] = $appEnv;
        Env::enablePutenv();
    }

    private function clearVar(string $name): void
    {
        $this->trackVar($name);
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }

    private function loadServicesConfig(): array
    {
        return require base_path('config/services.php');
    }

    public function test_discovers_credentials_from_putenv_env_and_server_with_expected_precedence(): void
    {
        $this->setAppEnvRaw('local');

        $this->clearVar('AI_TEST_PUTENV_DEV_KEY');
        $this->clearVar('AI_TEST_ENV_DEV_KEY');
        $this->clearVar('AI_TEST_SERVER_DEV_KEY');
        $this->clearVar('AI_TEST_PRECEDENCE_DEV_KEY');

        putenv('AI_TEST_PUTENV_DEV_KEY=  sk-putenv-value-123  ');
        $_ENV['AI_TEST_ENV_DEV_KEY'] = 'sk-env-value-456';
        $_SERVER['AI_TEST_SERVER_DEV_KEY'] = 'sk-server-value-789';

        // Precedence: $_ENV > $_SERVER > getenv()
        putenv('AI_TEST_PRECEDENCE_DEV_KEY=from-getenv');
        $_SERVER['AI_TEST_PRECEDENCE_DEV_KEY'] = 'from-server';
        $_ENV['AI_TEST_PRECEDENCE_DEV_KEY'] = 'from-env';

        $cfg = $this->loadServicesConfig();
        $creds = $cfg['ai_credentials'];

        $this->assertSame('sk-putenv-value-123', $creds['AI_TEST_PUTENV_DEV_KEY'] ?? null);
        $this->assertSame('sk-env-value-456', $creds['AI_TEST_ENV_DEV_KEY'] ?? null);
        $this->assertSame('sk-server-value-789', $creds['AI_TEST_SERVER_DEV_KEY'] ?? null);
        $this->assertSame('from-env', $creds['AI_TEST_PRECEDENCE_DEV_KEY'] ?? null);
    }

    public function test_rejects_empty_and_placeholder_values_while_preserving_legitimate_keys(): void
    {
        $this->setAppEnvRaw('development');

        $cases = [
            'AI_TEST_EMPTY_DEV_KEY' => '',
            'AI_TEST_SPACES_DEV_KEY' => '   ',
            'AI_TEST_NULL_DEV_KEY' => 'null',
            'AI_TEST_NULL_UPPER_DEV_KEY' => 'NULL',
            'AI_TEST_PAREN_NULL_DEV_KEY' => '(null)',
            'AI_TEST_EMPTY_WORD_DEV_KEY' => 'empty',
            'AI_TEST_PAREN_EMPTY_DEV_KEY' => '(empty)',
            'AI_TEST_FALSE_DEV_KEY' => 'false',
            'AI_TEST_PAREN_FALSE_DEV_KEY' => '(false)',
        ];

        foreach ($cases as $name => $value) {
            $this->clearVar($name);
            $_ENV[$name] = $value;
        }

        // Legitimate keys (including one containing "null" as a substring) must be preserved
        $this->clearVar('AI_TEST_VALID_DEV_KEY');
        $this->clearVar('AI_TEST_SUBSTRING_DEV_KEY');
        $_ENV['AI_TEST_VALID_DEV_KEY'] = 'sk-proj-AbC_123.456+789/==';
        $_ENV['AI_TEST_SUBSTRING_DEV_KEY'] = 'sk-annulla-falsehood-999';

        // Non-matching names must be ignored
        $this->clearVar('AI_INVALID_PATTERN');
        $this->clearVar('UNRELATED_DEV_KEY');
        $_ENV['AI_INVALID_PATTERN'] = 'should-not-capture';
        $_ENV['UNRELATED_DEV_KEY'] = 'should-not-capture';

        $cfg = $this->loadServicesConfig();
        $creds = $cfg['ai_credentials'];

        foreach (array_keys($cases) as $rejectedName) {
            $this->assertArrayNotHasKey($rejectedName, $creds, "Placeholder variable {$rejectedName} must not be captured.");
        }

        $this->assertSame('sk-proj-AbC_123.456+789/==', $creds['AI_TEST_VALID_DEV_KEY'] ?? null);
        $this->assertSame('sk-annulla-falsehood-999', $creds['AI_TEST_SUBSTRING_DEV_KEY'] ?? null);
        $this->assertArrayNotHasKey('AI_INVALID_PATTERN', $creds);
        $this->assertArrayNotHasKey('UNRELATED_DEV_KEY', $creds);
    }

    public function test_environment_specific_capture_restricts_to_active_environment_and_fails_closed(): void
    {
        $this->clearVar('AI_TESTISO_DEV_KEY');
        $this->clearVar('AI_TESTISO_RECEIPT_EXTRACTION_STAGING_KEY');
        $this->clearVar('AI_TESTISO_RECEIPT_EXTRACTION_PROD_KEY');

        $_ENV['AI_TESTISO_DEV_KEY'] = 'dev-secret';
        $_ENV['AI_TESTISO_RECEIPT_EXTRACTION_STAGING_KEY'] = 'staging-secret';
        $_ENV['AI_TESTISO_RECEIPT_EXTRACTION_PROD_KEY'] = 'prod-secret';

        foreach (['local', 'development', 'testing'] as $devEnv) {
            $this->setAppEnvRaw($devEnv);
            $creds = $this->loadServicesConfig()['ai_credentials'];
            $this->assertSame('dev-secret', $creds['AI_TESTISO_DEV_KEY'] ?? null);
            $this->assertArrayNotHasKey('AI_TESTISO_RECEIPT_EXTRACTION_STAGING_KEY', $creds);
            $this->assertArrayNotHasKey('AI_TESTISO_RECEIPT_EXTRACTION_PROD_KEY', $creds);
        }

        $this->setAppEnvRaw('staging');
        $stagingCreds = $this->loadServicesConfig()['ai_credentials'];
        $this->assertSame('staging-secret', $stagingCreds['AI_TESTISO_RECEIPT_EXTRACTION_STAGING_KEY'] ?? null);
        $this->assertArrayNotHasKey('AI_TESTISO_DEV_KEY', $stagingCreds);
        $this->assertArrayNotHasKey('AI_TESTISO_RECEIPT_EXTRACTION_PROD_KEY', $stagingCreds);

        $this->setAppEnvRaw('production');
        $prodCreds = $this->loadServicesConfig()['ai_credentials'];
        $this->assertSame('prod-secret', $prodCreds['AI_TESTISO_RECEIPT_EXTRACTION_PROD_KEY'] ?? null);
        $this->assertArrayNotHasKey('AI_TESTISO_DEV_KEY', $prodCreds);
        $this->assertArrayNotHasKey('AI_TESTISO_RECEIPT_EXTRACTION_STAGING_KEY', $prodCreds);

        // Unknown environments fail closed and capture zero credentials
        foreach (['prod', 'uat', ''] as $unknownEnv) {
            $this->setAppEnvRaw($unknownEnv);
            $unknownCreds = $this->loadServicesConfig()['ai_credentials'];
            $this->assertSame([], $unknownCreds);
        }
    }

    public function test_config_services_ai_credentials_is_var_export_serializable_and_works_with_resolver(): void
    {
        $this->setAppEnvRaw('production');
        $this->clearVar('AI_OPENAI_RECEIPT_EXTRACTION_PROD_KEY');
        $_ENV['AI_OPENAI_RECEIPT_EXTRACTION_PROD_KEY'] = 'prod-cached-openai-key';

        $cfg = $this->loadServicesConfig();
        $this->assertIsArray($cfg['ai_credentials']);

        foreach ($cfg['ai_credentials'] as $k => $v) {
            $this->assertIsString($k);
            $this->assertIsString($v);
        }

        // Simulate Laravel config:cache serialization (var_export + reload) strictly in memory
        $exported = var_export($cfg, true);
        $reloaded = eval('return '.$exported.';');

        $this->assertIsArray($reloaded);
        $this->assertSame('prod-cached-openai-key', $reloaded['ai_credentials']['AI_OPENAI_RECEIPT_EXTRACTION_PROD_KEY'] ?? null);

        // Verify credential resolver consumes the reconstructed cached config cleanly
        config([
            'app.env' => 'production',
            'services.ai_credentials' => $reloaded['ai_credentials'],
        ]);

        $resolver = app(ReceiptExtractionCredentialResolver::class);
        $this->assertSame('prod-cached-openai-key', $resolver->resolveApiKey('openai'));
    }

    public function test_resolver_rejects_placeholder_values_even_if_injected_into_config(): void
    {
        config(['app.env' => 'local']);
        $resolver = app(ReceiptExtractionCredentialResolver::class);

        foreach (['', '   ', 'null', '(null)', 'empty', '(empty)', 'false', '(false)'] as $placeholder) {
            config(['services.ai_credentials' => ['AI_OPENAI_DEV_KEY' => $placeholder]]);

            $this->assertFalse($resolver->hasCredential('openai'));

            try {
                $resolver->resolveApiKey('openai');
                $this->fail("Expected PROVIDER_CREDENTIAL_MISSING for placeholder '{$placeholder}'.");
            } catch (ReceiptExtractionException $e) {
                $this->assertSame('PROVIDER_CREDENTIAL_MISSING', $e->errorCode());
            }
        }
    }
}
