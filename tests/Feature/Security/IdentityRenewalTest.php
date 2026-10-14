<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\StartIdentitySession;
use App\Models\User;
use App\Security\SessionAuthority;
use Closure;
use Illuminate\Cache\RedisStore;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** Real route, CSRF, PostgreSQL and Redis; injected failures are labeled separately. */
class IdentityRenewalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'redis', 'session.connection' => 'default']);
        $this->app->detectEnvironment(fn () => 'local');
        $this->assertFalse($this->app->runningUnitTests());
    }

    private function resetGuard(): void
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

    private function login(): array
    {
        $user = User::factory()->create();
        $guest = $this->get('/login', ['Accept' => 'text/html'])->assertOk()->viewData('page')['props']['identity'];
        $this->withCookie(config('session.advertiser_cookie'), app('session')->driver()->getId());
        $this->withCookie(config('session.advertiser_cookie').'-lineage', $guest['binding']['lineage']);
        $this->resetGuard();
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password'], $this->headers($guest))->assertOk();
        $this->withCookie(config('session.advertiser_cookie'), app('session')->driver()->getId());
        $this->resetGuard();

        return $response->json('identity');
    }

    /** An admitted request is paused at its old automatic-save boundary. */
    private function atCheckpoint(Closure $callback): void
    {
        $this->app->bind(StartIdentitySession::class, fn ($app) => new class($app->make(SessionManager::class), $callback) extends StartIdentitySession
        {
            public function __construct(SessionManager $manager, private Closure $callback)
            {
                parent::__construct($manager);
            }

            protected function saveSession($request)
            {
                if ($request->isMethod('POST') && $request->is('identity/renew', 'internal/identity/renew')
                    && $request->attributes->has('builderad.identity_document')) {
                    ($this->callback)($request);
                }
                parent::saveSession($request);
            }
        });
    }

    public function test_renewal_keeps_newer_entire_snapshot_and_extends_only_redis_ttl(): void
    {
        $identity = $this->login();
        $canonical = DB::table('security_sessions')->first();
        $expected = null;
        $cache = null;
        $sid = null;
        $this->atCheckpoint(function (Request $request) use (&$expected, &$cache, &$sid): void {
            $fresh = clone $request->session();
            $fresh->flush();
            $fresh->start();
            // An independent writer, not a mutation of the paused request.
            $fresh->put('f02.functional', ['value' => 'newer']);
            $fresh->put('auth.password_confirmed_at', time());
            $fresh->flash('f02.flash', 'must-not-age');
            $fresh->save();
            $sid = $fresh->getId();
            $handler = $fresh->getHandler();
            $expected = $handler->read($sid);
            $cache = $handler->getCache();
            $this->assertTrue($cache->touch($sid, 30));
        });
        $response = $this->post('/identity/renew', [], $this->headers($identity))->assertOk();
        $this->assertNotNull($expected);
        $this->assertSame($expected, $cache->get($sid), 'No attribute replacement or flash aging by renewal.');
        $store = $cache->getStore();
        $this->assertGreaterThan(30, $store->connection()->ttl($store->getPrefix().$sid));
        $this->assertSame(1, $response->json('identity.renewalProtocol'));
        $this->assertSame((int) config('session.lifetime') * 60000, $response->json('identity.leaseMilliseconds'));
        $this->assertSame($identity['binding'], $response->json('identity.binding'));
        $after = DB::table('security_sessions')->first();
        foreach (['idle_expires_at', 'absolute_expires_at', 'revoked_at', 'session_key_hash'] as $field) {
            $this->assertSame($canonical->{$field}, $after->{$field});
        }
        $this->resetGuard();
        $this->post('/identity/renew', [], $this->headers($response->json('identity')))->assertOk();
    }

    public function test_deleted_session_is_not_recreated_or_published(): void
    {
        $identity = $this->login();
        $cache = null;
        $sid = null;
        $this->atCheckpoint(function (Request $request) use (&$cache, &$sid): void {
            $sid = $request->session()->getId();
            $cache = $request->session()->getHandler()->getCache();
            $request->session()->getHandler()->destroy($sid);
            $this->assertFalse($cache->touch($sid, 30), 'Real EXPIRE cannot create the missing key.');
        });
        $response = $this->post('/identity/renew', [], $this->headers($identity))->assertStatus(409);
        $this->assertFalse($cache->has($sid));
        $this->assertSame([], $response->headers->getCookies());
    }

    public static function failedTouch(): array
    {
        return ['false' => [false, 409], 'exception' => [true, 503]];
    }

    #[DataProvider('failedTouch')]
    public function test_injected_touch_failure_cannot_return_success_or_issue_cookies(bool $throws, int $status): void
    {
        $identity = $this->login();
        $this->atCheckpoint(function (Request $request) use ($throws): void {
            $cache = $request->session()->getHandler()->getCache();
            $real = $cache->getStore();
            $store = Mockery::mock(RedisStore::class.'[touch]', [$real->getRedis(), $real->getPrefix(), config('session.connection')]);
            $expectation = $store->shouldReceive('touch')->once();
            if ($throws) {
                $expectation->andThrow(new RuntimeException('Injected renewal touch outage'));
            } else {
                $expectation->andReturn(false);
            }
            $cache->setStore($store);
        });
        $response = $this->post('/identity/renew', [], $this->headers($identity))->assertStatus($status);
        $this->assertSame([], $response->headers->getCookies());
        $this->assertNull($response->json('identity'));
    }

    public function test_revocation_before_publication_prevents_touch_and_old_cookies(): void
    {
        $identity = $this->login();
        $this->atCheckpoint(fn (Request $request) => app(SessionAuthority::class)->revokeCurrent($request));
        $response = $this->post('/identity/renew', [], $this->headers($identity))->assertStatus(409);
        $this->assertSame([], $response->headers->getCookies());
        $this->assertNotNull(DB::table('security_sessions')->value('revoked_at'));
    }

    public function test_expiry_during_admission_preserves_the_owned_terminal_save(): void
    {
        $identity = $this->login();
        DB::connection('pgsql_authority')->table('security_sessions')->update(['idle_expires_at' => now()->subSecond()]);
        $response = $this->post('/identity/renew', [], $this->headers($identity))->assertStatus(409);
        $this->assertSame([], $response->headers->getCookies());
        $this->assertSame('closed', DB::table('browser_identity_transitions')->value('status'));
        $this->assertSame(2, (int) DB::table('browser_identity_transitions')->value('epoch'));
        $this->assertNotNull(DB::table('security_sessions')->value('revoked_at'));
    }

    public function test_unowned_mutation_fails_closed_without_snapshot_fallback(): void
    {
        $identity = $this->login();
        $this->atCheckpoint(fn (Request $request) => $request->session()->put('f02.unowned', 'must-not-persist'));
        $response = $this->post('/identity/renew', [], $this->headers($identity))->assertStatus(500);
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_internal_renewal_uses_its_actual_handler_and_cookie_boundary(): void
    {
        $staff = User::factory()->create();
        DB::connection('pgsql_migrations')->table('principal_lifecycles')->where('user_id', $staff->id)->update(['principal_class' => 'staff']);
        DB::connection('pgsql_migrations')->table('internal_personnel')->insert([
            'user_id' => $staff->id, 'kind' => 'employee', 'status' => 'active',
            'attested_by' => $staff->id, 'attested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('pgsql_migrations')->table('passkeys')->insert([
            'user_id' => $staff->id, 'name' => 'Existing synthetic factor',
            'credential_id' => 'renewal-staff-fixture-'.$staff->id, 'credential' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Existing synthetic canonical staff fixture, not WebAuthn ceremony evidence.
        $this->actingAs($staff, 'internal');
        $this->resetGuard();
        $context = $this->get('/internal/identity/context', ['Accept' => 'application/json'])->assertOk();
        $identity = $context->json('identity');
        $this->resetGuard();
        $before = DB::table('security_sessions')->first();
        $response = $this->post('/internal/identity/renew', [], $this->headers($identity))->assertOk();
        $this->assertSame('internal', $response->json('identity.binding.boundary'));
        $this->assertSame(1, $response->json('identity.renewalProtocol'));
        $names = array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());
        $this->assertContains(config('session.internal_cookie'), $names);
        $this->assertNotContains(config('session.advertiser_cookie'), $names);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->assertSame('/internal', $cookie->getPath());
        }
        $after = DB::table('security_sessions')->first();
        $this->assertSame($before->idle_expires_at, $after->idle_expires_at);
        $this->assertSame($before->absolute_expires_at, $after->absolute_expires_at);
    }
}
