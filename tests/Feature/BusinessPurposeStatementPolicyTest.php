<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\BusinessAuthorityFixture;
use Tests\Support\PurposeDeclarationFixture;
use Tests\TestCase;

final class BusinessPurposeStatementPolicyTest extends TestCase
{
    use ApplicationContractAssertions;

    private function selection(int|string $id): array
    {
        return ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => (int) $id]]];
    }

    public function test_read_team_authority_stale_authentication_or_unconfirmed_input_cannot_enroll(): void
    {
        $creator = User::factory()->create();
        $employee = User::factory()->create();
        $this->actingAs($creator);
        $file = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Purpose policy boundary'])->assertCreated()->json('data');
        $base = '/api/businesses/'.$file['business_public_id'];
        $body = ['command_id' => (string) Str::uuid(), 'confirmed' => true, 'previous_grant' => '0', 'authority_selection' => $this->selection($file['grant_refs']['business.context.read'])];
        foreach ([false, 'true', 1] as $confirmation) {
            $this->postJson($base.'/purpose-enrollment', [...$body, 'confirmed' => $confirmation])->assertUnprocessable();
        }
        $this->postJson($base.'/purpose-enrollment', [...$body, 'workspace_owner' => true])->assertUnprocessable();
        $db = DB::connection('pgsql_migrations');
        $businessId = $db->table('businesses')->where('public_id', $file['business_public_id'])->value('id');
        $read = BusinessAuthorityFixture::root($businessId, $employee->id, 'business.context.read');
        $this->actingAs($employee)->getJson($base.'/surface?access='.$read->id)->assertOk()->assertJsonPath('data.purpose_statement.enrollment_available', false);
        $this->postJson($base.'/purpose-enrollment', [...$body, 'authority_selection' => $this->selection($read->id)])->assertForbidden();
        $this->actingAs($creator);
        $otherBasis = BusinessAuthorityFixture::root($businessId, $creator->id, 'business.context.read');
        $this->postJson($base.'/purpose-enrollment', [...$body, 'authority_selection' => $this->selection($otherBasis->id)])->assertForbidden();
        $db->table('security_sessions')->where('user_id', $creator->id)->update(['authenticated_at' => now()->subMinutes(16)]);
        $this->postJson($base.'/purpose-enrollment', $body)->assertForbidden();
        $this->assertSame(0, $db->table('authority_qualification_receipts')->where('policy_id', 'business_purpose_statement_v1')->count());
        $this->assertSame(0, $db->table('object_purposes')->count());
        $this->assertSame(0, $db->table('workspaces')->count());
    }

    public function test_operating_creator_opt_in_correct_withdraw_surrender_and_requalify_never_grant_external_or_staff_authority(): void
    {
        $creator = User::factory()->create();
        $staff = User::factory()->create();
        $this->actingAs($creator);
        $file = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Own statement Business'])->assertCreated()->json('data');
        $base = '/api/businesses/'.$file['business_public_id'];
        $read = $this->selection($file['grant_refs']['business.context.read']);
        $surface = $this->getJson($base.'/surface?access='.$file['grant_refs']['business.context.read'])->assertOk()->assertJsonPath('data.purpose_statement.previous_grant', '0')->assertJsonPath('data.purpose_statement.current_grant', null);
        $this->assertApplicationContract('BusinessSurfaceResult', $surface->json());
        $enroll = ['command_id' => (string) Str::uuid(), 'confirmed' => true, 'previous_grant' => '0', 'authority_selection' => $read];
        $receipt = $this->postJson($base.'/purpose-enrollment', $enroll)->assertOk()->assertJsonPath('data.authority_scope', 'own_business_statement_only')->assertJsonPath('data.establishes_external_authority', false)->json('data');
        $this->postJson($base.'/purpose-enrollment', $enroll)->assertOk()->assertJsonPath('data.grant_refs', $receipt['grant_refs']);
        $grant = $receipt['grant_refs']['business.purpose.declare'];
        $selection = $this->selection($grant);
        $db = DB::connection('pgsql_migrations');
        $root = $db->table('security_action_grants')->where('id', $grant)->sole();
        $this->assertFalse($root->delegable);
        $this->assertNull($root->parent_grant_id);
        $this->assertNull($root->delegation_workspace_id);
        $this->assertSame('direct', $root->delegation_realm_kind);
        $q = $db->table('authority_qualification_receipts')->where('qualification_id', $root->qualification_id)->sole();
        $this->assertSame('{business.purpose.declare}', $q->allowed_actions);
        $this->assertSame((int) $file['grant_refs']['business.context.read'], $q->issuer_grant_id);
        $command = PurposeDeclarationFixture::command();
        $first = $this->postJson($base.'/purposes', ['command' => $command, 'expected_context' => 0, 'authority_selection' => $selection])->assertCreated()->assertJsonPath('data.establishes_external_authority', false)->json('data');
        $original = $db->table('object_purpose_revisions')->where('revision_id', $first['revision_id'])->sole();
        $correct = [...$command, 'command_id' => (string) Str::uuid(), 'purpose_id' => $first['purpose_id'], 'expected_version' => 1, 'operation' => 'correct',
            'supersedes_revision_id' => $first['revision_id'], 'affected_revision_ids' => [$first['revision_id']], 'correction_reason' => 'Supported own-statement correction'];
        $correct['confirmations']['transition'] = true;
        $correct['declaration']['meaning'] = 'Corrected statement of the Business purpose';
        $second = $this->postJson($base.'/purposes', ['command' => $correct, 'expected_context' => 0, 'authority_selection' => $selection])->assertCreated()->json('data');
        $withdraw = [...$correct, 'command_id' => (string) Str::uuid(), 'expected_version' => 2, 'operation' => 'withdraw', 'declaration' => null, 'correction_reason' => null,
            'withdrawal_reason' => 'The Business withdraws its own statement', 'supersedes_revision_id' => $second['revision_id'], 'affected_revision_ids' => [$first['revision_id'], $second['revision_id']]];
        $this->postJson($base.'/purposes', ['command' => $withdraw, 'expected_context' => 0, 'authority_selection' => $selection])->assertCreated();
        $this->assertEquals($original, $db->table('object_purpose_revisions')->where('revision_id', $first['revision_id'])->sole());
        $delegation = ['command_id' => (string) Str::uuid(), 'effect' => 'issue', 'grantee_public_id' => $staff->public_id, 'action' => 'business.purpose.declare',
            'parent_grant_id' => (int) $grant, 'grant_id' => null, 'expected_grant' => 0, 'expires_at' => null, 'delegable' => false, 'realm_workspace_public_id' => null, 'authority_selection' => $selection];
        $this->postJson($base.'/grants', $delegation)->assertForbidden();
        $this->postJson($base.'/grants', [...$delegation, 'delegable' => true])->assertForbidden();
        $this->putJson($base.'/context', ['command_id' => (string) Str::uuid(), 'name' => 'Forbidden other fact', 'business_type_id' => null, 'expected_context' => 0, 'authority_selection' => $selection])->assertForbidden();
        $this->postJson($base.'/read', ['authority_selection' => $selection])->assertForbidden();
        $surrender = [...$delegation, 'command_id' => (string) Str::uuid(), 'effect' => 'revoke', 'grantee_public_id' => $creator->public_id,
            'parent_grant_id' => null, 'grant_id' => (int) $grant, 'expected_grant' => 1];
        $this->postJson($base.'/grants', $surrender)->assertOk();
        $revoked = $db->table('security_action_grants')->where('id', $grant)->sole();
        $this->postJson($base.'/purposes', ['command' => $command, 'expected_context' => 0, 'authority_selection' => $selection])->assertForbidden();
        $this->getJson($base.'/purposes?access='.$file['grant_refs']['business.context.read'])->assertOk()->assertJsonCount(3, 'data.projection.items.0.history');
        $requalified = $this->postJson($base.'/purpose-enrollment', [...$enroll, 'command_id' => (string) Str::uuid(), 'previous_grant' => (string) $grant])->assertOk()->json('data.grant_refs');
        $newGrant = $requalified['business.purpose.declare'];
        $this->assertNotSame((string) $grant, (string) $newGrant);
        $this->assertEquals($revoked, $db->table('security_action_grants')->where('id', $grant)->sole());
        $this->postJson($base.'/purposes', ['command' => $command, 'expected_context' => 0, 'authority_selection' => $this->selection($newGrant)])->assertCreated()->assertJsonPath('data.version', 1);
        $this->assertSame(3, $db->table('object_purposes')->value('head_version'));
        // A new qualification admits a new statement through the same engine;
        // the preceding old-command replay did not revive its revoked grant.
        $freshStatement = PurposeDeclarationFixture::command();
        $this->postJson($base.'/purposes', ['command' => $freshStatement, 'expected_context' => 0, 'authority_selection' => $this->selection($newGrant)])->assertCreated();
        $this->assertSame(2, $db->table('object_purposes')->count());
        $this->assertEquals($revoked, $db->table('security_action_grants')->where('id', $grant)->sole());
        foreach (['workspaces', 'source_registry', 'data_use_scopes', 'business_data_use_bindings', 'client_data_boundaries'] as $table) {
            $this->assertSame(0, $db->table($table)->count(), $table);
        }
        $this->assertSame(0, $db->table('security_action_grants')->where('subject_user_id', $staff->id)->where('scope_kind', 'business')->count());
        $this->assertSame(0, $db->table('business_context_transitions')->count());
        BusinessAuthorityFixture::revokeQualification($root->qualification_id, $creator->id);
        $basis = $db->table('security_action_grants')->where('id', $file['grant_refs']['business.context.read'])->value('qualification_id');
        BusinessAuthorityFixture::revokeQualification($basis, $creator->id);
        $this->postJson($base.'/purposes', ['command' => PurposeDeclarationFixture::command(), 'expected_context' => 0, 'authority_selection' => $this->selection($newGrant)])->assertForbidden();
    }
}
