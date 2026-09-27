<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_disabled_advertiser_login_fails_without_retaining_a_web_session(): void
    {
        $user = User::factory()->create();
        DB::connection('pgsql_migrations')->table('principal_lifecycles')->where('user_id', $user->id)
            ->update(['status' => 'disabled', 'generation' => 2, 'cause' => 'security_hold']);

        $this->post('/login', [
            'email' => $user->email, 'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(0, DB::table('security_sessions')->where('user_id', $user->id)->count());
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
