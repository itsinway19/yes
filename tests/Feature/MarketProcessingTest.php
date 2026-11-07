<?php

namespace Tests\Feature;

use App\MarketKnowledge\Processing\MarketMethod;
use App\MarketKnowledge\Processing\MarketMethodSelector;
use App\MarketKnowledge\Processing\MarketProcessingTask;
use App\MarketKnowledge\Processing\OfferEquivalence;
use App\MarketKnowledge\Processing\QualifiedInterestChange;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\BusinessAuthorityFixture as Fixture;
use Tests\Support\MarketProcessingFixture;
use Tests\Support\OfferMethodRevisionFixture;
use Tests\TestCase;

final class MarketProcessingTest extends TestCase
{
    use ApplicationContractAssertions;
    use MarketProcessingFixture;

    public function test_captured_method_revision_survives_coexisting_newer_code_and_replays_without_reexecution(): void
    {
        $f = $this->processingOriginals();
        $c = $this->processingConsumer($f);
        $body = $this->processingBody($f, $c);
        $this->prepareProcessing($c, $body)->assertOk()->assertJsonPath('data.method_ref', 'offer_equivalence:1');
        $future = new OfferMethodRevisionFixture('2');
        $captured = new OfferMethodRevisionFixture('1');
        $this->app->instance(MarketMethodSelector::class, new MarketMethodSelector([$future, $captured]));
        $complete = ['command_id' => (string) Str::uuid(), 'effect' => 'complete', 'authority_selection' => $c['selection'], 'context_selection' => null];
        $response = $this->completeProcessing($c, $body['command_id'], $complete)->assertOk()
            ->assertJsonPath('data.state', 'validated')->assertJsonPath('data.finding.method_ref', 'offer_equivalence:1')
            ->assertJsonPath('data.finding.status', 'equivalent')->assertJsonPath('data.usage.executions', 1);
        $this->assertSame(1, $captured->executions);
        $this->assertSame(0, $future->executions);
        $this->assertSame(0, $future->probes);
        $this->app->instance(MarketMethodSelector::class, new MarketMethodSelector([$future]));
        DB::disconnect();
        $this->assertSame($response->json(), $this->completeProcessing($c, $body['command_id'], $complete)->assertOk()->json());
        $this->assertSame(1, $captured->executions);
        $this->assertSame(0, $future->probes);
        $owner = DB::connection('pgsql_migrations');
        $this->assertSame('offer_equivalence:1', $owner->table('market_processing_runs')->where('run_id', $body['command_id'])->value('method_ref'));
        $this->assertSame(1, (int) $owner->table('market_processing_runs')->where('run_id', $body['command_id'])->value('execution_count'));
    }

    public function test_missing_captured_method_revision_is_unavailable_without_executing_another_version(): void
    {
        $f = $this->processingOriginals();
        $c = $this->processingConsumer($f);
        $body = $this->processingBody($f, $c);
        $this->prepareProcessing($c, $body)->assertOk();
        $future = new OfferMethodRevisionFixture('2');
        $this->app->instance(MarketMethodSelector::class, new MarketMethodSelector([$future]));
        $this->completeProcessing($c, $body['command_id'])->assertOk()->assertJsonPath('data.state', 'unavailable')
            ->assertJsonPath('data.finding', null)->assertJsonPath('data.reason_code', 'METHOD_UNAVAILABLE')->assertJsonPath('data.usage.executions', 0);
        $this->assertSame(0, $future->probes);
        $this->assertSame(0, $future->executions);
    }

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

    public static function smallDeltaCompletionCases(): array
    {
        return [
            'qualified ratio legitimate decline' => [true, false],
            'qualified ratio reversed sign' => [true, true],
            'non-ratio legitimate decline' => [false, false],
            'non-ratio reversed sign' => [false, true],
        ];
    }

    #[DataProvider('smallDeltaCompletionCases')]
    public function test_small_delta_is_independently_validated_before_trusted_completion_and_durable_replay(bool $ratio, bool $alter): void
    {
        $f = $this->processingOriginals('signal');
        foreach ([0.000002, 0.000001] as $index => $value) {
            $record = $f['records'][$index];
            $payload = $record['payload'];
            $payload['fields']['value'] = $value;
            $payload['fields']['comparison_basis']['ratio_scale'] = $ratio;
            $right = $this->marketRight($f['writer'], $record['source'], $record['evidence'], 'correct', array_keys($payload['fields']));
            $f['records'][$index]['ref'] = $this->writeEvidence($record['evidence'], $right, $payload, 1)->assertOk()->json('data.material_ref');
            $f['records'][$index]['payload'] = $payload;
        }
        $c = $this->processingConsumer($f);
        $body = $this->processingBody($f, $c, 'compare_interest');
        $this->prepareProcessing($c, $body)->assertOk()->assertJsonPath('data.state', 'prepared');
        $counter = (object) ['executions' => 0];
        $method = new class($counter, $alter) implements MarketMethod
        {
            public function __construct(private object $counter, private bool $alter) {}

            public function question(): string
            {
                return 'compare_interest';
            }

            public function reference(): string
            {
                return 'qualified_interest_change:1';
            }

            public function available(): bool
            {
                return true;
            }

            public function execute(MarketProcessingTask $task): array
            {
                $this->counter->executions++;
                $finding = (new QualifiedInterestChange)->execute($task);
                if ($this->alter) {
                    $finding['values']->delta = -$finding['values']->delta;
                }

                return $finding;
            }
        };
        $this->app->instance(MarketMethodSelector::class, new MarketMethodSelector([$method]));
        $complete = ['command_id' => (string) Str::uuid(), 'effect' => 'complete', 'authority_selection' => $c['selection'], 'context_selection' => null];
        $state = $alter ? 'failed' : 'validated';
        $response = $this->completeProcessing($c, $body['command_id'], $complete)->assertOk()
            ->assertJsonPath('data.state', $state)->assertJsonPath('data.published', false)
            ->assertJsonPath('data.usage.executions', 1);
        $this->assertApplicationContract('MarketProcessingRunResult', (array) json_decode($response->getContent(), flags: JSON_THROW_ON_ERROR));
        $row = DB::connection('pgsql_migrations')->table('market_processing_runs')->where('run_id', $body['command_id'])->sole();
        $this->assertSame($state, $row->state);
        $this->assertNotNull($row->completion_attestation_digest);
        if ($alter) {
            $response->assertJsonPath('data.finding', null);
            $this->assertNull($row->finding);
            $this->assertSame('METHOD_OR_VALIDATION_FAILED', $row->reason_code);
        } else {
            $response->assertJsonPath('data.finding.values.delta', -0.000001)
                ->assertJsonPath('data.finding.values.percent_change', $ratio ? -50 : null);
        }
        $this->assertStringNotContainsString('DO_NOT_DISCLOSE_SOURCE_TOKEN', $response->getContent());
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('material_lineage_manifests')->count());
        DB::disconnect();
        $this->assertSame($response->json(), $this->completeProcessing($c, $body['command_id'], $complete)->assertOk()->json());
        $this->assertSame($response->json(), $this->completeProcessing($c, $body['command_id'])->assertOk()->json());
        $this->assertSame(1, $counter->executions);
    }

    public function test_rounded_large_drop_completes_trusted_http_processing_and_replays_durably_without_execution(): void
    {
        $f = $this->processingOriginals('signal');
        foreach ([9876543, 1] as $index => $value) {
            $record = $f['records'][$index];
            $payload = $record['payload'];
            $payload['fields']['value'] = $value;
            $right = $this->marketRight($f['writer'], $record['source'], $record['evidence'], 'correct', array_keys($payload['fields']));
            $f['records'][$index]['ref'] = $this->writeEvidence($record['evidence'], $right, $payload, 1)->assertOk()->json('data.material_ref');
            $f['records'][$index]['payload'] = $payload;
        }
        $c = $this->processingConsumer($f);
        $body = $this->processingBody($f, $c, 'compare_interest');
        $this->prepareProcessing($c, $body)->assertOk()->assertJsonPath('data.state', 'prepared');
        $counter = (object) ['executions' => 0];
        $method = new class($counter) implements MarketMethod
        {
            public function __construct(private object $counter) {}

            public function question(): string
            {
                return 'compare_interest';
            }

            public function reference(): string
            {
                return 'qualified_interest_change:1';
            }

            public function available(): bool
            {
                return true;
            }

            public function execute(MarketProcessingTask $task): array
            {
                $this->counter->executions++;

                return (new QualifiedInterestChange)->execute($task);
            }
        };
        $this->app->instance(MarketMethodSelector::class, new MarketMethodSelector([$method]));
        $complete = ['command_id' => (string) Str::uuid(), 'effect' => 'complete', 'authority_selection' => $c['selection'], 'context_selection' => null];
        $response = $this->completeProcessing($c, $body['command_id'], $complete)->assertOk()
            ->assertJsonPath('data.state', 'validated')->assertJsonPath('data.finding.status', 'comparable')
            ->assertJsonPath('data.finding.values.delta', -9876542)->assertJsonPath('data.finding.values.percent_change', -99.99998987)
            ->assertJsonPath('data.usage.executions', 1);
        $this->assertSame(1, $counter->executions);
        $this->assertApplicationContract('MarketProcessingRunResult', (array) json_decode($response->getContent(), flags: JSON_THROW_ON_ERROR));
        $row = DB::connection('pgsql_migrations')->table('market_processing_runs')->where('run_id', $body['command_id'])->sole();
        $this->assertSame('validated', $row->state);
        $this->assertNotNull($row->completion_attestation_digest);
        $this->assertSame(-99.99998987, json_decode($row->finding, true, flags: JSON_THROW_ON_ERROR)['values']['percent_change']);

        // Force a new runtime connection: replay must come from the committed run.
        DB::disconnect();
        $this->assertSame($response->json(), $this->completeProcessing($c, $body['command_id'], $complete)->assertOk()->json());
        $this->assertSame($response->json(), $this->completeProcessing($c, $body['command_id'])->assertOk()->json());
        $this->assertSame(1, $counter->executions);
        $this->assertSame(1, (int) DB::connection('pgsql_migrations')->table('market_processing_runs')->where('run_id', $body['command_id'])->value('execution_count'));
    }

    public function test_distinct_qualified_sources_with_identical_subject_and_measurement_labels_are_not_comparable(): void
    {
        $f = $this->processingOriginals('signal', distinctSources: true);
        $this->assertNotSame($f['records'][0]['source'], $f['records'][1]['source']);
        $c = $this->processingConsumer($f);
        $body = $this->processingBody($f, $c, 'compare_interest');
        $this->prepareProcessing($c, $body)->assertOk();
        $complete = ['command_id' => (string) Str::uuid(), 'effect' => 'complete', 'authority_selection' => $c['selection'], 'context_selection' => null];
        $response = $this->completeProcessing($c, $body['command_id'], $complete)->assertOk()
            ->assertJsonPath('data.state', 'abstained')->assertJsonPath('data.finding.status', 'not_comparable')
            ->assertJsonPath('data.finding.reason_codes.0', 'SOURCE_COMPARABILITY_UNQUALIFIED');
        $this->assertArrayNotHasKey('percent_change', $response->json('data.finding.values'));
        $this->assertStringNotContainsString('DO_NOT_DISCLOSE_SOURCE_TOKEN', $response->getContent());
        $this->assertSame($response->json(), $this->completeProcessing($c, $body['command_id'], $complete)->assertOk()->json());
    }

    public function test_availability_exception_uses_local_fallback_or_records_unavailability_without_leaking_details(): void
    {
        $f = $this->processingOriginals();
        $c = $this->processingConsumer($f);
        $optional = new class implements MarketMethod
        {
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
                throw new \RuntimeException('PRIVATE_OPTIONAL_TOOL_FAILURE');
            }

            public function execute(MarketProcessingTask $task): array
            {
                throw new \LogicException('Unavailable executor ran');
            }
        };
        foreach ([true, false] as $hasFallback) {
            $body = $this->processingBody($f, $c);
            $this->prepareProcessing($c, $body)->assertOk();
            $this->app->instance(MarketMethodSelector::class, new MarketMethodSelector($hasFallback ? [$optional, new OfferEquivalence] : [$optional]));
            $response = $this->completeProcessing($c, $body['command_id'])->assertOk()
                ->assertJsonPath('data.state', $hasFallback ? 'validated' : 'unavailable');
            $this->assertStringNotContainsString('PRIVATE_OPTIONAL_TOOL_FAILURE', $response->getContent());
            if ($hasFallback) {
                $response->assertJsonPath('data.finding.status', 'equivalent');
            } else {
                $response->assertJsonPath('data.finding', null)->assertJsonPath('data.reason_code', 'METHOD_UNAVAILABLE')->assertJsonPath('data.usage.executions', 0);
            }
        }
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
