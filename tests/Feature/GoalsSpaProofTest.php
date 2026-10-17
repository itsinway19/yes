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
        $api = '/spa/api/workspaces/'.$id.'/goals';
        $projection = $this->getJson($api)->assertOk()->json();
        $this->assertSame($this->getJson($original)->assertOk()->json(), $projection['preferences']);
        $this->assertCount(4, $projection['catalog']);
        $this->postJson($original, ['value' => AdvertiserGoalPreferencesTest::value(), 'version' => 0])->assertOk();
        $this->getJson($api)->assertOk()->assertJsonPath('preferences.version', 1);
        $this->postJson($original, ['value' => AdvertiserGoalPreferencesTest::value(), 'version' => 0])->assertStatus(409);
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('advertiser_goal_preference_events')->count());
        $this->get('/spa/workspaces/'.$id.'/goals')->assertOk()->assertSee('goals-bootstrap', false)->assertDontSee('data-page=', false);
    }

    public function test_foreign_workspace_cannot_be_read_or_streamed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $id = $this->workspace->public_id;
        $this->getJson('/spa/api/workspaces/'.$id.'/goals')->assertForbidden();
        $this->getJson('/spa/api/workspaces/'.$id.'/goals/events')->assertForbidden();
        $this->postJson('/workspaces/'.$id.'/goals', ['value' => AdvertiserGoalPreferencesTest::value(), 'version' => 0])->assertForbidden();
    }

    public function test_restoration_and_witness_reads_do_not_extend_idle_authority(): void
    {
        $db = DB::connection('pgsql_migrations');
        $before = $db->table('security_sessions')->first();
        $url = '/spa/api/workspaces/'.$this->workspace->public_id.'/goals';
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
        $response = $this->getJson('/spa/api/workspaces/'.$id.'/goals/events')->assertOk();
        $this->assertStringStartsWith('text/event-stream', $response->headers->get('Content-Type'));
        $event = json_decode(substr(trim($response->streamedContent()), 6), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($id, $event['workspace']);
        $this->assertSame(0, $event['version']);
        $this->assertSame(auth()->id(), $event['binding']['principal']);
    }
}
