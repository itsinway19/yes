<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApplicationContractAssertions;
use Tests\TestCase;

class InternalIdentitySpaTest extends TestCase
{
    use ApplicationContractAssertions;

    private function staff(bool $pending = false): User
    {
        $staff = $pending ? User::factory()->unverified()->create() : User::factory()->create();
        $database = DB::connection('pgsql_migrations');
        $database->table('principal_lifecycles')->where('user_id', $staff->id)->update(['principal_class' => 'staff']);
        $database->table('internal_personnel')->insert(['user_id' => $staff->id, 'kind' => 'employee',
            'status' => $pending ? 'pending' : 'active', 'attested_at' => $pending ? null : now(),
            'attested_by' => $pending ? null : $staff->id, 'created_at' => now(), 'updated_at' => now()]);
        if (! $pending) {
            $database->table('passkeys')->insert(['user_id' => $staff->id, 'name' => 'Private internal key',
                'credential_id' => 'internal-spa-'.$staff->id, 'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $staff;
    }

    public function test_internal_guest_has_only_safe_shell_and_actual_public_login_resource(): void
    {
        $identity = $this->useIdentityDocument('/internal/login');
        $this->assertSame('internal', $identity['binding']['boundary']);
        $this->assertSame(0, $identity['binding']['principal']);
        $this->get('/internal/login')->assertViewIs('spa')->assertDontSee('data-page=', false)
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertViewHas('bootstrap', fn ($bootstrap) => $bootstrap['documentState'] === 'guest');
        $resource = $this->getJson('/internal/login')->assertOk()->assertJsonPath('data.kind', 'login')
            ->assertJsonPath('data.canResetPassword', false)->assertJsonPath('visibility.protected', false)->json();
        $this->assertApplicationContract('AuthEntryResource', $resource);
        $this->getJson('/internal/security/devices')->assertUnauthorized();
        $this->postJson('/internal/login', ['email' => 'no-password-login@example.test', 'password' => 'password'])->assertStatus(405);
    }

    public function test_internal_devices_and_switch_use_the_same_guard_without_bootstrapping_private_projection(): void
    {
        $staff = $this->staff();
        $this->actingAsInternal($staff)->get('/internal/security/devices')->assertViewIs('spa')
            ->assertDontSee('Private internal key')->assertDontSee($staff->email)->assertDontSee('data-page=', false)
            ->assertViewHas('bootstrap', fn ($bootstrap) => $bootstrap['documentState'] === 'authenticated');
        $resource = $this->getJson('/internal/security/devices')->assertOk()->assertJsonPath('data.internalContext', true)
            ->assertJsonCount(1, 'data.sessions')->assertJsonPath('data.passkeys.0.name', 'Private internal key')
            ->assertJsonMissingPath('data.passkeys.0.credential')->assertJsonMissingPath('data.sessions.0.session_key_hash')->json();
        $this->assertApplicationContract('InternalDevicesResource', $resource);
        $this->getJson('/internal/security/devices', ['X-Builderad-Witness' => '1'])
            ->assertExactJson(['visibility' => $resource['visibility']]);
        $this->get('/internal/identity/switch')->assertViewIs('spa')->assertDontSee('data-page=', false);
        $this->getJson('/internal/identity/switch')->assertOk()->assertJsonPath('data.kind', 'login')->assertJsonPath('visibility.protected', true);
        $this->deleteJson('/internal/security/sessions/'.$resource['data']['sessions'][0]['id'])->assertUnprocessable();
        $this->deleteJson('/internal/security/passkeys/'.$resource['data']['passkeys'][0]['id'])->assertUnprocessable();
        $this->postJson('/internal/security/sessions/revoke-others')->assertOk()->assertExactJson(['saved' => true]);
        $this->assertAuthenticatedAs($staff, 'internal');
    }

    public function test_pending_web_enrollment_projection_is_not_internal_authority(): void
    {
        $staff = $this->staff(true);
        $this->actingAs($staff)->get('/staff/onboarding')->assertViewIs('spa')->assertDontSee($staff->email)
            ->assertViewHas('bootstrap', fn ($bootstrap) => $bootstrap['staffEnrollment'] && $bootstrap['identity']['binding']['boundary'] === 'advertiser');
        $resource = $this->getJson('/staff/onboarding')->assertOk()->assertJsonPath('data.verified', false)
            ->assertJsonPath('data.attested', false)->assertJsonPath('data.kind', 'employee')->assertJsonCount(0, 'data.passkeys')->json();
        $this->assertSame(['verified', 'kind', 'passkeys', 'attested'], array_keys($resource['data']));
        $this->assertApplicationContract('StaffEnrollmentResource', $resource);
        $before = DB::table('security_sessions')->where('user_id', $staff->id)->sole();
        $this->getJson('/staff/onboarding', ['X-Builderad-Passive' => '1'])->assertOk();
        $after = DB::table('security_sessions')->where('id', $before->id)->sole();
        $this->assertSame($before->idle_expires_at, $after->idle_expires_at);
        $this->assertSame($before->last_seen_at, $after->last_seen_at);
        $this->getJson('/staff/onboarding/passkeys/options')->assertForbidden();
        $this->getJson('/internal/security/devices')->assertStatus(409)->assertJsonPath('identityConflict', true);
        $this->assertGuest('internal');
        $this->assertSame('pending', DB::table('principal_lifecycles')->where('user_id', $staff->id)->value('status'));
        $this->assertSame('staff_enrollment', DB::table('security_sessions')->where('user_id', $staff->id)->value('auth_method'));
    }

    public function test_internal_json_commands_keep_original_writer_audit_and_native_redirects_without_revoking_web_sessions(): void
    {
        $staff = $this->staff();
        $this->actingAsInternal($staff)->get('/internal/security/devices')->assertOk();
        $database = DB::connection('pgsql_migrations');
        $current = (array) $database->table('security_sessions')->where('user_id', $staff->id)->sole();
        unset($current['id']);
        $other = [...$current, 'session_key_hash' => hash('sha256', 'internal-other-proof')];
        $otherId = $database->table('security_sessions')->insertGetId($other);
        $webId = $database->table('security_sessions')->insertGetId([...$current,
            'session_key_hash' => hash('sha256', 'enrollment-other-proof'), 'auth_method' => 'staff_enrollment']);
        $this->getJson('/internal/security/devices')->assertJsonCount(2, 'data.sessions');
        $this->deleteJson('/internal/security/sessions/'.$webId)->assertNotFound();
        $this->postJson('/internal/security/sessions/revoke-others')->assertOk()->assertExactJson(['saved' => true]);
        $this->assertNotNull($database->table('security_sessions')->where('id', $otherId)->value('revoked_at'));
        $this->assertNull($database->table('security_sessions')->where('id', $webId)->value('revoked_at'));
        $this->assertDatabaseHas('security_audit_events', ['subject_user_id' => $staff->id, 'event' => 'sessions_revoked']);
        $key = $database->table('passkeys')->insertGetId(['user_id' => $staff->id, 'name' => 'Additional key',
            'credential_id' => 'additional-internal-proof', 'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->deleteJson('/internal/security/passkeys/'.$key)->assertOk()->assertExactJson(['saved' => true]);
        $this->assertDatabaseMissing('passkeys', ['id' => $key]);
        $this->from('/internal/security/devices')->post('/internal/security/sessions/revoke-others')->assertRedirect('/internal/security/devices');
        $this->getJson('/internal/security/devices')->assertJsonCount(1, 'data.sessions')->assertJsonCount(1, 'data.passkeys');
    }
}
