<?php

// Guarded synthetic Current Intent concurrency participant.
use App\Business\BusinessContext;
use App\Foundation\Tenancy\PrincipalContext;
use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\User;
use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(DisposableTestDatabaseGuard::class)->assertSafe();
$fixture = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$participantLog = storage_path('framework/testing/'.$fixture['run'].'-participant-'.getmypid().'.log');
$emit = static function (string $line) use ($participantLog): void {
    file_put_contents($participantLog, $line.PHP_EOL, FILE_APPEND);
    echo $line.PHP_EOL;
    flush();
};
if (($fixture['operation'] ?? null) === 'revoke_manage') {
    // Guarded fixture-only authority change on an independent owner connection.
    $owner = DB::connection('pgsql_migrations');
    $owner->selectOne("SELECT set_config('application_name',?,false)", [$fixture['run'].'-revoker']);
    $emit('PID='.$owner->scalar('SELECT pg_backend_pid()'));
    $owner->transaction(function () use ($owner, $fixture, $emit): void {
        if (isset($fixture['revocation_barrier'])) {
            $emit('READY_REVOKER');
            $owner->selectOne('SELECT pg_advisory_xact_lock_shared(hashtextextended(?,0))', [$fixture['revocation_barrier']]);
        }
        $owner->table('security_action_grants')->where('workspace_id', $fixture['workspace'])
            ->where('subject_user_id', $fixture['actor'])->where('action', 'business.context.manage')
            ->update(['status' => 'revoked', 'revoked_at' => DB::raw('clock_timestamp()'),
                'version' => DB::raw('version+1'), 'updated_at' => DB::raw('clock_timestamp()')]);
    });
    $emit('REVOCATION_COMMITTED');
    exit(0);
}
$user = User::findOrFail($fixture['actor']);
DB::selectOne("SELECT set_config('application_name',?,false)", [$fixture['run']]);
$emit('PID='.DB::scalar('SELECT pg_backend_pid()'));
$awaitExternalRelease = static function (array $gate, string $signal, bool $heartbeat) use ($emit): void {
    // Test-only synchronization: never spend a product lock timeout on a gate.
    $state = DB::selectOne("SELECT pg_backend_pid() AS pid,pg_current_xact_id_if_assigned()::text AS xid,
        current_setting('lock_timeout') AS lock_timeout,current_setting('statement_timeout') AS statement_timeout,
        current_setting('idle_in_transaction_session_timeout') AS idle_timeout");
    if (file_put_contents($gate['ready'], json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new RuntimeException('Cannot publish external test gate readiness: '.$signal);
    }
    $emit($signal);
    $deadline = hrtime(true) + 30_000_000_000;
    $nextHeartbeat = hrtime(true);
    while (true) {
        clearstatcache(true, $gate['release']);
        if (is_file($gate['release'])) {
            return;
        }
        $now = hrtime(true);
        if ($now >= $deadline) {
            throw new RuntimeException('External test gate watchdog expired: '.$signal);
        }
        if ($heartbeat && $now >= $nextHeartbeat) {
            DB::selectOne('SELECT 1');
            $nextHeartbeat = $now + 1_000_000_000;
        }
        usleep(10000); // Poll an explicit release signal; no timing-based release.
    }
};
if (isset($fixture['writer_external_gate'])) {
    if (DB::transactionLevel() !== 0) {
        throw new RuntimeException('Writer readiness must be outside a product transaction.');
    }
    $awaitExternalRelease($fixture['writer_external_gate'], 'WRITER_READY', false);
}
if (isset($fixture['expiry_after_ready'])) {
    // Fixture clock starts after PHP/guard startup, within the unchanged 3s lock timeout.
    $owner = DB::connection('pgsql_migrations');
    if ($fixture['expiry_after_ready'] === 'grant') {
        $owner->table('security_action_grants')->where('workspace_id', $fixture['workspace'])->where('action', 'business.context.manage')
            ->update(['expires_at' => DB::raw("clock_timestamp() + interval '2 seconds'")]);
    } else {
        $owner->table('security_sessions')->where('user_id', $fixture['actor'])->where('session_key_hash', $fixture['session'])
            ->update(['idle_expires_at' => DB::raw("clock_timestamp() + interval '2 seconds'")]);
    }
}
try {
    if (($fixture['operation'] ?? null) === 'read') {
        if (isset($fixture['entry_barrier'])) {
            DB::transaction(function () use ($fixture, $emit): void {
                $emit('READY_XACT='.DB::scalar('SELECT pg_current_xact_id()::text'));
                DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtextextended(?,0))', [$fixture['entry_barrier']]);
            });
        }
        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $user);
        $session = new Store('intent-proof', new ArraySessionHandler(120));
        $session->setId($fixture['http_session_id']);
        $session->put('a001_generation', $fixture['generation']);
        $request->setLaravelSession($session);
        $revision = app(WorkspaceContext::class)->run($user, $fixture['workspace_public_id'],
            function ($active) use ($fixture, $request, $emit, $awaitExternalRelease) {
                $emit('SELECTED_XACT='.DB::scalar('SELECT pg_current_xact_id()::text'));
                if (isset($fixture['reader_external_gate'])) {
                    $awaitExternalRelease($fixture['reader_external_gate'], 'READER_HOLD_READY', true);
                }
                if (isset($fixture['read_barrier'])) {
                    DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtextextended(?,0))', [$fixture['read_barrier']]);
                }

                return app(BusinessContext::class)->projection($request, $active);
            }, $request);
    } else {
        $revision = DB::transaction(function () use ($fixture, $user, $emit) {
            app(PrincipalContext::class)->activate($user);
            if (isset($fixture['entry_barrier'])) {
                $emit('READY_XACT='.DB::scalar('SELECT pg_current_xact_id()::text'));
                DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtextextended(?,0))', [$fixture['entry_barrier']]);
            }
            $emit('ENTERED_XACT='.DB::scalar('SELECT pg_current_xact_id()::text'));
            DB::selectOne('SELECT builderad_business_context_begin(?,?)', [$fixture['workspace'], $fixture['session']]);
            $emit('SELECTED_XACT='.DB::scalar('SELECT pg_current_xact_id()::text'));

            if (($fixture['operation'] ?? null) === 'intent') {
                return DB::scalar('SELECT builderad_current_intent_write(?,?,?::jsonb,?::jsonb,?)', [
                    $fixture['workspace'], $fixture['session'], $fixture['value'] === null ? null : json_encode($fixture['value'], JSON_THROW_ON_ERROR),
                    json_encode((object) $fixture['confirmations'], JSON_THROW_ON_ERROR), $fixture['version'],
                ]);
            }
            if (($fixture['operation'] ?? 'name') === 'dtm') {
                return DB::scalar('SELECT builderad_business_target_markets_write(?,?,ARRAY(SELECT jsonb_array_elements_text(?::jsonb)),?)', [
                    $fixture['workspace'], $fixture['session'], json_encode($fixture['codes'], JSON_THROW_ON_ERROR), $fixture['version'],
                ]);
            }

            // Exercise the actual canonical forward name/type writer, never a mock.
            return DB::scalar('SELECT builderad_business_context_write(?,?,?,?,?)', [
                $fixture['workspace'], $fixture['session'], $fixture['type'], $fixture['name'], $fixture['version'],
            ]);
        });
    }
    if (is_array($revision)) {
        $emit('PROJECTION='.json_encode($revision, JSON_THROW_ON_ERROR));
        $emit(json_encode(['status' => 'accepted', 'sqlstate' => null, 'version' => $revision['version']], JSON_THROW_ON_ERROR));
    } else {
        $emit('RESULT='.$revision);
        $emit(json_encode(['status' => 'accepted', 'sqlstate' => null, 'version' => (int) $revision], JSON_THROW_ON_ERROR));
    }
} catch (QueryException $error) {
    $emit('DENIED='.$error->getCode());
    $emit(json_encode(['status' => 'denied', 'sqlstate' => $error->getCode(), 'version' => null], JSON_THROW_ON_ERROR));
}
