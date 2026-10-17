<?php

namespace Tests\Feature;

use App\Foundation\Tenancy\PrincipalContext;
use App\Foundation\Tenancy\WorkspaceContext;
use App\Http\Middleware\EnsureCurrentPrincipal;
use App\Models\User;
use App\Security\GrantAuthority;
use App\Security\ServiceWorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CurrentIntentSecurityTest extends TestCase
{
    private function business(): object
    {
        $this->withCredentials();
        $actor = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $this->actingAs($actor)->post('/workspaces', ['name' => 'Intent authority', 'slug' => 'intent-authority'])->assertRedirect();

        return DB::connection('pgsql_migrations')->table('workspaces')->where('slug', 'intent-authority')->first();
    }

    private function command(): array
    {
        return ['command' => 'replace', 'version' => 0, 'value' => ['direction' => 'increase_value', 'scope' => 'business_wide',
            'description' => null, 'outcome' => ['meaning' => 'Bookings', 'clarification' => null, 'count_unit' => null],
            'quantity' => null, 'horizon' => null],
            'confirmations' => ['declaration' => true, 'business_wide' => true, 'outcome' => true, 'acceptance_clear' => true]];
    }

    private function snapshot(object $workspace): array
    {
        $db = DB::connection('pgsql_migrations');

        return [(array) $db->table('workspaces')->find($workspace->id), $db->table('business_current_intent_events')->get()->toArray()];
    }

    private function denied(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Unauthorized Current Intent effect succeeded.');
        } catch (QueryException $error) {
            $this->assertSame('42501', $error->getCode());
        }
    }

    public function test_exactly_authorized_nonowner_manager_and_readonly_owner_reader_foreign_boundaries(): void
    {
        $workspace = $this->business();
        $owner = auth()->user();
        $member = User::factory()->create();
        $db = DB::connection('pgsql_migrations');
        $db->table('workspace_memberships')->insert(['workspace_id' => $workspace->id, 'user_id' => $member->id,
            'role' => 'member', 'status' => 'active', 'created_at' => now()]);
        foreach (['workspace.view', 'business.context.manage'] as $action) {
            $db->table('security_action_grants')->insert(['subject_user_id' => $member->id, 'granted_by' => $owner->id,
                'subject_generation' => 1, 'source' => 'human', 'scope_kind' => 'workspace', 'workspace_id' => $workspace->id,
                'action' => $action, 'reason' => 'explicit_fixture', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
        $url = '/workspaces/'.$workspace->public_id.'/business/current-intent';
        $this->actingAs($member)->postJson($url, $this->command())->assertOk();
        $before = $this->snapshot($workspace);
        $this->assertSame($member->id, (int) $before[1][0]->actor_user_id);
        $db->table('security_action_grants')->where('workspace_id', $workspace->id)->where('action', 'business.context.manage')
            ->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->getJson('/workspaces/'.$workspace->public_id.'/business')->assertOk()->assertJsonPath('data.business.canManage', false)->assertJsonPath('data.business.currentIntent.state', 'PRESENT');
        $this->postJson($url, $this->command())->assertForbidden();
        $this->actingAs($owner)->postJson($url, $this->command())->assertForbidden();
        $this->actingAs(User::factory()->create())->postJson($url, $this->command())->assertForbidden();
        $this->assertEquals($before, $this->snapshot($workspace));
        $this->denied(fn () => DB::statement('UPDATE workspaces SET current_intent=NULL WHERE id=?', [$workspace->id]));
        $this->denied(fn () => DB::scalar("SELECT builderad_current_intent_write(?, ?, NULL, '{\"declaration\":true}',0)", [$workspace->id, str_repeat('f', 64)]));
    }

    public function test_route_is_post_authenticated_current_principal_and_csrf_protected(): void
    {
        $workspace = $this->business();
        $route = app('router')->getRoutes()->getByName('workspaces.business.current-intent.update');
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains(EnsureCurrentPrincipal::class, $route->gatherMiddleware());
        $this->assertContains(PreventRequestForgery::class, app('router')->getMiddlewareGroups()['web']);
        $before = $this->snapshot($workspace);
        try {
            $this->app->detectEnvironment(fn () => 'local');
            // The admitted fixture owns a valid document CSRF token; explicitly
            // remove it to exercise rejection before the protected effect.
            $this->withoutHeader('X-CSRF-TOKEN');
            $this->postJson('/workspaces/'.$workspace->public_id.'/business/current-intent', $this->command())->assertStatus(419);
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
        $this->assertEquals($before, $this->snapshot($workspace));
        $this->withHeader('X-CSRF-TOKEN', $this->app['session']->driver()->token());
        $this->postJson('/logout')->assertOk()->assertJsonPath('transition.status', 'closed');
        $this->useIdentityDocument('/login');
        $this->postJson('/workspaces/'.$workspace->public_id.'/business/current-intent', $this->command())->assertUnauthorized();
        $this->assertEquals($before, $this->snapshot($workspace));
    }

    public function test_revoked_session_cannot_write_even_when_browser_cookie_remains(): void
    {
        $workspace = $this->business();
        $before = $this->snapshot($workspace);
        DB::connection('pgsql_migrations')->table('security_sessions')->where('user_id', auth()->id())->update(['revoked_at' => now()]);
        $this->postJson('/workspaces/'.$workspace->public_id.'/business/current-intent', $this->command())->assertStatus(409)->assertJsonPath('identityConflict', true);
        $this->assertGuest();
        $this->assertEquals($before, $this->snapshot($workspace));
    }

    public function test_scoped_service_authority_cannot_write_intent(): void
    {
        $workspace = $this->business();
        $before = $this->snapshot($workspace);
        $db = DB::connection('pgsql_migrations');
        $credential = bin2hex(random_bytes(32));
        $service = $db->table('service_identities')->insertGetId(['name' => 'Synthetic intent reader', 'status' => 'enabled',
            'credential_hash' => hash('sha256', $credential), 'version' => 1, 'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
        $public = $db->table('service_identities')->where('id', $service)->value('public_id');
        $db->table('service_workspace_scopes')->insert(['service_identity_id' => $service, 'workspace_id' => $workspace->id,
            'authorized_by' => auth()->id(), 'purpose' => 'synthetic_metadata_read', 'status' => 'active', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $db->table('service_action_grants')->insert(['service_identity_id' => $service, 'workspace_id' => $workspace->id,
            'granted_by' => auth()->id(), 'action' => 'workspace.view', 'reason' => 'synthetic_metadata_read', 'status' => 'active', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        app(ServiceWorkspaceContext::class)->run($public, $credential, $workspace->public_id, function ($active): void {
            $this->assertSame('service', DB::scalar("SELECT current_setting('app.authorization_phase')"));
            $this->denied(fn () => DB::scalar("SELECT builderad_current_intent_write(?, ?, NULL, '{\"declaration\":true}',0)", [$active->id, str_repeat('f', 64)]));
        });
        $this->assertEquals($before, $this->snapshot($workspace));
    }

    public function test_internal_or_provider_management_does_not_supply_advertiser_authority(): void
    {
        $workspace = $this->business();
        $before = $this->snapshot($workspace);
        $db = DB::connection('pgsql_migrations');
        $staff = User::factory()->create();
        $db->table('principal_lifecycles')->where('user_id', $staff->id)->update(['principal_class' => 'staff']);
        $db->table('internal_personnel')->insert(['user_id' => $staff->id, 'kind' => 'employee', 'status' => 'active',
            'attested_by' => auth()->id(), 'attested_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $db->table('passkeys')->insert(['user_id' => $staff->id, 'name' => 'Synthetic factor', 'credential_id' => 'intent-staff-'.$staff->id,
            'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $db->table('workspace_memberships')->insert(['workspace_id' => $workspace->id, 'user_id' => $staff->id, 'role' => 'member', 'status' => 'active', 'created_at' => now()]);
        foreach ([['business.manage', 'platform'], ['workspace.view', 'workspace']] as [$action, $scope]) {
            $db->table('security_action_grants')->insert(['subject_user_id' => $staff->id, 'granted_by' => auth()->id(), 'source' => 'human',
                'subject_generation' => 1, 'scope_kind' => $scope, 'workspace_id' => $scope === 'workspace' ? $workspace->id : null,
                'action' => $action, 'reason' => 'Synthetic administration', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAsInternal($staff);
        $session = $db->table('security_sessions')->where('user_id', $staff->id)->value('session_key_hash');
        $this->denied(function () use ($staff, $session, $workspace): void {
            app(PrincipalContext::class)->activate($staff);
            DB::scalar("SELECT builderad_current_intent_write(?, ?, NULL, '{\"declaration\":true}',0)", [$workspace->id, $session]);
        });
        $this->assertEquals($before, $this->snapshot($workspace));
    }

    public function test_history_is_scoped_to_the_signed_workspace(): void
    {
        $owner = DB::connection('pgsql_migrations');
        $fixtures = [];
        foreach (['first', 'second'] as $slug) {
            $actor = User::factory()->create();
            app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
            $this->actingAs($actor)->post('/workspaces', ['name' => 'Scoped intent '.$slug, 'slug' => 'intent-'.$slug])->assertRedirect();
            $workspace = $owner->table('workspaces')->where('slug', 'intent-'.$slug)->first();
            $value = json_encode(['direction' => 'preserve_value', 'scope' => 'business_wide', 'description' => null,
                'outcome' => ['meaning' => 'Bookings', 'clarification' => null, 'count_unit' => null], 'quantity' => null, 'horizon' => null], JSON_THROW_ON_ERROR);
            $owner->table('business_current_intent_events')->insert(['workspace_id' => $workspace->id, 'actor_user_id' => $actor->id,
                'before_version' => 0, 'after_version' => 1, 'before_value' => null, 'after_value' => $value]);
            $fixtures[] = [$actor, $workspace];
        }
        [$actor, $workspace] = $fixtures[0];
        $this->actingAs($actor);
        $request = Request::create('/', 'GET');
        $request->setLaravelSession(app('session')->driver());
        $request->setUserResolver(fn () => $actor);
        app(WorkspaceContext::class)->run($actor, $workspace->public_id, function () use ($workspace): void {
            $this->assertSame([(int) $workspace->id], DB::table('business_current_intent_events')->pluck('workspace_id')->all());
        }, $request);
        $this->assertSame(2, $owner->table('business_current_intent_events')->count());
    }

    public function test_journal_runtime_is_select_only_and_forced_rls(): void
    {
        $catalog = DB::connection('pgsql_migrations')->selectOne("SELECT c.relrowsecurity,c.relforcerowsecurity,r.rolname FROM pg_class c JOIN pg_roles r ON r.oid=c.relowner WHERE c.relname='business_current_intent_events'");
        $this->assertNotNull($catalog);
        $this->assertTrue($catalog->relrowsecurity);
        $this->assertTrue($catalog->relforcerowsecurity);
        $this->assertSame('builderad_owner', $catalog->rolname);
        $this->assertSame([], DB::table('business_current_intent_events')->get()->all());
        foreach (['DELETE FROM business_current_intent_events', 'TRUNCATE business_current_intent_events', 'UPDATE business_current_intent_events SET after_version=1'] as $sql) {
            try {
                DB::statement($sql);
                $this->fail('Runtime journal mutation unexpectedly succeeded.');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->getCode());
            }
        }
    }
}
