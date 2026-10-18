<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\SecurityVerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ApplicationContractAssertions;
use Tests\TestCase;

class AuthEntrySpaTest extends TestCase
{
    use ApplicationContractAssertions;

    public function test_guest_entry_has_no_private_projection_and_actual_resources_match_the_contract(): void
    {
        $this->get('/')->assertOk()->assertViewIs('welcome')->assertDontSee('data-page=', false);
        $this->useIdentityDocument();
        $this->get('/login')->assertOk()->assertViewIs('spa')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertViewHas('identity', fn ($identity) => $identity['binding']['principal'] === 0)->assertDontSee('data-page=', false);
        foreach (['/login' => 'login', '/register' => 'register'] as $path => $kind) {
            $resource = $this->getJson($path)->assertOk()->assertJsonPath('data.kind', $kind)
                ->assertJsonPath('visibility.protected', false)->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.token')->json();
            $this->assertApplicationContract('AuthEntryResource', $resource);
        }
    }

    public function test_password_login_keeps_the_existing_server_intended_destination_and_transition(): void
    {
        $user = User::factory()->create();
        $this->useIdentityDocument();
        $this->withSession(['url.intended' => '/security/devices'])
            ->postJson('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('redirect', '/security/devices')->assertJsonPath('transition.kind', 'login');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('password', DB::table('security_sessions')->where('user_id', $user->id)->latest('id')->value('auth_method'));
    }

    public function test_native_rejected_login_retains_only_safe_validation_fields_and_can_return_to_a_guest_document(): void
    {
        $user = User::factory()->create();
        $this->useIdentityDocument();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'not-the-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest();
        $this->get('/login', ['Accept' => 'text/html'])->assertOk()->assertViewIs('spa')
            ->assertViewHas('identity', fn ($identity) => $identity['binding']['principal'] === 0);
    }

    public function test_native_registration_keeps_pending_lifecycle_and_the_existing_verification_destination(): void
    {
        Notification::fake();
        $this->useIdentityDocument('/register');
        $this->postJson('/register', ['name' => 'Native pending advertiser', 'email' => 'native-pending@example.test',
            'password' => 'NativePendingPassword42!', 'password_confirmation' => 'NativePendingPassword42!'])
            ->assertOk()->assertJsonPath('redirect', '/verify-email');
        $user = User::where('email', 'native-pending@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->email_verified_at);
        $this->assertSame('pending', DB::table('principal_lifecycles')->where('user_id', $user->id)->value('status'));
        Notification::assertSentTo($user, SecurityVerifyEmail::class);
    }
}
