<?php

namespace Tests\Feature\Security;

use App\Security\DisposableTestDatabaseGuard;
use RuntimeException;
use Tests\TestCase;

class DisposableTestDatabaseGuardTest extends TestCase
{
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
}
