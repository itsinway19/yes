<?php

namespace Tests\Feature;

use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\BusinessAuthorityFixture;
use Tests\Support\BusinessNativeMaterialFixture;
use Tests\TestCase;

final class ExpenditureSummaryConcurrencyTest extends TestCase
{
    use BusinessNativeMaterialFixture;

    private array $workers = [];

    private function waitFor(callable $probe): mixed
    {
        $deadline = microtime(true) + 15;
        do {
            if ($value = $probe()) {
                return $value;
            }
            foreach ($this->workers as $worker) {
                if (! $worker->isRunning()) {
                    throw new \RuntimeException('Worker exited before observed contention: '.$worker->getOutput().$worker->getErrorOutput());
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new \RuntimeException('Required live worker/lock condition was not observed.');
    }

    private function participant(array $f, string $operation, array $arguments): array
    {
        $request = Request::create('/');
        $request->setLaravelSession(app('session')->driver());
        $run = 'c05-summary-'.Str::uuid();
        $path = storage_path('framework/testing/'.$run.'.json');
        $payload = [...$arguments, 'actor' => $f['user']->id, 'run' => $run, 'business' => $f['business']['business_public_id'],
            'operation' => $operation, 'session' => SessionAuthority::sessionKey($request), 'ready' => $path.'.ready', 'release' => $path.'.release'];
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertFileDoesNotExist($payload['ready']);
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $path], base_path());
        $worker->setTimeout(45);
        $worker->start();
        $this->workers[] = $worker;
        $identity = $this->waitFor(function () use ($payload) {
            if (! is_file($payload['ready'])) {
                return null;
            }
            $value = json_decode(file_get_contents($payload['ready']), true);

            return is_array($value) && ($value['run'] ?? null) === $payload['run'] && is_int($value['pid'] ?? null) && $value['pid'] > 0 ? $value : null;
        });

        return compact('worker', 'payload', 'identity', 'path');
    }

    private function waiting(array $participant, array $blockers): ?object
    {
        $result = DB::selectOne("SELECT pid,backend_xid,wait_event_type,pg_blocking_pids(pid)::text blockers,query FROM pg_stat_activity WHERE application_name=? AND pid=? AND wait_event_type='Lock'",
            [$participant['payload']['run'], $participant['identity']['pid']]);
        if (! $result || $result->backend_xid === null || ! array_intersect($blockers, json_decode(str_replace(['{', '}'], ['[', ']'], $result->blockers), true))) {
            return null;
        }

        return $result;
    }

    private function fixture(): array
    {
        $f = $this->nativeMaterial(operation: 'process');
        $this->postJson('/api/businesses/'.$f['business']['business_public_id'].'/materials/read', $f['body'])->assertOk();
        $selection = $f['body']['authority_selection'];
        $selection['requirements'][] = ['requirement_kind' => 'client_data', 'leaf_grant_id' => $f['boundary']['grant_id']];
        $selection['requirements'][] = ['requirement_kind' => 'business', 'leaf_grant_id' => $f['manager']->id];
        $f['prepare'] = ['command_id' => (string) Str::uuid(), 'summary_id' => (string) Str::uuid7(), 'input_ref' => $f['ref'],
            'input_license_id' => $f['useScope'], 'read_command_id' => $f['body']['command_id'], 'confirmed' => true,
            'valid_until' => now()->addMinutes(10)->toIso8601String(), 'consent' => ['derive' => true, 'deliver_summary' => true, 'retain_payload' => false],
            'authority_selection' => $selection];

        return $f;
    }

    private function cleanup(): void
    {
        $owner = DB::connection('pgsql_migrations');
        if ($owner->transactionLevel()) {
            $owner->rollBack();
        }
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(0);
            }
        }
    }

    public function test_distinct_preparations_overlap_serialize_and_replay_without_late_lock_conversion(): void
    {
        $f = $this->fixture();
        $owner = DB::connection('pgsql_migrations');
        $a = $this->participant($f, 'summary-prepare', ['body' => $f['prepare']]);
        $second = [...$f['prepare'], 'command_id' => (string) Str::uuid(), 'summary_id' => (string) Str::uuid7()];
        $b = $this->participant($f, 'summary-prepare', ['body' => $second]);
        try {
            $before = $owner->table('resource_right_fences')->where('boundary_id', $f['boundary']['boundary_id'])->value('rights_epoch');
            $beforeBusiness = $owner->table('business_authority_fences')->where('business_id', $f['id'])->value('auth_epoch');
            $owner->beginTransaction();
            $blocker = (int) $owner->scalar('SELECT pg_backend_pid()');
            // Hold effects after all planned fences. This gives the second worker
            // an observable overlap, without sleeps or any application test hook.
            $owner->statement('LOCK TABLE business_expenditure_summaries IN SHARE MODE');
            file_put_contents($a['payload']['release'], 'go');
            $firstWait = $this->waitFor(fn () => $this->waiting($a, [$blocker]));
            file_put_contents($b['payload']['release'], 'go');
            // Broken baseline: both reach effects holding SHARE fences. Fixed:
            // second waits for first at its early boundary write intent.
            $secondWait = $this->waitFor(fn () => $this->waiting($b, [$blocker, $a['identity']['pid']]));
            $owner->commit();
            $a['worker']->wait();
            $b['worker']->wait();
            $evidence = ['first' => $firstWait, 'second' => $secondWait, 'commands' => [$f['prepare']['command_id'], $second['command_id']],
                'outputs' => [$a['worker']->getOutput(), $b['worker']->getOutput()], 'exit' => [$a['worker']->getExitCode(), $b['worker']->getExitCode()]];
            file_put_contents($a['path'].'.evidence', json_encode($evidence, JSON_THROW_ON_ERROR));
            $this->assertSame([0, 0], $evidence['exit'], json_encode($evidence));
            $this->assertStringContainsString((string) $a['identity']['pid'], $secondWait->blockers);
            $this->assertSame(2, $owner->table('business_expenditure_summaries')->count());
            $this->assertSame($before + 2, $owner->table('resource_right_fences')->where('boundary_id', $f['boundary']['boundary_id'])->value('rights_epoch'));
            $this->assertSame($beforeBusiness + 2, $owner->table('business_authority_fences')->where('business_id', $f['id'])->value('auth_epoch'));
            $url = '/api/businesses/'.$f['business']['business_public_id'].'/expenditure-summaries';
            foreach ([$f['prepare'], $second] as $body) {
                $this->postJson($url, $body)->assertOk()->assertJsonPath('data.license_id', $body['summary_id']);
            }
            $this->assertSame($before + 2, $owner->table('resource_right_fences')->where('boundary_id', $f['boundary']['boundary_id'])->value('rights_epoch'));
            $this->assertSame($beforeBusiness + 2, $owner->table('business_authority_fences')->where('business_id', $f['id'])->value('auth_epoch'));
        } finally {
            $this->cleanup();
        }
    }

    public function test_preparation_requalifies_source_withdrawal_after_observed_resource_wait(): void
    {
        $f = $this->fixture();
        $a = $this->participant($f, 'summary-prepare', ['body' => $f['prepare']]);
        $owner = DB::connection('pgsql_migrations');
        try {
            $owner->beginTransaction();
            $blocker = (int) $owner->scalar('SELECT pg_backend_pid()');
            $owner->select("SELECT fence_id FROM resource_right_fences WHERE kind='source' AND source_id=?::uuid FOR UPDATE", [$f['source']]);
            file_put_contents($a['payload']['release'], 'go');
            $observed = $this->waitFor(fn () => $this->waiting($a, [$blocker]));
            $qualification = $owner->table('security_action_grants')->where('id', $f['native'])->value('qualification_id');
            BusinessAuthorityFixture::revokeQualification($qualification, $f['user']->id);
            $owner->commit();
            $a['worker']->wait();
            file_put_contents($a['path'].'.evidence', json_encode(['wait' => $observed, 'result' => $a['worker']->getOutput()], JSON_THROW_ON_ERROR));
            $this->assertSame(2, $a['worker']->getExitCode(), $a['worker']->getOutput());
            $this->assertStringContainsString('DENIED_STATE=42501', $a['worker']->getOutput());
            $this->assertSame(0, $owner->table('business_expenditure_summaries')->count());
            $this->assertSame(0, $owner->table('business_command_receipts')->where('command_id', $f['prepare']['command_id'])->count());
        } finally {
            $this->cleanup();
        }
    }

    public static function bindingOrders(): array
    {
        return ['prepare_then_end' => [false], 'end_then_prepare' => [true]];
    }

    #[DataProvider('bindingOrders')]
    public function test_preparation_and_binding_withdrawal_keep_resource_order_and_requalify(bool $bindingFirst): void
    {
        $f = $this->fixture();
        $binding = ['command' => (string) Str::uuid(), 'scope' => $f['useScope'], 'effect' => 'end', 'expected' => 1,
            'selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => $f['manager']->id],
            ]]];
        $a = $this->participant($f, $bindingFirst ? 'binding' : 'summary-prepare', $bindingFirst ? $binding : ['body' => $f['prepare']]);
        $b = $this->participant($f, $bindingFirst ? 'summary-prepare' : 'binding', $bindingFirst ? ['body' => $f['prepare']] : $binding);
        $owner = DB::connection('pgsql_migrations');
        try {
            $owner->beginTransaction();
            $blocker = (int) $owner->scalar('SELECT pg_backend_pid()');
            $owner->statement($bindingFirst ? 'LOCK TABLE business_command_receipts IN SHARE MODE' : 'LOCK TABLE business_expenditure_summaries IN SHARE MODE');
            file_put_contents($a['payload']['release'], 'go');
            $firstWait = $this->waitFor(fn () => $this->waiting($a, [$blocker]));
            file_put_contents($b['payload']['release'], 'go');
            $secondWait = $this->waitFor(fn () => $this->waiting($b, [$a['identity']['pid']]));
            $owner->commit();
            $a['worker']->wait();
            $b['worker']->wait();
            $evidence = ['binding_first' => $bindingFirst, 'first' => $firstWait, 'second' => $secondWait,
                'outputs' => [$a['worker']->getOutput(), $b['worker']->getOutput()], 'exit' => [$a['worker']->getExitCode(), $b['worker']->getExitCode()]];
            file_put_contents($a['path'].'.evidence', json_encode($evidence, JSON_THROW_ON_ERROR));
            $this->assertSame($bindingFirst ? [0, 2] : [0, 0], $evidence['exit'], json_encode($evidence));
            if ($bindingFirst) {
                $this->assertStringContainsString('DENIED_STATE=42501', $b['worker']->getOutput());
                $this->assertSame(0, $owner->table('business_command_receipts')->where('command_id', $f['prepare']['command_id'])->count());
            }
            $this->assertSame($bindingFirst ? 0 : 1, $owner->table('business_expenditure_summaries')->count());
            $this->assertSame('ended', $owner->table('business_data_use_bindings')->where('business_id', $f['id'])->where('data_use_scope_id', $f['useScope'])->value('status'));
            $this->postJson('/api/businesses/'.$f['business']['business_public_id'].'/expenditure-summaries', $f['prepare'])->assertForbidden();
        } finally {
            $this->cleanup();
        }
    }
}
