<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DatabaseAuthorityBoundaryTest extends TestCase
{
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
            'service_business_admin_scopes' => 'status',
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
            'SELECT * FROM builderad_service_grant_target(NULL::uuid,NULL::uuid)',
            "SELECT builderad_activate_principal_context('forged')",
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

    public function test_forged_settings_and_reused_session_cannot_read_or_write_another_business(): void
    {
        $user = User::factory()->create();
        $business = $this->actingAs($user)->postJson('/api/businesses', [
            'command_id' => (string) Str::uuid7(), 'name' => 'Private Business',
        ])->assertCreated()->json('data');
        $owner = DB::connection('pgsql_migrations');
        $sessionHash = $owner->table('security_sessions')->where('user_id', $user->id)->value('session_key_hash');
        $runtime = DB::connection('pgsql');
        $forged = (string) Str::uuid7();
        foreach ([
            ['SELECT builderad_v2_business_surface(?,?::uuid,?)', [$sessionHash, $business['business_public_id'], $business['grant_refs']['business.context.read']], '42501'],
            ['SELECT builderad_v2_business_create_draft(?,?::uuid,?,?)', [$sessionHash, $forged, 'Forged Business', null], '22023'],
        ] as [$sql, $bindings, $denial]) {
            try {
                $runtime->transaction(function () use ($runtime, $user, $sql, $bindings): void {
                    $runtime->selectOne("SELECT set_config('app.authorization_phase','principal',true),set_config('app.principal_user_id',?,true)", [(string) $user->id]);
                    $runtime->scalar($sql, $bindings);
                });
                $this->fail('Caller-supplied settings established Business authority.');
            } catch (QueryException $exception) {
                $this->assertSame($denial, $exception->getCode());
            }
        }
        $this->assertSame(1, $owner->table('businesses')->count());
        $this->assertFalse($owner->table('business_command_receipts')->where('command_id', $forged)->exists());
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
