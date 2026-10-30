<?php

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Synthetic second process for guarded PostgreSQL concurrency proof; no production endpoint.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(DisposableTestDatabaseGuard::class)->assertSafe();
$fixture = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
DB::selectOne("SELECT set_config('application_name',?,false)", [$fixture['run']]);
try {
    $result = DB::transaction(function () use ($fixture) {
        app(PrincipalContext::class)->activate(User::findOrFail($fixture['actor']));
        DB::selectOne('SELECT builderad_v2_native_source_enter((SELECT public_id FROM workspaces WHERE id=?),?::uuid)', [$fixture['workspace'], $fixture['input']['source_id']]);
        DB::selectOne('SELECT builderad_v2_legacy_enter((SELECT public_id FROM workspaces WHERE id=?))', [$fixture['workspace']]);
        DB::selectOne('SELECT builderad_select_workspace_context(?)', [$fixture['workspace']]);
        $input = $fixture['input'];
        $payload = array_diff_key($input, array_flip(['source_id', 'command_id', 'effect']));

        return DB::scalar('SELECT builderad_source_command(?, ?, ?::uuid, ?::uuid, ?, ?::jsonb)', [
            $fixture['workspace'], $fixture['session'], $input['command_id'], $input['source_id'], $input['effect'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    });
    echo 'RESULT='.$result;
} catch (QueryException $error) {
    echo 'DENIED='.$error->getCode();
}
