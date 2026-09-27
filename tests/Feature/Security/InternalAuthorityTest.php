<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Security\GrantAuthority;
use App\Security\LifecycleAuthority;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InternalAuthorityTest extends TestCase
{
    public function test_email_verification_alone_cannot_activate_pending_staff(): void
    {
        $staff = User::factory()->unverified()->create();
        DB::connection('pgsql_migrations')->table('principal_lifecycles')->where('user_id', $staff->id)
            ->update(['principal_class' => 'staff']);
        DB::connection('pgsql_migrations')->table('internal_personnel')->insert([
            'user_id' => $staff->id, 'kind' => 'employee', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(LifecycleAuthority::class)->verifyPrimaryEmail($staff);
        $this->assertSame(0, $result['generation']);
        $this->assertDatabaseHas('principal_lifecycles', [
            'user_id' => $staff->id, 'status' => 'pending', 'generation' => 0,
        ]);
    }

    public function test_pending_staff_password_session_is_confined_to_enrollment(): void
    {
        $staff = User::factory()->unverified()->create();
        DB::connection('pgsql_migrations')->table('principal_lifecycles')->where('user_id', $staff->id)
            ->update(['principal_class' => 'staff']);
        DB::connection('pgsql_migrations')->table('internal_personnel')->insert([
            'user_id' => $staff->id, 'kind' => 'employee', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->post('/login', ['email' => $staff->email, 'password' => 'password'])
            ->assertRedirect(route('staff.onboarding'));
        $this->get('/staff/onboarding')->assertOk();
        $this->get('/dashboard')->assertRedirect(route('verification.notice', absolute: false));
        $this->withCredentials()->getJson('/staff/onboarding/passkeys/options')->assertForbidden();

        app(LifecycleAuthority::class)->verifyPrimaryEmail($staff);
        auth()->user()->refresh();
        $this->get('/dashboard')->assertForbidden();
        $this->withCredentials()->getJson('/staff/onboarding/passkeys/options')->assertOk();
    }

    public function test_internal_password_login_is_denied_and_ineligible_staff_cannot_use_protected_routes(): void
    {
        $employee = $this->staff('active');
        $this->post('/login', ['email' => $employee->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($employee)->get('/dashboard')->assertOk();
        DB::connection('pgsql_migrations')->table('internal_personnel')->where('user_id', $employee->id)->update(['status' => 'revoked']);
        $this->get('/dashboard')->assertForbidden();
    }

    public function test_internal_administration_requires_an_explicit_current_grant(): void
    {
        $advertiser = User::factory()->create();
        $this->actingAs($advertiser)->get('/internal/administration')->assertForbidden();
        DB::connection('pgsql_migrations')->table('internal_personnel')->insert([
            'user_id' => $advertiser->id, 'kind' => 'employee', 'status' => 'active',
            'attested_by' => $advertiser->id, 'attested_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('pgsql_migrations')->table('security_action_grants')->insert([
            'subject_user_id' => $advertiser->id, 'granted_by' => null,
            'source' => 'founder_bootstrap',
            'action' => 'staff.view', 'scope_kind' => 'platform', 'subject_generation' => 1,
            'reason' => 'invalid class fixture', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->get('/internal/administration')->assertForbidden();

        $founder = $this->staff('active', 'founder');
        $this->actingAs($founder)->get('/internal/administration')->assertForbidden();

        DB::connection('pgsql_migrations')->table('security_action_grants')->insert([
            'subject_user_id' => $founder->id,
            'granted_by' => null,
            'source' => 'founder_bootstrap',
            'action' => 'staff.view',
            'scope_kind' => 'platform',
            'subject_generation' => 1,
            'reason' => 'founder bootstrap fixture',
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->get('/internal/administration')->assertOk();
        DB::connection('pgsql_migrations')->table('security_action_grants')->where('subject_user_id', $founder->id)->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->get('/internal/administration')->assertForbidden();
    }

    public function test_active_staff_request_preserves_fifteen_minute_idle_limit(): void
    {
        $employee = $this->staff('active');
        $this->actingAs($employee)->get('/dashboard')->assertOk();

        $session = DB::table('security_sessions')->where('user_id', $employee->id)->first();
        $remainingSeconds = now()->diffInSeconds(Carbon::parse($session->idle_expires_at));
        $this->assertGreaterThanOrEqual(890, $remainingSeconds);
        $this->assertLessThanOrEqual(900, $remainingSeconds);
    }

    public function test_staff_cannot_remove_required_passkey(): void
    {
        foreach (['employee', 'founder'] as $kind) {
            $person = $this->staff('active', $kind);
            $keyId = DB::table('passkeys')->where('user_id', $person->id)->orderBy('id')->value('id');
            $this->actingAs($person)->withCredentials()
                ->deleteJson('/security/passkeys/'.$keyId)->assertUnprocessable();
            $this->assertSame($kind === 'founder' ? 2 : 1,
                DB::table('passkeys')->where('user_id', $person->id)->count());
        }
    }

    public function test_staff_invitation_attestation_activation_and_revocation_are_separate(): void
    {
        Notification::fake();
        $founder = $this->staff('active', 'founder');
        foreach (['staff.view', 'staff.invite', 'staff.attest', 'staff.activate', 'staff.revoke', 'grant.manage'] as $action) {
            DB::connection('pgsql_migrations')->table('security_action_grants')->insert([
                'subject_user_id' => $founder->id, 'source' => 'founder_bootstrap',
                'action' => $action, 'scope_kind' => 'platform',
                'subject_generation' => 1, 'reason' => 'test bootstrap',
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->actingAs($founder);
        $this->post('/internal/staff', ['name' => 'New Employee', 'email' => 'employee@example.test'])
            ->assertRedirect();
        $employee = User::query()->where('email', 'employee@example.test')->firstOrFail();
        $this->assertDatabaseHas('principal_lifecycles', [
            'user_id' => $employee->id, 'principal_class' => 'staff',
            'status' => 'pending', 'generation' => 0,
        ]);
        $this->assertSame(0, DB::table('security_action_grants')
            ->where('subject_user_id', $employee->id)->count());
        $this->post('/internal/staff/'.$employee->public_id.'/resend-invitation')
            ->assertRedirect();
        $this->post('/internal/staff/'.$employee->public_id.'/activate')->assertForbidden();

        app(LifecycleAuthority::class)->verifyPrimaryEmail($employee);
        $employee->refresh();
        $this->post('/internal/staff/'.$employee->public_id.'/attest')->assertRedirect();
        $this->post('/internal/staff/'.$employee->public_id.'/activate')->assertForbidden();
        DB::connection('pgsql_migrations')->table('passkeys')->insert([
            'user_id' => $employee->id, 'name' => 'Verified authenticator',
            'credential_id' => 'staff-test-'.$employee->id, 'credential' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->post('/internal/staff/'.$employee->public_id.'/activate')->assertRedirect();
        $this->assertDatabaseHas('principal_lifecycles', [
            'user_id' => $employee->id, 'status' => 'current', 'generation' => 1,
        ]);
        $this->assertDatabaseHas('internal_personnel', [
            'user_id' => $employee->id, 'status' => 'active', 'attested_by' => $founder->id,
        ]);
        $this->post('/internal/staff/'.$employee->public_id.'/resend-invitation')
            ->assertForbidden();
        $this->assertSame(0, DB::table('security_action_grants')
            ->where('subject_user_id', $employee->id)->count());
        $this->post('/internal/grants', [
            'staff' => $employee->public_id,
            'action' => 'staff.view',
            'reason' => 'Ordinary administration view',
        ])->assertRedirect();
        $grantId = DB::table('security_action_grants')
            ->where('subject_user_id', $employee->id)->where('action', 'staff.view')->value('id');
        $this->assertNotNull($grantId);
        $this->actingAs($employee)->get('/internal/administration')->assertOk();
        $this->actingAs($founder)->delete('/internal/grants/'.$grantId)->assertRedirect();
        $this->actingAs($employee)->get('/internal/administration')->assertForbidden();
        $this->actingAs($founder);
        $this->post('/internal/staff/'.$employee->public_id.'/revoke')->assertRedirect();
        $this->assertDatabaseHas('principal_lifecycles', [
            'user_id' => $employee->id, 'status' => 'disabled', 'generation' => 2,
        ]);
        $this->assertDatabaseHas('internal_personnel', [
            'user_id' => $employee->id, 'status' => 'revoked',
        ]);
    }

    public function test_founder_bootstrap_is_one_time_atomic_and_revokes_old_sessions(): void
    {
        $founder = User::factory()->create();
        $this->actingAs($founder);
        foreach (['primary', 'backup'] as $factor) {
            $keyId = DB::connection('pgsql_migrations')->table('passkeys')->insertGetId([
                'user_id' => $founder->id, 'name' => $factor,
                'credential_id' => $factor.'-'.$founder->id, 'credential' => '{}',
                'created_at' => now()->subMinute(), 'updated_at' => now(), 'last_used_at' => now(),
            ]);
            DB::connection('pgsql_migrations')->table('security_audit_events')->insert([
                'subject_user_id' => $founder->id, 'event' => 'passkey_login_verified',
                'reason' => 'key:'.$keyId.';session:1;uv:1',
                'new_status' => 'current', 'new_generation' => 1, 'occurred_at' => now(),
            ]);
        }
        $input = [
            'user' => $founder->public_id,
            'primary' => 'primary-'.$founder->id,
            'backup' => 'backup-'.$founder->id,
            '--attest-independent-factors' => true,
            '--operator' => 'test-operator',
            '--independence-evidence' => 'synthetic-test-only',
        ];
        $this->assertSame(0, Artisan::call('security:bootstrap-founder', $input), Artisan::output());
        $this->assertDatabaseHas('principal_lifecycles', [
            'user_id' => $founder->id, 'principal_class' => 'staff',
            'status' => 'current', 'generation' => 2,
        ]);
        $this->assertDatabaseHas('internal_personnel', [
            'user_id' => $founder->id, 'kind' => 'founder', 'status' => 'active',
        ]);
        $this->assertSame(count(GrantAuthority::STAFF_ACTIONS),
            DB::table('security_action_grants')->where('subject_user_id', $founder->id)
                ->where('source', 'founder_bootstrap')->count());
        $this->assertSame(1, DB::table('security_sessions')->where('user_id', $founder->id)
            ->whereNotNull('revoked_at')->count());
        $this->get('/internal/administration')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame(1, Artisan::call('security:bootstrap-founder', $input));
        $this->assertSame(1, DB::table('internal_personnel')->where('kind', 'founder')->count());
    }

    public function test_employee_administrator_cannot_assign_or_revoke_sensitive_grants(): void
    {
        $founder = $this->staff('active', 'founder');
        DB::connection('pgsql_migrations')->table('security_action_grants')->insert([
            'subject_user_id' => $founder->id, 'source' => 'founder_bootstrap',
            'action' => 'grant.manage', 'scope_kind' => 'platform',
            'subject_generation' => 1, 'reason' => 'test bootstrap',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = $this->staff('active');
        $target = $this->staff('active');
        foreach ([$admin, $target] as $person) {
            DB::connection('pgsql_migrations')->table('passkeys')->insert([
                'user_id' => $person->id, 'name' => 'Backup authenticator',
                'credential_id' => 'backup-'.$person->id, 'credential' => '{}',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::connection('pgsql_migrations')->table('internal_personnel')->where('user_id', $admin->id)->update([
            'factor_independence_attested_at' => now(),
            'factor_independence_attested_by' => $founder->id,
        ]);

        $this->actingAs($founder)->post('/internal/grants', [
            'staff' => $admin->public_id, 'action' => 'grant.manage',
            'reason' => 'Ordinary staff grant administration',
        ])->assertRedirect();
        $this->post('/internal/staff/'.$target->public_id.'/attest-factors')->assertRedirect();
        $this->assertDatabaseHas('internal_personnel', [
            'user_id' => $target->id,
            'factor_independence_attested_by' => $founder->id,
        ]);
        $this->post('/internal/grants', [
            'staff' => $target->public_id, 'action' => 'service.manage',
            'reason' => 'Scoped service security administration',
        ])->assertRedirect();
        $sensitiveGrant = DB::table('security_action_grants')
            ->where('subject_user_id', $target->id)->where('action', 'service.manage')->value('id');

        $this->actingAs($admin)->post('/internal/grants', [
            'staff' => $target->public_id, 'action' => 'grant.manage',
            'reason' => 'Forbidden privilege delegation',
        ])->assertForbidden();
        $this->delete('/internal/grants/'.$sensitiveGrant)->assertForbidden();
        $this->post('/internal/staff/'.$target->public_id.'/attest-factors')->assertForbidden();
        $this->post('/internal/grants', [
            'staff' => $target->public_id, 'action' => 'staff.view',
            'reason' => 'Ordinary staff administration view',
        ])->assertRedirect();
    }

    private function staff(string $status, string $kind = 'employee'): User
    {
        $user = User::factory()->create();
        DB::connection('pgsql_migrations')->table('principal_lifecycles')->where('user_id', $user->id)->update(['principal_class' => 'staff']);
        DB::connection('pgsql_migrations')->table('internal_personnel')->insert([
            'user_id' => $user->id,
            'kind' => $kind,
            'status' => $status,
            'attested_by' => $user->id,
            'attested_at' => now(),
            'factor_independence_attested_at' => $kind === 'founder' ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (range(1, $kind === 'founder' ? 2 : 1) as $number) {
            DB::connection('pgsql_migrations')->table('passkeys')->insert([
                'user_id' => $user->id,
                'name' => 'Test authenticator '.$number,
                'credential_id' => 'fixture-'.$user->id.'-'.$number,
                'credential' => '{}',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $user;
    }
}
