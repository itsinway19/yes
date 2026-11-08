<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MarketKnowledgeFixture;
use Tests\TestCase;

final class CustomerDiscoveryPracticeTest extends TestCase
{
    use MarketKnowledgeFixture;

    private function businessSelection(int $grant): array
    {
        return ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $grant]]];
    }

    private function scenario(): array
    {
        $originals = $this->knowledgeOriginals('signal', ['practice_basis'], ['key' => 'synthetic:new_ac_purchase_interest', 'label' => 'اختبار اصطناعي: اهتمام بشراء مكيف جديد؛ ليس طلبًا مثبتًا', 'identity_state' => 'source_asserted']);
        $operator = $this->operator(['market.knowledge.capture', 'market.knowledge.publish']);
        $capture = $this->captureBody($operator, $originals);
        $capture['question'] = 'compare_interest';
        $publish = $this->knowledgePublication($operator, $originals, $capture);
        $this->actingAsInternal($operator)->postJson('/internal/market-knowledge/capture', $capture)->assertOk();
        $ref = $this->postJson('/internal/market-knowledge/publish', $publish)->assertOk()->json('data.artifact_ref');
        $consumer = $this->knowledgeConsumer(compact('originals', 'operator', 'capture', 'publish', 'ref'));
        $this->actingAs($consumer['user']);
        $right = $this->knowledgeRight($consumer['user'], $originals['source'], $ref['key'], 'market.knowledge', 'display', ['finding', 'practice_basis']);
        $base = '/api/businesses/'.$consumer['business']['business_public_id'];
        $this->postJson($base.'/data-use-bindings', ['command_id' => (string) Str::uuid(), 'data_use_scope_id' => $right['scope_id'], 'effect' => 'add', 'expected_relation' => 0,
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => $consumer['business']['grant_refs']['business.link.manage']], ['requirement_kind' => 'data_use', 'leaf_grant_id' => $right['grant_id']],
            ]],
        ])->assertOk();
        $read = $this->businessSelection($consumer['business']['grant_refs']['business.context.read']);
        $write = $this->businessSelection($consumer['business']['grant_refs']['business.context.manage']);
        $value = ['name' => 'بيع أجهزة تكييف', 'description' => 'أجهزة جديدة', 'kind' => 'sale', 'sectors' => ['تجارة'], 'specializations' => ['تكييف'], 'offering' => 'أجهزة جديدة', 'commercial_model' => null, 'channels' => [],
            'target_markets' => ['status' => 'declared', 'areas' => [['authority' => 'iso3166-1', 'level' => 'country', 'ref' => 'SA']]], 'fulfillment' => ['status' => 'unspecified', 'areas' => []]];
        $practice = ['command_id' => (string) Str::uuid(), 'practice_id' => (string) Str::uuid7(), 'operation' => 'create', 'expected_revision' => 0, 'value' => $value, 'authority_selection' => $write, 'read_selection' => $read];
        $this->postJson($base.'/practices', $practice)->assertOk();
        $body = ['command_id' => (string) Str::uuid(), 'practice_id' => $practice['practice_id'], 'expected_revision' => 1, 'material_ref' => $ref, 'purpose' => 'market_research',
            'relation' => ['basis' => 'business_declared', 'applicability' => 'related', 'rationale' => 'تصريحي: الموضوع متعلق بشراء الأجهزة الجديدة'],
            'question' => ['geography' => null, 'period' => null, 'offering' => null], 'read_selection' => $read,
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => $consumer['read']->id], ['requirement_kind' => 'data_use', 'leaf_grant_id' => $right['grant_id']],
            ]]];

        return compact('base', 'body', 'practice', 'consumer', 'originals', 'right');
    }

    public function test_positive_qualified_interest_remains_conditioned_on_declared_relation_in_api_and_storage(): void
    {
        $s = $this->scenario();
        $this->postJson($s['base'].'/knowledge/customer-discovery/practice', [...$s['body'], 'command_id' => $s['practice']['command_id']])->assertConflict();
        $this->postJson($s['base'].'/knowledge/customer-discovery/practice', $s['body'])->assertOk()
            ->assertJsonPath('data.assessment', 'applicable')->assertJsonPath('data.relation_basis', 'business_declared')
            ->assertJsonPath('data.relation_independently_verified', false)->assertJsonPath('data.fulfillment', 'unknown')->assertJsonPath('data.target_market', 'within_declared');
        $stored = json_decode(DB::connection('pgsql_migrations')->table('business_practice_assessments')->where('assessment_id', $s['body']['command_id'])->value('result'), true);
        $this->assertFalse($stored['relation_independently_verified']);
        $this->assertSame('business_declared', $stored['relation']['basis']);
        $this->assertContains('INTEREST_IS_NOT_BUYERS', $stored['limitations']);
        $this->assertArrayNotHasKey('subject_basis', $stored['relation']);
        $this->assertSame(64, strlen($stored['relation']['subject_binding']));
        $this->assertStringNotContainsString('synthetic:new_ac_purchase_interest', json_encode($stored));
        $maintenance = [...$s['practice'], 'command_id' => (string) Str::uuid(), 'practice_id' => (string) Str::uuid7(), 'value' => [...$s['practice']['value'], 'name' => 'صيانة المكيفات', 'kind' => 'maintenance']];
        $this->postJson($s['base'].'/practices', $maintenance)->assertOk();
        $this->postJson($s['base'].'/knowledge/customer-discovery/practice', [...$s['body'], 'command_id' => (string) Str::uuid(), 'practice_id' => $maintenance['practice_id'],
            'relation' => ['basis' => 'unknown', 'applicability' => 'unknown', 'rationale' => '']])->assertOk()->assertJsonPath('data.assessment', 'inconclusive');
        $this->postJson($s['base'].'/knowledge/customer-discovery/practice', [...$s['body'], 'command_id' => (string) Str::uuid(), 'relation' => [...$s['body']['relation'], 'basis' => 'qualified_reference']])->assertUnprocessable();
    }

    public function test_relevant_question_geography_and_period_change_eligibility_without_inventing_fulfillment(): void
    {
        $s = $this->scenario();
        $url = $s['base'].'/knowledge/customer-discovery/practice';
        $this->postJson($url, [...$s['body'], 'question' => ['geography' => ['authority' => 'iso3166-1', 'level' => 'country', 'ref' => 'AE'], 'period' => null, 'offering' => null]])->assertOk()->assertJsonPath('data.assessment', 'not_applicable');
        $this->postJson($url, [...$s['body'], 'command_id' => (string) Str::uuid(), 'question' => ['geography' => null, 'period' => ['from' => '2021-01-01T00:00:00Z', 'until' => '2021-02-01T00:00:00Z'], 'offering' => null]])->assertOk()->assertJsonPath('data.assessment', 'not_applicable');
        $this->postJson($url, [...$s['body'], 'command_id' => (string) Str::uuid(), 'question' => ['geography' => null, 'period' => ['from' => '2020-01-01T01:00:00Z', 'until' => '2020-01-01T02:00:00Z'], 'offering' => null]])->assertOk()->assertJsonPath('data.assessment', 'inconclusive');
        $this->postJson($url, [...$s['body'], 'command_id' => (string) Str::uuid(), 'question' => ['geography' => ['authority' => 'business_declared', 'level' => 'city', 'ref' => 'الرياض'], 'period' => null, 'offering' => null]])->assertOk()->assertJsonPath('data.assessment', 'inconclusive');
        $this->postJson($url, [...$s['body'], 'command_id' => (string) Str::uuid(), 'finding' => ['values' => ['delta' => 100]]])->assertUnprocessable();
    }

    public function test_replay_retains_original_revision_and_relation_but_currentness_tracks_only_its_dependencies(): void
    {
        $s = $this->scenario();
        $url = $s['base'].'/knowledge/customer-discovery/practice';
        $this->postJson($url, $s['body'])->assertOk();
        $other = [...$s['practice'], 'command_id' => (string) Str::uuid(), 'practice_id' => (string) Str::uuid7(), 'value' => [...$s['practice']['value'], 'kind' => 'maintenance']];
        $this->postJson($s['base'].'/practices', $other)->assertOk();
        $this->postJson($url, $s['body'])->assertOk()->assertJsonPath('data.currentness', 'current');
        $this->postJson($s['base'].'/practices', [...$s['practice'], 'command_id' => (string) Str::uuid(), 'operation' => 'update', 'expected_revision' => 1, 'value' => [...$s['practice']['value'], 'name' => 'اسم جديد']])->assertOk();
        $this->postJson($url, $s['body'])->assertOk()->assertJsonPath('data.currentness', 'current')->assertJsonPath('data.practice_revision', 1);
        $this->postJson($s['base'].'/practices', [...$s['practice'], 'command_id' => (string) Str::uuid(), 'operation' => 'reclassify', 'expected_revision' => 2, 'value' => [...$s['practice']['value'], 'name' => 'اسم جديد', 'specializations' => ['معدات المباني']]])->assertOk();
        $this->postJson($url, $s['body'])->assertOk()->assertJsonPath('data.currentness', 'review_required')->assertJsonPath('data.relation_basis', 'business_declared');
        $this->withdrawKnowledgeRoot($s['right']['grant_id']);
        $this->actingAs($s['consumer']['user'])->postJson($url, $s['body'])->assertForbidden();
    }

    public function test_finding_only_licence_does_not_gain_subject_projection_and_no_lexical_relation_is_inferred(): void
    {
        $s = $this->scenario();
        $old = $s['body'];
        $old['authority_selection']['requirements'][1]['leaf_grant_id'] = $s['consumer']['right']['grant_id'];
        $this->postJson($s['base'].'/knowledge/customer-discovery/practice', $old)->assertOk()->assertJsonPath('data.assessment', 'inconclusive')->assertJsonPath('data.relation.subject_basis', null);
        $this->getJson($s['base'].'/practices/knowledge?access='.$s['consumer']['business']['grant_refs']['business.context.read'])->assertOk()->assertJsonPath('data.options.0.practice_basis.schema', 'market.practice_basis:1');
    }

    public function test_name_only_meaning_change_invalidates_original_assessment_without_rewriting_history(): void
    {
        $s = $this->scenario();
        $minimal = [...$s['practice'], 'command_id' => (string) Str::uuid(), 'practice_id' => (string) Str::uuid7(),
            'value' => [...$s['practice']['value'], 'description' => '', 'offering' => null, 'sectors' => [], 'specializations' => []]];
        $this->postJson($s['base'].'/practices', $minimal)->assertOk();
        $body = [...$s['body'], 'command_id' => (string) Str::uuid(), 'practice_id' => $minimal['practice_id']];
        $url = $s['base'].'/knowledge/customer-discovery/practice';
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.currentness', 'current');
        $this->postJson($s['base'].'/practices', [...$minimal, 'command_id' => (string) Str::uuid(), 'operation' => 'update',
            'expected_revision' => 1, 'value' => [...$minimal['value'], 'name' => 'بيع السيارات']])->assertOk();
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.currentness', 'review_required')
            ->assertJsonPath('data.practice_revision', 1)->assertJsonPath('data.historical_receipt', true);
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('business_practice_assessments')->where('assessment_id', $body['command_id'])->count());
    }

    public function test_classifier_does_not_treat_unknown_geographic_or_subject_identity_as_proven_disjointness(): void
    {
        $s = $this->scenario();
        $option = $this->getJson($s['base'].'/practices/knowledge?access='.$s['consumer']['business']['grant_refs']['business.context.read'])->assertOk()->json('data.options.0');
        $this->assertSame('iso3166-1', $option['practice_basis']['signals'][0]['geography']['authority']);
        $basis = $option['practice_basis'];
        foreach ($basis['signals'] as &$signal) {
            $signal['geography'] = ['level' => 'country', 'ref' => 'unqualified source-local label'];
        }
        unset($signal);
        $question = ['geography' => ['authority' => 'iso3166-1', 'level' => 'country', 'ref' => 'SA'], 'period' => null, 'offering' => null];
        $owner = DB::connection('pgsql_migrations');
        $assess = function (array $basis) use ($owner, $option, $s, $question): array {
            return $owner->transaction(function () use ($owner, $basis, $option, $s, $question): array {
                $owner->statement('SET LOCAL ROLE builderad_scope_executor');

                return json_decode($owner->scalar('SELECT builderad_private.assess_practice(?::jsonb,?::jsonb,?::jsonb,?::jsonb,?::jsonb)',
                    [json_encode($option['finding']), json_encode($basis), json_encode(['state' => 'active', 'value' => $s['practice']['value']]), json_encode($s['body']['relation']), json_encode($question)]), true, flags: JSON_THROW_ON_ERROR);
            });
        };
        $this->assertSame('inconclusive', $assess($basis)['assessment']);
        foreach ($basis['signals'] as &$signal) {
            $signal['subject']['identity_state'] = 'unresolved';
            $signal['subject']['key'] = null;
        }
        unset($signal);
        $this->assertSame('inconclusive', $assess($basis)['assessment']);
    }
}
