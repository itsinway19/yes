<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveLocale;
use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApplicationContractAssertions;
use Tests\TestCase;

final class WorkspaceSpaIntegrationTest extends TestCase
{
    use ApplicationContractAssertions;

    public function test_previous_resources_never_offer_parallel_creation_even_with_an_existing_creation_grant(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $this->getJson('/workspaces')->assertOk()->assertJsonPath('data.canCreateWorkspace', false);
        $this->get('/organizations/new')->assertRedirect('/businesses/new');
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $resource = $this->getJson('/workspaces')->assertOk()->assertJsonPath('data.canCreateWorkspace', false);
        $this->assertApplicationContract('WorkspaceIndexResource', $resource->json());
        $this->get('/organizations/new')->assertRedirect('/businesses/new');
        $owner = DB::connection('pgsql_migrations');
        $this->assertSame(0, $owner->table('workspaces')->count());
        $this->assertSame(0, $owner->table('businesses')->count());
        $owner->table('security_action_grants')->where('subject_user_id', $actor->id)
            ->where('action', 'workspace.create')->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->getJson('/workspaces')->assertOk()->assertJsonPath('data.canCreateWorkspace', false);
        $this->get('/organizations/new')->assertRedirect('/businesses/new');
        $this->legacyWorkspace(['name' => 'Denied workspace', 'slug' => 'denied-workspace'])->assertForbidden();
        $this->assertSame(0, $owner->table('workspaces')->count());
    }

    public function test_retired_historical_html_redirect_and_qualified_json_witness_preserve_authority(): void
    {
        $actor = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $this->actingAs($actor)->legacyWorkspace(['name' => 'Organization SPA fixture', 'slug' => 'context-spa'])->assertRedirect();
        $owner = DB::connection('pgsql_migrations');
        $workspace = $owner->table('workspaces')->where('slug', 'context-spa')->sole();
        $path = '/workspaces/'.$workspace->public_id.'/commercial-history';
        $this->get($path)->assertRedirect('/businesses');
        $initial = $this->getJson($path)->assertOk()->assertJsonPath('data.read_only', true)->assertJsonPath('data.current_business_inferred', false);
        $this->assertApplicationContract('WorkspaceCommercialHistoryResource', $initial->json());
        $before = $owner->table('security_sessions')->where('user_id', $actor->id)->sole();
        $this->travel(30)->seconds();
        $passive = $this->getJson($path, ['X-Builderad-Passive' => '1'])->assertOk();
        $witness = $this->getJson($path, ['X-Builderad-Witness' => '1'])->assertOk()->assertJsonMissingPath('data');
        $this->assertSame($passive->json('visibility'), $witness->json('visibility'));
        $after = $owner->table('security_sessions')->where('id', $before->id)->sole();
        $this->assertSame($before->idle_expires_at, $after->idle_expires_at);
        $this->assertSame($before->absolute_expires_at, $after->absolute_expires_at);
        $owner->table('security_action_grants')->where('subject_user_id', $actor->id)->where('workspace_id', $workspace->id)
            ->where('action', 'workspace.view')->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->getJson($path)->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson($path)->assertForbidden();
        $this->getJson('/workspaces/not-a-uuid/commercial-history')->assertNotFound();
    }

    public function test_creation_routes_retire_while_the_private_legacy_resource_shell_remains(): void
    {
        $user = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($user, 1);
        $this->actingAs($user)->get('/organizations/new')->assertRedirect('/businesses/new');
        $this->postJson('/organizations', ['name' => 'Parallel agency'])->assertStatus(410);
        $this->legacyWorkspace(['name' => 'Existing custody', 'slug' => 'existing-custody'])->assertRedirect();
        $owner = DB::connection('pgsql_migrations');
        $workspace = $owner->table('workspaces')->sole();
        $this->getJson('/workspaces/'.$workspace->public_id)->assertOk()->assertJsonMissingPath('data.business')->assertJsonMissingPath('data.guidance');
        $this->get('/workspaces')->assertRedirect('/businesses');
        $this->get('/workspaces/'.$workspace->public_id)->assertRedirect('/businesses');
        $this->getJson('/workspaces')->assertOk()->assertJsonPath('data.canCreateWorkspace', false)->assertJsonPath('data.workspaces.0.public_id', $workspace->public_id);
        $this->assertSame(0, $owner->table('business_context_events')->count());
        $this->get('/contracts/application.openapi.json')->assertNotFound();
    }

    public function test_creation_grant_withdrawal_cannot_be_bypassed_by_the_spa_contract(): void
    {
        $user = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($user, 1);
        $this->actingAs($user);
        $owner = DB::connection('pgsql_migrations');
        $owner->table('security_action_grants')->where('subject_user_id', $user->id)->where('action', 'workspace.create')->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->get('/organizations/new')->assertRedirect('/businesses/new');
        $this->postJson('/organizations', ['name' => 'Denied creation'])->assertStatus(410);
        $this->postJson('/workspaces', ['name' => 'Denied creation', 'slug' => 'denied-creation'])->assertStatus(410);
        $this->assertSame(0, $owner->table('workspaces')->count());
    }

    public function test_passive_and_witness_reads_preserve_canonical_session_deadlines(): void
    {
        $user = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($user, 1);
        $this->actingAs($user)->getJson('/workspaces')->assertOk();
        $owner = DB::connection('pgsql_migrations');
        $before = $owner->table('security_sessions')->where('user_id', $user->id)->sole();
        $this->travel(30)->seconds();
        $resource = $this->getJson('/workspaces', ['X-Builderad-Passive' => '1'])->assertOk();
        $witness = $this->getJson('/workspaces', ['X-Builderad-Witness' => '1'])->assertOk()
            ->assertJsonPath('visibility.protected', true)->assertJsonMissingPath('data');
        $this->assertSame($resource->json('visibility'), $witness->json('visibility'));
        $this->assertStringContainsString('no-store', $witness->headers->get('Cache-Control'));
        $after = $owner->table('security_sessions')->where('id', $before->id)->sole();
        $this->assertSame($before->idle_expires_at, $after->idle_expires_at);
        $this->assertSame($before->absolute_expires_at, $after->absolute_expires_at);
    }

    public function test_workspace_index_witness_matches_qualified_rows_and_requalifies_after_revocation(): void
    {
        $actor = User::factory()->create();
        $other = User::factory()->create();
        foreach ([$actor, $other] as $user) {
            app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($user, 1);
        }
        $this->actingAs($actor)->legacyWorkspace(['name' => 'Own visible workspace', 'slug' => 'witness-own'])->assertRedirect();
        $this->actingAs($other)->legacyWorkspace(['name' => 'Foreign private workspace', 'slug' => 'witness-foreign'])->assertRedirect();
        $owner = DB::connection('pgsql_migrations');
        $own = $owner->table('workspaces')->where('slug', 'witness-own')->sole();
        $foreign = $owner->table('workspaces')->where('slug', 'witness-foreign')->sole();

        $this->actingAs($actor);
        $resource = $this->getJson('/workspaces')->assertOk()->assertJsonCount(1, 'data.workspaces')
            ->assertJsonPath('data.workspaces.0.public_id', $own->public_id)->json();
        $this->assertNotContains($foreign->public_id, array_column($resource['data']['workspaces'], 'public_id'));
        $witness = $this->getJson('/workspaces', ['X-Builderad-Witness' => '1'])->assertOk()
            ->assertJsonMissingPath('data');
        $this->assertSame($resource['visibility'], $witness->json('visibility'));
        $this->assertStringContainsString('no-store', $witness->headers->get('Cache-Control'));

        $owner->table('security_action_grants')->where('subject_user_id', $actor->id)
            ->where('workspace_id', $own->id)->where('action', 'workspace.view')
            ->update(['status' => 'revoked', 'revoked_at' => now()]);
        $revokedWitness = $this->getJson('/workspaces', ['X-Builderad-Witness' => '1'])->assertOk()->json('visibility');
        $this->assertNotSame($resource['visibility'], $revokedWitness);
        $after = $this->getJson('/workspaces')->assertOk()->assertJsonCount(0, 'data.workspaces');
        $this->assertSame($revokedWitness, $after->json('visibility'));
        $this->getJson('/workspaces/'.$own->public_id)->assertForbidden();
        $this->getJson('/workspaces/'.$foreign->public_id)->assertForbidden();
    }

    public function test_json_locale_acknowledgement_keeps_the_existing_cookie_contract(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/locale', ['locale' => 'en'])->assertOk()
            ->assertJsonPath('locale', 'en')->assertCookie(ResolveLocale::COOKIE, 'en');
        $this->postJson('/locale', ['locale' => 'fr'])->assertUnprocessable()->assertJsonValidationErrors('locale');
    }
}
