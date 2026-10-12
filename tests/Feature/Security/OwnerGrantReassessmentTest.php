<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Security\GrantAuthority;
use App\Security\LifecycleAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OwnerGrantReassessmentTest extends TestCase
{
    public function test_reverified_owner_must_explicitly_reassess_prior_grants_after_new_authentication(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->ownerWorkspace();
        $oldSessionId = DB::table('security_sessions')->where('user_id', $owner->id)
            ->whereNull('revoked_at')->value('id');

        $this->patch('/profile', [
            'name' => $owner->name, 'email' => 'new-owner@example.test',
        ])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNotNull(DB::table('security_sessions')->where('id', $oldSessionId)->value('revoked_at'));
        $this->assertSame('pending', DB::table('principal_lifecycles')
            ->where('user_id', $owner->id)->value('status'));

        $this->useIdentityDocument();
        $this->post('/login', ['email' => 'new-owner@example.test', 'password' => 'password'])
            ->assertOk()->assertJsonPath('redirect', '/dashboard');
        $owner->refresh();
        $this->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $owner->id, 'hash' => sha1($owner->email),
        ]))->assertRedirect();
        $this->useIdentityDocument('/profile');
        $this->assertSame(2, (int) DB::table('principal_lifecycles')
            ->where('user_id', $owner->id)->value('generation'));
        $changedAt = DB::table('security_audit_events')->where('subject_user_id', $owner->id)
            ->where('event', 'primary_email_changed')->value('occurred_at');
        $authenticatedAt = DB::table('security_sessions')->where('user_id', $owner->id)
            ->whereNull('revoked_at')->value('authenticated_at');
        $this->assertGreaterThanOrEqual($changedAt, $authenticatedAt);
        $this->get('/workspaces/'.$workspace->public_id)->assertForbidden();
        $this->get('/workspaces')->assertInertia(fn (Assert $page) => $page
            ->component('Business/Index')
            ->has('workspaces', 0)
            ->has('reassessableWorkspaces', 1)
            ->where('reassessableWorkspaces.0.public_id', $workspace->public_id));

        $this->post('/workspaces/'.$workspace->public_id.'/reassess-owner-grant')
            ->assertRedirect(route('workspaces.show', $workspace->public_id));
        $this->get('/workspaces/'.$workspace->public_id)->assertOk();
        $this->assertSame(2, DB::table('security_action_grants')
            ->where('workspace_id', $workspace->id)
            ->where('subject_generation', 2)
            ->where('source', 'system_owner_reassessment')->count());
        $this->post('/workspaces/'.$workspace->public_id.'/reassess-owner-grant')->assertRedirect();
        $this->assertSame(2, DB::table('security_action_grants')
            ->where('workspace_id', $workspace->id)
            ->where('subject_generation', 2)->count());
        $this->assertSame(2, DB::table('security_audit_events')
            ->where('workspace_id', $workspace->id)
            ->where('event', 'workspace_owner_grant_reassessed')->count());
    }

    public function test_revoked_prior_grants_and_stale_ownership_never_reappear(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->ownerWorkspace();
        DB::connection('pgsql_migrations')->table('security_action_grants')->where('workspace_id', $workspace->id)
            ->where('action', 'service.scope')
            ->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->reverifyNewEmail($owner);
        $this->post('/workspaces/'.$workspace->public_id.'/reassess-owner-grant')->assertRedirect();
        $this->assertDatabaseHas('security_action_grants', [
            'workspace_id' => $workspace->id, 'subject_generation' => 2,
            'action' => 'workspace.view', 'status' => 'active',
        ]);
        $this->assertSame(0, DB::table('security_action_grants')
            ->where('workspace_id', $workspace->id)
            ->where('subject_generation', 2)->where('action', 'service.scope')->count());

        [$otherOwner, $otherWorkspace] = $this->ownerWorkspace('Other Workspace', 'other-workspace');
        DB::connection('pgsql_migrations')->table('security_action_grants')->where('workspace_id', $otherWorkspace->id)
            ->where('action', 'workspace.view')
            ->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->actingAs($otherOwner);
        $this->reverifyNewEmail($otherOwner, 'other-new@example.test');
        $this->post('/workspaces/'.$otherWorkspace->public_id.'/reassess-owner-grant')->assertForbidden();
        $this->assertSame(0, DB::table('security_action_grants')
            ->where('workspace_id', $otherWorkspace->id)
            ->where('subject_generation', 2)->count());

        DB::connection('pgsql_migrations')->table('workspace_ownerships')
            ->where('workspace_id', $workspace->id)
            ->update(['responsible_owner_user_id' => $otherOwner->id, 'version' => 2]);
        $this->post('/logout')->assertOk()->assertJsonPath('redirect', '/')
            ->assertJsonPath('transition.status', 'closed');
        $this->useIdentityDocument();
        $this->post('/login', ['email' => $owner->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('redirect', '/dashboard');
        $this->post('/workspaces/'.$workspace->public_id.'/reassess-owner-grant')
            ->assertForbidden();
    }

    public function test_reassessment_requires_fresh_authentication_and_denies_disabled_lifecycle(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->ownerWorkspace();
        $this->reverifyNewEmail($owner);
        DB::connection('pgsql_migrations')->table('security_sessions')->where('user_id', $owner->id)
            ->whereNull('revoked_at')->update(['authenticated_at' => now()->subMinutes(16)]);

        $this->post('/workspaces/'.$workspace->public_id.'/reassess-owner-grant')
            ->assertRedirect(route('password.confirm'));
        $this->post('/confirm-password', ['password' => 'password'])->assertRedirect();
        $this->post('/workspaces/'.$workspace->public_id.'/reassess-owner-grant')
            ->assertRedirect(route('workspaces.show', $workspace->public_id));

        app(LifecycleAuthority::class)->disable($owner, $owner->id, 'security_hold');
        $this->post('/workspaces/'.$workspace->public_id.'/reassess-owner-grant')
            ->assertStatus(409)->assertJsonPath('identityConflict', true);
        $this->assertGuest();
    }

    public function test_reassessment_cannot_skip_an_intervening_lifecycle_generation(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->ownerWorkspace();
        $this->reverifyNewEmail($owner);
        $this->reverifyNewEmail($owner, 'third-owner@example.test');

        $this->assertSame(3, (int) DB::table('principal_lifecycles')
            ->where('user_id', $owner->id)->value('generation'));
        $this->get('/workspaces')->assertInertia(fn (Assert $page) => $page
            ->component('Business/Index')
            ->has('reassessableWorkspaces', 0));
        $this->post('/workspaces/'.$workspace->public_id.'/reassess-owner-grant')
            ->assertForbidden();
        $this->assertSame(0, DB::table('security_action_grants')
            ->where('workspace_id', $workspace->id)
            ->where('subject_generation', 3)->count());
    }

    /** @return array{User, object} */
    private function ownerWorkspace(string $name = 'Owner Workspace', string $slug = 'owner-workspace'): array
    {
        $owner = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($owner, 1);
        $this->actingAs($owner)->post('/workspaces', [
            'name' => $name, 'slug' => $slug,
        ])->assertRedirect();
        $workspace = DB::connection('pgsql_migrations')->table('workspaces')
            ->where('slug', $slug)->first();
        // The existing Business prerequisite applies after authority reassessment too.
        DB::connection('pgsql_migrations')->table('workspaces')->where('id', $workspace->id)
            ->update(['business_type_id' => DB::connection('pgsql_migrations')->table('business_types')
                ->where('key', 'real_estate')->value('id')]);

        return [$owner, $workspace];
    }

    private function reverifyNewEmail(User $owner, string $email = 'new-owner@example.test'): void
    {
        $this->patch('/profile', ['name' => $owner->name, 'email' => $email])
            ->assertRedirect(route('login'));
        $this->useIdentityDocument();
        $this->post('/login', ['email' => $email, 'password' => 'password'])->assertOk()
            ->assertJsonPath('redirect', '/dashboard');
        $owner->refresh();
        $this->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $owner->id, 'hash' => sha1($owner->email),
        ]))->assertRedirect();
        $this->useIdentityDocument('/profile');
    }
}
