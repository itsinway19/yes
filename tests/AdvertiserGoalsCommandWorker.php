<?php

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
$fixture = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$user = User::findOrFail($fixture['actor']);
file_put_contents($fixture['ready'], 'ready');
$deadline = microtime(true) + 25;
while (! is_file($fixture['release'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrency release was not provided');
    }
    clearstatcache(true, $fixture['release']);
    usleep(10000);
}
try {
    $saved = DB::transaction(function () use ($fixture, $user) {
        app(PrincipalContext::class)->activate($user);
        DB::selectOne('SELECT builderad_select_workspace_context(?)', [$fixture['workspace']]);

        return DB::scalar('SELECT builderad_advertiser_goals_write(?,?,?::jsonb,?)', [
            $fixture['workspace'], $fixture['session'], json_encode($fixture['value'], JSON_THROW_ON_ERROR), $fixture['version'],
        ]);
    });
    echo 'SAVED='.$saved.PHP_EOL;
} catch (QueryException $error) {
    echo 'STATE='.$error->getCode().PHP_EOL;
}
