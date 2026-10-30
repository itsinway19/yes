<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\BusinessAuthorityFixture;
use Tests\TestCase;

final class BusinessDelegationSurfaceTest extends TestCase
{
    use ApplicationContractAssertions;

    private function draft(User $actor, string $name): array
    {
        return $this->actingAs($actor)->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => $name])->assertCreated()->json('data');
    }

    private function selection(int $grant): array
    {
        return ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $grant]]];
    }

    private function grant(array $business, User $recipient, int $parent, bool $delegable = false): array
    {
        return ['command_id' => (string) Str::uuid(), 'effect' => 'issue', 'grantee_public_id' => $recipient->public_id,
            'action' => 'business.context.read', 'parent_grant_id' => $parent, 'grant_id' => null, 'expected_grant' => 0,
            'expires_at' => null, 'delegable' => $delegable, 'realm_workspace_public_id' => null, 'authority_selection' => $this->selection($parent)];
    }

    public function test_surface_shows_only_current_own_parent_paths_and_their_direct_issued_rights(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $a = $this->draft($owner, 'Customer A');
        $b = $this->draft($owner, 'Customer B');
        $db = DB::connection('pgsql_migrations');
        $root = BusinessAuthorityFixture::root($db->table('businesses')->where('public_id', $a['business_public_id'])->value('id'), $owner->id, 'business.context.read');
        $foreignRoot = BusinessAuthorityFixture::root($db->table('businesses')->where('public_id', $b['business_public_id'])->value('id'), $owner->id, 'business.context.read');
        $url = '/api/businesses/'.$a['business_public_id'];
        $one = $this->postJson($url.'/grants', $this->grant($a, $other, $root->id))->assertOk()->json('data');
        $two = $this->postJson($url.'/grants', $this->grant($a, $other, $root->id))->assertOk()->json('data');
        $foreign = $this->postJson('/api/businesses/'.$b['business_public_id'].'/grants', $this->grant($b, $other, $foreignRoot->id))->assertOk()->json('data');
        $surface = $this->getJson($url.'/surface?access='.$a['grant_refs']['business.context.read'])->assertOk();
        $this->assertApplicationContract('BusinessSurfaceResult', $surface->json());
        $this->assertSame($owner->public_id, $surface->json('data.delegation.actor_public_id'));
        $this->assertSame([(string) $root->id], array_column($surface->json('data.delegation.options'), 'grant_id'));
        $this->assertTrue($surface->json('data.delegation.options.0.can_issue'));
        $this->assertSame([(string) $two['grant_id'], (string) $one['grant_id']], array_column($surface->json('data.delegation.issued'), 'grant_id'));
        $this->assertStringNotContainsString($other->email, $surface->getContent());
        $this->assertStringNotContainsString('Customer B', $surface->getContent());
        $this->assertNotContains((string) $foreign['grant_id'], array_column($surface->json('data.delegation.issued'), 'grant_id'));
        $recipient = $this->actingAs($other)->getJson($url.'/surface?access='.$one['grant_id'])->assertOk();
        $recipient->assertJsonCount(0, 'data.delegation.options')->assertJsonCount(0, 'data.delegation.issued');
        $this->actingAs($owner)->postJson($url.'/grants', [...$this->grant($a, $other, $root->id), 'effect' => 'revoke',
            'parent_grant_id' => null, 'grant_id' => $one['grant_id'], 'expected_grant' => 1])->assertOk();
        $this->actingAs($other)->getJson($url.'/surface?access='.$one['grant_id'])->assertForbidden();
        $this->getJson($url.'/surface?access='.$two['grant_id'])->assertOk();
        $this->getJson('/api/businesses/'.$b['business_public_id'].'/surface?access='.$foreign['grant_id'])->assertOk();
    }

    public function test_non_delegable_draft_has_no_issuance_and_full_parent_chain_limits_are_projected(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $a = $this->draft($owner, 'Bounded parent rights');
        $url = '/api/businesses/'.$a['business_public_id'];
        $this->getJson($url.'/surface?access='.$a['grant_refs']['business.context.read'])->assertOk()->assertJsonCount(0, 'data.delegation.options');
        $db = DB::connection('pgsql_migrations');
        $expires = now()->addHour()->startOfSecond();
        $root = BusinessAuthorityFixture::root($db->table('businesses')->where('public_id', $a['business_public_id'])->value('id'), $owner->id, 'business.context.read', validUntil: $expires->toIso8601String());
        $surface = $this->getJson($url.'/surface?access='.$root->id)->assertOk();
        $this->assertSame($expires->timestamp, strtotime($surface->json('data.delegation.options.0.max_expires_at')));
        $this->assertSame(1, $surface->json('data.delegation.options.0.chain_depth'));
        $this->postJson($url.'/grants', $this->grant($a, $other, $root->id))->assertForbidden();
        $this->postJson($url.'/grants', [...$this->grant($a, $other, $root->id), 'expires_at' => $expires->toIso8601String()])->assertOk();
        $this->postJson($url.'/grants', [...$this->grant($a, $owner, $root->id), 'effect' => 'revoke', 'grant_id' => $root->id,
            'parent_grant_id' => null, 'expected_grant' => 1])->assertOk();
        $this->getJson($url.'/surface?access='.$a['grant_refs']['business.context.read'])->assertOk()
            ->assertJsonCount(0, 'data.delegation.options')->assertJsonCount(0, 'data.delegation.issued');
    }
}
