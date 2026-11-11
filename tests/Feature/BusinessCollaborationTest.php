<?php

namespace Tests\Feature;

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Security\DisposableTestDatabaseGuard;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\BusinessAuthorityFixture;
use Tests\TestCase;

final class BusinessCollaborationTest extends TestCase
{
    use ApplicationContractAssertions;

    private function fixture(): array
    {
        $actor = User::factory()->create();
        $this->actingAs($actor)->withCredentials();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $this->legacyWorkspace(['name' => 'Collaboration only', 'slug' => 'collaboration-owner'])->assertRedirect();
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

    private function historicalLink(array $business, object $workspace): object
    {
        $db = DB::connection('pgsql_migrations');
        $root = $db->table('security_action_grants')->where('id', $business['grant_refs']['business.link.manage'])->sole();

        return BusinessAuthorityFixture::legacyWorkspaceLink($root->business_id, $workspace->id, $root, $root->subject_user_id, 'collaboration');
    }

    public function test_retired_add_receipt_replays_without_recreating_a_relationship_and_qualified_end_remains_cas_guarded(): void
    {
        [$actor, $workspace, $business] = $this->fixture();
        $link = $this->historicalLink($business, $workspace);
        $db = DB::connection('pgsql_migrations');
        $b = $db->table('businesses')->where('public_id', $business['business_public_id'])->value('id');
        $body = $this->link($business, $workspace->public_id, 'add', 0);
        $original = ['business_public_id' => $business['business_public_id'], 'workspace_public_id' => $workspace->public_id,
            'kind' => 'collaboration', 'state' => 'active', 'versions' => ['link' => 1]];
        // Explicit synthetic historical receipt, using the canonical codec and
        // receipt writer in the already guarded disposable test database.
        app(DisposableTestDatabaseGuard::class)->assertSafe();
        $payload = $db->transaction(function () use ($db, $business, $workspace): string {
            $db->statement('SET LOCAL ROLE builderad_scope_executor');

            return $db->scalar(<<<'SQL'
            SELECT encode(builderad_private.digest('command-payload:1',?::jsonb,'{"business":"U","workspace":"U","expected":"I"}'),'hex')
            SQL, [json_encode(['business' => $business['business_public_id'], 'workspace' => $workspace->public_id, 'effect' => 'add', 'expected' => 0, 'kind' => 'collaboration'])]);
        });
        BusinessAuthorityFixture::insert('business_command_receipts', ['command_id' => $body['command_id'], 'business_id' => (int) $b,
            'actor_user_id' => $actor->id, 'generation' => $db->table('principal_lifecycles')->where('user_id', $actor->id)->value('generation'),
            'action' => 'business.link.manage', 'payload_digest' => '\\x'.$payload, 'state' => 'committed',
            'result_ref' => $original, 'returned_versions' => ['link' => 1], 'recorded_at' => $db->scalar('SELECT clock_timestamp()::text')], $actor->id);
        $url = '/api/businesses/'.$business['business_public_id'].'/workspace-links';
        $this->postJson($url, $this->link($business, $workspace->public_id, 'end', 0))->assertConflict();
        $end = $this->link($business, $workspace->public_id, 'end', 1);
        $ended = $this->postJson($url, $end)->assertOk()->json('data');
        $this->assertSame('ended', $ended['state']);
        $this->assertSame($ended, $this->postJson($url, $end)->assertOk()->json('data'));
        $replayed = $this->postJson($url, $body)->assertOk()->json('data');
        ksort($original);
        ksort($replayed);
        $this->assertSame($original, $replayed);
        $this->assertSame(1, $db->table('business_workspace_links')->count());
        $this->assertSame('ended', $db->table('business_workspace_links')->where('link_id', $link->link_id)->value('status'));
        $this->assertSame(2, $db->table('business_workspace_links')->where('link_id', $link->link_id)->value('revision'));
        $this->postJson($url, [...$body, 'expected_revision' => 2])->assertConflict();
        $this->postJson($url, [...$body, 'command_id' => (string) Str::uuid(), 'expected_revision' => 2])->assertForbidden();
        $this->actingAs(User::factory()->create())->postJson($url, $body)->assertForbidden();
    }

    public function test_a_prepared_end_cannot_end_a_different_recreated_relationship_with_the_same_old_revision(): void
    {
        [, $workspace, $business] = $this->fixture();
        $url = '/api/businesses/'.$business['business_public_id'].'/workspace-links';
        $this->assertSame(1, $this->historicalLink($business, $workspace)->revision);
        $preparedOldEnd = $this->link($business, $workspace->public_id, 'end', 1);
        $this->postJson($url, $this->link($business, $workspace->public_id, 'end', 1))->assertOk();
        $this->postJson($url, $this->link($business, $workspace->public_id, 'add', 0))->assertForbidden();
        $this->postJson($url, $this->link($business, $workspace->public_id, 'add', 2))->assertForbidden();
        $this->assertSame(3, $this->historicalLink($business, $workspace)->revision);
        $rejected = $this->postJson($url, $preparedOldEnd)->assertConflict()->assertJsonPath('error', 'business_relation_revision_rejected');
        $this->assertApplicationContract('BusinessFoundationError', $rejected->json());
        $active = DB::connection('pgsql_migrations')->table('business_workspace_links')->where('status', 'active')->sole();
        $this->assertSame(3, $active->revision);
        $this->assertSame(2, DB::connection('pgsql_migrations')->table('business_workspace_links')->count());
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('business_command_receipts')->where('command_id', $preparedOldEnd['command_id'])->count());
    }

    public function test_business_projection_does_not_discover_workspace_profiles_or_collaboration_identifiers(): void
    {
        [$actor, $workspace, $business] = $this->fixture();
        $db = DB::connection('pgsql_migrations');
        $cohort = $db->table('migration_cohort_members')->where('workspace_id', $workspace->id)->value('cohort_id');
        $db->scalar("SELECT builderad_v2_cohort_transition(?::uuid,?::uuid,'LEGACY_ACTIVE',0,'DRAINING',NULL,decode(?,'hex'))", [(string) Str::uuid(), $cohort, hash('sha256', 'isolated-retired-metadata')]);
        $url = '/api/businesses/'.$business['business_public_id'].'/surface?access='.$business['grant_refs']['business.context.read'];
        $response = $this->getJson($url)->assertOk()->assertJsonMissingPath('data.collaboration');
        $this->assertApplicationContract('BusinessSurfaceResult', $response->json());
        $this->assertStringNotContainsString('Collaboration only', $response->getContent());
        $this->assertStringNotContainsString('collaboration-owner', $response->getContent());
        DB::transaction(function () use ($actor, $workspace): void {
            app(PrincipalContext::class)->activate($actor);
            $this->assertSame(0, DB::table('workspaces')->where('id', $workspace->id)->count());
        });
        $this->postJson('/api/businesses/'.$business['business_public_id'].'/workspace-links', $this->link($business, $workspace->public_id, 'add', 0))->assertForbidden();
        $this->historicalLink($business, $workspace);
        $this->getJson($url)->assertOk()->assertJsonMissingPath('data.collaboration');
    }

    public function test_workspace_authority_withdrawal_does_not_change_independent_business_authority(): void
    {
        [$actor, $workspace, $business] = $this->fixture();
        $url = '/api/businesses/'.$business['business_public_id'].'/surface?access='.$business['grant_refs']['business.context.read'];
        $before = $this->getJson($url)->assertOk()->assertJsonMissingPath('data.collaboration')->json('data.scope.authority_fingerprint');
        $db = DB::connection('pgsql_migrations');
        // Explicit isolated native-authority withdrawal, not a new customer-facing issuer policy.
        $db->table('security_action_grants')->where('scope_kind', 'workspace')->where('workspace_id', $workspace->id)->where('subject_user_id', $actor->id)
            ->where('action', 'workspace.view')->update(['status' => 'revoked', 'revoked_at' => now(), 'version' => 2]);
        $this->getJson($url)->assertOk()->assertJsonMissingPath('data.collaboration')->assertJsonPath('data.business.name', 'Independent relationship subject')
            ->assertJsonPath('data.scope.authority_fingerprint', $before);
        $this->postJson('/api/businesses/'.$business['business_public_id'].'/workspace-links', $this->link($business, $workspace->public_id, 'add', 0))->assertForbidden();
        $this->assertSame(0, $db->table('business_workspace_links')->count());
        $this->actingAs(User::factory()->create())->getJson($url)->assertForbidden();
    }

    public function test_workspace_withdrawal_during_real_business_authority_wait_does_not_reintroduce_workspace_dependency(): void
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
            $db->table('security_action_grants')->where('id', $business['grant_refs']['business.context.read'])->lockForUpdate()->first();
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
            $this->assertArrayNotHasKey('collaboration', $read);
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
