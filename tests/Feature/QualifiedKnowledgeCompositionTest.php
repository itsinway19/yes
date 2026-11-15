<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\Support\MarketKnowledgeFixture;
use Tests\TestCase;

final class QualifiedKnowledgeCompositionTest extends TestCase
{
    use MarketKnowledgeFixture;

    public function test_business_opportunity_context_can_read_two_distinct_results_only_with_each_current_right(): void
    {
        $originals = $this->knowledgeOriginals();
        $originals['records'] = [$originals['records'][0]];
        $operator = $this->operator(['market.knowledge.capture', 'market.knowledge.publish']);
        $outputs = [];
        foreach (['record_price', 'record_geography'] as $question) {
            $capture = $this->captureBody($operator, $originals);
            $capture['question'] = $question;
            $publish = $this->knowledgePublication($operator, $originals, $capture);
            $this->actingAsInternal($operator)->postJson('/internal/market-knowledge/capture', $capture)->assertOk();
            $ref = $this->postJson('/internal/market-knowledge/publish', $publish)->assertOk()->json('data.artifact_ref');
            $outputs[$question] = compact('originals', 'ref');
        }
        $consumer = $this->knowledgeConsumer($outputs['record_price']);
        $business = $consumer['business'];
        $base = '/api/businesses/'.$business['business_public_id'];
        $selection = fn (string $action) => ['realm_kind' => 'direct', 'requirements' => [
            ['requirement_kind' => 'business', 'leaf_grant_id' => $business['grant_refs'][$action]],
        ]];
        // Actual opportunity declarations remain distinct from observed geography/price.
        $this->putJson($base.'/markets', ['command_id' => (string) Str::uuid(), 'codes' => ['JO'], 'expected_context' => 0,
            'authority_selection' => $selection('business.markets.declare')])->assertOk();
        $this->putJson($base.'/preferences', ['command_id' => (string) Str::uuid(), 'expected_preference' => 0,
            'value' => ['goals' => ['growth'], 'priority' => 'growth', 'results' => ['growth' => ['new_markets']]],
            'authority_selection' => $selection('business.preferences.manage')])->assertOk()->assertJsonPath('data.value.results.growth.0', 'new_markets');
        $this->getJson($base.'/surface?access='.$business['grant_refs']['business.context.read'])->assertOk()
            ->assertJsonPath('data.commercial.entitlement_state', 'UNKNOWN')->assertJsonPath('data.commercial.conditioned_feature_admission', 'HOLD');

        $other = $this->knowledgeRight($consumer['user'], $originals['source'], $outputs['record_geography']['ref']['key'], 'market.knowledge', 'display', ['finding']);
        $binding = $selection('business.link.manage');
        $binding['requirements'][] = ['requirement_kind' => 'data_use', 'leaf_grant_id' => $other['grant_id']];
        $this->postJson($base.'/data-use-bindings', ['command_id' => (string) Str::uuid(), 'data_use_scope_id' => $other['scope_id'],
            'effect' => 'add', 'expected_relation' => 0, 'authority_selection' => $binding])->assertOk();
        $body = ['command_id' => (string) Str::uuid(), 'material_refs' => array_column(array_values($outputs), 'ref'),
            'operation' => 'display', 'purpose' => 'market_research', 'recipient_ref' => $consumer['user']->public_id,
            'authority_selection' => ['realm_kind' => 'direct', 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => $consumer['read']->id],
                ['requirement_kind' => 'data_use', 'leaf_grant_id' => $consumer['right']['grant_id']],
                ['requirement_kind' => 'data_use', 'leaf_grant_id' => $other['grant_id']],
            ]]];
        $read = $this->postJson($base.'/materials/read', $body)->assertOk();
        $this->assertCount(2, $read->json('data.materials'));
        $findings = [];
        foreach ($read->json('data.materials') as $material) {
            $finding = $material['fields']['finding'];
            $this->assertSame('current', $finding['validity']['status']);
            $findings[$finding['method_ref']] = $finding;
        }
        $this->assertEquals(['price' => 200, 'currency' => 'SAR'], $findings['qualified_price_observation:1']['values']);
        $this->assertEquals(['geography' => ['ref' => 'SA', 'level' => 'country']], $findings['qualified_geography_observation:1']['values']);
        $this->assertContains('OBSERVED_PRICE_NOT_COMPLETED_SALE', $findings['qualified_price_observation:1']['limitations']);
        $this->assertContains('GEOGRAPHY_IS_NOT_AUDIENCE_REACH', $findings['qualified_geography_observation:1']['limitations']);
        // The JO declaration did not rewrite the SA observation. Reading both is
        // not a synthesized recommendation, causal claim or execution approval.
        $this->withdrawKnowledgeRoot($other['grant_id']);
        $this->actingAs($consumer['user'])->postJson($base.'/materials/read', $body)->assertForbidden();
        $this->readKnowledge($consumer, $outputs['record_price']['ref'])->assertOk();
    }
}
