<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Passkeys\Actions\StorePasskey;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Uid\Uuid;
use Tests\TestCase;
use Webauthn\AuthenticatorData;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

class PasskeyAssertionTest extends TestCase
{
    public function test_staff_passkey_issues_only_an_internal_session_and_rotates_the_session_id(): void
    {
        [$user, $key, $credentialId] = $this->createCredential(staff: true);
        $options = $this->getJson('/internal/auth/passkey/options')->assertOk()->json('options');
        $oldSessionHash = hash('sha256', $this->app['session']->driver()->getId());

        $this->postJson('/internal/auth/passkey', ['credential' => $this->assertion(
            $options, $key, $credentialId, $user,
            AuthenticatorData::FLAG_UP | AuthenticatorData::FLAG_UV,
        )])->assertOk()->assertJsonPath('redirect', '/internal/security/devices');
        $this->assertDatabaseHas('security_sessions', [
            'user_id' => $user->id, 'auth_method' => 'internal_passkey',
        ], 'pgsql_migrations');
        $this->assertDatabaseMissing('security_sessions', ['session_key_hash' => $oldSessionHash], 'pgsql_migrations');
        $this->assertDatabaseHas('security_audit_events', [
            'subject_user_id' => $user->id, 'event' => 'passkey_login_verified',
        ], 'pgsql_migrations');
        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->get('/internal/security/devices')->assertOk();
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->get('/internal/security/devices')->assertOk();
        $this->post('/internal/logout')->assertRedirect(route('internal.login'));
        $this->get('/internal/security/devices')->assertRedirect(route('internal.login'));
    }

    public function test_advertiser_passkey_cannot_issue_an_internal_session(): void
    {
        [$user, $key, $credentialId] = $this->createCredential();
        $options = $this->getJson('/internal/auth/passkey/options')->assertOk()->json('options');

        $this->postJson('/internal/auth/passkey', ['credential' => $this->assertion(
            $options, $key, $credentialId, $user,
            AuthenticatorData::FLAG_UP | AuthenticatorData::FLAG_UV,
        )])->assertUnprocessable();
        $this->assertSame(0, DB::table('security_sessions')->count());
        $this->get('/internal/security/devices')->assertRedirect(route('internal.login'));
    }

    public function test_staff_passkey_cannot_issue_an_advertiser_session(): void
    {
        [$user, $key, $credentialId] = $this->createCredential(staff: true);
        $options = $this->getJson('/auth/passkey/options')->assertOk()->json('options');

        $this->postJson('/auth/passkey', ['credential' => $this->assertion(
            $options, $key, $credentialId, $user,
            AuthenticatorData::FLAG_UP | AuthenticatorData::FLAG_UV,
        )])->assertUnprocessable();
        $this->assertSame(0, DB::table('security_sessions')->count());
    }

    public function test_signed_uv_assertion_issues_a_passkey_session_and_cannot_be_replayed(): void
    {
        [$user, $key, $credentialId] = $this->createCredential();
        $options = $this->getJson('/auth/passkey/options')->assertOk()->json('options');
        $this->assertSame('required', $options['userVerification']);
        $this->assertSame(config('passkeys.relying_party_id'), $options['rpId']);
        $this->assertSame([], $options['allowCredentials']);

        $assertion = $this->assertion($options, $key, $credentialId, $user,
            AuthenticatorData::FLAG_UP | AuthenticatorData::FLAG_UV);

        $this->postJson('/auth/passkey', ['credential' => $assertion])
            ->assertOk()->assertJsonPath('redirect', '/dashboard');
        $this->assertDatabaseHas('security_sessions', [
            'user_id' => $user->id,
            'auth_method' => 'passkey',
        ], 'pgsql_migrations');
        $this->assertNotNull(DB::table('passkeys')->where('user_id', $user->id)->value('last_used_at'));
        $this->assertDatabaseHas('security_audit_events', [
            'subject_user_id' => $user->id, 'event' => 'passkey_login_verified',
            'reason' => 'key:'.DB::table('passkeys')->where('user_id', $user->id)->value('id')
                .';session:'.DB::table('security_sessions')->where('user_id', $user->id)->value('id').';uv:1',
        ]);

        $this->post('/logout')->assertRedirect('/');
        $this->postJson('/auth/passkey', ['credential' => $assertion])->assertUnprocessable();
        $this->assertSame(1, DB::table('security_sessions')->where('user_id', $user->id)->count());
    }

    public function test_signed_assertion_without_uv_is_denied(): void
    {
        [$user, $key, $credentialId] = $this->createCredential();
        $options = $this->getJson('/auth/passkey/options')->assertOk()->json('options');

        $this->postJson('/auth/passkey', ['credential' => $this->assertion(
            $options, $key, $credentialId, $user, AuthenticatorData::FLAG_UP,
        )])->assertUnprocessable();
        $this->assertSame(0, DB::table('security_sessions')->count());
        $this->assertSame(0, DB::table('security_audit_events')->where('event', 'passkey_login_verified')->count());
        $this->assertNull(DB::table('passkeys')->where('user_id', $user->id)->value('last_used_at'));
    }

    public function test_signed_assertion_from_wrong_origin_is_denied(): void
    {
        [$user, $key, $credentialId] = $this->createCredential();
        $options = $this->getJson('/auth/passkey/options')->assertOk()->json('options');

        $this->postJson('/auth/passkey', ['credential' => $this->assertion(
            $options, $key, $credentialId, $user,
            AuthenticatorData::FLAG_UP | AuthenticatorData::FLAG_UV,
            'http://127.0.0.1',
        )])->assertUnprocessable();
        $this->assertSame(0, DB::table('security_sessions')->count());
    }

    public function test_assertion_with_invalid_signature_is_denied(): void
    {
        [$user, $key, $credentialId] = $this->createCredential();
        $options = $this->getJson('/auth/passkey/options')->assertOk()->json('options');
        $assertion = $this->assertion($options, $key, $credentialId, $user,
            AuthenticatorData::FLAG_UP | AuthenticatorData::FLAG_UV);
        $assertion['response']['signature'] = Base64UrlSafe::encodeUnpadded(random_bytes(64));

        $this->postJson('/auth/passkey', ['credential' => $assertion])->assertUnprocessable();
        $this->assertSame(0, DB::table('security_sessions')->count());
    }

    public function test_revoked_credential_and_expired_challenge_are_denied(): void
    {
        [$user, $key, $credentialId] = $this->createCredential();
        $options = $this->getJson('/auth/passkey/options')->assertOk()->json('options');
        $assertion = $this->assertion($options, $key, $credentialId, $user,
            AuthenticatorData::FLAG_UP | AuthenticatorData::FLAG_UV);

        $this->withSession(['builderad.passkey_verification_issued_at' => time() - 61])
            ->postJson('/auth/passkey', ['credential' => $assertion])->assertUnprocessable();
        $this->assertSame(0, DB::table('security_sessions')->count());

        $this->getJson('/auth/passkey/options')->assertOk();
        DB::connection('pgsql_migrations')->table('passkeys')->where('user_id', $user->id)->delete();
        $this->postJson('/auth/passkey', ['credential' => $assertion])->assertUnprocessable();
        $this->assertSame(0, DB::table('security_sessions')->count());
    }

    /** @return array{User, \OpenSSLAsymmetricKey, string} */
    private function createCredential(bool $staff = false): array
    {
        $user = User::factory()->create();
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        $x = $details['ec']['x'];
        $y = $details['ec']['y'];
        $credentialId = random_bytes(32);
        $cosePublicKey = "\xA5\x01\x02\x03\x26\x20\x01\x21\x58\x20".$x."\x22\x58\x20".$y;
        $record = CredentialRecord::create(
            publicKeyCredentialId: $credentialId,
            type: 'public-key',
            transports: ['internal'],
            attestationType: 'none',
            trustPath: EmptyTrustPath::create(),
            aaguid: Uuid::v4(),
            credentialPublicKey: $cosePublicKey,
            userHandle: $user->getPasskeyUserHandle(),
            counter: 0,
            uvInitialized: true,
        );
        DB::connection('pgsql_authority')->transaction(fn () => app(StorePasskey::class)->createPasskey(
            User::on('pgsql_authority')->findOrFail($user->id), 'Test authenticator', $record,
        ));
        if ($staff) {
            DB::connection('pgsql_migrations')->table('principal_lifecycles')->where('user_id', $user->id)
                ->update(['principal_class' => 'staff']);
            DB::connection('pgsql_migrations')->table('internal_personnel')->insert([
                'user_id' => $user->id, 'kind' => 'employee', 'status' => 'active',
                'attested_by' => $user->id, 'attested_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [$user, $key, $credentialId];
    }

    /** @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function assertion(array $options, \OpenSSLAsymmetricKey $key, string $credentialId,
        User $user, int $flags, ?string $origin = null): array
    {
        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $options['challenge'],
            'origin' => $origin ?? config('passkeys.allowed_origins')[0],
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR);
        $authenticatorData = hash('sha256', $options['rpId'], true).chr($flags).pack('N', 1);
        $this->assertTrue(openssl_sign(
            $authenticatorData.hash('sha256', $clientData, true), $signature, $key, OPENSSL_ALGO_SHA256,
        ));
        $id = Base64UrlSafe::encodeUnpadded($credentialId);

        return [
            'id' => $id,
            'rawId' => $id,
            'type' => 'public-key',
            'response' => [
                'authenticatorData' => Base64UrlSafe::encodeUnpadded($authenticatorData),
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientData),
                'signature' => Base64UrlSafe::encodeUnpadded($signature),
                'userHandle' => Base64UrlSafe::encodeUnpadded($user->getPasskeyUserHandle()),
            ],
        ];
    }
}
