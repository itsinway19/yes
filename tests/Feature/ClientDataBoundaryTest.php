<?php

namespace Tests\Feature;

use App\Foundation\Business\BusinessScope;
use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Security\SessionAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\BusinessAuthorityFixture as Fixture;
use Tests\TestCase;

final class ClientDataBoundaryTest extends TestCase
{
    private function request(User $u): Request
    {
        $this->actingAs($u);
        $r = Request::create('/api/data-rights');
        $r->setUserResolver(fn () => $u);
        $r->setLaravelSession(app('session')->driver());

        return $r;
    }

    private function selection(array $boundary, ?array $business = null): array
    {
        $req = [['requirement_kind' => 'client_data', 'leaf_grant_id' => $boundary['grant_id']]];
        if ($business) {
            $req[] = ['requirement_kind' => 'business', 'leaf_grant_id' => $business['grant_refs']['business.link.manage']];
        }

        return ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => $req];
    }

    private function command(Request $r, array $boundary, string $business, string $effect, int $version, array $selection): array
    {
        $target = $effect === 'attach_subject' ? $boundary['boundary_id'] : DB::connection('pgsql_migrations')->table('boundary_subjects')
            ->where('boundary_id', $boundary['boundary_id'])->where('business_id', DB::connection('pgsql_migrations')->table('businesses')->where('public_id', $business)->value('id'))->value('membership_id');
        $payload = $effect === 'attach_subject' ? ['business_public_id' => $business, 'branch_id' => null] : (object) [];

        return DB::transaction(function () use ($r, $effect, $version, $selection, $target, $payload) {
            app(PrincipalContext::class)->activate($r->user());

            return json_decode(DB::scalar('SELECT builderad_v2_resource_right_command(?,?::uuid,?,?::uuid,?,?::jsonb,?,?::jsonb)',
                [SessionAuthority::sessionKey($r), (string) Str::uuid(), 'boundary_subject', $target, $effect,
                    json_encode($payload), $version, json_encode($selection)]), true, flags: JSON_THROW_ON_ERROR);
        });
    }

    private function denied(callable $op): void
    {
        try {
            $op();
            $this->fail('Missing independent authority was accepted.');
        } catch (QueryException $e) {
            $this->assertSame('42501', $e->errorInfo[0]);
        }
    }

    public function test_attachment_requires_both_owners_and_never_infers_business_from_boundary(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $a = app(BusinessScope::class)->create($this->request($alice), (string) Str::uuid(), 'Alice', null);
        $b = app(BusinessScope::class)->create($this->request($bob), (string) Str::uuid(), 'Bob', null);
        $boundary = Fixture::boundary($alice->id);
        $this->denied(fn () => $this->command($this->request($alice), $boundary, $a['business_public_id'], 'attach_subject', 0, $this->selection($boundary)));
        $this->denied(fn () => $this->command($this->request($alice), $boundary, $b['business_public_id'], 'attach_subject', 0, $this->selection($boundary, $b)));
        $result = $this->command($this->request($alice), $boundary, $a['business_public_id'], 'attach_subject', 0, $this->selection($boundary, $a));
        $this->assertSame('active', $result['status']);
        $this->assertSame(1, $result['versions']['membership']);
        $db = DB::connection('pgsql_migrations');
        $this->assertSame(1, $db->table('boundary_subjects')->count());
        $this->assertSame(1, $db->table('resource_right_fences')->where('fence_id', $boundary['fence_id'])->value('rights_epoch'));
        $this->assertSame(0, $db->table('data_use_scopes')->count());
        $this->assertSame(0, $db->table('business_data_use_bindings')->count());
    }

    public function test_prepared_detach_cannot_withdraw_a_different_reattached_coverage(): void
    {
        $actor = User::factory()->create();
        $request = $this->request($actor);
        $business = app(BusinessScope::class)->create($request, (string) Str::uuid(), 'Coverage cycle', null);
        $boundary = Fixture::boundary($actor->id);
        $first = $this->command($request, $boundary, $business['business_public_id'], 'attach_subject', 0, $this->selection($boundary, $business));
        $prepared = ['command_id' => (string) Str::uuid(), 'target_kind' => 'boundary_subject', 'target_ref' => $first['membership_id'],
            'effect' => 'detach_subject', 'payload' => (object) [], 'expected_revision' => 1, 'authority_selection' => $this->selection($boundary)];
        $this->postJson('/api/data-rights/commands', [...$prepared, 'command_id' => (string) Str::uuid()])->assertOk();
        $second = $this->command($request, $boundary, $business['business_public_id'], 'attach_subject', 2, $this->selection($boundary, $business));
        $this->assertNotSame($first['membership_id'], $second['membership_id']);
        $this->assertSame(3, $second['versions']['membership']);
        $this->postJson('/api/data-rights/commands', $prepared)->assertConflict();
        $this->postJson('/api/data-rights/commands', [...$prepared, 'command_id' => (string) Str::uuid(), 'expected_revision' => 3])->assertConflict();
        $this->assertSame('active', DB::connection('pgsql_migrations')->table('boundary_subjects')->where('membership_id', $second['membership_id'])->value('status'));
        $this->postJson('/api/data-rights/commands', [...$prepared, 'command_id' => (string) Str::uuid(), 'target_ref' => $second['membership_id'], 'expected_revision' => 3])->assertOk()
            ->assertJsonPath('data.versions.membership', 4);
    }

    public function test_boundary_owner_can_withdraw_only_its_coverage_without_business_consent(): void
    {
        $owner = User::factory()->create();
        $a = app(BusinessScope::class)->create($this->request($owner), (string) Str::uuid(), 'Customer', null);
        $first = Fixture::boundary($owner->id);
        $otherOwner = User::factory()->create();
        $second = Fixture::boundary($otherOwner->id);
        $db = DB::connection('pgsql_migrations');
        $b = $db->table('businesses')->where('public_id', $a['business_public_id'])->value('id');
        foreach ([$first, $second] as $boundary) {
            Fixture::insert('boundary_subjects', ['membership_id' => (string) Str::uuid7(), 'boundary_id' => $boundary['boundary_id'],
                'business_id' => $b, 'branch_id' => null, 'status' => 'active', 'revision' => 1], $owner->id);
        }
        $result = $this->command($this->request($otherOwner), $second, $a['business_public_id'], 'detach_subject', 1, $this->selection($second));
        $this->assertSame('ended', $result['status']);
        $this->assertSame(2, $result['versions']['membership']);
        $this->assertSame('active', $db->table('boundary_subjects')->where('boundary_id', $first['boundary_id'])->value('status'));
        $this->assertSame(0, $db->table('resource_right_fences')->where('fence_id', $first['fence_id'])->value('rights_epoch'));
        $this->assertSame(1, $db->table('resource_right_fences')->where('fence_id', $second['fence_id'])->value('rights_epoch'));
        $this->assertSame(1, $db->table('business_authority_fences')->where('business_id', $b)->value('auth_epoch'));
        $this->assertSame('Customer', app(BusinessScope::class)->read($this->request($owner), $a['business_public_id'],
            ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $a['grant_refs']['business.context.read']]]])['name']);
    }

    public function test_raw_boundary_dml_and_caller_root_qualification_are_denied(): void
    {
        $u = User::factory()->create();
        $r = $this->request($u);
        $boundary = Fixture::boundary($u->id);
        $this->denied(fn () => DB::statement("UPDATE client_data_boundaries SET status='restricted'"));
        $this->denied(fn () => DB::transaction(function () use ($r, $boundary) {
            app(PrincipalContext::class)->activate($r->user());
            DB::scalar("SELECT builderad_v2_resource_right_command(?,?::uuid,'client_boundary',?::uuid,'qualify_issue','{}',0,?::jsonb)",
                [SessionAuthority::sessionKey($r), (string) Str::uuid(), $boundary['boundary_id'], json_encode($this->selection($boundary))]);
        }));
    }

    public function test_revoke_commits_before_a_queued_boundary_command_which_then_requalifies_and_denies(): void
    {
        $owner = User::factory()->create();
        $a = app(BusinessScope::class)->create($this->request($owner), (string) Str::uuid(), 'Customer', null);
        $boundaryOwner = User::factory()->create();
        $boundary = Fixture::boundary($boundaryOwner->id);
        $db = DB::connection('pgsql_migrations');
        $b = $db->table('businesses')->where('public_id', $a['business_public_id'])->value('id');
        Fixture::insert('boundary_subjects', ['membership_id' => (string) Str::uuid7(), 'boundary_id' => $boundary['boundary_id'], 'business_id' => $b,
            'branch_id' => null, 'status' => 'active', 'revision' => 1], $owner->id);
        $r = $this->request($boundaryOwner);
        $run = 'boundary-revoke-'.Str::uuid();
        if (! is_dir(storage_path('framework/testing'))) {
            mkdir(storage_path('framework/testing'), 0777, true);
        }
        $release = storage_path('framework/testing/'.$run.'.release');
        $path = storage_path('framework/testing/'.$run.'.json');
        $fixture = ['actor' => $boundaryOwner->id, 'run' => $run, 'session' => SessionAuthority::sessionKey($r), 'command' => (string) Str::uuid(),
            'release' => $release, 'operation' => 'resource', 'kind' => 'boundary_subject', 'target' => $db->table('boundary_subjects')->where('boundary_id', $boundary['boundary_id'])->value('membership_id'), 'effect' => 'detach_subject',
            'payload' => (object) [], 'expected' => 1, 'selection' => $this->selection($boundary)];
        file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $path], base_path());
        $worker->setTimeout(45);
        $observer = DB::build(config('database.connections.pgsql'));
        $evidence = ['run' => $run];
        $wait = function (callable $probe) use ($worker) {
            $deadline = microtime(true) + 25;
            do {
                if ($value = $probe()) {
                    return $value;
                }
                if (! $worker->isRunning()) {
                    $this->fail('Participant ended before actual contention: '.$worker->getOutput().$worker->getErrorOutput());
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->fail('Actual boundary revocation contention not observed.');
        };
        try {
            $worker->start();
            $wait(fn () => str_contains($worker->getOutput(), 'READY_PID='));
            DB::beginTransaction();
            app(PrincipalContext::class)->activate($boundaryOwner);
            $holder = DB::scalar('SELECT pg_backend_pid()');
            DB::scalar("SELECT builderad_v2_resource_right_command(?,?::uuid,'client_boundary',?::uuid,'revoke',?::jsonb,1,?::jsonb)",
                [SessionAuthority::sessionKey($r), (string) Str::uuid(), $boundary['boundary_id'], json_encode(['grant_id' => $boundary['grant_id']]), json_encode($this->selection($boundary))]);
            file_put_contents($release, 'release');
            $evidence['waiter'] = $wait(function () use ($observer, $run, $holder) {
                $row = $observer->selectOne('SELECT pid,backend_xid::text AS xid,wait_event_type,wait_event,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE application_name=?', [$run]);

                return $row?->wait_event_type === 'Lock' && str_contains($row->blockers, (string) $holder) ? $row : false;
            });
            DB::commit();
            $worker->wait();
            $this->assertSame(2, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
            $this->assertSame('active', $db->table('boundary_subjects')->where('boundary_id', $boundary['boundary_id'])->value('status'));
            $this->assertSame(1, $db->table('resource_right_fences')->where('fence_id', $boundary['fence_id'])->value('rights_epoch'));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $evidence['worker'] = ['exit' => $worker->getExitCode(), 'stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput()];
            file_put_contents(storage_path('framework/testing/'.$run.'-evidence.json'), json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $observer->disconnect();
            foreach ([$release, $path] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function test_http_boundary_commands_preserve_independent_rights_and_validate_body(): void
    {
        $u = User::factory()->create();
        $a = app(BusinessScope::class)->create($this->request($u), (string) Str::uuid(), 'HTTP customer', null);
        $boundary = Fixture::boundary($u->id);
        $this->actingAs($u);
        $body = ['command_id' => (string) Str::uuid(), 'authority_selection' => $this->selection($boundary, $a),
            'target_kind' => 'boundary_subject', 'target_ref' => $boundary['boundary_id'], 'effect' => 'attach_subject',
            'payload' => ['business_public_id' => $a['business_public_id'], 'branch_id' => null], 'expected_revision' => 0];
        $membership = $this->postJson('/api/data-rights/commands', $body)->assertOk()->assertJsonPath('data.status', 'active')->json('data.membership_id');
        $this->postJson('/api/data-rights/commands', $body)->assertOk()->assertJsonPath('data.versions.membership', 1);
        $body['command_id'] = (string) Str::uuid();
        $body['effect'] = 'detach_subject';
        $body['expected_revision'] = 1;
        $body['authority_selection'] = $this->selection($boundary);
        $body['target_ref'] = $membership;
        $body['payload'] = (object) [];
        $this->postJson('/api/data-rights/commands', $body)->assertOk()->assertJsonPath('data.status', 'ended');
        $this->actingAs(User::factory()->create())->postJson('/api/data-rights/commands', $body)->assertForbidden();
    }

    public function test_composed_command_locks_union_of_principals_before_either_selected_grant(): void
    {
        $issuer = User::factory()->create();
        $actor = User::factory()->create();
        $business = app(BusinessScope::class)->create($this->request($issuer), (string) Str::uuid(), 'Composed customer', null);
        $owner = DB::connection('pgsql_migrations');
        $b = $owner->table('businesses')->where('public_id', $business['business_public_id'])->value('id');
        $root = Fixture::root($b, $issuer->id, 'business.link.manage');
        $delegated = app(BusinessScope::class)->grant($this->request($issuer), $business['business_public_id'], (string) Str::uuid(), 'issue', $actor->public_id,
            'business.link.manage', $root->id, null, 0, null, false, null,
            ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $root->id]]]);
        $boundary = Fixture::boundary($actor->id);
        $business['grant_refs']['business.link.manage'] = $delegated['grant_id'];
        $request = $this->request($actor);
        $run = 'authority-union-'.Str::uuid();
        $dir = storage_path('framework/testing');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $release = $dir.'/'.$run.'.release';
        $file = $dir.'/'.$run.'.json';
        file_put_contents($file, json_encode(['actor' => $actor->id, 'run' => $run, 'session' => SessionAuthority::sessionKey($request),
            'command' => (string) Str::uuid(), 'release' => $release, 'operation' => 'resource', 'kind' => 'boundary_subject',
            'target' => $boundary['boundary_id'], 'effect' => 'attach_subject', 'payload' => ['business_public_id' => $business['business_public_id'], 'branch_id' => null],
            'expected' => 0, 'selection' => $this->selection($boundary, $business)], JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $file], base_path());
        $worker->setTimeout(45);
        $observer = DB::build(config('database.connections.pgsql'));
        $evidence = ['run' => $run];
        $wait = function (callable $probe) use ($worker) {
            $deadline = microtime(true) + 25;
            do {
                if ($value = $probe()) {
                    return $value;
                }
                if (! $worker->isRunning()) {
                    $this->fail('Participant stopped before lock observation: '.$worker->getOutput().$worker->getErrorOutput());
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->fail('Required authority lock wait was not observed.');
        };
        try {
            $observer->getPdo(); // Connect before the bounded production lock wait starts.
            $worker->start();
            $wait(fn () => str_contains($worker->getOutput(), 'READY_PID='));
            $owner->beginTransaction();
            $owner->selectOne('SELECT user_id FROM principal_lifecycles WHERE user_id=? FOR UPDATE', [$issuer->id]);
            $holder = $owner->scalar('SELECT pg_backend_pid()');
            file_put_contents($release, 'release');
            $evidence['waiter'] = $wait(function () use ($observer, $run, $holder) {
                $row = $observer->selectOne('SELECT pid,backend_xid::text AS xid,wait_event_type,wait_event,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE application_name=?', [$run]);

                return $row?->wait_event_type === 'Lock' && str_contains($row->blockers, (string) $holder) ? $row : false;
            });
            // If the first right were validated in isolation, its grant SHARE lock would already exist here.
            $row = $owner->selectOne('SELECT id FROM security_action_grants WHERE id=? FOR UPDATE NOWAIT', [$boundary['grant_id']]);
            $this->assertSame($boundary['grant_id'], $row->id);
            $evidence['first_grant_was_unlocked'] = true;
            $this->assertSame(0, $owner->table('boundary_subjects')->where('boundary_id', $boundary['boundary_id'])->count());
            $owner->commit();
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('COMMITTED=', $worker->getOutput());
            $this->assertSame('active', $owner->table('boundary_subjects')->where('boundary_id', $boundary['boundary_id'])->value('status'));
        } finally {
            if ($observer->transactionLevel() > 0) {
                $observer->rollBack();
            }
            if ($owner->transactionLevel() > 0) {
                $owner->rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $evidence['worker'] = ['exit' => $worker->getExitCode(), 'stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput()];
            file_put_contents($dir.'/'.$run.'-evidence.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $observer->disconnect();
            foreach ([$release, $file] as $ownedFile) {
                if (is_file($ownedFile)) {
                    unlink($ownedFile);
                }
            }
        }
    }
}
