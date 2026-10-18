<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InertiaHistorySecurityTest extends TestCase
{
    public function test_public_and_protected_pages_enable_official_history_encryption(): void
    {
        $this->get('/login')->assertViewHas('page', fn ($page) => $page['encryptHistory'] === true);
        $this->actingAs(User::factory()->create())->get('/confirm-password')
            ->assertViewHas('page', fn ($page) => $page['encryptHistory'] === true);
    }

    public function test_login_clears_prior_history_on_the_next_render_only(): void
    {
        $user = User::factory()->create();
        $this->useIdentityDocument();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('transition.epoch', 1);
        $this->get('/confirm-password')->assertViewHas('page', fn ($page) => $page['clearHistory'] === true);
        $this->get('/confirm-password')->assertViewHas('page', fn ($page) => empty($page['clearHistory']));
    }

    public function test_logout_sets_clear_history_after_invalidating_the_session(): void
    {
        $user = User::factory()->create();
        $this->useIdentityDocument();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->postJson('/logout')->assertOk()->assertJsonPath('redirect', '/')
            ->assertJsonPath('transition.status', 'closed');
        $this->assertGuest();
        $this->get('/')->assertViewHas('page', fn ($page) => $page['clearHistory'] === true && $page['encryptHistory'] === true);
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
        $this->get('/login')->assertViewHas('page', fn ($page) => $page['clearHistory'] === true);
    }
}
