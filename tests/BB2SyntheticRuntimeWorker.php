<?php

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderRequestGuard;
use App\Providers\Ads\ProviderScope;
use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Independent worker for disposable proof only. Never boot a preview or call a provider.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(DisposableTestDatabaseGuard::class)->assertSafe();
$fixture = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
config()->set('logging.channels.bb2_runtime_proof', ['driver' => 'single', 'path' => $argv[1].'.log', 'level' => 'debug']);
config()->set('logging.default', 'bb2_runtime_proof');
$http = new Factory;
$http->preventStrayRequests();
$http->fake(['*' => function ($request) use ($http, $fixture) {
    if (($fixture['http_failure'] ?? false) === true) {
        return $http->response('<html>synthetic unavailable</html>', 503);
    }
    $body = str_contains($request['query'] ?? '', 'FROM customer') ? $fixture['metadata'] : $fixture['report'];

    return $http->response($body);
}]);
$app->instance(Factory::class, $http);
config()->set('services.google_ads', $fixture['google_configuration'] ?? []);

if ($fixture['mode'] === 'queue') {
    $exit = Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'bb2.provider.google',
        '--once' => true, '--sleep' => 0, '--tries' => 1, '--timeout' => 60]);
    echo 'WORKER_EXIT='.$exit.'; HTTP_REQUESTS='.count($http->recorded()).PHP_EOL;
    exit($exit);
}

// Use the actual configured Redis, with a unique test prefix on both cache and locks.
$env = Dotenv\Dotenv::parse(file_get_contents('C:/dev/builderad/.env'));
$value = static fn ($name, $fallback = null) => in_array($env[$name] ?? null, ['', 'null', '(null)'], true) ? null : ($env[$name] ?? $fallback);
config()->set('database.redis.client', $value('REDIS_CLIENT', 'predis'));
foreach (['default', 'cache'] as $connection) {
    foreach (['host' => 'REDIS_HOST', 'port' => 'REDIS_PORT', 'password' => 'REDIS_PASSWORD', 'username' => 'REDIS_USERNAME', 'url' => 'REDIS_URL'] as $key => $name) {
        config()->set('database.redis.'.$connection.'.'.$key, $value($name, $key === 'host' ? '127.0.0.1' : ($key === 'port' ? 6379 : null)));
    }
}
config()->set('database.redis.options.prefix', $fixture['prefix']);
config()->set('cache.prefix', $fixture['prefix']);
$s = $fixture['scope'];
$scope = new ProviderScope($s['workspace_id'], $s['source_id'], Provider::from($s['provider']), $s['account_id'], $s['operation'], $s['purpose']);
$actor = User::findOrFail($fixture['actor']);
$request = Request::create('/');
$request->setUserResolver(fn () => $actor);
$session = new Store('bb2-redis-proof', new ArraySessionHandler(120));
$session->setId($fixture['session']);
$session->put('a001_generation', $fixture['generation']);
$request->setLaravelSession($session);
$family = ['meta' => 'Meta', 'google' => 'Google', 'snapchat' => 'Snapchat', 'tiktok' => 'TikTok', 'x' => 'X'][$s['provider']];
$class = 'App\\Providers\\Ads\\'.$family.'\\'.$family.'OAuthState';
$states = new $class(Cache::store('redis'), app(ProviderRequestGuard::class));
try {
    DB::transaction(function () use ($actor, $scope, $request, $states, $fixture): void {
        app(PrincipalContext::class)->activate($actor);
        DB::selectOne('SELECT builderad_select_workspace_context(?)', [$scope->workspaceId]);
        $states->consume($request, $scope, 0, $fixture['configuration'], $fixture['state']);
        if (($fixture['rollback'] ?? false) === true) {
            throw new RuntimeException('synthetic rollback after consumption');
        }
    });
    echo 'CONSUMED'.PHP_EOL;
} catch (Throwable $error) {
    echo ($error instanceof RuntimeException && $error->getMessage() === 'synthetic rollback after consumption' ? 'CONSUMED_THEN_ROLLED_BACK' : 'DENIED').PHP_EOL;
}
echo 'SESSION_KEYS='.implode(',', array_keys($session->all())).PHP_EOL;
echo 'HTTP_REQUESTS='.count($http->recorded()).PHP_EOL;
