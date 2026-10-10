<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SessionActivityConcurrencyTest extends TestCase
{
    public function test_activity_waits_for_a_live_authority_proof_and_does_not_bypass_it(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $run = storage_path('framework/testing/session-touch-'.bin2hex(random_bytes(8)));
        mkdir($run, 0700, true);
        $fixture = ['actor' => $actor->id, 'session_id' => app('session')->driver()->getId(),
            'run' => basename($run), 'ready' => $run.'/ready', 'release' => $run.'/release'];
        $workers = [];
        try {
            foreach (['holder', 'touch'] as $mode) {
                $path = $run.'/'.$mode.'.json';
                file_put_contents($path, json_encode([...$fixture, 'mode' => $mode], JSON_THROW_ON_ERROR));
                $worker = new Process([PHP_BINARY, base_path('tests/SessionTouchCommandWorker.php'), $path], base_path());
                $worker->setTimeout(35);
                $worker->start();
                $workers[] = $worker;
                // Bootstrap has the existing process timeout (35 seconds). The
                // 25-second lock-observation budget starts after bootstrap.
                // waitUntil only observes newly read chunks; BOOTED can already
                // be buffered by updateStatus on Windows. Inspect all output.
                while (! str_contains($worker->getOutput(), 'BOOTED')) {
                    $worker->checkTimeout();
                    $this->assertTrue($worker->isRunning(),
                        'Session worker exited before bootstrap: '.$worker->getOutput().$worker->getErrorOutput());
                    usleep(10000);
                }
                $deadline = microtime(true) + 25;
                do {
                    clearstatcache();
                    $ready = $mode === 'holder' ? is_file($fixture['ready']) : DB::connection('pgsql_authority')->select(
                        "SELECT pid, query, wait_event, pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE application_name=? AND query LIKE '%security_sessions%' AND wait_event_type='Lock'",
                        [$fixture['run'].'-touch'],
                    );
                    if ($ready) {
                        break;
                    }
                    $this->assertTrue($worker->isRunning(), $worker->getOutput().$worker->getErrorOutput());
                    $this->assertLessThan($deadline, microtime(true), 'Required session activity boundary not witnessed');
                    usleep(10000);
                } while (true);
                if ($mode === 'touch') {
                    file_put_contents($run.'/lock-evidence.json', json_encode($ready, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
                }
            }
            file_put_contents($fixture['release'], 'RELEASE');
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            }
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            // Retain synthetic lock evidence and process output for independent review.
            file_put_contents($run.'/outputs.txt', implode("\n", array_map(fn ($worker) => $worker->getOutput().$worker->getErrorOutput(), $workers)));
            echo 'SESSION_TOUCH_EVIDENCE='.$run.PHP_EOL;
        }
    }
}
