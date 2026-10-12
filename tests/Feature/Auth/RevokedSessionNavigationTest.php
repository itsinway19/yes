<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\EnsureCurrentPrincipal;
use App\Models\User;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RevokedSessionNavigationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'redis']);
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->withoutVite();
        Route::middleware('web')->get('/session-navigation-probe', function (Request $request) {
            app(SessionAuthority::class)->assertCurrent($request, $request->user(), 1);

            return response('Protected content');
        });
        Route::middleware('web')->get('/permission-navigation-probe', fn () => abort(403));
        Route::middleware(['web', 'auth', EnsureCurrentPrincipal::class])
            ->get('/generation-navigation-probe', fn () => response('Protected content'));
    }

    private function expiredSession(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['a001_generation' => 1, 'auth.password_confirmed_at' => time()]);
        $this->app['session']->driver()->save();
        DB::connection('pgsql_migrations')->table('security_sessions')->where('user_id', $user->id)
            ->update(['revoked_at' => now()]);
    }

    public function test_revoked_browser_session_returns_to_login_and_clears_authentication(): void
    {
        $this->expiredSession();
        $this->get('/session-navigation-probe')->assertRedirect('/login')
            ->assertSessionMissing('a001_generation')
            ->assertSessionMissing('auth.password_confirmed_at');
        $this->assertGuest();
        $this->get('/login')->assertOk();
    }

    public function test_json_denial_is_an_identity_conflict_and_clears_authentication(): void
    {
        $this->expiredSession();
        $this->getJson('/session-navigation-probe')->assertStatus(409)->assertJsonPath('identityConflict', true);
        $this->assertGuest();
    }

    public function test_lifecycle_generation_change_returns_stale_browser_to_login(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        DB::connection('pgsql_migrations')->table('principal_lifecycles')->where('user_id', $user->id)
            ->update(['generation' => 2]);

        $this->get('/generation-navigation-probe')->assertRedirect('/login')
            ->assertSessionMissing('a001_generation');
        $this->assertGuest();
    }

    public function test_ordinary_permission_denial_does_not_log_out_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/permission-navigation-probe')->assertForbidden();
        $this->assertAuthenticated();
    }
}
