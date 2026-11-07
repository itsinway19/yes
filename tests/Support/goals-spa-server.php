<?php

use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\Support\ProofRequestTiming;

if (PHP_SAPI !== 'cli-server' || getenv('GOALS_SPA_PROOF') !== 'enabled') {
    http_response_code(404);
    exit;
}
$proofStarted = hrtime(true);
$proofMarks = [];
$proofQueries = [];
$proofRedis = [];
$GLOBALS['proofRequestMark'] = static function (string $phase) use (&$proofMarks, $proofStarted): void {
    $proofMarks[] = ['phase' => $phase, 'ms' => (hrtime(true) - $proofStarted) / 1e6];
};
$proofMark = $GLOBALS['proofRequestMark'];
$proofMark('php_entry');
$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$asset = realpath($root.'/public'.$path);
if ($asset && is_file($asset) && str_starts_with($asset, realpath($root.'/public').DIRECTORY_SEPARATOR)) {
    return false;
}
$proofMark('autoload_begin');
require $root.'/vendor/autoload.php';
$proofMark('autoload_end');
$app = require $root.'/bootstrap/app.php';
$proofStorage = getenv('LARAVEL_STORAGE_PATH');
if (! is_string($proofStorage) || realpath($proofStorage) === false || realpath($proofStorage) !== realpath(getenv('GOALS_SPA_RUN').'/laravel')) {
    throw new RuntimeException('The server requires the recorded isolated proof storage.');
}
$app->useStoragePath($proofStorage);
$proofMark('bootstrap_begin');
$app->make(Kernel::class)->bootstrap();
$proofMark('bootstrap_end');
require_once __DIR__.'/ProofRequestTiming.php';
DB::listen(static function ($event) use (&$proofQueries): void {
    $proofQueries[] = ['connection' => $event->connectionName, 'ms' => $event->time, 'sql' => $event->sql];
});
Redis::enableEvents();
Event::listen(CommandExecuted::class, static function ($event) use (&$proofRedis): void {
    $proofRedis[] = ['command' => $event->command, 'ms' => $event->time];
});
Event::listen(RouteMatched::class, static function ($event) use ($proofMark): void {
    $proofMark('route_matched');
    $event->route->middleware(ProofRequestTiming::class);
});
$environment = $app->environment();
$app->instance('env', 'testing');
try {
    $proofMark('isolation_guard_begin');
    app(DisposableTestDatabaseGuard::class)->assertSafe();
    $proofMark('isolation_guard_end');
    if (in_array(getenv('GOALS_SPA_ONLY'), ['sources', 'data-licenses'], true)) {
        require __DIR__.'/sources-spa-runtime.php';
    }
    if (getenv('GOALS_SPA_ONLY') === 'businesses' && Cache::store('redis')->get('shared-source-return-runtime') === true) {
        require __DIR__.'/shared-source-return-runtime.php';
    }
    if (in_array(getenv('GOALS_SPA_ONLY'), ['account', 'auth'], true)) {
        Http::preventStrayRequests();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response(str_repeat('0', 35).':0')]);
    }
    if (getenv('GOALS_SPA_ONLY') === 'auth') {
        Event::listen(MessageSent::class, function ($event): void {
            $message = $event->sent->getOriginalMessage();
            file_put_contents(getenv('GOALS_SPA_RUN').'/mailbox.jsonl', json_encode([
                'to' => array_map(fn ($address) => $address->getAddress(), $message->getTo()),
                'html' => $message->getHtmlBody(),
            ], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        });
    }
} finally {
    $app->instance('env', $environment);
}
if ($environment !== 'local' || config('session.driver') !== 'redis' || (int) config('database.redis.default.port') !== 6397) {
    throw new RuntimeException('The proof requires real CSRF and its dedicated Redis process.');
}
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Request::capture();
$proofMark('handle_begin');
$response = $kernel->handle($request);
$proofMark('response_prepared');
$response->send();
$proofMark('response_sent');
$kernel->terminate($request, $response);
$proofMark('terminated');
file_put_contents(getenv('GOALS_SPA_RUN').'/http-timing.jsonl', json_encode([
    'correlation' => $_SERVER['HTTP_X_PROOF_CORRELATION'] ?? null,
    'pid' => getmypid(), 'phpArrivalUnix' => $_SERVER['REQUEST_TIME_FLOAT'],
    'path' => $path, 'method' => $request->method(), 'status' => $response->getStatusCode(),
    'marks' => $proofMarks, 'queries' => $proofQueries, 'redis' => $proofRedis,
], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
