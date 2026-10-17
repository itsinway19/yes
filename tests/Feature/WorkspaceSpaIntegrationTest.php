<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveLocale;
use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class WorkspaceSpaIntegrationTest extends TestCase
{
    public function test_creation_uses_the_existing_atomic_idempotent_writer_and_private_shell(): void
    {
        $user = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($user, 1);
        $this->actingAs($user);
        $owner = DB::connection('pgsql_migrations');
        $type = $owner->table('business_types')->where('key', 'real_estate')->value('id');
        $owner->table('business_types')->where('id', $type)->update(['active' => true]);
        $this->get('/business/start')->assertOk()->assertSee('application-bootstrap', false)->assertDontSee('data-page=', false);
        $projection = $this->getJson('/business/start')->assertOk()->json('data');
        $command = ['name' => 'Private SPA business', 'business_type_id' => $type, 'creation_key' => $projection['creationKey']];
        $this->postJson('/business/start', [...$command, 'business_type_id' => 999999])->assertUnprocessable();
        $this->assertSame(0, $owner->table('workspaces')->count());
        $first = $this->postJson('/business/start', $command)->assertOk()->json();
        $this->assertSame($first, $this->postJson('/business/start', $command)->assertOk()->json());
        $workspace = $owner->table('workspaces')->sole();
        $this->assertSame('/workspaces/'.$workspace->public_id.'/goals', $first['redirect']);
        $this->assertSame(1, $owner->table('workspaces')->count());
        $this->assertSame(1, $owner->table('business_context_events')->count());
        $this->assertSame($type, $workspace->business_type_id);
        $this->get('/workspaces')->assertOk()->assertSee('application-spa', false)->assertDontSee($workspace->name);
        $this->getJson('/workspaces')->assertOk()->assertJsonPath('data.workspaces.0.public_id', $workspace->public_id);
        $owner->table('security_action_grants')->where('subject_user_id', $user->id)
            ->where('action', 'workspace.create')->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->postJson('/business/start', $command)->assertForbidden();
        $this->assertSame(1, $owner->table('workspaces')->count());
        $this->get('/contracts/application.openapi.json')->assertNotFound();
    }

    public function test_creation_grant_withdrawal_cannot_be_bypassed_by_the_spa_contract(): void
    {
        $user = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($user, 1);
        $this->actingAs($user);
        $projection = $this->getJson('/business/start')->assertOk()->json('data');
        $owner = DB::connection('pgsql_migrations');
        $type = $owner->table('business_types')->where('key', 'real_estate')->value('id');
        $owner->table('business_types')->where('id', $type)->update(['active' => true]);
        $owner->table('security_action_grants')->where('subject_user_id', $user->id)
            ->where('action', 'workspace.create')->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->getJson('/business/start')->assertForbidden();
        $this->postJson('/business/start', ['name' => 'Denied creation', 'business_type_id' => $type,
            'creation_key' => $projection['creationKey']])->assertForbidden();
        $this->assertSame(0, $owner->table('workspaces')->count());
    }

    public function test_passive_and_witness_reads_preserve_canonical_session_deadlines(): void
    {
        $user = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($user, 1);
        $this->actingAs($user)->getJson('/workspaces')->assertOk();
        $owner = DB::connection('pgsql_migrations');
        $before = $owner->table('security_sessions')->where('user_id', $user->id)->sole();
        $this->travel(30)->seconds();
        $this->getJson('/workspaces', ['X-Builderad-Passive' => '1'])->assertOk();
        $witness = $this->getJson('/workspaces', ['X-Builderad-Witness' => '1'])->assertOk()
            ->assertJsonPath('visibility.protected', true)->assertJsonMissingPath('data');
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
