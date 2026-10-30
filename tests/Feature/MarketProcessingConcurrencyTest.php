<?php

namespace Tests\Feature;

use App\MarketKnowledge\Processing\MarketProcessingTask;
use App\MarketKnowledge\Processing\OfferEquivalence;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\BusinessAuthorityFixture as Fixture;
use Tests\Support\MarketProcessingFixture;
use Tests\TestCase;

final class MarketProcessingConcurrencyTest extends TestCase
{
    use MarketProcessingFixture;

    public function test_source_withdrawal_after_observed_resource_contention_denies_completion(): void
    {
        $this->contendedCompletion(false);
    }

    public function test_right_expiry_at_observed_final_receipt_wait_rolls_back_the_entire_completion(): void
    {
        $this->contendedCompletion(true);
    }

    public function test_completed_replay_requalifies_after_a_real_run_row_wait(): void
    {
        $this->contendedCompletion(true, true);
    }

    private function contendedCompletion(bool $receiptWait, bool $replay = false): void
    {
        $f = $this->processingOriginals();
        $c = $this->processingConsumer($f);
        $body = $this->processingBody($f, $c);
        $this->prepareProcessing($c, $body)->assertOk();
        $task = new MarketProcessingTask('compare_offers', array_map(fn ($record) => ['ref' => $record['ref'], 'fields' => $record['payload']['fields'], 'evidence' => ['schema' => 'market.offer:2']], $f['records']));
        $finding = (new OfferEquivalence)->execute($task);
        $request = Request::create('/');
        $request->setLaravelSession(app('session')->driver());
        $tag = 'processing-'.Str::uuid();
        $path = storage_path('framework/testing/'.$tag.'.json');
        $release = $path.'.release';
        $command = (string) Str::uuid();
        if ($replay) {
            $this->completeProcessing($c, $body['command_id'], ['command_id' => $command, 'effect' => 'complete', 'authority_selection' => $c['selection'], 'context_selection' => null])->assertOk()->assertJsonPath('data.state', 'validated');
        }
        file_put_contents($path, json_encode(['run' => $tag, 'operation' => 'market-processing-complete', 'actor' => $c['user']->id,
            'session' => SessionAuthority::sessionKey($request), 'business' => $c['business']['business_public_id'], 'processing_run' => $body['command_id'],
            'command' => $command, 'outcome' => ['state' => 'validated', 'finding' => $finding, 'reason_code' => null],
            'selection' => $c['selection'], 'release' => $release], JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $path], base_path());
        $worker->setTimeout(45);
        $owner = DB::connection('pgsql_migrations');
        $evidence = ['mode' => $receiptWait ? 'final_receipt_expiry' : 'source_withdrawal'];
        try {
            $worker->start();
            $this->waitFor(fn () => str_contains($worker->getOutput(), 'READY_PID='), $worker);
            if ($receiptWait) {
                $evidence['expiry'] = Fixture::expireQualificationSoon($c['rights'][0]['qualification_id'], $c['user']->id);
            }
            $owner->beginTransaction();
            $blocker = (int) $owner->scalar('SELECT pg_backend_pid()');
            if ($replay) {
                $owner->select('SELECT run_id FROM market_processing_runs WHERE run_id=?::uuid FOR UPDATE', [$body['command_id']]);
            } elseif ($receiptWait) {
                $owner->statement('LOCK TABLE business_command_receipts IN SHARE MODE');
            } else {
                $owner->select("SELECT fence_id FROM resource_right_fences WHERE kind='source' AND source_id=?::uuid FOR UPDATE", [$f['source']]);
            }
            file_put_contents($release, 'ready');
            $evidence['wait'] = $this->waitFor(function () use ($tag, $blocker) {
                foreach (DB::select('SELECT pid,backend_xid::text AS xid,wait_event_type,wait_event,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE application_name=?', [$tag]) as $row) {
                    if ($row->wait_event_type === 'Lock' && in_array($blocker, json_decode(str_replace(['{', '}'], ['[', ']'], $row->blockers), true), true)) {
                        return (array) $row;
                    }
                }

                return false;
            }, $worker);
            $this->assertNotEmpty($evidence['wait']['xid']);
            $this->assertNotSame($blocker, $evidence['wait']['pid']);
            if ($replay) {
                $this->waitFor(fn () => $owner->scalar('SELECT clock_timestamp()>=?::timestamptz', [$evidence['expiry']]), $worker);
            } elseif ($receiptWait) {
                $evidence['receipt_lock'] = DB::select("SELECT relation::regclass::text AS relation,mode,granted FROM pg_locks WHERE pid=? AND relation='business_command_receipts'::regclass AND NOT granted", [$evidence['wait']['pid']]);
                $this->assertCount(1, $evidence['receipt_lock']);
                $this->waitFor(fn () => $owner->scalar('SELECT clock_timestamp()>=?::timestamptz', [$evidence['expiry']]), $worker);
            } else {
                $this->admit('restrict_source', $f['source'], 1, ['reason' => 'SYNTHETIC source withdrawal while completion waits']);
            }
            $owner->commit();
            $worker->wait();
            $evidence['output'] = $worker->getOutput();
            $this->assertSame(2, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
            $this->assertStringNotContainsString('COMMITTED=', $worker->getOutput());
            $this->assertSame($replay ? 'validated' : 'prepared', $owner->table('market_processing_runs')->value('state'));
            if (! $replay) {
                $this->assertNull($owner->table('market_processing_runs')->value('finding'));
            }
            $this->assertSame($replay ? 1 : 0, (int) $owner->table('market_processing_runs')->value('execution_count'));
            $this->assertSame($replay ? 1 : 0, $owner->table('business_command_receipts')->where('command_id', $command)->count());
        } finally {
            if ($owner->transactionLevel() > 0) {
                $owner->rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
            file_put_contents(storage_path('framework/testing/'.$tag.'-evidence.json'), json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            foreach ([$path, $release] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    private function waitFor(callable $condition, Process $worker): mixed
    {
        $until = microtime(true) + 15;
        do {
            if ($value = $condition()) {
                return $value;
            }
            if (! $worker->isRunning()) {
                throw new \RuntimeException('Worker exited before required observation: '.$worker->getOutput().$worker->getErrorOutput());
            }
            usleep(15000);
        } while (microtime(true) < $until);
        throw new \RuntimeException('Required actual contention/clock evidence absent: '.$worker->getOutput().$worker->getErrorOutput());
    }
}
