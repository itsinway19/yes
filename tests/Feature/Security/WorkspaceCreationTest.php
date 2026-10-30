<?php

namespace Tests\Feature\Security;

use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\User;
use App\Security\GrantAuthority;
use App\Security\LifecycleAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkspaceCreationTest extends TestCase
{
    public function test_creation_requires_explicit_grant_and_assigns_one_responsible_owner_atomically(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->post('/workspaces', ['name' => 'North Region', 'slug' => 'north-region'])->assertForbidden();
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('workspaces')->count());

        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($user, 1);
        $this->post('/workspaces', ['name' => 'North Region', 'slug' => 'north-region'])
            ->assertRedirect(route('workspaces.index'));

        $owner = DB::connection('pgsql_migrations');
        $workspace = $owner->table('workspaces')->where('slug', 'north-region')->first();
        $this->assertNotNull($workspace);
        $this->assertDatabaseHas('workspace_memberships', [
            'workspace_id' => $workspace->id, 'user_id' => $user->id,
            'role' => 'owner', 'status' => 'active',
        ], 'pgsql_migrations');
        $this->assertDatabaseHas('workspace_ownerships', [
            'workspace_id' => $workspace->id,
            'responsible_owner_user_id' => $user->id,
            'version' => 1,
        ], 'pgsql_migrations');
        $this->assertDatabaseHas('security_action_grants', [
            'subject_user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'action' => 'workspace.view',
            'scope_kind' => 'workspace',
            'source' => 'system_workspace_creation',
            'status' => 'active',
        ], 'pgsql_migrations');
        $this->assertSame(0, DB::table('security_action_grants')->where('workspace_id', $workspace->id)->count(), 'Creation does not expose raw grants without a signed scope.');
        $this->post('/workspaces', ['name' => 'North Region', 'slug' => 'north-region'])
            ->assertRedirect(route('workspaces.index'));
        $this->assertSame(1, $owner->table('workspaces')->count());
        $this->assertSame(1, $owner->table('workspace_ownerships')->count());
        $this->assertSame(1, $owner->table('security_action_grants')
            ->where('action', 'workspace.view')->count());
        $this->assertSame(1, $owner->table('security_audit_events')
            ->where('event', 'workspace_created')->count());

        $this->withCredentials()->getJson('/workspaces/'.$workspace->public_id.'/foundation')->assertOk();
        $this->get('/workspaces/'.$workspace->public_id)->assertRedirect(route('workspaces.business.edit', $workspace->public_id));
        $this->getJson('/workspaces')->assertOk()
            ->assertJsonCount(1, 'data.workspaces')
            ->assertJsonPath('data.workspaces.0.public_id', $workspace->public_id);

        $owner->table('security_action_grants')->where('workspace_id', $workspace->id)
            ->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->get('/workspaces/'.$workspace->public_id)->assertForbidden();
        $this->post('/workspaces', ['name' => 'North Region', 'slug' => 'north-region'])->assertForbidden();
        $this->getJson('/workspaces')->assertOk()->assertJsonCount(0, 'data.workspaces');

        $other = User::factory()->create();
        $this->actingAs($other)->withCredentials()
            ->getJson('/workspaces/'.$workspace->public_id.'/foundation')->assertForbidden();
        $this->get('/workspaces/'.$workspace->public_id)->assertForbidden();
    }

    public function test_runtime_role_cannot_write_workspace_tables_directly(): void
    {
        $this->expectException(QueryException::class);
        DB::table('workspaces')->insert([
            'name' => 'Bypass', 'slug' => 'bypass', 'status' => 'active',
            'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_revoked_grant_denies_creation_without_partial_rows(): void
    {
        $user = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($user, 1);
        $this->actingAs($user);
        DB::connection('pgsql_migrations')->table('security_action_grants')->where('subject_user_id', $user->id)
            ->update(['status' => 'revoked', 'revoked_at' => now()]);

        $this->post('/workspaces', ['name' => 'Denied', 'slug' => 'denied'])->assertForbidden();
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('workspaces')->count());
    }

    public function test_grant_failure_rolls_back_workspace_owner_membership_and_audit(): void
    {
        $user = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($user, 1);
        $this->actingAs($user);
        $owner = DB::connection('pgsql_migrations');
        $owner->statement(<<<'SQL'
            CREATE FUNCTION reject_test_workspace_grant() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.source = 'system_workspace_creation' THEN
                    RAISE EXCEPTION 'Simulated grant writer failure';
                END IF;
                RETURN NEW;
            END $$
            SQL);
        $owner->statement('CREATE TRIGGER reject_test_workspace_grant BEFORE INSERT ON security_action_grants FOR EACH ROW EXECUTE FUNCTION reject_test_workspace_grant()');
        try {
            $this->post('/workspaces', ['name' => 'Atomic', 'slug' => 'atomic'])->assertInternalServerError();
        } finally {
            $owner->statement('DROP TRIGGER reject_test_workspace_grant ON security_action_grants');
            $owner->statement('DROP FUNCTION reject_test_workspace_grant()');
        }

        $this->assertSame(0, $owner->table('workspaces')->count());
        $this->assertSame(0, $owner->table('workspace_memberships')->count());
        $this->assertSame(0, $owner->table('workspace_ownerships')->count());
        $this->assertSame(0, $owner->table('security_audit_events')
            ->where('event', 'workspace_created')->count());
    }

    public function test_in_flight_workspace_effect_and_lifecycle_invalidation_have_one_database_order(): void
    {
        $user = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($user, 1);
        $this->actingAs($user)->post('/workspaces', [
            'name' => 'Ordered Workspace', 'slug' => 'ordered-workspace',
        ])->assertRedirect();
        $workspace = DB::connection('pgsql_migrations')->table('workspaces')->first();
        $request = Request::create('/workspaces/'.$workspace->public_id, 'GET');
        $request->setLaravelSession($this->app['session']->driver());
        $request->setUserResolver(fn () => $user);

        config()->set('database.connections.pgsql_concurrent', config('database.connections.pgsql_authority'));
        $other = DB::connection('pgsql_concurrent');
        try {
            $other->statement("SET lock_timeout = '150ms'");
            app(WorkspaceContext::class)->run($user, $workspace->public_id,
                function () use ($other, $user): void {
                    try {
                        $other->table('principal_lifecycles')->where('user_id', $user->id)
                            ->update(['status' => 'disabled', 'generation' => 2,
                                'cause' => 'concurrent_invalidation']);
                        $this->fail('Lifecycle invalidation must wait for the in-flight effect.');
                    } catch (QueryException $exception) {
                        $this->assertSame('55P03', $exception->getCode());
                    }
                }, $request);
        } finally {
            DB::purge('pgsql_concurrent');
        }

        app(LifecycleAuthority::class)->disable($user, $user->id, 'security_hold');
        $this->get('/workspaces/'.$workspace->public_id)->assertRedirect('/login');
        $this->assertGuest();
    }
}
