<?php

namespace Tests\Feature;

use App\Business\ObjectPurpose;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ObjectPurposeTestCase;

class ObjectPurposeConcurrencyTest extends ObjectPurposeTestCase
{
    public function test_canonical_read_holds_coherent_revision_until_transaction_finishes(): void
    {
        $first = $this->write(self::command());
        $command = [...self::command(), 'purpose_id' => $first['purpose_id'], 'expected_version' => 1,
            'operation' => 'replace', 'supersedes_revision_id' => $first['revision_id']];
        $command['confirmations']['transition'] = true;
        $session = DB::connection('pgsql_migrations')->table('security_sessions')->where('user_id', auth()->id())->value('session_key_hash');
        $path = storage_path('framework/testing/purpose-reader-'.Str::uuid().'.json');
        file_put_contents($path, json_encode(['workspace' => $this->workspace->id, 'actor' => auth()->id(),
            'session' => $session, 'command' => $command]));
        $worker = new Process([PHP_BINARY, base_path('tests/ObjectPurposeCommandWorker.php'), $path], base_path());
        $worker->setTimeout(45);
        try {
            $this->context(function ($request, $workspace) use ($worker): void {
                $projection = app(ObjectPurpose::class)->projection($request, $workspace);
                $this->assertSame(1, $projection['items'][0]['head_version']);
                $readerPid = (int) DB::scalar('SELECT pg_backend_pid()');
                $worker->start();
                $blocked = false;
                $deadline = microtime(true) + 20;
                do {
                    if (preg_match('/PID=(\d+)/', $worker->getOutput(), $match)) {
                        $blocked = (bool) DB::scalar('SELECT ?::integer=ANY(pg_blocking_pids(?::integer))', [$readerPid, (int) $match[1]]);
                    }
                    if ($blocked || ! $worker->isRunning()) {
                        break;
                    }
                    usleep(20000);
                } while (microtime(true) < $deadline);
                $this->assertTrue($blocked, 'Real writer must wait for the canonical reader transaction');
                $this->assertSame(1, DB::table('object_purposes')->value('head_version'));
            });
            $worker->wait();
            $this->assertStringContainsString('RESULT=', $worker->getOutput());
            $this->assertSame(2, DB::connection('pgsql_migrations')->table('object_purposes')->value('head_version'));
        } finally {
            if ($worker->isRunning()) {
                $worker->stop();
            }
            @unlink($path);
        }
    }

    public function test_serialized_same_expected_head_has_one_winner(): void
    {
        $first = $this->write(self::command());
        $a = [...self::command(), 'purpose_id' => $first['purpose_id'], 'operation' => 'replace', 'expected_version' => 1, 'supersedes_revision_id' => $first['revision_id']];
        $a['confirmations']['transition'] = true;
        $b = $a;
        $b['command_id'] = (string) Str::uuid();
        $b['declaration']['meaning'] = 'Another business use';
        $outputs = $this->race([$a, $b]);
        $this->assertSame(1, count(array_filter($outputs, fn ($s) => str_contains($s, 'RESULT='))));
        $this->assertSame(1, count(array_filter($outputs, fn ($s) => str_contains($s, 'DENIED=40001'))));
        $this->assertSame(2, DB::connection('pgsql_migrations')->table('object_purpose_revisions')->count());
    }

    public function test_replay_race_creates_one_identity(): void
    {
        $a = self::command();
        $outputs = $this->race([$a, $a]);
        foreach ($outputs as $s) {
            $this->assertStringContainsString('RESULT=', $s);
        }
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('object_purposes')->count());
    }

    public function test_session_expiry_during_observed_wait_leaves_no_effect(): void
    {
        $outputs = $this->race([self::command()], true);
        $this->assertStringContainsString('DENIED=42501', $outputs[0]);
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('object_purposes')->count());
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('object_purpose_command_receipts')->count());
    }

    private function race(array $commands, bool $expire = false): array
    {
        $owner = DB::connection('pgsql_migrations');
        $holder = DB::build([...config('database.connections.pgsql_migrations'), 'name' => 'purpose-holder']);
        $holder->beginTransaction();
        $holderPid = (int) $holder->scalar('SELECT pg_backend_pid()');
        $holder->scalar("SELECT pg_advisory_xact_lock(hashtextextended('builderad.object-purpose.'||?::text,0))", [$this->workspace->id]);
        $session = $owner->table('security_sessions')->where('user_id', auth()->id())->value('session_key_hash');
        $processes = [];
        $paths = [];
        try {
            foreach ($commands as $command) {
                $path = storage_path('framework/testing/purpose-'.Str::uuid().'.json');
                $paths[] = $path;
                file_put_contents($path, json_encode(['workspace' => $this->workspace->id, 'actor' => auth()->id(), 'session' => $session, 'command' => $command, 'expire' => $expire]));
                $process = new Process([PHP_BINARY, base_path('tests/ObjectPurposeCommandWorker.php'), $path], base_path());
                $process->setTimeout(45);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 30;
            do {
                $blocked = 0;
                foreach ($processes as $p) {
                    if (preg_match('/PID=(\d+)/', $p->getOutput(), $match)) {
                        if ((bool) $owner->scalar('SELECT ?::integer=ANY(pg_blocking_pids(?::integer))', [$holderPid, (int) $match[1]])) {
                            $blocked++;
                        }
                    }
                }
                if ($blocked === count($processes)) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertSame(count($processes), $blocked, 'Actual writer lock barriers must be observed');
            if ($expire) {
                do {
                    usleep(10000);
                    $expired = (bool) $owner->scalar('SELECT idle_expires_at<=clock_timestamp() FROM security_sessions WHERE session_key_hash=?', [$session]);
                } while (! $expired && microtime(true) < $deadline);
                $this->assertTrue($expired);
            }
            $holder->commit();
            $outputs = [];
            foreach ($processes as $p) {
                $p->wait();
                $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
                $outputs[] = $p->getOutput();
            }

            return $outputs;
        } finally {
            if ($holder->transactionLevel()) {
                $holder->rollBack();
            }
            foreach ($processes as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }
            foreach ($paths as $path) {
                @unlink($path);
            }
        }
    }
}
