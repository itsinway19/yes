<?php

use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;

// Disposable browser proof only. PHP's built-in HTTP server does not expose
// inherited environment values in $_SERVER exactly as CLI fixture bootstrap does.
if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'builderad_test_disposable'
    || getenv('DB_PORT') !== '5433' || ! getenv('BUILDERAD_TEST_ISOLATION_TOKEN')) {
    http_response_code(503);
    exit('Disposable proof environment required.');
}
$_SERVER['LARAVEL_STORAGE_PATH'] = getenv('LARAVEL_STORAGE_PATH');

$buildDirectory = getenv('GOALS_PROOF_BUILD_DIRECTORY') ?: 'build';
if ($buildDirectory !== 'build') {
    if (! preg_match('#^build/remediation-[a-f0-9-]+$#', $buildDirectory)) {
        http_response_code(503);
        exit('Isolated build directory required.');
    }
    $root = dirname(__DIR__, 2);
    $uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
    $asset = realpath($root.'/public'.$uri);
    if ($asset && str_starts_with($asset, realpath($root.'/public').DIRECTORY_SEPARATOR)
        && is_file($asset) && pathinfo($asset, PATHINFO_EXTENSION) !== 'php') {
        return false;
    }
    define('LARAVEL_START', microtime(true));
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    // Reproduce a pre-encryption deployment on the real, isolated application.
    // This test-only adapter is never used by owner preview or production.
    if (($_SERVER['HTTP_X_PROOF_LEGACY_HISTORY'] ?? '') === '1') {
        $app->afterBootstrapping(LoadConfiguration::class,
            fn ($application) => $application['config']->set('inertia.history.encrypt', false));
    }
    $app->afterResolving(Vite::class, fn ($vite) => $vite->useBuildDirectory($buildDirectory));
    $app->handleRequest(Request::capture());

    return;
}

return require dirname(__DIR__, 2).'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
