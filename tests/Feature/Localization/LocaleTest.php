<?php

namespace Tests\Feature\Localization;

use App\Http\Middleware\ResolveLocale;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Inertia\Inertia;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    public function test_guest_locale_cookie_is_encrypted_host_scoped_and_shared_across_both_login_pages(): void
    {
        $this->useIdentityDocument();
        $this->get('/login')->assertOk()->assertSee('lang="ar" dir="rtl"', false);

        $response = $this->withHeader('Referer', 'http://localhost/login')
            ->post('/locale', ['locale' => 'en'])->assertRedirect('/login');
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === ResolveLocale::COOKIE);
        $this->assertNotNull($cookie);
        $this->assertNotSame('en', $cookie->getValue());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));

        $this->get('/login')->assertOk()->assertSee('lang="en" dir="ltr"', false);
        $this->useIdentityDocument('/internal/login');
        $this->get('/internal/login')->assertOk()->assertSee('lang="en" dir="ltr"', false);
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion()])->get('/internal/login')
            ->assertOk()->assertJsonPath('component', 'Internal/Login')->assertJsonPath('props.locale', 'en');
        $this->flushHeaders();
        $this->useIdentityDocument('/internal/login');

        $this->withHeader('Referer', url('/internal/login'))
            ->post('/internal/locale', ['locale' => 'ar'])->assertRedirect('/internal/login');
        $this->get('/internal/login')->assertOk()->assertSee('lang="ar" dir="rtl"', false);
        $this->useIdentityDocument();
        $this->getJson('/login')->assertOk()->assertJsonPath('data.kind', 'login')->assertJsonPath('locale', 'ar');
    }

    public function test_invalid_or_unreadable_preference_falls_back_and_explicit_unsupported_submission_fails(): void
    {
        $this->withCookie(ResolveLocale::COOKIE, 'fr')
            ->get('/login')->assertOk()->assertSee('lang="ar" dir="rtl"', false);
        $this->withUnencryptedCookie(ResolveLocale::COOKIE, 'not-an-encrypted-cookie')
            ->get('/internal/login')->assertOk()->assertSee('lang="ar" dir="rtl"', false);

        $this->useIdentityDocument();
        $this->postJson('/locale', ['locale' => 'fr'])->assertUnprocessable()->assertJsonValidationErrors('locale');
        $this->useIdentityDocument('/internal/login');
        $this->postJson('/internal/locale', ['locale' => 'fr'])->assertUnprocessable()->assertJsonValidationErrors('locale');
        $this->useIdentityDocument();
        $this->get('/login')->assertOk()->assertSee('lang="ar" dir="rtl"', false);
    }

    public function test_switch_redirects_only_to_same_origin_and_session_context(): void
    {
        $this->useIdentityDocument();
        $this->withHeader('Referer', 'https://attacker.example/steal')
            ->post('/locale', ['locale' => 'en'])->assertRedirect('/login');
        $this->withHeader('Referer', 'http://localhost/internal/login')
            ->post('/locale', ['locale' => 'en'])->assertRedirect('/login');
        $this->useIdentityDocument('/internal/login');
        $this->withHeader('Referer', url('/login'))
            ->post('/internal/locale', ['locale' => 'en'])->assertRedirect('/internal/login');
        $this->withHeader('Referer', url('/internal/login').'?next=1')
            ->post('/internal/locale', ['locale' => 'en'])->assertRedirect('/internal/login?next=1');
        $differentPort = request()->getPort() === 8139 ? 8140 : 8139;
        $this->withHeader('Referer', request()->getScheme().'://'.request()->getHost().':'.$differentPort.'/internal/login?next=1')
            ->post('/internal/locale', ['locale' => 'en'])->assertRedirect('/internal/login');
        $this->useIdentityDocument();
        $this->withHeader('Referer', 'https://attacker.example/steal')
            ->post('/locale', ['locale' => 'fr'])->assertRedirect('/login');
    }

    public function test_locale_cookie_is_secure_on_https_and_session_cookies_keep_context_paths(): void
    {
        $advertiser = config('session.advertiser_cookie');
        $internal = config('session.internal_cookie');
        $this->assertNotSame($advertiser, $internal);

        $advertiserCookies = $this->get('/login')->headers->getCookies();
        $this->assertNotNull(collect($advertiserCookies)->first(fn ($cookie) => $cookie->getName() === $advertiser));
        $this->assertNull(collect($advertiserCookies)->first(fn ($cookie) => $cookie->getName() === $internal));

        $internalCookies = $this->get('/internal/login')->headers->getCookies();
        $internalSession = collect($internalCookies)->first(fn ($cookie) => $cookie->getName() === $internal);
        $this->assertNotNull($internalSession);
        $this->assertSame('/internal', $internalSession->getPath());
        $this->assertNull(collect($internalCookies)->first(fn ($cookie) => $cookie->getName() === $advertiser));

        $this->useIdentityDocument();
        $response = $this->withServerVariables(['HTTPS' => 'on'])
            ->post('https://localhost/locale', ['locale' => 'en']);
        $response->assertRedirect('/login');
        $localeCookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === ResolveLocale::COOKIE);
        $this->assertNotNull($localeCookie);
        $this->assertTrue($localeCookie->isSecure());
    }

    public function test_both_guest_routes_keep_csrf_and_separate_session_tokens(): void
    {
        $this->usePersistentTestSessions();
        $middleware = app('router')->getMiddlewareGroups()['web'];
        $this->assertContains(PreventRequestForgery::class, $middleware);
        $this->assertLessThan(array_search(ResolveLocale::class, $middleware, true),
            array_search(PreventRequestForgery::class, $middleware, true));

        $document = $this->get('/login', ['Accept' => 'text/html'])->assertOk()->viewData('identity');
        $advertiserToken = $this->app['session']->token();
        $this->app->detectEnvironment(fn () => 'local');

        $this->post('/locale', ['locale' => 'en'])->assertStatus(419);
        $this->post('/internal/locale', ['locale' => 'en'])->assertStatus(419);
        $this->post('/internal/locale', ['locale' => 'en', '_token' => $advertiserToken])->assertStatus(419);
        $this->post('/locale', ['locale' => 'en', '_token' => $advertiserToken,
            '_identity_receipt' => $document['receipt']])->assertRedirect('/login');
    }

    public function test_login_validation_and_authentication_errors_follow_effective_locale(): void
    {
        $this->useIdentityDocument();
        $missing = $this->postJson('/login', [])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertStringContainsString('البريد الإلكتروني', $missing->json('errors.email.0'));
        $this->postJson('/login', ['email' => 'unknown@example.com', 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'بيانات الدخول غير صحيحة.');

        $this->useIdentityDocument();
        $this->post('/locale', ['locale' => 'en'])->assertRedirect('/login');
        $missing = $this->postJson('/login', [])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertStringContainsString('email', strtolower($missing->json('errors.email.0')));
        $this->postJson('/login', ['email' => 'unknown@example.com', 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
    }
}
