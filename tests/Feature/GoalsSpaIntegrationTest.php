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
}
