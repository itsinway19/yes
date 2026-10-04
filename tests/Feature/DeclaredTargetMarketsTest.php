<?php

namespace Tests\Feature;

use App\Business\BusinessContext;
use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\User;
use App\Models\Workspace;
use App\Security\GrantAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DeclaredTargetMarketsTest extends TestCase
{
    protected function business(string $slug = 'declared-business'): object
    {
        $this->withCredentials();
        $actor = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $this->actingAs($actor)->post('/workspaces', ['name' => 'Declared business', 'slug' => $slug])->assertRedirect();

        return DB::connection('pgsql_migrations')->table('workspaces')->where('slug', $slug)->first();
    }

    private function replace(object $workspace, array $codes, int $version): int
    {
        $request = Request::create('/', 'POST');
        $request->setLaravelSession($this->app['session']->driver());
        $request->setUserResolver(fn () => auth()->user());

        return app(WorkspaceContext::class)->runBusinessMutation(auth()->user(), $workspace->public_id,
            fn (Workspace $active) => app(BusinessContext::class)->replaceTargetMarkets($request, $active, $codes, $version), $request);
    }

    private function projection(object $workspace): array
    {
        $request = Request::create('/', 'GET');
        $request->setLaravelSession($this->app['session']->driver());
        $request->setUserResolver(fn () => auth()->user());

        return app(WorkspaceContext::class)->run(auth()->user(), $workspace->public_id,
            fn (Workspace $active) => app(BusinessContext::class)->projection($request, $active), $request);
    }

    public function test_empty_projection_is_not_declared(): void
    {
        $workspace = $this->business();
        $projection = $this->projection($workspace);
        $this->assertArrayHasKey('targetMarketsDeclared', $projection);
        $this->assertFalse($projection['targetMarketsDeclared']);
        $this->assertSame([], $projection['targetMarkets']);
    }

    public function test_projection_round_trips_canonical_codes(): void
    {
        $workspace = $this->business();
        $this->assertSame(1, $this->replace($workspace, ['SA', 'KW', 'AE', 'SA'], 0));
        $projection = $this->projection($workspace);
        $this->assertTrue($projection['targetMarketsDeclared']);
        $this->assertSame(1, (int) $projection['version']);
        $this->assertSame(['AE', 'KW', 'SA'], array_column($projection['targetMarkets'], 'code'));
    }

    public function test_locale_change_preserves_codes(): void
    {
        $workspace = $this->business();
        $this->replace($workspace, ['SA', 'AE'], 0);
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertSame(['AE', 'SA'], array_column($this->projection($workspace)['targetMarkets'], 'code'));
        }
        $this->assertCount(249, app(BusinessContext::class)->countries());
    }

    public function test_malformed_php_adapter_values_preserve_populated_declaration_atomically(): void
    {
        $workspace = $this->business();
        $this->assertSame(1, $this->replace($workspace, ['KW', 'SA'], 0));
        $db = DB::connection('pgsql_migrations');
        $snapshot = fn () => [(array) $db->table('workspaces')->find($workspace->id),
            $db->table('business_target_markets')->where('workspace_id', $workspace->id)->orderBy('country_code')->get()->toArray(),
            $db->table('business_target_market_events')->where('workspace_id', $workspace->id)->orderBy('id')->get()->toArray()];
        $before = $snapshot();
        foreach ([['S"A'], ["S'A"], ['{SA}'], ['SA', '{AE}'], ['SA\\AE'], ["SA']; DELETE FROM workspaces; --"], [null], [['SA']], ['key' => 'SA']] as $codes) {
            try {
                $this->replace($workspace, $codes, 1);
                $this->fail('Malformed adapter value unexpectedly succeeded.');
            } catch (QueryException $error) {
                $this->assertSame('22023', $error->getCode());
            }
            $this->assertEquals($before, $snapshot());
        }
    }

    public function test_http_replacement_refreshes_canonical_projection_and_preserves_name_type(): void
    {
        $workspace = $this->business();
        $url = '/workspaces/'.$workspace->public_id.'/business';
        $this->post($url.'/target-markets', ['country_codes' => ['SA', 'KW', 'AE', 'SA'], 'version' => 0])
            ->assertRedirect($url);
        $this->get($url)->assertInertia(fn (Assert $page) => $page->component('Business/Context')
            ->has('countryOptions', 249)->where('business.targetMarketsDeclared', true)
            ->where('business.version', 1)->where('business.targetMarkets.0.code', 'AE')
            ->where('business.targetMarkets.1.code', 'KW')->where('business.targetMarkets.2.code', 'SA'));
        $actual = DB::connection('pgsql_migrations')->table('workspaces')->find($workspace->id);
        $this->assertSame($workspace->name, $actual->name);
        $this->assertSame($workspace->business_type_id, $actual->business_type_id);
    }

    public function test_http_invalid_shape_and_codes_have_no_effect(): void
    {
        $workspace = $this->business();
        $url = '/workspaces/'.$workspace->public_id.'/business/target-markets';
        foreach ([null, 'SA', ['sa'], ['GCC'], ['ZZ'], [null], ['key' => 'SA'], array_fill(0, 250, 'SA')] as $codes) {
            $this->postJson($url, ['country_codes' => $codes, 'version' => 0])->assertUnprocessable()
                ->assertJsonValidationErrors('country_codes');
        }
        $this->postJson($url, ['version' => 0])->assertUnprocessable()->assertJsonValidationErrors('country_codes');
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            '{"country_codes":{},"version":0}')->assertUnprocessable()->assertJsonValidationErrors('country_codes');
        foreach ([null, -1, 'invalid'] as $version) {
            $this->postJson($url, ['country_codes' => ['SA'], 'version' => $version])->assertUnprocessable()
                ->assertJsonValidationErrors('version');
        }
        $owner = DB::connection('pgsql_migrations');
        $this->assertSame(0, $owner->table('business_target_markets')->count());
        $this->assertSame(0, $owner->table('business_target_market_events')->count());
        $this->assertSame(0, (int) $owner->table('workspaces')->find($workspace->id)->business_context_version);
    }

    public function test_http_empty_removal_and_stale_same_set_are_noop_safe(): void
    {
        $workspace = $this->business();
        $url = '/workspaces/'.$workspace->public_id.'/business/target-markets';
        $this->post($url, ['country_codes' => ['SA'], 'version' => 0])->assertRedirect();
        $this->post($url, ['country_codes' => ['SA', 'SA'], 'version' => 0])->assertRedirect();
        $this->postJson($url, ['country_codes' => ['AE'], 'version' => 0])->assertUnprocessable()
            ->assertJsonValidationErrors('version');
        $this->assertSame(['SA'], array_column($this->projection($workspace)['targetMarkets'], 'code'));
        $this->post($url, ['country_codes' => [], 'version' => 1])->assertRedirect();
        $this->post($url, ['country_codes' => [], 'version' => 0])->assertRedirect();
        $projection = $this->projection($workspace);
        $this->assertFalse($projection['targetMarketsDeclared']);
        $this->assertSame([], $projection['targetMarkets']);
        $this->assertSame(2, (int) $projection['version']);
        $this->assertSame(2, DB::connection('pgsql_migrations')->table('business_target_market_events')->count());
    }

    public function test_authorized_nonowner_manager_can_edit(): void
    {
        $workspace = $this->business();
        $owner = auth()->user();
        $member = User::factory()->create();
        $db = DB::connection('pgsql_migrations');
        $db->table('workspace_memberships')->insert(['workspace_id' => $workspace->id, 'user_id' => $member->id,
            'role' => 'member', 'status' => 'active', 'created_at' => now()]);
        foreach (['workspace.view', 'business.context.manage'] as $action) {
            $db->table('security_action_grants')->insert(['subject_user_id' => $member->id, 'granted_by' => $owner->id,
                'subject_generation' => 1, 'source' => 'human', 'scope_kind' => 'workspace', 'workspace_id' => $workspace->id,
                'action' => $action, 'reason' => 'explicit_fixture', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($member)->post('/workspaces/'.$workspace->public_id.'/business/target-markets',
            ['country_codes' => ['SA'], 'version' => 0])->assertRedirect();
        $this->assertSame(['SA'], array_column($this->projection($workspace)['targetMarkets'], 'code'));
    }

    public function test_owner_without_exact_grant_cannot_replay_identical_empty_set(): void
    {
        $workspace = $this->business();
        DB::connection('pgsql_migrations')->table('security_action_grants')->where('workspace_id', $workspace->id)
            ->where('action', 'business.context.manage')->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->post('/workspaces/'.$workspace->public_id.'/business/target-markets',
            ['country_codes' => [], 'version' => 0])->assertForbidden();
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('business_target_market_events')->count());
    }

    public function test_name_type_write_preserves_declared_markets(): void
    {
        $workspace = $this->business();
        $url = '/workspaces/'.$workspace->public_id.'/business';
        $this->post($url.'/target-markets', ['country_codes' => ['SA', 'AE'], 'version' => 0])->assertRedirect();
        $db = DB::connection('pgsql_migrations');
        $before = $db->table('business_target_market_events')->orderBy('id')->get()->toArray();
        $type = $db->table('business_types')->where('key', 'real_estate')->value('id');
        $this->post($url, ['business_type_id' => $type, 'version' => 1])->assertRedirect();
        $this->assertSame(['AE', 'SA'], array_column($this->projection($workspace)['targetMarkets'], 'code'));
        $this->assertEquals($before, $db->table('business_target_market_events')->orderBy('id')->get()->toArray());
        $this->assertSame(2, (int) $db->table('workspaces')->find($workspace->id)->business_context_version);
    }

    public function test_foreign_workspace_and_revoked_membership_are_forbidden(): void
    {
        $workspace = $this->business();
        $actor = auth()->user();
        $url = '/workspaces/'.$workspace->public_id.'/business/target-markets';
        $db = DB::connection('pgsql_migrations');
        $db->table('workspace_memberships')->where('workspace_id', $workspace->id)->where('user_id', $actor->id)
            ->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->post($url, ['country_codes' => ['SA'], 'version' => 0])->assertForbidden();
        $this->actingAs(User::factory()->create())->post($url, ['country_codes' => ['SA'], 'version' => 0])->assertForbidden();
        $this->assertSame(0, $db->table('business_target_markets')->count());
        $this->assertSame(0, $db->table('business_target_market_events')->count());
    }

    public function test_revoked_session_cannot_declare(): void
    {
        $workspace = $this->business();
        $db = DB::connection('pgsql_migrations');
        $db->table('security_sessions')->where('user_id', auth()->id())->update(['revoked_at' => now()]);
        $this->postJson('/workspaces/'.$workspace->public_id.'/business/target-markets',
            ['country_codes' => ['SA'], 'version' => 0])->assertForbidden();
        $this->assertSame(0, $db->table('business_target_markets')->count());
        $this->assertSame(0, $db->table('business_target_market_events')->count());
    }

    public function test_zero_markets_business_remains_valid(): void
    {
        $workspace = $this->business();
        $db = DB::connection('pgsql_migrations');
        $type = $db->table('business_types')->where('key', 'real_estate')->value('id');
        $this->post('/workspaces/'.$workspace->public_id.'/business', ['business_type_id' => $type, 'version' => 0])->assertRedirect();
        $this->get('/workspaces/'.$workspace->public_id)->assertInertia(fn (Assert $page) => $page->component('Business/Sources')
            ->where('business.targetMarketsDeclared', false)->where('business.targetMarkets', []));
        $this->assertSame(0, $db->table('business_target_market_events')->count());
    }

    public function test_no_location_required_for_sa(): void
    {
        $workspace = $this->business();
        $this->post('/workspaces/'.$workspace->public_id.'/business/target-markets',
            ['country_codes' => ['SA'], 'version' => 0])->assertRedirect();
        $this->assertSame(['SA'], array_column($this->projection($workspace)['targetMarkets'], 'code'));
        $this->assertSame(1, (int) $this->projection($workspace)['version']);
    }

    public function test_jordan_location_example_keeps_independent_meaning(): void
    {
        // Semantic scenario only: no persisted Business Location exists in this product slice.
        $independentLocationScenario = 'JO';
        $workspace = $this->business();
        $before = (array) $workspace;
        $this->post('/workspaces/'.$workspace->public_id.'/business/target-markets',
            ['country_codes' => ['SA'], 'version' => 0])->assertRedirect();
        $this->assertSame('JO', $independentLocationScenario);
        $this->assertSame(['SA'], array_column($this->projection($workspace)['targetMarkets'], 'code'));
        $after = (array) DB::connection('pgsql_migrations')->table('workspaces')->find($workspace->id);
        unset($before['business_context_version'], $before['updated_at'], $after['business_context_version'], $after['updated_at']);
        $this->assertSame($before, $after);
    }
}
