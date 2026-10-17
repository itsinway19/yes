<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureCurrentPrincipal;
use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class GoalsSpaIntegrationTest extends TestCase
{
    public function test_enabled_shell_and_reads_use_the_real_web_routes_and_existing_writer(): void
    {
        // Register the opt-in production route file even if the suite booted disabled.
        if (! Route::has('workspaces.goals.spa.shell')) {
            Route::middleware('web')->group(base_path('routes/goals-spa.php'));
            Route::getRoutes()->refreshNameLookups();
        }
        $user = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($user, 1);
        $this->actingAs($user)->post('/workspaces', ['name' => 'Integrated Goals', 'slug' => 'integrated-goals'])->assertRedirect();
        $workspace = DB::connection('pgsql_migrations')->table('workspaces')->where('slug', 'integrated-goals')->first();
        $canonical = '/workspaces/'.$workspace->public_id.'/goals';
        $this->withHeader('X-Builderad-Transition', '');
        $this->get('/spa/workspaces/'.$workspace->public_id.'/goals')->assertOk()
            ->assertSee('goals-bootstrap', false)->assertDontSee('data-page=', false);
        $this->assertSame($this->getJson($canonical)->assertOk()->json(),
            $this->getJson('/spa/api/workspaces/'.$workspace->public_id.'/goals')->assertOk()->json('preferences'));
        $this->postJson($canonical, ['value' => AdvertiserGoalPreferencesTest::value(), 'version' => 0])->assertOk();
        $this->postJson($canonical, ['value' => AdvertiserGoalPreferencesTest::value(), 'version' => 0])->assertStatus(409);
        foreach (['workspaces.goals.spa.shell', 'workspaces.goals.spa.read', 'workspaces.goals.update'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains(EnsureCurrentPrincipal::class, $route->gatherMiddleware());
            $this->assertStringStartsWith('workspaces.goals.', $route->getName());
        }
    }

    public function test_internal_contract_has_no_documentation_route_or_public_artifact(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['/contracts/goals.openapi.json', '/openapi.json', '/docs/api', '/api/documentation'] as $url) {
            $this->getJson($url)->assertNotFound();
        }
        $this->assertFileDoesNotExist(public_path('contracts/goals.openapi.json'));
        $this->assertFileDoesNotExist(public_path('openapi.json'));
    }

    public function test_canonical_editor_review_and_context_share_the_existing_writer_and_scope(): void
    {
        $user = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($user, 1);
        $this->actingAs($user)->post('/workspaces', ['name' => 'Canonical private goals', 'slug' => 'canonical-goals'])->assertRedirect();
        $owner = DB::connection('pgsql_migrations');
        $workspace = $owner->table('workspaces')->where('slug', 'canonical-goals')->sole();
        $path = '/workspaces/'.$workspace->public_id.'/goals';
        foreach (['', '/review'] as $suffix) {
            $this->get($path.$suffix)->assertOk()->assertSee('application-bootstrap', false)
                ->assertDontSee('data-page=', false)->assertDontSee($workspace->name);
        }
        $projection = $this->getJson($path.'/context')->assertOk()->json();
        $this->assertSame($this->getJson($path)->assertOk()->json(), $projection['preferences']);
        $this->assertCount(4, $projection['catalog']);
        $this->postJson($path, ['value' => AdvertiserGoalPreferencesTest::value(), 'version' => 0])->assertOk();
        $this->getJson($path.'/context')->assertOk()->assertJsonPath('preferences.version', 1);
        $before = $owner->table('security_sessions')->where('user_id', $user->id)->sole();
        $this->travel(30)->seconds();
        $this->getJson($path.'/context', ['X-Goals-Passive' => '1'])->assertOk();
        $this->getJson($path.'/context', ['X-Goals-Witness' => '1'])->assertOk()->assertJsonMissingPath('preferences');
        $after = $owner->table('security_sessions')->where('id', $before->id)->sole();
        $this->assertSame($before->idle_expires_at, $after->idle_expires_at);
        $this->assertSame($before->absolute_expires_at, $after->absolute_expires_at);
        $owner->table('security_action_grants')->where('subject_user_id', $user->id)->where('workspace_id', $workspace->id)
            ->where('action', 'business.context.manage')->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->getJson($path.'/context')->assertOk()->assertJsonPath('business.canManage', false);
        $this->postJson($path, ['value' => AdvertiserGoalPreferencesTest::value(), 'version' => 1])->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson($path.'/context')->assertForbidden();
        $this->getJson('/workspaces/not-a-uuid/goals/context')->assertNotFound();
    }
}
