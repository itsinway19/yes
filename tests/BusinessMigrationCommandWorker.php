<?php

use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(DisposableTestDatabaseGuard::class)->assertSafe();
$f = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$db = DB::connection('pgsql_migrations');
if ($db->scalar('SELECT session_user') !== 'builderad_owner') {
    throw new RuntimeException('Isolated deployment role required.');
}
$db->scalar("SELECT set_config('application_name',?,false)", [$f['run']]);
echo 'READY_PID='.$db->scalar('SELECT pg_backend_pid()').PHP_EOL;
flush();
$waitFile = static function (string $path): void {
    $until = microtime(true) + 25;
    while (! is_file($path)) {
        clearstatcache(true, $path);
        if (microtime(true) > $until) {
            throw new RuntimeException('Owned worker release missing.');
        }
        usleep(10000);
    }
};
$waitFile($f['release']);
try {
    $db->beginTransaction();
    echo 'XID='.$db->scalar('SELECT pg_current_xact_id()::text').PHP_EOL;
    flush();
    if ($f['operation'] === 'transition') {
        $result = $db->scalar('SELECT builderad_v2_cohort_transition(?::uuid,?::uuid,?,?,?,?::uuid,decode(?,\'hex\'))',
            [$f['command'], $f['cohort'], $f['mode'], $f['epoch'], $f['next'], $f['manifest'] ?? null, $f['checkpoint']]);
    } elseif ($f['operation'] === 'import') {
        $result = $db->scalar('SELECT builderad_v2_legacy_import(?::uuid,?::uuid,?,?::uuid,?::jsonb,decode(?,\'hex\'))',
            [$f['command'], $f['cohort'], $f['epoch'], $f['manifest'], json_encode($f['entries'], JSON_THROW_ON_ERROR), $f['native_digest']]);
    } else {
        throw new RuntimeException('Unknown fixed migration operation.');
    }
    $db->commit();
    if ($f['suppress_ack'] ?? false) {
        file_put_contents($f['committed'], 'committed; client acknowledgement withheld');
        $waitFile($f['ack_release']);
    }
    echo 'COMMITTED='.$result.PHP_EOL;
} catch (QueryException $error) {
    if ($db->transactionLevel() > 0) {
        $db->rollBack();
    }
    echo 'DENIED_STATE='.$error->errorInfo[0].PHP_EOL;
    echo 'DENIED_REASON='.strtok($error->errorInfo[2] ?? 'unknown', chr(10)).PHP_EOL;
    exit(2);
} finally {
    if ($db->transactionLevel() > 0) {
        $db->rollBack();
    }
}
