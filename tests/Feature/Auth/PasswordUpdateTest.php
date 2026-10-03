<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/security/devices')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'new unique password 2026',
                'password_confirmation' => 'new unique password 2026',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/security/devices');

        $this->assertTrue(Hash::check('new unique password 2026', $user->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/security/devices')
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => 'new unique password 2026',
                'password_confirmation' => 'new unique password 2026',
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect('/security/devices');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_password_change_preserves_passkeys_and_current_session_but_revokes_other_sessions(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $database = DB::connection('pgsql_migrations');
        $currentId = $database->table('security_sessions')->where('user_id', $user->id)->value('id');
        $other = (array) $database->table('security_sessions')->where('id', $currentId)->first();
        unset($other['id']);
        $other['session_key_hash'] = hash('sha256', 'other-browser');
        $otherId = $database->table('security_sessions')->insertGetId($other);
        $keyId = $database->table('passkeys')->insertGetId([
            'user_id' => $user->id, 'name' => 'Existing key',
            'credential_id' => bin2hex(random_bytes(16)),
            'credential' => json_encode(['aaguid' => 'd3452668-01fd-4c12-926c-83a4204853aa']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $keyBefore = (array) $database->table('passkeys')->where('id', $keyId)->first();

        $this->from('/security/devices')->put('/password', [
            'current_password' => 'password',
            'password' => 'new unique password 2026',
            'password_confirmation' => 'new unique password 2026',
        ])->assertSessionHasNoErrors()->assertRedirect('/security/devices');

        $this->assertNull($database->table('security_sessions')->where('id', $currentId)->value('revoked_at'));
        $this->assertNotNull($database->table('security_sessions')->where('id', $otherId)->value('revoked_at'));
        $this->assertSame($keyBefore, (array) $database->table('passkeys')->where('id', $keyId)->first());
        $this->get('/security/devices')->assertOk();
    }

    public function test_confirmation_and_existing_password_policy_are_enforced_without_changing_password(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        foreach ([['short', 'short'], ['new unique password 2026', 'different confirmation']] as [$password, $confirmation]) {
            $this->from('/security/devices')->put('/password', [
                'current_password' => 'password', 'password' => $password,
                'password_confirmation' => $confirmation,
            ])->assertSessionHasErrors('password')->assertRedirect('/security/devices');
            $this->assertTrue(Hash::check('password', $user->refresh()->password));
        }
    }

    public function test_password_change_requires_an_authenticated_advertiser(): void
    {
        $this->put('/password', [
            'current_password' => 'password', 'password' => 'new unique password 2026',
            'password_confirmation' => 'new unique password 2026',
        ])->assertRedirect('/login');
    }
}
