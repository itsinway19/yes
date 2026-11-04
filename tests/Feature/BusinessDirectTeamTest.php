<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\DisposableDatabaseReset;
use Tests\TestCase;

final class BusinessDirectTeamTest extends TestCase
{
    use ApplicationContractAssertions;

    private function selection(int|string $grant): array
    {
        return ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
            'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => (int) $grant]]];
    }

    private function file(User $creator, string $name = 'Independent advertiser'): array
    {
        return $this->actingAs($creator)->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => $name])
            ->assertCreated()->json('data');
    }

    private function issue(array $roots, User $recipient, string $action): array
    {
        return ['command_id' => (string) Str::uuid(), 'authority_selection' => $this->selection($roots[$action]),
            'effect' => 'issue', 'grantee_public_id' => $recipient->public_id, 'action' => $action,
            'parent_grant_id' => (int) $roots[$action], 'grant_id' => null, 'expected_grant' => 0,
            'expires_at' => null, 'delegable' => false, 'realm_workspace_public_id' => null];
    }

    public function test_creator_can_add_staff_to_the_same_business_without_any_workspace_and_surrender_is_exact(): void
    {
        $creator = User::factory()->create();
        $employee = User::factory()->create();
        $other = User::factory()->create();
        $file = $this->file($creator);
        $foreign = $this->file($other, 'Unrelated business');
        $this->actingAs($creator);
        $base = '/api/businesses/'.$file['business_public_id'];
        $command = ['command_id' => (string) Str::uuid(), 'authority_selection' => $this->selection($file['grant_refs']['business.context.read'])];
        $receipt = $this->postJson($base.'/direct-team', $command)->assertOk()->json('data');
        $this->postJson($base.'/direct-team', $command)->assertOk()->assertJsonPath('data.grant_refs', $receipt['grant_refs']);
        $roots = $receipt['grant_refs'];
        $this->assertCount(5, $roots);
        $administration = $this->getJson($base.'/surface?access='.$file['grant_refs']['business.context.read'])->assertOk()->json('data.delegation.options');
        $this->assertCount(5, $administration);
        foreach ($administration as $option) {
            $this->assertFalse($option['can_delegate_onward']);
            $this->assertFalse($option['can_choose_agency']);
        }
        $grants = [];
        foreach (['business.context.read', 'business.context.manage'] as $action) {
            $body = $this->issue($roots, $employee, $action);
            $grants[$action] = $this->postJson($base.'/grants', $body)->assertOk()->json('data.grant_id');
            $this->postJson($base.'/grants', $body)->assertOk()->assertJsonPath('data.grant_id', $grants[$action]);
        }
        $surface = $this->actingAs($employee)->getJson($base.'/surface?access='.$grants['business.context.read'])->assertOk()->json('data');
        $this->assertApplicationContract('BusinessSurface', $surface);
        $this->assertFalse($surface['private_history_available']);
        $this->assertFalse($surface['direct_team_enrollment_available']);
        $this->assertSame((string) $grants['business.context.manage'], $surface['context_write_options'][0]['grant_id']);
        $write = ['command_id' => (string) Str::uuid(), 'authority_selection' => $this->selection($grants['business.context.manage']),
            'name' => 'Edited by employee', 'business_type_id' => null, 'expected_context' => 0];
        $this->putJson($base.'/context', $write)->assertOk();
        $this->getJson($base.'/history?access='.$grants['business.context.read'].'&kind=context')->assertForbidden();
        $this->getJson($base.'/legacy-history?access='.$grants['business.context.read'].'&kind=context')->assertForbidden();
        $this->getJson($base.'/inquiry-evidence/eligibility?access='.$grants['business.context.read'])->assertForbidden();
        $this->getJson('/api/businesses/'.$foreign['business_public_id'].'/surface?access='.$grants['business.context.read'])->assertForbidden();
        $this->postJson($base.'/direct-team', ['command_id' => (string) Str::uuid(), 'authority_selection' => $this->selection($grants['business.context.read'])])->assertForbidden();
        $this->postJson($base.'/grants', $this->issue($grants, $other, 'business.context.read'))->assertForbidden();
        $surrender = [...$this->issue($grants, $employee, 'business.context.read'), 'effect' => 'revoke',
            'parent_grant_id' => null, 'grant_id' => (int) $grants['business.context.read'], 'expected_grant' => 1];
        $this->postJson($base.'/grants', $surrender)->assertOk();
        $this->getJson($base.'/surface?access='.$grants['business.context.read'])->assertForbidden();
        $this->actingAs($creator)->getJson($base.'/surface?access='.$file['grant_refs']['business.context.read'])
            ->assertOk()->assertJsonPath('data.business.name', 'Edited by employee')->assertJsonPath('data.private_history_available', true);
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('workspaces')->count());
    }

    public function test_direct_team_cannot_expand_rights_or_realm_and_revoking_parent_invalidates_staff(): void
    {
        $creator = User::factory()->create();
        $employee = User::factory()->create();
        $file = $this->file($creator);
        $base = '/api/businesses/'.$file['business_public_id'];
        $roots = $this->postJson($base.'/direct-team', ['command_id' => (string) Str::uuid(),
            'authority_selection' => $this->selection($file['grant_refs']['business.context.read'])])->assertOk()->json('data.grant_refs');
        $body = $this->issue($roots, $employee, 'business.context.read');
        $this->postJson($base.'/grants', [...$body, 'delegable' => true])->assertForbidden();
        $this->postJson($base.'/grants', [...$body, 'realm_workspace_public_id' => (string) Str::uuid7()])->assertForbidden();
        $this->postJson($base.'/grants', [...$body, 'action' => 'business.data.use'])->assertForbidden();
        $staff = $this->postJson($base.'/grants', $body)->assertOk()->json('data.grant_id');
        $this->postJson($base.'/grants', [...$body, 'command_id' => (string) Str::uuid(), 'effect' => 'revoke',
            'grantee_public_id' => $creator->public_id, 'parent_grant_id' => null, 'grant_id' => (int) $roots['business.context.read'],
            'expected_grant' => 1])->assertOk();
        $this->actingAs($employee)->getJson($base.'/surface?access='.$staff)->assertForbidden();
    }

    public function test_populated_upgrade_preserves_existing_facts_rights_and_history_without_auto_enrollment(): void
    {
        $reset = app(DisposableDatabaseReset::class);
        $reset->clear();
        try {
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => strcmp(basename($path), '0001_01_01_000570_business_declaration_team.php') < 0));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--path' => $paths, '--realpath' => true, '--force' => true]));
            $creator = User::factory()->create();
            $file = $this->file($creator);
            $db = DB::connection('pgsql_migrations');
            $tables = ['businesses', 'business_context_heads', 'business_workspace_links', 'authority_qualification_receipts', 'security_action_grants', 'business_command_receipts'];
            $before = [];
            foreach ($tables as $table) {
                $before[$table] = $db->scalar("SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]')::text FROM {$table} r");
            }
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            foreach ($tables as $table) {
                $this->assertSame($before[$table], $db->scalar("SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]')::text FROM {$table} r"));
            }
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            $this->getJson('/api/businesses/'.$file['business_public_id'].'/surface?access='.$file['grant_refs']['business.context.read'])
                ->assertOk()->assertJsonPath('data.direct_team_enrollment_available', true)->assertJsonPath('data.private_history_available', true);
        } finally {
            $reset->clear();
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
        }
    }
}
