<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Middleware\AdmitIdentityDocument;
use App\Http\Middleware\CaptureIdentityInput;
use App\Models\User;
use App\Notifications\SecurityResetPassword;
use App\Security\IdentityReceipt;
use App\Security\SessionAuthority;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Process\Process;
use Tests\Support\IdentityTransitionFixture;
use Tests\TestCase;

/** Executes candidate production routes, including Laravel's real CSRF branch. */
class ProductionIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'redis', 'session.connection' => 'default']);
        $this->app->detectEnvironment(fn () => 'local');
        $this->assertFalse($this->app->runningUnitTests(), 'CSRF bypass must be inactive.');
        $this->assertSame(realpath(base_path('app/Http/Controllers/Auth/AuthenticatedSessionController.php')),
            realpath((new \ReflectionClass(AuthenticatedSessionController::class))->getFileName()));
    }

    private function freshGuest(): array
    {
        $response = $this->get('/login', ['Accept' => 'text/html']);
        $response->assertOk();
        $identity = $response->viewData('identity');
        $this->assertSame(0, $identity['binding']['principal']);
        $this->withCookie(config('session.advertiser_cookie'), app('session')->driver()->getId());
        $this->withCookie(config('session.advertiser_cookie').'-lineage', $identity['binding']['lineage']);
        $this->reloadGuard();

        return $identity;
    }

    private function reloadGuard(): void
    {
        Auth::forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
    }

    private function headers(array $identity): array
    {
        return ['Accept' => 'application/json', 'X-CSRF-TOKEN' => $identity['csrf'],
            'X-Builderad-Document' => $identity['receipt'], 'X-Builderad-Transition' => $identity['transition']];
    }

    public function test_installed_route_order_captures_before_csrf_and_admits_before_auth(): void
    {
        $route = Route::getRoutes()->match(Request::create('/profile', 'GET'));
        $ordered = app('router')->gatherRouteMiddleware($route);
        $capture = array_search(CaptureIdentityInput::class, $ordered, true);
        $csrf = array_search(PreventRequestForgery::class, $ordered, true);
        $admit = array_search(AdmitIdentityDocument::class, $ordered, true);
        $auth = array_search(Authenticate::class, $ordered, true);
        $this->assertIsInt($capture);
        $this->assertIsInt($csrf);
        $this->assertIsInt($admit);
        $this->assertIsInt($auth);
        $this->assertLessThan($csrf, $capture);
        $this->assertLessThan($admit, $csrf);
        $this->assertLessThan($auth, $admit);
    }

    public function test_actual_password_route_owns_login_with_document_token_despite_stale_xsrf_cookie(): void
    {
        $user = User::factory()->create();
        $identity = $this->freshGuest();
        $this->withCookie('XSRF-TOKEN', 'obsolete-xsrf');
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password'], $this->headers($identity));
        $response->assertOk()->assertJsonPath('transition.epoch', 1)->assertJsonPath('identity.binding.principal', $user->id);
        $this->assertSame(1, DB::table('security_sessions')->count());
        $this->assertSame('active', DB::table('browser_identity_transitions')->value('status'));
        $names = array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());
        $this->assertContains(config('session.advertiser_cookie'), $names);
        $this->assertContains('XSRF-TOKEN', $names);
    }

    public function test_invalid_csrf_rejects_before_auth_and_emits_no_authentication_cookies(): void
    {
        $user = User::factory()->create();
        $identity = $this->freshGuest();
        $headers = $this->headers($identity);
        $headers['X-CSRF-TOKEN'] = 'wrong-token';
        $attempts = 0;
        Event::listen(Attempting::class, function () use (&$attempts): void {
            $attempts++;
        });
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password'], $headers);
        $response->assertStatus(419);
        $this->assertSame(0, $attempts);
        $this->assertSame(0, DB::table('security_sessions')->count());
        $this->assertSame(0, (int) DB::table('browser_identity_transitions')->value('epoch'));
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_valid_csrf_but_wrong_signed_capture_is_409_before_actual_auth_attempt(): void
    {
        $user = User::factory()->create();
        $identity = $this->freshGuest();
        $claims = app(IdentityReceipt::class)->read($identity['transition'], 'transition')['binding'];
        $claims['credential'] = str_repeat('f', 64);
        $headers = $this->headers($identity);
        $headers['X-Builderad-Transition'] = app(IdentityReceipt::class)->issue($claims, 'transition', time() + 60);
        $attempts = 0;
        Event::listen(Attempting::class, function () use (&$attempts): void {
            $attempts++;
        });
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password'], $headers);
        $response->assertStatus(409);
        $this->assertSame(0, $attempts);
        $this->assertSame(0, DB::table('security_sessions')->count());
        $this->assertSame([], $response->headers->getCookies());
    }

    private function ownedLogin(array $identity, User $user, string $url = '/login'): array
    {
        $response = $this->post($url, ['email' => $user->email, 'password' => 'password'], $this->headers($identity));
        $response->assertOk();
        $next = $response->json('identity');
        $this->withCookie(config('session.advertiser_cookie'), app('session')->driver()->getId());
        $this->reloadGuard();

        return $next;
    }

    public function test_actual_switch_rejects_old_document_before_locale_effect_even_with_current_csrf(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $first = $this->ownedLogin($this->freshGuest(), $a);
        $second = $this->ownedLogin($first, $b, '/identity/switch');
        $headers = $this->headers($second);
        $headers['X-Builderad-Document'] = $first['receipt'];
        $response = $this->post('/locale', ['locale' => 'en'], $headers);
        $response->assertStatus(409)->assertJsonPath('identityConflict', true);
        $this->assertSame([], $response->headers->getCookies());
        $this->assertSame($b->id, (int) DB::table('browser_identity_transitions')->value('principal_id'));
        $this->assertSame(2, (int) DB::table('browser_identity_transitions')->value('epoch'));
        $this->assertNotNull(DB::table('security_sessions')->where('user_id', $a->id)->value('revoked_at'));
        $this->assertNull(DB::table('security_sessions')->where('user_id', $b->id)->value('revoked_at'));
    }

    public function test_actual_owned_logout_closes_lineage_and_publishes_or_deletes_no_auth_cookie(): void
    {
        $user = User::factory()->create();
        $identity = $this->ownedLogin($this->freshGuest(), $user);
        $response = $this->post('/logout', [], $this->headers($identity));
        $response->assertOk()->assertJsonPath('transition.kind', 'logout')->assertJsonPath('transition.epoch', 2);
        $this->assertSame('closed', DB::table('browser_identity_transitions')->value('status'));
        $this->assertNotNull(DB::table('security_sessions')->value('revoked_at'));
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_renewal_preserves_canonical_deadlines_and_document_observes_redis_availability_within_fixed_cookie_bound(): void
    {
        $user = User::factory()->create();
        $identity = $this->ownedLogin($this->freshGuest(), $user);
        $before = DB::table('security_sessions')->first();
        $store = app('session')->driver();
        $store->setId($this->defaultCookies[config('session.advertiser_cookie')]);
        $store->start();
        $this->assertSame($identity['binding']['lineage'], $store->get('builderad.identity_lineage'));
        $store->put('builderad.identity_cookie_lease', time() + 30);
        $store->save();
        $this->assertTrue($store->getHandler()->getCache()->touch($store->getId(), 30));
        $this->reloadGuard();
        $page = $this->get('/profile', ['Accept' => 'text/html']);
        $page->assertOk();
        $document = $page->viewData('identity');
        $this->assertLessThanOrEqual(30000, $document['leaseMilliseconds']);
        $this->assertGreaterThan(0, $document['leaseMilliseconds']);
        $this->assertSame([], $page->headers->getCookies());
        $this->reloadGuard();
        $before = DB::table('security_sessions')->first();
        $response = $this->post('/identity/renew', [], $this->headers($document));
        $response->assertOk();
        $this->assertGreaterThan(30000, $response->json('identity.leaseMilliseconds'));
        $after = DB::table('security_sessions')->first();
        $this->assertSame($before->idle_expires_at, $after->idle_expires_at);
        $this->assertSame($before->absolute_expires_at, $after->absolute_expires_at);
        $this->assertSame($before->authenticated_at, $after->authenticated_at);
        $names = array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());
        $this->assertSame([], $names, 'Renewal must not publish a stale credential or CSRF cookie.');
    }

    public function test_expired_current_credential_is_retired_without_cookie_delivery_or_protected_effect(): void
    {
        $user = User::factory()->create();
        $identity = $this->ownedLogin($this->freshGuest(), $user);
        DB::connection('pgsql_authority')->table('security_sessions')->update(['idle_expires_at' => now()->subMinute()]);
        $response = $this->post('/locale', ['locale' => 'en'], $this->headers($identity));
        $response->assertStatus(409)->assertJsonPath('identityConflict', true);
        $this->assertSame('closed', DB::table('browser_identity_transitions')->value('status'));
        $this->assertSame('expired', DB::table('browser_identity_transitions')->value('kind'));
        $this->assertSame(2, (int) DB::table('browser_identity_transitions')->value('epoch'));
        $this->assertNotNull(DB::table('security_sessions')->value('revoked_at'));
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_actual_competing_password_logins_observe_row_contention_and_stale_loser_never_attempts_authentication(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $identity = $this->freshGuest();
        $worker = null;
        $proof = [];
        $input = base64_encode(json_encode(['data' => ['email' => $b->email, 'password' => 'password'],
            'headers' => $this->headers($identity), 'cookies' => $this->unencryptedCookies], JSON_THROW_ON_ERROR));
        Event::listen(Attempting::class, function () use ($input, &$worker, &$proof): void {
            if ($worker) {
                return;
            }
            $holder = (int) DB::connection('pgsql_authority')->selectOne('SELECT pg_backend_pid() AS pid')->pid;
            $this->assertSame(1, DB::connection('pgsql_authority')->transactionLevel());
            $worker = new Process([PHP_BINARY, base_path('tests/Support/ProductionIdentityWorker.php')],
                base_path(), ['P2B_REQUEST' => $input]);
            $worker->setTimeout(25);
            $worker->start();
            $deadline = microtime(true) + 10;
            do {
                $this->assertTrue($worker->isRunning(), 'Worker exited before real ownership contention: '.$worker->getOutput().$worker->getErrorOutput());
                if (preg_match('/BOOTED (\d+)/', $worker->getOutput(), $match)) {
                    $pid = (int) $match[1];
                    $blocked = DB::connection('pgsql_migrations')->selectOne('SELECT ? = ANY(pg_blocking_pids(?)) AS observed', [$holder, $pid])->observed;
                    if ($blocked) {
                        break;
                    }
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($blocked ?? false, 'Actual production lineage row contention was not observed.');
            $proof = ['holder_pid' => $holder, 'waiter_pid' => $pid, 'actual_blocker_observed' => true];
        });
        try {
            $response = $this->post('/login', ['email' => $a->email, 'password' => 'password'], $this->headers($identity));
            $response->assertOk()->assertJsonPath('identity.binding.principal', $a->id);
            $this->assertSame(0, $worker->wait(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('STATUS=409 AUTH_CALLS=0 COOKIES=0', $worker->getOutput());
            $this->assertSame(1, DB::table('security_sessions')->count());
            $this->assertSame(1, (int) DB::table('browser_identity_transitions')->value('epoch'));
            $proof['result'] = 'PASS';
        } finally {
            $proof['worker_output'] = $worker?->getOutput();
            $proof['worker_error_output'] = $worker?->getErrorOutput();
            $proof['worker_running_at_cleanup'] = $worker?->isRunning();
            file_put_contents(storage_path('logs/production-password-contention.json'), json_encode($proof, JSON_PRETTY_PRINT));
            if ($worker?->isRunning()) {
                $worker->stop();
            }
        }
    }

    public function test_authcompat_registration_owns_pending_session_without_business_authority_and_verification_rebinds_generation(): void
    {
        $guest = $this->freshGuest();
        $response = $this->post('/register', ['name' => 'Protocol fixture', 'email' => 'protocol-registration@example.test',
            'password' => 'Dense-Unique-Fixture-Password-921!', 'password_confirmation' => 'Dense-Unique-Fixture-Password-921!'], $this->headers($guest));
        $response->assertOk()->assertJsonPath('transition.epoch', 1)->assertJsonPath('redirect', '/verify-email');
        $identity = $response->json('identity');
        $user = User::where('email', 'protocol-registration@example.test')->firstOrFail();
        $this->assertSame('pending', DB::table('principal_lifecycles')->value('status'));
        $this->reloadGuard();
        $this->get('/verify-email', ['Accept' => 'text/html'])->assertOk();
        $this->get('/profile', ['Accept' => 'text/html'])->assertRedirect(route('verification.notice'));
        $this->patch('/profile', ['name' => 'Denied pending mutation', 'email' => $user->email], $this->headers($identity))->assertForbidden();
        $this->assertSame('Protocol fixture', $user->fresh()->name);
        $this->assertSame(1, DB::table('security_sessions')->count());
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(10),
            ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($url, ['Accept' => 'text/html'])->assertRedirect();
        $this->assertSame('current', DB::table('principal_lifecycles')->value('status'));
        $this->assertSame(1, (int) DB::table('security_sessions')->value('lifecycle_generation'));
        $this->reloadGuard();
        $profile = $this->get('/profile', ['Accept' => 'text/html']);
        $profile->assertOk();
        $this->assertSame(1, $profile->viewData('identity')['binding']['lifecycle']);
        $this->post('/locale', ['locale' => 'en'], $this->headers($identity))->assertStatus(409);
    }

    public function test_authcompat_confirmation_password_change_and_native_form_keep_current_owned_credential(): void
    {
        $user = User::factory()->create();
        $identity = $this->ownedLogin($this->freshGuest(), $user);
        $credential = $identity['binding']['credential'];
        $this->post('/confirm-password', ['password' => 'password'], $this->headers($identity))->assertRedirect();
        $this->put('/password', ['current_password' => 'password', 'password' => 'Dense-Unique-Fixture-Password-921!',
            'password_confirmation' => 'Dense-Unique-Fixture-Password-921!'], $this->headers($identity))->assertOk()->assertExactJson(['saved' => true]);
        $this->assertTrue(Hash::check('Dense-Unique-Fixture-Password-921!', $user->fresh()->password));
        $this->assertSame($credential, DB::table('browser_identity_transitions')->value('current_session_key_hash'));
        $native = $this->post('/locale', ['locale' => 'en', '_token' => $identity['csrf'], '_identity_receipt' => $identity['receipt']], ['Accept' => 'text/html']);
        $native->assertRedirect();
        $names = array_map(fn ($cookie) => $cookie->getName(), $native->headers->getCookies());
        $this->assertContains('builderad_locale', $names);
        $this->assertNotContains(config('session.advertiser_cookie'), $names);
        $this->assertNotContains('XSRF-TOKEN', $names);
    }

    public function test_authcompat_remember_cookie_is_owned_but_missing_session_never_promotes_recaller(): void
    {
        $user = User::factory()->create();
        $guest = $this->freshGuest();
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => true], $this->headers($guest));
        $response->assertOk();
        $names = array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());
        $recaller = Auth::guard('web')->getRecallerName();
        $this->assertContains($recaller, $names);
        unset($this->defaultCookies[config('session.advertiser_cookie')], $this->unencryptedCookies[config('session.advertiser_cookie')]);
        $this->reloadGuard();
        $context = $this->get('/identity/context', ['Accept' => 'application/json']);
        $context->assertStatus(401);
        $this->assertSame([], $context->headers->getCookies());
        $page = $this->get('/login', ['Accept' => 'text/html']);
        $page->assertOk();
        $this->assertSame(0, $page->viewData('identity')['binding']['principal']);
        $this->assertSame(1, (int) DB::table('browser_identity_transitions')->value('epoch'));
    }

    public function test_authcompat_old_frontend_with_valid_csrf_has_no_legacy_authentication_bypass(): void
    {
        $user = User::factory()->create();
        $guest = $this->freshGuest();
        $attempts = 0;
        Event::listen(Attempting::class, function () use (&$attempts): void {
            $attempts++;
        });
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password'],
            ['Accept' => 'application/json', 'X-CSRF-TOKEN' => $guest['csrf']]);
        $response->assertStatus(409)->assertJsonPath('identityConflict', true);
        $this->assertSame(0, $attempts);
        $this->assertSame([], $response->headers->getCookies());
        $this->assertSame(0, DB::table('security_sessions')->count());
    }

    public function test_authcompat_primary_email_change_revokes_and_owns_terminal_lifecycle_without_cookie_deletion(): void
    {
        $user = User::factory()->create();
        $identity = $this->ownedLogin($this->freshGuest(), $user);
        $response = $this->patch('/profile', ['name' => $user->name, 'email' => 'changed-primary@example.test'], $this->headers($identity));
        $response->assertRedirect(route('login'));
        $this->assertSame('closed', DB::table('browser_identity_transitions')->value('status'));
        $this->assertSame('expired', DB::table('browser_identity_transitions')->value('kind'));
        $this->assertNotNull(DB::table('security_sessions')->value('revoked_at'));
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_authcompat_unbound_legacy_session_requires_explicit_reauthentication_and_only_retires_its_input(): void
    {
        $user = User::factory()->create();
        $request = IdentityTransitionFixture::request();
        IdentityTransitionFixture::authenticate($request, $user->email);
        app(SessionAuthority::class)->issue($request, $user);
        $legacy = SessionAuthority::sessionKey($request);
        $request->session()->save();
        $this->withCookie(config('session.advertiser_cookie'), $request->session()->getId());
        $this->reloadGuard();
        $rejected = $this->get('/profile', ['Accept' => 'text/html']);
        $rejected->assertRedirect('/login');
        $this->assertSame([], $rejected->headers->getCookies());
        $this->assertSame(0, DB::table('browser_identity_transitions')->count());
        $page = $this->get('/login', ['Accept' => 'text/html']);
        $page->assertOk();
        $this->assertSame(0, $page->viewData('identity')['binding']['principal']);
        $this->assertNotNull(DB::table('security_sessions')->where('session_key_hash', $legacy)->value('revoked_at'));
        $this->assertSame('anonymous', DB::table('browser_identity_transitions')->value('status'));
    }

    public function test_actual_owned_sid_rebind_preserves_all_deadlines_and_old_receipt_is_not_admitted(): void
    {
        $user = User::factory()->create();
        $identity = $this->ownedLogin($this->freshGuest(), $user);
        $before = DB::table('security_sessions')->first();
        $response = $this->post('/identity/rebind', [], $this->headers($identity));
        $response->assertOk()->assertJsonPath('transition.kind', 'rebind')->assertJsonPath('transition.epoch', 2);
        $next = $response->json('identity');
        $after = DB::table('security_sessions')->first();
        $this->assertSame($before->id, $after->id);
        foreach (['idle_expires_at', 'absolute_expires_at', 'authenticated_at', 'last_seen_at'] as $deadline) {
            $this->assertSame($before->{$deadline}, $after->{$deadline});
        }
        $this->assertNotSame($before->session_key_hash, $after->session_key_hash);
        $this->reloadGuard();
        $headers = $this->headers($identity);
        $headers['X-CSRF-TOKEN'] = $next['csrf'];
        $this->post('/locale', ['locale' => 'en'], $headers)->assertStatus(409);
        $this->assertSame(1, DB::table('security_sessions')->count());
        $this->assertNull(DB::table('security_sessions')->value('revoked_at'));
    }

    public function test_authcompat_native_password_reset_is_single_use_and_revokes_another_current_device(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $old = $this->ownedLogin($this->freshGuest(), $user);
        $oldSid = app('session')->driver()->getId();
        $this->defaultCookies = $this->unencryptedCookies = [];
        $this->reloadGuard();
        $guest = $this->freshGuest();
        $this->post('/forgot-password', ['email' => $user->email, '_token' => $guest['csrf'],
            '_identity_receipt' => $guest['receipt']], ['Accept' => 'text/html'])->assertRedirect();
        $token = null;
        Notification::assertSentTo($user, SecurityResetPassword::class,
            function ($notification) use (&$token): bool {
                $token = $notification->token;

                return true;
            });
        $this->assertIsString($token);
        $page = $this->get('/reset-password/'.$token, ['Accept' => 'text/html']);
        $page->assertOk();
        $reset = $page->viewData('identity');
        $input = ['token' => $token, 'email' => $user->email, 'password' => 'Dense-Unique-Fixture-Password-921!',
            'password_confirmation' => 'Dense-Unique-Fixture-Password-921!', '_token' => $reset['csrf'],
            '_identity_receipt' => $reset['receipt']];
        $response = $this->post('/reset-password', $input, ['Accept' => 'text/html']);
        $response->assertSessionHasNoErrors()->assertRedirect(route('login'));
        $this->assertTrue(Hash::check($input['password'], $user->fresh()->password));
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->assertSame(0, DB::table('security_sessions')->whereNull('revoked_at')->count());
        $this->assertSame('current', DB::table('principal_lifecycles')->where('user_id', $user->id)->value('status'));
        $replay = $this->withCredentials()->postJson('/reset-password', [...$input, 'password' => 'Different-Replay-Password-792!',
            'password_confirmation' => 'Different-Replay-Password-792!'], $this->headers($reset));
        $this->assertSame(422, $replay->status(), (string) $replay->json('message'));
        $replay->assertJsonValidationErrors('email');
        $this->assertTrue(Hash::check($input['password'], $user->fresh()->password));
        $this->assertSame([], $response->headers->getCookies());
        $this->withCookie(config('session.advertiser_cookie'), $oldSid);
        $this->withCookie(config('session.advertiser_cookie').'-lineage', $old['binding']['lineage']);
        $this->reloadGuard();
        $this->get('/identity/context', ['Accept' => 'application/json'])->assertStatus(401);
    }
}
