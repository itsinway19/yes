<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DisposableDatabaseReset;
use Tests\TestCase;

final class BusinessActivityUpgradeTest extends TestCase
{
    public function test_forward_only_recovery_refuses_down_and_preserves_history_and_catalog(): void
    {
        $this->actingAs(User::factory()->create());
        $business = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Forward recovery client'])->assertCreated()->json('data');
        $selection = fn ($grant) => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
            'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $grant]]];
        $body = ['command_id' => (string) Str::uuid(), 'practice_id' => (string) Str::uuid7(), 'operation' => 'create', 'expected_revision' => 0,
            'value' => ['name' => 'بيع الأجهزة', 'description' => '', 'kind' => 'sale', 'sectors' => [], 'specializations' => [], 'offering' => null, 'commercial_model' => null, 'channels' => [],
                'target_markets' => ['status' => 'unspecified', 'areas' => []], 'fulfillment' => ['status' => 'unspecified', 'areas' => []]],
            'authority_selection' => $selection($business['grant_refs']['business.context.manage']), 'read_selection' => $selection($business['grant_refs']['business.context.read'])];
        $this->postJson('/api/businesses/'.$business['business_public_id'].'/practices', $body)->assertOk();
        $owner = DB::connection('pgsql_migrations');
        $snapshot = $owner->scalar('SELECT jsonb_agg(to_jsonb(r) ORDER BY revision)::text FROM business_practice_revisions r');
        $migration = require database_path('migrations/0001_01_01_000720_business_activity_foundation.php');
        $default = DB::getDefaultConnection();
        try {
            DB::setDefaultConnection('pgsql_migrations');
            try {
                $migration->down();
                $this->fail('History-bearing installation must require forward recovery.');
            } catch (QueryException $error) {
                $this->assertSame('P0001', $error->errorInfo[0]);
                $this->assertStringContainsString('forward recovery', $error->getMessage());
            }
        } finally {
            DB::setDefaultConnection($default);
        }
        $this->assertSame($snapshot, $owner->scalar('SELECT jsonb_agg(to_jsonb(r) ORDER BY revision)::text FROM business_practice_revisions r'));
        $this->assertNotNull($owner->scalar("SELECT to_regclass('public.business_practice_assessments')"));
        app(DisposableDatabaseReset::class)->assertInstalledCatalog();
    }

    public function test_populated710_upgrade_preserves_legacy_business_history_rights_and_finding_contracts(): void
    {
        $this->usePersistentTestSessions();
        $reset = app(DisposableDatabaseReset::class);
        $reset->clear();
        try {
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => strcmp(basename($path), '0001_01_01_000720_business_activity_foundation.php') < 0));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--path' => $paths, '--realpath' => true, '--force' => true]));
            $this->actingAs(User::factory()->create());
            $command = ['command_id' => (string) Str::uuid(), 'name' => 'نشاط قائم قبل الترقية'];
            $business = $this->postJson('/api/businesses', $command)->assertCreated()->json('data');
            $owner = DB::connection('pgsql_migrations');
            $tables = ['businesses', 'business_context_heads', 'business_branches', 'object_purposes', 'security_action_grants', 'authority_qualification_receipts', 'business_command_receipts', 'data_use_scopes', 'business_data_use_bindings'];
            $snapshots = [];
            foreach ($tables as $table) {
                $snapshots[$table] = $owner->scalar("SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]')::text FROM {$table} r");
            }
            $oldFields = $owner->scalar("SELECT pg_get_functiondef('builderad_private.registered_producer_fields(jsonb,jsonb)'::regprocedure)");
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            foreach ($snapshots as $table => $value) {
                $this->assertSame($value, $owner->scalar("SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]')::text FROM {$table} r"), $table);
            }
            $this->assertSame(str_replace('registered_producer_fields(', 'registered_producer_fields_before_activity(', $oldFields), $owner->scalar("SELECT pg_get_functiondef('builderad_private.registered_producer_fields_before_activity(jsonb,jsonb)'::regprocedure)"));
            $this->postJson('/api/businesses', $command)->assertCreated()->assertJsonPath('data', $business);
            $this->getJson('/api/businesses/'.$business['business_public_id'].'/practices?access='.$business['grant_refs']['business.context.read'])->assertOk()->assertJsonPath('data.practices', []);
            foreach (['builderad_app', 'builderad_authority'] as $role) {
                foreach (['business_practice_revisions', 'business_practice_assessments'] as $table) {
                    $this->assertFalse((bool) $owner->scalar("SELECT has_table_privilege(?,?,'SELECT,INSERT,UPDATE,DELETE')", [$role, $table]));
                }
                $this->assertFalse((bool) $owner->scalar("SELECT has_function_privilege(?,'builderad_private.assess_practice(jsonb,jsonb,jsonb,jsonb,jsonb)','EXECUTE')", [$role]));
            }
            $this->assertFalse((bool) $owner->scalar("SELECT has_schema_privilege('builderad_scope_executor','public','CREATE')"));
            $this->assertFalse((bool) $owner->scalar("SELECT has_table_privilege('builderad_scope_executor','businesses','REFERENCES')"));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
        } finally {
            DB::disconnect('pgsql');
            $reset->install();
        }
    }
}
