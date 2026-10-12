<?php

/** Launch explicitly with PHP's disposable cli-server; never loaded by public/index.php. */
use App\Security\DisposableTestDatabaseGuard;
use App\Security\IdentityTransition;
use App\Security\IdentityTransitionConflict;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Support\IdentityTransitionFixture;

if (PHP_SAPI !== 'cli-server' || getenv('P2A_HTTP_FIXTURE') !== 'enabled') {
    http_response_code(404);
    exit;
}
$root = getenv('APP_BASE_PATH');
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(DisposableTestDatabaseGuard::class)->assertSafe();

Route::middleware('web')->get('/p2a', fn () => response('<!doctype html><title>P2-A disposable transport fixture</title>'));
Route::middleware('web')->get('/p2a/bootstrap', fn () => response()->json(['lineage' => app(IdentityTransition::class)->create('advertiser'), 'epoch' => 0]));
Route::middleware('web')->get('/p2a/resolve', fn (Request $request) => response()->json(app(IdentityTransition::class)->resolve($request->query('lineage'), 'advertiser', $request->query('nonce'))));
Route::middleware('web')->post('/p2a/login', function (Request $request) {
    try {
        $receipt = app(IdentityTransition::class)->login($request, $request->string('lineage')->toString(), 'advertiser', $request->integer('epoch'), $request->string('nonce')->toString(), fn () => IdentityTransitionFixture::authenticate($request, $request->string('email')->toString()));

        return response()->json($receipt)->header('Cache-Control', 'no-store');
    } catch (IdentityTransitionConflict) {
        return response()->json(['outcome' => 'stale'], 409);
    }
});
Route::middleware('web')->get('/p2a/protected', function (Request $request) {
    try {
        app(IdentityTransition::class)->admit($request, $request->query('lineage'), 'advertiser');

        return response()->json(['principal' => $request->user()->id])->header('Cache-Control', 'no-store');
    } catch (IdentityTransitionConflict|AuthorizationException) {
        return response()->json(['outcome' => 'denied'], 409);
    }
});

$app->handleRequest(Request::capture());
