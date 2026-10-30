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
            // GitHub's PostgreSQL service listens on 5432 inside Docker even
            // when its host endpoint is mapped to 127.0.0.1:5433.
            $serverMatches = env('GITHUB_ACTIONS') === true
                ? $identity?->server_host === env('BUILDERAD_TEST_SERVER_HOST') &&
                    filter_var($identity?->server_host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false &&
                    $identity?->server_host !== '127.0.0.1' &&
                    (int) $identity?->server_port === 5432
                : $identity?->server_host === '127.0.0.1' &&
                    (int) $identity?->server_port === self::PORT;
            if ($identity?->database_name !== self::DATABASE || $identity?->role_name !== $role ||
                ! $serverMatches ||
                (int) $identity?->server_version < 180000 || (int) $identity?->server_version >= 190000 ||
                (bool) $identity?->unsafe_role ||
                $identity?->database_marker !== 'builderad-disposable-test:v1:'.$token) {
                throw new RuntimeException("Actual test database identity or disposable marker mismatch: {$name}.");
            }
        }
        $executor = DB::connection('pgsql_migrations')->selectOne(<<<'SQL'
            SELECT rolcanlogin OR rolsuper OR rolbypassrls OR rolinherit OR rolcreaterole OR rolcreatedb AS unsafe,
                pg_has_role('builderad_app', oid, 'MEMBER') OR pg_has_role('builderad_authority', oid, 'MEMBER') AS runtime_member,
                pg_has_role('builderad_owner', oid, 'SET') AS deployer_can_set
            FROM pg_roles WHERE rolname = 'builderad_scope_executor'
            SQL);
        if (! $executor || (bool) $executor->unsafe || (bool) $executor->runtime_member || ! (bool) $executor->deployer_can_set) {
            throw new RuntimeException('Non-login Business executor or runtime/deployment membership boundary is unsafe.');
        }
    }
}
