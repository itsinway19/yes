<?php

namespace Tests\Support;

use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Support\Facades\DB;

/** This custodian is installed only in the guarded disposable profile and removed after every test. */
final class RegisteredProducerFixture
{
    public static function install(): array
    {
        app(DisposableTestDatabaseGuard::class)->assertSafe();
        $db = DB::connection('pgsql_migrations');
        $definitions = [];
        foreach (['registered_producer(jsonb)', 'registered_producer_publish(jsonb,uuid,bytea)',
            'registered_producer_fields(jsonb,jsonb)', 'registered_output_constraints(jsonb,text,text)'] as $signature) {
            $definitions[] = $db->scalar('SELECT pg_get_functiondef(?::regprocedure)', ['builderad_private.'.$signature]);
        }

        $db->transaction(function () use ($db): void {
            $db->unprepared(<<<'SQL'
            CREATE TABLE builderad_private.test_registered_producer(
                artifact_key text PRIMARY KEY, descriptor jsonb NOT NULL, payload jsonb NOT NULL,
                publication_manifest_id uuid, fail_publish boolean NOT NULL DEFAULT false, policy_current boolean NOT NULL DEFAULT true
            );
            REVOKE ALL ON builderad_private.test_registered_producer FROM PUBLIC,builderad_app,builderad_authority;
            GRANT SELECT,UPDATE(publication_manifest_id) ON builderad_private.test_registered_producer TO builderad_scope_executor;
            SQL);
            self::replace(file_get_contents(__DIR__.'/registered-producer-fixture.sql'));
        });

        return $definitions;
    }

    public static function uninstall(array $definitions): void
    {
        app(DisposableTestDatabaseGuard::class)->assertSafe();
        $db = DB::connection('pgsql_migrations');
        $db->transaction(function () use ($db, $definitions): void {
            self::replace(implode(";\n", $definitions));
            $db->statement('DROP TABLE builderad_private.test_registered_producer');
        });
    }

    private static function replace(string $sql): void
    {
        $db = DB::connection('pgsql_migrations');
        $db->transaction(function () use ($db, $sql): void {
            $db->statement('GRANT CREATE ON SCHEMA builderad_private TO builderad_scope_executor');
            $db->statement('SET LOCAL ROLE builderad_scope_executor');
            $db->unprepared($sql);
            $db->statement('RESET ROLE');
            $db->statement('REVOKE CREATE ON SCHEMA builderad_private FROM builderad_scope_executor');
        });
    }
}
