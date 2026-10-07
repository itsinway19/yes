<?php

// Real test participant, restricted by the disposable database guard.
use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(DisposableTestDatabaseGuard::class)->assertSafe();
$f = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
if ($f['expire'] ?? false) {
    DB::connection('pgsql_migrations')->table('security_sessions')->where('session_key_hash', $f['session'])
        ->update(['idle_expires_at' => DB::raw("clock_timestamp()+interval '2 seconds'")]);
}
echo 'PID='.DB::scalar('SELECT pg_backend_pid()').PHP_EOL;
flush();
try {
    $result = DB::transaction(function () use ($f) {
        app(PrincipalContext::class)->activate(User::findOrFail($f['actor']));
        DB::scalar('SELECT builderad_select_workspace_context(?)', [$f['workspace']]);

        return DB::scalar('SELECT builderad_object_purpose_write(?,?,?::jsonb)', [$f['workspace'], $f['session'], json_encode($f['command'])]);
    });
    echo 'RESULT='.$result.PHP_EOL;
} catch (QueryException $e) {
    echo 'DENIED='.$e->getCode().PHP_EOL;
}
