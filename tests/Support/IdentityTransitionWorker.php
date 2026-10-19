<?php

use App\Security\DisposableTestDatabaseGuard;
use App\Security\IdentitySessionPrepared;
use App\Security\IdentityTransition;
use App\Security\IdentityTransitionConflict;
use App\Security\SessionAuthority;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\IdentityTransitionFixture;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(DisposableTestDatabaseGuard::class)->assertSafe();
$pid = DB::connection('pgsql_authority')->selectOne('SELECT pg_backend_pid() AS pid')->pid;
echo 'BOOTED '.$pid.PHP_EOL;
flush();
usleep((int) (getenv('P2A_PREPARATION_DELAY_MS') ?: 0) * 1000);
$request = IdentityTransitionFixture::request();
if (getenv('P2A_WORKER_MODE') === 'race') {
    echo 'READY'.PHP_EOL;
    flush();
    if (trim((string) fgets(STDIN)) !== 'CONTEND') {
        fwrite(STDERR, 'Invalid fixture readiness gate'.PHP_EOL);
        exit(1);
    }
}
$calls = 0;
if (getenv('P2A_WORKER_MODE') === 'interrupt') {
    Event::listen(IdentitySessionPrepared::class, function () use ($request): void {
        echo 'PREPARED '.SessionAuthority::sessionKey($request).PHP_EOL;
        flush();
        while (true) {
            usleep(10000);
        }
    });
}
echo 'ATTEMPT'.PHP_EOL;
flush();
try {
    $receipt = app(IdentityTransition::class)->login($request, getenv('P2A_LINEAGE'), 'advertiser', 0, getenv('P2A_NONCE'), function () use ($request, &$calls): void {
        $calls++;
        IdentityTransitionFixture::authenticate($request, getenv('P2A_EMAIL'));
    });
    echo 'COMMITTED '.$receipt['epoch'].' AUTH_CALLS='.$calls.PHP_EOL;
} catch (IdentityTransitionConflict) {
    echo 'STALE AUTH_CALLS='.$calls.PHP_EOL;
} catch (Throwable $error) {
    // Error classes only: never dump credential-bearing request/store/trace data.
    fwrite(STDERR, get_class($error).PHP_EOL);
    exit(1);
}
