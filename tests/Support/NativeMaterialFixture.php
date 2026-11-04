<?php

namespace Tests\Support;

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BusinessAuthorityFixture as Fixture;

/** Actual scoped-read fixtures; no live provider or operating root issuer is asserted. */
trait NativeMaterialFixture
{
    private function original(string $operation = 'retained_read', array $fields = ['spend'], int $count = 1, bool $adoptViaCommand = false, bool $historicalWorkspace = false): array
    {
        sort($fields, SORT_STRING);
        $user = User::factory()->create();
        $this->actingAs($user);
        $a = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Material client'])->assertCreated()->json('data');
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($user, 1);
        if ($historicalWorkspace) {
            // The organizational read view is a later migration. Populate the
            // historical schema through its real signed canonical creator,
            // without asking current HTTP presentation to emulate an old app.
            $request = Request::create('/workspaces');
            $request->setLaravelSession(app('session')->driver());
            DB::transaction(function () use ($user, $request): void {
                app(PrincipalContext::class)->activate($user);
                DB::scalar('SELECT builderad_create_workspace(?,?,?,?)', [$user->id, SessionAuthority::sessionKey($request), 'Native material custody', 'material-custody']);
            });
        } else {
            $this->post('/workspaces', ['name' => 'Native material custody', 'slug' => 'material-custody'])->assertRedirect();
        }
        $db = DB::connection('pgsql_migrations');
        $workspace = $db->table('workspaces')->first();
        $business = $db->table('businesses')->where('public_id', $a['business_public_id'])->value('id');
        $source = (string) Str::uuid7();
        $db->table('source_registry')->insert(['id' => $source, 'workspace_id' => $workspace->id, 'family' => 'meta', 'namespace' => 'ad_account',
            'declared_external_id' => 'account-1', 'label' => 'Synthetic material source', 'version' => 1, 'status' => 'UNVERIFIED']);
        $db->table('source_capabilities')->insert(['workspace_id' => $workspace->id, 'source_id' => $source, 'resource' => 'account-1', 'operation' => 'report.read', 'purpose' => 'capture']);
        $native = $db->table('security_action_grants')->insertGetId(['subject_user_id' => $user->id, 'granted_by' => $user->id, 'source' => 'source_owner_request',
            'scope_kind' => 'source', 'workspace_id' => $workspace->id, 'source_target_id' => $source,
            'source_resource' => 'account-1', 'source_operation' => 'report.read', 'source_purpose' => 'capture', 'action' => 'source.read',
            'subject_generation' => 1, 'status' => 'active', 'version' => 1, 'reason' => 'SYNTHETIC NATIVE FIXTURE', 'created_at' => now(), 'updated_at' => now()]);
        $namespace = hash('sha256', 'test:material-native');
        $identity = 'bb2:'.hash('sha256', 'test:material-row');
        $hash = hash('sha256', 'trusted-test-native-content');
        $db->table('provider_observation_streams')->insert(['namespace' => $namespace, 'workspace_id' => $workspace->id, 'source_id' => $source, 'provider' => 'meta',
            'resource' => 'account-1', 'operation' => 'report.read', 'purpose' => 'capture', 'stream' => 'campaign', 'generation' => 1, 'access_version' => 1]);
        $db->table('provider_observation_runs')->insert(['namespace' => $namespace, 'generation' => 1, 'command_id' => (string) Str::uuid(), 'fingerprint' => hash('sha256', 'fixture'),
            'coverage' => 'PARTIAL', 'provenance' => json_encode(['provider_reported' => true]), 'progress_advanced' => false, 'actor_user_id' => $user->id, 'actor_generation' => 1]);
        $db->table('provider_observation_entries')->insert(['namespace' => $namespace, 'generation' => 1, 'identity' => $identity, 'content_hash' => $hash,
            'observation' => json_encode(['spend' => ['amount' => '17', 'currency' => 'SAR'], 'native_facts' => ['private_lead' => 'must not leave custodian']]), 'relation' => 'FIRST_OBSERVATION']);
        $identities = [$identity];
        for ($i = 1; $i < $count; $i++) {
            $extra = 'bb2:'.hash('sha256', 'test:material-row-'.$i);
            $identities[] = $extra;
            $db->table('provider_observation_entries')->insert(['namespace' => $namespace, 'generation' => 1, 'identity' => $extra, 'content_hash' => $hash,
                'observation' => json_encode(['spend' => ['amount' => '17', 'currency' => 'SAR'], 'native_facts' => ['private_lead' => 'must not leave custodian']]), 'relation' => 'FIRST_OBSERVATION']);
        }
        sort($identities, SORT_STRING);
        $boundary = Fixture::boundary($user->id);
        $manager = null;
        if ($adoptViaCommand) {
            $grant = (array) $db->table('security_action_grants')->where('id', $native)->sole();
            unset($grant['id']);
            $grant['action'] = 'source.manage';
            $manager = $db->table('security_action_grants')->insertGetId($grant);
            $this->postJson('/api/data-rights/commands', ['command_id' => (string) Str::uuid(), 'target_kind' => 'source', 'target_ref' => $source,
                'effect' => 'adopt', 'payload' => (object) [], 'expected_revision' => 1,
                'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
                    'requirements' => [['requirement_kind' => 'native_source', 'leaf_grant_id' => $manager]]]])->assertOk();
        } else {
            Fixture::insert('resource_right_fences', ['fence_id' => (string) Str::uuid7(), 'kind' => 'source', 'source_id' => $source, 'boundary_id' => null, 'rights_epoch' => 0, 'adoption_state' => 'v2_adopted'], $user->id);
        }
        Fixture::insert('boundary_subjects', ['membership_id' => (string) Str::uuid7(), 'boundary_id' => $boundary['boundary_id'], 'business_id' => $business, 'branch_id' => null, 'status' => 'active', 'revision' => 1], $user->id);
        $scope = (string) Str::uuid7();
        $selector = ['native_namespaces' => [$namespace], 'native_keys' => $identities, 'field_keys' => $fields, 'partition_ref' => null];
        $digest = $db->transaction(function () use ($db, $boundary, $source, $operation, $selector) {
            $db->statement('SET LOCAL ROLE builderad_scope_executor');

            return $db->scalar("SELECT encode(builderad_private.data_scope_digest(?::uuid,?::uuid,'account-1',?,'market_research',?::jsonb),'hex')", [$boundary['boundary_id'], $source, $operation, json_encode($selector)]);
        });
        Fixture::insert('data_use_scopes', ['scope_id' => $scope, 'boundary_id' => $boundary['boundary_id'], 'source_id' => $source, 'resource' => 'account-1', 'selection' => $selector,
            'selection_schema_version' => 1, 'operation' => $operation, 'purpose' => 'market_research', 'scope_digest' => '\\x'.$digest, 'status' => 'active', 'revision' => 1], $user->id);
        $until = null;
        $use = Fixture::root($scope, $user->id, 'data_use.'.$operation, kind: 'data_use');
        $businessGrant = Fixture::root($business, $user->id, 'business.data.use');
        Fixture::insert('business_data_use_bindings', ['binding_id' => (string) Str::uuid7(), 'business_id' => $business, 'data_use_scope_id' => $scope, 'status' => 'active', 'revision' => 1, 'qualification_ref' => $use->qualification_id], $user->id);
        $key = $db->scalar("SELECT jsonb_build_object('namespace',?::text,'generation',1,'identity',?::text)::text", [$namespace, $identity]);
        $selection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
            ['requirement_kind' => 'business', 'leaf_grant_id' => $businessGrant->id], ['requirement_kind' => 'data_use', 'leaf_grant_id' => $use->id], ['requirement_kind' => 'native_source', 'leaf_grant_id' => $native]]];
        $refs = array_map(fn ($id) => ['namespace' => 'provider_observation_entry', 'key' => $db->scalar("SELECT jsonb_build_object('namespace',?::text,'generation',1,'identity',?::text)::text", [$namespace, $id]), 'version' => $hash], $identities);
        $body = ['command_id' => (string) Str::uuid(), 'material_refs' => $refs,
            'operation' => $operation, 'purpose' => 'market_research', 'recipient_ref' => $user->public_id, 'authority_selection' => $selection];

        return compact('user', 'a', 'business', 'source', 'scope', 'boundary', 'use', 'businessGrant', 'native', 'manager', 'body', 'until');
    }
}
