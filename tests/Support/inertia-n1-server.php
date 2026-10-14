<?php

use App\Http\Middleware\EnsureCurrentPrincipal;
use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Explicit disposable cli-server fixture, never included by public/index.php.
if (PHP_SAPI !== 'cli-server' || getenv('N1_HTTP_FIXTURE') !== 'enabled') {
    http_response_code(404);
    exit;
}
$root = realpath(getenv('APP_BASE_PATH'));
$requested = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$asset = realpath($root.'/public'.$requested);
if ($asset && is_file($asset) && str_starts_with($asset, realpath($root.'/public').DIRECTORY_SEPARATOR)) {
    return false;
}
require getenv('N1_CANDIDATE_BOOTSTRAP');
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$environment = $app->environment();
if ($environment !== 'local') {
    throw new RuntimeException('The browser fixture requires local mode for real CSRF processing.');
}
// Validate the same configured connections/roles/marker through the mandatory
// testing guard, then restore local mode before any HTTP middleware executes.
$app->instance('env', 'testing');
try {
    app(DisposableTestDatabaseGuard::class)->assertSafe();
} finally {
    $app->instance('env', $environment);
}
Route::middleware(['web', 'auth', EnsureCurrentPrincipal::class])
    ->get('/__n1/render-fault', fn () => Inertia\Inertia::render('__N1Fault'))->name('n1.fixture.fault');
Route::middleware(['web', 'auth', EnsureCurrentPrincipal::class])
    ->get('/__n1/partials', fn () => Inertia\Inertia::render('Profile/Edit', [
        'mustVerifyEmail' => false, 'status' => null,
        'deferredA' => Inertia\Inertia::defer(fn () => 'A', 'groupA'),
        'deferredB' => Inertia\Inertia::defer(fn () => 'B', 'groupB'),
    ]))->name('n1.fixture.partials');
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
