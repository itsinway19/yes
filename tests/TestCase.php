<?php

namespace Tests;

use App\Security\DisposableTestDatabaseGuard;
use App\Security\IdentityReceipt;
use App\Security\IdentityTransition;
use App\Security\SessionAuthority;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\DisposableDatabaseReset;

abstract class TestCase extends BaseTestCase
{
    private static bool $databasePrepared = false;

    private bool $ownedIdentityFixture = false;

    /** Explicitly enter the real anonymous/document protocol for compatibility tests. */
    protected function useIdentityDocument(string $path = '/login'): array
    {
        config(['session.driver' => 'redis', 'session.connection' => 'default']);
        Auth::forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        foreach (['X-CSRF-TOKEN', 'X-Builderad-Document', 'X-Builderad-Transition'] as $header) {
            unset($this->defaultHeaders[$header]);
        }
        $this->withCredentials();
        $response = $this->get($path, ['Accept' => 'text/html']);
        $response->assertOk();
        $identity = $response->original->getData()['identity'] ?? $response->viewData('page')['props']['identity'];
        $this->identityFixtureHeaders($identity);
        $this->ownedIdentityFixture = true;

        return $identity;
    }

    private function identityFixtureHeaders(array $identity): void
    {
        $this->withHeaders(['X-CSRF-TOKEN' => $identity['csrf'],
            'X-Builderad-Document' => $identity['receipt'], 'X-Builderad-Transition' => $identity['transition']]);
    }

    public function actingAs(Authenticatable $user, $guard = null)
    {
        $guard ??= 'web';
        $cookie = config($guard === 'internal' ? 'session.internal_cookie' : 'session.advertiser_cookie');
        if (config('session.cookie') !== $cookie) {
            config()->set('session.cookie', $cookie);
            $this->app['session']->forgetDrivers();
            $this->app->forgetInstance('session.store');
            Auth::forgetGuards();
        }
        config()->set('session.path', $guard === 'internal' ? '/internal' : '/');
        parent::actingAs($user, $guard);
        $generation = DB::table('principal_lifecycles')->where('user_id', $user->getAuthIdentifier())->value('generation');
        $this->withSession(['a001_generation' => (int) ($generation ?? 0)]);
        $session = $this->app['session']->driver();
        $existing = DB::table('security_sessions')
            ->where('session_key_hash', hash('sha256', $session->getId()))->first();
        if ($existing && (int) $existing->user_id !== (int) $user->getAuthIdentifier()) {
            $session->regenerate();
            $existing = null;
        }
        unset($this->unencryptedCookies[$session->getName()]);
        $this->withCookie($session->getName(), $session->getId());
        $request = Request::create($guard === 'internal' ? '/internal/administration' : '/', 'GET');
        $request->setLaravelSession($session);
        if (! $existing) {
            $staff = DB::table('principal_lifecycles')->where('user_id', $user->getAuthIdentifier())
                ->where('principal_class', 'staff')->exists();
            app(SessionAuthority::class)->issue($request, $user,
                $guard === 'internal' ? 'internal_passkey' : ($staff ? 'staff_enrollment' : 'password'));
        }
        $session->put(Auth::guard($guard)->getName(), $user->getAuthIdentifier());
        $this->withIdentityAdmissionFixture($request, $user, $guard);
        $session->save();

        return $this;
    }

    /** Synthetic document for existing authorization tests, not authentication/race evidence. */
    private function withIdentityAdmissionFixture(Request $request, Authenticatable $user, string $guard): void
    {
        $this->withCredentials();
        $boundary = $guard === 'internal' ? 'internal' : 'advertiser';
        $existing = DB::connection('pgsql_authority')->table('browser_identity_transitions')
            ->where('current_session_key_hash', SessionAuthority::sessionKey($request))
            ->where('principal_id', $user->getAuthIdentifier())->where('boundary', $boundary)->first();
        $lineage = $existing?->lineage_id ?? app(IdentityTransition::class)->create($boundary);
        $epoch = $existing ? (int) $existing->epoch : 1;
        if (! $existing) {
            DB::connection('pgsql_authority')->table('browser_identity_transitions')->where('lineage_id', $lineage)->update([
                'epoch' => 1, 'current_session_key_hash' => SessionAuthority::sessionKey($request),
                'principal_id' => $user->getAuthIdentifier(), 'status' => 'active', 'kind' => 'login',
                'last_nonce' => bin2hex(random_bytes(32)), 'updated_at' => now(),
            ]);
        }
        $request->session()->put(['builderad.identity_lineage' => $lineage, 'builderad.identity_epoch' => $epoch,
            'builderad.identity_cookie_lease' => time() + (int) config('session.lifetime') * 60]);
        $binding = ['lineage' => $lineage, 'epoch' => $epoch, 'boundary' => $boundary,
            'credential' => SessionAuthority::sessionKey($request), 'principal' => (int) $user->getAuthIdentifier(),
            'lifecycle' => (int) $request->session()->get('a001_generation'),
            'context' => hash_hmac('sha256', $request->session()->getId(), (string) config('app.key')),
            'generation' => bin2hex(random_bytes(32))];
        $receipts = app(IdentityReceipt::class);
        unset($this->unencryptedCookies[$request->session()->getName().'-lineage']);
        $this->withCookie($request->session()->getName().'-lineage', $lineage);
        $this->withHeaders(['X-CSRF-TOKEN' => $request->session()->token(),
            'X-Builderad-Document' => $receipts->issue($binding, 'document', time() + 7200),
            'X-Builderad-Transition' => $receipts->issue([...$binding, 'nonce' => bin2hex(random_bytes(32))], 'transition', time() + 600)]);
    }

    /** Synthetic administrative session fixture for authorization tests, not a WebAuthn proof. */
    public function actingAsInternal(Authenticatable $user): static
    {
        return $this->actingAs($user, 'internal');
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        foreach ($response->baseResponse->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }
        if ($this->ownedIdentityFixture && $response->status() === 200) {
            $result = json_decode($response->getContent(), true);
            if (is_array($result['transition'] ?? null) && is_array($result['identity'] ?? null)) {
                $this->identityFixtureHeaders($result['identity']);
            } elseif (($result['transition']['status'] ?? null) === 'closed') {
                foreach (['X-CSRF-TOKEN', 'X-Builderad-Document', 'X-Builderad-Transition'] as $header) {
                    unset($this->defaultHeaders[$header]);
                }
            }
        }

        return $response;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // This check precedes both disposable reset and every fixture TRUNCATE,
        // including when PHPUnit is invoked without the PowerShell runner.
        app(DisposableTestDatabaseGuard::class)->assertSafe();

        $this->withoutVite();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response(str_repeat('F', 35).":0\n", 200)]);

        if (! self::$databasePrepared) {
            app(DisposableDatabaseReset::class)->install();

            self::$databasePrepared = true;
        }

        DB::connection('pgsql')->disconnect();
        DB::connection('pgsql_migrations')->statement(
            'TRUNCATE TABLE jobs, failed_jobs, service_audit_events, service_workspace_scopes, service_action_grants, service_identities, workspace_ownerships, security_action_grants, internal_personnel, passkeys, security_sessions, security_audit_events, principal_lifecycles, workspace_memberships, workspaces, password_reset_tokens, users RESTART IDENTITY CASCADE'
        );
        DB::purge('pgsql');
        DB::connection('pgsql_migrations')->statement(
            'TRUNCATE TABLE commercial_markets, commercial_scopes, commercial_command_results, security_commercial_audit_custody, security_commercial_mandate_executions, security_source_audit_custody CASCADE'
        );
    }

    /** Context switching recreates drivers; array sessions cannot survive that boundary. */
    protected function usePersistentTestSessions(): void
    {
        $directory = sys_get_temp_dir().'/bb2-sessions-'.bin2hex(random_bytes(16));
        if (! mkdir($directory, 0700)) {
            throw new \RuntimeException('Unable to create isolated session storage.');
        }
        config()->set('session.driver', 'file');
        config()->set('session.files', $directory);
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->beforeApplicationDestroyed(static function () use ($directory): void {
            self::removeTemporarySessionDirectory($directory);
        });
    }

    protected static function removeTemporarySessionDirectory(string $directory): void
    {
        foreach (scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $file = $directory.DIRECTORY_SEPARATOR.$entry;
            if (! is_file($file) || ! unlink($file)) {
                throw new \RuntimeException('Unable to remove isolated session entry: '.$file);
            }
        }
        // Windows can defer an unlinked file's removal until an existing handle
        // closes. Retry only an otherwise empty, test-owned directory; real
        // remaining entries and persistent failures still fail cleanup.
        $deadline = hrtime(true) + 1_000_000_000;
        do {
            if (@rmdir($directory)) {
                return;
            }
            if (scandir($directory) !== ['.', '..'] || hrtime(true) >= $deadline) {
                throw new \RuntimeException('Unable to remove isolated session directory: '.$directory);
            }
            usleep(10000);
        } while (true);
    }
}
