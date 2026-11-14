<?php

use App\Content\ContentAdmin;
use App\Http\Controllers\InternalContentController;
use App\Models\User;
use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
app(DisposableTestDatabaseGuard::class)->assertSafe();
$actor = User::findOrFail((int) $argv[1]);
$payload = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);

// The parent exercises the protected endpoint. This request isolates the competing controller write.
$app['router']->post('/__test/category-create', function (Request $request) use ($actor) {
    $request->setUserResolver(fn () => $actor);

    return app(InternalContentController::class)->storeCategory($request, app(ContentAdmin::class));
});

$pid = (int) DB::scalar('SELECT pg_backend_pid()');
if (isset($argv[3])) {
    file_put_contents($argv[3].'.ready', json_encode(['pid' => $pid], JSON_THROW_ON_ERROR), LOCK_EX);
    $deadline = microtime(true) + 15;
    do {
        clearstatcache(true, $argv[3].'.release');
        if (is_file($argv[3].'.release')) {
            break;
        }
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Category write release not observed.');
        }
        usleep(10000);
    } while (true);
}
echo 'PID '.$pid."\n";
flush();
$request = Request::create('/__test/category-create', 'POST', $payload, server: ['HTTP_ACCEPT' => 'application/json']);
$kernel = $app->make(HttpKernel::class);
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
