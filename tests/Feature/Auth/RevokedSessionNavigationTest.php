<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\EnsureCurrentPrincipal;
use App\Models\User;
use App\Security\SessionAuthority;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class RevokedSessionNavigationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'array']);
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
        $user = new User;
        $user->id = 123;
        $this->actingAs($user)->withSession(['a001_generation' => 1, 'auth.password_confirmed_at' => time()]);
        // This test exercises HTTP/session cleanup only; no preview database is mutated.
        DB::shouldReceive('table')->with('security_sessions')->once()->andReturn(
            $query = \Mockery::mock(Builder::class)
        );
        $query->shouldReceive('where')->twice()->andReturnSelf();
        $query->shouldReceive('sharedLock')->once()->andReturnSelf();
        $query->shouldReceive('first')->once()->andReturn((object) ['revoked_at' => now()]);
    }

    public function test_revoked_browser_session_returns_to_login_and_clears_authentication(): void
    {
        $this->expiredSession();
        $this->get('/session-navigation-probe')->assertRedirect('/login')
            ->assertSessionMissing('a001_generation')
            ->assertSessionMissing('auth.password_confirmed_at')
            ->assertSessionHas('status');
        $this->assertGuest();
        $this->get('/login')->assertOk();
    }

    public function test_json_denial_remains_forbidden_and_clears_authentication(): void
    {
        $this->expiredSession();
        $this->getJson('/session-navigation-probe')->assertForbidden();
        $this->assertGuest();
    }

    public function test_lifecycle_generation_change_returns_stale_browser_to_login(): void
    {
        $user = new User;
        $user->id = 123;
        $user->email_verified_at = now();
        $this->actingAs($user)->withSession(['a001_generation' => 2]);

        DB::shouldReceive('transaction')->once()->andReturnUsing(fn ($callback) => $callback());
        DB::shouldReceive('table')->with('principal_lifecycles')->twice()->andReturn(
            $current = \Mockery::mock(Builder::class),
            $generation = \Mockery::mock(Builder::class),
        );
        $current->shouldReceive('where')->times(3)->andReturnSelf();
        $current->shouldReceive('sharedLock')->once()->andReturnSelf();
        $current->shouldReceive('first')->once()->andReturnNull();
        $generation->shouldReceive('where')->once()->andReturnSelf();
        $generation->shouldReceive('value')->with('generation')->once()->andReturn(3);

        $this->get('/generation-navigation-probe')->assertRedirect('/login')
            ->assertSessionMissing('a001_generation');
        $this->assertGuest();
    }

    public function test_ordinary_permission_denial_does_not_log_out_user(): void
    {
        $user = new User;
        $user->id = 123;
        $this->actingAs($user)->get('/permission-navigation-probe')->assertForbidden();
        $this->assertAuthenticated();
    }
}
