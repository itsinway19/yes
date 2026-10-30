<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\DisposableDatabaseReset;
use Tests\Support\MarketEvidenceFixture;
use Tests\Support\NativeMaterialFixture;
use Tests\TestCase;

final class MarketEvidenceUpgradeTest extends TestCase
{
    use MarketEvidenceFixture;
    use NativeMaterialFixture;

    public function test_populated_g4_upgrades_without_changing_client_rights_digests_or_native_replays(): void
    {
        $reset = app(DisposableDatabaseReset::class);
        $reset->clear();
        try {
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')),
                fn ($path) => ! str_ends_with($path, '000430_create_market_evidence_origin.php')));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--path' => $paths, '--realpath' => true, '--force' => true]));
            $f = $this->original();
            $route = '/api/businesses/'.$f['a']['business_public_id'].'/materials/read';
            $before = $this->postJson($route, $f['body'])->assertOk()->json();
            $db = DB::connection('pgsql_migrations');
            $digest = $db->scalar("SELECT encode(scope_digest,'hex') FROM data_use_scopes WHERE scope_id=?::uuid", [$f['scope']]);
            $grants = $db->table('security_action_grants')->orderBy('id')->get()->toJson();
            $db->unprepared("CREATE FUNCTION public.market_upgrade_test_drift() RETURNS integer LANGUAGE sql AS 'SELECT 1'");
            try {
                Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]);
                $this->fail('Unqualified catalog drift was promoted into the D1 installation.');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->errorInfo[0]);
                $this->assertStringContainsString('MARKET_UPGRADE_BASELINE_QUALIFICATION_REQUIRED', $error->getMessage());
                $this->assertFalse($db->scalar("SELECT EXISTS(SELECT 1 FROM pg_attribute WHERE attrelid='source_registry'::regclass AND attname='custody_kind' AND NOT attisdropped)"));
            } finally {
                $db->statement('DROP FUNCTION public.market_upgrade_test_drift()');
            }
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            $db->transaction(function () use ($db): void {
                $db->statement('SET LOCAL ROLE builderad_scope_executor');
                $this->assertTrue($db->scalar('SELECT builderad_private.legacy_catalog_digest()=builderad_private.legacy_installation_digest()'));
            });
            $this->assertSame($digest, $db->scalar("SELECT encode(scope_digest,'hex') FROM data_use_scopes WHERE scope_id=?::uuid", [$f['scope']]));
            $this->assertSame($grants, $db->table('security_action_grants')->orderBy('id')->get()->toJson());
            $this->assertSame('client', $db->table('data_use_scopes')->where('scope_id', $f['scope'])->value('rights_domain'));
            $this->assertSame('workspace_native', $db->table('source_registry')->where('id', $f['source'])->value('custody_kind'));
            $this->assertSame($before, $this->postJson($route, $f['body'])->assertOk()->json());
            $this->marketSource();
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            $this->assertSame(1, $db->table('migrations')->where('migration', '0001_01_01_000430_create_market_evidence_origin')->count());
            foreach (['canonical_data_selection(jsonb)', 'data_scope_digest(uuid,uuid,text,text,text,jsonb)', 'market_scope_digest(uuid,text,text,text,jsonb)', 'market_scope_identity(text,uuid,uuid,text,text,text,jsonb,bytea)', 'guard_effect()'] as $function) {
                $this->assertFalse($db->scalar('SELECT has_function_privilege(?, ?, ?)', ['builderad_owner', 'builderad_private.'.$function, 'EXECUTE']));
            }
        } finally {
            // Return the shared disposable harness to the latest installed schema.
            $reset->install();
        }
    }
}
