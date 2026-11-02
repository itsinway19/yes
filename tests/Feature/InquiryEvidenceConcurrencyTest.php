<?php

namespace Tests\Feature;

use App\Security\SessionAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\BusinessAuthorityFixture;
use Tests\Support\InquiryEvidenceFixture;
use Tests\TestCase;

final class InquiryEvidenceConcurrencyTest extends TestCase
{
    use InquiryEvidenceFixture;

    public function test_original_basis_revocation_committed_during_observed_business_wait_prevents_write(): void
    {
        $this->contend('basis');
    }

    public function test_session_expires_during_observed_final_receipt_wait_rolls_back_every_effect(): void
    {
        $this->contend('session');
    }

    public function test_two_real_writers_on_same_revision_produce_one_correction_and_one_conflict(): void
    {
        $this->contend('revision');
    }

    private function contend(string $mode): void
    {
        $f = $this->fixture();
        $owner = DB::connection('pgsql_migrations');
        $bid = $owner->table('businesses')->where('public_id', $f['business'])->value('id');
        $record = $this->selection($f['enrolled']['grants']['business.inquiry_evidence.record']);
        $correct = $this->selection($f['enrolled']['grants']['business.inquiry_evidence.correct']);
        $first = $mode === 'revision' ? $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), null, 0, 'record', $this->value(), $record) : null;
        $tag = 'inquiry-'.$mode.'-'.Str::uuid();
        $path = storage_path('framework/testing/'.$tag.'.json');
        $release = $path.'.release';
        $command = (string) Str::uuid();
        file_put_contents($path, json_encode(['tag' => $tag, 'actor' => $f['user']->id, 'session_id' => $f['request']->session()->getId(),
            'generation' => (int) $f['request']->session()->get('a001_generation'), 'release' => $release, 'business' => $f['business'], 'command' => $command,
            'declaration' => $first['declaration_id'] ?? null, 'expected' => $first ? 1 : 0, 'effect' => $first ? 'correct' : 'record', 'value' => $this->value(), 'selection' => $first ? $correct : $record], JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/Support/InquiryEvidenceWorker.php'), $path], base_path());
        $worker->setTimeout(120);
        $evidence = ['mode' => $mode];
        $secondWorker = null;
        $secondPath = null;
        try {
            $worker->start();
            $this->observe(fn () => str_contains($worker->getOutput(), 'READY_PID='), $worker, 60);
            $evidence['first_ready_at'] = microtime(true);
            if ($mode === 'revision') {
                $secondPath = $path.'.second';
                $second = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                $second['tag'] .= '-second';
                $second['command'] = (string) Str::uuid();
                file_put_contents($secondPath, json_encode($second, JSON_THROW_ON_ERROR));
                $secondWorker = new Process([PHP_BINARY, base_path('tests/Support/InquiryEvidenceWorker.php'), $secondPath], base_path());
                $secondWorker->setTimeout(120);
                $secondWorker->start();
                $this->observe(fn () => str_contains($secondWorker->getOutput(), 'READY_PID='), $secondWorker, 60);
                $evidence['second_ready_at'] = microtime(true);
            }
            if ($mode === 'session') {
                $expires = $owner->scalar("SELECT (clock_timestamp()+interval '1500 milliseconds')::text");
                $this->assertSame(1, $owner->table('security_sessions')->where('session_key_hash', SessionAuthority::sessionKey($f['request']))->update(['idle_expires_at' => $expires]));
                $evidence['stored_expiry'] = $owner->table('security_sessions')->where('session_key_hash', SessionAuthority::sessionKey($f['request']))->value('idle_expires_at');
                $evidence['requested_expiry'] = $expires;
                $expires = $evidence['stored_expiry'];
                $evidence['expires'] = $expires; // security_sessions stores second-precision deadlines.
                $owner->beginTransaction();
                $owner->statement('LOCK TABLE business_command_receipts IN SHARE MODE');
            } else {
                $owner->beginTransaction();
                $owner->select('SELECT * FROM business_authority_fences WHERE business_id=? FOR UPDATE', [$bid]);
            }
            $blocker = (int) $owner->scalar('SELECT pg_backend_pid()');
            // The operation watchdog starts only after every worker is genuinely ready.
            $worker->setTimeout(null);
            $secondWorker?->setTimeout(null);
            $evidence['operation_released_at'] = microtime(true);
            file_put_contents($release, 'go');
            preg_match('/READY_PID=(\d+)/', $worker->getOutput(), $firstPid);
            $evidence['wait'] = $this->observe(fn () => DB::selectOne("SELECT pid,backend_xid::text AS xid,wait_event_type,wait_event,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE pid=? AND wait_event_type='Lock' AND (?=ANY(pg_blocking_pids(pid)) OR ?)", [(int) $firstPid[1], $blocker, $mode === 'revision']), $worker);
            $this->assertNotSame($blocker, $evidence['wait']->pid);
            $this->assertNotEmpty($evidence['wait']->xid);
            if ($mode === 'basis') {
                BusinessAuthorityFixture::revokeQualification($f['eligibility']['basis_qualification'], $f['user']->id);
            } elseif ($mode === 'revision') {
                preg_match('/READY_PID=(\d+)/', $secondWorker->getOutput(), $secondPid);
                $evidence['second_wait'] = $this->observe(fn () => DB::selectOne("SELECT pid,wait_event_type,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE pid=? AND wait_event_type='Lock'", [(int) $secondPid[1]]), $secondWorker);
                // PostgreSQL can queue the second tuple waiter behind the first waiter.
                // Observe the exact blocking graph, not a false assumption that both direct blockers equal the holder.
                $waiters = [(int) $firstPid[1], (int) $secondPid[1]];
                $edges = DB::select('SELECT pid,unnest(pg_blocking_pids(pid)) AS blocker FROM pg_stat_activity WHERE pid=ANY(ARRAY[?,?]::integer[])', $waiters);
                $this->assertNotEmpty($edges);
                $this->assertContains($blocker, array_map(fn ($edge) => (int) $edge->blocker, $edges));
                foreach ($edges as $edge) {
                    $this->assertContains((int) $edge->blocker, [$blocker, ...$waiters]);
                }
                $evidence['blocking_graph'] = $edges;
            } else {
                $evidence['expiry_observed_at'] = $this->observe(fn () => $owner->scalar('SELECT CASE WHEN clock_timestamp()>=?::timestamptz THEN clock_timestamp()::text ELSE NULL END', [$expires]), $worker);
            }
            if ($owner->transactionLevel() > 0) {
                $owner->commit();
            }
            $this->observe(fn () => ! $worker->isRunning(), $worker);
            $worker->wait();
            if ($secondWorker) {
                $this->observe(fn () => ! $secondWorker->isRunning(), $secondWorker);
                $secondWorker->wait();
                $codes = [$worker->getExitCode(), $secondWorker->getExitCode()];
                sort($codes);
                $this->assertSame([0, 2], $codes, $worker->getOutput().$secondWorker->getOutput());
                $this->assertSame(1, substr_count($worker->getOutput().$secondWorker->getOutput(), 'DENIED_STATE=I4090'));
                $this->assertSame(1, substr_count($worker->getOutput().$secondWorker->getOutput(), 'COMMITTED='));
                $this->assertSame(2, $owner->table('inquiry_evidence_revisions')->count());
            } else {
                $this->assertSame(2, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
                $this->assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
                $this->assertStringNotContainsString('COMMITTED=', $worker->getOutput());
                $this->assertSame(0, $owner->table('inquiry_evidence_revisions')->count());
                $this->assertSame(0, $owner->table('business_command_receipts')->where('command_id', $command)->count());
            }
        } finally {
            if ($owner->transactionLevel() > 0) {
                $owner->rollBack();
            }
            $evidence['first_before_cleanup'] = ['running' => $worker->isRunning(), 'exit' => $worker->getExitCode(), 'stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput()];
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
            if ($secondWorker) {
                $evidence['second_before_cleanup'] = ['running' => $secondWorker->isRunning(), 'exit' => $secondWorker->getExitCode(), 'stdout' => $secondWorker->getOutput(), 'stderr' => $secondWorker->getErrorOutput()];
                if ($secondWorker->isRunning()) {
                    $secondWorker->stop(1);
                }
                $evidence['second_worker'] = ['stdout' => $secondWorker->getOutput(), 'stderr' => $secondWorker->getErrorOutput(), 'exit' => $secondWorker->getExitCode()];
            }
            $evidence += ['stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput(), 'exit' => $worker->getExitCode()];
            file_put_contents(storage_path('framework/testing/'.$tag.'-evidence.json'), json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            foreach (array_filter([$path, $release, $secondPath]) as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    private function observe(callable $condition, Process $worker, int $seconds = 15): mixed
    {
        $until = microtime(true) + $seconds;
        do {
            if ($value = $condition()) {
                return $value;
            }
            if (! $worker->isRunning()) {
                throw new \RuntimeException('Worker exited before required observation: '.$worker->getOutput().$worker->getErrorOutput());
            }
            usleep(15000);
        } while (microtime(true) < $until);
        throw new \RuntimeException('Required real contention/clock evidence absent: '.$worker->getOutput().$worker->getErrorOutput());
    }
}
