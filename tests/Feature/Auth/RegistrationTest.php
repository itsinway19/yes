<?php

namespace Tests\Feature\Auth;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200)->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Register')
            ->where('passwordPolicy.min', 15)
            ->where('passwordPolicy.max', 1024)
            ->where('passwordPolicy.browserRules', 'minlength: 15; maxlength: 1024;'));
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'long unique password 2026',
            'password_confirmation' => 'long unique password 2026',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('verification.notice', absolute: false));
    }

    public function test_mismatched_password_confirmation_is_rejected_in_arabic(): void
    {
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
}
