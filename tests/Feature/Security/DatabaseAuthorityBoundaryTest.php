<?php

namespace Tests\Feature\Security;

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DatabaseAuthorityBoundaryTest extends TestCase
{
    public function test_workspace_reader_is_denied_when_responsible_owner_loses_advertiser_eligibility(): void
    {
        $ownerId = $this->seedCurrentAdvertiser('owner');
        $viewerId = $this->seedCurrentAdvertiser('viewer');
        $databaseOwner = DB::connection('pgsql_migrations');
        $workspaceId = $databaseOwner->table('workspaces')->insertGetId([
            'name' => 'Owner Continuity', 'slug' => 'owner-continuity',
            'status' => 'active', 'timezone' => 'UTC',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $databaseOwner->table('workspace_ownerships')->insert([
            'workspace_id' => $workspaceId, 'responsible_owner_user_id' => $ownerId,
            'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([[$ownerId, 'owner'], [$viewerId, 'member']] as [$userId, $role]) {
            $databaseOwner->table('workspace_memberships')->insert([
                'workspace_id' => $workspaceId, 'user_id' => $userId,
                'role' => $role, 'status' => 'active', 'created_at' => now(),
            ]);
        }
        $databaseOwner->table('security_action_grants')->insert([
            'subject_user_id' => $viewerId, 'source' => 'system_workspace_creation',
            'action' => 'workspace.view', 'scope_kind' => 'workspace',
            'workspace_id' => $workspaceId, 'subject_generation' => 1,
            'reason' => 'security_fixture', 'status' => 'active', 'version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $runtime = DB::connection('pgsql');
        $viewer = User::findOrFail($viewerId);
        $runtime->transaction(function () use ($runtime, $viewer, $workspaceId): void {
            app(PrincipalContext::class)->activate($viewer);
            $runtime->selectOne('SELECT builderad_v2_legacy_enter((SELECT public_id FROM workspaces WHERE id=?))', [$workspaceId]);
            $runtime->selectOne('SELECT builderad_select_workspace_context(?)', [$workspaceId]);
            $this->assertSame(1, $runtime->table('workspaces')->count());
        });

        $databaseOwner->table('principal_lifecycles')->where('user_id', $ownerId)
            ->update(['principal_class' => 'staff']);
        $runtime->beginTransaction();
        try {
            app(PrincipalContext::class)->activate($viewer);
            $runtime->selectOne('SELECT builderad_v2_legacy_enter((SELECT public_id FROM workspaces WHERE id=?))', [$workspaceId]);
            $runtime->selectOne('SELECT builderad_select_workspace_context(?)', [$workspaceId]);
            $this->fail('Workspace access survived loss of responsible Owner eligibility.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->getCode());
        } finally {
            $runtime->rollBack();
        }
    }

    public function test_runtime_role_cannot_mutate_authority_tables_or_audit_directly(): void
    {
        $columns = [
            'users' => 'password',
            'password_reset_tokens' => 'token',
            'principal_lifecycles' => 'status',
            'security_sessions' => 'revoked_at',
            'passkeys' => 'name',
            'internal_personnel' => 'status',
            'security_action_grants' => 'status',
            'security_audit_events' => 'event',
            'service_identities' => 'status',
            'service_action_grants' => 'status',
            'service_workspace_scopes' => 'status',
            'service_audit_events' => 'event',
        ];
        $runtime = DB::connection('pgsql');

        foreach ($columns as $table => $column) {
            foreach ([
                "INSERT INTO {$table} DEFAULT VALUES",
                "UPDATE {$table} SET {$column} = {$column} WHERE false",
                "DELETE FROM {$table} WHERE false",
            ] as $statement) {
                $runtime->beginTransaction();
                try {
                    $runtime->statement($statement);
                    $this->fail("Restricted runtime SQL succeeded: {$statement}");
                } catch (QueryException $exception) {
                    $this->assertSame('42501', $exception->getCode(), $statement);
                } finally {
                    $runtime->rollBack();
                }
            }
        }
    }

    public function test_runtime_role_cannot_call_operator_only_writer_or_forge_a_principal_ticket(): void
    {
        $runtime = DB::connection('pgsql');
        foreach ([
            'SELECT builderad_lock_and_has_active_workspace_membership(1)',
            "SELECT builderad_activate_principal_context('forged')",
            'SELECT builderad_record_service_workspace_access()',
        ] as $statement) {
            $runtime->beginTransaction();
            try {
                $runtime->statement($statement);
                $this->fail("Unauthorized privileged function succeeded: {$statement}");
            } catch (QueryException $exception) {
                $this->assertSame('42501', $exception->getCode(), $statement);
            } finally {
                $runtime->rollBack();
            }
        }
    }

    public function test_runtime_role_cannot_mutate_lifecycle_without_its_authority_writer(): void
    {
        $userId = $this->seedCurrentAdvertiser();
        $runtime = DB::connection('pgsql');
        $runtime->beginTransaction();

        try {
            $runtime->table('principal_lifecycles')->where('user_id', $userId)->update([
                'status' => 'disabled', 'generation' => 2, 'cause' => 'forged_direct_sql',
            ]);
            $this->fail('The restricted runtime role changed lifecycle state directly.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->getCode());
        } finally {
            $runtime->rollBack();
        }

        $this->assertDatabaseHas('principal_lifecycles', [
            'user_id' => $userId, 'status' => 'current', 'generation' => 1,
        ], 'pgsql_migrations');
    }

    public function test_caller_controlled_transaction_settings_cannot_reveal_another_principals_workspace(): void
    {
        $userId = $this->seedCurrentAdvertiser();
        $owner = DB::connection('pgsql_migrations');
        $workspaceId = $owner->table('workspaces')->insertGetId([
            'name' => 'Private Workspace', 'slug' => 'authority-boundary-private',
            'status' => 'active', 'timezone' => 'UTC',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $owner->table('workspace_memberships')->insert([
            'workspace_id' => $workspaceId, 'user_id' => $userId,
            'role' => 'owner', 'status' => 'active', 'created_at' => now(),
        ]);

        $runtime = DB::connection('pgsql');
        $runtime->transaction(function () use ($runtime, $userId): void {
            $this->assertSame(0, $runtime->table('workspaces')->count());
            $runtime->selectOne("SELECT set_config('app.authorization_phase', 'principal', true)");
            $runtime->selectOne("SELECT set_config('app.principal_user_id', ?, true)", [(string) $userId]);
            $runtime->selectOne("SELECT set_config('app.workspace_id', '', true)");
            $this->assertSame(0, $runtime->table('workspaces')->count());
        });
    }

    public function test_runtime_role_cannot_invoke_workspace_writer_with_reused_session_hash(): void
    {
        $userId = $this->seedCurrentAdvertiser();
        $owner = DB::connection('pgsql_migrations');
        $sessionHash = str_repeat('a', 64);
        $owner->table('security_sessions')->insert([
            'user_id' => $userId, 'session_key_hash' => $sessionHash,
            'lifecycle_generation' => 1, 'auth_method' => 'password',
            'authenticated_at' => now(), 'last_seen_at' => now(),
            'idle_expires_at' => now()->addHour(), 'absolute_expires_at' => now()->addHours(2),
        ]);
        $owner->table('security_action_grants')->insert([
            'subject_user_id' => $userId, 'source' => 'system_registration',
            'action' => 'workspace.create', 'scope_kind' => 'platform',
            'subject_generation' => 1, 'reason' => 'fixture', 'status' => 'active',
            'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $runtime = DB::connection('pgsql');
        $runtime->beginTransaction();
        try {
            $runtime->scalar('SELECT builderad_create_workspace(?, ?, ?, ?)', [
                $userId, $sessionHash, 'Forged Workspace', 'authority-boundary-forged',
            ]);
            $this->fail('The restricted runtime role invoked the privileged Workspace writer.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->getCode());
        } finally {
            $runtime->rollBack();
        }

        $this->assertDatabaseMissing('workspaces', ['slug' => 'authority-boundary-forged'], 'pgsql_migrations');
    }

    private function seedCurrentAdvertiser(string $suffix = 'default'): int
    {
        $owner = DB::connection('pgsql_migrations');
        $userId = $owner->table('users')->insertGetId([
            'public_id' => (string) Str::uuid(), 'name' => 'Authority Fixture',
            'email' => "authority-fixture-{$suffix}@example.test", 'password' => 'unused-test-hash',
            'email_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $owner->table('principal_lifecycles')->insert([
            'user_id' => $userId, 'status' => 'current', 'generation' => 1,
            'principal_class' => 'advertiser', 'cause' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $userId;
    }
}
