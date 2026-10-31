<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MarketKnowledgeFixture;
use Tests\TestCase;

final class MarketBusinessFindingTest extends TestCase
{
    use MarketKnowledgeFixture;

    public function test_real_d2_finding_uses_private_v1_publication_and_remains_inaccessible_to_another_business(): void
    {
        $originals = $this->knowledgeOriginals();
        $observer = $this->operator(['market.knowledge.observe']);
        $this->actingAsInternal($observer);
        $before = $this->getJson('/internal/administration/market-knowledge')->assertOk()->json('data');
        $a = $this->processingConsumer($originals);
        $body = $this->processingBody($originals, $a);
        $this->prepareProcessing($a, $body)->assertOk();
        $this->completeProcessing($a, $body['command_id'])->assertOk();
        $read = (string) Str::uuid();
        $this->postJson('/api/businesses/'.$a['business']['business_public_id'].'/materials/read', [
            'command_id' => $read, 'material_refs' => array_column($originals['records'], 'ref'), 'operation' => 'process', 'purpose' => 'market_research',
            'recipient_ref' => $a['user']->public_id, 'authority_selection' => $a['selection'],
        ])->assertOk();
        $artifact = (string) Str::uuid();
        $output = $this->knowledgeRight($a['user'], $originals['source'], $artifact, 'market.business_finding', 'display', ['finding']);
        $continuation = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => []];
        foreach ($originals['records'] as $record) {
            foreach (['forward', 'retain'] as $operation) {
                $right = $this->marketRight($a['user'], $record['source'], $record['evidence'], $operation, array_keys($record['payload']['fields']));
                $continuation['requirements'][] = ['requirement_kind' => 'data_use', 'leaf_grant_id' => $right['grant_id']];
            }
        }
        $this->postJson('/api/businesses/'.$a['business']['business_public_id'].'/data-use-bindings', [
            'command_id' => (string) Str::uuid(), 'data_use_scope_id' => $output['scope_id'], 'effect' => 'add', 'expected_relation' => 0,
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => $a['business']['grant_refs']['business.link.manage']], ['requirement_kind' => 'data_use', 'leaf_grant_id' => $output['grant_id']],
            ]],
        ])->assertOk();
        $captureBody = [
            'command_id' => $artifact, 'processing_run_ref' => $body['command_id'], 'read_command_id' => $read, 'output_scope_id' => $output['scope_id'],
            'authority_selection' => $a['selection'], 'context_selection' => null, 'continuation_selection' => $continuation,
        ];
        $forgedReceipt = $captureBody;
        $forgedReceipt['read_command_id'] = (string) Str::uuid();
        $this->postJson('/api/businesses/'.$a['business']['business_public_id'].'/market-findings/capture', $forgedReceipt)->assertForbidden();
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('market_business_finding_captures')->count());
        $captured = $this->postJson('/api/businesses/'.$a['business']['business_public_id'].'/market-findings/capture', $captureBody)->assertOk()->json('data');
        $selection = $a['selection'];
        $selection['requirements'][] = ['requirement_kind' => 'data_use', 'leaf_grant_id' => $output['grant_id']];
        $publish = ['command_id' => (string) Str::uuid(), 'artifact_ref' => $captured['artifact_ref'], 'dependencies' => $captured['dependencies'],
            'purpose' => 'market_research', 'recipient_ref' => $a['user']->public_id, 'authority_selection' => $selection];
        $reclassified = $publish;
        $reclassified['artifact_ref']['namespace'] = 'market.knowledge';
        $this->postJson('/api/businesses/'.$a['business']['business_public_id'].'/materials/publish', $reclassified)->assertForbidden();
        $this->postJson('/api/businesses/'.$a['business']['business_public_id'].'/materials/publish', $publish)->assertOk();
        $aRead = ['command_id' => (string) Str::uuid(), 'material_refs' => [$captured['artifact_ref']], 'operation' => 'display', 'purpose' => 'market_research',
            'recipient_ref' => $a['user']->public_id, 'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                $a['selection']['requirements'][0], ['requirement_kind' => 'data_use', 'leaf_grant_id' => $output['grant_id']],
            ]]];
        $this->postJson('/api/businesses/'.$a['business']['business_public_id'].'/materials/read', $aRead)->assertOk()->assertJsonPath('data.materials.0.fields.finding.status', 'equivalent');
        $b = $this->processingConsumer($originals);
        $bRight = $this->knowledgeRight($b['user'], $originals['source'], $artifact, 'market.business_finding', 'display', ['finding']);
        $this->postJson('/api/businesses/'.$b['business']['business_public_id'].'/data-use-bindings', [
            'command_id' => (string) Str::uuid(), 'data_use_scope_id' => $bRight['scope_id'], 'effect' => 'add', 'expected_relation' => 0,
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => $b['business']['grant_refs']['business.link.manage']], ['requirement_kind' => 'data_use', 'leaf_grant_id' => $bRight['grant_id']],
            ]],
        ])->assertOk();
        $aRead['command_id'] = (string) Str::uuid();
        $aRead['recipient_ref'] = $b['user']->public_id;
        $aRead['authority_selection']['requirements'] = [$b['selection']['requirements'][0], ['requirement_kind' => 'data_use', 'leaf_grant_id' => $bRight['grant_id']]];
        $this->postJson('/api/businesses/'.$b['business']['business_public_id'].'/materials/read', $aRead)->assertForbidden();
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('material_lineage_manifests')->where('material_namespace', 'market.business_finding')->count());
        $this->actingAsInternal($observer);
        $this->assertSame($before, $this->getJson('/internal/administration/market-knowledge')->assertOk()->json('data'), 'Private D3 capture/publication and independent Business activity must not change even the shared counters.');
    }

    public function test_business_context_correction_marks_published_history_for_review_and_blocks_new_stale_publication(): void
    {
        $f = $this->knowledgeOriginals();
        $f['records'] = [$f['records'][0]];
        $c = $this->processingConsumer($f, ['geography']);
        $business = $c['business']['business_public_id'];
        $markets = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $c['business']['grant_refs']['business.markets.declare']]]];
        $this->putJson('/api/businesses/'.$business.'/markets', ['command_id' => (string) Str::uuid(), 'codes' => ['SA'], 'expected_context' => 0, 'authority_selection' => $markets])->assertOk();
        $body = $this->processingBody($f, $c, 'assess_business_feasibility');
        $body['context_selection'] = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $c['business']['grant_refs']['business.context.read']]]];
        $this->prepareProcessing($c, $body)->assertOk();
        $this->completeProcessing($c, $body['command_id'], ['command_id' => (string) Str::uuid(), 'effect' => 'complete', 'authority_selection' => $c['selection'], 'context_selection' => $body['context_selection']])->assertOk();
        $read = (string) Str::uuid();
        $this->postJson('/api/businesses/'.$business.'/materials/read', ['command_id' => $read, 'material_refs' => array_column($f['records'], 'ref'), 'operation' => 'process', 'purpose' => 'market_research', 'recipient_ref' => $c['user']->public_id, 'authority_selection' => $c['selection']])->assertOk();
        $continuation = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => []];
        foreach (['forward', 'retain'] as $op) {
            $right = $this->marketRight($c['user'], $f['source'], $f['records'][0]['evidence'], $op, ['geography']);
            $continuation['requirements'][] = ['requirement_kind' => 'data_use', 'leaf_grant_id' => $right['grant_id']];
        }
        $artifacts = [];
        foreach ([0, 1] as $index) {
            $key = (string) Str::uuid();
            $output = $this->knowledgeRight($c['user'], $f['source'], $key, 'market.business_finding', 'display', ['finding']);
            $this->postJson('/api/businesses/'.$business.'/data-use-bindings', ['command_id' => (string) Str::uuid(), 'data_use_scope_id' => $output['scope_id'], 'effect' => 'add', 'expected_relation' => 0,
                'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $c['business']['grant_refs']['business.link.manage']], ['requirement_kind' => 'data_use', 'leaf_grant_id' => $output['grant_id']]]],
            ])->assertOk();
            $captured = $this->postJson('/api/businesses/'.$business.'/market-findings/capture', ['command_id' => $key, 'processing_run_ref' => $body['command_id'], 'read_command_id' => $read, 'output_scope_id' => $output['scope_id'], 'authority_selection' => $c['selection'], 'context_selection' => $body['context_selection'], 'continuation_selection' => $continuation])->assertOk()->json('data');
            $selection = $c['selection'];
            $selection['requirements'][] = ['requirement_kind' => 'data_use', 'leaf_grant_id' => $output['grant_id']];
            $publish = ['command_id' => (string) Str::uuid(), 'artifact_ref' => $captured['artifact_ref'], 'dependencies' => $captured['dependencies'], 'purpose' => 'market_research', 'recipient_ref' => $c['user']->public_id, 'authority_selection' => $selection];
            $artifacts[] = compact('output', 'captured', 'publish');
        }
        $this->postJson('/api/businesses/'.$business.'/materials/publish', $artifacts[0]['publish'])->assertOk();
        $this->putJson('/api/businesses/'.$business.'/markets', ['command_id' => (string) Str::uuid(), 'codes' => ['JO'], 'expected_context' => 1, 'authority_selection' => $markets])->assertOk();
        $this->postJson('/api/businesses/'.$business.'/materials/publish', $artifacts[1]['publish'])->assertForbidden();
        $this->postJson('/api/businesses/'.$business.'/materials/read', ['command_id' => (string) Str::uuid(), 'material_refs' => [$artifacts[0]['captured']['artifact_ref']], 'operation' => 'display', 'purpose' => 'market_research', 'recipient_ref' => $c['user']->public_id,
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [$c['selection']['requirements'][0], ['requirement_kind' => 'data_use', 'leaf_grant_id' => $artifacts[0]['output']['grant_id']]]],
        ])->assertOk()->assertJsonPath('data.materials.0.fields.finding.status', 'within_declared_market')->assertJsonPath('data.materials.0.fields.finding.validity.status', 'review_required');
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('material_lineage_manifests')->where('material_namespace', 'market.business_finding')->count());
    }
}
