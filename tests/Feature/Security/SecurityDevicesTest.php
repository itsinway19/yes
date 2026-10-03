<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Security\SessionAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SecurityDevicesTest extends TestCase
{
    public function test_key_provider_is_visible_to_its_owner_without_exposing_credential_data(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $database = DB::connection('pgsql_migrations');
        foreach ([$user->id, $other->id] as $ownerId) {
            $database->table('passkeys')->insert([
                'user_id' => $ownerId,
                'name' => 'Test key',
                'credential_id' => bin2hex(random_bytes(16)),
                'credential' => json_encode(['aaguid' => 'd3452668-01fd-4c12-926c-83a4204853aa']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAs($user)->get('/security/devices')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Security/Devices')
                ->has('passkeys', 1)
                ->where('passkeys.0.provider', 'Microsoft Password Manager')
                ->missing('passkeys.0.credential')
                ->etc());
    }

    public function test_user_sees_and_revokes_only_their_own_sessions(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user);
        $otherId = $this->insertSession($user->id, 'second-browser');
        $foreignId = $this->insertSession($other->id, 'foreign-browser');

        $this->get('/security/devices')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Security/Devices')
            ->has('sessions', 2));

        $this->delete('/security/sessions/'.$foreignId)->assertNotFound();
        $this->assertNull(DB::table('security_sessions')->where('id', $foreignId)->value('revoked_at'));

        $this->delete('/security/sessions/'.$otherId)->assertRedirect();
        $this->assertNotNull(DB::table('security_sessions')->where('id', $otherId)->value('revoked_at'));
        $this->get('/security/devices')->assertOk();
    }

    public function test_sign_out_other_sessions_keeps_current_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $otherId = $this->insertSession($user->id, 'second-browser');

        $this->post('/security/sessions/revoke-others')->assertRedirect();
        $this->assertNotNull(DB::table('security_sessions')->where('id', $otherId)->value('revoked_at'));
        $this->get('/security/devices')->assertOk();
    }

    public function test_session_revocation_rolls_back_if_required_audit_fails(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $otherId = $this->insertSession($user->id, 'audit-failure-browser');
        $owner = DB::connection('pgsql_migrations');
        $owner->statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_test_session_audit() RETURNS trigger
            LANGUAGE plpgsql AS $$ BEGIN
                IF NEW.event = 'session_revoked' THEN
                    RAISE EXCEPTION 'Forced audit failure' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$
            SQL);
        $owner->statement('CREATE TRIGGER reject_test_session_audit BEFORE INSERT ON security_audit_events FOR EACH ROW EXECUTE FUNCTION reject_test_session_audit()');

        try {
            $request = Request::create('/security/sessions/'.$otherId, 'DELETE');
            $request->setLaravelSession($this->app['session']->driver());
            try {
                app(SessionAuthority::class)->revokeSelected($request, $user, $otherId);
                $this->fail('Session revocation committed without its audit record.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }
        } finally {
            $owner->statement('DROP TRIGGER reject_test_session_audit ON security_audit_events');
            $owner->statement('DROP FUNCTION reject_test_session_audit()');
        }

        $this->assertNull(DB::table('security_sessions')->where('id', $otherId)->value('revoked_at'));
    }

    public function test_in_flight_security_mutation_serializes_with_session_revocation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $request = Request::create('/security/sessions/revoke-others', 'POST');
        $request->setLaravelSession($this->app['session']->driver());
        $currentId = DB::table('security_sessions')->where('user_id', $user->id)->value('id');
        config()->set('database.connections.pgsql_concurrent', config('database.connections.pgsql_authority'));
        $other = DB::connection('pgsql_concurrent');

        try {
            DB::connection('pgsql_authority')->transaction(function () use ($request, $user, $currentId, $other): void {
                app(SessionAuthority::class)->assertCurrentAuthority($request, $user, 1);
                try {
                    $other->table('security_sessions')->where('id', $currentId)
                        ->lock('FOR UPDATE NOWAIT')->first();
                    $this->fail('Session invalidation bypassed an in-flight security action.');
                } catch (QueryException $exception) {
                    $this->assertSame('55P03', $exception->getCode());
                }
            });
        } finally {
            DB::purge('pgsql_concurrent');
        }

        $this->assertNotNull(DB::connection('pgsql_authority')->table('security_sessions')
            ->where('id', $currentId)->lockForUpdate()->first());
    }

    private function insertSession(int $userId, string $key): int
    {
        $now = now();

        return DB::connection('pgsql_migrations')->table('security_sessions')->insertGetId([
            'user_id' => $userId,
            'session_key_hash' => hash('sha256', $key),
            'lifecycle_generation' => 1,
            'auth_method' => 'password',
            'client_description' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0',
            'authenticated_at' => $now,
            'last_seen_at' => $now,
            'idle_expires_at' => $now->copy()->addHour(),
            'absolute_expires_at' => $now->copy()->addDay(),
        ]);
    }
}
