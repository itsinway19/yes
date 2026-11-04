<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ApplicationContractAssertions;
use Tests\TestCase;

final class BusinessFoundationContractTest extends TestCase
{
    use ApplicationContractAssertions;

    public function test_current_business_retains_inactive_type_history_without_admitting_it_as_a_new_selection(): void
    {
        $this->actingAs(User::factory()->create());
        $db = DB::connection('pgsql_migrations');
        $types = $db->table('business_types')->orderBy('id')->limit(2)->pluck('id');
        $db->table('business_types')->whereIn('id', $types)->update(['active' => true]);
        $file = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Type continuity', 'business_type_id' => $types[0]])->assertCreated()->json('data');
        $selection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $file['grant_refs']['business.context.manage']]]];
        $body = ['command_id' => (string) Str::uuid(), 'name' => 'Type continuity', 'business_type_id' => $types[0], 'expected_context' => 0, 'authority_selection' => $selection];
        $db->table('business_types')->where('id', $types[0])->update(['active' => false]);
        $base = '/api/businesses/'.$file['business_public_id'].'/context';
        $this->putJson($base, $body)->assertOk()->assertJsonPath('data.versions.context', 0);
        $this->putJson($base, [...$body, 'command_id' => (string) Str::uuid(), 'business_type_id' => $types[1]])->assertOk();
        $this->putJson($base, [...$body, 'command_id' => (string) Str::uuid(), 'expected_context' => 1])->assertUnprocessable();
        $this->assertSame(1, $db->table('business_context_transitions')->count());
        $before = json_decode($db->table('business_context_transitions')->value('before_value'), true);
        $this->assertSame($types[0], $before['business_type_id']);
        $this->assertSame(0, $db->table('workspaces')->count());
    }

    public function test_implemented_http_contracts_preserve_independent_identity_versions_and_explicit_authority(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u);
        $created = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Independent contract subject', 'business_type_id' => null])->assertCreated();
        $this->assertApplicationContract('BusinessDraftResult', $created->json());
        $a = $created->json('data');
        $base = '/api/businesses/'.$a['business_public_id'];
        $selection = fn (string $action) => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
            'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $a['grant_refs'][$action]]]];
        $body = fn (string $action) => ['command_id' => (string) Str::uuid(), 'authority_selection' => $selection($action)];
        $read = $this->postJson($base.'/read', ['authority_selection' => $selection('business.context.read')])->assertOk();
        $this->assertApplicationContract('BusinessFoundationProjection', $read->json());
        $context = $this->putJson($base.'/context', $body('business.context.manage') + ['name' => 'Revised declaration', 'business_type_id' => null, 'expected_context' => 0])->assertOk();
        $this->assertApplicationContract('BusinessContextWriteResult', $context->json());
        $markets = $this->putJson($base.'/markets', $body('business.markets.declare') + ['codes' => ['SA', 'JO'], 'expected_context' => 1])->assertOk();
        $this->assertApplicationContract('BusinessMarketWriteResult', $markets->json());
        $preferences = $this->putJson($base.'/preferences', $body('business.preferences.manage') + ['value' => ['goals' => ['sales'], 'priority' => 'sales', 'results' => (object) []], 'expected_preference' => 0])->assertOk();
        $this->assertApplicationContract('BusinessPreferenceWriteResult', $preferences->json());
        $intent = $this->putJson($base.'/intent', $body('business.intent.declare') + ['value' => null, 'confirmations' => ['declaration' => true], 'expected_context' => 2, 'expected_intent' => 0])->assertOk();
        $this->assertApplicationContract('BusinessIntentWriteResult', $intent->json());
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($u, 1);
        $this->post('/workspaces', ['name' => 'Collaboration contract', 'slug' => 'collaboration-contract'])->assertRedirect();
        $w = DB::connection('pgsql_migrations')->table('workspaces')->where('slug', 'collaboration-contract')->value('public_id');
        $linked = $this->postJson($base.'/workspace-links', $body('business.link.manage') + ['workspace_public_id' => $w, 'kind' => 'collaboration', 'effect' => 'add', 'expected_revision' => 0])->assertOk();
        $this->assertApplicationContract('BusinessLinkWriteResult', $linked->json());
        $this->assertApplicationContract('BusinessFoundationProjection', $this->postJson($base.'/read', ['authority_selection' => $selection('business.context.read')])->assertOk()->json());
        $this->assertSame('no-store, private', $context->headers->get('Cache-Control'));
        $denied = $this->actingAs(User::factory()->create())->postJson($base.'/read', ['authority_selection' => $selection('business.context.read')])->assertForbidden();
        $this->assertApplicationContract('BusinessFoundationError', $denied->json());
        $this->actingAs($u);
        $archive = $this->postJson($base.'/lifecycle', $body('business.draft.archive') + ['effect' => 'archive', 'target_ref' => null, 'payload' => (object) [], 'expected_revision' => 0])->assertOk();
        $this->assertApplicationContract('BusinessLifecycleWriteResult', $archive->json());
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('businesses')->count());
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('workspaces')->count());
    }

    public function test_business_entry_rejects_uncontracted_workspace_and_authority_claims_instead_of_discarding_them(): void
    {
        $this->actingAs(User::factory()->create());
        $command = ['command_id' => (string) Str::uuid(), 'name' => 'Explicit declaration'];
        foreach (['workspace_id' => 1, 'owner' => true, 'authority_selection' => [], 'source_id' => (string) Str::uuid7()] as $field => $value) {
            $this->postJson('/api/businesses', $command + [$field => $value])->assertUnprocessable();
        }
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('businesses')->count());
        $a = $this->postJson('/api/businesses', $command)->assertCreated()->json('data');
        $selection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
            'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $a['grant_refs']['business.context.read']]]];
        $this->postJson('/api/businesses/'.$a['business_public_id'].'/read', ['authority_selection' => $selection, 'workspace_id' => 1])->assertUnprocessable();
        $this->postJson('/api/businesses/'.$a['business_public_id'].'/workspace-links', ['command_id' => (string) Str::uuid(), 'authority_selection' => $selection,
            'workspace_public_id' => (string) Str::uuid7(), 'effect' => 'add', 'expected_revision' => 0, 'grant_owner' => true])->assertUnprocessable();
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('business_workspace_links')->count());
        $this->assertSame(7, DB::connection('pgsql_migrations')->table('security_action_grants')->where('scope_kind', 'business')->count());
    }
}
