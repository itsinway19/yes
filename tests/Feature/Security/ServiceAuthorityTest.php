<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Security\ServiceBusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServiceAuthorityTest extends TestCase
{
    public function test_business_metadata_requires_exact_independent_approval_and_administration_for_each_consent_version(): void
    {
        $owner = User::factory()->create();
        $manager = $this->serviceManager();
        $this->actingAsInternal($manager);
        $service = $this->postJson('/internal/services', ['name' => 'Bounded metadata reader'])->assertOk()->json();
        $business = $this->actingAs($owner)->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Bounded Business'])
            ->assertCreated()->json('data');
        $target = $business['business_public_id'];
        $path = '/businesses/'.$target.'/services';
        $api = '/api/businesses/'.$target;
        $selection = static fn ($grant) => ['realm_kind' => 'direct', 'requirements' => [
            ['requirement_kind' => 'business', 'leaf_grant_id' => (int) $grant],
        ]];
        $basis = $selection($business['grant_refs']['business.context.read']);
        $purpose = 'Explicit bounded metadata purpose';
        $metadata = fn (?string $requested = null, ?string $credential = null) => app(ServiceBusinessContext::class)
            ->metadata($service['public_id'], $credential ?? $service['credential'], $target, $requested ?? $purpose);
        $this->assertDenied($metadata);
        $command = ['command_id' => (string) Str::uuid(), 'service' => $service['public_id'], 'purpose' => $purpose,
            'expected_revision' => 0, 'business_revision' => 0, 'authority_selection' => $selection($business['grant_refs']['business.context.manage'])];
        $this->postJson($path, $command)->assertForbidden();
        $mandate = $this->postJson($api.'/operating-mandate', ['command_id' => (string) Str::uuid(), 'confirmed' => true,
            'capacity' => 'responsible_business', 'authority_reference' => 'Explicit responsibility for this Business',
            'valid_until' => now()->addHour()->toIso8601String(), 'previous_grant' => '0', 'authority_selection' => $basis])
            ->assertOk()->json('data.grant_refs')['business.data.use'];
        $enrollment = ['command_id' => (string) Str::uuid(), 'confirmed' => true, 'authority_reference' => 'Explicit limited metadata service responsibility',
            'expires_at' => now()->addMinutes(30)->toIso8601String(), 'mandate_grant_id' => (string) $mandate, 'authority_selection' => $basis];
        $approval = $this->postJson($api.'/service-approval/enrollment', $enrollment)->assertOk()->json('data');
        $this->postJson($api.'/service-approval/enrollment', $enrollment)->assertOk()->assertJsonPath('data.grant_id', $approval['grant_id']);
        $command['authority_selection'] = $selection($approval['grant_id']);
        $command['business_revision'] = $approval['revision'];
        $consent = $this->postJson($path, $command)->assertOk()->json('versions');
        $this->postJson($path, $command)->assertOk()->assertJsonPath('versions', $consent);
        $this->assertDenied($metadata);
        $grant = ['service' => $service['public_id'], 'business' => $target, 'reason' => 'Approve only Business metadata'];
        $this->actingAsInternal($manager)->postJson('/internal/service-grants', $grant)->assertOk();
        $this->postJson('/internal/service-grants', $grant)->assertOk();
        $this->assertSame(['public_id' => $target, 'lifecycle' => 'declared_draft', 'display_name' => 'Bounded Business'],
            array_replace(array_fill_keys(['public_id', 'lifecycle', 'display_name'], null), $metadata()));
        $this->assertDenied(fn () => $metadata('Another metadata purpose'));
        $revoke = [...$command, 'command_id' => (string) Str::uuid(), 'expected_revision' => $consent['consent'], 'business_revision' => $consent['business']];
        $revoked = $this->actingAs($owner)->deleteJson($path.'/'.$service['public_id'], $revoke)->assertOk()->json('versions');
        $this->assertDenied($metadata);
        $this->postJson($path, [...$command, 'command_id' => (string) Str::uuid(),
            'expected_revision' => $revoked['consent'], 'business_revision' => $revoked['business']])->assertOk();
        $this->assertDenied($metadata);
        $this->actingAsInternal($manager)->postJson('/internal/service-grants', $grant)->assertOk();
        $this->assertSame($target, $metadata()['public_id']);
        $rotated = $this->postJson('/internal/services/'.$service['public_id'].'/rotate')->assertOk()->json();
        $this->assertDenied($metadata);
        $this->assertEquals(['public_id' => $target, 'lifecycle' => 'declared_draft', 'display_name' => 'Bounded Business'],
            $metadata(credential: $rotated['credential']));
        $this->assertDenied(fn () => $metadata('Another metadata purpose', $rotated['credential']));

        $suspension = ['command_id' => (string) Str::uuid7(), 'kind' => 'businesses', 'target' => $target,
            'decision' => 'suspend', 'reason' => 'Test administrative Business suspension', 'expected_status' => 'declared_draft', 'expected_revision' => 0];
        $this->postJson('/internal/administration/customers/status', $suspension)->assertOk();
        $this->assertDenied(fn () => $metadata(credential: $rotated['credential']));
        $this->postJson('/internal/administration/customers/status', [...$suspension, 'command_id' => (string) Str::uuid7(),
            'decision' => 'reactivate', 'expected_status' => 'suspended', 'expected_revision' => 1])->assertOk();
        $this->assertSame($target, $metadata(credential: $rotated['credential'])['public_id']);

        $db = DB::connection('pgsql_migrations');
        $this->assertSame(2, $db->table('service_audit_events')->where('event', 'service_business_consent_approved')->count());
        $this->assertSame(1, $db->table('service_audit_events')->where('event', 'service_business_consent_revoked')->count());
        $db->table('service_action_grants')->where('consent_version', 3)->update(['expires_at' => now()->subMinute()]);
        $this->assertDenied(fn () => $metadata(credential: $rotated['credential']));
        $this->actingAsInternal($manager)->postJson('/internal/service-grants', $grant)->assertForbidden();
        self::assertSame(2, $db->table('service_audit_events')->where('event', 'service_grant_issued')->count());
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
