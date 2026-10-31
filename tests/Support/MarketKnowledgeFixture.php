<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait MarketKnowledgeFixture
{
    use MarketProcessingFixture;

    private function operator(array $actions = ['market.knowledge.capture']): User
    {
        $user = User::factory()->create();
        $db = DB::connection('pgsql_migrations');
        $db->table('principal_lifecycles')->where('user_id', $user->id)->update(['principal_class' => 'staff']);
        $db->table('internal_personnel')->insert(['user_id' => $user->id, 'kind' => 'employee', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $db->table('passkeys')->insert(['user_id' => $user->id, 'name' => 'SYNTHETIC', 'credential_id' => 'knowledge-'.$user->id, 'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($actions as $action) {
            $db->table('security_action_grants')->insert(['subject_user_id' => $user->id, 'subject_generation' => 1, 'source' => 'founder_bootstrap', 'action' => $action, 'scope_kind' => 'platform', 'reason' => 'SYNTHETIC explicitly qualified operator', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $user;
    }

    private function captureBody(User $operator, array $originals, string $purpose = 'market_research'): array
    {
        $selection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => []];
        foreach ($originals['records'] as $record) {
            $right = json_decode(DB::connection('pgsql_migrations')->scalar('SELECT builderad_v2_market_knowledge_enroll(?::uuid,?::uuid,1,?,?::jsonb)', [
                (string) Str::uuid(), $record['source'], 'market.evidence', json_encode([
                    'scope_id' => (string) Str::uuid7(), 'subject_ref' => $operator->public_id, 'subject_generation' => 1,
                    'operation' => 'process', 'purpose' => $purpose, 'selection' => [
                        'native_namespaces' => ['market.evidence'], 'native_keys' => [$record['evidence']], 'field_keys' => array_keys($record['payload']['fields']), 'partition_ref' => null,
                    ], 'valid_until' => null, 'evidence_ref' => 'test:synthetic-human-operator', 'evidence_digest' => hash('sha256', 'SYNTHETIC'),
                ], JSON_THROW_ON_ERROR),
            ]), true, flags: JSON_THROW_ON_ERROR);
            $selection['requirements'][] = ['requirement_kind' => 'data_use', 'leaf_grant_id' => $right['grant_id']];
        }

        return ['command_id' => (string) Str::uuid(), 'question' => 'compare_offers', 'material_refs' => array_column($originals['records'], 'ref'),
            'purpose' => $purpose, 'authority_selection' => $selection];
    }

    private function knowledgeRight(User $user, string $source, string $key, string $resource, string $operation, array $fields, string $purpose = 'market_research', ?string $validUntil = null): array
    {
        return json_decode(DB::connection('pgsql_migrations')->scalar('SELECT builderad_v2_market_knowledge_enroll(?::uuid,?::uuid,1,?,?::jsonb)', [
            (string) Str::uuid(), $source, $resource, json_encode([
                'scope_id' => (string) Str::uuid7(), 'subject_ref' => $user->public_id, 'subject_generation' => 1, 'operation' => $operation, 'purpose' => $purpose,
                'selection' => ['native_namespaces' => [$resource], 'native_keys' => [$key], 'field_keys' => $fields, 'partition_ref' => null],
                'valid_until' => $validUntil, 'evidence_ref' => 'test:synthetic-qualified-knowledge-only', 'evidence_digest' => hash('sha256', 'SYNTHETIC'),
            ], JSON_THROW_ON_ERROR),
        ]), true, flags: JSON_THROW_ON_ERROR);
    }

    private function knowledgeOriginals(string $type = 'offer'): array
    {
        $writer = User::factory()->create();
        $this->actingAs($writer);
        $source = (string) Str::uuid7();
        $this->admit('provision_source', $source, 0, [
            'family' => 'synthetic', 'namespace' => 'synthetic_knowledge', 'external_id' => $source, 'label' => 'SYNTHETIC licensed transformation test; no commercial claim',
            'policy_id' => 'test.synthetic.knowledge', 'policy_version' => 1, 'evidence_ref' => 'test:synthetic-only', 'evidence_digest' => hash('sha256', 'synthetic'),
            'allowed_operations' => ['ingest', 'correct', 'retained_read', 'process', 'display', 'forward', 'retain', 'reuse'],
            'allowed_purposes' => ['market_research', 'competitive_research'],
            'allowed_fields' => ['subject', 'geography', 'currency', 'price', 'inclusions', 'product', 'service', 'metric', 'value', 'comparison_basis', 'finding'], 'valid_until' => null,
        ]);
        $records = [];
        foreach ([false, true] as $after) {
            $evidence = (string) Str::uuid7();
            $payload = $this->offer();
            $payload['record_key'] = $evidence;
            $payload['evidence_type'] = $type === 'offer' ? 'market.offer:2' : 'market.interest_signal:2';
            $payload['fields'] = $type === 'offer' ? MarketProcessingExamples::offer() : MarketProcessingExamples::signal($after);
            $payload['coverage'] = 'COMPLETE';
            $right = $this->marketRight($writer, $source, $evidence, 'ingest', array_keys($payload['fields']));
            $ref = $this->writeEvidence($evidence, $right, $payload)->assertOk()->json('data.material_ref');
            $records[] = compact('source', 'evidence', 'payload', 'ref');
        }

        return compact('writer', 'source', 'records');
    }

    private function knowledgePublication(User $operator, array $originals, array $capture): array
    {
        $purpose = $capture['purpose'];
        $selection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => []];
        foreach ($originals['records'] as $record) {
            foreach (['forward', 'retain'] as $operation) {
                $right = $this->knowledgeRight($operator, $record['source'], $record['evidence'], 'market.evidence', $operation, array_keys($record['payload']['fields']), $purpose);
                $selection['requirements'][] = ['requirement_kind' => 'data_use', 'leaf_grant_id' => $right['grant_id']];
            }
        }
        $output = $this->knowledgeRight($operator, $originals['source'], $capture['command_id'], 'market.knowledge', 'display', ['finding'], $purpose);
        $selection['requirements'][] = ['requirement_kind' => 'data_use', 'leaf_grant_id' => $output['grant_id']];

        return ['command_id' => (string) Str::uuid(), 'run_ref' => $capture['command_id'], 'output_scope_id' => $output['scope_id'],
            'process_selection' => $capture['authority_selection'], 'publication_selection' => $selection];
    }

    private function publishedKnowledge(): array
    {
        $originals = $this->knowledgeOriginals();
        $operator = $this->operator(['market.knowledge.capture', 'market.knowledge.publish']);
        $capture = $this->captureBody($operator, $originals);
        $publish = $this->knowledgePublication($operator, $originals, $capture);
        $this->actingAsInternal($operator)->postJson('/internal/market-knowledge/capture', $capture)->assertOk();
        $ref = $this->postJson('/internal/market-knowledge/publish', $publish)->assertOk()->json('data.artifact_ref');

        return compact('originals', 'operator', 'capture', 'publish', 'ref');
    }

    private function knowledgeConsumer(array $shared, string $purpose = 'market_research'): array
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $business = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'SYNTHETIC independent knowledge consumer'])->assertCreated()->json('data');
        $id = DB::connection('pgsql_migrations')->table('businesses')->where('public_id', $business['business_public_id'])->value('id');
        $read = BusinessAuthorityFixture::root($id, $user->id, 'business.data.use');
        $right = $this->knowledgeRight($user, $shared['originals']['source'], $shared['ref']['key'], 'market.knowledge', 'display', ['finding'], $purpose);
        $this->postJson('/api/businesses/'.$business['business_public_id'].'/data-use-bindings', [
            'command_id' => (string) Str::uuid(), 'data_use_scope_id' => $right['scope_id'], 'effect' => 'add', 'expected_relation' => 0,
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => $business['grant_refs']['business.link.manage']], ['requirement_kind' => 'data_use', 'leaf_grant_id' => $right['grant_id']],
            ]],
        ])->assertOk();

        return compact('user', 'business', 'id', 'read', 'right');
    }

    private function readKnowledge(array $consumer, array $ref, string $purpose = 'market_research')
    {
        $this->actingAs($consumer['user']);

        return $this->postJson('/api/businesses/'.$consumer['business']['business_public_id'].'/materials/read', [
            'command_id' => (string) Str::uuid(), 'material_refs' => [$ref], 'operation' => 'display', 'purpose' => $purpose, 'recipient_ref' => $consumer['user']->public_id,
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => $consumer['read']->id], ['requirement_kind' => 'data_use', 'leaf_grant_id' => $consumer['right']['grant_id']],
            ]],
        ]);
    }

    private function withdrawKnowledgeRoot(int $grant, ?string $command = null): array
    {
        $owner = DB::connection('pgsql_migrations');
        $row = $owner->table('security_action_grants')->where('id', $grant)->sole();

        return json_decode($owner->scalar('SELECT builderad_v2_market_knowledge_withdraw(?::uuid,?::uuid,?,1,?)', [
            $command ?? (string) Str::uuid(), $row->data_use_scope_id, $grant, 'SYNTHETIC explicitly withdrawn source licence',
        ]), true, flags: JSON_THROW_ON_ERROR);
    }
}
