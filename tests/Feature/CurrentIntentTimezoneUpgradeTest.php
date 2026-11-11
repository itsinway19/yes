<?php

namespace Tests\Feature;

use App\Business\BusinessContext;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DisposableDatabaseReset;
use Tests\TestCase;

final class CurrentIntentTimezoneUpgradeTest extends TestCase
{
    public function test_populated730_upgrade_preserves_declarations_and_native_validation_with_a_sealed_read_only_reference(): void
    {
        $this->usePersistentTestSessions();
        $reset = app(DisposableDatabaseReset::class);
        $reset->clear();
        try {
            $migrationName = '0001_01_01_000740_install_current_intent_timezone_catalog.php';
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')),
                fn ($path) => strcmp(basename($path), $migrationName) < 0));
            $this->assertSame(0, Artisan::call('migrate', [
                '--database' => 'pgsql_migrations', '--path' => $paths, '--realpath' => true, '--force' => true,
            ]));
            $actor = User::factory()->create();
            $this->actingAs($actor);
            $business = $this->postJson('/api/businesses', [
                'command_id' => (string) Str::uuid(), 'name' => 'Existing timezone-qualified Business',
            ])->assertCreated()->json('data');
            $owner = DB::connection('pgsql_migrations');
            $owner->table('registration_email_proofs')->insert([
                'attempt_id' => (string) Str::uuid7(), 'user_id' => $actor->id, 'expires_at' => now()->addDay(),
            ]);
            $intent = ['direction' => 'increase_value', 'scope' => 'business_wide', 'description' => null,
                'outcome' => ['meaning' => 'Qualified customer purchases', 'clarification' => null, 'count_unit' => null],
                'quantity' => null, 'horizon' => ['deadline_date' => '2026-11-10', 'timezone' => 'Asia/Amman']];
            $this->putJson('/api/businesses/'.$business['business_public_id'].'/intent', [
                'command_id' => (string) Str::uuid(), 'value' => $intent, 'expected_context' => 0, 'expected_intent' => 0,
                'confirmations' => ['declaration' => true, 'business_wide' => true, 'outcome' => true, 'acceptance_clear' => true,
                    'clarification_required' => false, 'quantity' => true, 'horizon' => true],
                'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
                    'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $business['grant_refs']['business.intent.declare']]]],
            ])->assertOk();
            $tables = ['users', 'principal_lifecycles', 'security_sessions', 'browser_identity_transitions',
                'registration_email_proofs', 'businesses', 'business_context_heads', 'business_current_intents',
                'business_intent_transitions', 'business_command_receipts', 'security_action_grants', 'authority_qualification_receipts'];
            $snapshot = static function () use ($owner, $tables): array {
                $rows = [];
                foreach ($tables as $table) {
                    $rows[$table] = $owner->scalar("SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]')::text FROM {$table} r");
                }

                return $rows;
            };
            $before = $snapshot();
            $functions = ['builderad_current_intent_timezone_name_allowed(text)',
                'builderad_current_intent_timezone_supported(text)', 'builderad_current_intent_validate_horizon(jsonb)',
                'builderad_v2_business_surface(text,uuid,bigint)'];
            $definitions = [];
            foreach ($functions as $function) {
                $definitions[$function] = $owner->scalar('SELECT pg_get_functiondef(?::regprocedure)', [$function]);
            }
            $reset->assertInstalledCatalog();
            $upgrade = ['--database' => 'pgsql_migrations', '--path' => [database_path('migrations/'.$migrationName)],
                '--realpath' => true, '--force' => true];
            $allowedFunction = $definitions['builderad_current_intent_timezone_name_allowed(text)'];
            $owner->unprepared('CREATE OR REPLACE FUNCTION builderad_current_intent_timezone_name_allowed(p_zone text) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$ SELECT false $$');
            try {
                Artisan::call('migrate', $upgrade);
                $this->fail('An unsealed730 catalog cannot authorize timezone reference installation.');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->errorInfo[0]);
                $this->assertStringContainsString('TIMEZONE_REFERENCE_BASELINE_REQUIRED', $error->getMessage());
            } finally {
                $owner->unprepared($allowedFunction);
            }
            $this->assertSame(0, Artisan::call('migrate', $upgrade));
            $this->assertSame($before, $snapshot());
            foreach ($definitions as $function => $definition) {
                $this->assertSame($definition, $owner->scalar('SELECT pg_get_functiondef(?::regprocedure)', [$function]), $function);
            }
            $reset->assertInstalledCatalog();
            $reference = $owner->table('current_intent_timezone_catalog')->sole();
            $this->assertSame((int) $owner->scalar("SELECT current_setting('server_version_num')"), $reference->postgres_version);
            $native = $owner->scalar('SELECT jsonb_agg(name ORDER BY name COLLATE "C")::text FROM (SELECT DISTINCT name FROM pg_catalog.pg_timezone_names) names');
            $this->assertSame(json_decode($native, true), json_decode($reference->names, true));
            foreach (['builderad_app', 'builderad_authority', 'builderad_scope_executor'] as $role) {
                foreach (['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
                    $this->assertFalse((bool) $owner->scalar('SELECT has_table_privilege(?,?,?)',
                        [$role, 'current_intent_timezone_catalog', $privilege]), $role.' '.$privilege);
                }
            }
            $this->assertTrue((bool) $owner->scalar("SELECT has_table_privilege('builderad_app','current_intent_timezone_catalog','SELECT')"));
            try {
                DB::table('current_intent_timezone_catalog')->where('id', 1)->update(['postgres_version' => 0]);
                $this->fail('Runtime must not refresh the environment reference.');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->errorInfo[0]);
            }
            $this->assertSame(0, Artisan::call('migrate', $upgrade));
            $this->assertEquals($reference, $owner->table('current_intent_timezone_catalog')->sole());
            $this->assertSame($before, $snapshot());
            foreach (['pgsql', 'pgsql_authority'] as $connection) {
                try {
                    DB::connection($connection)->transaction(fn () => DB::connection($connection)->unprepared(
                        file_get_contents(database_path('migration-sql/0001_01_01_000740_install_current_intent_timezone_catalog/refresh.sql')),
                    ));
                    $this->fail('A runtime role cannot execute the deployment refresh.');
                } catch (QueryException $error) {
                    $this->assertSame('42501', $error->errorInfo[0]);
                }
            }
            $owner->unprepared('CREATE OR REPLACE FUNCTION builderad_current_intent_timezone_name_allowed(p_zone text) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$ SELECT false $$');
            try {
                $owner->transaction(fn () => $owner->unprepared(file_get_contents(database_path(
                    'migration-sql/0001_01_01_000740_install_current_intent_timezone_catalog/refresh.sql',
                ))));
                $this->fail('An unsealed environment must not refresh its reference.');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->errorInfo[0]);
                $this->assertStringContainsString('TIMEZONE_REFERENCE_SEALED_INSTALLATION_REQUIRED', $error->getMessage());
            } finally {
                $owner->unprepared($allowedFunction);
            }
            $this->assertEquals($reference, $owner->table('current_intent_timezone_catalog')->sole());
            $default = DB::getDefaultConnection();
            try {
                DB::setDefaultConnection('pgsql_migrations');
                $migration = require database_path('migrations/'.$migrationName);
                try {
                    $migration->down();
                    $this->fail('Installed references must require forward recovery.');
                } catch (QueryException $error) {
                    $this->assertSame('P0001', $error->errorInfo[0]);
                    $this->assertStringContainsString('forward recovery', $error->getMessage());
                }
            } finally {
                DB::setDefaultConnection($default);
            }
            $this->assertSame($before, $snapshot());
            $this->assertContains('Asia/Amman', app(BusinessContext::class)->currentIntentTimezones());
            $this->assertTrue((bool) DB::scalar("SELECT builderad_current_intent_timezone_supported('Asia/Amman')"));
            $this->assertFalse((bool) DB::scalar("SELECT builderad_current_intent_timezone_supported('Some/Unsupported')"));
            $reset->assertInstalledCatalog();
        } finally {
            DB::disconnect('pgsql');
            $reset->install();
        }
    }
}
