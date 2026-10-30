<?php

namespace Tests\Support;

use App\Models\User;
use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Explicit synthetic licence declarations through the actual isolated maintenance entry. */
trait MarketEvidenceFixture
{
    private function admit(string $action, string $source, int $expected, array $payload, ?string $command = null): array
    {
        app(DisposableTestDatabaseGuard::class)->assertSafe();

        return json_decode(DB::connection('pgsql_migrations')->scalar('SELECT builderad_v2_market_admit(?::uuid,?,?::uuid,?,?::jsonb)', [
            $command ?? (string) Str::uuid(), $action, $source, $expected, json_encode($payload, JSON_THROW_ON_ERROR),
        ]), true, flags: JSON_THROW_ON_ERROR);
    }

    private function marketSource(): string
    {
        $source = (string) Str::uuid7();
        $this->admit('provision_source', $source, 0, [
            'family' => 'synthetic', 'namespace' => 'synthetic_market', 'external_id' => $source, 'label' => 'SYNTHETIC MARKET; NOT REAL DEMAND',
            'policy_id' => 'test.synthetic.market', 'policy_version' => 1, 'evidence_ref' => 'test:synthetic-licence-only', 'evidence_digest' => hash('sha256', 'synthetic'),
            'allowed_operations' => ['ingest', 'correct', 'retained_read', 'process', 'display'], 'allowed_purposes' => ['market_research'],
            'allowed_fields' => ['subject', 'geography', 'currency', 'price', 'inclusions', 'metric', 'value'], 'valid_until' => null,
        ]);

        return $source;
    }

    private function marketRight(User $user, string $source, string $evidence, string $operation, array $fields): array
    {
        sort($fields, SORT_STRING);

        return [...$this->admit('enroll_data_use', $source, 1, [
            'scope_id' => (string) Str::uuid7(), 'subject_ref' => $user->public_id,
            'subject_generation' => DB::connection('pgsql_migrations')->table('principal_lifecycles')->where('user_id', $user->id)->value('generation'),
            'operation' => $operation, 'purpose' => 'market_research', 'selection' => [
                'native_namespaces' => ['market.evidence'], 'native_keys' => [$evidence], 'field_keys' => $fields, 'partition_ref' => null,
            ], 'valid_until' => null, 'evidence_ref' => 'test:synthetic-independent-recipient', 'evidence_digest' => hash('sha256', 'synthetic:'.$user->id),
        ]), 'operation' => $operation];
    }

    private function marketSelection(array $right): array
    {
        return ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
            'requirements' => [['requirement_kind' => 'data_use', 'leaf_grant_id' => $right['grant_id']]]];
    }

    private function offer(): array
    {
        return ['record_key' => 'synthetic-offer-1', 'evidence_type' => 'market.offer:1', 'fields' => [
            'subject' => ['key' => null, 'label' => 'SYNTHETIC OFFER', 'identity_state' => 'unresolved'],
            'geography' => ['ref' => null, 'level' => 'unknown'], 'currency' => 'SAR', 'price' => 0, 'inclusions' => ['synthetic installation'],
        ], 'coverage' => 'PARTIAL', 'provenance' => ['synthetic' => true, 'observed_claim_not_verified_demand' => true],
            'observed_at' => '2020-01-01T00:00:00Z', 'effective_at' => '2020-01-01T00:00:00Z', 'valid_until' => '2090-01-01T00:00:00Z', 'reason' => 'Synthetic contract test'];
    }

    private function writeEvidence(string $evidence, array $right, array $payload, int $expected = 0, ?string $command = null)
    {
        return $this->postJson('/api/market-evidence/'.$evidence, ['command_id' => $command ?? (string) Str::uuid(),
            'data_use_scope_id' => $right['scope_id'], 'expected_revision' => $expected, 'payload' => $payload, 'authority_selection' => $this->marketSelection($right)]);
    }
}
