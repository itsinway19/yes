<?php

namespace Tests\Feature;

use App\Business\AdvertiserGoalPreferences;
use App\Business\BusinessContext;
use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\BusinessAuthorityFixture;
use Tests\TestCase;

final class BusinessSurfaceTest extends TestCase
{
    use ApplicationContractAssertions;

    private function draft(string $name): array
    {
        return $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => $name])->assertCreated()->json('data');
    }

    public function test_choices_and_surface_use_qualified_business_identity_without_a_workspace(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $empty = $this->getJson('/api/businesses')->assertOk()->assertJsonCount(0, 'data.items')->assertJsonPath('data.actor_public_id', $actor->public_id);
        $this->assertApplicationContract('BusinessChoicesResult', $empty->json());
        $a = $this->draft('First independent activity');
        $b = $this->draft('Second independent activity');
        $list = $this->getJson('/api/businesses')->assertOk();
        $this->assertApplicationContract('BusinessChoicesResult', $list->json());
        $this->assertSame([$a['business_public_id'], $b['business_public_id']], array_column($list->json('data.items'), 'business_public_id'));
        $url = '/businesses/'.$a['business_public_id'].'?access='.$a['grant_refs']['business.context.read'];
        $surface = $this->getJson('/api/businesses/'.$a['business_public_id'].'/surface?access='.$a['grant_refs']['business.context.read'])->assertOk();
        $this->assertApplicationContract('BusinessSurfaceResult', $surface->json());
        $this->assertSame('First independent activity', $surface->json('data.business.name'));
        $this->assertSame((string) $a['grant_refs']['business.context.manage'], $surface->json('data.context_write_options.0.grant_id'));
        foreach (['markets' => 'business.markets.declare', 'intent' => 'business.intent.declare', 'preferences' => 'business.preferences.manage'] as $kind => $action) {
            $this->assertSame((string) $a['grant_refs'][$action], $surface->json('data.fact_write_options.'.$kind.'.0.grant_id'));
        }
        $this->assertContains('SA', array_column($surface->json('data.catalog.countries'), 'code'));
        $this->assertContains('JO', array_column($surface->json('data.catalog.countries'), 'code'));
        $this->assertContains('Asia/Amman', $surface->json('data.catalog.intent_timezones'));
        $this->assertSame(app(BusinessContext::class)->currentIntentTimezones(), $surface->json('data.catalog.intent_timezones'));
        $this->assertSame(app(BusinessContext::class)->countries(), $surface->json('data.catalog.countries'));
        $this->assertSame(AdvertiserGoalPreferences::catalog(), $surface->json('data.catalog.goals'));
        $page = $this->getJson($url)->assertOk();
        $witness = $this->withHeader('X-Builderad-Witness', '1')->getJson($url)->assertOk();
        $this->assertSame($page->json('visibility'), $witness->json('visibility'));
        $this->assertArrayNotHasKey('data', $witness->json());
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('workspaces')->count());
    }

    public function test_selected_path_cannot_open_another_business_or_use_an_edit_grant_for_reading(): void
    {
        $this->actingAs(User::factory()->create());
        $a = $this->draft('Visible first activity');
        $b = $this->draft('Separate second activity');
        $base = '/api/businesses/'.$b['business_public_id'].'/surface?access=';
        $timezoneQueries = [];
        DB::listen(function ($event) use (&$timezoneQueries): void {
            if (str_contains($event->sql, 'pg_timezone_names')) {
                $timezoneQueries[] = $event->sql;
            }
        });
        $this->getJson($base.$a['grant_refs']['business.context.read'])->assertForbidden();
        $this->assertSame([], $timezoneQueries, 'A denied resource must not depend on loading public reference catalogs first.');
        $this->getJson($base.$b['grant_refs']['business.context.manage'])->assertForbidden();
        $this->getJson('/businesses/'.$b['business_public_id'])->assertUnprocessable();
        $this->actingAs(User::factory()->create())->getJson('/api/businesses')->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson($base.$b['grant_refs']['business.context.read'])->assertForbidden();
    }

    public function test_fact_surface_commands_preserve_other_business_and_independent_versions(): void
    {
        $this->actingAs(User::factory()->create());
        $a = $this->draft('Facts for activity A');
        $b = $this->draft('Facts for activity B');
        $selection = fn (array $business, string $action) => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
            'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $business['grant_refs'][$action]]]];
        $market = $this->putJson('/api/businesses/'.$a['business_public_id'].'/markets', ['command_id' => (string) Str::uuid(), 'codes' => ['SA', 'JO'],
            'expected_context' => 0, 'authority_selection' => $selection($a, 'business.markets.declare')])->assertOk();
        $this->assertApplicationContract('BusinessMarketWriteResult', $market->json());
        $intent = ['direction' => 'increase_value', 'scope' => 'business_wide', 'description' => null,
            'outcome' => ['meaning' => 'Qualified customer purchases', 'clarification' => null, 'count_unit' => null], 'quantity' => null, 'horizon' => null];
        $written = $this->putJson('/api/businesses/'.$a['business_public_id'].'/intent', ['command_id' => (string) Str::uuid(), 'value' => $intent,
            'confirmations' => ['declaration' => true, 'business_wide' => true, 'outcome' => true, 'acceptance_clear' => true],
            'expected_context' => 1, 'expected_intent' => 0, 'authority_selection' => $selection($a, 'business.intent.declare')])->assertOk();
        $this->assertApplicationContract('BusinessIntentWriteResult', $written->json());
        $goals = ['goals' => ['sales'], 'priority' => 'sales', 'results' => ['sales' => ['actual_sales']]];
        $saved = $this->putJson('/api/businesses/'.$a['business_public_id'].'/preferences', ['command_id' => (string) Str::uuid(), 'value' => $goals,
            'expected_preference' => 0, 'authority_selection' => $selection($a, 'business.preferences.manage')])->assertOk();
        $this->assertApplicationContract('BusinessPreferenceWriteResult', $saved->json());
        $read = $this->getJson('/api/businesses/'.$a['business_public_id'].'/surface?access='.$a['grant_refs']['business.context.read'])->assertOk();
        $this->assertApplicationContract('BusinessSurfaceResult', $read->json());
        $read->assertJsonPath('data.business.versions.context', 2)->assertJsonPath('data.business.versions.intent', 1)
            ->assertJsonPath('data.business.versions.preferences', 1)->assertJsonPath('data.business.declared_markets', ['JO', 'SA']);
        $this->getJson('/api/businesses/'.$b['business_public_id'].'/surface?access='.$b['grant_refs']['business.context.read'])->assertOk()
            ->assertJsonPath('data.business.current_intent', null)->assertJsonPath('data.business.preferences', null)
            ->assertJsonPath('data.business.declared_markets', [])->assertJsonPath('data.business.versions.context', 0)
            ->assertJsonPath('data.business.versions.intent', 0)->assertJsonPath('data.business.versions.preferences', 0);
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('workspaces')->count());
    }

    public function test_fact_options_withdraw_independently_and_context_management_does_not_authorize_declaration(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $a = $this->draft('Independent declaration rights');
        $selection = fn (string $action) => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
            'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $a['grant_refs'][$action]]]];
        $withdrawal = ['command_id' => (string) Str::uuid(), 'effect' => 'revoke',
            'grantee_public_id' => $actor->public_id, 'action' => 'business.markets.declare', 'parent_grant_id' => null,
            'grant_id' => $a['grant_refs']['business.markets.declare'], 'expected_grant' => 1, 'expires_at' => null, 'delegable' => false,
            'realm_workspace_public_id' => null, 'authority_selection' => $selection('business.context.read')];
        $this->postJson('/api/businesses/'.$a['business_public_id'].'/grants', $withdrawal)->assertForbidden();
        $this->postJson('/api/businesses/'.$a['business_public_id'].'/grants', [...$withdrawal,
            'command_id' => (string) Str::uuid(), 'authority_selection' => $selection('business.markets.declare')])->assertOk();
        $this->getJson('/api/businesses/'.$a['business_public_id'].'/surface?access='.$a['grant_refs']['business.context.read'])->assertOk()
            ->assertJsonCount(0, 'data.fact_write_options.markets')->assertJsonCount(1, 'data.fact_write_options.intent')
            ->assertJsonCount(1, 'data.fact_write_options.preferences')->assertJsonCount(1, 'data.context_write_options');
        foreach (['business.markets.declare', 'business.context.manage'] as $action) {
            $this->putJson('/api/businesses/'.$a['business_public_id'].'/markets', ['command_id' => (string) Str::uuid(), 'codes' => ['SA'],
                'expected_context' => 0, 'authority_selection' => $selection($action)])->assertForbidden();
        }
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('business_market_transitions')->count());
    }

    public function test_archiving_removes_choices_and_blocks_page_and_witness_without_fallback(): void
    {
        $this->actingAs(User::factory()->create());
        $a = $this->draft('Activity to archive');
        $url = '/businesses/'.$a['business_public_id'].'?access='.$a['grant_refs']['business.context.read'];
        $this->getJson($url)->assertOk();
        $this->postJson('/api/businesses/'.$a['business_public_id'].'/lifecycle', [
            'command_id' => (string) Str::uuid(), 'effect' => 'archive', 'target_ref' => null, 'payload' => (object) [], 'expected_revision' => 0,
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => $a['grant_refs']['business.draft.archive']],
            ]],
        ])->assertOk();
        $this->getJson('/api/businesses')->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson($url)->assertForbidden();
        $this->withHeader('X-Builderad-Witness', '1')->getJson($url)->assertForbidden();
    }

    public function test_business_fact_change_invalidates_the_qualified_page_witness(): void
    {
        $this->actingAs(User::factory()->create());
        $a = $this->draft('Name before update');
        $url = '/businesses/'.$a['business_public_id'].'?access='.$a['grant_refs']['business.context.read'];
        $before = $this->getJson($url)->assertOk()->json('visibility');
        $this->putJson('/api/businesses/'.$a['business_public_id'].'/context', [
            'command_id' => (string) Str::uuid(), 'name' => 'Name after update', 'business_type_id' => null, 'expected_context' => 0,
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => $a['grant_refs']['business.context.manage']],
            ]],
        ])->assertOk();
        $this->assertNotSame($before, $this->withHeader('X-Builderad-Witness', '1')->getJson($url)->assertOk()->json('visibility'));
    }

    public function test_expired_and_old_generation_leaves_never_create_a_repeating_empty_cursor(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $a = $this->draft('Bounded choices');
        $db = DB::connection('pgsql_migrations');
        $row = (array) $db->table('security_action_grants')->where('id', $a['grant_refs']['business.context.read'])->first();
        $after = (int) $db->table('security_action_grants')->max('id');
        foreach (['expired', 'old_generation'] as $kind) {
            $row['id'] = (int) $db->scalar("SELECT nextval('security_action_grants_id_seq')");
            $row['expires_at'] = $kind === 'expired' ? now()->subMinute()->toIso8601String() : null;
            $row['subject_generation'] = $kind === 'old_generation' ? 2 : 1;
            BusinessAuthorityFixture::insert('security_action_grants', $row, $user->id);
        }
        $page = $this->getJson('/api/businesses?after='.$after)->assertOk();
        $this->assertApplicationContract('BusinessChoicesResult', $page->json());
        $page->assertJsonCount(0, 'data.items')->assertJsonPath('data.next_cursor', null);
    }

    public function test_native_preflight_uses_a_scoped_identity_without_opening_unscoped_or_business_grant_access(): void
    {
        $actor = User::factory()->create();
        $other = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $authority = app(GrantAuthority::class);
        $this->assertSame(0, DB::table('security_action_grants')->count());
        $this->assertTrue($authority->has($actor, 1, 'workspace.create'));
        $this->assertFalse($authority->has($other, 1, 'workspace.create'));
        $this->assertFalse($authority->has($actor, 1, 'workspace.create', 'business', 1));
        $this->assertSame(0, DB::table('security_action_grants')->count());
        DB::transaction(function () use ($actor, $other, $authority): void {
            app(PrincipalContext::class)->activate($other);
            $this->assertFalse($authority->has($actor, 1, 'workspace.create'));
        });
    }
}
