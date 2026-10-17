<?php

use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

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
$app->make(Kernel::class)->bootstrap();
$environment = $app->environment();
$app->instance('env', 'testing');
try {
    app(DisposableTestDatabaseGuard::class)->assertSafe();
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
