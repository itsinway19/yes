<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SessionAuthorityTest extends TestCase
{
    public function test_activity_callback_belongs_to_the_connection_that_holds_the_session_proof(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $request = Request::create('/', 'GET');
        $request->setLaravelSession(app('session')->driver());
        $owner = DB::connection('pgsql_migrations');
        $owner->table('security_sessions')->where('user_id', $user->id)
            ->update(['last_seen_at' => now()->subMinute()]);
        DB::transaction(function () use ($request, $user, $owner): void {
            DB::connection('pgsql_authority')->transaction(function () use ($request, $user): void {
                // The current-session proof still locks the default runtime connection.
                app(SessionAuthority::class)->assertCurrent($request, $user, 1);
            });
            $this->assertTrue(Carbon::parse($owner->table('security_sessions')->where('user_id', $user->id)->value('last_seen_at'))->lessThan(now()->subSeconds(30)));
        });
        $this->assertTrue(Carbon::parse($owner->table('security_sessions')->where('user_id', $user->id)->value('last_seen_at'))->greaterThan(now()->subSeconds(5)));
    }

    public function test_login_creates_a_bounded_server_session_and_revocation_denies_access(): void
    {
        $user = User::factory()->create();

        $this->useIdentityDocument();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('redirect', route('dashboard', absolute: false));

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
        $this->get('/security/devices')->assertOk();

        DB::connection('pgsql_migrations')->table('security_sessions')->where('id', $session->id)->update(['revoked_at' => now()]);
        $this->get('/security/devices')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/login')->assertOk();
    }

    public function test_rollback_of_the_proof_discards_activity_even_after_another_connection_commits(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $request = Request::create('/', 'GET');
        $request->setLaravelSession(app('session')->driver());
        $owner = DB::connection('pgsql_migrations');
        $owner->table('security_sessions')->where('user_id', $user->id)->update(['last_seen_at' => now()->subMinute()]);
        $before = $owner->table('security_sessions')->where('user_id', $user->id)->value('last_seen_at');
        DB::beginTransaction();
        try {
            DB::connection('pgsql_authority')->transaction(fn () => app(SessionAuthority::class)->assertCurrent($request, $user, 1));
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $owner->table('security_sessions')->where('user_id', $user->id)->value('last_seen_at'));
    }

    public function test_expired_session_is_denied_even_when_cookie_still_exists(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        DB::connection('pgsql_migrations')->table('security_sessions')->where('user_id', $user->id)->update(['idle_expires_at' => now()->subSecond()]);

        $this->get('/security/devices')->assertRedirect('/login');
        $this->assertGuest();
    }
}
