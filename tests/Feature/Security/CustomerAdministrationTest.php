<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Security\GrantAuthority;
use App\Security\LifecycleAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerAdministrationTest extends TestCase
{
    public function test_directory_is_founder_only_searchable_and_excludes_internal_personnel(): void
    {
        $customer = User::factory()->create(['name' => 'Customer Proof']);
        $founder = $this->staff('founder');
        $this->actingAsInternal($founder)->getJson('/internal/administration/customers?search=Customer%20Proof')
            ->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.public_id', $customer->public_id)
            ->assertJsonPath('data.total', 1)->assertJsonMissingPath('data.rows.0.password');
        $this->getJson('/internal/administration/customers?selected='.$customer->public_id)->assertOk()
            ->assertJsonPath('data.details.name', 'Customer Proof');
        $this->actingAsInternal($this->staff())->get('/internal/administration/customers')->assertForbidden();
    }

    public function test_account_suspension_is_atomic_replayable_and_reactivation_does_not_restore_old_sessions_or_grants(): void
    {
        [$customer, $workspace] = $this->workspace();
        $db = DB::connection('pgsql_migrations');
        $sessions = $db->table('security_sessions')->where('user_id', $customer->id)->count();
        $this->assertGreaterThan(0, $sessions);
        $this->actingAsInternal($this->staff('founder'))->withCredentials();
        $input = $this->decision('users', $customer->public_id, 'current', 1);
        $this->postJson('/internal/administration/customers/status', $input)->assertOk()->assertJsonPath('revision', 2);
        $this->postJson('/internal/administration/customers/status', $input)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson('/internal/administration/customers/status', [...$input, 'reason' => 'changed'])->assertConflict();
        $this->assertSame('active', $db->table('workspaces')->where('id', $workspace->id)->value('status'));
        $this->assertSame(0, $db->table('security_sessions')->where('user_id', $customer->id)->whereNull('revoked_at')->count());
        $this->assertSame(1, $db->table('customer_administration_events')->count());
        $this->assertFalse(app(LifecycleAuthority::class)->isCurrent($customer, 1));
        $restore = $this->decision('users', $customer->public_id, 'disabled', 2, 'reactivate');
        $this->postJson('/internal/administration/customers/status', $restore)->assertOk()->assertJsonPath('revision', 3);
        $this->assertSame(0, $db->table('security_sessions')->where('user_id', $customer->id)->whereNull('revoked_at')->count());
        $this->assertSame(0, $db->table('security_action_grants')->where('subject_user_id', $customer->id)->where('subject_generation', 3)->whereNotNull('workspace_id')->count());
        $this->assertSame(1, $db->table('security_action_grants')->where('subject_user_id', $customer->id)->where('subject_generation', 3)->where('action', 'workspace.create')->count());
        $this->assertFalse(app(LifecycleAuthority::class)->isCurrent($customer, 1));
    }

    public function test_workspace_suspension_blocks_existing_tenant_access_and_reactivation_preserves_memberships(): void
    {
        $this->usePersistentTestSessions();
        [$customer, $workspace] = $this->workspace();
        $this->actingAs($customer)->get('/workspaces/'.$workspace->public_id.'/foundation')->assertOk();
        $this->actingAsInternal($this->staff('founder'))->withCredentials();
        $input = $this->decision('workspaces', $workspace->public_id, 'active', 0);
        $this->postJson('/internal/administration/customers/status', $input)->assertOk()->assertJsonPath('status', 'suspended');
        $this->getJson('/internal/administration/customers?kind=workspaces&selected='.$workspace->public_id)->assertOk()
            ->assertJsonPath('data.rows.0.status', 'suspended')->assertJsonCount(1, 'data.details.members')->assertJsonCount(1, 'data.details.history');
        $this->actingAs($customer)->get('/workspaces/'.$workspace->public_id.'/foundation')->assertForbidden();
        $this->actingAsInternal($this->staff('founder'))->withCredentials();
        $this->postJson('/internal/administration/customers/status', $this->decision('workspaces', $workspace->public_id, 'active', 0, 'reactivate'))->assertConflict();
        $this->postJson('/internal/administration/customers/status', $this->decision('workspaces', $workspace->public_id, 'suspended', 1, 'reactivate'))->assertOk();
        $this->actingAs($customer)->get('/workspaces/'.$workspace->public_id.'/foundation')->assertOk();
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('workspace_memberships')->where('workspace_id', $workspace->id)->where('status', 'active')->count());
    }

    public function test_internal_accounts_and_security_disabled_accounts_cannot_be_reactivated_by_customer_administration(): void
    {
        $db = DB::connection('pgsql_migrations');
        $customer = User::factory()->create();
        $db->table('principal_lifecycles')->where('user_id', $customer->id)->update(['status' => 'disabled', 'generation' => 2]);
        $employee = $this->staff();
        $this->actingAsInternal($this->staff('founder'))->withCredentials();
        $this->postJson('/internal/administration/customers/status', $this->decision('users', $employee->public_id, 'current', 1))->assertNotFound();
        $this->postJson('/internal/administration/customers/status', $this->decision('users', $customer->public_id, 'disabled', 2, 'reactivate'))->assertUnprocessable();
        $this->assertSame('disabled', $db->table('principal_lifecycles')->where('user_id', $customer->id)->value('status'));
    }

    public function test_failed_audit_rolls_back_lifecycle_generation_and_session_revocation(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer);
        $this->actingAsInternal($this->staff('founder'))->withCredentials();
        $db = DB::connection('pgsql_migrations');
        $db->unprepared("CREATE FUNCTION customer_test_failure() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'synthetic audit failure'; END \$\$; CREATE TRIGGER customer_test_failure BEFORE INSERT ON customer_administration_events FOR EACH ROW EXECUTE FUNCTION customer_test_failure()");
        try {
            $this->postJson('/internal/administration/customers/status', $this->decision('users', $customer->public_id, 'current', 1))->assertStatus(500);
        } finally {
            $db->unprepared('DROP TRIGGER customer_test_failure ON customer_administration_events; DROP FUNCTION customer_test_failure()');
        }
        $this->assertSame('current', $db->table('principal_lifecycles')->where('user_id', $customer->id)->value('status'));
        $this->assertSame(1, $db->table('principal_lifecycles')->where('user_id', $customer->id)->value('generation'));
        $this->assertSame(1, $db->table('security_sessions')->where('user_id', $customer->id)->whereNull('revoked_at')->count());
        $this->assertSame(0, $db->table('customer_administration_events')->count());
    }

    public function test_employee_and_stale_founder_cannot_execute_status_changes(): void
    {
        $customer = User::factory()->create();
        $input = $this->decision('users', $customer->public_id, 'current', 1);
        $this->actingAsInternal($this->staff())->withCredentials()->postJson('/internal/administration/customers/status', $input)->assertForbidden();
        $founder = $this->staff('founder');
        $this->actingAsInternal($founder)->withCredentials();
        DB::connection('pgsql_migrations')->table('security_sessions')->where('user_id', $founder->id)->update(['authenticated_at' => now()->subMinutes(6)]);
        $this->postJson('/internal/administration/customers/status', $input)->assertForbidden();
    }

    public function test_pending_account_reactivation_does_not_bypass_email_verification(): void
    {
        $customer = User::factory()->unverified()->create();
        $this->actingAsInternal($this->staff('founder'))->withCredentials();
        $this->postJson('/internal/administration/customers/status', $this->decision('users', $customer->public_id, 'pending', 0))->assertOk();
        $this->postJson('/internal/administration/customers/status', $this->decision('users', $customer->public_id, 'disabled', 1, 'reactivate'))->assertOk()->assertJsonPath('status', 'pending');
        $this->assertNull($customer->fresh()->email_verified_at);
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('security_action_grants')->where('subject_user_id', $customer->id)->where('action', 'workspace.create')->count());
    }

    public function test_restored_owner_can_explicitly_reassess_only_unrevoked_prior_workspace_grants_after_new_login(): void
    {
        $this->usePersistentTestSessions();
        [$customer, $workspace] = $this->workspace();
        $founder = $this->staff('founder');
        $this->actingAsInternal($founder)->withCredentials();
        $this->postJson('/internal/administration/customers/status', $this->decision('users', $customer->public_id, 'current', 1))->assertOk();
        $this->postJson('/internal/administration/customers/status', $this->decision('users', $customer->public_id, 'disabled', 2, 'reactivate'))->assertOk();
        // Simulate a new authenticated session through the actual session issuer.
        $this->app['session']->driver()->regenerate();
        $this->actingAs($customer)->get('/workspaces/'.$workspace->public_id.'/foundation')->assertForbidden();
        $this->getJson('/workspaces')->assertOk()->assertJsonCount(1, 'data.reassessableWorkspaces')
            ->assertJsonPath('data.reassessableWorkspaces.0.public_id', $workspace->public_id);
        $this->post('/workspaces/'.$workspace->public_id.'/reassess-owner-grant')->assertRedirect();
        $this->get('/workspaces/'.$workspace->public_id.'/foundation')->assertOk();
        DB::connection('pgsql_migrations')->table('security_action_grants')->where('subject_user_id', $customer->id)->where('action', 'workspace.view')->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->post('/workspaces/'.$workspace->public_id.'/reassess-owner-grant')->assertForbidden();
    }

    private function decision(string $kind, string $target, string $status, int $revision, string $decision = 'suspend'): array
    {
        return ['command_id' => (string) Str::uuid7(), 'kind' => $kind, 'target' => $target, 'decision' => $decision,
            'reason' => 'Owner administrative review', 'expected_status' => $status, 'expected_revision' => $revision];
    }

    private function staff(string $kind = 'employee'): User
    {
        $user = User::factory()->create();
        $db = DB::connection('pgsql_migrations');
        $db->table('principal_lifecycles')->where('user_id', $user->id)->update(['principal_class' => 'staff']);
        $db->table('internal_personnel')->insert(['user_id' => $user->id, 'kind' => $kind, 'status' => 'active', 'attested_by' => $user->id,
            'attested_at' => now(), 'factor_independence_attested_at' => $kind === 'founder' ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
        foreach (range(1, $kind === 'founder' ? 2 : 1) as $factor) {
            $db->table('passkeys')->insert(['user_id' => $user->id, 'name' => 'Synthetic factor', 'credential_id' => 'customer-proof-'.$user->id.'-'.$factor, 'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $user;
    }

    private function workspace(): array
    {
        $owner = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($owner, 1);
        $this->actingAs($owner)->post('/workspaces', ['name' => 'Customer Space', 'slug' => 'customer-space'])->assertRedirect();
        $db = DB::connection('pgsql_migrations');
        $workspace = $db->table('workspaces')->where('slug', 'customer-space')->first();
        $workspace = $db->table('workspaces')->where('id', $workspace->id)->first();

        return [$owner, $workspace];
    }

    public function test_shell_and_witness_use_the_same_founder_authority_without_directory_projection(): void
    {
        $this->actingAsInternal($this->staff('founder'));
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $this->get('/internal/administration/customers')->assertOk()->assertViewIs('spa');
        $this->getJson('/internal/administration/customers', ['X-Builderad-Witness' => '1'])
            ->assertOk()->assertJsonMissingPath('data')->assertJsonPath('visibility.protected', true);
        $this->assertTrue(collect($queries)->contains(fn ($sql) => str_contains($sql, 'builderad_customer_directory_witness()')));
        $this->assertFalse(collect($queries)->contains(fn ($sql) => str_contains($sql, 'builderad_customer_directory(')));
        $this->actingAsInternal($this->staff())->getJson('/internal/administration/customers', ['X-Builderad-Witness' => '1'])->assertForbidden();
    }

    public function test_directory_witness_upgrade_is_repeatable_and_does_not_grant_private_authority(): void
    {
        $owner = DB::connection('pgsql_migrations');
        $before = $owner->table('users')->count();
        $owner->statement('DROP FUNCTION builderad_customer_directory_witness()');
        $definition = file_get_contents(database_path('sql/customer_directory_witness.sql'));
        $owner->unprepared($definition);
        $owner->unprepared($definition);
        $this->assertSame($before, $owner->table('users')->count());
        foreach (['builderad_app' => true, 'builderad_authority' => false] as $role => $allowed) {
            $this->assertSame($allowed, $owner->scalar("SELECT has_function_privilege(?, 'builderad_customer_directory_witness()', 'EXECUTE')", [$role]));
            $this->assertFalse($owner->scalar("SELECT has_function_privilege(?, 'builderad_customer_administrator(text)', 'EXECUTE')", [$role]));
        }
    }

    public function test_witness_function_has_exact_owner_search_path_and_effective_privileges(): void
    {
        $owner = DB::connection('pgsql_migrations');
        $function = $owner->selectOne("SELECT r.rolname AS owner, p.prosecdef, p.proconfig::text AS settings, pg_get_functiondef(p.oid) AS definition FROM pg_proc p JOIN pg_roles r ON r.oid=p.proowner WHERE p.oid='public.builderad_customer_directory_witness()'::regprocedure");
        $this->assertSame('builderad_owner', $function->owner);
        $this->assertTrue($function->prosecdef);
        $this->assertStringContainsString('search_path=public, pg_temp', $function->settings);
        $this->assertStringContainsString('PERFORM builderad_customer_administrator()', $function->definition);
        $this->assertFalse($owner->scalar("SELECT EXISTS(SELECT 1 FROM pg_proc p, LATERAL aclexplode(p.proacl) a WHERE p.oid='public.builderad_customer_directory_witness()'::regprocedure AND a.grantee=0 AND a.privilege_type='EXECUTE')"));
        foreach (['builderad_app', 'builderad_authority'] as $role) {
            $this->assertFalse($owner->scalar("SELECT has_schema_privilege(?, 'public', 'CREATE')", [$role]));
            $this->assertFalse($owner->scalar("SELECT has_function_privilege(?, 'public.builderad_customer_administrator(text)', 'EXECUTE')", [$role]));
        }
        $this->assertTrue($owner->scalar("SELECT has_function_privilege('builderad_app', 'public.builderad_customer_directory_witness()', 'EXECUTE')"));
        $this->assertFalse($owner->scalar("SELECT has_function_privilege('builderad_authority', 'public.builderad_customer_directory_witness()', 'EXECUTE')"));
    }

    public function test_witness_and_resource_both_deny_invalid_current_founder_authority(): void
    {
        $owner = DB::connection('pgsql_migrations');
        foreach (['idle-expired', 'revoked', 'inactive-founder', 'employee', 'advertiser'] as $scenario) {
            $principal = $scenario === 'advertiser' ? User::factory()->create() : $this->staff($scenario === 'employee' ? 'employee' : 'founder');
            if ($scenario === 'advertiser') {
                $this->actingAs($principal)->withCredentials();
            } else {
                $this->actingAsInternal($principal)->withCredentials();
            }
            if ($scenario === 'idle-expired') {
                $owner->table('security_sessions')->where('user_id', $principal->id)->update(['idle_expires_at' => now()->subMinute()]);
            } elseif ($scenario === 'revoked') {
                $owner->table('security_sessions')->where('user_id', $principal->id)->update(['revoked_at' => now()]);
            } elseif ($scenario === 'inactive-founder') {
                $owner->table('internal_personnel')->where('user_id', $principal->id)->update(['status' => 'revoked']);
            }
            foreach ([['X-Builderad-Witness' => '1'], []] as $headers) {
                $response = $this->getJson('/internal/administration/customers', $headers);
                $this->assertContains($response->getStatusCode(), [403, 409], $scenario.' must deny both representations: '.$response->getContent());
                $response->assertJsonMissingPath('data.rows');
            }
        }
    }

    public function test_witness_execute_permission_alone_never_supplies_principal_authority(): void
    {
        try {
            DB::transaction(fn () => DB::selectOne('SELECT public.builderad_customer_directory_witness()'));
            $this->fail('An unsigned runtime connection must not obtain a witness decision.');
        } catch (QueryException $error) {
            $this->assertSame('42501', $error->getCode());
        }
    }
}
