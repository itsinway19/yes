<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\BusinessNativeMaterialFixture;
use Tests\Support\DisposableDatabaseReset;
use Tests\Support\NativeMaterialFixture;
use Tests\TestCase;

final class BusinessNativeMaterialUpgradeTest extends TestCase
{
    use BusinessNativeMaterialFixture;
    use NativeMaterialFixture;

    private function installThrough650(): void
    {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')),
            fn ($path) => strcmp(basename($path), '0001_01_01_000660_complete_native_material_identity.php') < 0));
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--path' => $paths, '--realpath' => true, '--force' => true]));
    }

    private function legacyClosure(array $refs): string
    {
        $owner = DB::connection('pgsql_migrations');

        return $owner->transaction(function () use ($owner, $refs) {
            $owner->statement('SET LOCAL ROLE builderad_scope_executor');

            return $owner->scalar('SELECT builderad_private.material_closure(?::jsonb)::text', [json_encode($refs)]);
        });
    }

    public function test_populated650_upgrade_restores_qualified_native_use_without_rewriting_legacy_data_rights_or_lineage(): void
    {
        $this->usePersistentTestSessions();
        $reset = app(DisposableDatabaseReset::class);
        $reset->clear();
        try {
            $this->installThrough650();
            $native = $this->nativeMaterial();
            $nativeUrl = '/api/businesses/'.$native['business']['business_public_id'].'/materials/read';
            $this->postJson($nativeUrl, $native['body'])->assertForbidden();
            $legacy = $this->original(adoptViaCommand: true);
            $legacyUrl = '/api/businesses/'.$legacy['a']['business_public_id'].'/materials/read';
            $legacyResult = $this->postJson($legacyUrl, $legacy['body'])->assertOk()->json();
            $closure = $this->legacyClosure($legacy['body']['material_refs']);
            $owner = DB::connection('pgsql_migrations');
            $before = [];
            foreach (['source_registry', 'source_versions', 'source_command_receipts', 'source_capabilities', 'source_audit_obligations',
                'provider_access', 'provider_access_events', 'provider_observation_streams', 'provider_observation_runs', 'provider_observation_entries',
                'security_action_grants', 'authority_qualification_receipts', 'client_data_boundaries', 'boundary_subjects',
                'data_use_scopes', 'business_data_use_bindings', 'business_command_receipts', 'material_lineage_manifests'] as $table) {
                $before[$table] = $owner->scalar("SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]')::text FROM {$table} r");
            }
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            foreach ($before as $table => $rows) {
                $this->assertSame($rows, $owner->scalar("SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]')::text FROM {$table} r"), $table.' is not rewritten by660');
            }
            $this->assertSame($closure, $this->legacyClosure($legacy['body']['material_refs']));
            $this->assertSame($legacyResult, $this->postJson($legacyUrl, $legacy['body'])->assertOk()->json());
            $this->actingAs($native['user']);
            $this->postJson($nativeUrl, $native['body'])->assertOk()->assertJsonPath('data.materials.0.fields.spend.native_value', '12');
            foreach (['builderad_app', 'builderad_authority', 'public'] as $role) {
                $this->assertFalse((bool) $owner->scalar("SELECT has_function_privilege(?, 'builderad_private.resolve_material(jsonb)', 'EXECUTE')", [$role]));
                $this->assertFalse((bool) $owner->scalar("SELECT has_function_privilege(?, 'builderad_private.material_use_plan(bigint,uuid,jsonb,text,text,jsonb)', 'EXECUTE')", [$role]));
            }
            $this->assertFalse((bool) $owner->scalar("SELECT has_schema_privilege('builderad_scope_executor','builderad_private','CREATE')"));
        } finally {
            DB::disconnect('pgsql');
            $reset->install();
        }
    }

    public function test660_rejects_unsealed_catalog_before_replacing_any_material_function(): void
    {
        $reset = app(DisposableDatabaseReset::class);
        $reset->clear();
        try {
            $this->installThrough650();
            $owner = DB::connection('pgsql_migrations');
            $before = $owner->scalar("SELECT pg_get_functiondef('builderad_private.resolve_material(jsonb)'::regprocedure)");
            $owner->unprepared('CREATE OR REPLACE FUNCTION public.builderad_source_registration_available(p_workspace bigint,p_source uuid,p_resource text,p_operation text,p_purpose text) RETURNS boolean LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=public,pg_temp AS $$ SELECT false $$');
            try {
                Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]);
                $this->fail('An unsealed native-material baseline was accepted.');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->getCode());
                $this->assertStringContainsString('NATIVE_MATERIAL_IDENTITY_BASELINE_REQUIRED', $error->getMessage());
            }
            $this->assertSame($before, $owner->scalar("SELECT pg_get_functiondef('builderad_private.resolve_material(jsonb)'::regprocedure)"));
            $this->assertFalse($owner->table('migrations')->where('migration', '0001_01_01_000660_complete_native_material_identity')->exists());
            $this->assertFalse((bool) $owner->scalar("SELECT has_schema_privilege('builderad_scope_executor','builderad_private','CREATE')"));
        } finally {
            DB::disconnect('pgsql');
            $reset->install();
        }
    }
}
