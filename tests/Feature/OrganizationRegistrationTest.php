<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrganizationRegistrationTest extends TestCase
{
    public function test_retired_onboarding_cannot_create_business_or_authority(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['/organizations/new', '/business/start'] as $route) {
            $this->get($route)->assertNotFound();
        }
        foreach (['/organizations', '/business/start'] as $route) {
            $this->postJson($route, ['name' => 'Old flow', 'creation_key' => (string) Str::uuid()])->assertNotFound();
        }
        $db = DB::connection('pgsql_migrations');
        foreach (['workspaces', 'workspace_memberships', 'workspace_ownerships'] as $table) {
            $this->assertFalse($db->getSchemaBuilder()->hasTable($table));
        }
        foreach (['businesses', 'business_context_heads', 'business_goal_preferences', 'object_purposes'] as $table) {
            $this->assertSame(0, $db->table($table)->count());
        }
        $this->postJson('/api/businesses', ['name' => 'Independent Business', 'command_id' => (string) Str::uuid7()])->assertCreated();
        $this->assertSame(1, $db->table('businesses')->count());
    }
}
