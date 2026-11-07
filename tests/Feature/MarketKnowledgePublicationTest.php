<?php

namespace Tests\Feature;

use App\MarketKnowledge\Processing\MarketMethod;
use App\MarketKnowledge\Processing\MarketMethodSelector;
use App\MarketKnowledge\Processing\MarketProcessingTask;
use App\MarketKnowledge\Processing\OfferEquivalence;
use App\Security\LifecycleAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\MarketKnowledgeFixture;
use Tests\Support\OfferMethodRevisionFixture;
use Tests\TestCase;

final class MarketKnowledgePublicationTest extends TestCase
{
    use MarketKnowledgeFixture;

    public function test_shared_capture_uses_its_exact_method_revision_among_coexisting_versions(): void
    {
        $originals = $this->knowledgeOriginals();
        $operator = $this->operator(['market.knowledge.capture', 'market.knowledge.publish']);
        $capture = $this->captureBody($operator, $originals);
        $publish = $this->knowledgePublication($operator, $originals, $capture);
        $future = new OfferMethodRevisionFixture('2');
        $captured = new OfferMethodRevisionFixture('1');
        $this->app->instance(MarketMethodSelector::class, new MarketMethodSelector([$future, $captured]));
        $this->actingAsInternal($operator);
        $this->postJson('/internal/market-knowledge/capture', $capture)->assertOk()->assertJsonPath('data.method_ref', 'offer_equivalence:1');
        $response = $this->postJson('/internal/market-knowledge/publish', $publish)->assertOk()->assertJsonPath('data.state', 'published');
        $this->assertSame(1, $captured->executions);
        $this->assertSame(0, $future->executions);
        $this->assertSame(0, $future->probes);
        $this->app->instance(MarketMethodSelector::class, new MarketMethodSelector([$future]));
        DB::disconnect();
        $this->assertSame($response->json(), $this->postJson('/internal/market-knowledge/publish', $publish)->assertOk()->json());
        $this->assertSame(1, $captured->executions);
        $this->assertSame(0, $future->probes);
        $owner = DB::connection('pgsql_migrations');
        $this->assertSame('offer_equivalence:1', $owner->table('market_knowledge_captures')->value('method_ref'));
        $this->assertSame(1, $owner->table('material_lineage_manifests')->count());
    }

    public function test_qualified_http_capture_computation_atomic_publication_and_replay_use_the_canonical_lineage(): void
    {
        $originals = $this->knowledgeOriginals();
        $operator = $this->operator(['market.knowledge.capture', 'market.knowledge.publish']);
        $capture = $this->captureBody($operator, $originals);
        $publish = $this->knowledgePublication($operator, $originals, $capture);
        $method = new class implements MarketMethod
        {
            public int $executions = 0;

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
                $this->executions++;

                return (new OfferEquivalence)->execute($task);
            }
        };
        $this->app->instance(MarketMethodSelector::class, new MarketMethodSelector([$method]));
        $this->actingAsInternal($operator)->postJson('/internal/market-knowledge/capture', $capture)->assertOk();
        $result = $this->postJson('/internal/market-knowledge/publish', $publish)->assertOk()->assertJsonPath('data.state', 'published')->json('data');
        $this->assertSame('market.knowledge', $result['artifact_ref']['namespace']);
        $this->assertSame($result, $this->postJson('/internal/market-knowledge/publish', $publish)->assertOk()->json('data'));
        $this->assertSame(1, $method->executions, 'A durable replay must not execute the method again.');
        $db = DB::connection('pgsql_migrations');
        $this->assertSame(0, $db->table('businesses')->count());
        $this->assertSame('equivalent', json_decode($db->table('market_knowledge_captures')->value('finding'), true)['status']);
        $this->assertSame(1, $db->table('material_lineage_manifests')->count());
        $this->assertSame(2, $db->table('material_use_dependencies')->count());
        $this->assertSame(1, $db->table('market_knowledge_completion_tickets')->whereNotNull('consumed_at')->count());
    }

    public function test_two_businesses_read_one_shared_output_with_independent_rights_without_ancestor_disclosure(): void
    {
        $shared = $this->publishedKnowledge();
        $a = $this->knowledgeConsumer($shared);
        $b = $this->knowledgeConsumer($shared);
        foreach ([$a, $b] as $consumer) {
            $response = $this->readKnowledge($consumer, $shared['ref'])->assertOk()
                ->assertJsonPath('data.materials.0.fields.finding.status', 'equivalent')
                ->assertJsonPath('data.materials.0.fields.finding.validity.status', 'current');
            foreach ($shared['originals']['records'] as $record) {
                $this->assertStringNotContainsString($record['evidence'], $response->getContent());
            }
            $this->assertStringNotContainsString($shared['operator']->public_id, $response->getContent());
        }
        $this->readKnowledge($a, $shared['ref'], 'competitive_research')->assertForbidden();
        $this->withdrawKnowledgeRoot($a['right']['grant_id']);
        $this->readKnowledge($a, $shared['ref'])->assertForbidden();
        $this->readKnowledge($b, $shared['ref'])->assertOk();
        $db = DB::connection('pgsql_migrations');
        $process = $shared['capture']['authority_selection']['requirements'][0]['leaf_grant_id'];
        $this->withdrawKnowledgeRoot($process);
        $this->readKnowledge($b, $shared['ref'])->assertOk();
        $retain = $shared['publish']['publication_selection']['requirements'][1]['leaf_grant_id'];
        $command = (string) Str::uuid();
        $withdrawn = $this->withdrawKnowledgeRoot($retain, $command);
        $this->assertSame($withdrawn, $this->withdrawKnowledgeRoot($retain, $command));
        $this->readKnowledge($b, $shared['ref'])->assertForbidden();
        $this->assertNull($db->table('market_knowledge_captures')->where('run_id', $shared['capture']['command_id'])->value('finding'));
        $this->assertSame('retention_removed', $db->table('market_knowledge_captures')->where('run_id', $shared['capture']['command_id'])->value('state'));
        $this->assertSame(1, $db->table('material_lineage_manifests')->where('material_namespace', 'market.knowledge')->count(), 'Audit hash and lineage survive payload erasure.');
    }

    public function test_new_purpose_requires_new_qualified_capture_and_reanalysis_before_consumption(): void
    {
        $old = $this->publishedKnowledge();
        $consumer = $this->knowledgeConsumer($old);
        $this->readKnowledge($consumer, $old['ref'], 'competitive_research')->assertForbidden();
        $capture = $this->captureBody($old['operator'], $old['originals'], 'competitive_research');
        $publish = $this->knowledgePublication($old['operator'], $old['originals'], $capture);
        $this->actingAsInternal($old['operator'])->postJson('/internal/market-knowledge/capture', $capture)->assertOk();
        $ref = $this->postJson('/internal/market-knowledge/publish', $publish)->assertOk()->json('data.artifact_ref');
        $this->assertNotSame($old['ref'], $ref, 'A new purpose produces a separately qualified artifact, not a relabelled historical result.');
        $new = ['originals' => $old['originals'], 'operator' => $old['operator'], 'capture' => $capture, 'publish' => $publish, 'ref' => $ref];
        $qualified = $this->knowledgeConsumer($new, 'competitive_research');
        $this->readKnowledge($qualified, $ref, 'competitive_research')->assertOk()->assertJsonPath('data.materials.0.fields.finding.status', 'equivalent');
        $this->readKnowledge($qualified, $ref, 'market_research')->assertForbidden();
    }

    public function test_price_correction_changes_only_the_dependent_result_and_preserves_original_versions(): void
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
            $shared = compact('originals', 'operator', 'capture', 'publish', 'ref');
            $consumer = $this->knowledgeConsumer($shared);
            $this->readKnowledge($consumer, $ref)->assertOk()->assertJsonPath('data.materials.0.fields.finding.validity.status', 'current');
            $outputs[$question] = compact('consumer', 'ref');
        }
        $record = $originals['records'][0];
        $payload = $record['payload'];
        $payload['fields']['price'] = 543.25;
        $this->actingAs($originals['writer']);
        $right = $this->marketRight($originals['writer'], $record['source'], $record['evidence'], 'correct', array_keys($payload['fields']));
        $corrected = $this->writeEvidence($record['evidence'], $right, $payload, 1)->assertOk()->json('data.material_ref');
        $this->assertNotSame($record['ref'], $corrected);
        $this->readKnowledge($outputs['record_price']['consumer'], $outputs['record_price']['ref'])->assertOk()
            ->assertJsonPath('data.materials.0.fields.finding.validity.status', 'review_required')
            ->assertJsonPath('data.materials.0.fields.finding.values.price', $record['payload']['fields']['price']);
        $this->readKnowledge($outputs['record_geography']['consumer'], $outputs['record_geography']['ref'])->assertOk()
            ->assertJsonPath('data.materials.0.fields.finding.validity.status', 'current');
    }

    public function test_timed_retention_is_rejected_before_a_result_or_ticket_is_written(): void
    {
        $originals = $this->knowledgeOriginals();
        $operator = $this->operator(['market.knowledge.capture', 'market.knowledge.publish']);
        $capture = $this->captureBody($operator, $originals);
        $publish = $this->knowledgePublication($operator, $originals, $capture);
        $record = $originals['records'][0];
        $timed = $this->knowledgeRight($operator, $record['source'], $record['evidence'], 'market.evidence', 'retain', array_keys($record['payload']['fields']), 'market_research', '2090-01-01T00:00:00Z');
        $publish['publication_selection']['requirements'][1]['leaf_grant_id'] = $timed['grant_id'];
        $this->actingAsInternal($operator)->postJson('/internal/market-knowledge/capture', $capture)->assertOk();
        $this->postJson('/internal/market-knowledge/publish', $publish)->assertUnprocessable();
        $db = DB::connection('pgsql_migrations');
        $this->assertNull($db->table('market_knowledge_captures')->value('finding'));
        $this->assertSame('prepared', $db->table('market_knowledge_captures')->value('state'));
        $this->assertSame(0, $db->table('market_knowledge_completion_tickets')->count());
        $this->assertSame(0, $db->table('material_lineage_manifests')->count());
    }

    public function test_publication_replay_rejects_a_changed_authority_payload_without_a_new_effect(): void
    {
        $shared = $this->publishedKnowledge();
        $body = $shared['publish'];
        $body['publication_selection']['requirements'] = array_reverse($body['publication_selection']['requirements']);
        $this->postJson('/internal/market-knowledge/publish', $body)->assertConflict();
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('material_lineage_manifests')->count());
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('market_knowledge_completion_tickets')->whereNotNull('consumed_at')->count());
    }

    public function test_platform_knowledge_licence_does_not_depend_on_the_processing_employees_account_staying_active(): void
    {
        $shared = $this->publishedKnowledge();
        $consumer = $this->knowledgeConsumer($shared);
        $this->readKnowledge($consumer, $shared['ref'])->assertOk();
        Notification::fake();
        app(LifecycleAuthority::class)->disable($shared['operator'], $shared['operator']->id, 'SYNTHETIC employee lifecycle ends; source custody licence remains');
        $this->readKnowledge($consumer, $shared['ref'])->assertOk()->assertJsonPath('data.materials.0.fields.finding.status', 'equivalent');
        $this->assertNotNull(DB::connection('pgsql_migrations')->table('market_knowledge_captures')->value('finding'));
        $retain = $shared['publish']['publication_selection']['requirements'][1]['leaf_grant_id'];
        $this->withdrawKnowledgeRoot($retain);
        $this->readKnowledge($consumer, $shared['ref'])->assertForbidden();
        $this->assertNull(DB::connection('pgsql_migrations')->table('market_knowledge_captures')->value('finding'));
    }

    public function test_retiring_the_retention_resource_contract_removes_its_output_payload(): void
    {
        $shared = $this->publishedKnowledge();
        $consumer = $this->knowledgeConsumer($shared);
        $retain = $shared['publish']['publication_selection']['requirements'][1]['leaf_grant_id'];
        $owner = DB::connection('pgsql_migrations');
        $scope = $owner->table('security_action_grants')->where('id', $retain)->value('data_use_scope_id');
        // Guarded fixture changes an existing G4 scope lifecycle, not an unimplemented runtime retirement API.
        $owner->transaction(function () use ($owner, $scope, $shared): void {
            $owner->statement('SET LOCAL ROLE builderad_scope_executor');
            $owner->selectOne("SELECT builderad_private.start_command(?,'synthetic-session',?::uuid,'test.synthetic.retention_scope_retire','{}'::jsonb,'{\"fixture\":true}'::jsonb)", [$shared['operator']->id, (string) Str::uuid()]);
            $before = $owner->scalar('SELECT to_jsonb(s)::text FROM data_use_scopes s WHERE scope_id=?::uuid', [$scope]);
            $after = json_decode($before, true, flags: JSON_THROW_ON_ERROR);
            $after['status'] = 'retired';
            $after['revision']++;
            $owner->selectOne("SELECT builderad_private.permit('public.data_use_scopes','update',?::jsonb,?::jsonb)", [$before, json_encode($after, JSON_THROW_ON_ERROR)]);
            $owner->update("UPDATE data_use_scopes SET status='retired',revision=revision+1 WHERE scope_id=?::uuid", [$scope]);
            $owner->selectOne('SELECT builderad_private.finish_command()');
        });
        $this->readKnowledge($consumer, $shared['ref'])->assertForbidden();
        $this->assertNull($owner->table('market_knowledge_captures')->value('finding'));
    }

    public function test_canonical_source_registry_revocation_is_not_overridden_by_old_market_custody(): void
    {
        $shared = $this->publishedKnowledge();
        $consumer = $this->knowledgeConsumer($shared);
        $owner = DB::connection('pgsql_migrations');
        $owner->transaction(function () use ($owner, $shared): void {
            $owner->statement('SET LOCAL ROLE builderad_scope_executor');
            $owner->selectOne("SELECT builderad_private.start_command(?,'synthetic-session',?::uuid,'test.synthetic.source_registry_revoke','{}'::jsonb,'{\"fixture\":true}'::jsonb)", [$shared['operator']->id, (string) Str::uuid()]);
            $before = $owner->scalar('SELECT to_jsonb(s)::text FROM source_registry s WHERE id=?::uuid', [$shared['originals']['source']]);
            $after = json_decode($before, true, flags: JSON_THROW_ON_ERROR);
            $after['status'] = 'REVOKED';
            $after['version']++;
            $owner->selectOne("SELECT builderad_private.permit('public.source_registry','update',?::jsonb,?::jsonb)", [$before, json_encode($after, JSON_THROW_ON_ERROR)]);
            $owner->update("UPDATE source_registry SET status='REVOKED',version=version+1 WHERE id=?::uuid", [$shared['originals']['source']]);
            $owner->selectOne('SELECT builderad_private.finish_command()');
        });
        $this->assertSame('active', $owner->table('market_source_custody')->where('source_id', $shared['originals']['source'])->value('status'));
        $newCapture = $shared['capture'];
        $newCapture['command_id'] = (string) Str::uuid();
        $this->actingAsInternal($shared['operator'])->postJson('/internal/market-knowledge/capture', $newCapture)->assertForbidden();
        $observer = $this->operator(['market.knowledge.observe']);
        $this->actingAsInternal($observer)->getJson('/internal/administration/market-knowledge')->assertOk()->assertJsonPath('data.sources.0.state', 'revoked');
        $this->readKnowledge($consumer, $shared['ref'])->assertForbidden();
        $this->assertNull($owner->table('market_knowledge_captures')->value('finding'));
    }
}
