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

final class BusinessDelegationTest extends TestCase
{
    private function request(User $user): Request
    {
        $this->actingAs($user);
        $r = Request::create('/api/businesses');
        $r->setUserResolver(fn () => $user);
        $r->setLaravelSession(app('session')->driver());

        return $r;
    }

    private function selection(int $grant, ?string $workspace = null): array
    {
        return ['realm_kind' => $workspace ? 'agency' : 'direct', 'realm_workspace_public_id' => $workspace,
            'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $grant]]];
    }

    private function denied(callable $op, string $state = '42501'): void
    {
        try {
            $op();
            $this->fail('Protected operation unexpectedly succeeded.');
        } catch (QueryException $e) {
            $this->assertSame($state, $e->errorInfo[0]);
        }
    }

    private function command(Request $r, string $business, User $recipient, int $issuer, string $effect = 'issue', ?int $grant = null, ?string $agency = null, bool $delegable = false, string $action = 'business.context.read'): array
    {
        return DB::transaction(function () use ($r, $business, $recipient, $issuer, $effect, $grant, $agency, $delegable, $action) {
            app(PrincipalContext::class)->activate($r->user());

            return json_decode(DB::scalar('SELECT builderad_v2_business_grant_command(?,?::uuid,?::uuid,?,?::uuid,?,?,?,?,NULL,?,?::uuid,?::jsonb)',
                [SessionAuthority::sessionKey($r), $business, (string) Str::uuid(), $effect, $recipient->public_id, $action,
                    $effect === 'issue' ? $issuer : null, $grant, $effect === 'issue' ? 0 : 1, $delegable ? 'true' : 'false', $agency,
                    json_encode($this->selection($issuer))]), true, flags: JSON_THROW_ON_ERROR);
        });
    }

    private function draft(User $owner): array
    {
        return app(BusinessScope::class)->create($this->request($owner), (string) Str::uuid(), 'Independent customer', null);
    }

    public function test_draft_authority_cannot_be_promoted_into_team_or_company_authority(): void
    {
        $owner = User::factory()->create();
        $a = $this->draft($owner);
        $other = User::factory()->create();
        $this->denied(fn () => $this->command($this->request($owner), $a['business_public_id'], $other, $a['grant_refs']['business.context.read']));
        $this->assertSame(7, DB::connection('pgsql_migrations')->table('security_action_grants')->where('scope_kind', 'business')->count());
    }

    public function test_two_independent_paths_survive_exact_revocation_and_do_not_resurrect(): void
    {
        $owner = User::factory()->create();
        $a = $this->draft($owner);
        $other = User::factory()->create();
        $db = DB::connection('pgsql_migrations');
        $b = $db->table('businesses')->where('public_id', $a['business_public_id'])->value('id');
        $root = Fixture::root($b, $owner->id, 'business.context.read');
        $first = $this->command($this->request($owner), $a['business_public_id'], $other, $root->id);
        $second = $this->command($this->request($owner), $a['business_public_id'], $other, $root->id);
        $this->assertNotSame($first['grant_id'], $second['grant_id']);
        $scope = app(BusinessScope::class);
        $this->assertSame('Independent customer', $scope->read($this->request($other), $a['business_public_id'], $this->selection($first['grant_id']))['name']);
        $this->command($this->request($owner), $a['business_public_id'], $other, $root->id, 'revoke', $first['grant_id']);
        $this->denied(fn () => $scope->read($this->request($other), $a['business_public_id'], $this->selection($first['grant_id'])));
        $this->assertSame('Independent customer', $scope->read($this->request($other), $a['business_public_id'], $this->selection($second['grant_id']))['name']);
        $this->assertSame('revoked', $db->table('security_action_grants')->where('id', $first['grant_id'])->value('status'));
        $this->assertSame('active', $db->table('security_action_grants')->where('id', $second['grant_id'])->value('status'));
        $this->assertSame(3, $db->table('business_authority_fences')->where('business_id', $b)->value('auth_epoch'));
    }

    public function test_parent_revocation_invalidates_descendant_without_deleting_an_independent_path(): void
    {
        $owner = User::factory()->create();
        $a = $this->draft($owner);
        $middle = User::factory()->create();
        $leaf = User::factory()->create();
        $db = DB::connection('pgsql_migrations');
        $b = $db->table('businesses')->where('public_id', $a['business_public_id'])->value('id');
        $root = Fixture::root($b, $owner->id, 'business.context.read');
        $delegated = $this->command($this->request($owner), $a['business_public_id'], $middle, $root->id, delegable: true);
        $child = $this->command($this->request($middle), $a['business_public_id'], $leaf, $delegated['grant_id']);
        $this->assertSame('Independent customer', app(BusinessScope::class)->read($this->request($leaf), $a['business_public_id'], $this->selection($child['grant_id']))['name']);
        $this->command($this->request($owner), $a['business_public_id'], $middle, $root->id, 'revoke', $delegated['grant_id']);
        $this->denied(fn () => app(BusinessScope::class)->read($this->request($leaf), $a['business_public_id'], $this->selection($child['grant_id'])));
        $this->assertSame('active', $db->table('security_action_grants')->where('id', $child['grant_id'])->value('status'));
    }

    public function test_two_agency_realms_require_their_own_complete_chain_and_current_team(): void
    {
        $owner = User::factory()->create();
        $a = $this->draft($owner);
        $agent = User::factory()->create();
        $db = DB::connection('pgsql_migrations');
        $b = $db->table('businesses')->where('public_id', $a['business_public_id'])->value('id');
        $root = Fixture::root($b, $owner->id, 'business.context.read');
        $linkRoot = Fixture::root($b, $owner->id, 'business.link.manage');
        $workspaces = [];
        $grants = [];
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($agent, 1);
        for ($i = 0; $i < 2; $i++) {
            $this->request($agent);
            $this->post('/workspaces', ['name' => 'Agency '.$i, 'slug' => 'agency-'.$i])->assertRedirect();
            $w = $db->table('workspaces')->where('slug', 'agency-'.$i)->first();
            $workspaces[] = $w;
            Fixture::insert('business_workspace_links', ['link_id' => (string) Str::uuid7(), 'business_id' => $b, 'workspace_id' => $w->id,
                'kind' => 'agency', 'status' => 'active', 'revision' => 1, 'valid_from' => now()->toIso8601String(), 'valid_until' => null, 'authority_ref' => $linkRoot->qualification_id], $owner->id);
            $grants[] = $this->command($this->request($owner), $a['business_public_id'], $agent, $root->id, agency: $w->public_id);
        }
        $scope = app(BusinessScope::class);
        $this->assertSame('Independent customer', $scope->read($this->request($agent), $a['business_public_id'], $this->selection($grants[0]['grant_id'], $workspaces[0]->public_id))['name']);
        $nullRealm = $this->selection($grants[0]['grant_id'], $workspaces[0]->public_id);
        $nullRealm['realm_kind'] = null;
        $this->denied(fn () => $scope->read($this->request($agent), $a['business_public_id'], $nullRealm));
        $malformed = $this->selection($grants[0]['grant_id'], str_repeat('-', 36));
        $this->denied(fn () => $scope->read($this->request($agent), $a['business_public_id'], $malformed));
        $this->denied(fn () => $scope->read($this->request($agent), $a['business_public_id'], $this->selection($grants[1]['grant_id'], $workspaces[0]->public_id)));
        $this->command($this->request($owner), $a['business_public_id'], $agent, $root->id, 'revoke', $grants[0]['grant_id']);
        $this->denied(fn () => $scope->read($this->request($agent), $a['business_public_id'], $this->selection($grants[0]['grant_id'], $workspaces[0]->public_id)));
        $this->assertSame('Independent customer', $scope->read($this->request($agent), $a['business_public_id'], $this->selection($grants[1]['grant_id'], $workspaces[1]->public_id))['name']);
        $db->table('workspace_memberships')->where('workspace_id', $workspaces[1]->id)->where('user_id', $agent->id)->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->denied(fn () => $scope->read($this->request($agent), $a['business_public_id'], $this->selection($grants[1]['grant_id'], $workspaces[1]->public_id)));
    }

    public function test_http_grant_writer_has_no_root_bootstrap_and_revokes_the_exact_selected_id(): void
    {
        $owner = User::factory()->create();
        $a = $this->draft($owner);
        $other = User::factory()->create();
        $db = DB::connection('pgsql_migrations');
        $b = $db->table('businesses')->where('public_id', $a['business_public_id'])->value('id');
        $root = Fixture::root($b, $owner->id, 'business.context.read');
        $this->actingAs($owner);
        $url = '/api/businesses/'.$a['business_public_id'].'/grants';
        $body = ['command_id' => (string) Str::uuid(), 'authority_selection' => $this->selection($root->id), 'effect' => 'issue',
            'grantee_public_id' => $other->public_id, 'action' => 'business.context.read', 'parent_grant_id' => null, 'grant_id' => null,
            'expected_grant' => 0, 'expires_at' => null, 'delegable' => false, 'realm_workspace_public_id' => null];
        $this->postJson($url, $body)->assertUnprocessable();
        $body['parent_grant_id'] = $root->id;
        $issued = $this->postJson($url, $body)->assertOk()->assertJsonPath('data.status', 'active')->json('data');
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.grant_id', $issued['grant_id']);
        $body['command_id'] = (string) Str::uuid();
        $body['effect'] = 'revoke';
        $body['parent_grant_id'] = null;
        $body['grant_id'] = $issued['grant_id'];
        $body['expected_grant'] = 1;
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.status', 'revoked')->assertJsonPath('data.versions.grant', 2);
        $this->denied(fn () => app(BusinessScope::class)->read($this->request($other), $a['business_public_id'], $this->selection($issued['grant_id'])));
    }

    public function test_revoked_relationship_qualification_denies_agency_use_even_with_active_leaf_and_team(): void
    {
        $owner = User::factory()->create();
        $a = $this->draft($owner);
        $agent = User::factory()->create();
        $db = DB::connection('pgsql_migrations');
        $b = $db->table('businesses')->where('public_id', $a['business_public_id'])->value('id');
        $readRoot = Fixture::root($b, $owner->id, 'business.context.read');
        $linkRoot = Fixture::root($b, $owner->id, 'business.link.manage');
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($agent, 1);
        $this->request($agent);
        $this->post('/workspaces', ['name' => 'Qualified agency', 'slug' => 'qualified-agency'])->assertRedirect();
        $w = $db->table('workspaces')->where('slug', 'qualified-agency')->first();
        Fixture::insert('business_workspace_links', ['link_id' => (string) Str::uuid7(), 'business_id' => $b, 'workspace_id' => $w->id,
            'kind' => 'agency', 'status' => 'active', 'revision' => 1, 'valid_from' => now()->toIso8601String(), 'valid_until' => null, 'authority_ref' => $linkRoot->qualification_id], $owner->id);
        $g = $this->command($this->request($owner), $a['business_public_id'], $agent, $readRoot->id, agency: $w->public_id);
        $this->assertSame('Independent customer', app(BusinessScope::class)->read($this->request($agent), $a['business_public_id'], $this->selection($g['grant_id'], $w->public_id))['name']);
        Fixture::revokeQualification($linkRoot->qualification_id, $owner->id);
        $count = $db->table('security_action_grants')->count();
        $this->denied(fn () => $this->command($this->request($owner), $a['business_public_id'], $agent, $readRoot->id, agency: $w->public_id));
        $this->assertSame($count, $db->table('security_action_grants')->count());
        $this->denied(fn () => app(BusinessScope::class)->read($this->request($agent), $a['business_public_id'], $this->selection($g['grant_id'], $w->public_id)));
        $this->assertSame('active', $db->table('security_action_grants')->where('id', $g['grant_id'])->value('status'));
        $this->assertSame('active', $db->table('workspace_memberships')->where('workspace_id', $w->id)->where('user_id', $agent->id)->value('status'));
    }

    public function test_sixteen_node_chain_is_usable_but_issuing_a_seventeenth_node_has_no_effect(): void
    {
        $owner = User::factory()->create();
        $a = $this->draft($owner);
        $db = DB::connection('pgsql_migrations');
        $b = $db->table('businesses')->where('public_id', $a['business_public_id'])->value('id');
        $parent = Fixture::root($b, $owner->id, 'business.context.read');
        $issuer = $owner;
        for ($i = 1; $i < 16; $i++) {
            $subject = User::factory()->create();
            $q = Fixture::insert('authority_qualification_receipts', ['qualification_id' => (string) Str::uuid7(), 'policy_id' => 'test.synthetic.chain', 'policy_version' => 1,
                'issuer_kind' => 'existing_grant', 'issuer_grant_id' => $parent->id, 'subject_user_id' => $subject->id, 'target_kind' => 'business', 'target_ref' => $a['business_public_id'],
                'allowed_actions' => '{business.context.read}', 'evidence_ref' => 'test:bounded-chain', 'evidence_digest' => '\\x'.hash('sha256', 'test:bounded-chain'), 'status' => 'active', 'revision' => 1,
                'valid_from' => now()->subSecond()->toIso8601String(), 'valid_until' => null, 'provenance' => 'SYNTHETIC CHAIN EDGE; not production qualification'], $owner->id);
            $parent = Fixture::insert('security_action_grants', ['id' => (int) $db->scalar("SELECT nextval('security_action_grants_id_seq')"), 'subject_user_id' => $subject->id,
                'granted_by' => $issuer->id, 'source' => 'qualified_delegation', 'action' => 'business.context.read', 'scope_kind' => 'business', 'business_id' => $b, 'subject_generation' => 1,
                'reason' => 'SYNTHETIC CHAIN', 'status' => 'active', 'version' => 1, 'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(), 'runtime_lock_marker' => false,
                'qualification_id' => $q->qualification_id, 'parent_grant_id' => $parent->id, 'delegable' => true, 'delegation_realm_kind' => 'direct', 'delegation_workspace_id' => null], $owner->id);
            $issuer = $subject;
        }
        $this->assertSame('Independent customer', app(BusinessScope::class)->read($this->request($issuer), $a['business_public_id'], $this->selection($parent->id))['name']);
        $target = User::factory()->create();
        $count = $db->table('security_action_grants')->count();
        $this->denied(fn () => $this->command($this->request($issuer), $a['business_public_id'], $target, $parent->id));
        $this->assertSame($count, $db->table('security_action_grants')->count());
    }

    public function test_future_agency_relationship_cannot_issue_or_enable_a_current_grant(): void
    {
        $owner = User::factory()->create();
        $a = $this->draft($owner);
        $agent = User::factory()->create();
        $db = DB::connection('pgsql_migrations');
        $b = $db->table('businesses')->where('public_id', $a['business_public_id'])->value('id');
        $readRoot = Fixture::root($b, $owner->id, 'business.context.read');
        $linkRoot = Fixture::root($b, $owner->id, 'business.link.manage');
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($agent, 1);
        $this->request($agent);
        $this->post('/workspaces', ['name' => 'Future agency', 'slug' => 'future-agency'])->assertRedirect();
        $w = $db->table('workspaces')->where('slug', 'future-agency')->first();
        Fixture::insert('business_workspace_links', ['link_id' => (string) Str::uuid7(), 'business_id' => $b, 'workspace_id' => $w->id,
            'kind' => 'agency', 'status' => 'active', 'revision' => 1, 'valid_from' => now()->addDay()->toIso8601String(), 'valid_until' => null,
            'authority_ref' => $linkRoot->qualification_id], $owner->id);
        $count = $db->table('security_action_grants')->count();
        $this->denied(fn () => $this->command($this->request($owner), $a['business_public_id'], $agent, $readRoot->id, agency: $w->public_id));
        $this->assertSame($count, $db->table('security_action_grants')->count());
        // Previously recorded grant validity does not shorten the independently scheduled relationship.
        $q = Fixture::insert('authority_qualification_receipts', ['qualification_id' => (string) Str::uuid7(), 'policy_id' => 'test.synthetic.scheduled_relationship', 'policy_version' => 1,
            'issuer_kind' => 'existing_grant', 'issuer_grant_id' => $readRoot->id, 'subject_user_id' => $agent->id, 'target_kind' => 'business', 'target_ref' => $a['business_public_id'],
            'allowed_actions' => '{business.context.read}', 'evidence_ref' => 'test:scheduled-agency', 'evidence_digest' => '\\x'.hash('sha256', 'test:scheduled-agency'),
            'status' => 'active', 'revision' => 1, 'valid_from' => now()->subSecond()->toIso8601String(), 'valid_until' => null, 'provenance' => 'SYNTHETIC TEST ONLY'], $owner->id);
        $grant = Fixture::insert('security_action_grants', ['id' => (int) $db->scalar("SELECT nextval('security_action_grants_id_seq')"), 'subject_user_id' => $agent->id,
            'granted_by' => $owner->id, 'source' => 'qualified_delegation', 'action' => 'business.context.read', 'scope_kind' => 'business', 'business_id' => $b, 'subject_generation' => 1,
            'reason' => 'SYNTHETIC SCHEDULED RELATIONSHIP', 'status' => 'active', 'version' => 1, 'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(), 'runtime_lock_marker' => false,
            'qualification_id' => $q->qualification_id, 'parent_grant_id' => $readRoot->id, 'delegable' => false, 'delegation_realm_kind' => 'agency', 'delegation_workspace_id' => $w->id], $owner->id);
        $this->denied(fn () => app(BusinessScope::class)->read($this->request($agent), $a['business_public_id'], $this->selection($grant->id, $w->public_id)));
        $this->assertSame('Independent customer', app(BusinessScope::class)->read($this->request($owner), $a['business_public_id'], $this->selection($readRoot->id))['name']);
        $this->assertSame('active', $db->table('security_action_grants')->where('id', $grant->id)->value('status'));
    }

    public function test_agency_relationship_is_an_explicit_qualified_command_and_never_a_draft_membership_grant(): void
    {
        $u = User::factory()->create();
        $a = $this->draft($u);
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($u, 1);
        $this->post('/workspaces', ['name' => 'Own agency workspace', 'slug' => 'own-agency'])->assertRedirect();
        $db = DB::connection('pgsql_migrations');
        $w = $db->table('workspaces')->where('slug', 'own-agency')->first();
        $url = '/api/businesses/'.$a['business_public_id'].'/workspace-links';
        $body = ['command_id' => (string) Str::uuid(), 'workspace_public_id' => $w->public_id, 'effect' => 'add', 'kind' => 'agency',
            'expected_revision' => 0, 'authority_selection' => $this->selection($a['grant_refs']['business.link.manage'])];
        $this->postJson($url, $body)->assertForbidden();
        $this->assertSame(0, $db->table('business_workspace_links')->count());
        $b = $db->table('businesses')->where('public_id', $a['business_public_id'])->value('id');
        $root = Fixture::root($b, $u->id, 'business.link.manage');
        $count = $db->table('security_action_grants')->count();
        $body['authority_selection'] = $this->selection($root->id);
        $body['command_id'] = (string) Str::uuid();
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.kind', 'agency')->assertJsonPath('data.state', 'active')->assertJsonPath('data.versions.link', 1);
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.versions.link', 1);
        $this->assertSame($count, $db->table('security_action_grants')->count());
        $this->assertSame(1, $db->table('business_workspace_links')->count());
        $body['command_id'] = (string) Str::uuid();
        $body['expected_revision'] = 1;
        $body['effect'] = 'end';
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.state', 'ended')->assertJsonPath('data.versions.link', 2);
        $this->assertSame($count, $db->table('security_action_grants')->count());
    }
}
