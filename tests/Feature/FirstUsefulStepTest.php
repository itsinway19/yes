<?php

namespace Tests\Feature;

use App\Commercial\FirstUsefulStep;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\BusinessAuthorityFixture;
use Tests\Support\MarketKnowledgeFixture;
use Tests\TestCase;

final class FirstUsefulStepTest extends TestCase
{
    use ApplicationContractAssertions;
    use MarketKnowledgeFixture;

    private function createBusiness(): array
    {
        $this->actingAs(User::factory()->create());

        return $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Unconnected practice'])->assertCreated()->json('data');
    }

    private function path(array $business): string
    {
        return '/api/businesses/'.$business['business_public_id'];
    }

    private function selection(array $business, string $action): array
    {
        return ['realm_kind' => 'direct', 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $business['grant_refs'][$action]]]];
    }

    private function surface(array $business, ?string $focus = null): string
    {
        $query = ['access' => $business['grant_refs']['business.context.read']];
        if ($focus !== null) {
            $query['focus_practice'] = $focus;
        }

        return $this->path($business).'/surface?'.http_build_query($query);
    }

    private function goal(array $business, string $goal, int $revision = 0): void
    {
        $this->putJson($this->path($business).'/preferences', [
            'command_id' => (string) Str::uuid(), 'expected_preference' => $revision,
            'value' => ['goals' => [$goal], 'priority' => $goal, 'results' => [$goal => []]],
            'authority_selection' => $this->selection($business, 'business.preferences.manage'),
        ])->assertOk();
    }

    private function practice(array $business, array $changes = [], ?array $before = null): array
    {
        $value = $before['value'] ?? [
            'name' => 'Maintenance service', 'description' => 'Declared service', 'kind' => 'service',
            'sectors' => [], 'specializations' => [], 'offering' => 'Air conditioner maintenance', 'commercial_model' => null,
            'channels' => [], 'target_markets' => ['status' => 'unspecified', 'areas' => []],
            'fulfillment' => ['status' => 'unspecified', 'areas' => []],
        ];

        return $this->postJson($this->path($business).'/practices', [
            'command_id' => (string) Str::uuid(), 'practice_id' => $before['practice_id'] ?? (string) Str::uuid7(),
            'operation' => $before === null ? 'create' : 'update', 'expected_revision' => $before['revision'] ?? 0,
            'value' => [...$value, ...$changes], 'authority_selection' => $this->selection($business, 'business.context.manage'),
            'read_selection' => $this->selection($business, 'business.context.read'),
        ])->assertOk()->json('data.practice');
    }

    public function test_unconnected_service_asks_only_for_the_input_that_changes_the_growth_decision(): void
    {
        $business = $this->createBusiness();
        $surface = $this->surface($business);
        $initial = $this->getJson($surface)->assertOk()
            ->assertJsonPath('data.first_useful_step.method_revision', 2)
            ->assertJsonPath('data.first_useful_step.status', 'NEEDS_INPUT')
            ->assertJsonPath('data.first_useful_step.reason', 'choose_goal');
        $this->assertApplicationContract('BusinessSurfaceResult', $initial->json());
        $this->goal($business, 'growth');
        $this->getJson($surface)->assertOk()->assertJsonPath('data.first_useful_step.reason', 'add_practice');
        $practice = $this->practice($business, ['offering' => null]);
        $this->getJson($surface)->assertOk()->assertJsonPath('data.first_useful_step.reason', 'describe_offering');
        $practice = $this->practice($business, ['offering' => 'Air conditioner maintenance'], $practice);
        $this->getJson($surface)->assertOk()->assertJsonPath('data.first_useful_step.reason', 'declare_target_market');
        $area = ['status' => 'declared', 'areas' => [['authority' => 'iso3166-1', 'level' => 'country', 'ref' => 'SA']]];
        $practice = $this->practice($business, ['target_markets' => $area], $practice);
        $this->getJson($surface)->assertOk()->assertJsonPath('data.first_useful_step.reason', 'declare_fulfillment')
            ->assertJsonPath('data.first_useful_step.basis.market_check.target_countries', ['SA']);
        $city = ['status' => 'declared', 'areas' => [['authority' => 'business_declared', 'level' => 'city', 'ref' => 'Riyadh']]];
        $practice = $this->practice($business, ['fulfillment' => $city], $practice);
        $this->getJson($surface)->assertOk()->assertJsonPath('data.first_useful_step.status', 'NEEDS_INPUT')
            ->assertJsonPath('data.first_useful_step.reason', 'check_market_coverage');
        $otherArea = ['status' => 'declared', 'areas' => [['authority' => 'iso3166-1', 'level' => 'country', 'ref' => 'JO']]];
        $practice = $this->practice($business, ['fulfillment' => $otherArea], $practice);
        $this->getJson($surface)->assertOk()->assertJsonPath('data.first_useful_step.status', 'NEEDS_INPUT')
            ->assertJsonPath('data.first_useful_step.reason', 'clarify_cross_market_handling')
            ->assertJsonPath('data.first_useful_step.basis.market_check', ['target_countries' => ['SA'], 'fulfillment_countries' => ['JO'], 'shared_countries' => []]);
        $practice = $this->practice($business, ['fulfillment' => $area], $practice);
        $this->getJson($surface)->assertOk()->assertJsonPath('data.first_useful_step.status', 'ACTIONABLE')
            ->assertJsonPath('data.first_useful_step.reason', 'test_offer_in_declared_market')
            ->assertJsonPath('data.first_useful_step.basis.market_check.shared_countries', ['SA'])
            ->assertJsonPath('data.first_useful_step.basis.qualification', 'business_declared')
            ->assertJsonPath('data.first_useful_step.basis.preference_version', 1)
            ->assertJsonPath('data.first_useful_step.basis.practice.revision', $practice['revision']);
        $practice = $this->practice($business, ['description' => 'Unrelated description edit'], $practice);
        $this->getJson($surface)->assertOk()->assertJsonPath('data.first_useful_step.reason', 'test_offer_in_declared_market')
            ->assertJsonPath('data.first_useful_step.basis.practice.revision', $practice['revision']);

        $this->goal($business, 'sales', 1);
        $this->getJson($surface)->assertOk()->assertJsonPath('data.first_useful_step.reason', 'record_sale_evidence')
            ->assertJsonPath('data.first_useful_step.basis.preference_version', 2);
        $this->goal($business, 'efficiency', 2);
        $this->getJson($surface)->assertOk()->assertJsonPath('data.first_useful_step.status', 'NEEDS_INPUT')
            ->assertJsonPath('data.first_useful_step.reason', 'spend_evidence_required');
    }

    public function test_buyer_market_location_does_not_establish_a_fulfillment_barrier_or_feasibility(): void
    {
        $business = $this->createBusiness();
        $this->goal($business, 'growth');
        $markets = ['status' => 'declared', 'areas' => [['authority' => 'iso3166-1', 'level' => 'country', 'ref' => 'SA']]];
        $fulfillment = ['status' => 'declared', 'areas' => [['authority' => 'iso3166-1', 'level' => 'country', 'ref' => 'JO']]];
        $brokerage = $this->practice($business, [
            'kind' => 'brokerage', 'offering' => 'Apartment for sale in Amman', 'target_markets' => $markets, 'fulfillment' => $fulfillment,
        ]);
        // The existing coverage declaration has no structured attendance restriction.
        // Even a description of an on-site service cannot act as a qualified constraint.
        $installation = $this->practice($business, [
            'kind' => 'installation', 'offering' => 'On-site air conditioner installation',
            'description' => 'Work is performed at the installation site', 'target_markets' => $markets, 'fulfillment' => $fulfillment,
        ]);
        foreach ([$brokerage, $installation] as $practice) {
            $response = $this->getJson($this->surface($business, $practice['practice_id']))->assertOk()
                ->assertJsonPath('data.first_useful_step.status', 'NEEDS_INPUT')
                ->assertJsonPath('data.first_useful_step.reason', 'clarify_cross_market_handling')
                ->assertJsonPath('data.first_useful_step.basis.qualification', 'business_declared')
                ->assertJsonPath('data.first_useful_step.basis.market_check.shared_countries', [])
                ->assertJsonPath('data.first_useful_step.basis.practice.practice_id', $practice['practice_id']);
            $this->assertApplicationContract('BusinessSurfaceResult', $response->json());
        }

        $actor = auth()->id();
        $qualification = DB::connection('pgsql_migrations')->table('security_action_grants')
            ->where('id', $business['grant_refs']['business.context.read'])->value('qualification_id');
        BusinessAuthorityFixture::revokeQualification($qualification, $actor);
        $this->getJson($this->surface($business, $brokerage['practice_id']))->assertForbidden()
            ->assertDontSee('Apartment for sale in Amman');
    }

    public function test_future_goal_preserves_its_reference_without_a_spend_or_practice_requirement(): void
    {
        // The current preference writer rejects unknown catalog entries. Exercise
        // this compatibility branch at the judgment owner, without seeding false data.
        $result = (new FirstUsefulStep)->evaluate([
            'business_public_id' => (string) Str::uuid(),
            'state' => 'active',
            'versions' => ['context' => 4, 'preferences' => 3],
            'preferences' => ['priority' => 'retention'],
        ], [], null);

        $this->assertSame('UNAVAILABLE', $result['status']);
        $this->assertSame('goal_not_supported', $result['reason']);
        $this->assertSame('retention', $result['basis']['goal']);
        $this->assertSame(3, $result['basis']['preference_version']);
        $this->assertApplicationContract('FirstUsefulStep', $result);
    }

    public function test_practice_selection_adapts_without_inventing_buyer_types_or_borrowing_authority(): void
    {
        $business = $this->createBusiness();
        $this->goal($business, 'customers');
        $retail = $this->practice($business, ['kind' => 'sale', 'name' => 'Retail', 'commercial_model' => 'Buyer purchases for personal use']);
        $wholesale = $this->practice($business, ['kind' => 'sale', 'name' => 'Wholesale', 'commercial_model' => 'A procurement manager approves store orders']);
        $brokerage = $this->practice($business, ['kind' => 'brokerage', 'name' => 'Property brokerage', 'offering' => 'Property introductions']);
        $this->getJson($this->surface($business))->assertOk()
            ->assertJsonPath('data.first_useful_step.reason', 'choose_practice')->assertJsonCount(3, 'data.first_useful_step.practice_choices');
        $this->getJson($this->surface($business, $retail['practice_id']))->assertOk()
            ->assertJsonPath('data.first_useful_step.reason', 'record_buyer_roles')->assertJsonPath('data.first_useful_step.basis.practice.name', 'Retail');
        $this->getJson($this->surface($business, $wholesale['practice_id']))->assertOk()
            ->assertJsonPath('data.first_useful_step.reason', 'record_buyer_roles')->assertJsonPath('data.first_useful_step.basis.practice.name', 'Wholesale');
        $selected = $this->surface($business, $brokerage['practice_id']);
        $this->getJson($selected)->assertOk()->assertJsonPath('data.first_useful_step.reason', 'record_brokerage_qualifier');
        $this->getJson($this->surface($business, (string) Str::uuid7()))->assertOk()
            ->assertJsonPath('data.first_useful_step.status', 'UNAVAILABLE')->assertJsonPath('data.first_useful_step.basis.practice', null);
        $this->getJson($this->surface($business, 'not-a-uuid'))->assertUnprocessable();
        $other = $this->createBusiness();
        $this->getJson($this->surface($other, $brokerage['practice_id']))->assertOk()
            ->assertJsonPath('data.first_useful_step.reason', 'practice_unavailable')->assertJsonMissing(['name' => 'Property brokerage']);
        $this->getJson($selected)->assertForbidden()->assertDontSee('commercial.first_useful_step');
    }

    public function test_optional_market_material_stays_out_of_the_judgment_before_and_after_withdrawal(): void
    {
        $published = $this->publishedKnowledge();
        $consumer = $this->knowledgeConsumer($published);
        $this->readKnowledge($consumer, $published['ref'])->assertOk();
        $surface = $this->surface($consumer['business']);
        $before = $this->getJson($surface)->assertOk()->json('data.first_useful_step');
        $this->assertSame('choose_goal', $before['reason']);
        $this->assertSame('business_declared', $before['basis']['qualification']);

        $this->withdrawKnowledgeRoot($consumer['right']['grant_id']);
        $this->readKnowledge($consumer, $published['ref'])->assertForbidden();
        $this->getJson($surface)->assertOk()->assertJsonPath('data.first_useful_step', $before);
    }
}
