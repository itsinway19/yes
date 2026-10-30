<?php

// Test-only real participant for the canonical Business Context lock investigation.
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
if (isset($fixture['entry_external_gate'])) {
    // Prepare PHP/application/database startup before the parent begins a real
    // product lock wait. This gate owns no transaction, row lock or authority.
    $gate = $fixture['entry_external_gate'];
    $state = DB::selectOne("SELECT pg_backend_pid() AS pid,pg_current_xact_id_if_assigned()::text AS xid,current_setting('lock_timeout') AS lock_timeout");
    file_put_contents($gate['ready'], json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
    $emit('ENTRY_READY');
    $deadline = microtime(true) + 30;
    do {
        clearstatcache(true, $gate['release']);
        if (is_file($gate['release'])) {
            break;
        }
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Prepared DTM entry release absent.');
        }
        usleep(10000);
    } while (true);
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
    $revision = DB::transaction(function () use ($fixture, $user, $emit) {
        app(PrincipalContext::class)->activate($user);
        DB::selectOne('SELECT builderad_v2_legacy_enter((SELECT public_id FROM workspaces WHERE id=?))', [$fixture['workspace']]);
        if (isset($fixture['entry_barrier'])) {
            $emit('READY_XACT='.DB::scalar('SELECT pg_current_xact_id()::text'));
            DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtextextended(?,0))', [$fixture['entry_barrier']]);
        }
        $emit('ENTERED_XACT='.DB::scalar('SELECT pg_current_xact_id()::text'));
        DB::selectOne('SELECT builderad_business_context_begin(?,?)', [$fixture['workspace'], $fixture['session']]);
        $emit('SELECTED_XACT='.DB::scalar('SELECT pg_current_xact_id()::text'));

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
    $emit('RESULT='.$revision);
} catch (QueryException $error) {
    $emit('DENIED='.$error->getCode());
    $emit('DETAIL='.$error->getMessage());
}
