<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Webauthn\AuthenticatorData;

/** Reuses the unchanged P-256/COSE signing fixture; no verifier mocks. */
class ProductionPasskeyTest extends PasskeyAssertionTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'redis', 'session.connection' => 'default']);
        $this->app->detectEnvironment(fn () => 'local');
        $this->assertFalse($this->app->runningUnitTests());
    }

    private function protocolGuest(bool $internal): array
    {
        $response = $this->get($internal ? '/internal/login' : '/login', ['Accept' => 'text/html']);
        $response->assertOk();
        $identity = $response->viewData('identity');
        Auth::forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');

        return $identity;
    }

    private function protocolHeaders(array $identity): array
    {
        return ['Accept' => 'application/json', 'X-CSRF-TOKEN' => $identity['csrf'],
            'X-Builderad-Document' => $identity['receipt'], 'X-Builderad-Transition' => $identity['transition']];
    }

    public static function boundaries(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('boundaries')]
    public function test_p2b_real_signed_uv_passkey_owns_commit_audit_cookies_and_denies_replay(bool $internal): void
    {
        [$user, $key, $id] = $this->createCredential(staff: $internal);
        $guest = $this->protocolGuest($internal);
        $prefix = $internal ? '/internal' : '';
        $headers = $this->protocolHeaders($guest);
        $options = $this->get($prefix.'/auth/passkey/options', $headers)->assertOk()->json('options');
        $assertion = $this->assertion($options, $key, $id, $user, AuthenticatorData::FLAG_UP | AuthenticatorData::FLAG_UV);
        $response = $this->post($prefix.'/auth/passkey', ['credential' => $assertion], $headers);
        $response->assertOk()->assertJsonPath('identity.binding.principal', $user->id)
            ->assertJsonPath('identity.binding.boundary', $internal ? 'internal' : 'advertiser')
            ->assertJsonPath('transition.epoch', 1);
        $this->assertSame($internal ? 'internal_passkey' : 'passkey', DB::table('security_sessions')->value('auth_method'));
        $this->assertSame(1, DB::table('security_audit_events')->where('event', 'passkey_login_verified')->count());
        $this->assertNotNull(DB::table('passkeys')->value('last_used_at'));
        $cookies = $response->headers->getCookies();
        $cookie = collect($cookies)->first(fn ($cookie) => $cookie->getName() === config($internal ? 'session.internal_cookie' : 'session.advertiser_cookie'));
        $this->assertNotNull($cookie);
        $this->assertSame($internal ? '/internal' : '/', $cookie->getPath());
        $this->assertTrue($cookie->isHttpOnly());
        Auth::forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $headers['X-CSRF-TOKEN'] = $response->json('identity.csrf');
        $replay = $this->post($prefix.'/auth/passkey', ['credential' => $assertion], $headers);
        $replay->assertStatus(409);
        $this->assertSame([], $replay->headers->getCookies());
        $this->assertSame(1, DB::table('security_sessions')->count());
        $this->assertSame(1, DB::table('security_audit_events')->where('event', 'passkey_login_verified')->count());
    }

    #[DataProvider('boundaries')]
    public function test_malformed_signature_is_rejected_without_authentication_publication(bool $internal): void
    {
        [$user, $key, $id] = $this->createCredential(staff: $internal);
        $guest = $this->protocolGuest($internal);
        $prefix = $internal ? '/internal' : '';
        $headers = $this->protocolHeaders($guest);
        $options = $this->get($prefix.'/auth/passkey/options', $headers)->assertOk()->json('options');
        $assertion = $this->assertion($options, $key, $id, $user, AuthenticatorData::FLAG_UP | AuthenticatorData::FLAG_UV);
        $assertion['response']['signature'] = 'invalid';
        $this->post($prefix.'/auth/passkey', ['credential' => $assertion], $headers)->assertUnprocessable()
            ->assertJsonValidationErrors('credential');
        $this->assertSame(0, DB::table('security_sessions')->count());
        $this->assertSame(0, DB::table('security_audit_events')->where('event', 'passkey_login_verified')->count());
        $this->assertNull(DB::table('passkeys')->value('last_used_at'));
        $this->assertSame(0, DB::table('browser_identity_transitions')->value('epoch'));
    }

    public function test_p2b_real_signed_passkey_without_uv_still_fails_without_publication(): void
    {
        [$user, $key, $id] = $this->createCredential();
        $guest = $this->protocolGuest(false);
        $headers = $this->protocolHeaders($guest);
        $options = $this->get('/auth/passkey/options', $headers)->assertOk()->json('options');
        $response = $this->post('/auth/passkey', ['credential' => $this->assertion($options, $key, $id, $user, AuthenticatorData::FLAG_UP)], $headers);
        $response->assertUnprocessable();
        $this->assertSame(0, DB::table('security_sessions')->count());
        $this->assertSame(0, DB::table('security_audit_events')->where('event', 'passkey_login_verified')->count());
        $this->assertSame(0, (int) DB::table('browser_identity_transitions')->value('epoch'));
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_p2b_competing_signed_passkey_waits_on_real_lineage_lock_and_never_consumes_authentication(): void
    {
        [$loser, $key, $id] = $this->createCredential();
        $winner = User::factory()->create();
        $guest = $this->protocolGuest(false);
        $headers = $this->protocolHeaders($guest);
        $options = $this->get('/auth/passkey/options', $headers)->assertOk()->json('options');
        $credential = $this->assertion($options, $key, $id, $loser, AuthenticatorData::FLAG_UP | AuthenticatorData::FLAG_UV);
        $input = base64_encode(json_encode(['path' => '/auth/passkey', 'headers' => $headers, 'cookies' => $this->unencryptedCookies,
            'data' => ['credential' => $credential]], JSON_THROW_ON_ERROR));
        $worker = null;
        $proof = [];
        Event::listen(Attempting::class, function () use ($input, &$worker, &$proof): void {
            if ($worker) {
                return;
            }
            $holder = (int) DB::connection('pgsql_authority')->selectOne('SELECT pg_backend_pid() AS pid')->pid;
            $worker = new Process([PHP_BINARY, base_path('tests/Support/ProductionIdentityWorker.php')], base_path(), ['P2B_REQUEST' => $input]);
            $worker->setTimeout(25);
            $worker->start();
            $deadline = microtime(true) + 10;
            do {
                $this->assertTrue($worker->isRunning(), 'Worker exited before actual passkey contention: '.$worker->getOutput().$worker->getErrorOutput());
                if (preg_match('/BOOTED (\d+)/', $worker->getOutput(), $match)) {
                    $pid = (int) $match[1];
                    $blocked = DB::connection('pgsql_migrations')->selectOne('SELECT ? = ANY(pg_blocking_pids(?)) AS observed', [$holder, $pid])->observed;
                    if ($blocked) {
                        break;
                    }
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($blocked ?? false, 'Actual passkey lineage blocker not observed.');
            $proof = ['holder_pid' => $holder, 'waiter_pid' => $pid, 'actual_blocker_observed' => true];
        });
        try {
            $this->post('/login', ['email' => $winner->email, 'password' => 'password'], $headers)->assertOk();
            $this->assertSame(0, $worker->wait(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('LOGIN_EVENTS=0 VERIFIER_LOOKUPS=0', $worker->getOutput());
            $this->assertNull(DB::table('passkeys')->value('last_used_at'));
            $this->assertSame(0, DB::table('security_audit_events')->where('event', 'passkey_login_verified')->count());
            $this->assertSame(1, DB::table('security_sessions')->count());
            $this->assertSame($winner->id, (int) DB::table('browser_identity_transitions')->value('principal_id'));
            $proof['result'] = 'PASS';
        } finally {
            $proof['worker_output'] = $worker?->getOutput();
            file_put_contents(storage_path('logs/production-passkey-contention.json'), json_encode($proof, JSON_PRETTY_PRINT));
            if ($worker?->isRunning()) {
                $worker->stop();
            }
        }
    }
}
