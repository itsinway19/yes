<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\MarketKnowledgeFixture;
use Tests\TestCase;

final class MarketKnowledgeConsumersTest extends TestCase
{
    use ApplicationContractAssertions;
    use MarketKnowledgeFixture;

    private function consume(array $consumer, array $ref, string $kind)
    {
        $this->actingAs($consumer['user']);

        return $this->postJson('/api/businesses/'.$consumer['business']['business_public_id'].'/knowledge/'.$kind, [
            'command_id' => (string) Str::uuid(), 'material_ref' => $ref, 'purpose' => 'market_research',
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => $consumer['read']->id], ['requirement_kind' => 'data_use', 'leaf_grant_id' => $consumer['right']['grant_id']],
            ]],
        ]);
    }

    public function test_two_real_consumers_answer_different_questions_from_the_same_qualified_knowledge(): void
    {
        $shared = $this->publishedKnowledge();
        $consumer = $this->knowledgeConsumer($shared);
        $customers = $this->consume($consumer, $shared['ref'], 'customer-discovery')->assertOk()->assertJsonPath('data.assessment', 'needs_interest_evidence');
        $competition = $this->consume($consumer, $shared['ref'], 'competition')->assertOk()->assertJsonPath('data.assessment', 'terms_comparable');
        $this->assertNotSame($customers->json('data.next_step'), $competition->json('data.next_step'));
        foreach ([$customers, $competition] as $response) {
            $this->assertApplicationContract('KnowledgeConsumerResult', $response->json());
            $this->assertStringNotContainsString($shared['operator']->public_id, $response->getContent());
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        }
    }

    public function test_interest_supports_investigating_reach_but_a_metric_definition_correction_requires_review(): void
    {
        $originals = $this->knowledgeOriginals('signal');
        $operator = $this->operator(['market.knowledge.capture', 'market.knowledge.publish']);
        $capture = $this->captureBody($operator, $originals);
        $capture['question'] = 'compare_interest';
        $publish = $this->knowledgePublication($operator, $originals, $capture);
        $this->actingAsInternal($operator)->postJson('/internal/market-knowledge/capture', $capture)->assertOk();
        $ref = $this->postJson('/internal/market-knowledge/publish', $publish)->assertOk()->json('data.artifact_ref');
        $consumer = $this->knowledgeConsumer(compact('originals', 'operator', 'capture', 'publish', 'ref'));
        $this->consume($consumer, $ref, 'customer-discovery')->assertOk()->assertJsonPath('data.assessment', 'investigate_reach');
        $record = $originals['records'][1];
        $payload = $record['payload'];
        $payload['fields']['metric']['definition_version'] = '2';
        $this->actingAs($originals['writer']);
        $right = $this->marketRight($originals['writer'], $record['source'], $record['evidence'], 'correct', array_keys($payload['fields']));
        $this->writeEvidence($record['evidence'], $right, $payload, 1)->assertOk();
        $this->consume($consumer, $ref, 'customer-discovery')->assertOk()->assertJsonPath('data.assessment', 'needs_review');
        $this->readKnowledge($consumer, $ref)->assertOk()->assertJsonPath('data.materials.0.fields.finding.values.percent_change', 25);
    }

    public function test_saved_knowledge_works_without_a_connector_then_its_stale_evidence_withholds_a_current_conclusion(): void
    {
        Http::fake();
        $shared = $this->publishedKnowledge();
        $consumer = $this->knowledgeConsumer($shared);
        $this->consume($consumer, $shared['ref'], 'competition')->assertOk()->assertJsonPath('data.assessment', 'terms_comparable');
        Http::assertNothingSent();
        $record = $shared['originals']['records'][0];
        $payload = $record['payload'];
        $payload['valid_until'] = '2021-01-01T00:00:00Z';
        $this->actingAs($shared['originals']['writer']);
        $right = $this->marketRight($shared['originals']['writer'], $record['source'], $record['evidence'], 'correct', array_keys($payload['fields']));
        $this->writeEvidence($record['evidence'], $right, $payload, 1)->assertOk();
        $this->readKnowledge($consumer, $shared['ref'])->assertOk()->assertJsonPath('data.materials.0.fields.finding.validity.status', 'review_required');
        $this->consume($consumer, $shared['ref'], 'competition')->assertOk()->assertJsonPath('data.assessment', 'needs_review');
        Http::assertNothingSent();
    }
}
