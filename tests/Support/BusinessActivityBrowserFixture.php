<?php

// Included only by the existing guarded disposable Business browser fixture.
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BusinessAuthorityFixture;
use Tests\Support\MarketKnowledgeFixture;
use Tests\TestCase;

final class BusinessActivityBrowserHarness extends TestCase
{
    use MarketKnowledgeFixture;

    public function buildFixture($application, User $consumer, string $business): array
    {
        $this->app = $application;
        $originals = $this->knowledgeOriginals('signal', ['practice_basis'], ['key' => 'synthetic:new_ac_purchase_interest', 'label' => 'اختبار اصطناعي: اهتمام بشراء مكيف جديد؛ ليس طلبًا مثبتًا', 'identity_state' => 'source_asserted']);
        $operator = $this->operator(['market.knowledge.capture', 'market.knowledge.publish']);
        $capture = $this->captureBody($operator, $originals);
        $capture['question'] = 'compare_interest';
        $publish = $this->knowledgePublication($operator, $originals, $capture);
        $this->actingAsInternal($operator)->postJson('/internal/market-knowledge/capture', $capture)->assertOk();
        $ref = $this->postJson('/internal/market-knowledge/publish', $publish)->assertOk()->json('data.artifact_ref');
        $this->actingAs($consumer);
        $db = DB::connection('pgsql_migrations');
        $id = $db->table('businesses')->where('public_id', $business)->value('id');
        if (! $id || ! $db->table('businesses')->where('id', $id)->where('created_by', $consumer->id)->exists()) {
            throw new RuntimeException('Browser-created own Business required.');
        }
        $data = BusinessAuthorityFixture::root($id, $consumer->id, 'business.data.use');
        $link = $db->table('security_action_grants')->where('business_id', $id)->where('subject_user_id', $consumer->id)->where('action', 'business.link.manage')->where('status', 'active')->sole()->id;
        $right = $this->knowledgeRight($consumer, $originals['source'], $ref['key'], 'market.knowledge', 'display', ['finding', 'practice_basis']);
        $this->postJson('/api/businesses/'.$business.'/data-use-bindings', ['command_id' => (string) Str::uuid(), 'data_use_scope_id' => $right['scope_id'], 'effect' => 'add', 'expected_relation' => 0,
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => $link], ['requirement_kind' => 'data_use', 'leaf_grant_id' => $right['grant_id']],
            ]],
        ])->assertOk();

        return ['material_ref' => $ref, 'business_public_id' => $business, 'data_grant' => $data->id, 'display_grant' => $right['grant_id'], 'scope_id' => $right['scope_id'],
            'qualification' => 'SYNTHETIC interest and explicit licensed projection; synthetic Business data-use root. No market demand, source truth, operating issuer or paid value claim.'];
    }
}

if ($mode === 'activity-knowledge-withdraw') {
    $input = json_decode(file_get_contents($run.'/activity-knowledge-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    DB::connection('pgsql_migrations')->scalar('SELECT builderad_v2_market_knowledge_withdraw(?::uuid,?::uuid,?,1,?)', [(string) Str::uuid(), $input['scope_id'], $input['display_grant'], 'SYNTHETIC browser licence withdrawal']);
} else {
    $input = json_decode(file_get_contents($run.'/activity-knowledge-request.json'), true, flags: JSON_THROW_ON_ERROR);
    $activityFixture = (new BusinessActivityBrowserHarness('buildFixture'))->buildFixture($app, $recipient, $input['business_public_id']);
    file_put_contents($run.'/activity-knowledge-fixture.json', json_encode($activityFixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}
