<?php

namespace Tests\Feature;

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BusinessContextLockOrderTest extends TestCase
{
    public function test_identical_name_type_replay_preserves_revision_and_audit_even_with_stale_expected_version(): void
    {
        $actor = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $this->actingAs($actor)->post('/workspaces', ['name' => 'Business lock', 'slug' => 'business-lock'])->assertRedirect();
        $owner = DB::connection('pgsql_migrations');
        $workspace = $owner->table('workspaces')->where('slug', 'business-lock')->first();
        $input = ['business_type_id' => $owner->table('business_types')->value('id'), 'version' => 0];
        $url = '/workspaces/'.$workspace->public_id.'/business';
        $this->post($url, $input)->assertRedirect();
        $before = $owner->table('workspaces')->where('id', $workspace->id)->first();
        $events = $owner->table('business_context_events')->where('workspace_id', $workspace->id)->get()->all();
        $this->post($url, $input)->assertRedirect();
        $this->assertEquals($before, $owner->table('workspaces')->where('id', $workspace->id)->first());
        $this->assertEquals($events, $owner->table('business_context_events')->where('workspace_id', $workspace->id)->get()->all());
        $this->assertCount(1, $events);
        $this->assertSame(1, (int) $before->business_context_version);
    }

    public function test_unsigned_and_foreign_scope_cannot_take_mutation_entry_lock(): void
    {
        $actor = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $this->actingAs($actor)->post('/workspaces', ['name' => 'Business lock', 'slug' => 'business-lock'])->assertRedirect();
        $workspace = DB::connection('pgsql_migrations')->table('workspaces')->where('slug', 'business-lock')->first();
        $foreign = User::factory()->create();
        foreach ([null, $foreign] as $principal) {
            try {
                DB::transaction(function () use ($principal, $workspace): void {
                    if ($principal) {
                        app(PrincipalContext::class)->activate($principal);
                    }
                    DB::selectOne('SELECT builderad_business_context_begin(?,?)', [$workspace->id, 'forged']);
                });
                $this->fail('Unsigned or foreign mutation entry accepted.');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->getCode());
            }
        }
        $this->assertSame(0, (int) DB::connection('pgsql_migrations')->table('workspaces')->where('id', $workspace->id)->value('business_context_version'));
    }

    public function test_caller_cannot_forge_prepared_lock_order_evidence(): void
    {
        $actor = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $this->actingAs($actor)->post('/workspaces', ['name' => 'Business lock', 'slug' => 'business-lock'])->assertRedirect();
        $owner = DB::connection('pgsql_migrations');
        $workspace = $owner->table('workspaces')->where('slug', 'business-lock')->first();
        $session = $owner->table('security_sessions')->where('user_id', $actor->id)->value('session_key_hash');
        $type = $owner->table('business_types')->value('id');
        try {
            DB::transaction(function () use ($actor, $workspace, $session, $type): void {
                app(PrincipalContext::class)->activate($actor);
                DB::selectOne('SELECT builderad_select_workspace_context(?)', [$workspace->id]);
                DB::selectOne("SELECT set_config('app.business_context_prepared','forged',true)");
                DB::scalar('SELECT builderad_business_context_write(?,?,?,?,?)', [$workspace->id, $session, $type, $workspace->name, 0]);
            });
            $this->fail('Caller forged mutation sequencing evidence.');
        } catch (QueryException $error) {
            $this->assertSame('42501', $error->getCode());
        }
        $this->assertSame(0, $owner->table('business_context_events')->where('workspace_id', $workspace->id)->count());
    }
}
