<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\SecurityResetPassword as ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIdentityDocument('/forgot-password');
    }

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'new unique password 2026',
                'password_confirmation' => 'new unique password 2026',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    public function test_successful_password_reset_revokes_existing_sessions_without_reactivating_the_account(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('business.start'));
        $this->app['auth']->logout();
        $this->flushSession();
        // The reset requester is another browser, not a reload of the old
        // Redis credential. Preserve that old canonical session for revocation.
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->useIdentityDocument('/forgot-password');
        $this->assertSame(1, DB::table('security_sessions')->where('user_id', $user->id)->whereNull('revoked_at')->count());

        $this->post('/forgot-password', ['email' => $user->email]);
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'new unique password 2026',
                'password_confirmation' => 'new unique password 2026',
            ])->assertRedirect(route('login'));

            return true;
        });

        $this->assertSame(0, DB::table('security_sessions')->where('user_id', $user->id)->whereNull('revoked_at')->count());
        $this->assertSame('current', DB::table('principal_lifecycles')->where('user_id', $user->id)->value('status'));
    }

    public function test_reset_token_is_single_use_and_cannot_change_password_twice(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user): bool {
            $input = [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'new unique password 2026',
                'password_confirmation' => 'new unique password 2026',
            ];
            $this->post('/reset-password', $input)->assertRedirect(route('login'));
            $this->post('/reset-password', $input)->assertSessionHasErrors('email');
            $this->assertTrue(Hash::check('new unique password 2026', $user->refresh()->password));

            return true;
        });
    }

    public function test_reset_request_does_not_reveal_unknown_or_active_staff_email(): void
    {
        Notification::fake();
        $staff = User::factory()->create();
        DB::connection('pgsql_migrations')->table('principal_lifecycles')->where('user_id', $staff->id)
            ->update(['principal_class' => 'staff']);

        $unknown = $this->post('/forgot-password', ['email' => 'absent@example.test']);
        $activeStaff = $this->post('/forgot-password', ['email' => $staff->email]);
        $unknown->assertSessionHas('status', __('security.reset.requested'));
        $activeStaff->assertSessionHas('status', __('security.reset.requested'));
        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }
}
