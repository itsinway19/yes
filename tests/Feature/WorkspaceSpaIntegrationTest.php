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

    public function test_individual_advertiser_workspace_creation_is_discoverable_only_with_its_existing_grant(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $this->getJson('/workspaces')->assertOk()->assertJsonPath('data.canCreateWorkspace', false);
        $this->getJson('/business/start?new=1')->assertForbidden();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $resource = $this->getJson('/workspaces')->assertOk()->assertJsonPath('data.canCreateWorkspace', true);
        $this->assertApplicationContract('WorkspaceIndexResource', $resource->json());
        $this->getJson('/business/start?new=1')->assertOk()->assertJsonPath('data.canManage', true);
        $owner = DB::connection('pgsql_migrations');
        $this->assertSame(0, $owner->table('workspaces')->count());
        $this->assertSame(0, $owner->table('businesses')->count());
        $owner->table('security_action_grants')->where('subject_user_id', $actor->id)
            ->where('action', 'workspace.create')->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->getJson('/workspaces')->assertOk()->assertJsonPath('data.canCreateWorkspace', false);
        $this->getJson('/business/start?new=1')->assertForbidden();
        $this->post('/workspaces', ['name' => 'Denied workspace', 'slug' => 'denied-workspace'])->assertForbidden();
        $this->assertSame(0, $owner->table('workspaces')->count());
    }

    public function test_business_context_shell_resource_commands_and_passive_reads_preserve_existing_authority(): void
    {
        $actor = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $this->actingAs($actor)->post('/workspaces', ['name' => 'Context SPA fixture', 'slug' => 'context-spa'])->assertRedirect();
        $owner = DB::connection('pgsql_migrations');
        $workspace = $owner->table('workspaces')->where('slug', 'context-spa')->sole();
        $type = $owner->table('business_types')->where('key', 'real_estate')->value('id');
        $owner->table('business_types')->where('id', $type)->update(['active' => true]);
        $path = '/workspaces/'.$workspace->public_id.'/business';
        $this->get($path)->assertOk()->assertSee('application-bootstrap', false)->assertDontSee('data-page=', false)->assertDontSee('Context SPA fixture');
        $initial = $this->getJson($path)->assertOk()->assertJsonCount(249, 'data.countryOptions')->json('data');
        $this->assertTrue($initial['canManage']);
        $this->postJson($path, ['name' => 'Context SPA saved', 'business_type_id' => $type, 'version' => 0])
            ->assertOk()->assertJsonPath('redirect', '/workspaces/'.$workspace->public_id);
        $this->postJson($path.'/target-markets', ['country_codes' => ['AE', 'SA'], 'version' => 1])->assertOk()->assertJsonPath('redirect', $path);
        $this->getJson($path)->assertOk()->assertJsonPath('data.workspace.name', 'Context SPA saved')
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.business.targetMarkets.0.code', 'AE');
        $this->postJson($path.'/target-markets', ['country_codes' => ['KW'], 'version' => 1])->assertUnprocessable()->assertJsonValidationErrors('version');
        $before = $owner->table('security_sessions')->where('user_id', $actor->id)->sole();
        $this->travel(30)->seconds();
        $this->getJson($path, ['X-Builderad-Passive' => '1'])->assertOk();
        $this->getJson($path, ['X-Builderad-Witness' => '1'])->assertOk()->assertJsonMissingPath('data');
        $after = $owner->table('security_sessions')->where('id', $before->id)->sole();
        $this->assertSame($before->idle_expires_at, $after->idle_expires_at);
        $this->assertSame($before->absolute_expires_at, $after->absolute_expires_at);
        $owner->table('security_action_grants')->where('subject_user_id', $actor->id)->where('workspace_id', $workspace->id)
            ->where('action', 'business.context.manage')->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->getJson($path)->assertOk()->assertJsonPath('data.canManage', false);
        $this->postJson($path, ['name' => 'Forbidden replacement', 'business_type_id' => $type, 'version' => 2])->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson($path)->assertForbidden();
        $this->getJson('/workspaces/not-a-uuid/business')->assertNotFound();
    }

    public function test_creation_uses_the_existing_atomic_idempotent_writer_and_private_shell(): void
    {
        $user = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($user, 1);
        $this->actingAs($user);
        $owner = DB::connection('pgsql_migrations');
        $type = $owner->table('business_types')->where('key', 'real_estate')->value('id');
        $owner->table('business_types')->where('id', $type)->update(['active' => true]);
        $this->get('/business/start')->assertOk()->assertSee('application-bootstrap', false)->assertDontSee('data-page=', false);
        $projection = $this->getJson('/business/start')->assertOk()->json('data');
        $command = ['name' => 'Private SPA business', 'business_type_id' => $type, 'creation_key' => $projection['creationKey']];
        $this->postJson('/business/start', [...$command, 'business_type_id' => 999999])->assertUnprocessable();
        $this->assertSame(0, $owner->table('workspaces')->count());
        $first = $this->postJson('/business/start', $command)->assertOk()->json();
        $this->assertSame($first, $this->postJson('/business/start', $command)->assertOk()->json());
        $workspace = $owner->table('workspaces')->sole();
        $this->assertSame('/workspaces/'.$workspace->public_id.'/goals', $first['redirect']);
        $this->assertSame(1, $owner->table('workspaces')->count());
        $this->assertSame(1, $owner->table('business_context_events')->count());
        $this->assertSame($type, $workspace->business_type_id);
        $this->getJson('/business/start')->assertRedirect('/workspaces');
        $this->getJson('/business/start?new=1')->assertOk()->assertJsonPath('data.canManage', true);
        $this->get('/workspaces')->assertOk()->assertSee('application-spa', false)->assertDontSee($workspace->name);
        $this->getJson('/workspaces')->assertOk()->assertJsonPath('data.workspaces.0.public_id', $workspace->public_id);
        $owner->table('security_action_grants')->where('subject_user_id', $user->id)
            ->where('action', 'workspace.create')->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->postJson('/business/start', $command)->assertForbidden();
        $this->assertSame(1, $owner->table('workspaces')->count());
        $this->get('/contracts/application.openapi.json')->assertNotFound();
    }

    public function test_creation_grant_withdrawal_cannot_be_bypassed_by_the_spa_contract(): void
    {
        $user = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($user, 1);
        $this->actingAs($user);
        $projection = $this->getJson('/business/start')->assertOk()->json('data');
        $owner = DB::connection('pgsql_migrations');
        $type = $owner->table('business_types')->where('key', 'real_estate')->value('id');
        $owner->table('business_types')->where('id', $type)->update(['active' => true]);
        $owner->table('security_action_grants')->where('subject_user_id', $user->id)
            ->where('action', 'workspace.create')->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->getJson('/business/start')->assertForbidden();
        $this->postJson('/business/start', ['name' => 'Denied creation', 'business_type_id' => $type,
            'creation_key' => $projection['creationKey']])->assertForbidden();
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
        $this->actingAs($actor)->post('/workspaces', ['name' => 'Own visible workspace', 'slug' => 'witness-own'])->assertRedirect();
        $this->actingAs($other)->post('/workspaces', ['name' => 'Foreign private workspace', 'slug' => 'witness-foreign'])->assertRedirect();
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
