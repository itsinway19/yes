<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\Ads\Provider;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BusinessNativeMaterialFixture;
use Tests\Support\DisposableDatabaseReset;
use Tests\TestCase;

final class DataLicenseJourneyTest extends TestCase
{
    use BusinessNativeMaterialFixture;

    public static function providers(): array
    {
        return array_map(fn ($provider) => [$provider], Provider::cases());
    }

    private function mandate(array $f): array
    {
        return $this->postJson('/api/businesses/'.$f['business']['business_public_id'].'/operating-mandate', [
            'command_id' => (string) Str::uuid(), 'confirmed' => true, 'capacity' => 'responsible_business',
            'authority_reference' => 'Explicit synthetic Business responsibility', 'valid_until' => now()->addHour()->toIso8601String(), 'previous_grant' => '0',
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => (int) $f['business']['grant_refs']['business.context.read']],
            ]],
        ])->assertOk()->json('data');
    }

    public function test_beneficiary_assignment_is_separate_bounded_and_never_grants_source_access_or_upgrades_original_licence(): void
    {
        $f = $this->nativeMaterial(licensed: false);
        $mandate = $this->mandate($f);
        $recipient = User::factory()->create();
        $base = '/api/businesses/'.$f['business']['business_public_id'];
        $context = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
            ['requirement_kind' => 'business', 'leaf_grant_id' => (int) $f['business']['grant_refs']['business.context.read']],
        ]];
        $team = $this->postJson($base.'/direct-team', ['command_id' => (string) Str::uuid(), 'authority_selection' => $context])->assertOk()->json('data.grant_refs');
        $readRoot = (int) $team['business.context.read'];
        $context['requirements'][0]['leaf_grant_id'] = $readRoot;
        $readGrant = $this->postJson($base.'/grants', ['command_id' => (string) Str::uuid(), 'effect' => 'issue',
            'grantee_public_id' => $recipient->public_id, 'action' => 'business.context.read', 'parent_grant_id' => $readRoot,
            'grant_id' => null, 'expected_grant' => 0, 'expires_at' => null, 'delegable' => false,
            'realm_workspace_public_id' => null, 'authority_selection' => $context])->assertOk()->json('data.grant_id');
        $businessSelection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
            ['requirement_kind' => 'business', 'leaf_grant_id' => (int) $mandate['grant_refs']['business.data.use']],
        ]];
        $this->postJson($base.'/grants', ['command_id' => (string) Str::uuid(), 'effect' => 'issue', 'grantee_public_id' => $recipient->public_id,
            'action' => 'business.data.use', 'parent_grant_id' => (int) $mandate['grant_refs']['business.data.use'], 'grant_id' => null,
            'expected_grant' => 0, 'expires_at' => now()->addMinutes(30)->toIso8601String(), 'delegable' => false,
            'realm_workspace_public_id' => null, 'authority_selection' => $businessSelection])->assertOk();
        $journey = $this->getJson($base.'/data-use-journey?access='.$f['business']['grant_refs']['business.context.read'])->assertOk()->json('data');
        $dataset = $journey['sources'][0]['datasets'][0];
        $license = ['command_id' => (string) Str::uuid(), 'license_id' => (string) Str::uuid7(), 'effect' => 'issue', 'expected_revision' => 0,
            'declaration' => ['source_id' => $f['source'], 'resource' => '123', 'operation' => 'retained_read', 'purpose' => 'advertising_performance',
                'selection' => $dataset['selection'], 'recipient_public_id' => $f['user']->public_id, 'valid_until' => now()->addHour()->toIso8601String(),
                'capacity' => 'data_holder', 'holder_name' => 'Synthetic holder', 'authority_reference' => 'Explicit synthetic licence authority', 'confirmed' => true, 'requires_review' => false],
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => (int) $f['business']['grant_refs']['business.context.read']],
                ['requirement_kind' => 'native_source', 'leaf_grant_id' => (int) $f['custody']['grant_refs']['source.manage']],
            ]]];
        $issued = $this->postJson($base.'/data-use-licenses', $license)->assertOk()->json('data');
        $owner = DB::connection('pgsql_migrations');
        $original = $owner->table('security_action_grants')->where('id', $issued['use_grant_id'])->sole();
        $selection = $businessSelection;
        $selection['requirements'][] = ['requirement_kind' => 'client_data', 'leaf_grant_id' => (int) $issued['boundary_grant_id']];
        $body = ['command_id' => (string) Str::uuid(), 'license_id' => $issued['license_id'], 'effect' => 'assign',
            'recipient_public_id' => $recipient->public_id, 'valid_until' => now()->addMinutes(15)->toIso8601String(), 'expected_revision' => 0, 'authority_selection' => $selection];
        $assigned = $this->postJson($base.'/data-use-beneficiaries', $body)->assertOk()->assertJsonPath('data.status', 'active')->json('data');
        $this->assertFalse($original->delegable);
        $this->assertEquals($original, $owner->table('security_action_grants')->where('id', $issued['use_grant_id'])->sole());
        $this->assertSame(0, $owner->table('security_action_grants')->where('subject_user_id', $recipient->id)->where('scope_kind', 'source')->count());
        $this->actingAs($recipient)->getJson($base.'/data-use-journey?access='.$readGrant)->assertOk()
            ->assertJsonCount(0, 'data.sources')->assertJsonCount(0, 'data.licenses')
            ->assertJsonPath('data.assigned_licenses.0.license_id', $issued['license_id'])
            ->assertJsonMissingPath('data.assigned_licenses.0.source_id')
            ->assertJsonMissingPath('data.assigned_licenses.0.selection');
        $this->actingAs($f['user']);
        $this->assertSame($assigned, $this->postJson($base.'/data-use-beneficiaries', $body)->assertOk()->json('data'));
        $revoke = [...$body, 'command_id' => (string) Str::uuid(), 'effect' => 'revoke', 'expected_revision' => 1];
        $this->postJson($base.'/data-use-beneficiaries', $revoke)->assertOk()->assertJsonPath('data.status', 'revoked')->assertJsonPath('data.versions.grant', 2);
        $body['command_id'] = (string) Str::uuid();
        $this->postJson($base.'/data-use-beneficiaries', $body)->assertConflict();
        $body['expected_revision'] = 2;
        $renewed = $this->postJson($base.'/data-use-beneficiaries', $body)->assertOk()->assertJsonPath('data.versions.grant', 3)->json('data');
        $this->assertNotSame($assigned['grant_id'], $renewed['grant_id']);
        $this->assertSame('revoked', $owner->table('security_action_grants')->where('id', $assigned['grant_id'])->value('status'));
        $this->postJson($base.'/data-use-licenses', [...$license, 'command_id' => (string) Str::uuid(), 'effect' => 'withdraw', 'expected_revision' => 1, 'declaration' => (object) []])->assertOk();
        $this->assertSame('revoked', $owner->table('security_action_grants')->where('id', $renewed['grant_id'])->value('status'));
    }

    public function test_business_journey_exposes_only_current_qualified_source_and_bounded_native_report_selection(): void
    {
        $f = $this->nativeMaterial(licensed: false);
        $url = '/api/businesses/'.$f['business']['business_public_id'].'/data-use-journey?access='.$f['business']['grant_refs']['business.context.read'];
        $response = $this->getJson($url)->assertOk()->assertJsonPath('data.business_public_id', $f['business']['business_public_id'])
            ->assertJsonPath('data.actor_public_id', $f['user']->public_id)->assertJsonCount(1, 'data.sources')->assertJsonCount(0, 'data.licenses')
            ->assertJsonCount(0, 'data.business_use_options')->assertJsonPath('data.sources.0.source_id', $f['source'])
            ->assertJsonPath('data.sources.0.datasets.0.selection.field_keys', ['spend'])->assertJsonPath('data.sources.0.datasets.0.coverage', 'PARTIAL');
        $this->assertStringNotContainsString('DO_NOT_DISCLOSE', $response->getContent());
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('data_use_scopes')->count());
        $this->actingAs(User::factory()->create());
        $this->getJson($url)->assertForbidden();
    }

    public function test_explicit_operating_mandate_issues_only_two_rights_and_preserves_original_non_delegable_grants(): void
    {
        $f = $this->nativeMaterial(licensed: false);
        $owner = DB::connection('pgsql_migrations');
        $before = $owner->table('security_action_grants')->orderBy('id')->get()->toArray();
        $body = ['command_id' => (string) Str::uuid(), 'confirmed' => true, 'capacity' => 'responsible_business',
            'authority_reference' => 'Explicit responsibility for this synthetic Business', 'valid_until' => now()->addHour()->toIso8601String(), 'previous_grant' => '0',
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => (int) $f['business']['grant_refs']['business.context.read']],
            ]]];
        $path = '/api/businesses/'.$f['business']['business_public_id'].'/operating-mandate';
        $invalid = [...$body, 'confirmed' => false];
        $this->postJson($path, $invalid)->assertUnprocessable();
        $receipt = $this->postJson($path, $body)->assertOk()->assertJsonPath('data.assurance', 'DECLARED_AUTHORITY')->json('data');
        $this->assertSame(['business.data.use', 'business.link.manage'], array_keys($receipt['grant_refs']));
        $this->assertSame(count($before) + 2, $owner->table('security_action_grants')->count());
        $this->assertEquals($before, $owner->table('security_action_grants')->where('id', '<=', end($before)->id)->orderBy('id')->get()->toArray());
        $this->assertSame($receipt, $this->postJson($path, $body)->assertOk()->json('data'));
        $this->assertSame(0, $owner->table('workspaces')->count());
        $body['command_id'] = (string) Str::uuid();
        $this->postJson($path, $body)->assertConflict();
        $this->actingAs(User::factory()->create());
        $this->postJson($path, $body)->assertForbidden();
    }

    public function test_surrender_of_original_read_basis_disables_operating_mandate_for_delegation(): void
    {
        $f = $this->nativeMaterial(licensed: false);
        $mandate = $this->mandate($f);
        $recipient = User::factory()->create();
        $base = '/api/businesses/'.$f['business']['business_public_id'].'/grants';
        $access = (int) $f['business']['grant_refs']['business.context.read'];
        $use = (int) $mandate['grant_refs']['business.data.use'];
        $selection = static fn (int $grant) => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
            'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $grant]]];
        $issue = ['command_id' => (string) Str::uuid(), 'effect' => 'issue', 'grantee_public_id' => $recipient->public_id,
            'action' => 'business.data.use', 'parent_grant_id' => $use, 'grant_id' => null, 'expected_grant' => 0,
            'expires_at' => now()->addMinutes(15)->toIso8601String(), 'delegable' => false,
            'realm_workspace_public_id' => null, 'authority_selection' => $selection($use)];
        $this->postJson($base, $issue)->assertOk();
        $this->postJson($base, ['command_id' => (string) Str::uuid(), 'effect' => 'revoke', 'grantee_public_id' => $f['user']->public_id,
            'action' => 'business.context.read', 'parent_grant_id' => null, 'grant_id' => $access, 'expected_grant' => 1,
            'expires_at' => null, 'delegable' => false, 'realm_workspace_public_id' => null,
            'authority_selection' => $selection($access)])->assertOk();
        $this->postJson($base, [...$issue, 'command_id' => (string) Str::uuid()])->assertForbidden();
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('security_action_grants')->where('parent_grant_id', $use)->count());
    }

    public function test690_upgrades_populated680_and_rejects_unsealed_input_without_rewriting_history(): void
    {
        $this->usePersistentTestSessions();
        $reset = app(DisposableDatabaseReset::class);
        $reset->clear();
        try {
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')),
                fn ($file) => strcmp(basename($file), '0001_01_01_000690_preserve_agency_relationship_incarnation.php') < 0));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--path' => $paths, '--realpath' => true, '--force' => true]));
            $fixture = $this->nativeMaterial();
            $this->mandate($fixture);
            $owner = DB::connection('pgsql_migrations');
            $before = [];
            foreach (['businesses', 'workspace_memberships', 'source_registry', 'provider_observation_entries', 'security_action_grants', 'authority_qualification_receipts',
                'client_data_boundaries', 'data_use_scopes', 'business_command_receipts'] as $table) {
                $before[$table] = $owner->scalar("SELECT jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text)::text FROM {$table} r");
            }
            $signature = 'builderad_private.verify_scope_path(text,text,bigint,text,jsonb,text,bigint[],bigint[],boolean)';
            $definition = $owner->scalar('SELECT pg_get_functiondef(?::regprocedure)', [$signature]);
            $anchor = 'AND(relationship.revision=1 OR q.valid_from>=relationship.valid_from)';
            $this->assertSame(1, substr_count($definition, $anchor));
            $owner->statement('GRANT CREATE ON SCHEMA builderad_private TO builderad_scope_executor');
            $owner->transaction(function () use ($owner, $definition, $anchor): void {
                $owner->statement('SET LOCAL ROLE builderad_scope_executor');
                $owner->unprepared(str_replace($anchor, '', $definition));
            });
            $owner->statement('REVOKE CREATE ON SCHEMA builderad_private FROM builderad_scope_executor');
            try {
                Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]);
                $this->fail('Unsealed agency verifier was accepted.');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->getCode());
                $this->assertStringContainsString('AGENCY_INCARNATION_BASELINE_REQUIRED', $error->getMessage());
            }
            $this->assertFalse($owner->table('migrations')->where('migration', '0001_01_01_000690_preserve_agency_relationship_incarnation')->exists());
            // A disposable historical fixture explicitly seals the demonstrated680 regression.
            $owner->unprepared(file_get_contents(database_path('sql/market_evidence/catalog_seal.sql')));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            $this->assertSame($definition, $owner->scalar('SELECT pg_get_functiondef(?::regprocedure)', [$signature]));
            foreach (['workspace_memberships', 'security_action_grants'] as $table) {
                $this->assertSame('timestamp(6) with time zone', $owner->scalar("SELECT format_type(atttypid,atttypmod) FROM pg_attribute WHERE attrelid=?::regclass AND attname='created_at'", [$table]));
            }
            foreach ($before as $table => $rows) {
                $this->assertSame($rows, $owner->scalar("SELECT jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text)::text FROM {$table} r"), $table);
            }
            $this->assertFalse((bool) $owner->scalar("SELECT has_schema_privilege('builderad_scope_executor','builderad_private','CREATE')"));
            foreach (['builderad_app', 'builderad_authority', 'public'] as $role) {
                $this->assertFalse((bool) $owner->scalar("SELECT has_function_privilege(?,?,'EXECUTE')", [$role, $signature]));
            }
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
        } finally {
            DB::disconnect('pgsql');
            $reset->install();
        }
    }

    #[DataProvider('providers')]
    public function test_source_to_operating_mandate_licence_actual_read_and_withdrawal_needs_no_synthetic_authority_roots(Provider $provider): void
    {
        $f = $this->nativeMaterial($provider, licensed: false);
        $base = '/api/businesses/'.$f['business']['business_public_id'];
        $access = (int) $f['business']['grant_refs']['business.context.read'];
        $journey = $this->getJson($base.'/data-use-journey?access='.$access)->assertOk()->json('data');
        $source = $journey['sources'][0];
        $dataset = $source['datasets'][0];
        $this->assertFalse(DB::connection('pgsql_migrations')->table('authority_qualification_receipts')->where('policy_id', 'like', 'test.synthetic%')->exists());
        $mandate = $this->mandate($f);
        $licenseBody = ['command_id' => (string) Str::uuid(), 'license_id' => (string) Str::uuid7(), 'effect' => 'issue', 'expected_revision' => 0,
            'declaration' => ['source_id' => $source['source_id'], 'resource' => $source['resource'], 'operation' => 'retained_read', 'purpose' => 'advertising_performance',
                'selection' => $dataset['selection'], 'recipient_public_id' => $f['user']->public_id, 'valid_until' => now()->addHour()->toIso8601String(),
                'capacity' => 'data_holder', 'holder_name' => 'Synthetic responsible business', 'authority_reference' => 'Explicit synthetic data authority statement', 'confirmed' => true, 'requires_review' => false],
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => $access], ['requirement_kind' => 'native_source', 'leaf_grant_id' => (int) $source['manager_grant_id']],
            ]]];
        $issued = $this->postJson($base.'/data-use-licenses', $licenseBody)->assertOk()->json('data');
        $selection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
            ['requirement_kind' => 'business', 'leaf_grant_id' => (int) $mandate['grant_refs']['business.link.manage']],
            ['requirement_kind' => 'client_data', 'leaf_grant_id' => (int) $issued['boundary_grant_id']],
        ]];
        $this->postJson('/api/data-rights/commands', ['command_id' => (string) Str::uuid(), 'target_kind' => 'boundary_subject', 'target_ref' => $issued['boundary_id'],
            'effect' => 'attach_subject', 'payload' => ['business_public_id' => $f['business']['business_public_id'], 'branch_id' => null], 'expected_revision' => 0, 'authority_selection' => $selection])->assertOk();
        $selection['requirements'][1] = ['requirement_kind' => 'data_use', 'leaf_grant_id' => (int) $issued['use_grant_id']];
        $this->postJson($base.'/data-use-bindings', ['command_id' => (string) Str::uuid(), 'data_use_scope_id' => $issued['license_id'], 'effect' => 'add', 'expected_relation' => 0, 'authority_selection' => $selection])->assertOk();
        $read = $f['body'];
        $read['purpose'] = 'advertising_performance';
        $read['material_refs'] = $dataset['material_refs'];
        $selection['requirements'][0]['leaf_grant_id'] = (int) $mandate['grant_refs']['business.data.use'];
        $selection['requirements'][] = ['requirement_kind' => 'native_source', 'leaf_grant_id' => (int) $source['read_grant_id']];
        $read['authority_selection'] = $selection;
        $first = $this->postJson($base.'/materials/read', $read)->assertOk()->assertJsonPath('data.materials.0.fields.spend.native_value', '12')->json();
        $this->assertSame($first, $this->postJson($base.'/materials/read', $read)->assertOk()->json());
        $this->assertStringNotContainsString('DO_NOT_DISCLOSE', json_encode($first));
        $journey = $this->getJson($base.'/data-use-journey?access='.$access)->assertOk()->json('data');
        $this->assertTrue($journey['licenses'][0]['covered']);
        $this->assertTrue($journey['licenses'][0]['bound']);
        $this->postJson($base.'/data-use-licenses', [...$licenseBody, 'command_id' => (string) Str::uuid(), 'effect' => 'withdraw', 'expected_revision' => 1, 'declaration' => (object) []])->assertOk();
        $this->postJson($base.'/materials/read', $read)->assertForbidden();
        $this->getJson($base.'/data-use-journey?access='.$access)->assertOk()->assertJsonPath('data.licenses.0.status', 'withdrawn');
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('workspaces')->count());
    }
}
