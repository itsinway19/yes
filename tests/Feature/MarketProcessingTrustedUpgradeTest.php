<?php

namespace Tests\Feature;

use App\Foundation\Tenancy\PrincipalContext;
use App\MarketKnowledge\Processing\MarketProcessingTask;
use App\MarketKnowledge\Processing\OfferEquivalence;
use App\Security\SessionAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DisposableDatabaseReset;
use Tests\Support\MarketProcessingFixture;
use Tests\TestCase;

final class MarketProcessingTrustedUpgradeTest extends TestCase
{
    use MarketProcessingFixture;

    public function test_populated_d2_upgrade_preserves_history_but_never_promotes_unattested_results(): void
    {
        $reset = app(DisposableDatabaseReset::class);
        $reset->clear();
        try {
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($p) => strcmp(basename($p), '0001_01_01_000460_harden_market_processing_completion.php') < 0));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--path' => $paths, '--realpath' => true, '--force' => true]));
            $f = $this->processingOriginals();
            $c = $this->processingConsumer($f);
            $body = $this->processingBody($f, $c);
            $this->prepareProcessing($c, $body)->assertOk();
            $task = new MarketProcessingTask('compare_offers', array_map(fn ($record) => ['ref' => $record['ref'], 'fields' => $record['payload']['fields'], 'evidence' => ['schema' => 'market.offer:2']], $f['records']));
            $finding = (new OfferEquivalence)->execute($task);
            $request = Request::create('/');
            $request->setLaravelSession(app('session')->driver());
            $legacy = DB::transaction(function () use ($c, $body, $finding, $request) {
                app(PrincipalContext::class)->activate($c['user']);

                return json_decode(DB::scalar('SELECT builderad_v2_market_processing_complete(?,?::uuid,?::uuid,?::uuid,?,?::jsonb,?,?::jsonb,NULL)', [
                    SessionAuthority::sessionKey($request), $c['business']['business_public_id'], (string) Str::uuid(), $body['command_id'],
                    'complete', json_encode(['state' => 'validated', 'finding' => $finding, 'reason_code' => null], JSON_THROW_ON_ERROR), 1,
                    json_encode($c['selection'], JSON_THROW_ON_ERROR),
                ]), true, flags: JSON_THROW_ON_ERROR);
            });
            $this->assertSame('validated', $legacy['state']);
            $prepared = $body;
            $prepared['command_id'] = (string) Str::uuid();
            $this->prepareProcessing($c, $prepared)->assertOk();
            $db = DB::connection('pgsql_migrations');
            $snapshots = [];
            foreach (['market_evidence_revisions', 'data_use_scopes', 'security_action_grants', 'business_command_receipts', 'market_processing_runs'] as $table) {
                $snapshots[$table] = $db->scalar('SELECT jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text)::text FROM '.$table.' t');
            }
            $db->statement("CREATE FUNCTION public.completion_upgrade_drift() RETURNS integer LANGUAGE sql AS 'SELECT 1'");
            try {
                Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]);
                $this->fail('Unqualified drift was sealed');
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->errorInfo[0]);
                $this->assertStringContainsString('TRUSTED_COMPLETION_UPGRADE_BASELINE_QUALIFICATION_REQUIRED', $e->getMessage());
                $this->assertNull($db->scalar("SELECT to_regclass('market_processing_completion_tickets')::text"));
            } finally {
                $db->statement('DROP FUNCTION public.completion_upgrade_drift()');
            }
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            foreach ($snapshots as $table => $bytes) {
                $expression = $table === 'market_processing_runs' ? "to_jsonb(t)-'completion_attestation_digest'" : 'to_jsonb(t)';
                $this->assertSame($bytes, $db->scalar('SELECT jsonb_agg('.$expression.' ORDER BY ('.$expression.')::text)::text FROM '.$table.' t'), $table);
            }
            $this->assertSame(0, $db->table('market_processing_completion_tickets')->count());
            $this->completeProcessing($c, $body['command_id'])->assertForbidden();
            $this->assertSame('validated', $db->table('market_processing_runs')->where('run_id', $body['command_id'])->value('state'));
            $this->assertNull($db->table('market_processing_runs')->where('run_id', $body['command_id'])->value('completion_attestation_digest'));
            $this->completeProcessing($c, $prepared['command_id'])->assertOk()->assertJsonPath('data.finding.status', 'equivalent');
            $this->assertNotNull($db->table('market_processing_runs')->where('run_id', $prepared['command_id'])->value('completion_attestation_digest'));
            $db->transaction(function () use ($db): void {
                $db->statement('SET LOCAL ROLE builderad_scope_executor');
                $this->assertTrue($db->scalar('SELECT builderad_private.legacy_catalog_digest()=builderad_private.legacy_installation_digest()'));
            });
            $this->assertFalse($db->scalar("SELECT has_schema_privilege('builderad_scope_executor','public','CREATE')"));
            $this->assertFalse($db->scalar("SELECT has_function_privilege('builderad_owner','builderad_private.guard_effect()','EXECUTE')"));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
        } finally {
            $reset->install();
        }
    }
}
