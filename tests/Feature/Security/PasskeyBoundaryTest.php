<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Security\AuthorityVerifyPasskey;
use Illuminate\Support\Facades\DB;
use Laravel\Passkeys\Actions\StorePasskey;
use Symfony\Component\Uid\Uuid;
use Tests\TestCase;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorData;
use Webauthn\CollectedClientData;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredential;
use Webauthn\TrustPath\EmptyTrustPath;

class PasskeyBoundaryTest extends TestCase
{
    public function test_native_passkey_storage_uses_the_independent_authority_connection(): void
    {
        $user = User::factory()->create();
        $source = CredentialRecord::create(
            publicKeyCredentialId: random_bytes(24),
            type: 'public-key',
            transports: ['internal'],
            attestationType: 'none',
            trustPath: EmptyTrustPath::create(),
            aaguid: Uuid::v4(),
            credentialPublicKey: random_bytes(32),
            userHandle: (string) $user->id,
            counter: 0,
        );

        $passkey = DB::connection('pgsql_authority')->transaction(
            fn () => app(StorePasskey::class)->createPasskey(
                User::on('pgsql_authority')->findOrFail($user->id), 'Native authenticator', $source,
            ),
        );

        $this->assertDatabaseHas('passkeys', [
            'id' => $passkey->id,
            'user_id' => $user->id,
            'name' => 'Native authenticator',
        ], 'pgsql_migrations');

        $client = new CollectedClientData('{}', [
            'type' => 'webauthn.get', 'challenge' => 'YWJj',
            'origin' => 'http://127.0.0.1',
        ]);
        $credential = PublicKeyCredential::create('public-key', $source->publicKeyCredentialId,
            AuthenticatorAssertionResponse::create(
                $client, AuthenticatorData::create('', '', chr(AuthenticatorData::FLAG_UP), 0), '',
            ));
        $stored = DB::connection('pgsql_authority')->transaction(
            fn () => app(AuthorityVerifyPasskey::class)->getPasskey($credential, lock: true),
        );
        $this->assertSame('pgsql_authority', $stored->getConnectionName());
        $stored->forceFill(['last_used_at' => now()])->save();
        $this->assertNotNull(DB::table('passkeys')->where('id', $passkey->id)->value('last_used_at'));
    }

    public function test_passkey_enrollment_requires_current_authenticated_principal(): void
    {
        $this->getJson('/security/passkeys/options')->assertUnauthorized();

        $pending = User::factory()->unverified()->create();
        $this->actingAs($pending)->getJson('/security/passkeys/options')->assertForbidden();

        $current = User::factory()->create();
        $this->actingAs($current)->withCredentials()
            ->getJson('/security/passkeys/options')->assertOk()->assertJsonStructure(['options']);
    }

    public function test_invalid_passkey_assertion_does_not_create_a_session(): void
    {
        $this->getJson('/auth/passkey/options')->assertOk()->assertJsonStructure(['options']);
        $this->postJson('/auth/passkey', ['credential' => []])->assertUnprocessable();
        $this->assertSame(0, DB::table('security_sessions')->count());
    }

    public function test_current_advertiser_can_remove_an_owned_passkey_with_atomic_audit(): void
    {
        $user = User::factory()->create();
        $passkeyId = DB::connection('pgsql_migrations')->table('passkeys')->insertGetId([
            'user_id' => $user->id,
            'name' => 'Test authenticator',
            'credential_id' => 'delete-test-'.$user->id,
            'credential' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)->delete('/security/passkeys/'.$passkeyId)->assertRedirect();
        $this->assertDatabaseMissing('passkeys', ['id' => $passkeyId], 'pgsql_migrations');
        $this->assertDatabaseHas('security_audit_events', [
            'subject_user_id' => $user->id,
            'event' => 'passkey_removed',
        ], 'pgsql_migrations');
    }

    public function test_revoked_staff_eligibility_blocks_security_mutations_even_with_a_live_session(): void
    {
        $staff = User::factory()->create();
        $owner = DB::connection('pgsql_migrations');
        $owner->table('principal_lifecycles')->where('user_id', $staff->id)
            ->update(['principal_class' => 'staff']);
        $owner->table('internal_personnel')->insert([
            'user_id' => $staff->id, 'kind' => 'employee', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $owner->table('passkeys')->insert([
            'user_id' => $staff->id, 'name' => 'Staff authenticator',
            'credential_id' => 'staff-security-'.$staff->id, 'credential' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAsInternal($staff);
        $owner->table('internal_personnel')->where('user_id', $staff->id)
            ->update(['status' => 'revoked', 'revoked_at' => now()]);

        $this->post('/internal/security/sessions/revoke-others')->assertForbidden();
        $this->assertDatabaseMissing('security_audit_events', [
            'actor_user_id' => $staff->id, 'event' => 'sessions_revoked',
        ], 'pgsql_migrations');
    }
}
