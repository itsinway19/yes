<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ApplicationContractAssertions;
use Tests\TestCase;

final class OrganizationRegistrationTest extends TestCase
{
    use ApplicationContractAssertions;

    public function test_registration_is_retired_and_only_explicit_business_creation_remains(): void
    {
        $owner = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($owner, 1);
        $this->actingAs($owner)->get('/organizations/new')->assertRedirect('/businesses/new');
        $body = ['name' => 'Regional Agency', 'creation_key' => (string) Str::uuid()];
        foreach ([$body, [...$body, 'business_type_id' => 1]] as $command) {
            $this->postJson('/organizations', $command)->assertStatus(410)->assertJsonPath('error', 'workspace_creation_retired');
        }
        $db = DB::connection('pgsql_migrations');
        foreach (['workspaces', 'workspace_memberships', 'workspace_ownerships', 'businesses', 'business_context_events', 'business_current_intent_events', 'business_target_markets', 'advertiser_goal_preferences', 'object_purposes'] as $table) {
            $this->assertSame(0, $db->table($table)->count(), $table);
        }
    }

    public function test_retired_onboarding_cannot_create_a_workspace_or_commercial_facts_even_for_an_authorized_owner(): void
    {
        $owner = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($owner, 1);
        $this->actingAs($owner)->get('/business/start')->assertRedirect('/businesses/new');
        $this->postJson('/business/start', ['name' => 'Old flow', 'business_type_id' => 1,
            'creation_key' => '11111111-1111-4111-8111-111111111111'])->assertStatus(410);
        $db = DB::connection('pgsql_migrations');
        $this->assertSame(0, $db->table('workspaces')->count());
        $this->assertSame(0, $db->table('business_context_events')->count());
        $this->assertSame(0, $db->table('advertiser_goal_preferences')->count());
    }
}
