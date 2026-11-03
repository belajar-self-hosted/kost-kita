<?php

namespace App\Support\Deployment;

use Illuminate\Encryption\Encrypter;

final class ProductionConfigValidator
{
    /** @param array<string, mixed> $configuration
     * @return list<string>
     */
    public static function failures(array $configuration): array
    {
        $appKey = $configuration['app_key'] ?? null;
        if (is_string($appKey) && str_starts_with($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7), true);
        }

        $appCipher = is_string($configuration['app_cipher'] ?? null) ? $configuration['app_cipher'] : '';

        $requirements = [
            'APP_ENV' => ($configuration['app_env'] ?? null) === 'production',
            'APP_DEBUG' => ($configuration['app_debug'] ?? null) === false,
            'APP_URL' => ($configuration['app_url'] ?? null) === 'https://kostkita.sequein.my.id',
            'APP_KEY' => is_string($appKey) && $appKey !== '' && Encrypter::supported($appKey, $appCipher),
            'DB_CONNECTION' => ($configuration['database_default'] ?? null) === 'pgsql',
            'DB_URL' => in_array($configuration['database_url'] ?? null, [null, ''], true),
            'DB_HOST' => ($configuration['database_host'] ?? null) === 'db',
            'DB_PORT' => (string) ($configuration['database_port'] ?? '') === '5432',
            'SESSION_SECURE_COOKIE' => ($configuration['session_secure'] ?? null) === true,
        ];

        return array_keys(array_filter($requirements, fn (bool $valid): bool => ! $valid));
    }
}
