<?php

namespace Tests;

use App\Security\DisposableTestDatabaseGuard;
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
        $session->save();

        return $this;
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
            foreach (glob($directory.'/*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        });
    }
}
