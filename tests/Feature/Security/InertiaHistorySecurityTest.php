<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InertiaHistorySecurityTest extends TestCase
{
    public function test_commercial_and_migrated_documents_have_no_legacy_history_or_private_bootstrap_payload(): void
    {
        $this->get('/internal/login')->assertViewIs('spa')->assertDontSee('data-page=', false);
        $this->get('/login')->assertViewIs('spa')->assertDontSee('data-page=', false);
        $this->actingAs(User::factory()->create())->get('/confirm-password')
            ->assertViewIs('spa')->assertDontSee('data-page=', false);

        $staff = User::factory()->create();
        $db = DB::connection('pgsql_migrations');
        $db->table('principal_lifecycles')->where('user_id', $staff->id)->update(['principal_class' => 'staff']);
        $db->table('internal_personnel')->insert(['user_id' => $staff->id, 'kind' => 'founder', 'status' => 'active',
            'attested_by' => $staff->id, 'attested_at' => now(), 'factor_independence_attested_by' => $staff->id, 'factor_independence_attested_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $db->table('passkeys')->insert(['user_id' => $staff->id, 'name' => 'History fixture', 'credential_id' => 'history-'.$staff->id,
            'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $db->table('passkeys')->insert(['user_id' => $staff->id, 'name' => 'Second synthetic founder factor', 'credential_id' => 'second-legacy-'.$staff->id, 'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $db->table('security_action_grants')->insert(['subject_user_id' => $staff->id, 'source' => 'founder_bootstrap',
            'action' => 'staff.view', 'scope_kind' => 'platform', 'subject_generation' => 1,
            'reason' => 'remaining legacy history fixture', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsInternal($staff)->get('/internal/commercial')
            ->assertViewIs('spa')->assertDontSee('data-page=', false)->assertDontSee($staff->email);
        $this->get('/internal/administration/commercial-markets')
            ->assertViewIs('spa')->assertDontSee('data-page=', false)->assertDontSee($staff->email)
            ->assertDontSee('intentBinding', false)->assertDontSee('recordedMarkets', false);
    }

    public function test_login_replaces_the_guest_document_and_does_not_restore_an_inertia_history_payload(): void
    {
        $user = User::factory()->create();
        $guest = $this->useIdentityDocument();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('transition.epoch', 1);
        $page = $this->get('/confirm-password')->assertViewIs('spa')->assertDontSee('data-page=', false);
        $this->assertSame($user->id, $page->viewData('identity')['binding']['principal']);
        $this->assertNotSame($guest['binding']['generation'], $page->viewData('identity')['binding']['generation']);
    }

    public function test_logout_sets_clear_history_after_invalidating_the_session(): void
    {
        $user = User::factory()->create();
        $this->useIdentityDocument();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->postJson('/logout')->assertOk()->assertJsonPath('redirect', '/')
            ->assertJsonPath('transition.status', 'closed');
        $this->assertGuest();
        $this->get('/')->assertViewIs('welcome')->assertDontSee('data-page=', false)->assertDontSee($user->email);
    }

    public function test_expired_session_clears_history_when_the_server_rejects_it_without_renewing_idle(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $db = DB::connection('pgsql_migrations');
        $db->table('security_sessions')->where('user_id', $user->id)->update(['idle_expires_at' => now()->subMinute()]);
        $deadline = $db->table('security_sessions')->where('user_id', $user->id)->value('idle_expires_at');
        $this->get('/profile')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame($deadline, $db->table('security_sessions')->where('user_id', $user->id)->value('idle_expires_at'));
        $this->get('/login')->assertViewIs('spa')->assertViewHas('identity', fn ($identity) => $identity['binding']['principal'] === 0)
            ->assertDontSee('data-page=', false)->assertDontSee($user->email);
    }
}
