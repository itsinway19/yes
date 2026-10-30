<?php

namespace Tests\Feature;

use App\Foundation\Business\BusinessScope;
use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BusinessAuthorityFixture as Fixture;
use Tests\TestCase;

final class DataUseBindingTest extends TestCase
{
    private function request(User $u): Request
    {
        $this->actingAs($u);
        $r = Request::create('/api/businesses');
        $r->setUserResolver(fn () => $u);
        $r->setLaravelSession(app('session')->driver());

        return $r;
    }

    private function setupScope(User $u): array
    {
        $db = DB::connection('pgsql_migrations');
        $a = app(BusinessScope::class)->create($this->request($u), (string) Str::uuid(), 'Data customer', null);
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($u, 1);
        $this->post('/workspaces', ['name' => 'Source custody', 'slug' => 'source-custody'])->assertRedirect();
        $w = $db->table('workspaces')->where('slug', 'source-custody')->first();
        $source = (string) Str::uuid7();
        // Registered native metadata only; no credential, live provider, or production source-adoption claim.
        $db->table('source_registry')->insert(['id' => $source, 'workspace_id' => $w->id, 'family' => 'test', 'namespace' => 'test.synthetic',
            'declared_external_id' => 'fixture-only', 'label' => 'Synthetic source', 'version' => 1, 'status' => 'UNVERIFIED']);
        $boundary = Fixture::boundary($u->id);
        $scope = (string) Str::uuid7();
        $selection = ['native_namespaces' => ['test.synthetic'], 'native_keys' => ['record-1'], 'field_keys' => ['city'], 'partition_ref' => null];
        $digest = $db->transaction(function () use ($db, $boundary, $source, $selection) {
            $db->statement('SET LOCAL ROLE builderad_scope_executor');

            return $db->scalar("SELECT encode(builderad_private.data_scope_digest(?::uuid,?::uuid,'leads','process','market_research',?::jsonb),'hex')", [$boundary['boundary_id'], $source, json_encode($selection)]);
        });
        Fixture::insert('data_use_scopes', ['scope_id' => $scope, 'boundary_id' => $boundary['boundary_id'], 'source_id' => $source,
            'resource' => 'leads', 'selection' => $selection, 'selection_schema_version' => 1, 'operation' => 'process', 'purpose' => 'market_research',
            'scope_digest' => '\\x'.$digest, 'status' => 'active', 'revision' => 1], $u->id);
        $g = Fixture::root($scope, $u->id, 'data_use.process', kind: 'data_use');

        return [$a, $boundary, $source, $scope, $g];
    }

    private function selection(array $a, int $grant): array
    {
        return ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
            ['requirement_kind' => 'business', 'leaf_grant_id' => $a['grant_refs']['business.link.manage']], ['requirement_kind' => 'data_use', 'leaf_grant_id' => $grant]]];
    }

    private function bind(Request $r, array $a, string $scope, array $selection): array
    {
        return DB::transaction(function () use ($r, $a, $scope, $selection) {
            app(PrincipalContext::class)->activate($r->user());

            return json_decode(DB::scalar("SELECT builderad_v2_business_data_use_binding(?,?::uuid,?::uuid,?::uuid,'add',0,?::jsonb)",
                [SessionAuthority::sessionKey($r), $a['business_public_id'], (string) Str::uuid(), $scope, json_encode($selection)]), true, flags: JSON_THROW_ON_ERROR);
        });
    }

    private function denied(callable $op): void
    {
        try {
            $op();
            $this->fail('Unqualified data binding succeeded.');
        } catch (QueryException $e) {
            $this->assertSame('42501', $e->errorInfo[0]);
        }
    }

    public function test_binding_needs_pre_adopted_source_exact_data_use_right_and_boundary_coverage(): void
    {
        $u = User::factory()->create();
        [$a, $boundary, $source, $scope, $grant] = $this->setupScope($u);
        $r = $this->request($u);
        $selection = $this->selection($a, $grant->id);
        $this->denied(fn () => $this->bind($r, $a, $scope, $selection));
        Fixture::insert('resource_right_fences', ['fence_id' => (string) Str::uuid7(), 'kind' => 'source', 'source_id' => $source,
            'boundary_id' => null, 'rights_epoch' => 0, 'adoption_state' => 'v2_adopted'], $u->id);
        $this->denied(fn () => $this->bind($r, $a, $scope, $selection));
        $db = DB::connection('pgsql_migrations');
        $b = $db->table('businesses')->where('public_id', $a['business_public_id'])->value('id');
        Fixture::insert('boundary_subjects', ['membership_id' => (string) Str::uuid7(), 'boundary_id' => $boundary['boundary_id'], 'business_id' => $b,
            'branch_id' => null, 'status' => 'active', 'revision' => 1], $u->id);
        $missing = $selection;
        $missing['requirements'] = [$selection['requirements'][0]];
        $this->denied(fn () => $this->bind($r, $a, $scope, $missing));
        $result = $this->bind($r, $a, $scope, $selection);
        $this->assertSame('active', $result['status']);
        $this->assertSame(1, $db->table('business_data_use_bindings')->count());
        $this->assertSame(1, $db->table('security_action_grants')->where('scope_kind', 'data_use')->count());
        $receipt = $db->table('business_command_receipts')->where('action', 'business.binding.add')->first();
        $this->assertNotSame('', $receipt->dependency_digest);
        $this->denied(fn () => DB::select('SELECT * FROM data_use_scopes'));
    }

    public function test_scope_codec_binds_source_boundary_purpose_and_operation_with_canonical_opaque_selection(): void
    {
        $u = User::factory()->create();
        [$a, $boundary, $source] = $this->setupScope($u);
        $db = DB::connection('pgsql_migrations');
        $selection = ['native_namespaces' => ['test.synthetic'], 'native_keys' => ['record-1'], 'field_keys' => ['city'], 'partition_ref' => null];
        $db->statement('SET ROLE builderad_scope_executor');
        try {
            $hash = fn ($b, $s, $op, $purpose) => $db->scalar("SELECT encode(builderad_private.data_scope_digest(?::uuid,?::uuid,'leads',?,?,?::jsonb),'hex')", [$b, $s, $op, $purpose, json_encode($selection)]);
            $first = $hash($boundary['boundary_id'], $source, 'process', 'market_research');
            $this->assertNotSame($first, $hash((string) Str::uuid7(), $source, 'process', 'market_research'));
            $this->assertNotSame($first, $hash($boundary['boundary_id'], (string) Str::uuid7(), 'process', 'market_research'));
            $this->assertNotSame($first, $hash($boundary['boundary_id'], $source, 'display', 'market_research'));
            $this->assertNotSame($first, $hash($boundary['boundary_id'], $source, 'process', 'sales_followup'));
            $this->assertSame($first, $hash($boundary['boundary_id'], $source, 'process', 'market_research'));
        } finally {
            $db->statement('RESET ROLE');
        }
    }

    public function test_http_binding_receipt_replay_does_not_create_a_second_link_or_grant(): void
    {
        $u = User::factory()->create();
        [$a, $boundary, $source, $scope, $grant] = $this->setupScope($u);
        $db = DB::connection('pgsql_migrations');
        Fixture::insert('resource_right_fences', ['fence_id' => (string) Str::uuid7(), 'kind' => 'source', 'source_id' => $source,
            'boundary_id' => null, 'rights_epoch' => 0, 'adoption_state' => 'v2_adopted'], $u->id);
        Fixture::insert('boundary_subjects', ['membership_id' => (string) Str::uuid7(), 'boundary_id' => $boundary['boundary_id'],
            'business_id' => $db->table('businesses')->where('public_id', $a['business_public_id'])->value('id'), 'branch_id' => null, 'status' => 'active', 'revision' => 1], $u->id);
        $this->actingAs($u);
        $url = '/api/businesses/'.$a['business_public_id'].'/data-use-bindings';
        $body = ['command_id' => (string) Str::uuid(), 'authority_selection' => $this->selection($a, $grant->id), 'data_use_scope_id' => $scope, 'effect' => 'add', 'expected_relation' => 0];
        $id = $this->postJson($url, $body)->assertOk()->assertJsonPath('data.status', 'active')->json('data.binding_id');
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.binding_id', $id);
        $this->assertSame(1, $db->table('business_data_use_bindings')->count());
        $this->assertSame(1, $db->table('security_action_grants')->where('scope_kind', 'data_use')->count());
        $body['command_id'] = (string) Str::uuid();
        $body['effect'] = 'end';
        $body['expected_relation'] = 1;
        $body['authority_selection']['requirements'] = [$body['authority_selection']['requirements'][0]];
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.status', 'ended')->assertJsonPath('data.versions.binding', 2);
        $this->assertSame('active', $db->table('security_action_grants')->where('id', $grant->id)->value('status'));
    }

    public function test_prepared_end_cannot_end_a_different_recreated_data_binding(): void
    {
        $actor = User::factory()->create();
        [$a, $boundary, $source, $scope, $grant] = $this->setupScope($actor);
        $db = DB::connection('pgsql_migrations');
        Fixture::insert('resource_right_fences', ['fence_id' => (string) Str::uuid7(), 'kind' => 'source', 'source_id' => $source,
            'boundary_id' => null, 'rights_epoch' => 0, 'adoption_state' => 'v2_adopted'], $actor->id);
        Fixture::insert('boundary_subjects', ['membership_id' => (string) Str::uuid7(), 'boundary_id' => $boundary['boundary_id'],
            'business_id' => $db->table('businesses')->where('public_id', $a['business_public_id'])->value('id'), 'branch_id' => null,
            'status' => 'active', 'revision' => 1], $actor->id);
        $url = '/api/businesses/'.$a['business_public_id'].'/data-use-bindings';
        $body = ['command_id' => (string) Str::uuid(), 'authority_selection' => $this->selection($a, $grant->id),
            'data_use_scope_id' => $scope, 'effect' => 'add', 'expected_relation' => 0];
        $first = $this->postJson($url, $body)->assertOk()->json('data.binding_id');
        $prepared = [...$body, 'command_id' => (string) Str::uuid(), 'effect' => 'end', 'expected_relation' => 1];
        $prepared['authority_selection']['requirements'] = [$prepared['authority_selection']['requirements'][0]];
        $this->postJson($url, [...$prepared, 'command_id' => (string) Str::uuid()])->assertOk();
        $this->postJson($url, [...$body, 'command_id' => (string) Str::uuid()])->assertConflict();
        $second = $this->postJson($url, [...$body, 'command_id' => (string) Str::uuid(), 'expected_relation' => 2])->assertOk()
            ->assertJsonPath('data.versions.binding', 3)->json('data.binding_id');
        $this->assertNotSame($first, $second);
        $this->postJson($url, $prepared)->assertConflict();
        $this->assertSame('active', $db->table('business_data_use_bindings')->where('binding_id', $second)->value('status'));
        $this->assertSame(2, $db->table('business_data_use_bindings')->count());
    }

    public function test_revoke_exact_data_use_path_blocks_its_bound_use_without_revoking_independent_rights(): void
    {
        $u = User::factory()->create();
        [$a, $boundary, $source, $scope, $grant] = $this->setupScope($u);
        $db = DB::connection('pgsql_migrations');
        Fixture::insert('resource_right_fences', ['fence_id' => (string) Str::uuid7(), 'kind' => 'source', 'source_id' => $source,
            'boundary_id' => null, 'rights_epoch' => 0, 'adoption_state' => 'v2_adopted'], $u->id);
        Fixture::insert('boundary_subjects', ['membership_id' => (string) Str::uuid7(), 'boundary_id' => $boundary['boundary_id'],
            'business_id' => $db->table('businesses')->where('public_id', $a['business_public_id'])->value('id'), 'branch_id' => null, 'status' => 'active', 'revision' => 1], $u->id);
        $other = Fixture::root($scope, $u->id, 'data_use.process', kind: 'data_use');
        $r = $this->request($u);
        $this->assertSame('active', $this->bind($r, $a, $scope, $this->selection($a, $grant->id))['status']);
        $body = ['command_id' => (string) Str::uuid(), 'target_kind' => 'data_use', 'target_ref' => $scope, 'effect' => 'revoke',
            'payload' => ['grant_id' => (string) $other->id], 'expected_revision' => 1,
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
                'requirements' => [['requirement_kind' => 'data_use', 'leaf_grant_id' => $grant->id]]]];
        $this->postJson('/api/data-rights/commands', $body)->assertForbidden();
        $this->assertSame(0, $db->table('resource_right_fences')->where('fence_id', $boundary['fence_id'])->value('rights_epoch'));
        $body['payload']['grant_id'] = (string) $grant->id;
        $body['command_id'] = (string) Str::uuid();
        $this->postJson('/api/data-rights/commands', $body)->assertOk()->assertJsonPath('data.status', 'revoked')->assertJsonPath('data.versions.grant', 2);
        $this->assertSame(1, $db->table('resource_right_fences')->where('fence_id', $boundary['fence_id'])->value('rights_epoch'));
        $this->denied(fn () => $this->bind($r, $a, $scope, $this->selection($a, $grant->id)));
        $this->assertSame('active', app(BusinessScope::class)->bindDataUse($r, $a['business_public_id'], (string) Str::uuid(), $scope, 'add', 1, $this->selection($a, $other->id))['status']);
        $this->assertSame('active', $db->table('security_action_grants')->where('id', $other->id)->value('status'));
        $this->assertSame('active', $db->table('security_action_grants')->where('id', $boundary['grant_id'])->value('status'));
        $this->assertSame('active', $db->table('security_action_grants')->where('id', $a['grant_refs']['business.link.manage'])->value('status'));
        $this->assertSame(0, $db->table('resource_right_fences')->where('source_id', $source)->value('rights_epoch'));
        $this->assertSame(1, $db->table('business_data_use_bindings')->count());
        $body['command_id'] = (string) Str::uuid();
        $body['payload']['grant_id'] = (string) $other->id;
        $body['authority_selection']['requirements'][0]['leaf_grant_id'] = $other->id;
        $this->postJson('/api/data-rights/commands', $body)->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->assertSame(0, $db->table('security_action_grants')->where('scope_kind', 'data_use')->where('status', 'active')->count());
        $ended = app(BusinessScope::class)->bindDataUse($r, $a['business_public_id'], (string) Str::uuid(), $scope, 'end', 1,
            ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $a['grant_refs']['business.link.manage']]]]);
        $this->assertSame('ended', $ended['status']);
        $this->assertSame('revoked', $db->table('security_action_grants')->where('id', $other->id)->value('status'));
        $this->assertSame(2, $db->table('resource_right_fences')->where('fence_id', $boundary['fence_id'])->value('rights_epoch'));
    }
}
