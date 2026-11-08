<?php

namespace Tests\Feature;

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\BusinessAuthorityFixture;
use Tests\TestCase;

final class BusinessActivityConcurrencyTest extends TestCase
{
    public function test_read_waits_for_actual_practice_write_and_returns_one_committed_revision(): void
    {
        $this->contend(false);
    }

    public function test_replayed_command_after_observed_authority_wait_cannot_disclose_revoked_read_result(): void
    {
        $this->contend(true);
    }

    private function contend(bool $revoke): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $business = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Concurrent practices'])->assertCreated()->json('data');
        $selection = fn ($grant) => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $grant]]];
        $body = ['command_id' => (string) Str::uuid(), 'practice_id' => (string) Str::uuid7(), 'operation' => 'create', 'expected_revision' => 0,
            'value' => ['name' => 'بيع الأجهزة', 'description' => '', 'kind' => 'sale', 'sectors' => [], 'specializations' => [], 'offering' => null, 'commercial_model' => null, 'channels' => [],
                'target_markets' => ['status' => 'unspecified', 'areas' => []], 'fulfillment' => ['status' => 'unspecified', 'areas' => []]],
            'authority_selection' => $selection($business['grant_refs']['business.context.manage']), 'read_selection' => $selection($business['grant_refs']['business.context.read'])];
        $base = '/api/businesses/'.$business['business_public_id'];
        $this->postJson($base.'/practices', $body)->assertOk();
        $request = Request::create('/');
        $request->setLaravelSession(app('session')->driver());
        $session = SessionAuthority::sessionKey($request);
        $tag = 'practice-concurrency-'.Str::uuid();
        $path = storage_path('framework/testing/'.$tag.'.json');
        $release = $path.'.release';
        $ready = $path.'.ready';
        $input = ['actor' => $user->id, 'run' => $tag, 'ready' => $ready, 'release' => $release, 'session' => $session, 'business' => $business['business_public_id'],
            'operation' => $revoke ? 'practice-write' : 'practice-read', 'practice_body' => $body, 'access' => $business['grant_refs']['business.context.read']];
        file_put_contents($path, json_encode($input, JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $path], base_path());
        $worker->setTimeout(45);
        $owner = DB::connection('pgsql_migrations');
        // Observe with the participant's runtime role: PostgreSQL intentionally
        // hides another role's wait details from the isolated migration role.
        $observer = DB::build(config('database.connections.pgsql'));
        $evidence = [];
        $wait = function (callable $condition) use ($worker) {
            $until = microtime(true) + 15;
            do {
                if ($value = $condition()) {
                    return $value;
                }
                if (! $worker->isRunning()) {
                    throw new \RuntimeException($worker->getOutput().$worker->getErrorOutput());
                }
                usleep(10000);
            } while (microtime(true) < $until);
            throw new \RuntimeException('Required observed contention absent: '.$worker->getOutput().$worker->getErrorOutput());
        };
        try {
            $worker->start();
            $wait(function () use ($ready) {
                clearstatcache(true, $ready);

                return is_file($ready);
            });
            if ($revoke) {
                $owner->beginTransaction();
                $blocker = (int) $owner->scalar('SELECT pg_backend_pid()');
                $owner->select('SELECT f.business_id FROM business_authority_fences f JOIN businesses b ON b.id=f.business_id WHERE b.public_id=?::uuid FOR UPDATE OF f', [$business['business_public_id']]);
            } else {
                DB::beginTransaction();
                app(PrincipalContext::class)->activate($user);
                $blocker = (int) DB::scalar('SELECT pg_backend_pid()');
                $update = [...$body, 'command_id' => (string) Str::uuid(), 'operation' => 'update', 'expected_revision' => 1, 'value' => [...$body['value'], 'name' => 'بيع الأجهزة — وصف محدث', 'description' => 'العمل نفسه بعد تحديث الوصف']];
                DB::scalar('SELECT builderad_v2_business_practice_write(?,?::uuid,?::uuid,?::uuid,?,?,?::jsonb,?::jsonb,?::jsonb)',
                    [$session, $business['business_public_id'], $update['command_id'], $update['practice_id'], 'update', 1, json_encode($update['value']), json_encode($update['authority_selection']), json_encode($update['read_selection'])]);
            }
            file_put_contents($release, 'ready');
            $evidence['wait'] = $wait(fn () => $observer->selectOne("SELECT pid,backend_xid::text xid,wait_event_type,wait_event,pg_blocking_pids(pid)::text blockers FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock' AND ?=ANY(pg_blocking_pids(pid))", [$tag, $blocker]));
            $this->assertNotSame($blocker, $evidence['wait']->pid);
            if ($revoke) {
                $qualification = $owner->table('security_action_grants')->where('id', $business['grant_refs']['business.context.read'])->value('qualification_id');
                BusinessAuthorityFixture::revokeQualification($qualification, $user->id);
                $owner->statement('RESET ROLE');
                $owner->commit();
            } else {
                DB::commit();
            }
            $worker->wait();
            if ($revoke) {
                $this->assertSame(2, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
                $this->assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
                $this->assertStringNotContainsString('COMMITTED=', $worker->getOutput());
                $this->assertSame(1, $owner->table('business_practice_revisions')->count());
            } else {
                $this->assertSame(0, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
                preg_match('/COMMITTED=(.+)/', $worker->getOutput(), $match);
                $result = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame(2, $result['practices'][0]['revision']);
                $this->assertSame('sale', $result['practices'][0]['value']['kind']);
                $this->assertSame('بيع الأجهزة — وصف محدث', $result['practices'][0]['value']['name']);
                $this->assertSame('العمل نفسه بعد تحديث الوصف', $result['practices'][0]['value']['description']);
            }
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            if ($owner->transactionLevel()) {
                $owner->rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
            $evidence['stdout'] = $worker->getOutput();
            $evidence['stderr'] = $worker->getErrorOutput();
            $evidence['exit'] = $worker->getExitCode();
            $observer->disconnect();
            file_put_contents(storage_path('framework/testing/'.$tag.'-evidence.json'), json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            foreach ([$path, $release, $ready] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
