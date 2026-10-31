<?php

// Disposable integration participant; never an application command.
use App\Foundation\Tenancy\PrincipalContext;
use App\MarketKnowledge\Processing\TrustedCompletion;
use App\Models\User;
use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(DisposableTestDatabaseGuard::class)->assertSafe();
$f = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$user = User::findOrFail($f['actor']);
DB::scalar("SELECT set_config('application_name',?,false)", [$f['run']]);
echo 'READY_PID='.DB::scalar('SELECT pg_backend_pid()').PHP_EOL;
flush();
$deadline = microtime(true) + 25;
$releaseValue = null;
while (true) {
    clearstatcache(true, $f['release']);
    if (is_file($f['release']) && (empty($f['require_release_content']) || ($releaseValue = file_get_contents($f['release'])) !== '')) {
        break;
    }
    if (microtime(true) > $deadline) {
        throw new RuntimeException('External startup release absent.');
    }
    usleep(10000);
}
$f['release_value'] = $releaseValue;
try {
    $result = DB::transaction(function () use ($f, $user): mixed {
        app(PrincipalContext::class)->activate($user);
        echo 'XID='.DB::scalar('SELECT pg_current_xact_id()::text').PHP_EOL;
        flush();
        if ($f['operation'] === 'market-processing-complete') {
            if ($f['outcome']['finding'] !== null) {
                $f['outcome']['finding']['values'] = (object) $f['outcome']['finding']['values'];
            }
            $store = app('session')->driver();
            $store->setId($f['session_id']);
            $store->put('a001_generation', $f['generation']);
            $request = Request::create('/');
            $request->setLaravelSession($store);
            $request->setUserResolver(fn () => $user);
            $ticket = empty($f['attested_replay']) ? app(TrustedCompletion::class)->issue(
                $request, $f['business'], ['run_ref' => $f['processing_run'], 'method_ref' => $f['outcome']['finding']['method_ref']],
                $f['command'], 'complete', $f['outcome'], 1, $f['selection'], null,
            ) : null;

            if (! empty($f['attestation_expires_soon'])) {
                // Guarded disposable fixture: exercise expiry at the final receipt
                // without lengthening any product timeout or the actual issuer lifetime.
                $row = json_decode(DB::connection('pgsql_migrations')->scalar('SELECT to_jsonb(t)::text FROM market_processing_completion_tickets t WHERE token_hash=?', [hash('sha256', $ticket)]), true, flags: JSON_THROW_ON_ERROR);
                $ticket = bin2hex(random_bytes(32));
                $row['token_hash'] = hash('sha256', $ticket);
                $row['expires_at'] = DB::connection('pgsql_authority')->scalar("SELECT (clock_timestamp()+interval '2 seconds')::text");
                DB::connection('pgsql_authority')->table('market_processing_completion_tickets')->insert($row);
                echo 'COMPLETION_ATTESTATION_EXPIRES='.$row['expires_at'].PHP_EOL;
                flush();
            }

            return DB::scalar('SELECT builderad_v2_market_processing_complete(?,?::uuid,?::uuid,?::uuid,?,?::jsonb,?,?::jsonb,NULL,?)',
                [$f['session'], $f['business'], $f['command'], $f['processing_run'], 'complete', json_encode($f['outcome']), 1, json_encode($f['selection']), $ticket]);
        }
        if ($f['operation'] === 'market-write') {
            return DB::scalar('SELECT builderad_v2_market_evidence_write(?,?::uuid,?::uuid,?::uuid,?,?::jsonb,?::jsonb)',
                [$f['session'], $f['command'], $f['evidence'], $f['scope'], $f['expected'], json_encode($f['payload']), json_encode($f['selection'])]);
        }
        if ($f['operation'] === 'boundary') {
            return DB::scalar('SELECT builderad_v2_boundary_surface(?,?::uuid,?)', [$f['session'], $f['boundary'], $f['read_grant']]);
        }
        if ($f['operation'] === 'source-surface') {
            return DB::scalar('SELECT builderad_v2_source_surface(?,?::uuid,?)', [$f['session'], $f['source'], $f['read_grant']]);
        }
        if ($f['operation'] === 'source-choices') {
            return DB::scalar('SELECT builderad_v2_source_choices(?)', [$f['session']]);
        }
        if ($f['operation'] === 'data-use-choices') {
            return DB::scalar('SELECT builderad_v2_data_use_choices(?)', [$f['session']]);
        }
        if ($f['operation'] === 'surface') {
            return DB::scalar('SELECT builderad_v2_business_surface(?,?::uuid,?)', [$f['session'], $f['business'], $f['read_grant']]);
        }
        if ($f['operation'] === 'history') {
            return DB::scalar('SELECT builderad_v2_business_history(?,?::uuid,?,\'context\',0)', [$f['session'], $f['business'], $f['read_grant']]);
        }
        if ($f['operation'] === 'legacy-history') {
            return DB::scalar('SELECT builderad_v2_business_legacy_history(?,?::uuid,?,\'context\',NULL)', [$f['session'], $f['business'], $f['read_grant']]);
        }
        if ($f['operation'] === 'grant') {
            return DB::scalar('SELECT builderad_v2_business_grant_command(?,?::uuid,?::uuid,?,?::uuid,?,?,?,?,?::timestamptz,false,NULL,?::jsonb)',
                [$f['session'], $f['business'], $f['command'], $f['grant_effect'], $f['recipient'], 'business.context.read',
                    $f['grant_effect'] === 'issue' ? $f['issuer'] : null, $f['grant_id'], $f['grant_effect'] === 'issue' ? 0 : 1,
                    $f['grant_effect'] === 'issue' ? trim($f['release_value'] ?? file_get_contents($f['release'])) : null, json_encode($f['selection'])]);
        }
        if ($f['operation'] === 'publish') {
            return DB::scalar('SELECT builderad_v2_scoped_material_publish(?,?::uuid,?::uuid,?::jsonb,?::jsonb,?,?::uuid,?::jsonb)',
                [$f['session'], $f['business'], $f['command'], json_encode($f['artifact']), json_encode($f['dependencies']), $f['purpose'], $f['recipient'], json_encode($f['selection'])]);
        }
        if ($f['operation'] === 'purpose') {
            return DB::scalar('SELECT builderad_v2_business_purpose_write(?,?::uuid,?::jsonb,?,?::jsonb)',
                [$f['session'], $f['business'], json_encode($f['purpose_command']), $f['expected'], json_encode($f['selection'])]);
        }
        if ($f['operation'] === 'material') {
            if (! empty($f['material_refs_from_release'])) {
                $late = json_decode($f['release_value'], true, flags: JSON_THROW_ON_ERROR);
                $f['refs'] = $late['material_refs'];
            }

            return DB::scalar('SELECT builderad_v2_scoped_material_read(?,?::uuid,?::uuid,?::jsonb,?,?,?::uuid,?::jsonb)',
                [$f['session'], $f['business'], $f['command'], json_encode($f['refs']), $f['material_operation'], $f['purpose'], $f['recipient'], json_encode($f['selection'])]);
        }
        if ($f['operation'] === 'resource') {
            return DB::scalar('SELECT builderad_v2_resource_right_command(?,?::uuid,?,?::uuid,?,?::jsonb,?,?::jsonb)',
                [$f['session'], $f['command'], $f['kind'], $f['target'], $f['effect'], json_encode((object) $f['payload']), $f['expected'], json_encode($f['selection'])]);
        }
        if ($f['operation'] === 'markets') {
            return DB::scalar('SELECT builderad_v2_business_markets_write(?,?::uuid,?::uuid,ARRAY(SELECT jsonb_array_elements_text(?::jsonb)),?,?::jsonb)',
                [$f['session'], $f['business'], $f['command'], json_encode($f['codes']), $f['expected'], json_encode($f['selection'])]);
        }
        if ($f['operation'] === 'intent') {
            return DB::scalar('SELECT builderad_v2_business_intent_write(?,?::uuid,?::uuid,?::jsonb,?::jsonb,?,?,?::jsonb)',
                [$f['session'], $f['business'], $f['command'], json_encode($f['value']), json_encode($f['confirmations']), $f['expected'], 0, json_encode($f['selection'])]);
        }
        if ($f['operation'] === 'preferences') {
            return DB::scalar('SELECT builderad_v2_business_preferences_write(?,?::uuid,?::uuid,?::jsonb,?,?::jsonb)',
                [$f['session'], $f['business'], $f['command'], json_encode($f['value']), $f['expected'], json_encode($f['selection'])]);
        }

        return DB::scalar('SELECT builderad_v2_business_context_write(?,?::uuid,?::uuid,?,NULL,?,?::jsonb)',
            [$f['session'], $f['business'], $f['command'], $f['name'], $f['expected'], json_encode($f['selection'])]);
    }, 1);
    echo 'COMMITTED='.$result.PHP_EOL;
} catch (QueryException $error) {
    echo 'DENIED_STATE='.$error->errorInfo[0].PHP_EOL;
    echo 'DENIED_REASON='.strtok($error->errorInfo[2] ?? 'unspecified', chr(10)).PHP_EOL;
    exit(2);
}
