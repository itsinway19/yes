<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\BusinessAuthorityFixture as Fixture;
use Tests\Support\DisposableDatabaseReset;
use Tests\Support\MarketEvidenceFixture;
use Tests\TestCase;

final class MarketEvidenceOriginTest extends TestCase
{
    use ApplicationContractAssertions;
    use MarketEvidenceFixture;

    private function consumer(string $source, string $evidence, array $fields = ['price'], string $operation = 'retained_read'): array
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $business = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Synthetic independent consumer'])->assertCreated()->json('data');
        $id = DB::connection('pgsql_migrations')->table('businesses')->where('public_id', $business['business_public_id'])->value('id');
        $read = Fixture::root($id, $user->id, 'business.data.use');
        $link = Fixture::root($id, $user->id, 'business.link.manage');
        $right = $this->marketRight($user, $source, $evidence, $operation, $fields);

        return compact('user', 'business', 'read', 'link', 'right');
    }

    private function binding(array $consumer, string $effect, int $expected)
    {
        $requirements = [['requirement_kind' => 'business', 'leaf_grant_id' => $consumer['link']->id]];
        if ($effect === 'add') {
            $requirements[] = ['requirement_kind' => 'data_use', 'leaf_grant_id' => $consumer['right']['grant_id']];
        }

        return $this->postJson('/api/businesses/'.$consumer['business']['business_public_id'].'/data-use-bindings', [
            'command_id' => (string) Str::uuid(), 'data_use_scope_id' => $consumer['right']['scope_id'], 'effect' => $effect, 'expected_relation' => $expected,
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => $requirements],
        ]);
    }

    private function readOriginal(array $consumer, array $ref, ?array $body = null)
    {
        return $this->postJson('/api/businesses/'.$consumer['business']['business_public_id'].'/materials/read', $body ?? [
            'command_id' => (string) Str::uuid(), 'material_refs' => [$ref], 'operation' => $consumer['right']['operation'] ?? 'retained_read', 'purpose' => 'market_research', 'recipient_ref' => $consumer['user']->public_id,
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => $consumer['read']->id], ['requirement_kind' => 'data_use', 'leaf_grant_id' => $consumer['right']['grant_id']],
            ]],
        ]);
    }

    private function original(?array $payload = null): array
    {
        $payload ??= $this->offer();
        $user = User::factory()->create();
        $this->actingAs($user);
        $source = $this->marketSource();
        $evidence = (string) Str::uuid7();
        $right = $this->marketRight($user, $source, $evidence, 'ingest', array_keys($this->offer()['fields']));
        $ref = $this->writeEvidence($evidence, $right, $payload)->assertOk()->json('data.material_ref');

        return compact('user', 'source', 'evidence', 'right', 'ref');
    }

    public function test_original_is_written_by_a_scoped_runtime_actor_without_a_business_or_workspace(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $source = $this->marketSource();
        $evidence = (string) Str::uuid7();
        $right = $this->marketRight($user, $source, $evidence, 'ingest', array_keys($this->offer()['fields']));
        $result = $this->writeEvidence($evidence, $right, $this->offer())->assertOk();
        $result->assertJsonPath('data.material_ref.namespace', 'market.evidence')->assertJsonPath('data.versions.evidence', 1);
        $owner = DB::connection('pgsql_migrations');
        $this->assertSame(0, $owner->table('businesses')->count());
        $this->assertSame(0, $owner->table('workspaces')->count());
        $this->assertSame(1, $owner->table('market_evidence_revisions')->count());
        $this->assertNull($owner->table('source_registry')->where('id', $source)->value('workspace_id'));
        $this->assertSame('builderad_app', DB::scalar('SELECT session_user'));
    }

    public function test_two_distinct_users_keep_independent_rights_and_can_end_a_binding_after_use_withdrawal(): void
    {
        $f = $this->original();
        $a = $this->consumer($f['source'], $f['evidence']);
        $this->binding($a, 'add', 0)->assertOk();
        $this->readOriginal($a, $f['ref'])->assertOk()->assertJsonPath('data.materials.0.fields.price', 0);
        $b = $this->consumer($f['source'], $f['evidence']);
        $this->binding($b, 'add', 0)->assertOk();
        $response = $this->readOriginal($b, $f['ref'])->assertOk();
        $this->assertApplicationContract('BusinessMaterialReadResult', $response->json());
        $response->assertJsonPath('data.materials.0.validity.status', 'current');
        $borrowed = $b;
        $borrowed['right'] = $a['right'];
        $this->readOriginal($borrowed, $f['ref'])->assertForbidden();
        $this->actingAs($a['user']);
        $this->postJson('/api/data-rights/commands', [
            'command_id' => (string) Str::uuid(), 'target_kind' => 'data_use', 'target_ref' => $a['right']['scope_id'], 'effect' => 'revoke',
            'payload' => ['grant_id' => (string) $a['right']['grant_id']], 'expected_revision' => 1, 'authority_selection' => $this->marketSelection($a['right']),
        ])->assertOk();
        $this->readOriginal($a, $f['ref'])->assertForbidden();
        $this->binding($a, 'end', 1)->assertOk()->assertJsonPath('data.status', 'ended');
        $this->actingAs($b['user']);
        $this->readOriginal($b, $f['ref'])->assertOk();
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('market_evidence_revisions')->count());
    }

    public function test_correction_and_retry_preserve_history_and_expose_currentness_separately_from_read_rights(): void
    {
        $f = $this->original();
        $correct = $this->marketRight($f['user'], $f['source'], $f['evidence'], 'correct', array_keys($this->offer()['fields']));
        $payload = $this->offer();
        $payload['fields']['price'] = 15;
        $command = (string) Str::uuid();
        $new = $this->writeEvidence($f['evidence'], $correct, $payload, 1, $command)->assertOk();
        $noop = (string) Str::uuid();
        $this->assertSame($new->json(), $this->writeEvidence($f['evidence'], $correct, $payload, 2, $noop)->assertOk()->json());
        $this->assertSame('no_material_change', DB::connection('pgsql_migrations')->table('business_command_receipts')->where('command_id', $noop)->value('state'));
        $this->assertSame($new->json(), $this->writeEvidence($f['evidence'], $correct, $payload, 1, $command)->assertOk()->json());
        $this->writeEvidence($f['evidence'], $correct, $payload, 1)->assertConflict();
        $changed = $payload;
        $changed['fields']['price'] = 16;
        $this->writeEvidence($f['evidence'], $correct, $changed, 1, $command)->assertConflict();
        $a = $this->consumer($f['source'], $f['evidence']);
        $this->binding($a, 'add', 0)->assertOk();
        $this->readOriginal($a, $f['ref'])->assertOk()->assertJsonPath('data.materials.0.fields.price', 0)->assertJsonPath('data.materials.0.validity.status', 'historical');
        $this->readOriginal($a, $new->json('data.material_ref'))->assertOk()->assertJsonPath('data.materials.0.fields.price', 15);
        $owner = DB::connection('pgsql_migrations');
        $this->assertSame(2, $owner->table('market_evidence_revisions')->count());
        $this->assertSame(2, $owner->table('market_evidence_transitions')->count());
        $this->assertSame('{price}', $owner->table('market_evidence_transitions')->where('command_id', $command)->value('changed_fields'));
    }

    public function test_operational_grants_do_not_authorize_admission_other_sources_fields_or_schema_changes(): void
    {
        $f = $this->original();
        $other = (string) Str::uuid7();
        $this->writeEvidence($other, $f['right'], $this->offer())->assertForbidden();
        $this->writeEvidence($f['evidence'], $f['right'], $this->offer(), 1)->assertForbidden();
        $payload = $this->offer();
        $payload['fields']['unlicensed'] = 'not admitted';
        $this->writeEvidence($other, $f['right'], $payload)->assertUnprocessable();
        $this->assertFalse(DB::scalar("SELECT has_function_privilege(current_user,'builderad_v2_market_admit(uuid,text,uuid,bigint,jsonb)','EXECUTE')"));
        $this->assertFalse(DB::scalar("SELECT has_schema_privilege(current_user,'public','CREATE')"));
        $this->assertFalse(DB::scalar("SELECT has_table_privilege(current_user,'market_evidence_revisions','INSERT')"));
        $this->assertFalse(DB::scalar("SELECT pg_has_role(current_user,'builderad_scope_executor','MEMBER')"));
    }

    public function test_source_restriction_denies_both_consumers_but_does_not_block_relationship_withdrawal(): void
    {
        $f = $this->original();
        $a = $this->consumer($f['source'], $f['evidence']);
        $this->binding($a, 'add', 0)->assertOk();
        $b = $this->consumer($f['source'], $f['evidence']);
        $this->binding($b, 'add', 0)->assertOk();
        $this->admit('restrict_source', $f['source'], 1, ['reason' => 'Synthetic continuing policy withdrawn']);
        $this->readOriginal($b, $f['ref'])->assertForbidden();
        $this->binding($b, 'end', 1)->assertOk();
        $this->actingAs($a['user']);
        $this->readOriginal($a, $f['ref'])->assertForbidden();
        $this->binding($a, 'end', 1)->assertOk();
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('market_evidence_revisions')->count());
    }

    public function test_unknown_signal_and_failed_coverage_are_not_coerced_into_observed_zero_or_ready_processing(): void
    {
        $f = $this->original();
        $correct = $this->marketRight($f['user'], $f['source'], $f['evidence'], 'correct', ['subject', 'geography', 'metric', 'value']);
        $payload = $this->offer();
        $payload['evidence_type'] = 'market.interest_signal:1';
        $payload['fields'] = ['subject' => $payload['fields']['subject'], 'geography' => $payload['fields']['geography'],
            'metric' => ['name' => 'synthetic interest', 'unit' => 'normalized index', 'definition_version' => '1'], 'value' => null];
        $payload['coverage'] = 'FAILED';
        $payload['valid_until'] = null;
        $ref = $this->writeEvidence($f['evidence'], $correct, $payload, 1)->assertOk()->json('data.material_ref');
        $a = $this->consumer($f['source'], $f['evidence'], ['value', 'metric']);
        $this->binding($a, 'add', 0)->assertOk();
        $this->readOriginal($a, $ref)->assertOk()->assertJsonPath('data.materials.0.fields.value', null)
            ->assertJsonPath('data.materials.0.coverage', 'FAILED')->assertJsonPath('data.materials.0.validity.status', 'unknown');
    }

    public function test_missing_fields_invalid_meaning_and_future_observations_never_leave_a_partial_original(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $source = $this->marketSource();
        $evidence = (string) Str::uuid7();
        $right = $this->marketRight($user, $source, $evidence, 'ingest', array_keys($this->offer()['fields']));
        $missing = $this->offer();
        unset($missing['fields']['currency']);
        $identity = $this->offer();
        $identity['fields']['subject']['identity_state'] = 'source_asserted';
        $time = $this->offer();
        $time['observed_at'] = '2090-01-01T00:00:00Z';
        $coverage = $this->offer();
        $coverage['coverage'] = null;
        foreach ([$missing, $identity, $time, $coverage] as $invalid) {
            $this->writeEvidence($evidence, $right, $invalid)->assertUnprocessable();
        }
        $db = DB::connection('pgsql_migrations');
        $this->assertSame(0, $db->table('market_evidence_heads')->count());
        $this->assertSame(0, $db->table('market_evidence_revisions')->count());
        $this->assertSame(0, $db->table('market_evidence_transitions')->count());
        $this->assertSame(0, $db->table('business_command_receipts')->where('action', 'market.ingest')->count());
        $this->writeEvidence($evidence, $right, $this->offer())->assertOk();
    }

    public function test_purpose_recipient_and_field_selection_cannot_expand_a_market_read(): void
    {
        $f = $this->original();
        $a = $this->consumer($f['source'], $f['evidence']);
        $this->binding($a, 'add', 0)->assertOk();
        $read = $this->readOriginal($a, $f['ref'])->assertOk();
        $read->assertJsonPath('data.materials.0.fields', ['price' => 0])
            ->assertJsonPath('data.materials.0.evidence.schema', 'market.offer:1')
            ->assertJsonPath('data.materials.0.evidence.source_ref', $f['source']);
        $this->assertApplicationContract('BusinessMaterialReadResult', $read->json());
        $body = ['command_id' => (string) Str::uuid(), 'material_refs' => [$f['ref']], 'operation' => 'retained_read', 'purpose' => 'other-purpose',
            'recipient_ref' => $a['user']->public_id, 'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
                'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $a['read']->id], ['requirement_kind' => 'data_use', 'leaf_grant_id' => $a['right']['grant_id']]]]];
        $this->readOriginal($a, $f['ref'], $body)->assertForbidden();
        $body['purpose'] = 'market_research';
        $body['recipient_ref'] = $f['user']->public_id;
        $this->readOriginal($a, $f['ref'], $body)->assertForbidden();
        $body['recipient_ref'] = $a['user']->public_id;
        $body['operation'] = 'process';
        $this->readOriginal($a, $f['ref'], $body)->assertForbidden();
        $body['operation'] = 'retained_read';
        $body['material_refs'][] = ['namespace' => 'market.evidence', 'key' => (string) Str::uuid7(), 'version' => $f['ref']['version']];
        $this->readOriginal($a, $f['ref'], $body)->assertForbidden();
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('business_command_receipts')->where('command_id', $body['command_id'])->count());
    }

    public function test_current_processing_right_does_not_license_superseded_or_unknown_evidence(): void
    {
        $f = $this->original();
        $correct = $this->marketRight($f['user'], $f['source'], $f['evidence'], 'correct', array_keys($this->offer()['fields']));
        $a = $this->consumer($f['source'], $f['evidence'], ['price'], 'process');
        $this->binding($a, 'add', 0)->assertOk();
        $this->readOriginal($a, $f['ref'])->assertOk();
        $this->actingAs($f['user']);
        $payload = $this->offer();
        $payload['valid_until'] = null;
        $payload['coverage'] = 'UNKNOWN';
        $ref = $this->writeEvidence($f['evidence'], $correct, $payload, 1)->assertOk()->json('data.material_ref');
        $this->actingAs($a['user']);
        $this->readOriginal($a, $f['ref'])->assertForbidden();
        $this->readOriginal($a, $ref)->assertForbidden();
        $transition = DB::connection('pgsql_migrations')->table('market_evidence_transitions')->where('to_revision', 2)->value('changed_fields');
        $this->assertStringContainsString('@coverage', $transition);
        $this->assertStringContainsString('@valid_until', $transition);
    }

    public function test_equal_signal_values_with_different_metric_definitions_are_distinct_evidence(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $source = $this->marketSource();
        $evidence = (string) Str::uuid7();
        $payload = $this->offer();
        $payload['evidence_type'] = 'market.interest_signal:1';
        $payload['fields'] = ['subject' => $payload['fields']['subject'], 'geography' => $payload['fields']['geography'],
            'metric' => ['name' => 'synthetic index', 'unit' => 'index', 'definition_version' => '1'], 'value' => 20];
        $ingest = $this->marketRight($user, $source, $evidence, 'ingest', array_keys($payload['fields']));
        $one = $this->writeEvidence($evidence, $ingest, $payload)->assertOk()->json('data.material_ref');
        $correct = $this->marketRight($user, $source, $evidence, 'correct', array_keys($payload['fields']));
        $payload['fields']['metric']['definition_version'] = '2';
        $two = $this->writeEvidence($evidence, $correct, $payload, 1)->assertOk()->json('data.material_ref');
        $this->assertNotSame($one['version'], $two['version']);
        $this->assertSame('{metric}', DB::connection('pgsql_migrations')->table('market_evidence_transitions')->where('to_revision', 2)->value('changed_fields'));
        $this->assertSame(2, DB::connection('pgsql_migrations')->table('market_evidence_revisions')->count());
    }

    public function test_market_read_metadata_never_releases_private_provenance_outside_the_selected_fields_even_on_replay(): void
    {
        $secret = 'MUST_NOT_LEAVE_MARKET_CUSTODY';
        $payload = $this->offer();
        $payload['record_key'] = $secret.'-record';
        $payload['reason'] = $secret.'-operator-note';
        $payload['fields']['subject']['label'] = $secret.'-unlicensed-subject';
        $payload['fields']['inclusions'] = [$secret.'-unlicensed-inclusion'];
        $payload['provenance'] = [
            'url' => 'https://synthetic.invalid/private?token='.$secret,
            'nested' => [['payload' => ['subject' => $payload['fields']['subject']], 'raw_record' => $secret]],
            $secret.'-private-key' => ['kind' => 'market_evidence_revision', 'source_ref' => $secret, 'revision' => 999],
            'source_ref' => $secret, 'revision' => 999, 'kind' => $secret,
        ];
        $f = $this->original($payload);
        $owner = DB::connection('pgsql_migrations');
        $stored = json_decode($owner->table('market_evidence_revisions')->where('evidence_id', $f['evidence'])->value('provenance'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertEquals($payload['provenance'], $stored, 'Full source provenance must remain in internal custody.');
        $internal = $owner->transaction(function () use ($owner, $f) {
            $owner->statement('SET LOCAL ROLE builderad_scope_executor');

            return json_decode($owner->scalar("SELECT builderad_private.resolve_material(?::jsonb)#>'{origin,provenance}'", [json_encode($f['ref'])]), true, flags: JSON_THROW_ON_ERROR);
        });
        $this->assertEquals($stored, $internal, 'Internal lineage must retain the original provenance and digest inputs.');

        foreach (['retained_read', 'display', 'process'] as $operation) {
            $a = $this->consumer($f['source'], $f['evidence'], ['price'], $operation);
            $this->binding($a, 'add', 0)->assertOk();
            $body = ['command_id' => (string) Str::uuid(), 'material_refs' => [$f['ref']], 'operation' => $operation,
                'purpose' => 'market_research', 'recipient_ref' => $a['user']->public_id,
                'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                    ['requirement_kind' => 'business', 'leaf_grant_id' => $a['read']->id], ['requirement_kind' => 'data_use', 'leaf_grant_id' => $a['right']['grant_id']],
                ]]];
            foreach ([false, true] as $replay) {
                $read = $this->readOriginal($a, $f['ref'], $body)->assertOk()->assertJsonPath('data.materials.0.fields', ['price' => 0]);
                $this->assertStringNotContainsString($secret, $read->getContent(), 'Unlicensed values or keys leaked through metadata; replay='.(int) $replay);
                $read->assertJsonPath('data.materials.0.provenance', ['kind' => 'market_evidence_revision', 'revision' => 1, 'source_ref' => $f['source']]);
                $metadata = $read->json('data.materials.0.evidence');
                $this->assertEqualsCanonicalizing(['source_ref', 'schema', 'revision', 'observed_at', 'effective_at', 'valid_until'], array_keys($metadata));
                $this->assertSame($f['source'], $metadata['source_ref']);
                $this->assertSame('market.offer:1', $metadata['schema']);
                $this->assertSame(1, $metadata['revision']);
                $this->assertApplicationContract('BusinessMaterialReadResult', $read->json());
            }
            $receipt = $owner->table('business_command_receipts')->where('command_id', $body['command_id'])->sole();
            $this->assertStringNotContainsString($secret, $receipt->result_ref);
            $this->assertSame(1, $owner->table('business_command_receipts')->where('command_id', $body['command_id'])->count());
        }
        $this->assertSame(1, $owner->table('market_evidence_revisions')->where('evidence_id', $f['evidence'])->count());
    }

    public function test_installed_d1_metadata_upgrade_redacts_an_existing_read_replay_without_changing_internal_lineage_or_rights(): void
    {
        $reset = app(DisposableDatabaseReset::class);
        $reset->clear();
        try {
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')),
                fn ($path) => strcmp(basename($path), '0001_01_01_000440_qualify_market_evidence_read_metadata.php') < 0));
            // Current HTTP admission requires its operational singleton even on this historical domain schema.
            $paths[] = database_path('migrations/0001_01_01_000470_create_platform_maintenance.php');
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--path' => $paths, '--realpath' => true, '--force' => true]));
            $payload = $this->offer();
            $secret = 'RETAIN_INTERNAL_TRACE_REDACT_DELIVERY';
            $payload['provenance'] = ['private_note' => ['value' => $secret], 'source_ref' => $secret];
            $f = $this->original($payload);
            $a = $this->consumer($f['source'], $f['evidence']);
            $this->binding($a, 'add', 0)->assertOk();
            $body = ['command_id' => (string) Str::uuid(), 'material_refs' => [$f['ref']], 'operation' => 'retained_read',
                'purpose' => 'market_research', 'recipient_ref' => $a['user']->public_id,
                'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                    ['requirement_kind' => 'business', 'leaf_grant_id' => $a['read']->id], ['requirement_kind' => 'data_use', 'leaf_grant_id' => $a['right']['grant_id']],
                ]]];
            $before = $this->readOriginal($a, $f['ref'], $body)->assertOk();
            $this->assertStringContainsString($secret, $before->getContent(), 'The installed pre-fix D1 reader must reproduce the disclosure.');
            $owner = DB::connection('pgsql_migrations');
            $grants = $owner->table('security_action_grants')->orderBy('id')->get()->toJson();
            $revisions = $owner->scalar('SELECT jsonb_agg(to_jsonb(r) ORDER BY evidence_id,revision)::text FROM market_evidence_revisions r');
            $receipt = $owner->scalar('SELECT to_jsonb(r)::text FROM business_command_receipts r WHERE command_id=?::uuid', [$body['command_id']]);
            $permissions = $owner->selectOne("SELECT proowner,proacl::text,proconfig::text FROM pg_proc WHERE oid='builderad_v2_scoped_material_read(text,uuid,uuid,jsonb,text,text,uuid,jsonb)'::regprocedure");
            $owner->unprepared("CREATE FUNCTION public.market_read_upgrade_test_drift() RETURNS integer LANGUAGE sql AS 'SELECT 1'");
            try {
                Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]);
                $this->fail('Unqualified catalog drift was promoted by the metadata fix.');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->errorInfo[0]);
                $this->assertStringContainsString('MARKET_READ_UPGRADE_BASELINE_QUALIFICATION_REQUIRED', $error->getMessage());
            } finally {
                $owner->statement('DROP FUNCTION public.market_read_upgrade_test_drift()');
            }
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            $after = $this->readOriginal($a, $f['ref'], $body)->assertOk()->assertJsonPath('data.materials.0.fields', ['price' => 0]);
            $this->assertStringNotContainsString($secret, $after->getContent());
            $after->assertJsonPath('data.materials.0.provenance', ['kind' => 'market_evidence_revision', 'revision' => 1, 'source_ref' => $f['source']]);
            $this->assertSame($before->json('data.dependency_digest'), $after->json('data.dependency_digest'));
            $this->assertSame($receipt, $owner->scalar('SELECT to_jsonb(r)::text FROM business_command_receipts r WHERE command_id=?::uuid', [$body['command_id']]));
            $this->assertSame($grants, $owner->table('security_action_grants')->orderBy('id')->get()->toJson());
            $this->assertSame($revisions, $owner->scalar('SELECT jsonb_agg(to_jsonb(r) ORDER BY evidence_id,revision)::text FROM market_evidence_revisions r'));
            $this->assertEquals($permissions, $owner->selectOne("SELECT proowner,proacl::text,proconfig::text FROM pg_proc WHERE oid='builderad_v2_scoped_material_read(text,uuid,uuid,jsonb,text,text,uuid,jsonb)'::regprocedure"));
            $owner->transaction(function () use ($owner): void {
                $owner->statement('SET LOCAL ROLE builderad_scope_executor');
                $this->assertTrue($owner->scalar('SELECT builderad_private.legacy_catalog_digest()=builderad_private.legacy_installation_digest()'));
            });
            $this->assertFalse($owner->scalar("SELECT has_schema_privilege('builderad_scope_executor','public','CREATE')"));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            $this->assertSame(1, $owner->table('migrations')->where('migration', '0001_01_01_000440_qualify_market_evidence_read_metadata')->count());
        } finally {
            $reset->install();
        }
    }
}
