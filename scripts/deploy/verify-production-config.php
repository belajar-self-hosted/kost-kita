<?php

use App\Support\Deployment\ProductionConfigValidator;
use Illuminate\Contracts\Console\Kernel;

$projectRoot = dirname(__DIR__, 2);

try {
    require $projectRoot.'/vendor/autoload.php';
    $app = require $projectRoot.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $pgsql = config('database.connections.pgsql', []);
    $configuration = [
        'app_env' => config('app.env'),
        'app_debug' => config('app.debug'),
        'app_url' => config('app.url'),
        'app_key' => config('app.key'),
        'app_cipher' => config('app.cipher'),
        'database_default' => config('database.default'),
        'database_url' => $pgsql['url'] ?? null,
        'database_host' => $pgsql['host'] ?? null,
        'database_port' => $pgsql['port'] ?? null,
        'session_secure' => config('session.secure'),
    ];
} catch (Throwable) {
    fwrite(STDERR, "Production configuration preflight could not inspect Laravel configuration.\n");
    exit(1);
}

$failures = ProductionConfigValidator::failures($configuration);
if ($failures !== []) {
    fwrite(STDERR, 'Production configuration preflight failed: '.implode(', ', $failures)."\n");
    exit(1);
}

fwrite(STDOUT, "Production configuration verified.\n");
