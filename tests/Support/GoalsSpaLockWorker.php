<?php

use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(DisposableTestDatabaseGuard::class)->assertSafe();
$run = realpath(getenv('GOALS_SPA_RUN'));
$checkpoint = $argv[1] ?? '';
if (! $run || ! preg_match('/^closure-[a-z0-9-]+$/D', $checkpoint)) {
    throw new RuntimeException('Owned proof checkpoint required');
}
$fixture = json_decode(file_get_contents($run.'/fixture.json'), true, flags: JSON_THROW_ON_ERROR);
$workspace = $fixture['a']['workspaces'][0]['id'];
$db = DB::connection('pgsql_migrations');
$db->selectOne("SELECT set_config('application_name', ?, false)", [$checkpoint]);
$db->transaction(function () use ($db, $run, $checkpoint, $workspace): void {
    $db->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['builderad.advertiser.goals.'.$workspace]);
    file_put_contents($run.'/'.$checkpoint.'.ready', json_encode($db->selectOne('SELECT pg_backend_pid() pid, current_database() db, current_user role, pg_current_xact_id()::text xid'), JSON_THROW_ON_ERROR));
    $deadline = microtime(true) + 20;
    while (! is_file($run.'/'.$checkpoint.'.release')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Owned Goals fence release missing');
        }
        clearstatcache(true, $run.'/'.$checkpoint.'.release');
        usleep(10000);
    }
});
echo "RELEASED\n";
