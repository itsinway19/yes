<?php

// Independent participant in the guarded disposable integration test only.
use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Platform\PlatformMaintenance;
use App\Security\DisposableTestDatabaseGuard;
use App\Security\SessionAuthority;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(DisposableTestDatabaseGuard::class)->assertSafe();
$f = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$user = User::findOrFail($f['actor']);
$store = app('session')->driver();
$store->setId($f['session_id']);
$store->put('a001_generation', 1);
$request = Request::create('/internal/administration/maintenance', 'POST');
$request->setLaravelSession($store);
$request->setUserResolver(fn () => $user);
try {
    $result = DB::transaction(function () use ($request, $user, $f) {
        app(PrincipalContext::class)->activate($user);

        return DB::connection('pgsql_authority')->transaction(function () use ($request, $user, $f) {
            app(SessionAuthority::class)->assertCurrentAuthority($request, $user, 1);
            echo 'READY_PID='.DB::connection('pgsql_authority')->scalar('SELECT pg_backend_pid()').PHP_EOL;
            echo 'AUTHORITY_XID='.DB::connection('pgsql_authority')->scalar('SELECT pg_current_xact_id()::text').PHP_EOL;
            flush();

            return app(PlatformMaintenance::class)->change($request, $f['body']);
        });
    });
    echo json_encode(['saved' => $result['saved']]), PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['denied' => true, 'class' => get_class($error), 'status' => $error instanceof AuthorizationException ? 403 : ($error instanceof HttpExceptionInterface ? $error->getStatusCode() : 500)]), PHP_EOL;
}
