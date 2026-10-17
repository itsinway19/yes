<?php

namespace Tests\Unit;

use App\Security\IdentityCookies;
use App\Security\IdentityDocument;
use App\Security\IdentityRenewal;
use App\Security\IdentityTransitionConflict;
use App\Security\SessionAuthority;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class IdentityCookiesTest extends TestCase
{
    private Container $previous;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['session' => [
            'cookie' => 'builderad-session', 'path' => '/', 'domain' => null,
        ]]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
    }

    private function request(): Request
    {
        $request = Request::create('/profile', 'GET');
        $store = new Store('test', new ArraySessionHandler(120));
        $store->start();
        $store->put('builderad.identity_epoch', 1);
        $request->setLaravelSession($store);
        $request->setUserResolver(fn () => (object) ['id' => 1]);
        (new IdentityCookies)->capture($request, 'remember_web');

        return $request;
    }

    private function response(int $status = 200): Response
    {
        $response = new Response('', $status);
        foreach (['builderad-session', 'XSRF-TOKEN', 'remember_web', 'locale'] as $name) {
            $response->headers->setCookie(Cookie::create($name, 'value', 0, '/', null, true, true, false, 'lax'));
        }
        $response->headers->setCookie(Cookie::create('builderad-internal-session', 'internal', 0, '/internal'));

        return $response;
    }

    public static function suppressedOperations(): array
    {
        return ['ordinary' => [null, null], 'logout' => ['terminal', ['status' => 'closed', 'kind' => 'logout']],
            'expiry' => ['terminal', ['status' => 'closed', 'kind' => 'expired']]];
    }

    #[DataProvider('suppressedOperations')]
    public function test_ordinary_and_terminal_responses_preserve_newer_and_unrelated_cookie_owners(?string $operation, ?array $committed): void
    {
        $request = $this->request();
        $request->attributes->set('builderad.identity_cookie_operation', $operation);
        $request->attributes->set('builderad.identity_committed', $committed);
        // A late response must not derive ownership from a subsequently changed identity.
        $request->session()->put('builderad.identity_epoch', 2);
        $request->setUserResolver(fn () => (object) ['id' => 2]);
        $response = (new IdentityCookies)->finish($request, $this->response());
        $this->assertSame(['locale', 'builderad-internal-session'], array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies()));
    }

    public function test_owned_login_retains_exact_cookie_security_attributes(): void
    {
        $request = $this->request();
        $request->attributes->set('builderad.identity_cookie_operation', 'transition');
        $request->attributes->set('builderad.identity_committed', ['status' => 'active', 'kind' => 'login']);
        $request->session()->put('builderad.identity_cookie_deadline', time() + 86400);
        $request->attributes->set('builderad.identity_output', [
            'credential' => SessionAuthority::sessionKey($request), 'epoch' => 1,
            'lineage' => $request->session()->get('builderad.identity_lineage'), 'csrf' => $request->session()->token(),
            'cookie_deadline' => $request->session()->get('builderad.identity_cookie_deadline'),
        ]);
        $response = (new IdentityCookies)->finish($request, $this->response());
        $this->assertCount(5, $response->headers->getCookies());
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'builderad-session') {
                $this->assertTrue($cookie->isSecure());
                $this->assertTrue($cookie->isHttpOnly());
                $this->assertSame('lax', $cookie->getSameSite());
                $this->assertSame('/', $cookie->getPath());
                $this->assertSame(0, $cookie->getExpiresTime(), 'Session cookies retain expire-on-close semantics.');
            }
        }
    }

    public static function failedPublication(): array
    {
        return ['failed' => ['builderad.identity_failed'], 'unknown' => ['builderad.identity_uncertain'],
            'replayed' => ['builderad.identity_replayed']];
    }

    #[DataProvider('failedPublication')]
    public function test_failed_uncertain_and_replayed_transitions_publish_no_auth_cookie(string $attribute): void
    {
        $request = $this->request();
        $request->attributes->set('builderad.identity_cookie_operation', 'transition');
        $request->attributes->set('builderad.identity_committed', ['status' => 'active', 'kind' => 'login']);
        $request->attributes->set($attribute, true);
        $response = (new IdentityCookies)->finish($request, $this->response());
        $this->assertCount(2, $response->headers->getCookies());
    }

    public function test_late_deletion_is_suppressed_and_unexpected_regeneration_cannot_publish(): void
    {
        $request = $this->request();
        $request->session()->regenerate();
        $response = $this->response();
        $response->headers->clearCookie('builderad-session', '/');
        $response = (new IdentityCookies)->finish($request, $response);
        $this->assertCount(2, $response->headers->getCookies());
    }

    public function test_renewal_publishes_no_owned_cookies_and_rejects_sid_change(): void
    {
        // Cookie-policy unit only. Real canonical admission and Redis are
        // exercised separately by IdentityRenewalTest and the browser gate.
        Container::getInstance()->instance(IdentityRenewal::class, new class
        {
            public function refresh(Request $request): int
            {
                if ($request->attributes->get('builderad.identity_cookies')['credential'] !== SessionAuthority::sessionKey($request)) {
                    throw new IdentityTransitionConflict;
                }

                return 3600;
            }
        });
        Container::getInstance()->instance(IdentityDocument::class, new class
        {
            public function metadata(Request $request): array
            {
                return ['leaseMilliseconds' => 3600000, 'renewalProtocol' => 1];
            }
        });
        $publication = function (): JsonResponse {
            $response = new JsonResponse;
            foreach ($this->response()->headers->getCookies() as $cookie) {
                $response->headers->setCookie($cookie);
            }

            return $response;
        };
        $request = $this->request();
        $request->attributes->set('builderad.identity_cookie_operation', 'renewal');
        $response = (new IdentityCookies)->finish($request, $publication());
        $this->assertCount(2, $response->headers->getCookies());
        $this->assertSame(['locale', 'builderad-internal-session'], array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies()));
        $request->session()->regenerate();
        $response = (new IdentityCookies)->finish($request, $publication());
        $this->assertCount(2, $response->headers->getCookies());
        $this->assertSame(409, $response->getStatusCode());
    }
}
