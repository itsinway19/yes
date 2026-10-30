<?php

namespace Tests\Feature;

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ApplicationContractAssertions;
use Tests\TestCase;

final class BusinessCollaborationTest extends TestCase
{
    use ApplicationContractAssertions;

    private function fixture(): array
    {
        $actor = User::factory()->create();
        $this->actingAs($actor)->withCredentials();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $this->post('/workspaces', ['name' => 'Collaboration only', 'slug' => 'collaboration-owner'])->assertRedirect();
        $workspace = DB::connection('pgsql_migrations')->table('workspaces')->where('slug', 'collaboration-owner')->first();
        $business = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Independent relationship subject'])->assertCreated()->json('data');

        return [$actor, $workspace, $business];
    }

    private function selection(array $business, string $action = 'business.link.manage'): array
    {
        return ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
            'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $business['grant_refs'][$action]]]];
    }

    private function link(array $business, string $workspace, string $effect, int $expected): array
    {
        return ['command_id' => (string) Str::uuid(), 'workspace_public_id' => $workspace, 'kind' => 'collaboration',
            'effect' => $effect, 'expected_revision' => $expected, 'authority_selection' => $this->selection($business)];
    }

    public function test_a_prepared_end_cannot_end_a_different_recreated_relationship_with_the_same_old_revision(): void
    {
        [, $workspace, $business] = $this->fixture();
        $url = '/api/businesses/'.$business['business_public_id'].'/workspace-links';
        $this->postJson($url, $this->link($business, $workspace->public_id, 'add', 0))->assertOk()->assertJsonPath('data.versions.link', 1);
        $preparedOldEnd = $this->link($business, $workspace->public_id, 'end', 1);
        $this->postJson($url, $this->link($business, $workspace->public_id, 'end', 1))->assertOk();
        $this->postJson($url, $this->link($business, $workspace->public_id, 'add', 0))->assertConflict();
        $this->postJson($url, $this->link($business, $workspace->public_id, 'add', 2))->assertOk()->assertJsonPath('data.versions.link', 3);
        $rejected = $this->postJson($url, $preparedOldEnd)->assertConflict()->assertJsonPath('error', 'business_relation_revision_rejected');
        $this->assertApplicationContract('BusinessFoundationError', $rejected->json());
        $active = DB::connection('pgsql_migrations')->table('business_workspace_links')->where('status', 'active')->sole();
        $this->assertSame(3, $active->revision);
        $this->assertSame(2, DB::connection('pgsql_migrations')->table('business_workspace_links')->count());
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('business_command_receipts')->where('command_id', $preparedOldEnd['command_id'])->count());
    }

    public function test_retired_profile_stays_closed_while_exact_collaboration_identifier_is_available(): void
    {
        [$actor, $workspace, $business] = $this->fixture();
        $db = DB::connection('pgsql_migrations');
        $cohort = $db->table('migration_cohort_members')->where('workspace_id', $workspace->id)->value('cohort_id');
        $db->scalar("SELECT builderad_v2_cohort_transition(?::uuid,?::uuid,'LEGACY_ACTIVE',0,'DRAINING',NULL,decode(?,'hex'))", [(string) Str::uuid(), $cohort, hash('sha256', 'isolated-retired-metadata')]);
        $url = '/api/businesses/'.$business['business_public_id'].'/surface?access='.$business['grant_refs']['business.context.read'];
        $response = $this->getJson($url)->assertOk()->assertJsonPath('data.collaboration.workspaces.0.slug', 'collaboration-owner')
            ->assertJsonMissingPath('data.collaboration.workspaces.0.name')->assertJsonPath('data.collaboration.options.0.allows_agency', false);
        $this->assertApplicationContract('BusinessSurfaceResult', $response->json());
        $this->assertStringNotContainsString('Collaboration only', $response->getContent());
        DB::transaction(function () use ($actor, $workspace): void {
            app(PrincipalContext::class)->activate($actor);
            $this->assertSame(0, DB::table('workspaces')->where('id', $workspace->id)->count());
        });
        $this->postJson('/api/businesses/'.$business['business_public_id'].'/workspace-links', $this->link($business, $workspace->public_id, 'add', 0))->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.collaboration.relationships.0.workspace_slug', 'collaboration-owner')
            ->assertJsonMissingPath('data.collaboration.relationships.0.workspace_name');
    }

    public function test_workspace_authority_withdrawal_removes_only_collaboration_actions_without_business_fallback(): void
    {
        [$actor, $workspace, $business] = $this->fixture();
        $url = '/api/businesses/'.$business['business_public_id'].'/surface?access='.$business['grant_refs']['business.context.read'];
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data.collaboration.workspaces');
        $db = DB::connection('pgsql_migrations');
        // Explicit isolated native-authority withdrawal, not a new customer-facing issuer policy.
        $db->table('security_action_grants')->where('scope_kind', 'workspace')->where('workspace_id', $workspace->id)->where('subject_user_id', $actor->id)
            ->where('action', 'workspace.view')->update(['status' => 'revoked', 'revoked_at' => now(), 'version' => 2]);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data.collaboration.workspaces')->assertJsonPath('data.business.name', 'Independent relationship subject');
        $this->postJson('/api/businesses/'.$business['business_public_id'].'/workspace-links', $this->link($business, $workspace->public_id, 'add', 0))->assertForbidden();
        $this->assertSame(0, $db->table('business_workspace_links')->count());
        $this->actingAs(User::factory()->create())->getJson($url)->assertForbidden();
    }

    public function test_workspace_metadata_withdrawal_during_actual_authority_wait_is_rechecked_before_readback(): void
    {
        [$actor, $workspace, $business] = $this->fixture();
        $request = Request::create('/api/businesses');
        $request->setLaravelSession(app('session')->driver());
        $run = 'collaboration-withdrawal-'.Str::uuid();
        $path = storage_path('framework/testing/'.$run.'.json');
        $release = $path.'.release';
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, json_encode(['run' => $run, 'operation' => 'surface', 'release' => $release, 'actor' => $actor->id,
            'session' => SessionAuthority::sessionKey($request), 'business' => $business['business_public_id'], 'read_grant' => $business['grant_refs']['business.context.read']], JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $path], base_path());
        $worker->setTimeout(45);
        $wait = function (callable $probe) use ($worker) {
            $until = microtime(true) + 25;
            do {
                if ($result = $probe()) {
                    return $result;
                }
                if (! $worker->isRunning()) {
                    $this->fail('Owned metadata worker ended before its required barrier: '.$worker->getOutput().$worker->getErrorOutput());
                }
                usleep(10000);
            } while (microtime(true) < $until);
            $this->fail('Actual collaboration authority wait was not observed.');
        };
        $db = DB::connection('pgsql_migrations');
        $evidence = ['scope' => 'actual native Workspace authority withdrawal while new metadata reader waits'];
        try {
            $worker->start();
            $wait(fn () => str_contains($worker->getOutput(), 'READY_PID='));
            $db->beginTransaction();
            $holder = (int) $db->scalar('SELECT pg_backend_pid()');
            $db->table('workspace_ownerships')->where('workspace_id', $workspace->id)->lockForUpdate()->first();
            file_put_contents($release, 'release');
            $evidence['wait'] = $wait(fn () => DB::selectOne("SELECT pid,backend_xid::text AS xid,wait_event_type,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock' AND ?=ANY(pg_blocking_pids(pid))", [$run, $holder]));
            $db->table('security_action_grants')->where('subject_user_id', $actor->id)->where('workspace_id', $workspace->id)->where('scope_kind', 'workspace')
                ->where('action', 'workspace.view')->update(['status' => 'revoked', 'revoked_at' => now(), 'version' => 2]);
            $db->commit();
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertMatchesRegularExpression('/COMMITTED=(.+)/', $worker->getOutput());
            preg_match('/COMMITTED=(.+)/', $worker->getOutput(), $match);
            $read = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame([], $read['collaboration']['workspaces']);
            $this->assertSame([], $read['collaboration']['relationships']);
            $this->assertSame('Independent relationship subject', $read['business']['name']);
        } finally {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $evidence['worker'] = ['stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput(), 'exit' => $worker->getExitCode()];
            file_put_contents(storage_path('framework/testing/'.$run.'-evidence.json'), json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            foreach ([$path, $release] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
