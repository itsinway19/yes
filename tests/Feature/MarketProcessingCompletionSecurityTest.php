<?php

namespace Tests\Feature;

use App\Foundation\Tenancy\PrincipalContext;
use App\MarketKnowledge\Processing\Findings;
use App\MarketKnowledge\Processing\MarketProcessingTask;
use App\MarketKnowledge\Processing\OfferEquivalence;
use App\MarketKnowledge\Processing\TrustedCompletion;
use App\Models\User;
use App\Security\SessionAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MarketProcessingFixture;
use Tests\TestCase;

final class MarketProcessingCompletionSecurityTest extends TestCase
{
    use MarketProcessingFixture;

    private function prepared(bool $different = false): array
    {
        $f = $this->processingOriginals();
        if ($different) {
            $record = $f['records'][1];
            $payload = $record['payload'];
            $payload['fields']['product']['model'] = 'DISTINCT_SYNTHETIC_MODEL';
            $right = $this->marketRight($f['writer'], $record['source'], $record['evidence'], 'correct', array_keys($payload['fields']));
            $f['records'][1]['ref'] = $this->writeEvidence($record['evidence'], $right, $payload, 1)->assertOk()->json('data.material_ref');
            $f['records'][1]['payload'] = $payload;
        }
        $c = $this->processingConsumer($f);
        $body = $this->processingBody($f, $c);
        $run = $this->prepareProcessing($c, $body)->assertOk()->json('data');
        $task = new MarketProcessingTask('compare_offers', array_map(fn ($record) => ['ref' => $record['ref'], 'fields' => $record['payload']['fields'], 'evidence' => ['schema' => 'market.offer:2']], $f['records']));
        $method = new OfferEquivalence;
        $finding = $method->execute($task);
        Findings::validate($task, $method, $finding);
        $outcome = ['state' => 'validated', 'finding' => $finding, 'reason_code' => null];
        $request = Request::create('/');
        $request->setLaravelSession(app('session')->driver());
        $request->setUserResolver(fn () => $c['user']);
        $command = (string) Str::uuid();

        return compact('c', 'body', 'run', 'outcome', 'request', 'command');
    }

    private function transaction(array $f, callable $operation): mixed
    {
        return DB::transaction(function () use ($f, $operation) {
            app(PrincipalContext::class)->activate($f['c']['user']);
            $this->assertSame('builderad_app', DB::scalar('SELECT session_user'));

            return $operation();
        });
    }

    private function issue(array $f): string
    {
        return app(TrustedCompletion::class)->issue($f['request'], $f['c']['business']['business_public_id'], $f['run'], $f['command'], 'complete', $f['outcome'], 1, $f['c']['selection'], null);
    }

    private function invokeCompletion(array $f, ?string $ticket, bool $oldSignature = false): array
    {
        $bindings = [SessionAuthority::sessionKey($f['request']), $f['c']['business']['business_public_id'], $f['command'], $f['run']['run_ref'],
            'complete', json_encode($f['outcome'], JSON_THROW_ON_ERROR), 1, json_encode($f['c']['selection'], JSON_THROW_ON_ERROR), null];
        if (! $oldSignature) {
            $bindings[] = $ticket;
        }

        return json_decode(DB::scalar('SELECT builderad_v2_market_processing_complete(?,?::uuid,?::uuid,?::uuid,?,?::jsonb,?,?::jsonb,?::jsonb'.($oldSignature ? ')' : ',?)'), $bindings), true, flags: JSON_THROW_ON_ERROR);
    }

    private function denied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Unauthorized completion attestation use succeeded');
        } catch (QueryException $e) {
            $this->assertSame('42501', $e->errorInfo[0]);
        }
    }

    public function test_direct_signed_runtime_fabrication_is_rejected_by_both_sql_signatures(): void
    {
        $f = $this->prepared(different: true);
        $this->assertSame('different', $f['outcome']['finding']['status']);
        $f['outcome']['finding']['status'] = 'equivalent';
        $f['outcome']['finding']['reason_codes'] = ['QUALIFIED_IDENTITY_AND_TERMS_MATCH'];
        foreach ([true, false] as $oldSignature) {
            $this->denied(fn () => $this->transaction($f, fn () => $this->invokeCompletion($f, null, $oldSignature)));
        }
        $db = DB::connection('pgsql_migrations');
        $this->assertSame('prepared', $db->table('market_processing_runs')->value('state'));
        $this->assertNull($db->table('market_processing_runs')->value('finding'));
        $this->assertSame(0, $db->table('business_command_receipts')->where('command_id', $f['command'])->count());
        $this->completeProcessing($f['c'], $f['run']['run_ref'])->assertOk()->assertJsonPath('data.finding.status', 'different');
    }

    public function test_full_result_command_method_beneficiary_actor_generation_and_run_binding_are_enforced(): void
    {
        $f = $this->prepared();
        $other = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Synthetic other beneficiary'])->assertCreated()->json('data.business_public_id');
        $another = $f['body'];
        $another['command_id'] = (string) Str::uuid();
        $otherRun = $this->prepareProcessing($f['c'], $another)->assertOk()->json('data');
        $otherActor = User::factory()->create();
        foreach (['outcome', 'command', 'method', 'beneficiary', 'actor', 'generation', 'run'] as $tamper) {
            $token = null;
            $this->denied(function () use ($f, $tamper, &$token, $other, $otherRun, $otherActor) {
                return $this->transaction($f, function () use ($f, $tamper, &$token, $other, $otherRun, $otherActor) {
                    $issuer = $f;
                    if ($tamper === 'method') {
                        $issuer['run']['method_ref'] = 'unsupported:1';
                    }
                    if ($tamper === 'beneficiary') {
                        $issuer['c']['business']['business_public_id'] = $other;
                    }
                    if ($tamper === 'run') {
                        $issuer['run'] = $otherRun;
                    }
                    if (in_array($tamper, ['actor', 'generation'], true)) {
                        $issuer['request'] = clone $f['request'];
                        $store = clone $f['request']->session();
                        $issuer['request']->setLaravelSession($store);
                        if ($tamper === 'actor') {
                            $issuer['request']->setUserResolver(fn () => $otherActor);
                        } else {
                            $store->put('a001_generation', (int) $store->get('a001_generation') + 1);
                        }
                    }
                    $token = $this->issue($issuer);
                    if ($tamper === 'outcome') {
                        $f['outcome']['finding']['status'] = 'different';
                    }
                    if ($tamper === 'command') {
                        $f['command'] = (string) Str::uuid();
                    }

                    return $this->invokeCompletion($f, $token);
                });
            });
            $db = DB::connection('pgsql_migrations');
            $this->assertNull($db->table('market_processing_completion_tickets')->where('token_hash', hash('sha256', $token))->value('consumed_at'));
            $this->assertSame('prepared', $db->table('market_processing_runs')->where('run_id', $f['run']['run_ref'])->value('state'));
        }
    }

    public function test_single_use_consumption_and_run_are_atomic_but_terminal_replay_needs_no_new_ticket(): void
    {
        $f = $this->prepared();
        $this->transaction($f, function () use ($f): void {
            $token = $this->issue($f);
            $this->assertSame('validated', $this->invokeCompletion($f, $token)['state']);
            $this->denied(fn () => DB::transaction(fn () => $this->invokeCompletion($f, $token)));
            $this->assertSame('validated', $this->invokeCompletion($f, null, oldSignature: true)['state']);
        });
        $db = DB::connection('pgsql_migrations');
        $this->assertSame(1, $db->table('market_processing_completion_tickets')->whereNotNull('consumed_at')->count());
        $this->assertSame(1, $db->table('business_command_receipts')->where('command_id', $f['command'])->count());
        $this->assertSame(1, (int) $db->table('market_processing_runs')->value('execution_count'));
    }

    public function test_attestation_cannot_move_to_a_new_transaction_on_the_same_connection(): void
    {
        $f = $this->prepared();
        [$token, $first] = $this->transaction($f, fn () => [$this->issue($f), DB::selectOne('SELECT pg_backend_pid() AS pid,pg_current_xact_id()::text AS xid')]);
        $this->denied(fn () => $this->transaction($f, function () use ($f, $token, $first) {
            $next = DB::selectOne('SELECT pg_backend_pid() AS pid,pg_current_xact_id()::text AS xid');
            $this->assertSame($first->pid, $next->pid);
            $this->assertNotSame($first->xid, $next->xid);

            return $this->invokeCompletion($f, $token);
        }));
    }

    public function test_attestation_cannot_move_to_an_independent_runtime_connection(): void
    {
        $f = $this->prepared();
        [$token, $pid] = $this->transaction($f, fn () => [$this->issue($f), DB::scalar('SELECT pg_backend_pid()')]);
        $originalConnection = DB::getDefaultConnection();
        config(['database.connections.completion_peer' => config('database.connections.pgsql')]);
        DB::setDefaultConnection('completion_peer');
        try {
            $this->denied(fn () => $this->transaction($f, function () use ($f, $token, $pid) {
                $this->assertNotSame($pid, DB::scalar('SELECT pg_backend_pid()'));

                return $this->invokeCompletion($f, $token);
            }));
        } finally {
            DB::disconnect('completion_peer');
            DB::setDefaultConnection($originalConnection);
        }
    }

    public function test_expired_authority_issued_attestation_is_rejected_without_an_effect(): void
    {
        $f = $this->prepared();
        $this->denied(fn () => $this->transaction($f, function () use ($f) {
            $valid = $this->issue($f);
            $db = DB::connection('pgsql_migrations');
            $row = (array) $db->table('market_processing_completion_tickets')->where('token_hash', hash('sha256', $valid))->first();
            $token = bin2hex(random_bytes(32));
            $row['token_hash'] = hash('sha256', $token);
            $row['created_at'] = DB::raw("clock_timestamp()-interval '2 seconds'");
            $row['expires_at'] = DB::raw("clock_timestamp()-interval '1 second'");
            // Synthetic expired issuer evidence uses the same actual authority role,
            // never a runtime override or altered product timeout.
            $row['session_digest'] = '\\x'.hash('sha256', SessionAuthority::sessionKey($f['request']));
            $row['binding_digest'] = $db->scalar("SELECT '\\x'||encode(binding_digest,'hex') FROM market_processing_completion_tickets WHERE token_hash=?", [hash('sha256', $valid)]);
            DB::connection('pgsql_authority')->table('market_processing_completion_tickets')->insert($row);

            return $this->invokeCompletion($f, $token);
        }));
        $this->assertSame('prepared', DB::connection('pgsql_migrations')->table('market_processing_runs')->value('state'));
    }

    public function test_runtime_cannot_mint_read_or_mutate_attestations_and_authority_cannot_complete_runs(): void
    {
        $f = $this->prepared();
        foreach (['SELECT * FROM market_processing_completion_tickets', 'INSERT INTO market_processing_completion_tickets DEFAULT VALUES', 'UPDATE market_processing_completion_tickets SET consumed_at=clock_timestamp()', 'DELETE FROM market_processing_completion_tickets', 'SET ROLE builderad_authority'] as $sql) {
            $this->denied(fn () => DB::statement($sql));
        }
        $this->denied(fn () => DB::connection('pgsql_authority')->select('SELECT * FROM market_processing_completion_tickets'));
        $this->denied(fn () => DB::connection('pgsql_authority')->scalar('SELECT builderad_v2_market_processing_complete(NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL)'));
    }
}
