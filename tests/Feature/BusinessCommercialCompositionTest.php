<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\ApplicationContractAssertions;
use Tests\TestCase;

final class BusinessCommercialCompositionTest extends TestCase
{
    use ApplicationContractAssertions;

    public function test_native_commercial_projection_keeps_business_beneficiary_and_unknown_capacity_separate_from_authority(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $businesses = [];
        foreach (['First beneficiary', 'Second beneficiary'] as $name) {
            $businesses[] = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => $name])->assertCreated()->json('data');
        }
        foreach ($businesses as $business) {
            $surface = $this->getJson('/api/businesses/'.$business['business_public_id'].'/surface?access='.$business['grant_refs']['business.context.read'])->assertOk();
            $this->assertApplicationContract('BusinessSurfaceResult', $surface->json());
            $surface->assertJsonPath('data.commercial.beneficiary.public_id', $business['business_public_id'])
                ->assertJsonPath('data.commercial.context', null)->assertJsonPath('data.commercial.subscription_state', 'UNKNOWN')
                ->assertJsonPath('data.commercial.entitlement_state', 'UNKNOWN')->assertJsonPath('data.commercial.quota_state', 'UNKNOWN')
                ->assertJsonPath('data.commercial.payer_state', 'UNKNOWN')->assertJsonPath('data.commercial.coverage_state', 'UNKNOWN')
                ->assertJsonPath('data.commercial.conditioned_feature_admission', 'HOLD')->assertJsonPath('data.commercial.usage_reservation', 'NOT_CREATED')
                ->assertJsonPath('data.commercial.foundation_declarations.quota', 'NOT_APPLICABLE');
            // Local foundation declarations do not need provider configuration,
            // an invented plan, a billing account or an invented metered unit.
            $this->putJson('/api/businesses/'.$business['business_public_id'].'/context', [
                'command_id' => (string) Str::uuid(), 'name' => 'Still an authorized local declaration', 'business_type_id' => null, 'expected_context' => 0,
                'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
                    'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $business['grant_refs']['business.context.manage']]]],
            ])->assertOk();
        }
        $other = User::factory()->create();
        $this->actingAs($other)->getJson('/api/businesses/'.$businesses[0]['business_public_id'].'/surface?access='.$businesses[0]['grant_refs']['business.context.read'])->assertForbidden();
    }
}
