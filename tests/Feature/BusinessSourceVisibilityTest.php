<?php

namespace Tests\Feature;

use App\Foundation\Business\BusinessScope;
use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\BusinessAuthorityFixture;
use Tests\TestCase;

final class BusinessSourceVisibilityTest extends TestCase
{
    use ApplicationContractAssertions;

    private function owner()
    {
        return DB::connection('pgsql_migrations');
    }

    private function draft(User $actor, string $name): array
    {
        $this->actingAs($actor);
        $request = Request::create('/api/businesses');
        $request->setUserResolver(fn () => $actor);
        $request->setLaravelSession(app('session')->driver());

        return app(BusinessScope::class)->create($request, (string) Str::uuid(), $name, null);
    }

    private function selection(int $grant): array
    {
        return ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
            'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $grant]]];
    }

    private function workspace(User $actor, string $slug): object
    {
        if (! $this->owner()->table('security_action_grants')->where('subject_user_id', $actor->id)
            ->where('action', 'workspace.create')->where('status', 'active')->exists()) {
            app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        }
        $this->actingAs($actor)->post('/workspaces', ['name' => $slug, 'slug' => $slug])->assertRedirect();

        return $this->owner()->table('workspaces')->where('slug', $slug)->sole();
    }

    private function source(User $actor, object $workspace, string $account): string
    {
        $id = (string) Str::uuid7();
        $this->owner()->table('source_registry')->insert(['id' => $id, 'workspace_id' => $workspace->id,
            'family' => 'meta', 'namespace' => 'ad_account', 'declared_external_id' => $account,
            'label' => $account, 'version' => 1, 'status' => 'UNVERIFIED', 'created_at' => now()]);
        $this->owner()->table('source_capabilities')->insert(['workspace_id' => $workspace->id, 'source_id' => $id,
            'resource' => $account, 'operation' => 'report.read', 'purpose' => 'advertising_analysis']);
        $this->owner()->table('security_action_grants')->insert([
            'subject_user_id' => $actor->id, 'granted_by' => $actor->id, 'source' => 'source_owner_request',
            'scope_kind' => 'source', 'workspace_id' => $workspace->id, 'source_target_id' => $id,
            'source_resource' => $account, 'source_operation' => 'report.read', 'source_purpose' => 'advertising_analysis',
            'action' => 'source.manage', 'subject_generation' => 1, 'status' => 'active',
            'reason' => 'synthetic_source_fixture', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function read(array $business): array
    {
        return $this->getJson('/api/businesses/'.$business['business_public_id'].'/sources?access='.$business['grant_refs']['business.context.read'])
            ->assertOk()->json('data');
    }

    public function test_individual_sources_require_both_current_business_workspace_link_and_exact_source_authority(): void
    {
        $actor = User::factory()->create();
        $first = $this->workspace($actor, 'first-source-team');
        $second = $this->workspace($actor, 'second-source-team');
        $a = $this->draft($actor, 'Shop A');
        $b = $this->draft($actor, 'Shop B');
        $sourceA = $this->source($actor, $first, 'account-a');
        $sourceB = $this->source($actor, $second, 'account-b');
        $this->assertCount(0, $this->read($a)['sources']);
        $this->assertCount(0, $this->read($b)['sources']);

        $scope = app(BusinessScope::class);
        $request = Request::create('/api/businesses');
        $request->setUserResolver(fn () => $actor);
        $request->setLaravelSession(app('session')->driver());
        $link = fn (array $business, object $workspace, string $effect, int $expected) => $scope->link($request,
            $business['business_public_id'], $workspace->public_id, (string) Str::uuid(), $effect, $expected,
            $this->selection($business['grant_refs']['business.link.manage']));
        $link($a, $first, 'add', 0);
        $response = $this->read($a);
        $this->assertCount(1, $response['sources']);
        $this->assertSame($sourceA, $response['sources'][0]['source_id']);
        $this->assertSame($first->public_id, $response['sources'][0]['workspace_public_id']);
        $this->assertApplicationContract('BusinessSourcesResult', ['data' => $response]);
        $this->assertCount(0, $this->read($b)['sources']);
        $link($b, $first, 'add', 0);
        $this->assertSame($sourceA, $this->read($b)['sources'][0]['source_id']);
        $this->assertCount(1, $this->read($a)['sources']);
        $link($a, $second, 'add', 0);
        $this->assertEqualsCanonicalizing([$sourceA, $sourceB], array_column($this->read($a)['sources'], 'source_id'));
        $link($a, $first, 'end', 1);
        $this->assertSame([$sourceB], array_column($this->read($a)['sources'], 'source_id'));
        $this->assertSame([$sourceA], array_column($this->read($b)['sources'], 'source_id'));
        $this->owner()->table('security_action_grants')->where('source_target_id', $sourceA)->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->assertCount(0, $this->read($b)['sources']);
        $this->assertSame([$sourceB], array_column($this->read($a)['sources'], 'source_id'));
    }

    public function test_agency_workspace_source_stays_private_from_business_only_client(): void
    {
        $agency = User::factory()->create();
        $workspace = $this->workspace($agency, 'agency-private-tools');
        $business = $this->draft($agency, 'Client business');
        $source = $this->source($agency, $workspace, 'agency-secret-account');
        $businessId = $this->owner()->table('businesses')->where('public_id', $business['business_public_id'])->value('id');
        $qualified = BusinessAuthorityFixture::root($businessId, $agency->id, 'business.link.manage');
        $request = Request::create('/api/businesses');
        $request->setUserResolver(fn () => $agency);
        $request->setLaravelSession(app('session')->driver());
        app(BusinessScope::class)->link($request, $business['business_public_id'], $workspace->public_id,
            (string) Str::uuid(), 'add', 0, $this->selection($qualified->id), 'agency');
        $this->assertSame([$source], array_column($this->read($business)['sources'], 'source_id'));

        $client = User::factory()->create();
        $clientGrant = BusinessAuthorityFixture::root($businessId, $client->id, 'business.context.read');
        $this->actingAs($client);
        $clientRead = $this->getJson('/api/businesses/'.$business['business_public_id'].'/sources?access='.$clientGrant->id)
            ->assertOk()->json('data');
        $this->assertSame([], $clientRead['sources']);
        $this->assertStringNotContainsString('agency-secret-account', json_encode($clientRead));
        $this->getJson('/api/businesses/'.$business['business_public_id'].'/sources?access='.$business['grant_refs']['business.context.read'])
            ->assertForbidden();
    }
}
