<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Support\ApplicationContractAssertions;
use Tests\TestCase;

class AccountSecuritySpaTest extends TestCase
{
    use ApplicationContractAssertions;

    public function test_profile_shell_contains_no_account_projection_and_json_preserves_the_existing_owner(): void
    {
        $user = User::factory()->create(['email' => 'private-account@example.test']);
        $this->actingAs($user)->get('/profile')->assertOk()->assertViewIs('spa')->assertDontSee($user->email)->assertDontSee('data-page=', false);
        $resource = $this->getJson('/profile')->assertOk()->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.canChangeEmail', true)->assertJsonMissingPath('data.user.password')->json();
        $this->assertApplicationContract('AccountProfileResource', $resource);
        $this->patchJson('/profile', ['name' => 'Changed name', 'email' => $user->email])->assertOk()->assertExactJson(['saved' => true]);
        $this->assertSame('Changed name', $user->fresh()->name);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $user->refresh();
        $this->getJson('/profile')->assertOk()->assertJsonPath('data.user.name', 'Changed name');
    }

    public function test_native_email_validation_survives_shell_then_json_without_exposing_password_drafts(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user)->from('/profile')->patch('/profile', ['name' => 'Retained native draft', 'email' => $other->email])->assertRedirect('/profile')->assertSessionHasErrors('email');
        $this->get('/profile')->assertOk()->assertViewIs('spa');
        $this->getJson('/profile')->assertOk()->assertJsonPath('data.old.name', 'Retained native draft')
            ->assertJsonPath('data.old.email', $other->email)->assertJsonCount(1, 'data.errors.email')->assertJsonMissingPath('data.old.password');
        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_password_json_uses_the_existing_policy_and_retains_only_the_current_session(): void
    {
        Http::fake(['api.pwnedpasswords.com/*' => Http::response(str_repeat('0', 35).':0')]);
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/security/devices')->assertOk();
        $database = DB::connection('pgsql_migrations');
        $current = $database->table('security_sessions')->where('user_id', $user->id)->sole();
        $other = (array) $current;
        unset($other['id']);
        $other['session_key_hash'] = hash('sha256', 'account-other-session');
        $database->table('security_sessions')->insert($other);
        $this->putJson('/password', ['current_password' => 'wrong', 'password' => 'NewDisposableAccount42!', 'password_confirmation' => 'NewDisposableAccount42!'])->assertUnprocessable();
        $this->putJson('/password', ['current_password' => 'password', 'password' => 'NewDisposableAccount42!', 'password_confirmation' => 'NewDisposableAccount42!'])->assertOk()->assertExactJson(['saved' => true]);
        $this->assertTrue(Hash::check('NewDisposableAccount42!', $user->fresh()->password));
        $this->assertNull($database->table('security_sessions')->where('id', $current->id)->value('revoked_at'));
        $this->assertSame(1, $database->table('security_sessions')->where('user_id', $user->id)->whereNotNull('revoked_at')->count());
        $this->getJson('/security/devices')->assertOk()->assertJsonCount(1, 'data.sessions');
    }

    public function test_device_resource_contains_only_current_boundary_metadata_and_cannot_revoke_current_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/security/devices')->assertOk()->assertViewIs('spa');
        $resource = $this->getJson('/security/devices')->assertOk()->assertJsonPath('data.internalContext', false)
            ->assertJsonCount(1, 'data.sessions')->assertJsonMissingPath('data.sessions.0.session_key_hash')->json();
        $this->assertApplicationContract('AccountDevicesResource', $resource);
        $this->deleteJson('/security/sessions/'.$resource['data']['sessions'][0]['id'])->assertUnprocessable();
        $this->deleteJson('/security/sessions/99999999')->assertNotFound();
        $this->postJson('/security/sessions/revoke-others')->assertOk()->assertExactJson(['saved' => true]);
    }
}
