<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BusinessAuthorityFixture;
use Tests\TestCase;

final class BusinessDelegationTest extends TestCase
{
    public function test_sixteen_grant_path_is_usable_next_level_is_denied_and_root_withdrawal_preserves_independent_same_business_path(): void
    {
        $owner = User::factory()->create();
        $business = $this->actingAs($owner)->postJson('/api/businesses', [
            'command_id' => (string) Str::uuid7(), 'name' => 'Bounded delegation',
        ])->assertCreated()->json('data');
        $db = DB::connection('pgsql_migrations');
        $id = $db->table('businesses')->where('public_id', $business['business_public_id'])->value('id');
        $root = BusinessAuthorityFixture::root($id, $owner->id, 'business.context.read');
        $base = '/api/businesses/'.$business['business_public_id'];
        $actor = $owner;
        $parent = $root->id;
        for ($depth = 2; $depth <= 16; $depth++) {
            $recipient = User::factory()->create();
            $parent = $this->actingAs($actor)->postJson($base.'/grants', [
                'command_id' => (string) Str::uuid7(), 'authority_selection' => $this->selection($parent),
                'effect' => 'issue', 'grantee_public_id' => $recipient->public_id, 'action' => 'business.context.read',
                'parent_grant_id' => $parent, 'grant_id' => null, 'expected_grant' => 0,
                'expires_at' => null, 'delegable' => true,
            ])->assertOk()->json('data.grant_id');
            $actor = $recipient;
        }
        $this->actingAs($actor)->getJson($base.'/surface?access='.$parent)->assertOk();
        $count = $db->table('security_action_grants')->count();
        $this->postJson($base.'/grants', [
            'command_id' => (string) Str::uuid7(), 'authority_selection' => $this->selection($parent),
            'effect' => 'issue', 'grantee_public_id' => User::factory()->create()->public_id,
            'action' => 'business.context.read', 'parent_grant_id' => $parent, 'grant_id' => null,
            'expected_grant' => 0, 'expires_at' => null, 'delegable' => true,
        ])->assertForbidden();
        $this->assertSame($count, $db->table('security_action_grants')->count());
        $independent = BusinessAuthorityFixture::root($id, $actor->id, 'business.context.read');
        BusinessAuthorityFixture::revokeQualification($root->qualification_id, $owner->id);
        $this->getJson($base.'/surface?access='.$parent)->assertForbidden();
        $this->getJson($base.'/surface?access='.$independent->id)->assertOk();
    }

    private function selection(int|string $grant): array
    {
        return ['realm_kind' => 'direct', 'requirements' => [[
            'requirement_kind' => 'business', 'leaf_grant_id' => (int) $grant,
        ]]];
    }

    public function test_draft_read_cannot_issue_a_root_grant_but_qualified_parent_can_revoke_exact_child(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();
        $business = $this->actingAs($owner)->postJson('/api/businesses', [
            'command_id' => (string) Str::uuid7(), 'name' => 'Delegated Business',
        ])->assertCreated()->json('data');
        $base = '/api/businesses/'.$business['business_public_id'];
        $db = DB::connection('pgsql_migrations');
        $businessId = $db->table('businesses')->where('public_id', $business['business_public_id'])->value('id');
        $draftRead = (int) $business['grant_refs']['business.context.read'];
        $command = ['command_id' => (string) Str::uuid7(), 'authority_selection' => $this->selection($draftRead),
            'effect' => 'issue', 'grantee_public_id' => $recipient->public_id, 'action' => 'business.context.read',
            'parent_grant_id' => $draftRead, 'grant_id' => null, 'expected_grant' => 0,
            'expires_at' => null, 'delegable' => false];
        $this->postJson($base.'/grants', $command)->assertForbidden();
        $this->assertSame(7, $db->table('security_action_grants')->where('business_id', $businessId)->count());

        $parent = BusinessAuthorityFixture::root($businessId, $owner->id, 'business.context.read');
        $command['command_id'] = (string) Str::uuid7();
        $command['parent_grant_id'] = $parent->id;
        $command['authority_selection'] = $this->selection($parent->id);
        $issued = $this->postJson($base.'/grants', $command)->assertOk()->json('data');
        $this->postJson($base.'/grants', $command)->assertOk()->assertJsonPath('data.grant_id', $issued['grant_id']);
        $this->actingAs($recipient)->getJson($base.'/surface?access='.$issued['grant_id'])->assertOk();
        $this->actingAs($owner);
        $revoke = [...$command, 'command_id' => (string) Str::uuid7(), 'effect' => 'revoke',
            'parent_grant_id' => null, 'grant_id' => (int) $issued['grant_id'], 'expected_grant' => 1];
        $this->postJson($base.'/grants', $revoke)->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->postJson($base.'/grants', $revoke)->assertOk()->assertJsonPath('data.grant_id', $issued['grant_id']);
        $this->actingAs($recipient)->getJson($base.'/surface?access='.$issued['grant_id'])->assertForbidden();
        $this->assertSame('revoked', $db->table('security_action_grants')->where('id', $issued['grant_id'])->value('status'));
    }

    public function test_independent_business_read_survives_revocation_of_another_business_grant(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();
        $created = [];
        foreach (['First independent client', 'Second independent client'] as $name) {
            $created[] = $this->actingAs($owner)->postJson('/api/businesses', [
                'command_id' => (string) Str::uuid7(), 'name' => $name,
            ])->assertCreated()->json('data');
        }
        $issued = [];
        foreach ($created as $business) {
            $id = DB::connection('pgsql_migrations')->table('businesses')->where('public_id', $business['business_public_id'])->value('id');
            $parent = BusinessAuthorityFixture::root($id, $owner->id, 'business.context.read');
            $body = ['command_id' => (string) Str::uuid7(), 'authority_selection' => $this->selection($parent->id),
                'effect' => 'issue', 'grantee_public_id' => $recipient->public_id, 'action' => 'business.context.read',
                'parent_grant_id' => $parent->id, 'grant_id' => null, 'expected_grant' => 0,
                'expires_at' => null, 'delegable' => false];
            $issued[] = ['parent' => $parent->id, 'body' => $body, 'grant' => $this->postJson('/api/businesses/'.$business['business_public_id'].'/grants', $body)->assertOk()->json('data.grant_id')];
        }
        $first = $created[0]['business_public_id'];
        $second = $created[1]['business_public_id'];
        $this->actingAs($recipient)->getJson('/api/businesses/'.$first.'/surface?access='.$issued[0]['grant'])->assertOk();
        $this->getJson('/api/businesses/'.$second.'/surface?access='.$issued[1]['grant'])->assertOk();
        $this->actingAs($owner);
        $this->postJson('/api/businesses/'.$first.'/grants', [...$issued[0]['body'],
            'command_id' => (string) Str::uuid7(), 'effect' => 'revoke', 'parent_grant_id' => null,
            'grant_id' => $issued[0]['grant'], 'expected_grant' => 1])->assertOk();
        $this->actingAs($recipient)->getJson('/api/businesses/'.$first.'/surface?access='.$issued[0]['grant'])->assertForbidden();
        $this->getJson('/api/businesses/'.$second.'/surface?access='.$issued[1]['grant'])->assertOk();
    }
}
