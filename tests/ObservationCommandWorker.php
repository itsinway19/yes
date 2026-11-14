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
if (isset($fixture['entry_external_gate'])) {
    // Bootstrap outside the product transaction; release only after the reader captures its snapshot.
    if (DB::transactionLevel() !== 0) {
        throw new RuntimeException('Observation entry readiness must be outside a transaction.');
    }
    $gate = $fixture['entry_external_gate'];
    $state = DB::selectOne('SELECT pg_backend_pid() AS pid, pg_current_xact_id_if_assigned()::text AS xid');
    file_put_contents($gate['ready'], json_encode((array) $state + ['run' => $fixture['run'], 'command' => $fixture['command']], JSON_THROW_ON_ERROR), LOCK_EX);
    // Test-only transport variation: readiness is already durable even when
    // stdout cannot be delivered until the parent releases this worker.
    if (empty($fixture['defer_entry_ready_stdout'])) {
        echo 'ENTRY_READY'.PHP_EOL;
        flush();
    }
    $deadline = hrtime(true) + 30_000_000_000;
    do {
        clearstatcache(true, $gate['release']);
        if (is_file($gate['release'])) {
            break;
        }
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Observation entry release watchdog expired.');
        }
        usleep(10000);
    } while (true);
    if (! empty($fixture['defer_entry_ready_stdout'])) {
        echo 'ENTRY_READY'.PHP_EOL;
        flush();
    }
}
try {
    $result = DB::transaction(function () use ($fixture) {
        app(PrincipalContext::class)->activate(User::findOrFail($fixture['actor']));
        $scope = App\Providers\Ads\ProviderScope::fromArray($fixture['scope']);
        $s = $scope->jsonSerialize();

        return DB::scalar('SELECT builderad_observation_accept(?, ?, ?::uuid, ?, ?, ?, ?, ?, ?::uuid, ?, ?, ?::jsonb)', [
            $s['business_id'], $fixture['session'], $s['source_id'], $s['provider'], $s['account_id'], $s['operation'], $s['purpose'],
            'synthetic-range', $fixture['command'], $fixture['generation'], 1, json_encode($fixture['payload'], JSON_THROW_ON_ERROR),
        ]);
    });
    echo 'RESULT='.$result;
} catch (QueryException $error) {
    echo 'DENIED='.$error->getCode();
}
