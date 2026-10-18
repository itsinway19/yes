<?php

use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

if (PHP_SAPI !== 'cli-server' || getenv('GOALS_SPA_PROOF') !== 'enabled') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$asset = realpath($root.'/public'.$path);
if ($asset && is_file($asset) && str_starts_with($asset, realpath($root.'/public').DIRECTORY_SEPARATOR)) {
    return false;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$proofStorage = getenv('LARAVEL_STORAGE_PATH');
if (! is_string($proofStorage) || realpath($proofStorage) === false || realpath($proofStorage) !== realpath(getenv('GOALS_SPA_RUN').'/laravel')) {
    throw new RuntimeException('The server requires the recorded isolated proof storage.');
}
$app->useStoragePath($proofStorage);
$app->make(Kernel::class)->bootstrap();
$environment = $app->environment();
$app->instance('env', 'testing');
try {
    app(DisposableTestDatabaseGuard::class)->assertSafe();
    if (getenv('GOALS_SPA_ONLY') === 'sources') {
        require __DIR__.'/sources-spa-runtime.php';
    }
    if (getenv('GOALS_SPA_ONLY') === 'account') {
        Http::preventStrayRequests();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response(str_repeat('0', 35).':0')]);
    }
} finally {
    $app->instance('env', $environment);
}
if ($environment !== 'local' || config('session.driver') !== 'redis' || (int) config('database.redis.default.port') !== 6397) {
    throw new RuntimeException('The proof requires real CSRF and its dedicated Redis process.');
}
require __DIR__.'/goals-spa-routes.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
