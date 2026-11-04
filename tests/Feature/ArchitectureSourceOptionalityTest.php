<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\BusinessAuthorityFixture;
use Tests\Support\PurposeDeclarationFixture;
use Tests\TestCase;

class ArchitectureSourceOptionalityTest extends TestCase
{
    public function test_existing_advertiser_declarations_operate_without_external_connections_or_credentials(): void
    {
        foreach (['meta_ads', 'google_ads', 'snapchat_ads', 'tiktok_ads', 'x_ads'] as $provider) {
            config()->set('services.'.$provider, []);
        }
        Http::preventStrayRequests();
        $this->withCredentials();
        $this->get('/')->assertOk();
        $this->get('/login')->assertOk();
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $file = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Source-optional Business'])->assertCreated()->json('data');
        $base = '/api/businesses/'.$file['business_public_id'];
        $selection = fn ($grant) => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => (int) $grant]]];
        $command = fn ($action) => ['command_id' => (string) Str::uuid(), 'authority_selection' => $selection($file['grant_refs'][$action])];
        $this->putJson($base.'/context', $command('business.context.manage') + ['name' => 'Confirmed independent Business', 'business_type_id' => null, 'expected_context' => 0])->assertOk();
        $this->putJson($base.'/markets', $command('business.markets.declare') + ['codes' => ['SA', 'AE'], 'expected_context' => 1])->assertOk();
        $intent = ['direction' => 'increase_value', 'scope' => 'business_wide', 'description' => null,
            'outcome' => ['meaning' => 'Completed bookings', 'clarification' => null, 'count_unit' => null], 'quantity' => null, 'horizon' => null];
        $this->putJson($base.'/intent', $command('business.intent.declare') + ['value' => $intent, 'expected_context' => 2, 'expected_intent' => 0,
            'confirmations' => ['declaration' => true, 'business_wide' => true, 'outcome' => true, 'acceptance_clear' => true]])->assertOk();
        $this->putJson($base.'/preferences', $command('business.preferences.manage') + ['value' => ['goals' => ['sales'], 'priority' => 'sales', 'results' => (object) []], 'expected_preference' => 0])->assertOk();
        $db = DB::connection('pgsql_migrations');
        $this->assertSame(0, $db->table('workspaces')->count());
        // Synthetic qualification tests source independence, not an operating
        // Purpose issuer or evidence of real external object rights.
        $id = $db->table('businesses')->where('public_id', $file['business_public_id'])->value('id');
        $purpose = BusinessAuthorityFixture::root($id, $actor->id, 'business.purpose.declare');
        $this->getJson($base.'/purposes?access='.$file['grant_refs']['business.context.read'])->assertOk()->assertJsonPath('data.projection.state', 'UNKNOWN');
        $body = ['command' => PurposeDeclarationFixture::command(), 'expected_context' => 3, 'authority_selection' => $selection($purpose->id)];
        $this->postJson($base.'/purposes', $body)->assertCreated();
        $body['command'] = PurposeDeclarationFixture::command();
        $body['command']['declaration']['included_objects'] = [];
        $this->postJson($base.'/purposes', $body)->assertUnprocessable();
        $this->assertSame(1, $db->table('object_purpose_revisions')->count());
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $key = $this->getJson('/organizations/new')->assertOk()->json('data.creationKey');
        $organization = $this->postJson('/organizations', ['name' => 'Source-optional organization', 'creation_key' => $key])->assertOk()->json('redirect');
        $this->getJson($organization)->assertOk()->assertJsonMissingPath('data.business')->assertJsonMissingPath('data.guidance');
        foreach (['business_context_events', 'business_current_intent_events', 'business_target_markets', 'advertiser_goal_preferences', 'source_registry', 'provider_access'] as $table) {
            $this->assertSame(0, $db->table($table)->count(), $table);
        }
        Http::assertNothingSent();
    }
}
