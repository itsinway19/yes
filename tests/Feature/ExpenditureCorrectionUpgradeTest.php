<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BusinessNativeMaterialFixture;
use Tests\Support\DisposableDatabaseReset;
use Tests\TestCase;

final class ExpenditureCorrectionUpgradeTest extends TestCase
{
    use BusinessNativeMaterialFixture;

    public function test710_preserves_populated700_output_receipts_and_history_and_requires_sealed_input(): void
    {
        $this->usePersistentTestSessions();
        $reset = app(DisposableDatabaseReset::class);
        $reset->clear();
        try {
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => strcmp(basename($path), '0001_01_01_000710_correct_expenditure_preparation_and_selection.php') < 0));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--path' => $paths, '--realpath' => true, '--force' => true]));
            $owner = DB::connection('pgsql_migrations');
            $replace = function (string $sql) use ($owner): void {
                $owner->statement('GRANT CREATE ON SCHEMA public,builderad_private TO builderad_scope_executor');
                $owner->transaction(function () use ($owner, $sql): void {
                    $owner->statement('SET LOCAL ROLE builderad_scope_executor');
                    $owner->unprepared($sql);
                });
                $owner->statement('REVOKE CREATE ON SCHEMA public,builderad_private FROM builderad_scope_executor');
            };
            $replace(file_get_contents(base_path('tests/Fixtures/g01-c03-preparation.sql')));
            // Explicit disposable historical700 installation, including its old
            // lock behavior. This does not bless an unsealed installed catalog.
            $owner->unprepared(file_get_contents(database_path('sql/market_evidence/catalog_seal.sql')));
            $f = $this->nativeMaterial(operation: 'process');
            $base = '/api/businesses/'.$f['business']['business_public_id'];
            $this->postJson($base.'/materials/read', $f['body'])->assertOk();
            $selection = $f['body']['authority_selection'];
            $selection['requirements'][] = ['requirement_kind' => 'business', 'leaf_grant_id' => $f['manager']->id];
            $selection['requirements'][] = ['requirement_kind' => 'client_data', 'leaf_grant_id' => $f['boundary']['grant_id']];
            $body = ['command_id' => (string) Str::uuid(), 'summary_id' => (string) Str::uuid7(), 'input_ref' => $f['ref'],
                'input_license_id' => $f['useScope'], 'read_command_id' => $f['body']['command_id'], 'confirmed' => true, 'valid_until' => now()->addMinutes(10)->toIso8601String(),
                'consent' => ['derive' => true, 'deliver_summary' => true, 'retain_payload' => false], 'authority_selection' => $selection];
            $prepared = $this->postJson($base.'/expenditure-summaries', $body)->assertOk()->json('data');
            $tables = ['businesses', 'source_registry', 'provider_observation_entries', 'security_action_grants', 'authority_qualification_receipts',
                'client_data_boundaries', 'data_use_scopes', 'business_data_use_bindings', 'business_expenditure_summaries', 'business_command_receipts', 'material_lineage_manifests', 'resource_right_fences', 'business_authority_fences'];
            $before = [];
            foreach ($tables as $table) {
                $before[$table] = $owner->scalar("SELECT jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text)::text FROM {$table} r");
            }
            $signature = 'builderad_private.lock_material_resources(jsonb,jsonb)';
            $definition = $owner->scalar('SELECT pg_get_functiondef(?::regprocedure)', [$signature]);
            $replace(str_replace('SOURCE_ADOPTION_REQUIRED', 'UNSEALED_SYNTHETIC_LOCKS', $definition));
            try {
                Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]);
                $this->fail('Unsealed700 input was accepted.');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->getCode());
                $this->assertStringContainsString('EXPENDITURE_CORRECTION_BASELINE_REQUIRED', $error->getMessage());
            }
            $replace($definition);
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            foreach ($before as $table => $rows) {
                $this->assertSame($rows, $owner->scalar("SELECT jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text)::text FROM {$table} r"), $table);
            }
            $final = $owner->scalar('SELECT pg_get_functiondef(?::regprocedure)', [$signature]);
            $this->assertStringContainsString('write_boundary_id', $final);
            $this->assertStringContainsString('market_business_finding_captures', $final);
            foreach (['public', 'builderad_app', 'builderad_authority'] as $role) {
                $this->assertFalse((bool) $owner->scalar('SELECT has_function_privilege(?, ?, \'EXECUTE\')', [$role, $signature]));
                $this->assertFalse((bool) $owner->scalar("SELECT has_table_privilege(?,'business_expenditure_summaries','SELECT')", [$role]));
            }
            $this->assertFalse((bool) $owner->scalar("SELECT has_schema_privilege('builderad_scope_executor','builderad_private','CREATE')"));
            $this->assertSame($prepared, $this->postJson($base.'/expenditure-summaries', $body)->assertOk()->json('data'));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
        } finally {
            DB::disconnect('pgsql');
            $reset->install();
        }
    }
}
