<?php

namespace Tests;

use App\Security\DisposableTestDatabaseGuard;
use App\Security\SessionAuthority;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    private static bool $databasePrepared = false;

    public function actingAs(Authenticatable $user, $guard = null)
    {
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
        $request = Request::create('/', 'GET');
        $request->setLaravelSession($session);
        if (! $existing) {
            $staff = DB::table('principal_lifecycles')->where('user_id', $user->getAuthIdentifier())
                ->where('principal_class', 'staff')->exists();
            app(SessionAuthority::class)->issue($request, $user, $staff ? 'passkey' : 'password');
        }
        $session->save();

        return $this;
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

        // This check precedes both migrate:fresh and every fixture TRUNCATE,
        // including when PHPUnit is invoked without the PowerShell runner.
        app(DisposableTestDatabaseGuard::class)->assertSafe();

        $this->withoutVite();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response(str_repeat('F', 35).":0\n", 200)]);

        if (! self::$databasePrepared) {
            Artisan::call('migrate:fresh', [
                '--database' => 'pgsql_migrations',
                '--force' => true,
            ]);

            self::$databasePrepared = true;
        }

        DB::connection('pgsql')->disconnect();
        DB::connection('pgsql_migrations')->statement(
            'TRUNCATE TABLE jobs, failed_jobs, service_audit_events, service_workspace_scopes, service_action_grants, service_identities, workspace_ownerships, security_action_grants, internal_personnel, passkeys, security_sessions, security_audit_events, principal_lifecycles, workspace_memberships, workspaces, password_reset_tokens, users RESTART IDENTITY CASCADE'
        );
        DB::purge('pgsql');
    }
}
