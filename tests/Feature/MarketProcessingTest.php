<?php

namespace Tests\Feature;

use App\MarketKnowledge\Processing\MarketMethod;
use App\MarketKnowledge\Processing\MarketMethodSelector;
use App\MarketKnowledge\Processing\MarketProcessingTask;
use App\MarketKnowledge\Processing\OfferEquivalence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\BusinessAuthorityFixture as Fixture;
use Tests\Support\MarketProcessingFixture;
use Tests\TestCase;

final class MarketProcessingTest extends TestCase
{
    use ApplicationContractAssertions;
    use MarketProcessingFixture;

    private function decorateMethod(\Closure $callback): void
    {
        $method = new class($callback) implements MarketMethod
        {
            public function __construct(private \Closure $callback) {}

            public function question(): string
            {
                return 'compare_offers';
            }

            public function reference(): string
            {
                return 'offer_equivalence:1';
            }

            public function available(): bool
            {
                return true;
            }

            public function execute(MarketProcessingTask $task): array
            {
                ($this->callback)();

                return (new OfferEquivalence)->execute($task);
            }
        };
        $this->app->instance(MarketMethodSelector::class, new MarketMethodSelector([$method]));
    }

    public function test_qualified_offer_processing_computes_without_transaction_and_retries_have_one_terminal_effect(): void
    {
        $originals = $this->processingOriginals();
        $consumer = $this->processingConsumer($originals, ['geography', 'product', 'service']);
        $body = $this->processingBody($originals, $consumer);
        $run = $body['command_id'];
        $prepared = $this->prepareProcessing($consumer, $body)->assertOk()->assertJsonPath('data.state', 'prepared');
        $this->assertSame($prepared->json(), $this->prepareProcessing($consumer, $body)->assertOk()->json());
        $observed = null;
        $this->decorateMethod(function () use (&$observed): void {
            $level = DB::connection()->transactionLevel();
            $probeStart = hrtime(true);
            $pid = DB::scalar('SELECT pg_backend_pid()');
            $observer = DB::build(config('database.connections.pgsql'));
            try {
                $idle = $observer->selectOne('SELECT state,xact_start FROM pg_stat_activity WHERE pid=?', [$pid]);
                $this->assertNotSame((int) $pid, (int) $observer->scalar('SELECT pg_backend_pid()'));
            } finally {
                $observer->disconnect();
            }
            $observed = ['level' => $level, 'state' => $idle->state, 'xact_start' => $idle->xact_start, 'probe_us' => (int) ((hrtime(true) - $probeStart) / 1000)];
        });
        $complete = ['command_id' => (string) Str::uuid(), 'effect' => 'complete', 'authority_selection' => $consumer['selection'], 'context_selection' => null];
        $response = $this->completeProcessing($consumer, $run, $complete)->assertOk();
        $this->assertNotNull($observed);
        $this->assertSame(0, $observed['level']);
        $this->assertSame('idle', $observed['state']);
        $this->assertNull($observed['xact_start']);
        $this->assertSame('validated', $response->json('data.state'), json_encode(['observation' => $observed, 'outcome' => $response->json('data')]));
        $this->assertApplicationContract('MarketProcessingRunResult', (array) json_decode($response->getContent(), flags: JSON_THROW_ON_ERROR));
        $response
            ->assertJsonPath('data.finding.status', 'equivalent')->assertJsonPath('data.published', false)->assertJsonPath('data.usage.executions', 1)
            ->assertJsonPath('data.usage.external_cost', 'NOT_INCURRED')->assertJsonPath('data.usage.unmeasured_cost', 'UNKNOWN')->assertJsonPath('data.usage.billable', false);
        $this->assertStringNotContainsString('DO_NOT_DISCLOSE_SOURCE_TOKEN', $response->getContent());
        $this->assertArrayNotHasKey('product', $response->json('data.finding'));
        $this->assertSame($response->json(), $this->completeProcessing($consumer, $run, $complete)->assertOk()->json());
        $this->assertSame($response->json(), $this->completeProcessing($consumer, $run)->assertOk()->json());
        $db = DB::connection('pgsql_migrations');
        $this->assertSame(1, $db->table('market_processing_runs')->count());
        $this->assertSame(1, (int) $db->table('market_processing_runs')->value('execution_count'));
        $this->assertSame(0, $db->table('material_lineage_manifests')->count());
        $this->assertSame(0, $db->table('material_use_dependencies')->count());
        $this->assertStringNotContainsString('equivalent', $db->table('business_command_receipts')->where('command_id', $complete['command_id'])->value('result_ref'));
        $wrong = $body;
        $wrong['question'] = 'compare_interest';
        $this->prepareProcessing($consumer, $wrong)->assertConflict();
        $wrong = $complete;
        $wrong['effect'] = 'cancel';
        $this->completeProcessing($consumer, $run, $wrong)->assertConflict();
    }

    public function test_signal_processing_uses_v2_meaning_and_preserves_private_provenance(): void
    {
        $f = $this->processingOriginals('signal');
        $c = $this->processingConsumer($f);
        $body = $this->processingBody($f, $c, 'compare_interest');
        $this->prepareProcessing($c, $body)->assertOk();
        $response = $this->completeProcessing($c, $body['command_id'])->assertOk()->assertJsonPath('data.state', 'validated')
            ->assertJsonPath('data.finding.status', 'comparable')->assertJsonPath('data.finding.values.delta', 10)->assertJsonPath('data.finding.values.percent_change', 25);
        $this->assertStringNotContainsString('DO_NOT_DISCLOSE_SOURCE_TOKEN', $response->getContent());
    }

    public function test_right_withdrawal_during_computation_denies_result_and_leaves_capture_uncompleted(): void
    {
        $f = $this->processingOriginals();
        $c = $this->processingConsumer($f);
        $body = $this->processingBody($f, $c);
        $this->prepareProcessing($c, $body)->assertOk();
        $this->decorateMethod(fn () => Fixture::revokeQualification($c['rights'][0]['qualification_id'], $c['user']->id));
        $response = $this->completeProcessing($c, $body['command_id'])->assertForbidden();
        $this->assertStringNotContainsString('equivalent', $response->getContent());
        $this->assertSame('prepared', DB::connection('pgsql_migrations')->table('market_processing_runs')->value('state'));
        $this->assertSame(0, (int) DB::connection('pgsql_migrations')->table('market_processing_runs')->value('execution_count'));
    }

    public function test_exact_private_result_and_independent_rights_never_cross_businesses(): void
    {
        $f = $this->processingOriginals();
        $a = $this->processingConsumer($f);
        $body = $this->processingBody($f, $a);
        $this->prepareProcessing($a, $body)->assertOk();
        $this->completeProcessing($a, $body['command_id'])->assertOk();
        $b = $this->processingConsumer($f);
        $other = $this->processingBody($f, $b);
        $this->prepareProcessing($b, $other)->assertOk();
        $this->completeProcessing($b, $body['command_id'])->assertForbidden();
        Fixture::revokeQualification($a['rights'][0]['qualification_id'], $a['user']->id);
        $this->completeProcessing($b, $other['command_id'])->assertOk()->assertJsonPath('data.finding.status', 'equivalent');
        $this->actingAs($a['user']);
        $this->completeProcessing($a, $body['command_id'])->assertForbidden();
    }

    public function test_invalid_purpose_unselected_fields_forged_findings_and_arbitrary_methods_are_denied(): void
    {
        $f = $this->processingOriginals();
        $c = $this->processingConsumer($f, ['price']);
        $body = $this->processingBody($f, $c);
        $bad = $body;
        $bad['purpose'] = 'unlicensed';
        $this->prepareProcessing($c, $bad)->assertForbidden();
        $bad = $body;
        $bad['question'] = 'arbitrary_class';
        $this->prepareProcessing($c, $bad)->assertUnprocessable();
        $this->prepareProcessing($c, $body)->assertOk();
        $this->completeProcessing($c, $body['command_id'])->assertOk()->assertJsonPath('data.state', 'abstained')->assertJsonPath('data.finding.status', 'unresolved');
        $this->completeProcessing($c, $body['command_id'], ['command_id' => (string) Str::uuid(), 'effect' => 'complete', 'authority_selection' => $c['selection'], 'context_selection' => null,
            'finding' => ['status' => 'equivalent']])->assertUnprocessable();
    }

    public function test_failure_unavailability_and_cancel_are_explicit_and_do_not_fabricate_success(): void
    {
        $f = $this->processingOriginals();
        $c = $this->processingConsumer($f);
        $first = $this->processingBody($f, $c);
        $this->prepareProcessing($c, $first)->assertOk();
        $this->decorateMethod(fn () => throw new \RuntimeException('PRIVATE_PAYLOAD_MUST_NOT_LEAK'));
        $failed = $this->completeProcessing($c, $first['command_id'])->assertOk()->assertJsonPath('data.state', 'failed')->assertJsonPath('data.finding', null);
        $this->assertStringNotContainsString('PRIVATE_PAYLOAD', $failed->getContent());
        $second = $this->processingBody($f, $c);
        $this->prepareProcessing($c, $second)->assertOk();
        $this->app->instance(MarketMethodSelector::class, new MarketMethodSelector([]));
        $this->completeProcessing($c, $second['command_id'])->assertOk()->assertJsonPath('data.state', 'unavailable')->assertJsonPath('data.usage.executions', 0);
        $third = $this->processingBody($f, $c);
        $this->prepareProcessing($c, $third)->assertOk();
        $cancel = ['command_id' => (string) Str::uuid(), 'effect' => 'cancel', 'authority_selection' => $c['selection'], 'context_selection' => null];
        $this->completeProcessing($c, $third['command_id'], $cancel)->assertOk()->assertJsonPath('data.state', 'cancelled')->assertJsonPath('data.finding', null);
    }

    public function test_business_feasibility_requires_its_own_context_right_and_rejects_changed_context(): void
    {
        $f = $this->processingOriginals();
        $f['records'] = [$f['records'][0]];
        $c = $this->processingConsumer($f, ['geography']);
        $marketSelection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
            ['requirement_kind' => 'business', 'leaf_grant_id' => $c['business']['grant_refs']['business.markets.declare']],
        ]];
        $this->putJson('/api/businesses/'.$c['business']['business_public_id'].'/markets', ['command_id' => (string) Str::uuid(), 'codes' => ['SA'], 'expected_context' => 0, 'authority_selection' => $marketSelection])->assertOk();
        $body = $this->processingBody($f, $c, 'assess_business_feasibility');
        $body['context_selection'] = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
            ['requirement_kind' => 'business', 'leaf_grant_id' => $c['business']['grant_refs']['business.context.read']],
        ]];
        $this->prepareProcessing($c, $body)->assertOk();
        $complete = ['command_id' => (string) Str::uuid(), 'effect' => 'complete', 'authority_selection' => $c['selection'], 'context_selection' => $body['context_selection']];
        $this->completeProcessing($c, $body['command_id'], $complete)->assertOk()->assertJsonPath('data.finding.status', 'within_declared_market')
            ->assertJsonPath('data.finding.limitations.1', 'CAPACITY_NOT_ASSESSED');
        $this->putJson('/api/businesses/'.$c['business']['business_public_id'].'/markets', ['command_id' => (string) Str::uuid(), 'codes' => ['JO'], 'expected_context' => 1, 'authority_selection' => $marketSelection])->assertOk();
        $this->completeProcessing($c, $body['command_id'], $complete)->assertConflict();
        $body['command_id'] = (string) Str::uuid();
        $this->prepareProcessing($c, $body)->assertOk();
        $complete['command_id'] = (string) Str::uuid();
        $this->completeProcessing($c, $body['command_id'], $complete)->assertOk()->assertJsonPath('data.finding.status', 'outside_declared_market');
        $other = $this->processingConsumer($f, ['geography']);
        $otherMarket = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
            ['requirement_kind' => 'business', 'leaf_grant_id' => $other['business']['grant_refs']['business.markets.declare']],
        ]];
        $this->putJson('/api/businesses/'.$other['business']['business_public_id'].'/markets', ['command_id' => (string) Str::uuid(), 'codes' => ['SA'], 'expected_context' => 0, 'authority_selection' => $otherMarket])->assertOk();
        $otherBody = $this->processingBody($f, $other, 'assess_business_feasibility');
        $otherBody['context_selection'] = $body['context_selection'];
        $this->prepareProcessing($other, $otherBody)->assertForbidden();
        $otherBody['context_selection']['requirements'][0]['leaf_grant_id'] = $other['business']['grant_refs']['business.context.read'];
        $this->prepareProcessing($other, $otherBody)->assertOk();
        $this->completeProcessing($other, $otherBody['command_id'], ['command_id' => (string) Str::uuid(), 'effect' => 'complete', 'authority_selection' => $other['selection'], 'context_selection' => $otherBody['context_selection']])
            ->assertOk()->assertJsonPath('data.finding.status', 'within_declared_market');
        $stored = json_decode(DB::connection('pgsql_migrations')->table('market_processing_runs')->where('run_id', $body['command_id'])->value('finding'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('outside_declared_market', $stored['status']);

    }

    public function test_evidence_correction_after_capture_denies_old_input_without_exposing_findings(): void
    {
        $f = $this->processingOriginals();
        $c = $this->processingConsumer($f);
        $body = $this->processingBody($f, $c);
        $this->prepareProcessing($c, $body)->assertOk();
        $right = $this->marketRight($f['writer'], $f['source'], $f['records'][0]['evidence'], 'correct', array_keys($f['records'][0]['payload']['fields']));
        $payload = $f['records'][0]['payload'];
        $payload['fields']['product']['model'] = 'corrected-model';
        // Writer needs its own legitimate session; create the correction before testing completion of the old consumer capture.
        $this->actingAs($f['writer']);
        $this->writeEvidence($f['records'][0]['evidence'], $right, $payload, 1)->assertOk();
        $this->actingAs($c['user']);
        $this->completeProcessing($c, $body['command_id'])->assertForbidden();
        $this->assertSame('prepared', DB::connection('pgsql_migrations')->table('market_processing_runs')->value('state'));
    }

    public function test_session_expiry_during_pure_computation_prevents_terminal_effect(): void
    {
        $f = $this->processingOriginals();
        $c = $this->processingConsumer($f);
        $body = $this->processingBody($f, $c);
        $this->prepareProcessing($c, $body)->assertOk();
        $this->decorateMethod(function () use ($c): void {
            DB::connection('pgsql_migrations')->table('security_sessions')->where('user_id', $c['user']->id)->update(['idle_expires_at' => now()->subSecond()]);
        });
        $this->completeProcessing($c, $body['command_id'])->assertForbidden();
        $this->assertSame('prepared', DB::connection('pgsql_migrations')->table('market_processing_runs')->value('state'));
    }

    public function test_local_compute_deadline_cannot_be_reported_as_a_validated_finding(): void
    {
        $f = $this->processingOriginals();
        $c = $this->processingConsumer($f);
        $body = $this->processingBody($f, $c);
        $this->prepareProcessing($c, $body)->assertOk();
        $this->decorateMethod(fn () => usleep(2100000));
        $response = $this->completeProcessing($c, $body['command_id'])->assertOk()
            ->assertJsonPath('data.state', 'failed')->assertJsonPath('data.reason_code', 'LOCAL_COMPUTE_BUDGET_EXCEEDED')->assertJsonPath('data.finding', null);
        $this->assertGreaterThan(2000000, $response->json('data.usage.elapsed_microseconds'));
    }

    public function test_v2_extensions_reject_untyped_or_unsupported_semantics_without_modifying_existing_evidence(): void
    {
        $f = $this->processingOriginals();
        $this->actingAs($f['writer']);
        $record = $f['records'][0];
        $right = $this->marketRight($f['writer'], $f['source'], $record['evidence'], 'correct', array_keys($record['payload']['fields']));
        $bad = $record['payload'];
        $bad['fields']['product']['untyped_extra'] = 'SECRET';
        $this->writeEvidence($record['evidence'], $right, $bad, 1)->assertUnprocessable();
        $bad = $record['payload'];
        $bad['evidence_type'] = 'market.offer:999';
        $this->writeEvidence($record['evidence'], $right, $bad, 1)->assertUnprocessable();
        $bad = $record['payload'];
        $bad['fields']['service']['warranty_months'] = 'forever';
        $this->writeEvidence($record['evidence'], $right, $bad, 1)->assertUnprocessable();
        $this->assertSame(2, DB::connection('pgsql_migrations')->table('market_evidence_revisions')->count());
        $this->assertSame(1, (int) DB::connection('pgsql_migrations')->table('market_evidence_heads')->where('evidence_id', $record['evidence'])->value('current_revision'));
    }

    public function test_runtime_roles_cannot_read_mutate_or_truncate_processing_custody(): void
    {
        foreach (['pgsql', 'pgsql_authority'] as $connection) {
            foreach (['SELECT * FROM market_processing_runs', 'DELETE FROM market_processing_runs', 'TRUNCATE market_processing_runs', 'SET ROLE builderad_scope_executor'] as $statement) {
                try {
                    DB::connection($connection)->statement($statement);
                    $this->fail('Unqualified direct access succeeded');
                } catch (QueryException $error) {
                    $this->assertSame('42501', $error->errorInfo[0]);
                }
            }
        }
    }
}
