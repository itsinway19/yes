<?php

namespace Tests\Feature\Foundation;

use App\Foundation\Tenancy\PrincipalContext;
use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
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

    public function test_active_membership_bootstraps_context_without_circular_rls_dependency(): void
    {
        [$user, $workspace] = $this->fixture('active');

        $visible = $this->runWorkspace($user, $this->publicId($workspace), function () {
            return [
                'workspaces' => DB::table('workspaces')->pluck('name')->all(),
                'memberships' => DB::table('workspace_memberships')->pluck('status')->all(),
                'principal' => DB::scalar("select current_setting('app.principal_user_id', true)"),
                'workspace' => DB::scalar("select current_setting('app.workspace_id', true)"),
            ];
        });

        $this->assertSame(['Workspace active'], $visible['workspaces']);
        $this->assertSame(['active'], $visible['memberships']);
        $this->assertSame((string) $user->getKey(), $visible['principal']);
        $this->assertSame((string) $workspace, $visible['workspace']);
    }

    public function test_principal_context_alone_can_resolve_active_memberships_and_workspace_candidates(): void
    {
        [$user] = $this->fixture('active');
        $connection = DB::connection();
        $connection->beginTransaction();

        try {
            app(PrincipalContext::class)->activate($user);

            $this->assertSame(1, $connection->table('workspace_memberships')->where('status', 'active')->count());
            $this->assertSame(1, $connection->table('workspaces')->count());
            $this->assertContains(DB::scalar("select current_setting('app.workspace_id', true)"), [null, '']);
        } finally {
            $connection->rollBack();
        }

        $this->assertContains(DB::scalar("select current_setting('app.principal_user_id', true)"), [null, '']);
    }

    public function test_signed_context_discovers_own_memberships_then_isolates_each_workspace(): void
    {
        [$user, $firstWorkspace] = $this->fixture('active');
        [$otherUser, $secondWorkspace] = $this->fixture('active');

        $owner = DB::connection('pgsql_migrations');
        $owner->table('workspace_memberships')->insert([
            ['workspace_id' => $secondWorkspace, 'user_id' => $user->getKey(),
                'role' => 'owner', 'status' => 'active', 'created_at' => now()],
            ['workspace_id' => $firstWorkspace, 'user_id' => $otherUser->getKey(),
                'role' => 'member', 'status' => 'active', 'created_at' => now()],
        ]);
        $owner->table('workspace_memberships')->where('workspace_id', $secondWorkspace)
            ->where('user_id', $otherUser->getKey())->update(['role' => 'member']);
        $owner->table('workspace_ownerships')->where('workspace_id', $secondWorkspace)
            ->update(['responsible_owner_user_id' => $user->getKey()]);
        $owner->table('security_action_grants')->insert([
            'subject_user_id' => $user->getKey(), 'source' => 'system_workspace_creation',
            'action' => 'workspace.view', 'scope_kind' => 'workspace',
            'workspace_id' => $secondWorkspace, 'subject_generation' => 1,
            'reason' => 'workspace fixture', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $connection = DB::connection();
        $connection->beginTransaction();

        try {
            app(PrincipalContext::class)->activate($user);
            $this->assertSame(
                [$firstWorkspace, $secondWorkspace],
                $connection->table('workspaces')->orderBy('id')->pluck('id')->all(),
            );
            $this->assertSame(
                [$firstWorkspace, $secondWorkspace],
                $connection->table('workspace_memberships')->orderBy('workspace_id')->pluck('workspace_id')->all(),
            );
            $this->assertSame(0, $connection->table('workspace_memberships')->where('user_id', $otherUser->getKey())->count());
        } finally {
            $connection->rollBack();
        }

        foreach ([$firstWorkspace, $secondWorkspace] as $selectedWorkspace) {
            $this->runWorkspace($user, $this->publicId($selectedWorkspace), function () use ($selectedWorkspace, $otherUser): void {
                $this->assertSame([$selectedWorkspace], DB::table('workspaces')->pluck('id')->all());
                $this->assertSame([$selectedWorkspace], DB::table('workspace_memberships')->pluck('workspace_id')->all());
                $this->assertSame([$selectedWorkspace], DB::table('workspace_ownerships')->pluck('workspace_id')->all());
                $this->assertSame(0, DB::table('workspace_memberships')->where('user_id', $otherUser->getKey())->count());
            });
        }
    }

    public function test_principal_context_discovers_only_active_membership_workspaces(): void
    {
        [$user, $activeWorkspace] = $this->fixture('active');
        [, $revokedWorkspace] = $this->fixture('revoked');

        DB::connection('pgsql_migrations')->table('workspace_memberships')
            ->where('workspace_id', $revokedWorkspace)
            ->update(['user_id' => $user->getKey()]);

        DB::beginTransaction();
        try {
            app(PrincipalContext::class)->activate($user);
            $this->assertSame([$activeWorkspace], DB::table('workspaces')->pluck('id')->all());
            $this->assertNotContains($revokedWorkspace, DB::table('workspaces')->pluck('id')->all());
        } finally {
            DB::rollBack();
        }
    }

    public function test_principal_context_is_single_use_for_the_same_principal_without_mutation(): void
    {
        [$user] = $this->fixture('active');
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
        [$firstUser] = $this->fixture('active');
        [$secondUser] = $this->fixture('active');
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
        [$user] = $this->fixture('active');

        foreach ([
            'app.authorization_phase' => 'principal',
            'app.principal_user_id' => (string) $user->getKey(),
            'app.workspace_id' => '1',
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
        [$user] = $this->fixture('active');

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

    public function test_workspace_context_cannot_be_downgraded_to_principal_context(): void
    {
        [$user, $firstWorkspace] = $this->fixture('active');
        [, $secondWorkspace] = $this->fixture('active');
        DB::connection('pgsql_migrations')->table('workspace_memberships')
            ->where('workspace_id', $secondWorkspace)
            ->update(['user_id' => $user->getKey()]);

        $state = $this->runWorkspace(
            $user,
            $this->publicId($firstWorkspace),
            function () use ($user) {
                try {
                    app(PrincipalContext::class)->activate($user);
                    $this->fail('Workspace authorization must not be downgraded to principal authorization.');
                } catch (LogicException) {
                    $this->addToAssertionCount(1);
                }

                return [
                    ...$this->authorizationState(),
                    'visible' => DB::table('workspaces')->orderBy('id')->pluck('id')->all(),
                ];
            },
        );

        $this->assertSame('workspace', $state['phase']);
        $this->assertSame((string) $firstWorkspace, $state['workspace']);
        $this->assertSame([$firstWorkspace], $state['visible']);
        $this->assertNotContains($secondWorkspace, $state['visible']);
    }

    public function test_workspace_context_cannot_transition_to_another_workspace(): void
    {
        [$user, $firstWorkspace] = $this->fixture('active');
        [, $secondWorkspace] = $this->fixture('active');
        DB::connection('pgsql_migrations')->table('workspace_memberships')
            ->where('workspace_id', $secondWorkspace)
            ->update(['user_id' => $user->getKey()]);

        $state = $this->runWorkspace(
            $user,
            $this->publicId($firstWorkspace),
            function () use ($user, $secondWorkspace) {
                $before = $this->authorizationState();

                try {
                    $this->runWorkspace(
                        $user,
                        $this->publicId($secondWorkspace),
                        fn () => null,
                    );
                    $this->fail('An active Workspace context must not transition to another Workspace.');
                } catch (LogicException) {
                    $this->addToAssertionCount(1);
                }

                $this->assertSame($before, $this->authorizationState());

                return [
                    ...$this->authorizationState(),
                    'visible' => DB::table('workspaces')->orderBy('id')->pluck('id')->all(),
                ];
            },
        );

        $this->assertSame('workspace', $state['phase']);
        $this->assertSame((string) $firstWorkspace, $state['workspace']);
        $this->assertSame([$firstWorkspace], $state['visible']);
        $this->assertNotContains($secondWorkspace, $state['visible']);
    }

    public function test_inconsistent_authorization_states_fail_closed(): void
    {
        [$user, $firstWorkspace] = $this->fixture('active');
        [, $secondWorkspace] = $this->fixture('active');
        DB::connection('pgsql_migrations')->table('workspace_memberships')
            ->where('workspace_id', $secondWorkspace)
            ->update(['user_id' => $user->getKey()]);

        DB::beginTransaction();
        try {
            DB::selectOne("select set_config('app.principal_user_id', ?, true)", [(string) $user->getKey()]);
            DB::selectOne("select set_config('app.authorization_phase', 'principal', true)");
            DB::selectOne("select set_config('app.workspace_id', ?, true)", [(string) $firstWorkspace]);
            $this->assertSame([], DB::table('workspaces')->pluck('id')->all());
            $this->assertSame([], DB::table('workspace_memberships')->pluck('workspace_id')->all());
        } finally {
            DB::rollBack();
        }

        DB::beginTransaction();
        try {
            DB::selectOne("select set_config('app.principal_user_id', ?, true)", [(string) $user->getKey()]);
            DB::selectOne("select set_config('app.authorization_phase', 'workspace', true)");
            DB::selectOne("select set_config('app.workspace_id', '', true)");
            $this->assertSame([], DB::table('workspaces')->pluck('id')->all());
        } finally {
            DB::rollBack();
        }

        DB::beginTransaction();
        try {
            DB::selectOne("select set_config('app.principal_user_id', ?, true)", [(string) $user->getKey()]);
            DB::selectOne("select set_config('app.authorization_phase', 'unknown', true)");
            DB::selectOne("select set_config('app.workspace_id', ?, true)", [(string) $firstWorkspace]);
            $this->assertSame([], DB::table('workspaces')->pluck('id')->all());
        } finally {
            DB::rollBack();
        }
    }

    public function test_phase_only_tampering_does_not_reopen_principal_visibility(): void
    {
        [$user, $firstWorkspace] = $this->fixture('active');
        [, $secondWorkspace] = $this->fixture('active');
        DB::connection('pgsql_migrations')->table('workspace_memberships')
            ->where('workspace_id', $secondWorkspace)
            ->update(['user_id' => $user->getKey()]);

        $visible = $this->runWorkspace(
            $user,
            $this->publicId($firstWorkspace),
            function () {
                DB::selectOne("select set_config('app.authorization_phase', 'principal', true)");

                return DB::table('workspaces')->orderBy('id')->pluck('id')->all();
            },
        );

        $this->assertSame([], $visible);
        $this->assertNotContains($secondWorkspace, $visible);
    }

    public function test_principal_discovery_requires_active_workspace_and_active_membership(): void
    {
        [$user, $activeWorkspace] = $this->fixture('active');
        [, $archivedWorkspace] = $this->fixture('active');
        [, $revokedWorkspace] = $this->fixture('revoked');
        $owner = DB::connection('pgsql_migrations');
        $owner->table('workspace_memberships')
            ->whereIn('workspace_id', [$archivedWorkspace, $revokedWorkspace])
            ->update(['user_id' => $user->getKey()]);
        $owner->table('workspaces')->where('id', $archivedWorkspace)->update(['status' => 'archived']);

        DB::beginTransaction();
        try {
            app(PrincipalContext::class)->activate($user);
            $visible = DB::table('workspaces')->orderBy('id')->pluck('id')->all();
            $this->assertSame([$activeWorkspace], $visible);
            $this->assertNotContains($archivedWorkspace, $visible);
            $this->assertNotContains($revokedWorkspace, $visible);
        } finally {
            DB::rollBack();
        }
    }

    public function test_principal_context_disappears_after_commit_and_rollback(): void
    {
        [$user] = $this->fixture('active');

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

    public function test_missing_workspace_context_hides_reads_and_blocks_writes(): void
    {
        $this->fixture('active');

        $this->assertSame(0, DB::table('workspaces')->count());

        try {
            DB::table('workspaces')->insert([
                'public_id' => (string) Str::uuid7(),
                'name' => 'Forbidden',
                'slug' => Str::lower(Str::random(12)),
                'status' => 'active',
                'currency_code' => 'USD',
                'timezone' => 'UTC',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A workspace write without context must be blocked by RLS.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_invalid_workspace_context_fails_closed(): void
    {
        [$user] = $this->fixture('active');
        DB::beginTransaction();

        try {
            app(PrincipalContext::class)->activate($user);
            DB::selectOne("select set_config('app.authorization_phase', 'workspace', true)");
            DB::selectOne("select set_config('app.workspace_id', ?, true)", ['invalid']);
            $this->assertSame(0, DB::table('workspaces')->count());
        } finally {
            DB::rollBack();
        }
    }

    public function test_missing_or_revoked_membership_is_denied(): void
    {
        [$user, $workspace] = $this->fixture('revoked');

        $this->expectException(AuthorizationException::class);
        $this->runWorkspace($user, $this->publicId($workspace), fn () => null);
    }

    public function test_absent_membership_is_denied(): void
    {
        [$user] = $this->fixture('active');
        [, $workspace] = $this->fixture('active');

        $this->expectException(AuthorizationException::class);
        $this->runWorkspace($user, $this->publicId($workspace), fn () => null);
    }

    public function test_public_workspace_identifier_is_the_only_http_candidate_and_grants_no_authority(): void
    {
        [$user, $workspace] = $this->fixture('active');
        [, $otherWorkspace] = $this->fixture('active');

        $this->actingAs($user)->withCredentials();
        $this->getJson("/workspaces/{$workspace}/foundation")->assertNotFound();
        $this
            ->getJson('/workspaces/'.$this->publicId($otherWorkspace).'/foundation')
            ->assertForbidden();
        $this
            ->getJson('/workspaces/'.$this->publicId($workspace).'/foundation')
            ->assertOk()
            ->assertJsonMissingPath('workspace.id')
            ->assertJsonPath('workspace.public_id', $this->publicId($workspace));
    }

    public function test_explicitly_empty_invalid_and_mismatched_workspace_contexts_fail_closed(): void
    {
        [$user] = $this->fixture('active');
        [, $otherWorkspace] = $this->fixture('active');
        $connection = DB::connection();

        $connection->beginTransaction();
        try {
            app(PrincipalContext::class)->activate($user);
            $connection->selectOne("select set_config('app.authorization_phase', 'workspace', true)");
            $connection->selectOne("select set_config('app.workspace_id', '', true)");
            $this->assertSame(0, $connection->table('workspaces')->count());
        } finally {
            $connection->rollBack();
        }

        $connection->beginTransaction();
        try {
            app(PrincipalContext::class)->activate($user);
            $connection->selectOne("select set_config('app.authorization_phase', 'workspace', true)");
            $connection->selectOne("select set_config('app.workspace_id', ?, true)", [(string) $otherWorkspace]);
            $this->assertSame(0, $connection->table('workspaces')->count());
        } finally {
            $connection->rollBack();
        }

        $connection->beginTransaction();
        try {
            app(PrincipalContext::class)->activate($user);
            $connection->selectOne("select set_config('app.authorization_phase', 'workspace', true)");
            $connection->selectOne("select set_config('app.workspace_id', 'invalid', true)");
            $this->assertSame(0, $connection->table('workspaces')->count());
        } finally {
            $connection->rollBack();
        }
    }

    public function test_context_rollback_clears_transaction_local_settings(): void
    {
        [$user, $workspace] = $this->fixture('active');

        try {
            $this->runWorkspace($user, $this->publicId($workspace), function () {
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }

        $this->assertSame('', DB::scalar("select current_setting('app.principal_user_id', true)"));
        $this->assertSame('', DB::scalar("select current_setting('app.workspace_id', true)"));
        $this->assertSame('', DB::scalar("select current_setting('app.authorization_phase', true)"));
    }

    public function test_context_commit_returns_protected_data_and_clears_transaction_local_settings(): void
    {
        [$user, $workspace] = $this->fixture('active');

        $visible = $this->runWorkspace(
            $user,
            $this->publicId($workspace),
            fn () => DB::table('workspaces')->pluck('id')->all(),
        );

        $this->assertSame([$workspace], $visible);
        $this->assertSame('', DB::scalar("select current_setting('app.principal_user_id', true)"));
        $this->assertSame('', DB::scalar("select current_setting('app.workspace_id', true)"));
        $this->assertSame('', DB::scalar("select current_setting('app.authorization_phase', true)"));
    }

    public function test_reused_connection_does_not_leak_context_between_workspaces(): void
    {
        [$firstUser, $firstWorkspace] = $this->fixture('active');
        [$secondUser, $secondWorkspace] = $this->fixture('active');

        $first = $this->runWorkspace($firstUser, $this->publicId($firstWorkspace), fn () => DB::table('workspaces')->pluck('id')->all());
        $second = $this->runWorkspace($secondUser, $this->publicId($secondWorkspace), fn () => DB::table('workspaces')->pluck('id')->all());

        $this->assertSame([$firstWorkspace], $first);
        $this->assertSame([$secondWorkspace], $second);
        $this->assertSame('', DB::scalar("select current_setting('app.principal_user_id', true)"));
        $this->assertSame('', DB::scalar("select current_setting('app.workspace_id', true)"));
    }

    public function test_archived_workspace_is_denied_even_with_active_membership(): void
    {
        [$user, $workspace] = $this->fixture('active');
        DB::connection('pgsql_migrations')->table('workspaces')->where('id', $workspace)->update(['status' => 'archived']);

        $this->expectException(AuthorizationException::class);
        $this->runWorkspace($user, $this->publicId($workspace), fn () => null);
    }

    public function test_context_fails_closed_inside_an_unowned_outer_transaction(): void
    {
        [$user, $workspace] = $this->fixture('active');
        DB::beginTransaction();

        try {
            $this->expectException(LogicException::class);
            $this->runWorkspace($user, $this->publicId($workspace), fn () => null);
        } finally {
            DB::rollBack();
        }
    }

    public function test_rls_prevents_cross_workspace_reads_even_with_direct_queries(): void
    {
        [$user, $workspace] = $this->fixture('active');
        [, $otherWorkspace] = $this->fixture('active');

        $ids = $this->runWorkspace(
            $user,
            $this->publicId($workspace),
            fn () => DB::table('workspaces')->orderBy('id')->pluck('id')->all(),
        );

        $this->assertSame([$workspace], $ids);
        $this->assertNotContains($otherWorkspace, $ids);
    }

    public function test_runtime_cannot_mutate_membership_even_inside_an_authorized_workspace_context(): void
    {
        [$user, $workspace] = $this->fixture('active');

        try {
            $this->runWorkspace(
                $user,
                $this->publicId($workspace),
                fn () => DB::table('workspace_memberships')
                    ->where('workspace_id', $workspace)
                    ->update(['role' => 'admin']),
            );
            $this->fail('Runtime membership mutation must be denied.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
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
            "select pg_get_userbyid(c.relowner) as owner from pg_class c where c.oid = 'public.workspaces'::regclass"
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
            'alter table workspaces add column forbidden_runtime_column text',
            'drop table workspace_memberships',
        ] as $statement) {
            try {
                DB::statement($statement);
                $this->fail("Runtime DDL unexpectedly succeeded: {$statement}");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_runtime_has_read_only_workspace_and_membership_privileges(): void
    {
        foreach (['workspaces', 'workspace_memberships'] as $table) {
            $this->assertTrue(DB::scalar("select has_table_privilege(current_user, '{$table}', 'SELECT')"));
            $this->assertFalse(DB::scalar("select has_table_privilege(current_user, '{$table}', 'INSERT')"));
            $this->assertFalse(DB::scalar("select has_table_privilege(current_user, '{$table}', 'UPDATE')"));
            $this->assertFalse(DB::scalar("select has_table_privilege(current_user, '{$table}', 'DELETE')"));
        }
    }

    public function test_workspace_currency_is_nullable(): void
    {
        $nullable = DB::connection('pgsql_migrations')->table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'workspaces')
            ->where('column_name', 'currency_code')
            ->value('is_nullable');

        $this->assertSame('YES', $nullable);
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

    /** @return array{0: User, 1: int} */
    private function fixture(string $status): array
    {
        $owner = DB::connection('pgsql_migrations');
        $userId = $owner->table('users')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'name' => 'Foundation User '.Str::random(8),
            'email' => Str::random(12).'@example.test',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $owner->table('principal_lifecycles')->insert([
            'user_id' => $userId,
            'status' => 'current',
            'generation' => 1,
            'cause' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $workspaceId = $owner->table('workspaces')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'name' => "Workspace {$status}",
            'slug' => Str::lower(Str::random(12)),
            'status' => 'active',
            'currency_code' => 'USD',
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $owner->table('workspace_memberships')->insert([
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'role' => 'owner',
            'status' => $status,
            'created_at' => now(),
            'revoked_at' => $status === 'revoked' ? now() : null,
        ]);
        $owner->table('workspace_ownerships')->insert([
            'workspace_id' => $workspaceId,
            'responsible_owner_user_id' => $userId,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($status === 'active') {
            $owner->table('security_action_grants')->insert([
                'subject_user_id' => $userId,
                'source' => 'system_workspace_creation',
                'action' => 'workspace.view',
                'scope_kind' => 'workspace',
                'workspace_id' => $workspaceId,
                'subject_generation' => 1,
                'reason' => 'workspace fixture',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [User::query()->findOrFail($userId), $workspaceId];
    }

    private function publicId(int $workspaceId): string
    {
        return DB::connection('pgsql_migrations')
            ->table('workspaces')
            ->where('id', $workspaceId)
            ->value('public_id');
    }

    private function runWorkspace(User $user, string $workspacePublicId, \Closure $effect): mixed
    {
        if (DB::connection()->transactionLevel() === 0) {
            $this->actingAs($user);
        }
        $request = Request::create('/workspaces/'.$workspacePublicId, 'GET');
        $request->setLaravelSession($this->app['session']->driver());
        $request->setUserResolver(fn () => $user);

        return app(WorkspaceContext::class)->run($user, $workspacePublicId, $effect, $request);
    }

    /** @return array{phase: ?string, principal: ?string, workspace: ?string} */
    private function authorizationState(): array
    {
        $state = DB::selectOne(<<<'SQL'
            select
                current_setting('app.authorization_phase', true) as phase,
                current_setting('app.principal_user_id', true) as principal,
                current_setting('app.workspace_id', true) as workspace
            SQL);

        return [
            'phase' => $state->phase,
            'principal' => $state->principal,
            'workspace' => $state->workspace,
        ];
    }
}
