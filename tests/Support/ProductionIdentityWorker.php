<?php

use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

echo 'STARTED '.microtime(true).PHP_EOL;
flush();
$candidateBootstrap = getenv('BUILDERAD_TEST_BOOTSTRAP');
require $candidateBootstrap ?: getcwd().'/vendor/autoload.php';
echo 'AUTOLOADED '.microtime(true).PHP_EOL;
flush();
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
echo 'KERNEL_READY '.microtime(true).PHP_EOL;
flush();
app(DisposableTestDatabaseGuard::class)->assertSafe();
echo 'GUARD_READY '.microtime(true).PHP_EOL;
flush();
$app->detectEnvironment(fn () => 'local');
$pid = DB::connection('pgsql_authority')->selectOne('SELECT pg_backend_pid() AS pid')->pid;
echo 'BOOTED '.$pid.PHP_EOL;
flush();
$input = json_decode(base64_decode(getenv('P2B_REQUEST')), true, flags: JSON_THROW_ON_ERROR);
$server = ['HTTP_ACCEPT' => 'application/json'];
foreach ($input['headers'] as $name => $value) {
    $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
}
$request = Request::create('http://localhost'.($input['path'] ?? '/login'), 'POST', $input['data'], $input['cookies'], [], $server);
$attempts = 0;
$logins = 0;
$verifierLookups = 0;
Event::listen(Attempting::class, function () use (&$attempts): void {
    $attempts++;
});
Event::listen(Login::class, function () use (&$logins): void {
    $logins++;
});
DB::listen(function ($query) use (&$verifierLookups): void {
    if (str_contains($query->sql, 'passkeys') && str_contains($query->sql, 'credential_id')) {
        $verifierLookups++;
    }
});
echo 'ATTEMPT'.PHP_EOL;
flush();
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
echo 'STATUS='.$response->getStatusCode().' AUTH_CALLS='.$attempts.' COOKIES='.count($response->headers->getCookies())
    .' LOGIN_EVENTS='.$logins.' VERIFIER_LOOKUPS='.$verifierLookups.PHP_EOL;
$kernel->terminate($request, $response);
exit($response->getStatusCode() === 409 && $attempts === 0 && $logins === 0 && $verifierLookups === 0 && count($response->headers->getCookies()) === 0 ? 0 : 1);
