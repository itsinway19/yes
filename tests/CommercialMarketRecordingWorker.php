<?php

// Independent real database/session participant, only for guarded disposable acceptance tests.
use App\Commercial\MarketRegistry;
use App\Models\User;
use App\Security\DisposableTestDatabaseGuard;
use App\Security\SessionAuthority;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(DisposableTestDatabaseGuard::class)->assertSafe();
$user = User::findOrFail((int) $argv[1]);
$session = $app['session']->driver();
$session->start();
$request = Request::create('/internal/commercial/markets', 'POST');
$request->setLaravelSession($session);
$request->setUserResolver(fn () => $user);
app(SessionAuthority::class)->issue($request, $user, 'internal_passkey');
echo 'PID='.DB::scalar('SELECT pg_backend_pid()').PHP_EOL;
flush();
$start = $argv[5].'-'.$argv[4].'-start';
$startDeadline = microtime(true) + 15;
while (! is_file($start) && microtime(true) < $startDeadline) {
    usleep(20000);
}
if (! is_file($start)) {
    throw new RuntimeException('Start barrier not observed.');
}
try {
    DB::connection('pgsql_authority')->transaction(function () use ($request, $argv): void {
        DB::transaction(function () use ($request, $argv): void {
            app(MarketRegistry::class)->configure($request, ['command_id' => $argv[3], 'market_id' => $argv[2], 'label' => 'Concurrent approved market', 'reason_code' => 'concurrent_market']);
            if ($argv[4] === 'hold') {
                echo 'INSERT_READY'.PHP_EOL;
                flush();
                $deadline = microtime(true) + 10;
                while (! is_file($argv[5]) && microtime(true) < $deadline) {
                    usleep(20000);
                }
                if (! is_file($argv[5])) {
                    throw new RuntimeException('Release barrier not observed.');
                }
            }
        });
    });
    echo 'STATUS=200'.PHP_EOL;
} catch (HttpException $error) {
    if ($error->getStatusCode() !== 409) {
        throw $error;
    }
    echo 'STATUS=409'.PHP_EOL;
}
