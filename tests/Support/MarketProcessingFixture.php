<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait MarketProcessingFixture
{
    use MarketEvidenceFixture;

    private function processingSource(): string
    {
        $source = (string) Str::uuid7();
        $this->admit('provision_source', $source, 0, [
            'family' => 'synthetic', 'namespace' => 'synthetic_processing', 'external_id' => $source, 'label' => 'SYNTHETIC PROCESSING; NOT REAL MARKET',
            'policy_id' => 'test.synthetic.processing', 'policy_version' => 1, 'evidence_ref' => 'test:synthetic-only', 'evidence_digest' => hash('sha256', 'synthetic'),
            'allowed_operations' => ['ingest', 'correct', 'retained_read', 'process', 'display'], 'allowed_purposes' => ['market_research'],
            'allowed_fields' => ['subject', 'geography', 'currency', 'price', 'inclusions', 'product', 'service', 'metric', 'value', 'comparison_basis'], 'valid_until' => null,
        ]);

        return $source;
    }

    private function processingOriginals(string $type = 'offer', bool $distinctSources = false): array
    {
        $writer = User::factory()->create();
        $this->actingAs($writer);
        $source = $this->processingSource();
        $records = [];
        foreach ([false, true] as $after) {
            $recordSource = $distinctSources && $after ? $this->processingSource() : $source;
            $evidence = (string) Str::uuid7();
            $fields = $type === 'offer' ? MarketProcessingExamples::offer() : MarketProcessingExamples::signal($after);
            $payload = $this->offer();
            $payload['record_key'] = $evidence;
            $payload['evidence_type'] = 'market.'.($type === 'offer' ? 'offer' : 'interest_signal').':2';
            $payload['fields'] = $fields;
            $payload['coverage'] = 'COMPLETE';
            $payload['provenance'] = ['private' => 'DO_NOT_DISCLOSE_SOURCE_TOKEN'];
            $right = $this->marketRight($writer, $recordSource, $evidence, 'ingest', array_keys($fields));
            $ref = $this->writeEvidence($evidence, $right, $payload)->assertOk()->json('data.material_ref');
            $records[] = ['source' => $recordSource, ...compact('evidence', 'payload', 'ref')];
        }

        return compact('writer', 'source', 'records');
    }

    private function processingConsumer(array $originals, ?array $fields = null): array
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $business = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'SYNTHETIC D2 consumer'])->assertCreated()->json('data');
        $id = DB::connection('pgsql_migrations')->table('businesses')->where('public_id', $business['business_public_id'])->value('id');
        $data = BusinessAuthorityFixture::root($id, $user->id, 'business.data.use');
        $selection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $data->id]]];
        $rights = [];
        foreach ($originals['records'] as $record) {
            $right = $this->marketRight($user, $record['source'] ?? $originals['source'], $record['evidence'], 'process', $fields ?? array_keys($record['payload']['fields']));
            $this->postJson('/api/businesses/'.$business['business_public_id'].'/data-use-bindings', [
                'command_id' => (string) Str::uuid(), 'data_use_scope_id' => $right['scope_id'], 'effect' => 'add', 'expected_relation' => 0,
                'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                    ['requirement_kind' => 'business', 'leaf_grant_id' => $business['grant_refs']['business.link.manage']], ['requirement_kind' => 'data_use', 'leaf_grant_id' => $right['grant_id']],
                ]],
            ])->assertOk();
            $selection['requirements'][] = ['requirement_kind' => 'data_use', 'leaf_grant_id' => $right['grant_id']];
            $rights[] = $right;
        }

        return compact('user', 'business', 'id', 'selection', 'rights');
    }

    private function processingBody(array $originals, array $consumer, string $question = 'compare_offers'): array
    {
        return ['command_id' => (string) Str::uuid(), 'question' => $question, 'material_refs' => array_column($originals['records'], 'ref'),
            'purpose' => 'market_research', 'authority_selection' => $consumer['selection'], 'context_selection' => null];
    }

    private function prepareProcessing(array $consumer, array $body)
    {
        return $this->postJson('/api/businesses/'.$consumer['business']['business_public_id'].'/market-analysis/prepare', $body);
    }

    private function completeProcessing(array $consumer, string $run, ?array $body = null)
    {
        return $this->postJson('/api/businesses/'.$consumer['business']['business_public_id'].'/market-analysis/'.$run.'/complete', $body ?? [
            'command_id' => (string) Str::uuid(), 'effect' => 'complete', 'authority_selection' => $consumer['selection'], 'context_selection' => null,
        ]);
    }
}
