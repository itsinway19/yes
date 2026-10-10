<?php

use App\Models\User;
use App\Security\DisposableTestDatabaseGuard;
use App\Security\SessionAuthority;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(DisposableTestDatabaseGuard::class)->assertSafe();
$fixture = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
DB::connection('pgsql_authority')->selectOne("SELECT set_config('application_name', ?, false)", [$fixture['run'].'-touch']);
DB::selectOne("SELECT set_config('application_name', ?, false)", [$fixture['run'].'-holder']);
$user = User::findOrFail($fixture['actor']);
$request = Request::create('/', 'GET');
$request->setUserResolver(fn () => $user);
$session = new Store('session-touch-proof', new ArraySessionHandler(120));
$session->setId($fixture['session_id']);
$request->setLaravelSession($session);
echo "BOOTED\n";
flush();
$wait = static function (string $signal): void {
    $deadline = hrtime(true) + 25_000_000_000;
    do {
        clearstatcache(true, $signal);
        if (is_file($signal)) {
            return;
        }
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Session proof gate not released');
        }
        DB::selectOne('SELECT 1');
        usleep(10000);
    } while (true);
};
try {
    if ($fixture['mode'] === 'holder') {
        DB::transaction(function () use ($fixture, $wait): void {
            DB::table('security_sessions')->where('user_id', $fixture['actor'])->sharedLock()->first();
            file_put_contents($fixture['ready'], 'SHARE_HELD');
            $wait($fixture['release']);
            // An existing authorized transaction rechecks the same security evidence.
            DB::table('security_sessions')->where('user_id', $fixture['actor'])->sharedLock()->first();
        });
        echo "HOLDER_COMMITTED\n";
    } else {
        DB::transaction(fn () => app(SessionAuthority::class)->assertCurrent($request, $user, 1));
        echo "TOUCH_COMPLETED\n";
    }
} catch (Throwable $error) {
    echo 'FAILURE='.$error->getCode().' '.$error->getMessage().PHP_EOL;
    exit(1);
}
