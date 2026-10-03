<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class LifecycleActivationTest extends TestCase
{
    public function test_registration_stays_pending_until_verified_and_repeated_verification_is_idempotent(): void
    {
        $response = $this->post('/register', [
            'name' => 'Advertiser',
            'email' => 'advertiser@example.com',
            'password' => 'long unique password 2026',
            'password_confirmation' => 'long unique password 2026',
        ]);

        $user = User::query()->where('email', 'advertiser@example.com')->firstOrFail();
        $response->assertRedirect(route('verification.notice', absolute: false));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('principal_lifecycles', [
            'user_id' => $user->id,
            'status' => 'pending',
            'generation' => 0,
        ]);
        $this->assertSame(0, DB::table('security_action_grants')->where('subject_user_id', $user->id)->count());
        $this->get('/dashboard')->assertRedirect(route('verification.notice', absolute: false));

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
        ]);
        $this->get('/dashboard')->assertRedirect(route('business.start'));
        $this->get('/business/start')->assertOk();

        $this->get($url)->assertRedirect();
        $this->assertSame(1, (int) DB::table('principal_lifecycles')->where('user_id', $user->id)->value('generation'));
        $this->assertSame(1, DB::table('security_action_grants')->where('subject_user_id', $user->id)->count());
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
