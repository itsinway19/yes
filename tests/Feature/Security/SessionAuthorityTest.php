<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SessionAuthorityTest extends TestCase
{
    public function test_login_creates_a_bounded_server_session_and_revocation_denies_access(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $session = DB::table('security_sessions')->where('user_id', $user->id)->first();
        $this->assertNotNull($session);
        $this->assertSame(1, (int) $session->lifecycle_generation);
        $this->assertSame(hash('sha256', $this->app['session']->driver()->getId()), $session->session_key_hash);
        $this->assertSame(1, $this->app['session']->get('a001_generation'));
        $this->assertDatabaseHas('security_audit_events', [
            'subject_user_id' => $user->id, 'event' => 'session_issued',
            'reason' => 'password', 'new_status' => 'active',
        ]);
        $this->assertLessThanOrEqual(now()->addHours(24)->addSeconds(2), Carbon::parse($session->absolute_expires_at));
        $this->get('/dashboard')->assertOk();

        DB::connection('pgsql_migrations')->table('security_sessions')->where('id', $session->id)->update(['revoked_at' => now()]);
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/login')->assertOk();
    }

    public function test_expired_session_is_denied_even_when_cookie_still_exists(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        DB::connection('pgsql_migrations')->table('security_sessions')->where('user_id', $user->id)->update(['idle_expires_at' => now()->subSecond()]);

        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }
}
