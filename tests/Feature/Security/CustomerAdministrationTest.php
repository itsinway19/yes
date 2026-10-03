<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Security\GrantAuthority;
use App\Security\LifecycleAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerAdministrationTest extends TestCase
{
    public function test_directory_is_founder_only_searchable_and_excludes_internal_personnel(): void
    {
        $customer = User::factory()->create(['name' => 'Customer Proof']);
        $founder = $this->staff('founder');
        $this->actingAsInternal($founder)->get('/internal/administration/customers?search=Customer%20Proof')
            ->assertOk()->assertInertia(fn (Assert $p) => $p->component('Internal/Customers')->has('rows', 1)
            ->where('rows.0.public_id', $customer->public_id)->where('total', 1)->missing('rows.0.password'));
        $this->get('/internal/administration/customers?selected='.$customer->public_id)->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('details.name', 'Customer Proof'));
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
        $this->get('/internal/administration/customers?kind=workspaces&selected='.$workspace->public_id)->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('rows.0.status', 'suspended')->has('details.members', 1)->has('details.history', 1));
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
        $this->get('/workspaces')->assertOk()->assertInertia(fn (Assert $p) => $p->has('reassessableWorkspaces', 1));
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
        $db->table('workspaces')->where('id', $workspace->id)->update(['business_type_id' => $db->table('business_types')->where('key', 'real_estate')->value('id')]);

        return [$owner, $workspace];
    }
}
