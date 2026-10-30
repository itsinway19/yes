<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Executable G19/G23/G25/G31 catalog and atomicity postconditions. */
final class BusinessFinalBoundaryTest extends TestCase
{
    private const TABLES = ['businesses', 'business_context_heads', 'business_current_intents', 'business_authority_fences',
        'migration_cohort_control', 'migration_cohort_members', 'authority_qualification_receipts', 'business_workspace_links',
        'business_command_receipts', 'business_goal_preferences', 'business_context_transitions', 'business_intent_transitions',
        'business_declared_markets', 'business_market_transitions', 'business_preference_transitions', 'business_branches',
        'client_data_boundaries', 'boundary_subjects', 'data_use_scopes', 'business_data_use_bindings', 'resource_right_fences',
        'legacy_scope_mappings', 'object_purpose_business_context_refs', 'material_use_dependencies', 'material_lineage_manifests'];

    public function test_installed_runtime_catalog_has_forced_rls_no_raw_or_public_privileges_and_statement_truncate_guards(): void
    {
        $db = DB::connection('pgsql_migrations');
        foreach (self::TABLES as $table) {
            $row = $db->selectOne('SELECT relrowsecurity,relforcerowsecurity FROM pg_class WHERE oid=?::regclass', ['public.'.$table]);
            $this->assertTrue($row->relrowsecurity, $table);
            $this->assertTrue($row->relforcerowsecurity, $table);
            foreach (['builderad_app', 'builderad_authority'] as $role) {
                $this->assertFalse((bool) $db->scalar("SELECT has_table_privilege(?,?,'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')", [$role, 'public.'.$table]), $role.' '.$table);
            }
            $this->assertSame(1, (int) $db->scalar("SELECT count(*) FROM pg_trigger WHERE tgrelid=?::regclass AND tgname='zz_business_truncate' AND NOT tgisinternal AND(tgtype&1)=0 AND(tgtype&32)=32", ['public.'.$table]));
            $this->assertSame(0, (int) $db->scalar("SELECT count(*) FROM pg_class c CROSS JOIN LATERAL aclexplode(coalesce(c.relacl,acldefault('r',c.relowner))) a WHERE c.oid=?::regclass AND a.grantee=0", ['public.'.$table]));
        }
        $functions = $db->select("SELECT p.oid::regprocedure::text signature,p.prosecdef,p.proconfig FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='builderad_private' OR(n.nspname='public' AND p.proname LIKE 'builderad_v2_%')");
        $this->assertGreaterThan(50, count($functions));
        foreach ($functions as $function) {
            $this->assertSame(0, (int) $db->scalar("SELECT count(*) FROM pg_proc p CROSS JOIN LATERAL aclexplode(coalesce(p.proacl,acldefault('f',p.proowner))) a WHERE p.oid=?::regprocedure AND a.grantee=0 AND a.privilege_type='EXECUTE'", [$function->signature]), $function->signature);
            if ($function->prosecdef) {
                $this->assertMatchesRegularExpression('/search_path=pg_catalog.*pg_temp/', $function->proconfig, $function->signature);
                $this->assertStringNotContainsString('pg_temp,', $function->proconfig, $function->signature);
            }
        }
        $this->assertFalse((bool) $db->scalar("SELECT rolcanlogin OR rolsuper OR rolbypassrls FROM pg_roles WHERE rolname='builderad_scope_executor'"));
        foreach (['builderad_app', 'builderad_authority'] as $role) {
            $this->assertFalse((bool) $db->scalar("SELECT pg_has_role(?,'builderad_scope_executor','MEMBER')", [$role]));
            $this->assertFalse((bool) $db->scalar("SELECT has_schema_privilege(?,'public','CREATE') OR has_schema_privilege(?,'builderad_private','CREATE')", [$role, $role]));
        }
    }

    public function test_metadata_lock_needs_no_effect_permit_but_material_update_requires_exact_single_use_before_and_after_images(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $business = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Permit truth'])->assertCreated()->json('data');
        $db = DB::connection('pgsql_migrations');
        $id = $db->table('businesses')->where('public_id', $business['business_public_id'])->value('id');
        $db->beginTransaction();
        try {
            $db->statement('SET LOCAL ROLE builderad_scope_executor');
            $this->assertNotNull($db->selectOne('SELECT * FROM business_context_heads WHERE business_id=? FOR UPDATE', [$id]));
            $denied = function (callable $operation) use ($db): void {
                try {
                    $db->transaction($operation);
                    $this->fail('A material effect bypassed its exact permit.');
                } catch (QueryException $error) {
                    $this->assertSame('42501', $error->getCode());
                }
            };
            $denied(fn () => $db->update("UPDATE business_context_heads SET display_name='Unpermitted' WHERE business_id=?", [$id]));
            $db->scalar("SELECT builderad_private.start_command(?,'synthetic-session',?::uuid,'test.permit.boundary','{}','{\"fixture\":true}')", [$actor->id, (string) Str::uuid()]);
            $before = $db->scalar('SELECT to_jsonb(h)::text FROM business_context_heads h WHERE business_id=?', [$id]);
            $after = json_decode($before, true, flags: JSON_THROW_ON_ERROR);
            $after['display_name'] = 'Exact qualified image';
            $db->scalar("SELECT builderad_private.permit('public.business_context_heads','update',?::jsonb,?::jsonb)", [$before, json_encode($after)]);
            $denied(fn () => $db->update("UPDATE business_context_heads SET display_name='Different image' WHERE business_id=?", [$id]));
            $this->assertSame('Exact qualified image', $db->selectOne('UPDATE business_context_heads SET display_name=? WHERE business_id=? RETURNING display_name', [$after['display_name'], $id])->display_name);
            $denied(fn () => $db->update('UPDATE business_context_heads SET display_name=? WHERE business_id=?', [$after['display_name'], $id]));
            $db->scalar('SELECT builderad_private.finish_command()');
        } finally {
            $db->rollBack();
        }
        $this->assertSame('Permit truth', $db->table('business_context_heads')->where('business_id', $id)->value('display_name'));
    }

    public function test_every_genesis_effect_failure_rolls_back_identity_control_grants_and_receipt_and_same_command_can_then_succeed(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $this->withoutExceptionHandling();
        $db = DB::connection('pgsql_migrations');
        $tables = ['businesses', 'business_context_heads', 'business_current_intents', 'business_authority_fences', 'migration_cohort_control', 'migration_cohort_members', 'authority_qualification_receipts', 'security_action_grants', 'business_command_receipts'];
        $db->unprepared("CREATE FUNCTION public.builderad_test_genesis_failure() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'Synthetic genesis interruption' USING ERRCODE='P0001'; END \$\$");
        try {
            foreach ($tables as $table) {
                $body = ['command_id' => (string) Str::uuid(), 'name' => 'Atomic genesis'];
                $before = array_map(fn ($t) => $db->table($t)->count(), array_combine($tables, $tables));
                $db->statement("CREATE TRIGGER zz_test_genesis_failure BEFORE INSERT ON public.{$table} FOR EACH ROW EXECUTE FUNCTION public.builderad_test_genesis_failure()");
                try {
                    try {
                        $this->postJson('/api/businesses', $body);
                        $this->fail('Interrupted genesis was accepted.');
                    } catch (QueryException $error) {
                        $this->assertSame('P0001', $error->getCode());
                    }
                } finally {
                    $db->statement("DROP TRIGGER zz_test_genesis_failure ON public.{$table}");
                }
                $this->assertSame($before, array_map(fn ($t) => $db->table($t)->count(), array_combine($tables, $tables)), $table);
                $this->assertSame(0, $db->table('business_goal_preferences')->count());
                $result = $this->postJson('/api/businesses', $body)->assertCreated()->json('data');
                $this->assertSame($result, $this->postJson('/api/businesses', $body)->assertCreated()->json('data'));
            }
        } finally {
            $db->statement('DROP FUNCTION public.builderad_test_genesis_failure()');
        }
    }
}
