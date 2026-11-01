<?php

// Guarded disposable participant only, never an application command or HTTP issuer.
use App\Foundation\Tenancy\PrincipalContext;
use App\MarketKnowledge\KnowledgeCompletionEvidence;
use App\Models\User;
use App\Security\DisposableTestDatabaseGuard;
use App\Security\SessionAuthority;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(DisposableTestDatabaseGuard::class)->assertSafe();
$f = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$user = User::findOrFail($f['actor']);
DB::scalar("SELECT set_config('application_name',?,false)", [$f['tag']]);
echo 'READY_PID='.DB::scalar('SELECT pg_backend_pid()').PHP_EOL;
flush();
$until = microtime(true) + 25;
while (! is_file($f['release'])) {
    clearstatcache(true, $f['release']);
    if (microtime(true) > $until) {
        throw new RuntimeException('External participant release absent.');
    }
    usleep(10000);
}
$store = app('session')->driver();
$store->setId($f['session_id']);
$store->put('a001_generation', $f['generation']);
$request = Request::create('/internal/market-knowledge/publish');
$request->setLaravelSession($store);
$request->setUserResolver(fn () => $user);
try {
    $result = DB::transaction(function () use ($f, $user, $request) {
        app(PrincipalContext::class)->activate($user);
        if (($f['operation'] ?? 'publish') === 'capture') {
            return DB::scalar('SELECT builderad_v2_market_knowledge_capture(?,?::uuid,?,?::jsonb,?,?::jsonb)', [
                SessionAuthority::sessionKey($request), $f['body']['command_id'], $f['body']['question'], json_encode($f['body']['material_refs'], JSON_THROW_ON_ERROR),
                $f['body']['purpose'], json_encode($f['body']['authority_selection'], JSON_THROW_ON_ERROR),
            ]);
        }
        if (($f['operation'] ?? 'publish') === 'private_capture') {
            $body = $f['body'];

            return DB::scalar('SELECT builderad_v2_business_finding_capture(?,?::uuid,?::uuid,?::uuid,?::uuid,?::uuid,?::jsonb,?::jsonb,?::jsonb)', [
                SessionAuthority::sessionKey($request), $f['business'], $body['command_id'], $body['processing_run_ref'], $body['read_command_id'], $body['output_scope_id'],
                json_encode($body['authority_selection'], JSON_THROW_ON_ERROR), $body['context_selection'] === null ? null : json_encode($body['context_selection'], JSON_THROW_ON_ERROR), json_encode($body['continuation_selection'], JSON_THROW_ON_ERROR),
            ]);
        }
        $finding = $f['finding'];
        $finding['values'] = (object) $finding['values'];
        $ticket = empty($f['replay']) ? app(KnowledgeCompletionEvidence::class)->issue($request, ['run_ref' => $f['body']['run_ref'], 'method_ref' => $finding['method_ref']], $f['body'], $finding) : null;
        if (! empty($f['short_ticket'])) {
            $row = (array) DB::connection('pgsql_migrations')->table('market_knowledge_completion_tickets')->where('token_hash', hash('sha256', $ticket))->first();
            $ticket = bin2hex(random_bytes(32));
            $row['token_hash'] = hash('sha256', $ticket);
            $row['expires_at'] = DB::connection('pgsql_authority')->scalar("SELECT (clock_timestamp()+interval '2 seconds')::text");
            DB::connection('pgsql_authority')->table('market_knowledge_completion_tickets')->insert($row);
            echo 'ATTESTATION_EXPIRES='.$row['expires_at'].PHP_EOL;
            flush();
        }

        return DB::scalar('SELECT builderad_v2_market_knowledge_publish(?,?::uuid,?::uuid,?::uuid,?::jsonb,?::jsonb,?::jsonb,?)', [
            SessionAuthority::sessionKey($request), $f['body']['command_id'], $f['body']['run_ref'], $f['body']['output_scope_id'], json_encode($finding, JSON_THROW_ON_ERROR),
            json_encode($f['body']['process_selection'], JSON_THROW_ON_ERROR), json_encode($f['body']['publication_selection'], JSON_THROW_ON_ERROR), $ticket,
        ]);
    });
    echo 'COMMITTED='.$result.PHP_EOL;
    exit(0);
} catch (QueryException $error) {
    echo 'DENIED_STATE='.($error->errorInfo[0] ?? 'unknown').PHP_EOL;
    exit(2);
}
