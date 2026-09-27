<?php

namespace Tests\Feature\Security;

use App\Security\DisposableTestDatabaseGuard;
use RuntimeException;
use Tests\TestCase;

class DisposableTestDatabaseGuardTest extends TestCase
{
    public function test_missing_or_mismatched_token_and_ci_loopback_are_rejected(): void
    {
        $guard = app(DisposableTestDatabaseGuard::class);
        $originalToken = getenv('BUILDERAD_TEST_ISOLATION_TOKEN');
        $originalActions = getenv('GITHUB_ACTIONS');
        $originalServerHost = getenv('BUILDERAD_TEST_SERVER_HOST');

        try {
            foreach (['', '00000000-0000-4000-8000-000000000000'] as $token) {
                putenv('BUILDERAD_TEST_ISOLATION_TOKEN='.$token);
                $this->assertGuardRejects($guard);
            }

            putenv('BUILDERAD_TEST_ISOLATION_TOKEN='.$originalToken);
            putenv('GITHUB_ACTIONS=true');
            putenv('BUILDERAD_TEST_SERVER_HOST=127.0.0.1');
            $this->assertGuardRejects($guard);
        } finally {
            $this->restoreEnvironment('BUILDERAD_TEST_ISOLATION_TOKEN', $originalToken);
            $this->restoreEnvironment('GITHUB_ACTIONS', $originalActions);
            $this->restoreEnvironment('BUILDERAD_TEST_SERVER_HOST', $originalServerHost);
        }
    }

    public function test_connection_overrides_are_rejected_without_running_a_migration(): void
    {
        $guard = app(DisposableTestDatabaseGuard::class);
        $guard->assertSafe();

        $originalDefault = config('database.default');
        $originalRuntime = config('database.connections.pgsql');
        $originalAuthority = config('database.connections.pgsql_authority');
        $originalOwner = config('database.connections.pgsql_migrations');

        $changes = [
            fn () => config()->set('database.default', 'sqlite'),
            fn () => config()->set('database.connections.pgsql.url', 'pgsql://builderad_app@127.0.0.1:5432/builderad_test'),
            fn () => config()->set('database.connections.pgsql.read', ['host' => '127.0.0.1']),
            fn () => config()->set('database.connections.pgsql_authority.username', 'builderad_app'),
            fn () => config()->set('database.connections.pgsql_migrations.port', '5432'),
        ];

        foreach ($changes as $change) {
            $change();
            try {
                $guard->assertSafe();
                $this->fail('An unsafe connection configuration was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            } finally {
                config()->set('database.default', $originalDefault);
                config()->set('database.connections.pgsql', $originalRuntime);
                config()->set('database.connections.pgsql_authority', $originalAuthority);
                config()->set('database.connections.pgsql_migrations', $originalOwner);
            }
        }
    }

    private function assertGuardRejects(DisposableTestDatabaseGuard $guard): void
    {
        try {
            $guard->assertSafe();
            $this->fail('An unsafe test connection was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    private function restoreEnvironment(string $name, string|false $value): void
    {
        putenv($value === false ? $name : $name.'='.$value);
    }
}
