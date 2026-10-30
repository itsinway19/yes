<?php

namespace Tests\Feature;

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Security\SessionAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\BusinessAuthorityFixture;
use Tests\TestCase;

final class ClientBoundarySurfaceTest extends TestCase
{
    use ApplicationContractAssertions;

    private function draft(User $user, string $name): array
    {
        return $this->actingAs($user)->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => $name])->assertCreated()->json('data');
    }

    private function selection(array $boundary, ?array $business = null): array
    {
        $requirements = [['requirement_kind' => 'client_data', 'leaf_grant_id' => $boundary['grant_id']]];
        if ($business) {
            $requirements[] = ['requirement_kind' => 'business', 'leaf_grant_id' => $business['grant_refs']['business.link.manage']];
        }

        return ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => $requirements];
    }

    private function attach(array $boundary, array $business, int $revision = 0): array
    {
        return ['command_id' => (string) Str::uuid(), 'target_kind' => 'boundary_subject', 'target_ref' => $boundary['boundary_id'], 'effect' => 'attach_subject',
            'payload' => ['business_public_id' => $business['business_public_id'], 'branch_id' => null], 'expected_revision' => $revision,
            'authority_selection' => $this->selection($boundary, $business)];
    }

    public function test_choices_and_surface_release_only_independently_qualified_boundary_metadata(): void
    {
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        $a = $this->draft($owner, 'Private customer name');
        $boundary = BusinessAuthorityFixture::boundary($owner->id);
        $other = BusinessAuthorityFixture::boundary($foreign->id);
        $list = $this->getJson('/api/data-rights/boundaries')->assertOk()->assertJsonCount(1, 'data.boundaries');
        $this->assertApplicationContract('ClientBoundaryChoicesResult', $list->json());
        $this->assertSame($boundary['boundary_id'], $list->json('data.boundaries.0.boundary_id'));
        $url = '/api/data-rights/boundaries/'.$boundary['boundary_id'].'?access='.$boundary['grant_id'];
        $surface = $this->getJson($url)->assertOk()->assertJsonCount(0, 'data.memberships');
        $this->assertApplicationContract('ClientBoundarySurfaceResult', $surface->json());
        $surface->assertJsonMissingPath('data.boundary.descriptor');
        $this->assertStringNotContainsString('Private customer name', $surface->getContent());
        $page = '/data-rights/'.$boundary['boundary_id'].'?access='.$boundary['grant_id'];
        $before = $this->getJson($page)->assertOk()->json('visibility');
        $this->assertSame($before, $this->withHeader('X-Builderad-Witness', '1')->getJson($page)->assertOk()->json('visibility'));
        $this->getJson('/api/data-rights/boundaries/'.$other['boundary_id'].'?access='.$boundary['grant_id'])->assertForbidden();
        $this->getJson('/api/data-rights/boundaries/'.$boundary['boundary_id'].'?access='.$a['grant_refs']['business.context.read'])->assertForbidden();
        try {
            DB::transaction(function () use ($owner): void {
                app(PrincipalContext::class)->activate($owner);
                DB::table('client_data_boundaries')->count();
            });
            $this->fail('Raw boundary table reading must be denied, even for its qualified manager.');
        } catch (QueryException $error) {
            $this->assertSame('42501', $error->errorInfo[0]);
        }
        $this->actingAs($foreign)->getJson($url)->assertForbidden();
        $this->getJson('/api/data-rights/boundaries')->assertOk()->assertJsonCount(1, 'data.boundaries')->assertJsonPath('data.boundaries.0.boundary_id', $other['boundary_id']);
    }

    public function test_qualified_surface_preserves_exact_recreated_membership_and_no_business_name_leak(): void
    {
        $owner = User::factory()->create();
        $a = $this->draft($owner, 'Do not relabel private business context');
        $boundary = BusinessAuthorityFixture::boundary($owner->id);
        $url = '/api/data-rights/boundaries/'.$boundary['boundary_id'].'?access='.$boundary['grant_id'];
        $first = $this->postJson('/api/data-rights/commands', $this->attach($boundary, $a))->assertOk()->json('data');
        $read = $this->getJson($url)->assertOk()->assertJsonPath('data.memberships.0.membership_id', $first['membership_id']);
        $this->assertStringNotContainsString('Do not relabel private business context', $read->getContent());
        $detach = ['command_id' => (string) Str::uuid(), 'target_kind' => 'boundary_subject', 'target_ref' => $first['membership_id'], 'effect' => 'detach_subject',
            'payload' => new \stdClass, 'expected_revision' => 1, 'authority_selection' => $this->selection($boundary)];
        $this->postJson('/api/data-rights/commands', $detach)->assertOk();
        $second = $this->postJson('/api/data-rights/commands', $this->attach($boundary, $a, 2))->assertOk()->json('data');
        $this->assertNotSame($first['membership_id'], $second['membership_id']);
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data.memberships')->assertJsonPath('data.memberships.0.revision', 3)
            ->assertJsonPath('data.memberships.0.membership_id', $second['membership_id']);
        $this->postJson('/api/data-rights/commands', [...$detach, 'command_id' => (string) Str::uuid(), 'expected_revision' => 3])->assertConflict();
        $db = DB::connection('pgsql_migrations');
        $this->assertSame(2, $db->table('boundary_subjects')->count());
        $this->assertSame(0, $db->table('data_use_scopes')->count());
        $this->assertSame(7, $db->table('security_action_grants')->where('scope_kind', 'business')->count());
    }

    public function test_boundary_owner_can_end_existing_coverage_without_business_read_or_management(): void
    {
        $owner = User::factory()->create();
        $custodian = User::factory()->create();
        $a = $this->draft($owner, 'Unavailable customer profile');
        $boundary = BusinessAuthorityFixture::boundary($custodian->id);
        $db = DB::connection('pgsql_migrations');
        $member = BusinessAuthorityFixture::insert('boundary_subjects', ['membership_id' => (string) Str::uuid7(), 'boundary_id' => $boundary['boundary_id'],
            'business_id' => $db->table('businesses')->where('public_id', $a['business_public_id'])->value('id'), 'branch_id' => null, 'status' => 'active', 'revision' => 1], $custodian->id);
        $this->actingAs($custodian);
        $url = '/api/data-rights/boundaries/'.$boundary['boundary_id'].'?access='.$boundary['grant_id'];
        $surface = $this->getJson($url)->assertOk();
        $this->assertStringNotContainsString('Unavailable customer profile', $surface->getContent());
        $this->getJson('/api/businesses')->assertOk()->assertJsonCount(0, 'data.items');
        $this->postJson('/api/data-rights/commands', ['command_id' => (string) Str::uuid(), 'target_kind' => 'boundary_subject', 'target_ref' => $member->membership_id,
            'effect' => 'detach_subject', 'payload' => new \stdClass, 'expected_revision' => 1, 'authority_selection' => $this->selection($boundary)])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.memberships.0.state', 'ended');
        $this->postJson('/api/data-rights/commands', [...$this->attach($boundary, $a, 2), 'authority_selection' => $this->selection($boundary)])->assertForbidden();
        BusinessAuthorityFixture::revokeQualification($db->table('security_action_grants')->where('id', $boundary['grant_id'])->value('qualification_id'), $custodian->id);
        $this->getJson($url)->assertForbidden();
        $this->getJson('/api/data-rights/boundaries')->assertOk()->assertJsonCount(0, 'data.boundaries');
        $this->actingAs($owner)->getJson('/api/businesses/'.$a['business_public_id'].'/surface?access='.$a['grant_refs']['business.context.read'])->assertOk();
    }

    public function test_boundary_metadata_requalifies_after_a_real_rights_fence_wait(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $boundary = BusinessAuthorityFixture::boundary($actor->id);
        $this->getJson('/api/data-rights/boundaries')->assertOk();
        $request = Request::create('/api/data-rights/boundaries');
        $request->setLaravelSession(app('session')->driver());
        $run = 'boundary-read-withdrawal-'.Str::uuid();
        $path = storage_path('framework/testing/'.$run.'.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        $release = $path.'.release';
        file_put_contents($path, json_encode(['actor' => $actor->id, 'run' => $run, 'operation' => 'boundary', 'boundary' => $boundary['boundary_id'],
            'read_grant' => $boundary['grant_id'], 'session' => SessionAuthority::sessionKey($request), 'release' => $release], JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $path], base_path());
        $worker->setTimeout(45);
        $db = DB::connection('pgsql_migrations');
        $qualification = $db->table('security_action_grants')->where('id', $boundary['grant_id'])->value('qualification_id');
        $evidence = ['run' => $run];
        $wait = function (callable $probe) use ($worker) {
            $deadline = microtime(true) + 15;
            do {
                if ($result = $probe()) {
                    return $result;
                }
                if (! $worker->isRunning()) {
                    throw new \RuntimeException($worker->getOutput().$worker->getErrorOutput());
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            throw new \RuntimeException('Required actual boundary read contention not witnessed.');
        };
        try {
            $worker->start();
            $wait(fn () => str_contains($worker->getOutput(), 'READY_PID='));
            $db->beginTransaction();
            $blocker = (int) $db->scalar('SELECT pg_backend_pid()');
            $db->select('SELECT fence_id FROM resource_right_fences WHERE fence_id=?::uuid FOR UPDATE', [$boundary['fence_id']]);
            file_put_contents($release, 'go');
            $evidence['wait'] = $wait(fn () => DB::selectOne('SELECT pid,backend_xid::text xid,wait_event_type,wait_event,pg_blocking_pids(pid)::text blockers FROM pg_stat_activity WHERE application_name=? AND ?=ANY(pg_blocking_pids(pid))', [$run, $blocker]));
            BusinessAuthorityFixture::revokeQualification($qualification, $actor->id);
            $db->commit();
            $worker->wait();
            $this->assertSame(2, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
            $this->assertStringContainsString('Current qualified authority edge required', $worker->getOutput());
            $this->assertStringNotContainsString('COMMITTED=', $worker->getOutput());
            $this->getJson('/api/data-rights/boundaries')->assertOk()->assertJsonCount(0, 'data.boundaries');
        } finally {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $evidence['worker'] = ['stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput(), 'exit' => $worker->getExitCode()];
            file_put_contents(storage_path('framework/testing/'.$run.'-evidence.json'), json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            foreach ([$path, $release] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
