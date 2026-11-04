<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApplicationContractAssertions;
use Tests\TestCase;

final class OrganizationRegistrationTest extends TestCase
{
    use ApplicationContractAssertions;

    public function test_registration_uses_one_organizational_boundary_without_creating_commercial_facts(): void
    {
        $owner = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($owner, 1);
        $this->actingAs($owner);
        $resource = $this->getJson('/organizations/new')->assertOk()->json();
        $this->assertApplicationContract('OrganizationCreationResource', $resource);
        $body = ['name' => 'Regional Agency', 'creation_key' => $resource['data']['creationKey']];
        $result = $this->postJson('/organizations', $body)->assertOk()->json();
        $this->postJson('/organizations', $body)->assertOk()->assertExactJson($result);
        $db = DB::connection('pgsql_migrations');
        $organization = $db->table('workspaces')->sole();
        $this->assertSame('/workspaces/'.$organization->public_id, $result['redirect']);
        $this->assertNull($organization->business_type_id);
        $this->assertNull($organization->current_intent);
        $this->assertSame(0, $db->table('businesses')->count());
        foreach (['business_context_events', 'business_current_intent_events', 'business_target_markets', 'advertiser_goal_preferences', 'object_purposes'] as $table) {
            $this->assertSame(0, $db->table($table)->count(), $table);
        }
        $this->assertSame(1, $db->table('workspace_memberships')->where('role', 'owner')->count());
        $this->assertSame(1, $db->table('workspace_ownerships')->count());
        $page = $this->getJson($result['redirect'])->assertOk()->assertJsonMissingPath('data.business')->assertJsonMissingPath('data.guidance')->json();
        $this->assertApplicationContract('WorkspaceSourcesResource', $page);
        $this->actingAs(User::factory()->create())->getJson($result['redirect'])->assertForbidden();
    }

    public function test_retired_onboarding_cannot_create_a_workspace_or_commercial_facts_even_for_an_authorized_owner(): void
    {
        $owner = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($owner, 1);
        $this->actingAs($owner)->get('/business/start')->assertRedirect('/organizations/new');
        $this->postJson('/business/start', ['name' => 'Old flow', 'business_type_id' => 1,
            'creation_key' => '11111111-1111-4111-8111-111111111111'])->assertStatus(410);
        $db = DB::connection('pgsql_migrations');
        $this->assertSame(0, $db->table('workspaces')->count());
        $this->assertSame(0, $db->table('business_context_events')->count());
        $this->assertSame(0, $db->table('advertiser_goal_preferences')->count());
    }
}
