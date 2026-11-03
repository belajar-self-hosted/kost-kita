<?php

namespace Tests\Unit\Support\Deployment;

use App\Support\Deployment\ProductionConfigValidator;
use PHPUnit\Framework\TestCase;

class ProductionConfigValidatorTest extends TestCase
{
    public function test_accepts_the_expected_production_runtime_configuration(): void
    {
        $this->assertSame([], ProductionConfigValidator::failures($this->validConfiguration()));
    }

    public function test_rejects_local_debug_database_override_and_insecure_cookie_settings(): void
    {
        $configuration = $this->validConfiguration();
        $configuration['app_env'] = 'local';
        $configuration['app_debug'] = true;
        $configuration['database_url'] = 'postgres://user:secret@external.example/db';
        $configuration['database_host'] = 'external.example';
        $configuration['session_secure'] = false;

        $failures = ProductionConfigValidator::failures($configuration);

        $this->assertSame(['APP_ENV', 'APP_DEBUG', 'DB_URL', 'DB_HOST', 'SESSION_SECURE_COOKIE'], $failures);
        $this->assertStringNotContainsString('secret', implode(' ', $failures));
    }

    public function test_rejects_missing_app_key_without_returning_or_logging_its_value(): void
    {
        $configuration = $this->validConfiguration();
        $configuration['app_key'] = '';

        $this->assertSame(['APP_KEY'], ProductionConfigValidator::failures($configuration));
    }

    public function test_rejects_an_app_key_with_the_wrong_length(): void
    {
        $configuration = $this->validConfiguration();
        $configuration['app_key'] = 'base64:'.base64_encode('short');

        $this->assertSame(['APP_KEY'], ProductionConfigValidator::failures($configuration));
    }

    /** @return array<string, mixed> */
    private function validConfiguration(): array
    {
        return [
            'app_env' => 'production',
            'app_debug' => false,
            'app_url' => 'https://kostkita.sequein.my.id',
            'app_key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app_cipher' => 'AES-256-CBC',
            'database_default' => 'pgsql',
            'database_url' => null,
            'database_host' => 'db',
            'database_port' => '5432',
            'session_secure' => true,
        ];
    }
}
