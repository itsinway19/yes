<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Security\GrantAuthority;
use App\Security\LifecycleAuthority;
use App\Security\ServiceWorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ServiceAuthorityTest extends TestCase
{
    public function test_service_requires_independent_identity_owner_scope_and_action_grant(): void
    {
        $owner = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($owner, 1);
        $this->actingAs($owner)->post('/workspaces', [
            'name' => 'Owner Workspace', 'slug' => 'owner-workspace',
        ])->assertRedirect();
        $workspace = DB::connection('pgsql_migrations')->table('workspaces')->first();

        $manager = $this->serviceManager();
        $this->actingAsInternal($manager)->get('/internal/security/devices')->assertOk();
        $created = $this->post('/internal/services', ['name' => 'Report Reader'])
            ->assertRedirect();
        $this->assertDatabaseCount('service_identities', 1);
        $secret = $this->serviceCredentialFrom($created);
        $this->assertNotEmpty($secret['credential']);
        $service = DB::table('service_identities')->where('public_id', $secret['public_id'])->first();
        $this->assertNotSame($secret['credential'], $service->credential_hash);
        $this->assertSame(0, DB::table('service_action_grants')->count());

        $context = app(ServiceWorkspaceContext::class);
        $this->assertDenied(fn () => $context->run($secret['public_id'], $secret['credential'],
            $workspace->public_id, fn () => null));

        $this->actingAs($owner)->post('/workspaces/'.$workspace->public_id.'/services', [
            'service' => $secret['public_id'], 'purpose' => 'Scoped reporting for this Workspace',
        ])->assertRedirect();
        $this->assertDenied(fn () => $context->run($secret['public_id'], $secret['credential'],
            $workspace->public_id, fn () => null));

        $this->actingAsInternal($manager)->post('/internal/service-grants', [
            'service' => $secret['public_id'],
            'workspace' => $workspace->public_id,
            'reason' => 'Owner approved metadata reporting',
        ])->assertRedirect();
        $visible = $context->run($secret['public_id'], $secret['credential'],
            $workspace->public_id, fn () => DB::table('workspaces')->pluck('id')->all());
        $this->assertSame([$workspace->id], $visible);
        $this->assertSame(0, DB::table('workspaces')->count());

        DB::transaction(function () use ($secret, $service, $workspace): void {
            DB::selectOne("SELECT set_config('app.service_identity_id', ?, true)", [(string) $service->id]);
            DB::selectOne("SELECT set_config('app.service_credential', ?, true)", [$secret['credential']]);
            DB::selectOne("SELECT set_config('app.workspace_id', ?, true)", [(string) $workspace->id]);
            DB::selectOne("SELECT set_config('app.authorization_phase', 'service', true)");
            DB::selectOne("SELECT set_config('app.signed_service_context', 'forged', true)");
            $this->assertSame(0, DB::table('workspaces')->count());
        });

        $this->assertInFlightEffectSerializesWithScopeRevocation(
            $context, $secret, $workspace->public_id, $service->id, $workspace->id,
        );

        $grantId = DB::table('service_action_grants')->where('service_identity_id', $service->id)
            ->where('workspace_id', $workspace->id)->where('status', 'active')->value('id');
        $this->actingAsInternal($manager)->delete('/internal/service-grants/'.$grantId)->assertRedirect();
        $this->assertDenied(fn () => $context->run($secret['public_id'], $secret['credential'],
            $workspace->public_id, fn () => null));
        $this->actingAsInternal($manager)->post('/internal/service-grants', [
            'service' => $secret['public_id'], 'workspace' => $workspace->public_id,
            'reason' => 'Owner approved metadata reporting',
        ])->assertRedirect();

        $rotatedResponse = $this->actingAsInternal($manager)->post('/internal/services/'.$secret['public_id'].'/rotate')
            ->assertRedirect();
        $rotated = $this->serviceCredentialFrom($rotatedResponse);
        $this->assertNotSame($secret['credential'], $rotated['credential']);
        $this->assertDenied(fn () => $context->run($secret['public_id'], $secret['credential'],
            $workspace->public_id, fn () => null));
        $secret = $rotated;
        $this->assertSame([$workspace->id], $context->run($secret['public_id'], $secret['credential'],
            $workspace->public_id, fn () => DB::table('workspaces')->pluck('id')->all()));

        app(LifecycleAuthority::class)->disable($manager, $manager->id, 'service_manager_no_longer_eligible');
        $this->assertSame([$workspace->id], $context->run(
            $secret['public_id'], $secret['credential'], $workspace->public_id,
            fn () => DB::table('workspaces')->pluck('id')->all(),
        ));

        $this->actingAs($owner)->delete('/workspaces/'.$workspace->public_id.'/services/'.$secret['public_id'])
            ->assertRedirect();
        $this->assertDenied(fn () => $context->run($secret['public_id'], $secret['credential'],
            $workspace->public_id, fn () => null));
        $this->assertDatabaseHas('service_audit_events', [
            'service_identity_id' => $service->id,
            'workspace_id' => $workspace->id,
            'event' => 'service_scope_revoked',
        ]);
    }

    public function test_revoked_service_identity_cannot_access_approved_workspace(): void
    {
        $owner = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($owner, 1);
        $this->actingAs($owner)->post('/workspaces', [
            'name' => 'Revocation Workspace', 'slug' => 'revocation-workspace',
        ])->assertRedirect();
        $workspace = DB::connection('pgsql_migrations')->table('workspaces')->first();
        $manager = $this->serviceManager();
        $this->actingAsInternal($manager)->get('/internal/security/devices')->assertOk();
        $created = $this->post('/internal/services', ['name' => 'Revocable Reader'])
            ->assertRedirect();
        $this->assertDatabaseCount('service_identities', 1);
        $secret = $this->serviceCredentialFrom($created);
        $this->actingAs($owner)->post('/workspaces/'.$workspace->public_id.'/services', [
            'service' => $secret['public_id'], 'purpose' => 'Owner-approved metadata read',
        ])->assertRedirect();
        $this->actingAsInternal($manager)->post('/internal/service-grants', [
            'service' => $secret['public_id'], 'workspace' => $workspace->public_id,
            'reason' => 'Owner approved metadata reporting',
        ])->assertRedirect();
        $context = app(ServiceWorkspaceContext::class);
        $this->assertSame($workspace->id, $context->run($secret['public_id'], $secret['credential'],
            $workspace->public_id, fn ($active) => $active->id));

        DB::connection('pgsql_migrations')->table('principal_lifecycles')->where('user_id', $owner->id)
            ->update(['principal_class' => 'staff']);
        $this->assertDenied(fn () => $context->run($secret['public_id'], $secret['credential'],
            $workspace->public_id, fn () => null));
        $serviceId = DB::table('service_identities')
            ->where('public_id', $secret['public_id'])->value('id');
        DB::transaction(function () use ($serviceId, $secret, $workspace): void {
            DB::selectOne("SELECT set_config('app.service_identity_id', ?, true)", [(string) $serviceId]);
            DB::selectOne("SELECT set_config('app.service_credential', ?, true)", [$secret['credential']]);
            DB::selectOne("SELECT set_config('app.workspace_id', ?, true)", [(string) $workspace->id]);
            DB::selectOne("SELECT set_config('app.authorization_phase', 'service', true)");
            $this->assertSame(0, DB::table('workspaces')->count());
        });
        DB::connection('pgsql_migrations')->table('principal_lifecycles')->where('user_id', $owner->id)
            ->update(['principal_class' => 'advertiser']);

        $this->actingAsInternal($manager)->post('/internal/services/'.$secret['public_id'].'/revoke')
            ->assertRedirect();
        $this->assertDenied(fn () => $context->run($secret['public_id'], $secret['credential'],
            $workspace->public_id, fn () => null));
    }

    private function assertInFlightEffectSerializesWithScopeRevocation(
        ServiceWorkspaceContext $context, array $secret, string $workspacePublicId,
        int $serviceId, int $workspaceId,
    ): void {
        config()->set('database.connections.pgsql_concurrent', config('database.connections.pgsql_authority'));
        $other = DB::connection('pgsql_concurrent');
        try {
            $other->statement("SET lock_timeout = '150ms'");
            $context->run($secret['public_id'], $secret['credential'], $workspacePublicId,
                function () use ($other, $serviceId, $workspaceId): void {
                    try {
                        $other->table('service_workspace_scopes')
                            ->where('service_identity_id', $serviceId)
                            ->where('workspace_id', $workspaceId)
                            ->update(['purpose' => 'Concurrent scope revocation probe']);
                        $this->fail('An in-flight service effect must hold the scope row lock.');
                    } catch (QueryException $exception) {
                        $this->assertSame('55P03', $exception->getCode());
                    }
                });
        } finally {
            DB::purge('pgsql_concurrent');
        }
    }

    private function serviceManager(): User
    {
        $manager = User::factory()->create();
        DB::connection('pgsql_migrations')->table('principal_lifecycles')->where('user_id', $manager->id)
            ->update(['principal_class' => 'staff']);
        DB::connection('pgsql_migrations')->table('internal_personnel')->insert([
            'user_id' => $manager->id, 'kind' => 'founder', 'status' => 'active',
            'attested_at' => now(), 'factor_independence_attested_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['primary', 'backup'] as $factor) {
            DB::connection('pgsql_migrations')->table('passkeys')->insert([
                'user_id' => $manager->id, 'name' => $factor,
                'credential_id' => $factor.'-'.$manager->id, 'credential' => '{}',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::connection('pgsql_migrations')->table('security_action_grants')->insert([
            'subject_user_id' => $manager->id, 'source' => 'founder_bootstrap',
            'action' => 'service.manage', 'scope_kind' => 'platform',
            'subject_generation' => 1, 'reason' => 'service manager fixture',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $manager;
    }

    /** @return array{public_id: string, credential: string} */
    private function serviceCredentialFrom(TestResponse $response): array
    {
        $credential = $response->baseResponse->getSession()?->get('serviceCredential');
        $this->assertIsArray($credential);

        return $credential;
    }

    private function assertDenied(callable $effect): void
    {
        try {
            $effect();
            $this->fail('Service authority should be denied.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }
}
