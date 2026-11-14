<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\RegistrationCode;
use App\Security\RegistrationEmailProof;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Support\DisposableDatabaseReset;
use Tests\Support\RegistrationEmailProofFixture;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RegistrationEmailProofFixture;

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->useIdentityDocument('/register');
        $this->getJson('/register')->assertOk()->assertJsonPath('data.kind', 'register')
            ->assertJsonPath('data.passwordPolicy.min', 15)
            ->assertJsonPath('data.passwordPolicy.max', 1024)
            ->assertJsonPath('data.passwordPolicy.browserRules', 'minlength: 15; maxlength: 1024;');
    }

    public function test_new_users_can_register(): void
    {
        $this->useIdentityDocument('/register');
        $proof = $this->issueRegistrationCode('test@example.com');
        $this->postJson('/register/email/verify', $proof)->assertOk()->assertJsonPath('state', 'verified');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('security_sessions', 0);
        $this->getJson('/register/email')->assertOk()->assertJsonPath('attempt_id', $proof['attempt_id'])
            ->assertJsonPath('state', 'verified');
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'long unique password 2026',
            'password_confirmation' => 'long unique password 2026',
            'attempt_id' => $proof['attempt_id'],
        ]);

        $user = User::where('email', 'test@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame('current', DB::table('principal_lifecycles')->where('user_id', $user->id)->value('status'));
        $this->assertSame($proof['attempt_id'], app(RegistrationEmailProof::class)->businessAttempt($user));
        $this->assertNull(DB::connection('pgsql_migrations')->scalar("SELECT to_regclass('public.workspaces')"));
        $response->assertOk()->assertJsonPath('redirect', '/businesses/new')->assertJsonPath('transition.epoch', 1);
    }

    public function test_mismatched_password_confirmation_is_rejected_in_arabic(): void
    {
        $this->useIdentityDocument('/register');
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'mismatch@example.test',
            'password' => 'long unique password 2026',
            'password_confirmation' => 'another long password 2026',
        ])->assertSessionHasErrors([
            'password' => 'كلمة المرور وتأكيدها غير متطابقين.',
        ]);
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'mismatch@example.test']);
    }

    public function test_completion_requires_current_verified_attempt_for_the_same_email(): void
    {
        $this->useIdentityDocument('/register');
        $password = 'long unique password 2026';
        $payload = ['name' => 'Test User', 'email' => 'new@example.test',
            'password' => $password, 'password_confirmation' => $password];
        $this->postJson('/register', $payload)->assertUnprocessable()->assertJsonValidationErrors('attempt_id');
        $attempt = $this->verifyRegistrationEmail('original@example.test');
        $this->postJson('/register', [...$payload, 'attempt_id' => $attempt])
            ->assertUnprocessable()->assertJsonValidationErrors('attempt_id');
        $this->postJson('/register/email/cancel', ['attempt_id' => $attempt])->assertOk()->assertExactJson(['state' => 'none']);
        $this->postJson('/register', [...$payload, 'email' => 'original@example.test', 'attempt_id' => $attempt])
            ->assertUnprocessable()->assertJsonValidationErrors('attempt_id');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_invalid_attempts_are_limited_and_expired_proof_cannot_create_an_account(): void
    {
        $this->useIdentityDocument('/register');
        $proof = $this->issueRegistrationCode('limited@example.test');
        $wrongCode = $proof['code'] === '000000' ? '000001' : '000000';
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/register/email/verify', [...$proof, 'code' => $wrongCode])
                ->assertUnprocessable()->assertJsonValidationErrors('code');
        }
        $this->postJson('/register/email/verify', $proof)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->travel(61)->seconds();
        $expiredCode = $this->issueRegistrationCode('limited@example.test');
        $this->assertSame($proof['attempt_id'], $expiredCode['attempt_id']);
        $this->travel(11)->minutes();
        $this->postJson('/register/email/verify', $expiredCode)->assertUnprocessable()->assertJsonValidationErrors('code');

        $attempt = $this->verifyRegistrationEmail('expire-proof@example.test');
        $this->travel(21)->minutes();
        $this->getJson('/register/email')->assertOk()->assertExactJson(['state' => 'none']);
        $this->postJson('/register', [
            'name' => 'Expired', 'email' => 'expire-proof@example.test', 'attempt_id' => $attempt,
            'password' => 'long unique password 2026', 'password_confirmation' => 'long unique password 2026',
        ])->assertUnprocessable()->assertJsonValidationErrors('attempt_id');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_resend_during_cooldown_does_not_dispatch_another_code_and_stale_cancel_is_harmless(): void
    {
        $this->useIdentityDocument('/register');
        $proof = $this->issueRegistrationCode('first@example.test');
        $this->postJson('/register/email', ['email' => 'first@example.test'])
            ->assertOk()->assertJsonPath('attempt_id', $proof['attempt_id']);
        Notification::assertSentOnDemandOnce(RegistrationCode::class);
        $replacement = $this->issueRegistrationCode('second@example.test');
        $this->assertNotSame($proof['attempt_id'], $replacement['attempt_id']);
        $this->postJson('/register/email/cancel', ['attempt_id' => $proof['attempt_id']])
            ->assertOk()->assertJsonPath('attempt_id', $replacement['attempt_id']);
        $this->postJson('/register/email/verify', $proof)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/register/email/verify', $replacement)->assertOk()->assertJsonPath('state', 'verified');
        $this->postJson('/register/email/verify', $replacement)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->getJson('/register/email')->assertOk()->assertJsonPath('state', 'verified');
    }

    public function test_mailbox_proof_cannot_authenticate_or_set_password_for_existing_user(): void
    {
        $user = User::factory()->create(['email' => 'existing@example.test']);
        // Preserve a historical mixed-case mailbox that predates application normalization.
        DB::connection('pgsql_migrations')->table('users')->where('id', $user->id)->update(['email' => 'Existing@Example.test']);
        $this->useIdentityDocument('/register');
        $attempt = $this->verifyRegistrationEmail('existing@example.test');
        $this->postJson('/register', [
            'name' => 'New name', 'email' => $user->email, 'attempt_id' => $attempt,
            'password' => 'long unique password 2026', 'password_confirmation' => 'long unique password 2026',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('security_sessions', 0);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->assertSame('Existing@Example.test', $user->fresh()->email);
        $this->assertNull(app(RegistrationEmailProof::class)->businessAttempt($user));
    }

    public function test_current_installation_preserves_registration_identity_and_factors_and_refuses_rollback(): void
    {
        $this->usePersistentTestSessions();
        $reset = app(DisposableDatabaseReset::class);
        $migrationName = '0001_01_01_000000_business_native_baseline.php';
        try {
            $current = User::factory()->create(['email' => 'upgrade-current@example.test']);
            User::factory()->unverified()->create(['email' => 'upgrade-pending@example.test']);
            $this->actingAs($current);
            $owner = DB::connection('pgsql_migrations');
            // This retained synthetic factor proves storage preservation, while the existing Passkey suite proves ceremonies.
            $owner->table('passkeys')->insert([
                'user_id' => $current->id, 'name' => 'Retained upgrade factor',
                'credential_id' => 'registration-upgrade-factor', 'credential' => '{}',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $owner->table('password_reset_tokens')->insert([
                'email' => $current->email, 'token' => Hash::make('retained-recovery-token'), 'created_at' => now(),
            ]);
            $tables = ['users', 'principal_lifecycles', 'security_sessions', 'browser_identity_transitions',
                'passkeys', 'password_reset_tokens', 'security_action_grants', 'security_audit_events'];
            $snapshot = static function () use ($owner, $tables): array {
                $rows = [];
                foreach ($tables as $table) {
                    $rows[$table] = $owner->scalar("SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]')::text FROM {$table} r");
                }

                return $rows;
            };
            $before = $snapshot();
            $reset->assertInstalledCatalog();
            $upgrade = ['--database' => 'pgsql_migrations', '--force' => true];
            $this->assertSame(0, Artisan::call('migrate', $upgrade));
            $this->assertSame($before, $snapshot());
            $reset->assertInstalledCatalog();
            $this->assertSame(0, $owner->table('registration_email_proofs')->count());

            foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
                $this->assertFalse((bool) $owner->scalar('SELECT has_table_privilege(?,?,?)',
                    ['builderad_app', 'registration_email_proofs', $privilege]), $privilege);
                $this->assertTrue((bool) $owner->scalar('SELECT has_table_privilege(?,?,?)',
                    ['builderad_authority', 'registration_email_proofs', $privilege]), $privilege);
            }
            try {
                DB::connection('pgsql')->table('registration_email_proofs')->count();
                $this->fail('The runtime role must not read registration proofs.');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->errorInfo[0]);
            }
            $this->assertSame(0, Artisan::call('migrate', $upgrade));
            $this->assertSame($before, $snapshot());

            $default = DB::getDefaultConnection();
            try {
                DB::setDefaultConnection('pgsql_migrations');
                $migration = require database_path('migrations/'.$migrationName);
                try {
                    $migration->down();
                    $this->fail('Registration recovery must require a forward migration.');
                } catch (QueryException $error) {
                    $this->assertSame('P0001', $error->errorInfo[0]);
                    $this->assertStringContainsString('forward recovery', $error->getMessage());
                }
            } finally {
                DB::setDefaultConnection($default);
            }
            $this->assertSame($before, $snapshot());
            $this->assertNotNull($owner->scalar("SELECT to_regclass('public.registration_email_proofs')"));
            $reset->assertInstalledCatalog();
            $this->getJson('/profile')->assertOk();
        } finally {
            DB::disconnect('pgsql');
            $reset->install();
        }
    }

    public function test_registration_proof_cannot_be_read_or_verified_from_another_guest_document(): void
    {
        $this->useIdentityDocument('/register');
        $proof = $this->issueRegistrationCode('isolated@example.test');
        $cookie = config('session.advertiser_cookie');
        $this->withUnencryptedCookie($cookie, '')->withUnencryptedCookie($cookie.'-lineage', '');
        $this->useIdentityDocument('/register');
        $this->getJson('/register/email')->assertOk()->assertExactJson(['state' => 'none']);
        $this->postJson('/register/email/verify', $proof)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertGuest();
    }
}
