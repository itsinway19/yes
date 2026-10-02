<?php

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Synthetic independent process. No provider API or production endpoint.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(DisposableTestDatabaseGuard::class)->assertSafe();
$fixture = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
DB::selectOne("SELECT set_config('application_name',?,false)", [$fixture['run']]);
try {
    $result = DB::transaction(function () use ($fixture) {
        app(PrincipalContext::class)->activate(User::findOrFail($fixture['actor']));
        DB::selectOne('SELECT builderad_select_workspace_context(?)', [$fixture['scope']['workspace_id']]);
        $s = $fixture['scope'];

        return DB::scalar('SELECT builderad_observation_accept(?, ?, ?::uuid, ?, ?, ?, ?, ?, ?::uuid, ?, ?, ?::jsonb)', [
            $s['workspace_id'], $fixture['session'], $s['source_id'], $s['provider'], $s['account_id'], $s['operation'], $s['purpose'],
            'synthetic-range', $fixture['command'], $fixture['generation'], 1, json_encode($fixture['payload'], JSON_THROW_ON_ERROR),
        ]);
    });
    echo 'RESULT='.$result;
} catch (QueryException $error) {
    echo 'DENIED='.$error->getCode();
}
