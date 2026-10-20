<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GoalsSpaProofTest extends TestCase
{
    private object $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        require base_path('tests/Support/goals-spa-routes.php');
        $user = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($user, 1);
        $this->actingAs($user)->post('/workspaces', ['name' => 'SPA proof', 'slug' => 'spa-proof'])->assertRedirect();
        $this->workspace = DB::connection('pgsql_migrations')->table('workspaces')->where('slug', 'spa-proof')->first();
    }

    public function test_projection_uses_the_same_canonical_reader_and_writer(): void
    {
        $id = $this->workspace->public_id;
        $original = '/workspaces/'.$id.'/goals';
        $api = '/workspaces/'.$id.'/goals/context';
        $projection = $this->getJson($api)->assertOk()->json();
        $this->assertSame($this->getJson($original)->assertOk()->json(), $projection['preferences']);
        $this->assertCount(4, $projection['catalog']);
        $this->postJson($original, ['value' => AdvertiserGoalPreferencesTest::value(), 'version' => 0])->assertOk();
        $this->getJson($api)->assertOk()->assertJsonPath('preferences.version', 1);
        $this->postJson($original, ['value' => AdvertiserGoalPreferencesTest::value(), 'version' => 0])->assertStatus(409);
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('advertiser_goal_preference_events')->count());
        $this->get('/workspaces/'.$id.'/goals')->assertOk()->assertSee('application-bootstrap', false)->assertDontSee('data-page=', false);
    }

    public function test_foreign_workspace_cannot_be_read_or_streamed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $id = $this->workspace->public_id;
        $this->getJson('/workspaces/'.$id.'/goals/context')->assertForbidden();
        $this->getJson('/workspaces/'.$id.'/goals/events')->assertForbidden();
        $this->postJson('/workspaces/'.$id.'/goals', ['value' => AdvertiserGoalPreferencesTest::value(), 'version' => 0])->assertForbidden();
    }

    public function test_restoration_and_witness_reads_do_not_extend_idle_authority(): void
    {
        $db = DB::connection('pgsql_migrations');
        $before = $db->table('security_sessions')->first();
        $url = '/workspaces/'.$this->workspace->public_id.'/goals/context';
        $this->getJson($url, ['X-Goals-Passive' => '1'])->assertOk();
        $this->getJson($url, ['X-Goals-Witness' => '1'])->assertOk()->assertJsonMissingPath('preferences');
        $after = $db->table('security_sessions')->where('id', $before->id)->first();
        $this->assertSame($before->idle_expires_at, $after->idle_expires_at);
        $this->assertSame($before->absolute_expires_at, $after->absolute_expires_at);
        $this->assertSame($before->last_seen_at, $after->last_seen_at);
    }

    public function test_event_is_authorized_by_workspace_context_and_contains_the_current_version(): void
    {
        $id = $this->workspace->public_id;
        $response = $this->getJson('/workspaces/'.$id.'/goals/events')->assertOk();
        $this->assertStringStartsWith('text/event-stream', $response->headers->get('Content-Type'));
        $event = json_decode(substr(trim($response->streamedContent()), 6), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($id, $event['workspace']);
        $this->assertSame(0, $event['version']);
        $this->assertSame(auth()->id(), $event['binding']['principal']);
    }

    public function test_native_shell_and_witness_authorize_without_building_business_projection(): void
    {
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $path = '/workspaces/'.$this->workspace->public_id.'/business';
        $this->get($path)->assertOk()->assertViewIs('spa')->assertSee('application-bootstrap', false);
        $this->getJson($path, ['X-Builderad-Witness' => '1'])->assertOk()
            ->assertJsonStructure(['visibility' => ['protected', 'path', 'context']])
            ->assertJsonMissingPath('data');
        foreach (['business_target_markets', 'country_references', 'pg_timezone_names', 'current_intent', 'business_types'] as $projection) {
            $this->assertFalse(collect($queries)->contains(fn ($sql) => str_contains($sql, $projection)), $projection);
        }
        $this->actingAs(User::factory()->create())->getJson($path, ['X-Builderad-Witness' => '1'])->assertForbidden();
    }
}
