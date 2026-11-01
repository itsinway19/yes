<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BusinessAuthorityFixture;
use Tests\Support\MarketKnowledgeFixture;
use Tests\TestCase;

final class MarketKnowledgeAuthorityTest extends TestCase
{
    use MarketKnowledgeFixture;

    public function test_operational_observation_never_authorizes_shared_capture_or_publication(): void
    {
        $user = $this->operator(['market.knowledge.observe']);
        $this->actingAsInternal($user);
        $this->getJson('/internal/administration/market-knowledge')->assertOk();
        foreach (['capture', 'publish'] as $action) {
            $this->postJson('/internal/market-knowledge/'.$action, [])->assertForbidden();
        }
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('business_command_receipts')->where('action', 'like', 'market.knowledge.%')->count());
    }

    public function test_explicit_human_capture_requires_independent_source_rights_and_is_idempotent(): void
    {
        $originals = $this->processingOriginals();
        $operator = $this->operator();
        $body = $this->captureBody($operator, $originals);
        $this->actingAsInternal($operator);
        $first = $this->postJson('/internal/market-knowledge/capture', $body)->assertOk()->assertJsonPath('data.state', 'prepared')->json('data');
        $this->assertSame($first, $this->postJson('/internal/market-knowledge/capture', $body)->assertOk()->json('data'));
        $db = DB::connection('pgsql_migrations');
        $this->assertSame(1, $db->table('market_knowledge_captures')->where('run_id', $body['command_id'])->count());
        $this->assertSame(0, $db->table('businesses')->count());
        $capture = $db->table('market_knowledge_captures')->first();
        $this->assertSame($operator->id, $capture->actor_user_id);
        $this->assertNull($capture->finding);
        $this->assertStringNotContainsString('DO_NOT_DISCLOSE_SOURCE_TOKEN', json_encode($first));
        $changed = $body;
        $changed['question'] = 'compare_interest';
        $this->postJson('/internal/market-knowledge/capture', $changed)->assertConflict();
        BusinessAuthorityFixture::revokeQualification($db->table('security_action_grants')->where('id', $body['authority_selection']['requirements'][0]['leaf_grant_id'])->value('qualification_id'), $operator->id);
        $this->postJson('/internal/market-knowledge/capture', $body)->assertForbidden();
    }

    public function test_publish_authority_does_not_imply_capture_and_another_actors_rights_cannot_be_borrowed(): void
    {
        $originals = $this->processingOriginals();
        $operator = $this->operator();
        $body = $this->captureBody($operator, $originals);
        $publisher = $this->operator(['market.knowledge.publish']);
        $this->actingAsInternal($publisher)->postJson('/internal/market-knowledge/capture', $body)->assertForbidden();
        $other = $this->operator();
        $this->actingAsInternal($other)->postJson('/internal/market-knowledge/capture', $body)->assertForbidden();
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('market_knowledge_captures')->count());
    }

    public function test_runtime_cannot_enroll_its_own_source_licence_or_read_raw_captures(): void
    {
        foreach ([
            ['SELECT builderad_v2_market_knowledge_withdraw(?::uuid,?::uuid,1,1,?)', [(string) Str::uuid(), (string) Str::uuid7(), 'SYNTHETIC attempted runtime withdrawal']],
            ['SELECT builderad_v2_market_knowledge_enroll(?::uuid,?::uuid,1,?,?::jsonb)', [(string) Str::uuid(), (string) Str::uuid7(), 'market.evidence', '{}']],
            ['SELECT * FROM market_knowledge_captures', []],
            ['SELECT builderad_v2_market_knowledge_capture(?,?::uuid,?,?::jsonb,?,?::jsonb)', ['forged', (string) Str::uuid(), 'compare_offers', json_encode(array_fill(0, 2, ['namespace' => 'market.evidence', 'key' => (string) Str::uuid7(), 'version' => str_repeat('a', 64)])), 'market_research', json_encode(['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'data_use', 'leaf_grant_id' => 1234567]]])]],
        ] as [$sql, $bindings]) {
            try {
                DB::select($sql, $bindings);
                $this->fail('Unqualified runtime SQL must be rejected.');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->errorInfo[0]);
            }
        }
    }

    public function test_isolated_licence_withdrawal_still_works_after_the_data_use_window_ends(): void
    {
        $originals = $this->knowledgeOriginals();
        $operator = $this->operator();
        $record = $originals['records'][0];
        $until = now()->addSeconds(3)->toIso8601String();
        $right = $this->knowledgeRight($operator, $record['source'], $record['evidence'], 'market.evidence', 'process', array_keys($record['payload']['fields']), 'market_research', $until);
        $deadline = microtime(true) + 6;
        while (! DB::connection('pgsql_migrations')->scalar('SELECT clock_timestamp()>=?::timestamptz', [$until])) {
            if (microtime(true) > $deadline) {
                $this->fail('Declared licence deadline was not observed.');
            }usleep(20000);
        }
        $result = $this->withdrawKnowledgeRoot($right['grant_id']);
        $this->assertSame('revoked', $result['status']);
        $this->assertSame(2, $result['versions']['grant']);
    }

    public function test_attestation_issuer_cannot_administer_source_licences(): void
    {
        try {
            DB::connection('pgsql_authority')->scalar('SELECT builderad_v2_market_knowledge_withdraw(?::uuid,?::uuid,1,1,?)', [(string) Str::uuid(), (string) Str::uuid7(), 'SYNTHETIC unauthorized issuer withdrawal']);
            $this->fail('Attestation authority cannot become source licensing authority.');
        } catch (QueryException $error) {
            $this->assertSame('42501', $error->errorInfo[0]);
        }
    }
}
