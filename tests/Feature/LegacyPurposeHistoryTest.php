<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LegacyCommercialUpgradeFixture;
use Tests\Support\PurposeDeclarationFixture;
use Tests\TestCase;

final class LegacyPurposeHistoryTest extends TestCase
{
    public function test_original_history_allows_only_qualified_correction_and_withdrawal_and_preserves_the_original(): void
    {
        LegacyCommercialUpgradeFixture::install();
        $actor = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $this->actingAs($actor)->post('/workspaces', ['name' => 'Original history owner', 'slug' => 'original-history'])->assertRedirect();
        $db = DB::connection('pgsql_migrations');
        $workspace = $db->table('workspaces')->where('slug', 'original-history')->sole();
        $request = Request::create('/organizations');
        $request->setUserResolver(fn () => $actor);
        $request->setLaravelSession(app('session')->driver());
        $command = PurposeDeclarationFixture::command();
        $command['declaration']['included_objects'] = [['provider' => 'meta', 'account_namespace' => 'meta_ads_account', 'account_id' => '123', 'object_kind' => 'campaign', 'object_id' => '456', 'incarnation' => null]];
        $first = json_decode(LegacyCommercialUpgradeFixture::write($actor, $request, $workspace, 'purpose', [json_encode($command)]), true);
        $original = (array) $db->table('object_purpose_revisions')->where('revision_id', $first['revision_id'])->sole();
        $grants = $db->table('security_action_grants')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        LegacyCommercialUpgradeFixture::upgrade();
        $this->assertNull($db->scalar("SELECT to_regprocedure('builderad_object_purpose_write(bigint,text,jsonb)')"));
        $base = '/workspaces/'.$workspace->public_id;
        $this->postJson($base.'/object-purposes', $command)->assertStatus(410);
        $this->postJson($base.'/object-purposes/'.$first['purpose_id'].'/revisions', $command)->assertStatus(410);
        $url = $base.'/purpose-history/'.$first['purpose_id'].'/revisions';
        $command = [...$command, 'command_id' => (string) Str::uuid(), 'purpose_id' => $first['purpose_id'], 'expected_version' => 1,
            'operation' => 'correct', 'supersedes_revision_id' => $first['revision_id'], 'affected_revision_ids' => [$first['revision_id']], 'correction_reason' => 'Supported correction to the original statement'];
        $command['confirmations']['transition'] = true;
        $command['declaration']['meaning'] = 'Corrected original historical statement';
        $second = $this->postJson($url, $command)->assertCreated()->json();
        $this->postJson($url, $command)->assertCreated()->assertExactJson($second);
        foreach (['declare', 'replace'] as $operation) {
            $this->postJson($url, [...$command, 'command_id' => (string) Str::uuid(), 'operation' => $operation])->assertUnprocessable();
        }
        $this->getJson($base.'/purpose-history')->assertOk()->assertJsonCount(2, 'items.0.history');
        $withdraw = [...$command, 'command_id' => (string) Str::uuid(), 'expected_version' => 2, 'operation' => 'withdraw', 'declaration' => null,
            'supersedes_revision_id' => $second['revision_id'], 'affected_revision_ids' => [$first['revision_id'], $second['revision_id']], 'correction_reason' => null, 'withdrawal_reason' => 'Original issuer withdraws the statement'];
        $this->postJson($url, $withdraw)->assertCreated();
        $this->assertSame($original, (array) $db->table('object_purpose_revisions')->where('revision_id', $first['revision_id'])->sole());
        $this->assertSame($grants, $db->table('security_action_grants')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame(3, $db->table('object_purpose_revisions')->count());
        $this->assertSame(0, $db->table('businesses')->count());
        $grant = $db->table('security_action_grants')->where('workspace_id', $workspace->id)->where('action', 'business.context.manage')->sole();
        DB::connection('pgsql_authority')->statement("UPDATE security_action_grants SET status='revoked',revoked_at=clock_timestamp(),version=version+1,updated_at=clock_timestamp() WHERE id=?", [$grant->id]);
        // Original Purpose qualification is the current responsible owner,
        // not the retired Context grant. Preserve that distinct original right.
        $this->getJson($base.'/purpose-history')->assertOk();
        $view = $db->table('security_action_grants')->where('workspace_id', $workspace->id)->where('action', 'workspace.view')->sole();
        DB::connection('pgsql_authority')->statement("UPDATE security_action_grants SET status='revoked',revoked_at=clock_timestamp(),version=version+1,updated_at=clock_timestamp() WHERE id=?", [$view->id]);
        $this->getJson($base.'/purpose-history')->assertForbidden();
        $this->postJson($url, $withdraw)->assertForbidden();
        $this->assertSame(3, $db->table('object_purpose_revisions')->count());
    }
}
