<?php

use App\Commercial\InquiryEvidence;
use App\Models\User;
use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

echo 'BOOT_STAGE=entry'.PHP_EOL;
flush();
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        fwrite(STDERR, 'BOOT_FATAL='.json_encode($error, JSON_THROW_ON_ERROR).PHP_EOL);
    }
});
require dirname(__DIR__, 2).'/vendor/autoload.php';
echo 'BOOT_STAGE=autoloaded'.PHP_EOL;
flush();
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
echo 'BOOT_STAGE=kernel_ready'.PHP_EOL;
flush();
app(DisposableTestDatabaseGuard::class)->assertSafe();
$f = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$user = User::findOrFail($f['actor']);
DB::scalar("SELECT set_config('application_name',?,false)", [$f['tag']]);
echo 'READY_PID='.DB::scalar('SELECT pg_backend_pid()').PHP_EOL;
flush();
// Readiness of the second process may consume its own separate bootstrap budget.
$until = microtime(true) + 75;
while (! is_file($f['release'])) {
    clearstatcache(true, $f['release']);
    if (microtime(true) > $until) {
        throw new RuntimeException('Inquiry worker release missing.');
    }
    usleep(10000);
}
$store = app('session')->driver();
$store->setId($f['session_id']);
$store->put('a001_generation', $f['generation']);
$request = Request::create('/api/businesses');
$request->setLaravelSession($store);
$request->setUserResolver(fn () => $user);
try {
    $result = app(InquiryEvidence::class)->write($request, $f['business'], $f['command'], $f['declaration'] ?? null, $f['expected'] ?? 0, $f['effect'] ?? 'record', $f['value'], $f['selection']);
    echo 'COMMITTED='.json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (QueryException $error) {
    echo 'DENIED_STATE='.$error->errorInfo[0].PHP_EOL;
    exit(2);
}
