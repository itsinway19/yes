<?php

namespace App\Security;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DisposableTestDatabaseGuard
{
    private const DATABASE = 'builderad_test_disposable';

    private const PORT = 5433;

    private const ROLES = [
        'pgsql' => 'builderad_app',
        'pgsql_authority' => 'builderad_authority',
        'pgsql_migrations' => 'builderad_owner',
    ];

    public function assertSafe(): void
    {
        $token = env('BUILDERAD_TEST_ISOLATION_TOKEN');
        if (! app()->environment('testing') || app()->configurationIsCached() ||
            config('database.default') !== 'pgsql' ||
            ! is_string($token) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $token)) {
            throw new RuntimeException('Destructive tests require the default PostgreSQL connection, uncached testing configuration and a disposable database token.');
        }

        foreach (self::ROLES as $name => $role) {
            $configured = config("database.connections.{$name}");
            if (! is_array($configured) || ($configured['driver'] ?? null) !== 'pgsql' ||
                ! empty($configured['url']) || isset($configured['read']) || isset($configured['write']) ||
                ($configured['host'] ?? null) !== '127.0.0.1' ||
                (string) ($configured['port'] ?? '') !== (string) self::PORT ||
                ($configured['database'] ?? null) !== self::DATABASE ||
                ($configured['username'] ?? null) !== $role) {
                throw new RuntimeException("Unsafe or ambiguous test connection: {$name}.");
            }
        }

        foreach (self::ROLES as $name => $role) {
            $identity = DB::connection($name)->selectOne(<<<'SQL'
                SELECT current_database() AS database_name, current_user AS role_name,
                    host(inet_server_addr()) AS server_host, inet_server_port() AS server_port,
                    current_setting('server_version_num')::integer AS server_version,
                    (SELECT rolsuper OR rolbypassrls OR rolinherit FROM pg_roles
                     WHERE rolname = current_user) AS unsafe_role,
                    (SELECT shobj_description(oid, 'pg_database') FROM pg_database
                     WHERE datname = current_database()) AS database_marker
                SQL);
            if ($identity?->database_name !== self::DATABASE || $identity?->role_name !== $role ||
                $identity?->server_host !== '127.0.0.1' || (int) $identity?->server_port !== self::PORT ||
                (int) $identity?->server_version < 180000 || (int) $identity?->server_version >= 190000 ||
                (bool) $identity?->unsafe_role ||
                $identity?->database_marker !== 'builderad-disposable-test:v1:'.$token) {
                throw new RuntimeException("Actual test database identity or disposable marker mismatch: {$name}.");
            }
        }
    }
}
