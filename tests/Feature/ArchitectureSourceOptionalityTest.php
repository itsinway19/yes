<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ObjectPurposeTestCase;
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
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $this->actingAs($actor)->post('/workspaces', ['name' => 'Source optional business', 'slug' => 'source-optional'])->assertRedirect();
        $owner = DB::connection('pgsql_migrations');
        $workspace = $owner->table('workspaces')->where('slug', 'source-optional')->first();
        $url = '/workspaces/'.$workspace->public_id.'/business';
        // An authorized synthetic catalog fixture, never provider evidence or real Pilot data.
        $type = $owner->table('business_types')->where('key', 'real_estate')->value('id');
        $owner->table('business_types')->where('id', $type)->update(['active' => true]);
        $this->post($url, ['business_type_id' => $type, 'name' => 'Confirmed business', 'version' => 0])->assertRedirect();
        $this->post($url.'/target-markets', ['country_codes' => ['SA', 'AE'], 'version' => 1])->assertRedirect($url);
        $intent = ['direction' => 'increase_value', 'scope' => 'business_wide', 'description' => null,
            'outcome' => ['meaning' => 'Completed bookings', 'clarification' => null, 'count_unit' => null], 'quantity' => null, 'horizon' => null];
        $this->postJson($url.'/current-intent', ['command' => 'replace', 'version' => 2, 'value' => $intent,
            'confirmations' => ['declaration' => true, 'business_wide' => true, 'outcome' => true, 'acceptance_clear' => true]])->assertRedirect($url);
        $this->get($url)->assertInertia(fn (Assert $page) => $page->component('Business/Context')
            ->where('workspace.name', 'Confirmed business')->where('business.version', 3)
            ->where('business.targetMarketsDeclared', true)->where('business.targetMarkets.0.code', 'AE')
            ->where('business.targetMarkets.1.code', 'SA')->where('business.currentIntent.value', $intent));
        $this->get('/workspaces/'.$workspace->public_id)->assertOk();

        // Explicit, finite synthetic identity and provenance are still mandatory; no discovery or inferred identity.
        $command = ObjectPurposeTestCase::command();
        $command['declaration']['included_objects'] = [['provider' => 'meta', 'account_namespace' => 'meta_ads_account',
            'account_id' => '100', 'object_kind' => 'campaign', 'object_id' => '200', 'incarnation' => null]];
        $purposeUrl = '/workspaces/'.$workspace->public_id.'/object-purposes';
        $this->getJson($purposeUrl)->assertOk()->assertJsonPath('state', 'UNKNOWN');
        $first = $this->postJson($purposeUrl, $command)->assertCreated()->json();
        $read = $this->getJson($purposeUrl.'/'.$first['purpose_id'])->assertOk()
            ->assertJsonCount(1, 'items.0.applicable.snapshot.included_objects');
        foreach ($command['declaration']['included_objects'][0] as $key => $value) {
            $read->assertJsonPath('items.0.applicable.snapshot.included_objects.0.'.$key, $value);
        }
        $missingIdentity = ObjectPurposeTestCase::command();
        $missingIdentity['declaration']['included_objects'] = [];
        $this->postJson($purposeUrl, $missingIdentity)->assertUnprocessable();
        $this->assertSame(1, $owner->table('object_purpose_revisions')->count());
        $this->assertSame(3, $owner->table('workspaces')->where('id', $workspace->id)->value('business_context_version'));
        $this->assertSame(0, $owner->table('source_registry')->count());
        $this->assertSame(0, $owner->table('provider_access')->count());
        Http::assertNothingSent();
    }
}
