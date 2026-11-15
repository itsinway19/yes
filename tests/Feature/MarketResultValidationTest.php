<?php

namespace Tests\Feature;

use App\MarketKnowledge\Processing\BusinessFeasibility;
use App\MarketKnowledge\Processing\MarketMethod;
use App\MarketKnowledge\Processing\MarketMethodSelector;
use App\MarketKnowledge\Processing\MarketProcessingTask;
use App\MarketKnowledge\Processing\OfferEquivalence;
use App\MarketKnowledge\Processing\QualifiedInterestChange;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MarketKnowledgeFixture;
use Tests\TestCase;

final class MarketResultValidationTest extends TestCase
{
    use MarketKnowledgeFixture;

    private function alteredMethod(MarketMethod $original, Closure $alter): void
    {
        $method = new class($original, $alter) implements MarketMethod
        {
            public function __construct(private MarketMethod $original, private Closure $alter) {}

            public function question(): string
            {
                return $this->original->question();
            }

            public function reference(): string
            {
                return $this->original->reference();
            }

            public function available(): bool
            {
                return true;
            }

            public function execute(MarketProcessingTask $task): array
            {
                return ($this->alter)($this->original->execute($task));
            }
        };
        $this->app->instance(MarketMethodSelector::class, new MarketMethodSelector([$method]));
    }

    public static function falseStates(): array
    {
        return ['offer' => ['offer'], 'interest' => ['interest'], 'business' => ['business']];
    }

    #[DataProvider('falseStates')]
    public function test_false_semantic_result_becomes_failed_through_real_coordinator_and_sql(string $kind): void
    {
        $original = match ($kind) {
            'offer' => new OfferEquivalence,
            'interest' => new QualifiedInterestChange,
            'business' => new BusinessFeasibility,
        };
        $f = $this->processingOriginals($kind === 'interest' ? 'signal' : 'offer');
        if ($kind === 'business') {
            $f['records'] = [$f['records'][0]];
        }
        $c = $this->processingConsumer($f);
        $body = $this->processingBody($f, $c, $original->question());
        if ($kind === 'business') {
            $body['context_selection'] = ['realm_kind' => 'direct', 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => $c['business']['grant_refs']['business.context.read']],
            ]];
        }
        $this->prepareProcessing($c, $body)->assertOk();
        $this->alteredMethod($original, function (array $finding) use ($kind): array {
            if ($kind === 'offer') {
                $finding['status'] = 'different';
                $finding['reason_codes'] = ['PRODUCT_MODEL_DIFFERS'];
            } elseif ($kind === 'interest') {
                $finding['values']->unexpected_private_field = 123;
            } else {
                $finding['status'] = 'within_declared_market';
                $finding['reason_codes'] = ['WITHIN_DECLARED_MARKET_ONLY'];
            }

            return $finding;
        });
        $complete = ['command_id' => (string) Str::uuid(), 'effect' => 'complete',
            'authority_selection' => $c['selection'], 'context_selection' => $body['context_selection']];
        $response = $this->completeProcessing($c, $body['command_id'], $complete)->assertOk()
            ->assertJsonPath('data.state', 'failed')->assertJsonPath('data.finding', null)
            ->assertJsonPath('data.reason_code', 'METHOD_OR_VALIDATION_FAILED');
        $row = DB::connection('pgsql_migrations')->table('market_processing_runs')->where('run_id', $body['command_id'])->sole();
        $this->assertSame('failed', $row->state);
        $this->assertNull($row->finding);
        $this->assertNotNull($row->completion_attestation_digest);
        $this->assertSame($response->json(), $this->completeProcessing($c, $body['command_id'], $complete)->assertOk()->json());
    }

    public function test_false_offer_cannot_be_published_as_shared_qualified_knowledge(): void
    {
        $f = $this->knowledgeOriginals();
        $operator = $this->operator(['market.knowledge.capture', 'market.knowledge.publish']);
        $capture = $this->captureBody($operator, $f);
        $publish = $this->knowledgePublication($operator, $f, $capture);
        $this->actingAsInternal($operator)->postJson('/internal/market-knowledge/capture', $capture)->assertOk();
        $this->alteredMethod(new OfferEquivalence, function (array $finding): array {
            $finding['status'] = 'different';
            $finding['reason_codes'] = ['PRODUCT_MODEL_DIFFERS'];

            return $finding;
        });
        $this->postJson('/internal/market-knowledge/publish', $publish)->assertUnprocessable();
        $this->assertSame('prepared', DB::connection('pgsql_migrations')->table('market_knowledge_captures')->where('run_id', $capture['command_id'])->value('state'));
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('market_knowledge_completion_tickets')->count());
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('material_lineage_manifests')->count());
    }
}
