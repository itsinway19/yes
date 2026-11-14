<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveLocale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorkspaceSpaIntegrationTest extends TestCase
{
    public function test_retired_workspace_routes_have_no_parallel_browser_or_json_resource(): void
    {
        $this->actingAs(User::factory()->create());
        $id = (string) Str::uuid7();
        foreach (['/workspaces', '/workspaces/'.$id, '/organizations/new'] as $path) {
            $this->get($path)->assertNotFound();
            $this->getJson($path)->assertNotFound();
        }
        $this->postJson('/workspaces', ['name' => 'Parallel authority'])->assertNotFound();
        $this->assertNull(DB::connection('pgsql_migrations')->scalar("SELECT to_regclass('public.workspaces')"));
    }

    public function test_business_catalog_passive_and_witness_reads_preserve_session_deadlines(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor)->postJson('/api/businesses', [
            'command_id' => (string) Str::uuid7(), 'name' => 'Witness Business',
        ])->assertCreated();
        $path = '/businesses';
        $resource = $this->getJson($path)->assertOk();
        $owner = DB::connection('pgsql_migrations');
        $before = $owner->table('security_sessions')->where('user_id', $actor->id)->sole();
        $this->travel(30)->seconds();
        $passive = $this->getJson($path, ['X-Builderad-Passive' => '1'])->assertOk();
        $witness = $this->getJson($path, ['X-Builderad-Witness' => '1'])->assertOk()
            ->assertJsonPath('visibility.protected', true)->assertJsonMissingPath('data');
        $this->assertSame($passive->json('visibility'), $witness->json('visibility'));
        $this->assertSame($resource->json('visibility'), $witness->json('visibility'));
        $this->assertStringContainsString('no-store', $witness->headers->get('Cache-Control'));
        $after = $owner->table('security_sessions')->where('id', $before->id)->sole();
        $this->assertSame($before->idle_expires_at, $after->idle_expires_at);
        $this->assertSame($before->absolute_expires_at, $after->absolute_expires_at);
    }

    public function test_json_locale_acknowledgement_keeps_the_existing_cookie_contract(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/locale', ['locale' => 'en'])->assertOk()
            ->assertJsonPath('locale', 'en')->assertCookie(ResolveLocale::COOKIE, 'en');
        $this->postJson('/locale', ['locale' => 'fr'])->assertUnprocessable()->assertJsonValidationErrors('locale');
    }
}
