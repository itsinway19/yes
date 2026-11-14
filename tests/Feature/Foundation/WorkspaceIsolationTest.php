<?php

namespace Tests\Feature\Foundation;

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use TypeError;

class WorkspaceIsolationTest extends TestCase
{
    public function test_migration_and_runtime_connections_use_distinct_database_roles(): void
    {
        $this->assertSame('builderad_app', DB::scalar('select current_user'));
        $this->assertSame(
            'builderad_owner',
            DB::connection('pgsql_migrations')->scalar('select current_user'),
        );
    }

    public function test_runtime_queries_use_the_restricted_role_against_the_test_database(): void
    {
        $identity = DB::selectOne('select current_database() as database, current_user as role');

        $this->assertSame('builderad_test_disposable', $identity->database);
        $this->assertSame('builderad_app', $identity->role);
    }

    public function test_principal_context_is_single_use_for_the_same_principal_without_mutation(): void
    {
        $user = User::factory()->create();
        DB::beginTransaction();

        try {
            app(PrincipalContext::class)->activate($user);
            $before = $this->authorizationState();

            try {
                app(PrincipalContext::class)->activate($user);
                $this->fail('Repeated PrincipalContext activation must be denied.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }

            $this->assertSame($before, $this->authorizationState());
        } finally {
            DB::rollBack();
        }
    }

    public function test_principal_context_cannot_replace_an_active_principal(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        DB::beginTransaction();

        try {
            app(PrincipalContext::class)->activate($firstUser);
            $before = $this->authorizationState();

            try {
                app(PrincipalContext::class)->activate($secondUser);
                $this->fail('An active principal must not be replaceable.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }

            $this->assertSame($before, $this->authorizationState());
        } finally {
            DB::rollBack();
        }
    }

    public function test_principal_context_rejects_each_partial_authorization_state_without_mutation(): void
    {
        $user = User::factory()->create();

        foreach ([
            'app.authorization_phase' => 'principal',
            'app.principal_user_id' => (string) $user->getKey(),
        ] as $key => $value) {
            DB::beginTransaction();

            try {
                DB::selectOne('select set_config(?, ?, true)', [$key, $value]);
                $before = $this->authorizationState();

                try {
                    app(PrincipalContext::class)->activate($user);
                    $this->fail("Principal activation must reject an existing {$key} value.");
                } catch (LogicException) {
                    $this->addToAssertionCount(1);
                }

                $this->assertSame($before, $this->authorizationState());
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_principal_context_rejects_raw_integer_and_unpersisted_user_inputs(): void
    {
        $user = User::factory()->create();

        DB::beginTransaction();
        try {
            try {
                app(PrincipalContext::class)->activate($user->getKey());
                $this->fail('Raw integer principals must not be accepted.');
            } catch (TypeError) {
                $this->addToAssertionCount(1);
            }
        } finally {
            DB::rollBack();
        }

        DB::beginTransaction();
        try {
            try {
                app(PrincipalContext::class)->activate(new User);
                $this->fail('An unpersisted User must not be accepted.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        } finally {
            DB::rollBack();
        }
    }

    public function test_principal_context_disappears_after_commit_and_rollback(): void
    {
        $user = User::factory()->create();

        DB::beginTransaction();
        app(PrincipalContext::class)->activate($user);
        $this->assertSame((string) $user->getKey(), DB::scalar("select current_setting('app.principal_user_id', true)"));
        DB::commit();
        $this->assertSame('', DB::scalar("select current_setting('app.principal_user_id', true)"));

        DB::beginTransaction();
        app(PrincipalContext::class)->activate($user);
        $this->assertSame((string) $user->getKey(), DB::scalar("select current_setting('app.principal_user_id', true)"));
        DB::rollBack();
        $this->assertSame('', DB::scalar("select current_setting('app.principal_user_id', true)"));
    }

    public function test_runtime_role_cannot_create_application_tables_or_bypass_rls(): void
    {
        $role = DB::selectOne(
            'select rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls, rolinherit from pg_roles where rolname = current_user'
        );

        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolcreatedb);
        $this->assertFalse($role->rolcreaterole);
        $this->assertFalse($role->rolreplication);
        $this->assertFalse($role->rolbypassrls);
        $this->assertFalse($role->rolinherit);

        $ownership = DB::selectOne(
            "select pg_get_userbyid(c.relowner) as owner from pg_class c where c.oid = 'public.businesses'::regclass"
        );
        $this->assertSame('builderad_owner', $ownership->owner);
        $this->assertNotSame(DB::scalar('select current_user'), $ownership->owner);
        $this->assertFalse(DB::scalar("select has_schema_privilege(current_user, 'public', 'CREATE')"));

        $this->expectException(QueryException::class);
        DB::statement('create table forbidden_runtime_ddl (id bigint)');
    }

    public function test_builderad_database_authority_is_explicit_and_excludes_public_runtime_escalation(): void
    {
        foreach (['builderad', 'builderad_test'] as $database) {
            $this->assertFalse($this->publicHasDatabasePrivilege($database, 'CONNECT'), "PUBLIC CONNECT on {$database}");
            $this->assertFalse($this->publicHasDatabasePrivilege($database, 'TEMPORARY'), "PUBLIC TEMPORARY on {$database}");
            $this->assertTrue($this->roleHasDatabasePrivilege('builderad_owner', $database, 'CONNECT'));
            $this->assertTrue($this->roleHasDatabasePrivilege('builderad_app', $database, 'CONNECT'));
            $this->assertFalse($this->roleHasDatabasePrivilege('builderad_app', $database, 'CREATE'));
            $this->assertFalse($this->roleHasDatabasePrivilege('builderad_app', $database, 'TEMPORARY'));
        }
    }

    public function test_runtime_cannot_create_temporary_tables_or_schemas(): void
    {
        foreach ([
            'create temporary table forbidden_runtime_temp (id bigint)',
            'create schema forbidden_runtime_schema',
        ] as $statement) {
            try {
                DB::statement($statement);
                $this->fail("Runtime database authority unexpectedly permitted: {$statement}");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_runtime_has_no_role_membership_and_cannot_set_role(): void
    {
        $memberships = DB::scalar(
            "select count(*) from pg_auth_members where member = (select oid from pg_roles where rolname = 'builderad_app')"
        );

        $this->assertSame(0, (int) $memberships);
        $this->assertFalse(DB::scalar("select rolinherit from pg_roles where rolname = 'builderad_app'"));

        try {
            DB::statement('set role builderad_owner');
            DB::statement('reset role');
            $this->fail('Runtime role must not SET ROLE to builderad_owner.');
        } catch (QueryException) {
            $this->assertSame('builderad_app', DB::scalar('select current_user'));
        }
    }

    public function test_non_superuser_bootstrap_fails_before_builderad_mutation(): void
    {
        $before = $this->builderadAuthoritySnapshot();
        $runtime = config('database.connections.pgsql');
        $process = new Process([
            'psql',
            '-X',
            '-w',
            '-h',
            (string) $runtime['host'],
            '-p',
            (string) $runtime['port'],
            '-U',
            (string) $runtime['username'],
            '-d',
            (string) $runtime['database'],
            '-f',
            database_path('bootstrap/postgresql.sql'),
        ], base_path(), ['PGPASSWORD' => (string) $runtime['password']]);
        $process->run();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString(
            'Builderad local/CI bootstrap requires a PostgreSQL superuser.',
            $process->getOutput().$process->getErrorOutput(),
        );
        $this->assertSame($before, $this->builderadAuthoritySnapshot());
    }

    public function test_runtime_role_cannot_alter_or_drop_protected_tables(): void
    {
        foreach ([
            'alter table businesses add column forbidden_runtime_column text',
            'drop table security_action_grants',
        ] as $statement) {
            try {
                DB::statement($statement);
                $this->fail("Runtime DDL unexpectedly succeeded: {$statement}");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function publicHasDatabasePrivilege(string $database, string $privilege): bool
    {
        return (bool) DB::connection('pgsql_migrations')->scalar(
            <<<'SQL'
                select exists (
                    select 1
                    from pg_database databases
                    cross join lateral aclexplode(
                        coalesce(databases.datacl, acldefault('d', databases.datdba))
                    ) privileges
                    where databases.datname = ?
                      and privileges.grantee = 0
                      and privileges.privilege_type = ?
                )
                SQL,
            [$database, $privilege],
        );
    }

    private function roleHasDatabasePrivilege(string $role, string $database, string $privilege): bool
    {
        return (bool) DB::connection('pgsql_migrations')->scalar(
            'select has_database_privilege(?, ?, ?)',
            [$role, $database, $privilege],
        );
    }

    private function builderadAuthoritySnapshot(): string
    {
        $owner = DB::connection('pgsql_migrations');

        return json_encode([
            'roles' => $owner->select(
                "select rolname, rolsuper, rolcreatedb, rolcreaterole, rolinherit, rolreplication, rolbypassrls from pg_roles where rolname in ('builderad_owner', 'builderad_app') order by rolname"
            ),
            'memberships' => $owner->select(
                "select roleid, member, grantor, admin_option, inherit_option, set_option from pg_auth_members where member = (select oid from pg_roles where rolname = 'builderad_app') order by roleid"
            ),
            'databases' => $owner->select(
                "select datname, datdba, datacl::text from pg_database where datname in ('builderad', 'builderad_test') order by datname"
            ),
            'schemas' => [
                'builderad' => $this->publicSchemaSnapshot('builderad'),
                'builderad_test' => $this->publicSchemaSnapshot('builderad_test'),
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function publicSchemaSnapshot(string $database): string
    {
        $owner = config('database.connections.pgsql_migrations');
        $process = new Process([
            'psql',
            '-X',
            '-w',
            '-h',
            (string) $owner['host'],
            '-p',
            (string) $owner['port'],
            '-U',
            (string) $owner['username'],
            '-d',
            $database,
            '-At',
            '-c',
            "select nspname, nspowner, coalesce(nspacl::text, '') from pg_namespace where nspname = 'public'",
        ], base_path(), ['PGPASSWORD' => (string) $owner['password']]);
        $process->mustRun();

        return trim($process->getOutput());
    }

    private function authorizationState(): array
    {
        $state = DB::selectOne(<<<'SQL'
            select
                current_setting('app.authorization_phase', true) as phase,
                current_setting('app.principal_user_id', true) as principal
            SQL);

        return [
            'phase' => $state->phase,
            'principal' => $state->principal,
        ];
    }
}
