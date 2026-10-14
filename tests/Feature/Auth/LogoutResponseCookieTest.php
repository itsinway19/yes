<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Security\IdentityCookies;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class LogoutResponseCookieTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'session.driver' => 'redis',
            'session.connection' => 'default',
            'session.advertiser_cookie' => 'proof-advertiser-session',
            'session.internal_cookie' => 'proof-internal-session',
            'session.domain' => null,
        ]);
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $this->withoutVite();
    }

    public static function responses(): array
    {
        return [[200, null], [302, null], [200, '.example.test']];
    }

    #[DataProvider('responses')]
    public function test_logout_removes_only_the_advertiser_session_cookie_after_the_web_stack(int $status, ?string $domain): void
    {
        config(['session.domain' => $domain]);
        $this->actingAs(User::factory()->create());
        // Response-finishing double only. Actual terminal transitions are proved
        // separately on the production route with real browser delivery races.
        Route::middleware('web')->post('/logout', function (Request $request) use ($status) {
            IdentityCookies::own($request, 'terminal');
            $response = response('owned-response', $status)->header('X-Proof', 'unchanged');
            $response->headers->setCookie(new Cookie('proof-locale', 'locale-value', 0, '/', null, false, false, false, 'strict'));
            $response->headers->setCookie(new Cookie('proof-internal-session', 'internal-value', 0, '/internal'));
            $response->headers->setCookie(new Cookie('proof-advertiser-session', 'other-path-value', 0, '/different', config('session.domain')));
            $response->headers->setCookie(new Cookie('proof-advertiser-session', 'other-domain-value', 0, '/', '.other.test'));

            return $response;
        });
        $response = $this->post('/logout');
        $response->assertStatus($status)->assertContent('owned-response')->assertHeader('X-Proof', 'unchanged');
        $cookies = $response->baseResponse->headers->getCookies();
        $target = array_filter($cookies, fn ($cookie) => $cookie->getName() === 'proof-advertiser-session'
            && $cookie->getPath() === '/' && $cookie->getDomain() === $domain);
        $this->assertSame([], $target);
        $this->assertCount(4, $cookies); // Exact authentication and XSRF tuples are suppressed.
        foreach (['proof-locale' => 'locale-value', 'proof-internal-session' => 'internal-value'] as $name => $value) {
            $cookie = collect($cookies)->first(fn ($cookie) => $cookie->getName() === $name);
            $this->assertNotNull($cookie);
            $this->assertSame($value, CookieValuePrefix::remove(app('encrypter')->decrypt($cookie->getValue(), false)));
        }
        $locale = collect($cookies)->first(fn ($cookie) => $cookie->getName() === 'proof-locale');
        $this->assertSame('strict', $locale->getSameSite());
        $this->assertFalse($locale->isHttpOnly());
        $this->assertNull(collect($cookies)->first(fn ($cookie) => $cookie->getName() === 'XSRF-TOKEN'));
        foreach (['/different' => 'other-path-value', '/' => 'other-domain-value'] as $path => $value) {
            $cookie = collect($cookies)->first(fn ($cookie) => $cookie->getName() === 'proof-advertiser-session' && $cookie->getPath() === $path);
            $this->assertSame($value, CookieValuePrefix::remove(app('encrypter')->decrypt($cookie->getValue(), false)));
        }
    }

    public static function failures(): array
    {
        return [[419], [500]];
    }

    #[DataProvider('failures')]
    public function test_rendered_exceptions_do_not_emit_the_advertiser_session_cookie(int $status): void
    {
        $this->actingAs(User::factory()->create());
        Route::middleware('web')->post('/logout', function () use ($status) {
            if ($status === 419) {
                throw new TokenMismatchException;
            }
            throw new \RuntimeException('Synthetic operational failure');
        });
        $response = $this->postJson('/logout');
        $response->assertStatus($status);
        $this->assertFalse(collect($response->baseResponse->headers->getCookies())->contains(fn ($cookie) => $cookie->getName() === 'proof-advertiser-session'));
    }

    public function test_internal_terminal_response_suppresses_its_cookie_and_preserves_unrelated_advertiser_cookie(): void
    {
        $staff = User::factory()->create();
        $owner = DB::connection('pgsql_migrations');
        $owner->table('principal_lifecycles')->where('user_id', $staff->id)->update(['principal_class' => 'staff']);
        $owner->table('internal_personnel')->insert(['user_id' => $staff->id, 'kind' => 'employee',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $owner->table('passkeys')->insert(['user_id' => $staff->id, 'name' => 'Response fixture factor',
            'credential_id' => 'response-factor-'.$staff->id, 'credential' => '{}',
            'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsInternal($staff);
        Route::middleware('web')->post('/internal/logout', function (Request $request) {
            IdentityCookies::own($request, 'terminal');
            $response = response('internal');
            $response->headers->setCookie(new Cookie('proof-advertiser-session', 'unrelated-advertiser', 0, '/'));

            return $response;
        });
        $response = $this->post('/internal/logout')->assertOk();
        $cookies = $response->baseResponse->headers->getCookies();
        $this->assertFalse(collect($cookies)->contains(fn ($cookie) => $cookie->getName() === 'proof-internal-session' && $cookie->getPath() === '/internal'));
        $this->assertTrue(collect($cookies)->contains(fn ($cookie) => $cookie->getName() === 'proof-advertiser-session' && $cookie->getPath() === '/'));
    }

    public function test_ordinary_authenticated_posts_and_reads_do_not_reissue_credentials(): void
    {
        $this->actingAs(User::factory()->create());
        Route::middleware('web')->post('/cookie-scope-probe', fn () => response('ordinary'));
        Route::middleware('web')->get('/logout', fn () => response('reading'));
        foreach ([$this->post('/cookie-scope-probe'), $this->get('/logout')] as $response) {
            $response->assertOk();
            $this->assertFalse(collect($response->baseResponse->headers->getCookies())->contains(fn ($cookie) => $cookie->getName() === 'proof-advertiser-session' && $cookie->getPath() === '/'));
        }
    }
}
