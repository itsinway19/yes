<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorkspaceOwnershipRetirementTest extends TestCase
{
    public function test_new_business_uses_business_authority_without_workspace_discovery(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner);
        $business = $this->postJson('/api/businesses', [
            'command_id' => (string) Str::uuid7(), 'name' => 'Clean Business',
        ])->assertCreated()->json('data');
        $this->assertCount(7, $business['grant_refs']);
        $this->assertArrayHasKey('business.link.manage', $business['grant_refs']);
        $path = '/api/businesses/'.$business['business_public_id'].'/surface?access='.$business['grant_refs']['business.context.read'];
        $this->getJson($path)->assertOk()->assertJsonMissingPath('data.collaboration');
        $this->actingAs($other)->getJson($path)->assertForbidden();
        $db = DB::connection('pgsql_migrations');
        $this->assertNull($db->scalar("SELECT to_regclass('public.workspaces')"));
        $this->assertSame(0, $db->table('security_action_grants')->where('scope_kind', 'workspace')->count());
    }

    public function test_retired_creation_and_agency_link_routes_create_no_parallel_resource(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $business = $this->postJson('/api/businesses', [
            'command_id' => (string) Str::uuid7(), 'name' => 'Independent shop',
        ])->assertCreated()->json('data');
        $id = (string) Str::uuid7();
        $this->postJson('/workspaces', ['name' => 'Parallel agency'])->assertNotFound();
        $this->postJson('/api/businesses/'.$business['business_public_id'].'/workspace-links', [
            'command_id' => (string) Str::uuid7(), 'workspace_public_id' => $id,
            'kind' => 'agency', 'effect' => 'add', 'expected_revision' => 0,
        ])->assertNotFound();
        $db = DB::connection('pgsql_migrations');
        $this->assertNull($db->scalar("SELECT to_regclass('public.business_workspace_links')"));
        $this->assertSame(1, $db->table('businesses')->count());
    }
}
