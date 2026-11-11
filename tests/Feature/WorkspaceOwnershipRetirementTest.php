<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BusinessAuthorityFixture;
use Tests\TestCase;

final class WorkspaceOwnershipRetirementTest extends TestCase
{
    public function test_advertiser_browser_has_one_business_entry_and_no_workspace_or_archive_pages(): void
    {
        $this->actingAs(User::factory()->create());
        $id = (string) Str::uuid7();
        foreach (['/workspaces', '/workspaces/'.$id, '/workspaces/'.$id.'/commercial-history', '/workspaces/'.$id.'/purpose-history', '/workspaces/'.$id.'/providers/google'] as $path) {
            $this->get($path)->assertRedirect('/businesses');
        }
        $this->getJson('/businesses')->assertOk()->assertJsonMissingPath('data.legacy_resources_available');
    }

    public function test_new_business_has_only_business_responsibilities_and_no_workspace_discovery(): void
    {
        $this->actingAs(User::factory()->create());
        $business = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Clean Business'])->assertCreated()->json('data');
        $this->assertCount(7, $business['grant_refs']);
        $this->assertArrayHasKey('business.link.manage', $business['grant_refs']);
        $surface = $this->getJson('/api/businesses/'.$business['business_public_id'].'/surface?access='.$business['grant_refs']['business.context.read'])->assertOk()
            ->assertJsonMissingPath('data.collaboration');
        $this->assertSame([(string) $business['grant_refs']['business.link.manage']], array_column($surface->json('data.data_use.binding_options'), 'grant_id'));
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('workspaces')->count());
    }

    public function test_public_creation_is_retired_even_with_a_historical_creation_grant_and_creates_nothing(): void
    {
        $owner = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($owner, 1);
        $this->actingAs($owner);
        foreach (['/workspaces', '/organizations'] as $path) {
            $this->postJson($path, ['name' => 'Parallel agency', 'slug' => 'parallel-agency', 'creation_key' => (string) Str::uuid()])
                ->assertStatus(410)->assertJsonPath('error', 'workspace_creation_retired')->assertJsonPath('next', '/businesses/new');
        }
        $this->get('/organizations/new')->assertRedirect('/businesses/new');
        $this->get('/business/start')->assertRedirect('/businesses/new');
        $this->getJson('/workspaces')->assertOk()->assertJsonPath('data.canCreateWorkspace', false);
        $this->getJson('/businesses')->assertOk()->assertJsonMissingPath('data.legacy_resources_available');
        $db = DB::connection('pgsql_migrations');
        foreach (['workspaces', 'workspace_memberships', 'workspace_ownerships', 'businesses'] as $table) {
            $this->assertSame(0, $db->table($table)->count(), $table.' must not be created implicitly.');
        }
    }

    public function test_retirement_preserves_qualified_legacy_resources_and_denies_an_unrelated_actor(): void
    {
        $owner = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($owner, 1);
        $this->actingAs($owner)->legacyWorkspace(['name' => 'Original resource custody', 'slug' => 'original-resource'])->assertRedirect();
        $db = DB::connection('pgsql_migrations');
        $workspace = $db->table('workspaces')->sole();
        $before = $db->scalar('SELECT to_jsonb(w)::text FROM workspaces w WHERE id=?', [$workspace->id]);
        $this->getJson('/workspaces')->assertOk()->assertJsonPath('data.canCreateWorkspace', false)->assertJsonCount(1, 'data.workspaces');
        $this->getJson('/workspaces/'.$workspace->public_id)->assertOk();
        $this->getJson('/businesses')->assertOk()->assertJsonMissingPath('data.legacy_resources_available');
        $this->assertSame($before, $db->scalar('SELECT to_jsonb(w)::text FROM workspaces w WHERE id=?', [$workspace->id]));
        $this->actingAs(User::factory()->create())->getJson('/workspaces/'.$workspace->public_id)->assertForbidden();
    }

    public function test_new_workspace_agency_links_are_retired_at_the_database_writer_even_for_qualified_owners(): void
    {
        $owner = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($owner, 1);
        $this->actingAs($owner)->legacyWorkspace(['name' => 'Previous custody', 'slug' => 'previous-custody'])->assertRedirect();
        $b = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Independent shop'])->assertCreated()->json('data');
        $db = DB::connection('pgsql_migrations');
        $workspace = $db->table('workspaces')->sole();
        $business = $db->table('businesses')->where('public_id', $b['business_public_id'])->value('id');
        $root = BusinessAuthorityFixture::root($business, $owner->id, 'business.link.manage');
        $selection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $root->id]]];
        foreach (['agency', 'collaboration'] as $kind) {
            $this->postJson('/api/businesses/'.$b['business_public_id'].'/workspace-links', ['command_id' => (string) Str::uuid(), 'workspace_public_id' => $workspace->public_id, 'kind' => $kind, 'effect' => 'add', 'expected_revision' => 0, 'authority_selection' => $selection])->assertForbidden();
        }
        $this->assertSame(0, $db->table('business_workspace_links')->where('kind', 'agency')->count());
        $this->assertSame(1, $db->table('workspaces')->count());
    }
}
