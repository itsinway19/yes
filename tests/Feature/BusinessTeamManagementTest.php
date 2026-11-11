<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\BusinessAuthorityFixture;
use Tests\TestCase;

final class BusinessTeamManagementTest extends TestCase
{
    private function setupTeam(User $owner): array
    {
        $b = $this->actingAs($owner)->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Shop team'])->assertCreated()->json('data');
        $selection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => (int) $b['grant_refs']['business.context.read']]]];
        $this->postJson('/api/businesses/'.$b['business_public_id'].'/direct-team', ['command_id' => (string) Str::uuid(), 'authority_selection' => $selection])->assertOk();

        return [$b, $selection];
    }

    public function test_lost_team_response_replays_after_expiry_and_recipient_change_without_renewing_rights(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->create();
        [$b, $selection] = $this->setupTeam($owner);
        $db = DB::connection('pgsql_migrations');
        $base = '/api/businesses/'.$b['business_public_id'].'/team';
        $expiry = (new \DateTimeImmutable($db->scalar('SELECT clock_timestamp()::text')))->modify('+6 seconds')->format('Y-m-d\TH:i:s.uP');
        $body = ['command_id' => (string) Str::uuid(), 'authority_selection' => $selection, 'recipient_public_id' => $staff->public_id,
            'expected_grants' => [], 'permissions' => ['business.context.read'], 'expires_at' => $expiry, 'confirmed' => true];
        $first = $this->postJson($base, $body)->assertOk()->json('data');
        $grant = $first['grant_refs']['business.context.read'];
        $db->select('SELECT pg_sleep(greatest(0,extract(epoch FROM ?::timestamptz-clock_timestamp()))+0.05)', [$expiry]);
        $db->table('users')->where('id', $staff->id)->update(['email_verified_at' => null]);
        $count = $db->table('security_action_grants')->count();
        $this->assertSame($first, $this->postJson($base, $body)->assertOk()->json('data'));
        $this->assertSame($count, $db->table('security_action_grants')->count());
        $this->assertSame(1, $db->table('business_command_receipts')->where('command_id', $body['command_id'])->count());
        $this->postJson($base, [...$body, 'permissions' => []])->assertConflict();
        $this->postJson($base, [...$body, 'command_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->actingAs($staff)->postJson($base, $body)->assertForbidden();
        $this->getJson('/api/businesses/'.$b['business_public_id'].'/surface?access='.$grant)->assertForbidden();
        $this->actingAs($owner);
        $remove = [...$body, 'command_id' => (string) Str::uuid(), 'expected_grants' => [['grant_id' => (string) $grant, 'version' => 1]], 'permissions' => [], 'expires_at' => null];
        $removed = $this->postJson($base, $remove)->assertOk()->json('data');
        $this->assertSame($removed, $this->postJson($base, $remove)->assertOk()->json('data'));
        BusinessAuthorityFixture::revokeQualification($db->table('security_action_grants')->where('id', $b['grant_refs']['business.context.read'])->value('qualification_id'), $owner->id);
        $this->postJson($base, $body)->assertForbidden();
    }

    public function test_email_staff_crud_is_atomic_current_and_business_isolated_without_workspaces(): void
    {
        $owner = User::factory()->create();
        $employee = User::factory()->create();
        $outsider = User::factory()->create();
        [$b, $selection] = $this->setupTeam($owner);
        $base = '/api/businesses/'.$b['business_public_id'].'/team';
        $read = $b['grant_refs']['business.context.read'];
        $this->getJson($base.'?access='.$read)->assertOk()->assertJsonPath('data.members', []);
        $this->postJson($base.'/recipient', ['access' => (int) $read, 'email' => strtoupper($employee->email)])->assertOk()->assertJsonPath('data.recipient.public_id', $employee->public_id);
        $body = ['command_id' => (string) Str::uuid(), 'authority_selection' => $selection, 'recipient_public_id' => $employee->public_id,
            'expected_grants' => [], 'permissions' => ['business.context.read', 'business.context.manage'], 'expires_at' => null, 'confirmed' => true];
        $result = $this->postJson($base, $body)->assertOk()->json('data');
        $this->postJson($base, $body)->assertOk()->assertJsonPath('data.grant_refs', $result['grant_refs']);
        $member = $this->getJson($base.'?access='.$read)->assertOk()->json('data.members.0');
        $this->assertSame($employee->email, $member['email']);
        $this->assertCount(2, $member['grants']);
        $expected = array_map(fn ($g) => ['grant_id' => $g['grant_id'], 'version' => $g['version']], $member['grants']);
        $staffRead = $result['grant_refs']['business.context.read'];
        $this->actingAs($employee)->getJson('/api/businesses/'.$b['business_public_id'].'/surface?access='.$staffRead)->assertOk();
        $this->getJson($base.'?access='.$staffRead)->assertForbidden();
        $this->postJson($base.'/recipient', ['access' => (int) $staffRead, 'email' => $outsider->email])->assertForbidden();
        $this->actingAs($outsider)->getJson($base.'?access='.$read)->assertForbidden();
        $this->actingAs($owner);
        $changed = [...$body, 'command_id' => (string) Str::uuid(), 'expected_grants' => $expected, 'permissions' => ['business.context.read']];
        $this->assertSame($staffRead, $this->postJson($base, $changed)->assertOk()->json('data.grant_refs')['business.context.read']);
        $this->postJson($base, [...$changed, 'command_id' => (string) Str::uuid()])->assertConflict();
        $this->postJson($base, [...$changed, 'command_id' => (string) Str::uuid(), 'permissions' => ['business.context.read', 'business.data.use']])->assertUnprocessable();
        $member = $this->getJson($base.'?access='.$read)->assertOk()->json('data.members.0');
        $this->assertCount(1, $member['grants']);
        $remove = [...$body, 'command_id' => (string) Str::uuid(), 'expected_grants' => array_map(fn ($g) => ['grant_id' => $g['grant_id'], 'version' => $g['version']], $member['grants']), 'permissions' => []];
        $this->postJson($base, $remove)->assertOk()->assertJsonPath('data.grant_refs', []);
        $this->postJson($base, $remove)->assertOk();
        $this->actingAs($employee)->getJson('/api/businesses/'.$b['business_public_id'].'/surface?access='.$staffRead)->assertForbidden();
        $this->actingAs($owner)->getJson($base.'?access='.$read)->assertOk()->assertJsonPath('data.members', []);
        $this->postJson($base, [...$body, 'command_id' => (string) Str::uuid()])->assertOk();
        $this->actingAs($employee)->getJson('/api/businesses/'.$b['business_public_id'].'/surface?access='.$staffRead)->assertForbidden();
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('workspaces')->count());
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('security_action_grants')->whereIn('scope_kind', ['source', 'client_data', 'data_use'])->count());
    }

    public function test_unknown_recipient_unconfirmed_and_changed_command_never_create_hidden_staff_rights(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->create();
        $unverified = User::factory()->unverified()->create();
        [$b, $selection] = $this->setupTeam($owner);
        $base = '/api/businesses/'.$b['business_public_id'].'/team';
        foreach (['not-registered@example.test', $unverified->email] as $email) {
            $this->postJson($base.'/recipient', ['access' => (int) $b['grant_refs']['business.context.read'], 'email' => $email])->assertForbidden();
        }
        $body = ['command_id' => (string) Str::uuid(), 'authority_selection' => $selection, 'recipient_public_id' => $staff->public_id,
            'expected_grants' => [], 'permissions' => ['business.context.read'], 'expires_at' => null, 'confirmed' => false];
        $this->postJson($base, $body)->assertUnprocessable();
        $body['confirmed'] = true;
        $this->postJson($base, [...$body, 'expires_at' => 'tomorrow'])->assertUnprocessable();
        $this->postJson($base, [...$body, 'authority_selection' => [...$selection, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => '9999999999999999999']]]])->assertUnprocessable();
        $this->postJson($base, $body)->assertOk();
        $this->postJson($base, [...$body, 'permissions' => ['business.context.read', 'business.context.manage']])->assertConflict();
        $this->getJson($base.'?access='.$b['grant_refs']['business.context.read'])->assertOk()->assertJsonCount(1, 'data.members.0.grants');
    }

    public function test_reconfirming_invalid_staff_right_creates_a_new_identity_and_expired_staff_can_be_removed(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->create();
        [$b, $selection] = $this->setupTeam($owner);
        $base = '/api/businesses/'.$b['business_public_id'].'/team';
        $body = ['command_id' => (string) Str::uuid(), 'authority_selection' => $selection, 'recipient_public_id' => $staff->public_id,
            'expected_grants' => [], 'permissions' => ['business.context.read'], 'expires_at' => null, 'confirmed' => true];
        $old = $this->postJson($base, $body)->assertOk()->json('data.grant_refs')['business.context.read'];
        $db = DB::connection('pgsql_migrations');
        $qualification = $db->table('security_action_grants')->where('id', $old)->value('qualification_id');
        BusinessAuthorityFixture::revokeQualification($qualification, $owner->id);
        $this->actingAs($staff)->getJson('/api/businesses/'.$b['business_public_id'].'/surface?access='.$old)->assertForbidden();
        $this->actingAs($owner);
        $new = $this->postJson($base, [...$body, 'command_id' => (string) Str::uuid(), 'expected_grants' => [['grant_id' => $old, 'version' => 1]]])
            ->assertOk()->json('data.grant_refs')['business.context.read'];
        $this->assertNotSame($old, $new, 'Explicit reconfirmation must not revive an invalid grant identity.');
        $this->actingAs($staff)->getJson('/api/businesses/'.$b['business_public_id'].'/surface?access='.$old)->assertForbidden();
        $this->getJson('/api/businesses/'.$b['business_public_id'].'/surface?access='.$new)->assertOk();
        BusinessAuthorityFixture::expireQualificationSoon($db->table('security_action_grants')->where('id', $new)->value('qualification_id'), $owner->id);
        usleep(2100000);
        $this->actingAs($owner)->postJson($base, [...$body, 'command_id' => (string) Str::uuid(), 'expected_grants' => [['grant_id' => $new, 'version' => 1]], 'permissions' => []])->assertOk();
        $this->getJson($base.'?access='.$b['grant_refs']['business.context.read'])->assertOk()->assertJsonPath('data.members', []);
    }

    public function test_unchanged_staff_qualification_expiring_during_final_receipt_wait_rejects_the_whole_command(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->create();
        [$b, $selection] = $this->setupTeam($owner);
        $base = '/api/businesses/'.$b['business_public_id'].'/team';
        $body = ['command_id' => (string) Str::uuid(), 'authority_selection' => $selection, 'recipient_public_id' => $staff->public_id,
            'expected_grants' => [], 'permissions' => ['business.context.read'], 'expires_at' => null, 'confirmed' => true];
        $grant = $this->postJson($base, $body)->assertOk()->json('data.grant_refs')['business.context.read'];
        $request = Request::create('/api/businesses');
        $request->setLaravelSession(app('session')->driver());
        $db = DB::connection('pgsql_migrations');
        $qualification = $db->table('security_action_grants')->where('id', $grant)->value('qualification_id');
        $run = 'team-final-'.Str::uuid();
        $path = storage_path('framework/testing/'.$run.'.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        $input = ['run' => $run, 'ready' => $path.'.ready', 'release' => $path.'.release', 'require_release_content' => true,
            'operation' => 'team-change', 'actor' => $owner->id, 'business' => $b['business_public_id'], 'session' => SessionAuthority::sessionKey($request),
            'command' => (string) Str::uuid(), 'selection' => $selection, 'recipient' => $staff->public_id,
            'expected_grants' => [['grant_id' => $grant, 'version' => 1]], 'permissions' => ['business.context.read'], 'expires_at' => null];
        file_put_contents($path, json_encode($input, JSON_THROW_ON_ERROR));
        $release = fopen($input['release'], 'x+b');
        config(['database.connections.team_receipt_barrier' => config('database.connections.pgsql_migrations')]);
        $barrier = DB::connection('team_receipt_barrier');
        $barrier->getPdo();
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $path], base_path());
        $worker->setTimeout(45);
        $evidence = ['run' => $run];
        $wait = function (callable $condition) use ($worker) {
            $deadline = microtime(true) + 15;
            do {
                if ($value = $condition()) {
                    return $value;
                } if (! $worker->isRunning()) {
                    throw new \RuntimeException($worker->getOutput().$worker->getErrorOutput());
                } usleep(10000);
            } while (microtime(true) < $deadline);
            throw new \RuntimeException('Required actual team receipt contention absent: '.$worker->getOutput().$worker->getErrorOutput());
        };
        try {
            $worker->start();
            $evidence['ready'] = $wait(function () use ($input) {
                clearstatcache(true, $input['ready']);

                return is_file($input['ready']) ? json_decode(file_get_contents($input['ready']), true, flags: JSON_THROW_ON_ERROR) : false;
            });
            $barrier->beginTransaction();
            $blocker = (int) $barrier->scalar('SELECT pg_backend_pid()');
            $barrier->statement('LOCK TABLE business_command_receipts IN SHARE MODE');
            $expires = BusinessAuthorityFixture::expireQualificationSoon($qualification, $owner->id);
            $evidence['expires'] = $expires;
            fwrite($release, 'go');
            fflush($release);
            $evidence['wait'] = $wait(function () use ($db, $run, $blocker) {
                $rows = $db->select("SELECT a.pid,a.backend_xid::text xid,a.wait_event,pg_blocking_pids(a.pid)::text blockers FROM pg_stat_activity a WHERE application_name=? AND ?=ANY(pg_blocking_pids(a.pid)) AND EXISTS(SELECT 1 FROM pg_locks WHERE pid=a.pid AND relation='business_command_receipts'::regclass AND mode='RowExclusiveLock' AND NOT granted)", [$run, $blocker]);

                return $rows ? (array) $rows[0] : false;
            });
            $this->assertSame((int) $evidence['ready']['pid'], (int) $evidence['wait']['pid']);
            $wait(fn () => (bool) $db->scalar('SELECT clock_timestamp()>=?::timestamptz', [$expires]));
            $barrier->commit();
            $worker->wait();
            $this->assertSame(2, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
            $this->assertSame(0, $db->table('business_command_receipts')->where('command_id', $input['command'])->count());
            $this->assertSame('active', $db->table('security_action_grants')->where('id', $grant)->value('status'));
        } finally {
            if ($barrier->transactionLevel()) {
                $barrier->rollBack();
            } if ($worker->isRunning()) {
                $worker->stop();
            }
            $evidence['worker'] = ['stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput(), 'exit' => $worker->getExitCode()];
            file_put_contents($path.'.evidence.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            fclose($release);
            DB::disconnect('team_receipt_barrier');
            foreach ([$path, $input['release'], $input['ready']] as $temporary) {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }
    }
}
