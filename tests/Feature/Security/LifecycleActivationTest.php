<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\Support\RegistrationEmailProofFixture;
use Tests\TestCase;

class LifecycleActivationTest extends TestCase
{
    use RegistrationEmailProofFixture;

    public function test_registration_stays_pending_until_verified_and_repeated_verification_is_idempotent(): void
    {
        $this->useIdentityDocument('/register');
        $command = [
            'name' => 'Advertiser',
            'email' => 'advertiser@example.com',
            'password' => 'long unique password 2026',
            'password_confirmation' => 'long unique password 2026',
        ];
        // Registration730 verifies email before creating the advertiser. A pending
        // attempt has no account, lifecycle, session or grant to activate.
        $this->post('/register', $command)->assertSessionHasErrors('attempt_id');
        $code = $this->issueRegistrationCode($command['email']);
        $this->post('/register', [...$command, 'attempt_id' => $code['attempt_id']])->assertSessionHasErrors('attempt_id');
        $this->assertGuest();
        foreach (['users', 'principal_lifecycles', 'security_sessions', 'security_action_grants'] as $table) {
            $this->assertSame(0, DB::connection('pgsql_migrations')->table($table)->count(), $table);
        }
        $this->postJson('/register/email/verify', $code)->assertOk()->assertJsonPath('state', 'verified');
        $this->assertDatabaseCount('users', 0);
        $this->post('/register', [...$command, 'attempt_id' => $code['attempt_id']])
            ->assertOk()->assertJsonPath('redirect', '/businesses/new');
        $user = User::query()->where('email', $command['email'])->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->hasVerifiedEmail());

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);
        $this->get($url)->assertRedirect();
        $this->assertDatabaseHas('principal_lifecycles', [
            'user_id' => $user->id,
            'status' => 'current',
            'generation' => 1,
        ]);
        $this->assertDatabaseHas('security_action_grants', [
            'subject_user_id' => $user->id,
            'source' => 'system_registration',
            'action' => 'workspace.create',
            'subject_generation' => 1,
        ], 'pgsql_migrations');
        // Verification advances the lifecycle. Enter the new native document
        // instead of submitting the old pending document's signed receipt.
        $this->useIdentityDocument('/profile');
        $this->get('/dashboard')->assertRedirect(route('business-foundation.index-page', ['entry' => 1]));
        $this->get('/organizations/new')->assertRedirect('/businesses/new');

        $this->get($url)->assertRedirect();
        $this->assertSame(1, (int) DB::table('principal_lifecycles')->where('user_id', $user->id)->value('generation'));
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('security_action_grants')->where('subject_user_id', $user->id)->count());
    }

    public function test_database_rejects_lifecycle_generation_regression_and_same_generation_reactivation(): void
    {
        $user = User::factory()->create();
        try {
            DB::connection('pgsql_migrations')->table('principal_lifecycles')->where('user_id', $user->id)
                ->update(['generation' => 0]);
            $this->fail('A lifecycle generation must not decrease.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }

        DB::connection('pgsql_migrations')->table('principal_lifecycles')->where('user_id', $user->id)
            ->update(['status' => 'pending', 'cause' => 'primary_email_unverified']);
        try {
            DB::connection('pgsql_migrations')->table('principal_lifecycles')->where('user_id', $user->id)
                ->update(['status' => 'current', 'cause' => null]);
            $this->fail('Returning to current state requires a fresh generation.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
    }
}
