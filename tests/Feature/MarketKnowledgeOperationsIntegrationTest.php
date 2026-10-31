<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\MarketKnowledgeFixture;
use Tests\TestCase;

final class MarketKnowledgeOperationsIntegrationTest extends TestCase
{
    use ApplicationContractAssertions;
    use MarketKnowledgeFixture;

    private function operationalPublication(string $type = 'offer', string $question = 'compare_offers', ?array $correction = null, bool $publishResult = true): array
    {
        $originals = $this->knowledgeOriginals($type);
        if ($correction !== null) {
            $record = $originals['records'][0];
            $payload = $record['payload'];
            $payload['fields'] = array_replace_recursive($payload['fields'], $correction);
            $this->actingAs($originals['writer']);
            $right = $this->marketRight($originals['writer'], $record['source'], $record['evidence'], 'correct', array_keys($payload['fields']));
            $ref = $this->writeEvidence($record['evidence'], $right, $payload, 1)->assertOk()->json('data.material_ref');
            $originals['records'][0] = [...$record, 'payload' => $payload, 'ref' => $ref];
        }
        if (in_array($question, ['record_price', 'record_geography'], true)) {
            $originals['records'] = [$originals['records'][0]];
        }
        $operator = $this->operator(['market.knowledge.capture', 'market.knowledge.publish']);
        $capture = $this->captureBody($operator, $originals);
        $capture['question'] = $question;
        $publish = $this->knowledgePublication($operator, $originals, $capture);
        $this->actingAsInternal($operator)->postJson('/internal/market-knowledge/capture', $capture)->assertOk();
        $ref = $publishResult ? $this->postJson('/internal/market-knowledge/publish', $publish)->assertOk()->json('data.artifact_ref') : null;

        return compact('originals', 'operator', 'capture', 'publish', 'ref');
    }

    public function test_current_completed_and_abstained_findings_for_one_method_never_collapse_into_one_operational_group(): void
    {
        $completed = $this->operationalPublication('signal', 'compare_interest');
        $abstained = $this->operationalPublication('signal', 'compare_interest', ['value' => 0]);
        $this->actingAsInternal($this->operator(['market.knowledge.observe']));
        $response = $this->getJson('/internal/administration/market-knowledge')->assertOk();
        $groups = $response->json('data.shared_knowledge');
        $this->assertCount(2, $groups, 'Current evidence does not turn an analytical abstention into a completed comparison.');
        $this->assertEqualsCanonicalizing([
            ['method_ref' => 'qualified_interest_change:1', 'publication_state' => 'published', 'validity' => 'current', 'processing_outcome' => 'completed', 'reason_category' => 'none', 'count' => 1],
            ['method_ref' => 'qualified_interest_change:1', 'publication_state' => 'published', 'validity' => 'current', 'processing_outcome' => 'abstained', 'reason_category' => 'comparison_basis_insufficient', 'count' => 1],
        ], $groups);
        $this->assertApplicationContract('MarketOperationsResource', $response->json());
        foreach ([$completed, $abstained] as $shared) {
            foreach ([$shared['ref']['key'], $shared['operator']->public_id, $shared['ref']['version']] as $private) {
                $this->assertStringNotContainsString($private, $response->getContent());
            }
        }
        foreach (['ZERO_BASELINE', 'QUALIFIED_COMPARABLE_PERIODS', 'percent_change', 'reason_codes', 'evidence_refs', 'comparison_basis', 'session_digest', 'input_digest'] as $private) {
            $this->assertStringNotContainsString('"'.$private.'"', $response->getContent());
        }
    }

    public function test_actual_unresolved_missing_information_and_different_findings_have_method_qualified_outcomes(): void
    {
        $this->operationalPublication('offer', 'compare_offers', ['product' => ['model' => null]]);
        $this->operationalPublication('offer', 'record_price', ['price' => null]);
        $this->operationalPublication('offer', 'compare_offers', ['product' => ['model' => 'other-qualified-model']]);
        $this->actingAsInternal($this->operator(['market.knowledge.observe']));
        $response = $this->getJson('/internal/administration/market-knowledge')->assertOk();
        $this->assertApplicationContract('MarketOperationsResource', $response->json());
        $groups = $response->json('data.shared_knowledge');
        $this->assertCount(3, $groups);
        foreach ($groups as $group) {
            $this->assertSame('published', $group['publication_state']);
            $this->assertSame('current', $group['validity']);
            $this->assertSame(1, $group['count']);
            $this->assertEqualsCanonicalizing(['method_ref', 'publication_state', 'validity', 'processing_outcome', 'reason_category', 'count'], array_keys($group));
        }
        $this->assertCount(1, array_filter($groups, fn ($row) => $row['method_ref'] === 'offer_equivalence:1' && $row['processing_outcome'] === 'completed' && $row['reason_category'] === 'none'));
        $this->assertCount(1, array_filter($groups, fn ($row) => $row['method_ref'] === 'offer_equivalence:1' && $row['processing_outcome'] === 'abstained' && $row['reason_category'] === 'required_information_missing'));
        $this->assertCount(1, array_filter($groups, fn ($row) => $row['method_ref'] === 'qualified_price_observation:1' && $row['processing_outcome'] === 'abstained' && $row['reason_category'] === 'required_information_missing'));
        foreach (['PRODUCT_IDENTITY_INCOMPLETE', 'QUALIFIED_FACT_FIELDS_REQUIRED', 'PRODUCT_MODEL_DIFFERS', 'other-qualified-model'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
    }

    public function test_prepared_and_non_disclosable_outputs_never_reveal_an_old_processing_outcome(): void
    {
        $this->operationalPublication('offer', 'record_geography', null, false);
        $shared = $this->operationalPublication();
        $observer = $this->operator(['market.knowledge.observe']);
        $this->actingAsInternal($observer);
        $groups = $this->getJson('/internal/administration/market-knowledge')->assertOk()->json('data.shared_knowledge');
        $prepared = array_values(array_filter($groups, fn ($row) => $row['publication_state'] === 'prepared'))[0];
        $this->assertSame('unknown', $prepared['processing_outcome']);
        $this->assertSame('none', $prepared['reason_category']);
        $this->withdrawKnowledgeRoot($shared['publish']['publication_selection']['requirements'][0]['leaf_grant_id']);
        $groups = $this->getJson('/internal/administration/market-knowledge')->assertOk()->json('data.shared_knowledge');
        $unavailable = array_values(array_filter($groups, fn ($row) => $row['publication_state'] === 'published'))[0];
        $this->assertSame('unavailable', $unavailable['validity']);
        $this->assertSame('unavailable', $unavailable['processing_outcome']);
        $this->assertSame('unavailable', $unavailable['reason_category']);
        $this->withdrawKnowledgeRoot($shared['publish']['publication_selection']['requirements'][1]['leaf_grant_id']);
        $groups = $this->getJson('/internal/administration/market-knowledge')->assertOk()->json('data.shared_knowledge');
        $removed = array_values(array_filter($groups, fn ($row) => $row['publication_state'] === 'retention_removed'))[0];
        $this->assertSame('unavailable', $removed['processing_outcome']);
        $this->assertSame('unavailable', $removed['reason_category']);
    }

    public function test_closed_reason_mapping_rejects_unsupported_method_status_and_arbitrary_reason_details(): void
    {
        $owner = DB::connection('pgsql_migrations');
        // Pure mapper probes do not manufacture or install trusted findings in a producer table.
        $classify = fn ($method, $finding) => $owner->transaction(function () use ($owner, $method, $finding) {
            $owner->statement('SET LOCAL ROLE builderad_scope_executor');

            return json_decode($owner->scalar('SELECT builderad_private.market_knowledge_operational_outcome(?,?::jsonb)', [$method, json_encode($finding, JSON_THROW_ON_ERROR)]), true, flags: JSON_THROW_ON_ERROR);
        });
        $finding = fn ($method, $status, $codes) => ['method_ref' => $method, 'status' => $status, 'reason_codes' => $codes,
            'values' => ['private' => 'PRIVATE_VALUE', 'baseline' => 0], 'evidence_refs' => ['PRIVATE_REF'], 'actor' => 'PRIVATE_ACTOR'];
        $cases = [
            ['qualified_interest_change:1', 'not_comparable', ['ZERO_BASELINE'], 'abstained', 'comparison_basis_insufficient'],
            ['qualified_interest_change:1', 'not_comparable', ['METRIC_NOT_EQUIVALENT'], 'abstained', 'comparison_basis_insufficient'],
            ['qualified_interest_change:1', 'not_comparable', ['VALUE_UNKNOWN'], 'abstained', 'required_information_missing'],
            ['offer_equivalence:1', 'unresolved', ['PRODUCT_IDENTITY_INCOMPLETE'], 'abstained', 'required_information_missing'],
            ['qualified_price_observation:1', 'requires_information', ['QUALIFIED_FACT_FIELDS_REQUIRED'], 'abstained', 'required_information_missing'],
            ['qualified_geography_observation:1', 'requires_information', ['QUALIFIED_FACT_FIELDS_REQUIRED'], 'abstained', 'required_information_missing'],
            ['offer_equivalence:1', 'different', ['SERVICE_WARRANTY_MONTHS_DIFFER'], 'completed', 'none'],
            ['qualified_interest_change:1', 'not_comparable', ['PRIVATE_UNSUPPORTED_REASON'], 'abstained', 'generic_abstention'],
            ['qualified_price_observation:1', 'requires_information', ['ZERO_BASELINE'], 'abstained', 'generic_abstention'],
            ['offer_equivalence:1', 'unresolved', ['METRIC_NOT_EQUIVALENT'], 'abstained', 'generic_abstention'],
            ['qualified_interest_change:1', 'not_comparable', ['ZERO_BASELINE', 'PRIVATE_UNSUPPORTED_REASON'], 'abstained', 'generic_abstention'],
            ['qualified_interest_change:1', 'not_comparable', ['private arbitrary text'], 'abstained', 'generic_abstention'],
            ['qualified_interest_change:1', 'not_comparable', (object) ['reason' => 'ZERO_BASELINE'], 'abstained', 'generic_abstention'],
            ['qualified_interest_change:1', 'equivalent', ['QUALIFIED_IDENTITY_AND_TERMS_MATCH'], 'unknown', 'unknown'],
            ['offer_equivalence:1', 'different', ['PRIVATE_UNSUPPORTED_REASON'], 'unknown', 'unknown'],
            ['future.method:1', 'observed', ['QUALIFIED_SOURCE_OBSERVATION'], 'unknown', 'unknown'],
        ];
        foreach ($cases as [$method, $status, $codes, $outcome, $reason]) {
            $this->assertSame(['reason_category' => $reason, 'processing_outcome' => $outcome], $classify($method, $finding($method, $status, $codes)));
        }
        foreach ([null, [], 'PRIVATE_TEXT', ['method_ref' => 'offer_equivalence:1', 'status' => 1], $finding('offer_equivalence:1', 'equivalent', ['QUALIFIED_IDENTITY_AND_TERMS_MATCH'])] as $invalid) {
            $this->assertSame(['reason_category' => 'unknown', 'processing_outcome' => 'unknown'], $classify('qualified_interest_change:1', $invalid));
        }
        $schema = json_decode(file_get_contents(base_path('contracts/application.openapi.json')), true)['components']['schemas']['MarketOperationsSharedKnowledge'];
        $this->assertEqualsCanonicalizing(['completed', 'abstained', 'unknown', 'unavailable'], $schema['properties']['processing_outcome']['enum']);
        $this->assertEqualsCanonicalizing(['none', 'comparison_basis_insufficient', 'required_information_missing', 'generic_abstention', 'unknown', 'unavailable'], $schema['properties']['reason_category']['enum']);
        foreach (['builderad_app', 'builderad_authority'] as $role) {
            $this->assertFalse($owner->scalar("SELECT has_function_privilege(?,'builderad_private.market_knowledge_operational_outcome(text,jsonb)','EXECUTE')", [$role]));
        }
        $this->assertFalse($owner->scalar("SELECT prosecdef FROM pg_proc WHERE oid='builderad_private.market_knowledge_operational_outcome(text,jsonb)'::regprocedure"));
    }

    public function test_actual_shared_publication_correction_and_retention_states_are_projected_without_content(): void
    {
        $shared = $this->publishedKnowledge();
        $observer = $this->operator(['market.knowledge.observe']);
        $this->actingAsInternal($observer);
        $read = $this->getJson('/internal/administration/market-knowledge')->assertOk()->assertJsonPath('data.shared_knowledge_window', 100)
            ->assertJsonPath('data.shared_knowledge.0.publication_state', 'published')->assertJsonPath('data.shared_knowledge.0.validity', 'current')->assertJsonPath('data.shared_knowledge.0.count', 1);
        $this->assertApplicationContract('MarketOperationsResource', $read->json());
        $this->assertStringNotContainsString($shared['ref']['key'], $read->getContent());
        $this->assertStringNotContainsString($shared['operator']->public_id, $read->getContent());
        $record = $shared['originals']['records'][0];
        $payload = $record['payload'];
        $payload['fields']['product']['model'] = 'changed-qualified-model';
        $this->actingAs($shared['originals']['writer']);
        $right = $this->marketRight($shared['originals']['writer'], $record['source'], $record['evidence'], 'correct', array_keys($payload['fields']));
        $this->writeEvidence($record['evidence'], $right, $payload, 1)->assertOk();
        $this->actingAsInternal($observer)->getJson('/internal/administration/market-knowledge')->assertOk()->assertJsonPath('data.shared_knowledge.0.validity', 'review_required')->assertJsonPath('data.shared_knowledge.0.processing_outcome', 'completed');
        $db = DB::connection('pgsql_migrations');
        $retain = $shared['publish']['publication_selection']['requirements'][1]['leaf_grant_id'];
        $this->withdrawKnowledgeRoot($retain);
        $this->getJson('/internal/administration/market-knowledge')->assertOk()->assertJsonPath('data.shared_knowledge.0.publication_state', 'retention_removed')->assertJsonPath('data.shared_knowledge.0.validity', 'unavailable')->assertJsonPath('data.shared_knowledge.0.processing_outcome', 'unavailable');
    }
}
