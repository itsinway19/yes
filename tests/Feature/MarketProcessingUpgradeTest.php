<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DisposableDatabaseReset;
use Tests\Support\MarketProcessingFixture;
use Tests\TestCase;

final class MarketProcessingUpgradeTest extends TestCase
{
    use MarketProcessingFixture;

    public function test_populated_d1_upgrade_keeps_original_bytes_rights_receipts_and_qualified_v1_replay(): void
    {
        $reset = app(DisposableDatabaseReset::class);
        $reset->clear();
        try {
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($p) => strcmp(basename($p), '0001_01_01_000450_create_market_processing.php') < 0));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--path' => $paths, '--realpath' => true, '--force' => true]));
            $user = User::factory()->create();
            $this->actingAs($user);
            $source = $this->processingSource();
            $records = [];
            foreach ([1, 2] as $number) {
                $evidence = (string) Str::uuid7();
                $payload = $this->offer();
                $payload['record_key'] = $evidence;
                $right = $this->marketRight($user, $source, $evidence, 'ingest', array_keys($payload['fields']));
                $ref = $this->writeEvidence($evidence, $right, $payload)->assertOk()->json('data.material_ref');
                $records[] = compact('evidence', 'payload', 'ref');
            }
            $originals = compact('source', 'records');
            $consumer = $this->processingConsumer($originals);
            $readBody = ['command_id' => (string) Str::uuid(), 'material_refs' => array_column($records, 'ref'), 'operation' => 'process', 'purpose' => 'market_research',
                'recipient_ref' => $consumer['user']->public_id, 'authority_selection' => $consumer['selection']];
            $route = '/api/businesses/'.$consumer['business']['business_public_id'].'/materials/read';
            $before = $this->postJson($route, $readBody)->assertOk()->json();
            $db = DB::connection('pgsql_migrations');
            $snapshot = [];
            foreach (['market_evidence_revisions', 'data_use_scopes', 'security_action_grants', 'business_command_receipts', 'market_source_custody'] as $table) {
                $snapshot[$table] = $db->scalar('SELECT coalesce(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text),\'[]\')::text FROM '.$table.' t');
            }
            $db->statement("CREATE FUNCTION public.processing_upgrade_drift() RETURNS integer LANGUAGE sql AS 'SELECT 1'");
            try {
                Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]);
                $this->fail('Unqualified drift was sealed');
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->errorInfo[0]);
                $this->assertStringContainsString('PROCESSING_UPGRADE_BASELINE_QUALIFICATION_REQUIRED', $e->getMessage());
                $this->assertNull($db->scalar("SELECT to_regclass('market_processing_runs')::text"));
            } finally {
                $db->statement('DROP FUNCTION public.processing_upgrade_drift()');
            }
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            foreach ($snapshot as $table => $bytes) {
                $this->assertSame($bytes, $db->scalar('SELECT coalesce(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text),\'[]\')::text FROM '.$table.' t'), $table);
            }
            $after = $this->postJson($route, $readBody)->assertOk()->json();
            // Currentness is evaluated at delivery, while original fields and dependency truth remain stable.
            foreach (['dependency_digest', 'operation', 'purpose', 'versions'] as $key) {
                $this->assertSame($before['data'][$key], $after['data'][$key]);
            }
            foreach ([0, 1] as $key) {
                foreach (['ref', 'fields', 'evidence', 'provenance'] as $field) {
                    $this->assertSame($before['data']['materials'][$key][$field], $after['data']['materials'][$key][$field]);
                }
            }
            $body = $this->processingBody($originals, $consumer);
            $this->prepareProcessing($consumer, $body)->assertOk();
            $this->completeProcessing($consumer, $body['command_id'])->assertOk()->assertJsonPath('data.state', 'abstained')->assertJsonPath('data.finding.status', 'unresolved');
            $db->transaction(function () use ($db): void {
                $db->statement('SET LOCAL ROLE builderad_scope_executor');
                $this->assertTrue($db->scalar('SELECT builderad_private.legacy_catalog_digest()=builderad_private.legacy_installation_digest()'));
            });
            $this->assertFalse($db->scalar("SELECT has_schema_privilege('builderad_scope_executor','public','CREATE')"));
            $this->assertFalse($db->scalar("SELECT has_function_privilege('builderad_owner','builderad_private.guard_effect()','EXECUTE')"));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            $this->assertSame(1, $db->table('migrations')->where('migration', '0001_01_01_000450_create_market_processing')->count());
        } finally {
            $reset->install();
        }
    }
}
