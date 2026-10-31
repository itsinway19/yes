<?php

namespace Tests\Feature;

use App\Business\BusinessContext;
use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CurrentIntentConcurrencyTest extends TestCase
{
    private array $workerStarts = [];

    private function value(): array
    {
        return ['direction' => 'increase_value', 'scope' => 'business_wide', 'description' => null,
            'outcome' => ['meaning' => 'Bookings', 'clarification' => null, 'count_unit' => 'booking'],
            'quantity' => '100', 'horizon' => ['deadline_date' => '2026-10-05', 'timezone' => 'UTC']];
    }

    private function fixture(): array
    {
        $actor = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $this->actingAs($actor)->post('/workspaces', ['name' => 'Intent race', 'slug' => 'intent-race'])->assertRedirect();
        $db = DB::connection('pgsql_migrations');
        $workspace = $db->table('workspaces')->where('slug', 'intent-race')->first();
        $session = $db->table('security_sessions')->where('user_id', $actor->id)->first();

        return [$workspace, $session, ['actor' => $actor->id, 'workspace' => $workspace->id,
            'session' => $session->session_key_hash, 'operation' => 'intent', 'version' => 0, 'value' => $this->value(),
            'confirmations' => ['declaration' => true, 'business_wide' => true, 'outcome' => true, 'acceptance_clear' => true, 'quantity' => true, 'horizon' => true],
            'type' => $db->table('business_types')->where('key', 'real_estate')->value('id'), 'name' => 'Concurrent name', 'codes' => ['SA', 'KW']]];
    }

    private function waitUntil(callable $probe, ?callable $timeoutDetails = null): mixed
    {
        $deadline = microtime(true) + 25;
        do {
            if ($value = $probe()) {
                return $value;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Required database/process barrier was not witnessed.'.($timeoutDetails ? ' '.json_encode($timeoutDetails(), JSON_THROW_ON_ERROR) : ''));
    }

    private function start(array $fixture, array &$workers, array &$paths): Process
    {
        $path = storage_path('framework/testing/'.$fixture['run'].'-'.Str::uuid().'.json');
        // The guarded runner gives every invocation a fresh Laravel storage.
        // A focused invocation must not depend on an earlier test creating this.
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));
        $paths[] = $path;
        $worker = new Process([PHP_BINARY, base_path('tests/CurrentIntentCommandWorker.php'), $path], base_path());
        $worker->setTimeout(45);
        $worker->start();
        $this->workerStarts[spl_object_id($worker)] = ['pid' => $worker->getPid(), 'started_at' => microtime(true), 'command' => $worker->getCommandLine()];
        $workers[] = $worker;

        return $worker;
    }

    private function waiting(string $run, int $blocker): array
    {
        return array_values(array_filter(DB::select('SELECT pid,backend_xid::text AS xid,wait_event_type,wait_event,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE application_name=? ORDER BY pid', [$run]),
            fn ($row) => $row->wait_event_type === 'Lock' && str_contains($row->blockers, (string) $blocker)));
    }

    private function externalReady(Process $worker, array $gate, string $signal): object
    {
        return $this->waitUntil(function () use ($worker, $gate, $signal) {
            clearstatcache(true, $gate['ready']);
            if (is_file($gate['ready']) && str_contains($worker->getOutput(), $signal)) {
                return json_decode(file_get_contents($gate['ready']), flags: JSON_THROW_ON_ERROR);
            }
            if (! $worker->isRunning()) {
                $this->fail('Worker exited before '.$signal.': '.json_encode([
                    'startup' => $this->workerStarts[spl_object_id($worker)] ?? null,
                    'exit' => $worker->getExitCode(), 'exit_text' => $worker->getExitCodeText(),
                    'stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput(),
                    'ready_file_exists' => is_file($gate['ready']),
                ], JSON_THROW_ON_ERROR));
            }

            return false;
        }, fn () => ['phase' => $signal, 'pid' => $worker->getPid(), 'running' => $worker->isRunning(), 'exit' => $worker->getExitCode(), 'stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput(), 'ready_file_exists' => is_file($gate['ready'])]);
    }

    public function test_equal_and_different_full_bundle_races(): void
    {
        $this->race('equal');
    }

    public function test_different_full_bundle_race(): void
    {
        $this->race('different');
    }

    public function test_intent_and_name_type_both_schedules_and_synchronized_entry(): void
    {
        foreach ([0, 1, null] as $first) {
            $this->race('name', $first);
        }
    }

    public function test_intent_and_markets_both_schedules_and_synchronized_entry(): void
    {
        foreach ([0, 1, null] as $first) {
            $this->race('dtm', $first);
        }
    }

    public function test_intent_and_outcome_full_bundle_both_schedules(): void
    {
        foreach ([0, 1] as $first) {
            $this->race('outcome', $first);
        }
    }

    public function test_preacquisition_expiry_revocation_and_prepared_entry_invalidation(): void
    {
        foreach (['grant_expiry', 'session_expiry', 'grant_revocation', 'session_revocation', 'generation', 'membership', 'owner'] as $change) {
            $this->race('equal', authorityChange: $change);
        }
    }

    public function test_equal_noop_waits_recheck_expired_and_revoked_authority(): void
    {
        foreach (['grant_expiry', 'session_expiry', 'grant_revocation', 'session_revocation'] as $change) {
            $this->race('equal', authorityChange: $change, noop: true);
        }
    }

    private function race(string $other, ?int $first = null, ?string $authorityChange = null, bool $noop = false): void
    {
        [$workspace, $session, $fixture] = $this->fixture();
        $db = DB::connection('pgsql_migrations');
        if ($noop) {
            $request = Request::create('/', 'POST');
            $request->setLaravelSession($this->app['session']->driver());
            $request->setUserResolver(fn () => auth()->user());
            app(WorkspaceContext::class)->runBusinessMutation(auth()->user(), $workspace->public_id,
                fn ($active) => app(BusinessContext::class)->replaceCurrentIntent($request, $active, $fixture['value'], $fixture['confirmations'], 0), $request);
            $workspace = $db->table('workspaces')->find($workspace->id);
        }
        $before = [(array) $workspace, $db->table('business_current_intent_events')->get()->toArray()];
        $run = 'intent-race-'.Str::uuid();
        $fixture['run'] = $run;
        $second = $fixture;
        if (in_array($other, ['name', 'dtm'], true)) {
            $second['operation'] = $other;
        } elseif ($other === 'outcome') {
            $second['value']['outcome']['meaning'] = 'Retained customers';
        } elseif ($other === 'different') {
            $second['value']['direction'] = 'preserve_value';
        }
        $holder = DB::build([...config('database.connections.pgsql_migrations'), 'name' => $run.'-holder']);
        $entry = DB::build([...config('database.connections.pgsql_migrations'), 'name' => $run.'-entry']);
        $workers = $paths = [];
        $evidence = ['baseline' => '2cf51ea337b26e07bfcaff59ed9d3e81c632a50e', 'run' => $run, 'other' => $other,
            'preferred_first' => $first, 'authority_change' => $authorityChange, 'noop' => $noop, 'before' => $before];
        $record = static function (string $event, array $details = []) use (&$evidence): void {
            if (str_starts_with($event, 'catalog_') && count($evidence['timing'] ?? []) >= 100) {
                $evidence['catalog_timing_samples_omitted'] = ($evidence['catalog_timing_samples_omitted'] ?? 0) + 1;

                return;
            }
            $evidence['timing'][] = ['event' => $event, 'mono_ns' => hrtime(true), 'wall_s' => microtime(true), ...$details];
        };
        try {
            if ($first === null) {
                $barrier = 'builderad.test.entry.'.$run;
                $fixture['entry_barrier'] = $second['entry_barrier'] = $barrier;
            }
            // Both processes finish PHP/bootstrap before spending the unchanged
            // product lock budget. Their readiness has no open transaction/xid.
            $byIndex = $startupGates = [];
            foreach ([0, 1] as $index) {
                $startupGates[$index] = ['ready' => storage_path('framework/testing/'.$run.'-entry-'.$index.'-ready'),
                    'release' => storage_path('framework/testing/'.$run.'-entry-'.$index.'-release')];
                array_push($paths, ...array_values($startupGates[$index]));
                $participant = [...($index === 0 ? $fixture : $second), 'entry_external_gate' => $startupGates[$index]];
                $byIndex[$index] = $this->start($participant, $workers, $paths);
                $evidence['pre_ready'][$index] = $this->externalReady($byIndex[$index], $startupGates[$index], 'ENTRY_READY');
                $this->assertNull($evidence['pre_ready'][$index]->xid);
            }
            $record('holder_transaction_start');
            $holder->beginTransaction();
            $holderIdentity = $holder->selectOne('SELECT pg_backend_pid() AS pid,pg_current_xact_id()::text AS xid');
            $blocker = (int) $holderIdentity->pid;
            $evidence['holder_identity'] = $holderIdentity;
            if (in_array($authorityChange, ['generation', 'membership', 'owner'], true)) {
                $holder->selectOne("SELECT pg_advisory_xact_lock(hashtextextended('builderad.business.context.'||?::text,0))", [$workspace->id]);
            } else {
                $holder->table('security_sessions')->where('id', $session->id)->lockForUpdate()->first();
            }
            $record('holder_lock_acquired');
            if ($first === null) {
                $entry->beginTransaction();
                $entryPid = (int) $entry->scalar('SELECT pg_backend_pid()');
                $entry->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$barrier]);
            }
            foreach ($first === null ? [0, 1] : [$first, 1 - $first] as $position => $index) {
                $record('participant_release', ['index' => $index]);
                if (file_put_contents($startupGates[$index]['release'], 'RELEASE') === false) {
                    $this->fail('Cannot release prepared concurrency participant.');
                }
                if ($first !== null && $position === 0) {
                    $evidence['first_wait_before_second_entry'] = $this->waitUntil(fn () => $this->waiting($run, $blocker));
                }
            }
            if ($first === null) {
                $evidence['synchronized_entry'] = $this->waitUntil(fn () => count($rows = $this->waiting($run, $entryPid)) === 2 ? $rows : false);
                foreach ($workers as $worker) {
                    $this->assertStringContainsString('READY_XACT=', $worker->getOutput());
                }
                $record('entry_release_start');
                $entry->commit();
                $record('entry_release_end');
            }
            // Capture the live holder boundary and its locks in one query. A
            // cached/transitional wait_event alone can still describe the entry
            // barrier just released. Separate catalog probes also spend the
            // participants' unchanged 3s lock budget while we hold the fixture.
            $record('observation_start');
            $sample = $this->waitUntil(function () use ($run, $blocker, $workspace, $record) {
                $record('catalog_query_start');
                $row = DB::selectOne(<<<'SQL'
                    WITH participants AS MATERIALIZED (
                      SELECT pid,backend_xid::text AS xid,query_start::text AS query_start,wait_event_type,wait_event,
                             pg_blocking_pids(pid)::text AS blockers
                      FROM pg_stat_activity WHERE application_name=?
                    ), observed_locks AS MATERIALIZED (
                      SELECT pid,locktype,mode,granted,relation::regclass::text AS relation,
                             transactionid::text AS xid,
                             (locktype='advisory' AND objsubid=1 AND
                              ((classid::bigint << 32) | objid::bigint)=
                              hashtextextended('builderad.business.context.'||?::text,0)) AS workspace_lock
                      FROM pg_locks WHERE pid IN (SELECT pid FROM participants) OR pid=?
                    )
                    SELECT clock_timestamp()::text AS captured_at,
                           (SELECT json_agg(p ORDER BY pid) FROM participants p) AS waiting,
                           (SELECT json_agg(l ORDER BY pid,locktype,mode) FROM observed_locks l) AS locks
                    SQL, [$run, $workspace->id, $blocker]);
                $record('catalog_query_end');
                $waiting = json_decode($row->waiting ?? '[]', flags: JSON_THROW_ON_ERROR);
                $locks = json_decode($row->locks ?? '[]', flags: JSON_THROW_ON_ERROR);
                $blockedByHolder = array_filter($waiting, fn ($waiter) => $waiter->wait_event_type === 'Lock'
                    && in_array($blocker, explode(',', trim($waiter->blockers, '{}'))));
                $queued = array_unique(array_column(array_filter($locks, fn ($lock) => ! $lock->granted), 'pid'));
                if (count($waiting) !== 2 || count($queued) !== 2 || ! $blockedByHolder) {
                    return false;
                }

                return ['waiting' => $waiting, 'locks' => $locks, 'blocked_by_holder' => array_values($blockedByHolder),
                    'captured_at' => $row->captured_at];
            });
            $record('observation_end');
            $evidence = [...$evidence, ...$sample];
            $this->assertNotEmpty($sample['blocked_by_holder']);
            if ($authorityChange === 'generation') {
                $db->table('principal_lifecycles')->where('user_id', $fixture['actor'])->increment('generation');
            } elseif ($authorityChange === 'membership') {
                $db->table('workspace_memberships')->where('workspace_id', $workspace->id)->update(['status' => 'revoked', 'revoked_at' => DB::raw('clock_timestamp()')]);
            } elseif ($authorityChange === 'owner') {
                $db->table('users')->where('id', $fixture['actor'])->update(['email_verified_at' => null]);
            } elseif ($authorityChange === 'grant_revocation') {
                $db->table('security_action_grants')->where('workspace_id', $workspace->id)->where('action', 'business.context.manage')->update(['status' => 'revoked', 'revoked_at' => DB::raw('clock_timestamp()')]);
            } elseif ($authorityChange === 'session_revocation') {
                $holder->table('security_sessions')->where('id', $session->id)->update(['revoked_at' => DB::raw('clock_timestamp()')]);
            } elseif ($authorityChange === 'grant_expiry') {
                $db->table('security_action_grants')->where('workspace_id', $workspace->id)->where('action', 'business.context.manage')->update(['expires_at' => DB::raw("clock_timestamp()+interval '250 milliseconds'")]);
                $this->waitUntil(fn () => $db->scalar("SELECT bool_and(expires_at<=clock_timestamp()) FROM security_action_grants WHERE workspace_id=? AND action='business.context.manage'", [$workspace->id]));
            } elseif ($authorityChange === 'session_expiry') {
                $holder->table('security_sessions')->where('id', $session->id)->update(['idle_expires_at' => DB::raw("clock_timestamp()+interval '250 milliseconds'")]);
                $this->waitUntil(fn () => $holder->scalar('SELECT idle_expires_at<=clock_timestamp() FROM security_sessions WHERE id=?', [$session->id]));
            }
            $record('holder_release_start');
            $holder->commit();
            $record('holder_release_end');
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            }
            ksort($byIndex);
            $outputs = array_map(fn ($worker) => $worker->getOutput(), $byIndex);
            $evidence['outputs'] = $outputs;
            $after = $db->table('workspaces')->find($workspace->id);
            $events = $db->table('business_current_intent_events')->get();
            $evidence['after'] = [(array) $after, $events->all()];
            $this->assertStringNotContainsString('DENIED=40P01', implode('', $outputs));
            if ($authorityChange !== null) {
                foreach ($outputs as $output) {
                    $this->assertStringContainsString('DENIED=42501', $output);
                }
                $this->assertEquals($before, $evidence['after']);
                $this->assertSame(0, $db->table('business_context_events')->count());
                $this->assertSame(0, $db->table('business_target_market_events')->count());
            } else {
                $this->assertSame($other === 'equal' ? 2 : 1, count(array_filter($outputs, fn ($output) => str_contains($output, 'RESULT=1'))));
                $this->assertSame($other === 'equal' ? 0 : 1, count(array_filter($outputs, fn ($output) => str_contains($output, 'DENIED=40001'))));
                $this->assertSame(1, (int) $after->business_context_version);
                $this->assertSame(1, $events->count() + $db->table('business_context_events')->count() + $db->table('business_target_market_events')->count());
                if ($first !== null) {
                    $this->assertStringContainsString('RESULT=1', $outputs[$first]);
                }
                if ($events->isNotEmpty()) {
                    $winning = str_contains($outputs[0], 'RESULT=1') ? $fixture : $second;
                    $this->assertEquals($winning['value'], json_decode($after->current_intent, true));
                    $this->assertEquals($winning['value'], json_decode($events[0]->after_value, true));
                    $this->assertNull($events[0]->before_value);
                    $this->assertSame(0, (int) $events[0]->before_version);
                    $this->assertSame(1, (int) $events[0]->after_version);
                    $this->assertSame((int) $fixture['actor'], (int) $events[0]->actor_user_id);
                } else {
                    $this->assertNull($after->current_intent);
                    if ($other === 'name') {
                        $this->assertSame('Concurrent name', $after->name);
                        $this->assertSame((int) $fixture['type'], (int) $after->business_type_id);
                    } else {
                        $this->assertSame(['KW', 'SA'], $db->table('business_target_markets')->orderBy('country_code')->pluck('country_code')->all());
                    }
                }
            }
        } finally {
            foreach ([$entry, $holder] as $connection) {
                if ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
                $connection->disconnect();
            }
            $evidence['worker_processes_before_cleanup'] = array_map(fn ($worker) => ['pid' => $worker->getPid(), 'running' => $worker->isRunning()], $workers);
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            // Preserve the exact failure phase even if the barrier assertion
            // failed before normal output collection; never disguise 55P03.
            $evidence['final_worker_outputs'] = array_map(fn ($worker) => $worker->getOutput().$worker->getErrorOutput(), $workers);
            $evidence['worker_exit_details'] = array_map(fn ($worker) => [
                'startup' => $this->workerStarts[spl_object_id($worker)] ?? null,
                'exit' => $worker->getExitCode(), 'exit_text' => $worker->getExitCodeText(),
                'stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput(),
            ], $workers);
            $evidence['worker_timings'] = array_map(fn ($path) => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR),
                glob(storage_path('framework/testing/'.$run.'-participant-*.log.timing.json')));
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $path = storage_path('framework/testing/'.$run.'-evidence.json');
            file_put_contents($path, json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            fwrite(STDOUT, 'INTENT_LOCK_EVIDENCE='.$path.PHP_EOL);
        }
        // Each schedule is independent; no implicit stale retry. Tear down synthetic
        // fixtures through the existing guarded per-test setup before another run.
        $db->statement('TRUNCATE workspaces,users RESTART IDENTITY CASCADE');
        auth()->forgetUser();
    }

    public function test_real_reader_writer_interleavings_return_one_complete_committed_state(): void
    {
        foreach (['replace', 'clear', 'name', 'dtm'] as $operation) {
            foreach (['reader_first', 'writer_first', 'synchronized'] as $schedule) {
                $this->readRace($operation, $schedule);
            }
        }
    }

    private function readRace(string $operation, string $schedule): void
    {
        [$workspace, , $fixture] = $this->fixture();
        $db = DB::connection('pgsql_migrations');
        $run = 'intent-read-'.Str::uuid();
        $fixture['run'] = $run;
        $request = Request::create('/', 'POST');
        $request->setLaravelSession($this->app['session']->driver());
        $request->setUserResolver(fn () => auth()->user());
        app(WorkspaceContext::class)->runBusinessMutation(auth()->user(), $workspace->public_id,
            fn ($active) => app(BusinessContext::class)->replaceCurrentIntent($request, $active, $fixture['value'], $fixture['confirmations'], 0), $request);
        $prior = $fixture['value'];
        $fixture['version'] = 1;
        $readerFixture = [...$fixture, 'operation' => 'read', 'generation' => 1,
            'http_session_id' => $this->app['session']->driver()->getId(), 'workspace_public_id' => $workspace->public_id];
        $next = $prior;
        if ($operation === 'replace') {
            $next = ['direction' => 'validate_business_direction', 'scope' => 'business_wide', 'description' => 'New direction sentinel',
                'outcome' => ['meaning' => 'New result sentinel', 'clarification' => 'New acceptance sentinel', 'count_unit' => 'new unit'],
                'quantity' => '987654321098765432109876543210', 'horizon' => ['deadline_date' => '2027-03-04', 'timezone' => 'Pacific/Apia']];
            $fixture['value'] = $next;
            $fixture['confirmations']['clarification_required'] = true;
        } elseif ($operation === 'clear') {
            $next = $fixture['value'] = null;
            $fixture['confirmations'] = ['declaration' => true];
        } else {
            $fixture['operation'] = $operation;
        }
        $holder = DB::build([...config('database.connections.pgsql_migrations'), 'name' => $run.'-holder']);
        $workers = $paths = [];
        $evidence = ['run' => $run, 'operation' => $operation, 'schedule' => $schedule, 'prior' => $prior, 'next' => $next];
        $trigger = false;
        try {
            $pid = (int) $holder->scalar('SELECT pg_backend_pid()');
            $barrier = 'builderad.test.read.'.$run;
            if ($schedule !== 'reader_first') {
                $holder->beginTransaction();
                $holder->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$barrier]);
            }
            if ($schedule === 'reader_first') {
                $gates = [];
                foreach (['writer', 'reader'] as $role) {
                    $gates[$role] = ['ready' => storage_path('framework/testing/'.$run.'-'.$role.'-ready'),
                        'release' => storage_path('framework/testing/'.$run.'-'.$role.'-release')];
                    array_push($paths, ...array_values($gates[$role]));
                }
                $fixture['writer_external_gate'] = $gates['writer'];
                $writer = $this->start($fixture, $workers, $paths);
                $evidence['writer_pre_ready'] = $this->externalReady($writer, $gates['writer'], 'WRITER_READY');
                $this->assertNull($evidence['writer_pre_ready']->xid);
                $readerFixture['reader_external_gate'] = $gates['reader'];
                $reader = $this->start($readerFixture, $workers, $paths);
                $evidence['reader_at_selected_scope'] = $this->externalReady($reader, $gates['reader'], 'READER_HOLD_READY');
                $this->assertStringContainsString('SELECTED_XACT=', $reader->getOutput());
                $this->assertNotNull($evidence['reader_at_selected_scope']->xid);
                $readerPid = (int) $evidence['reader_at_selected_scope']->pid;
                if (file_put_contents($gates['writer']['release'], 'RELEASE') === false) {
                    $this->fail('Cannot release external writer gate.');
                }
                $evidence['writer_waits_on_reader'] = $this->waitUntil(fn () => $this->waiting($run, $readerPid));
            } elseif ($schedule === 'writer_first') {
                // Bootstrap the reader outside any product transaction before the writer
                // reaches its bounded SQL lock wait. PHP startup is not lock contention.
                $readerGate = ['ready' => storage_path('framework/testing/'.$run.'-reader-entry-ready'),
                    'release' => storage_path('framework/testing/'.$run.'-reader-entry-release')];
                array_push($paths, ...array_values($readerGate));
                $readerFixture['entry_external_gate'] = $readerGate;
                $reader = $this->start($readerFixture, $workers, $paths);
                $evidence['reader_pre_ready'] = $this->externalReady($reader, $readerGate, 'ENTRY_READY');
                $this->assertNull($evidence['reader_pre_ready']->xid);
                $table = match ($operation) {
                    'name' => 'business_context_events', 'dtm' => 'business_target_market_events', default => 'business_current_intent_events',
                };
                $literal = $db->getPdo()->quote($barrier);
                $db->unprepared('CREATE FUNCTION builderad_test_read_wait() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_advisory_xact_lock_shared(hashtextextended('.$literal.',0)); RETURN NEW; END $$');
                $db->statement('CREATE TRIGGER test_read_wait AFTER INSERT ON '.$table.' FOR EACH ROW EXECUTE FUNCTION builderad_test_read_wait()');
                $trigger = true;
                $writer = $this->start($fixture, $workers, $paths);
                $evidence['writer_at_effect'] = $this->waitUntil(fn () => $this->waiting($run, $pid));
                $writerPid = (int) $evidence['writer_at_effect'][0]->pid;
                if (file_put_contents($readerGate['release'], 'RELEASE') === false) {
                    $this->fail('Cannot release prepared reader entry gate.');
                }
                $evidence['reader_waits_on_writer'] = $this->waitUntil(fn () => $this->waiting($run, $writerPid),
                    fn () => ['writer' => $writer->getOutput(), 'reader' => $reader->getOutput(),
                        'writer_running' => $writer->isRunning(), 'reader_running' => $reader->isRunning()]);
            } else {
                $fixture['entry_barrier'] = $readerFixture['entry_barrier'] = $barrier;
                $reader = $this->start($readerFixture, $workers, $paths);
                $writer = $this->start($fixture, $workers, $paths);
                $evidence['synchronized_entry'] = $this->waitUntil(fn () => count($rows = $this->waiting($run, $pid)) === 2 ? $rows : false);
            }
            $evidence['locks'] = DB::select('SELECT pid,locktype,mode,granted,relation::regclass::text AS relation FROM pg_locks WHERE pid IN (SELECT pid FROM pg_stat_activity WHERE application_name=?) OR pid=? ORDER BY pid,locktype,mode', [$run, $pid]);
            if ($schedule === 'reader_first') {
                if (file_put_contents($gates['reader']['release'], 'RELEASE') === false) {
                    $this->fail('Cannot release external reader gate.');
                }
            } else {
                $holder->commit();
            }
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $this->assertStringNotContainsString('DENIED=', $worker->getOutput());
            }
            $this->assertStringContainsString('RESULT=2', $writer->getOutput());
            preg_match('/^PROJECTION=(.+)$/m', $reader->getOutput(), $match);
            $this->assertNotEmpty($match, $reader->getOutput());
            $projection = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
            $evidence['projection'] = $projection;
            $evidence['outputs'] = [$reader->getOutput(), $writer->getOutput()];
            $this->assertContains($projection['version'], $schedule === 'reader_first' ? [1] : ($schedule === 'writer_first' ? [2] : [1, 2]));
            $this->assertSame($projection['version'], $projection['currentIntent']['version']);
            $expected = $projection['version'] === 1 ? $prior : $next;
            $this->assertEquals($expected, $projection['currentIntent']['value']);
            $this->assertSame($expected === null ? 'NOT_DECLARED' : 'PRESENT', $projection['currentIntent']['state']);
            $after = $db->table('workspaces')->find($workspace->id);
            $this->assertSame(2, (int) $after->business_context_version);
            $this->assertEquals($next, $after->current_intent === null ? null : json_decode($after->current_intent, true));
        } finally {
            if ($holder->transactionLevel() > 0) {
                $holder->rollBack();
            }
            $holder->disconnect();
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            if ($trigger) {
                $db->statement('DROP TRIGGER IF EXISTS test_read_wait ON '.$table);
                $db->statement('DROP FUNCTION IF EXISTS builderad_test_read_wait()');
            }
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $path = storage_path('framework/testing/'.$run.'-evidence.json');
            file_put_contents($path, json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            fwrite(STDOUT, 'INTENT_READ_EVIDENCE='.$path.PHP_EOL);
            $db->statement('TRUNCATE workspaces,users RESTART IDENTITY CASCADE');
            auth()->forgetUser();
        }
    }

    public function test_audit_insert_wait_rechecks_grant_and_session_and_serializes_revocation(): void
    {
        foreach (['grant', 'session', 'revocation'] as $change) {
            $this->effectWait('intent', $change);
        }
    }

    private function effectWait(string $operation, string $authorityChange): void
    {
        $actor = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $this->actingAs($actor)->post('/workspaces', ['name' => 'Effect clock business', 'slug' => 'effect-clock'])->assertRedirect();
        $owner = DB::connection('pgsql_migrations');
        $workspace = $owner->table('workspaces')->where('slug', 'effect-clock')->first();
        $session = $owner->table('security_sessions')->where('user_id', $actor->id)->first();
        $run = 'intent-effect-'.Str::uuid();
        $barrier = 'builderad.test.effect.'.$run;
        $eventTable = 'business_current_intent_events';
        $fixture = ['run' => $run, 'actor' => $actor->id, 'workspace' => $workspace->id,
            'session' => $session->session_key_hash, 'type' => $owner->table('business_types')->where('key', 'real_estate')->value('id'),
            'name' => $workspace->name, 'version' => 0, 'operation' => $operation, 'codes' => ['KW', 'SA'],
            ...($authorityChange === 'revocation' ? [] : ['expiry_after_ready' => $authorityChange])];
        $fixture['value'] = $this->value();
        $fixture['confirmations'] = ['declaration' => true, 'business_wide' => true, 'outcome' => true, 'acceptance_clear' => true, 'quantity' => true, 'horizon' => true];
        $fixturePath = storage_path('framework/testing/'.$run.'-fixture.json');
        $revokerPath = storage_path('framework/testing/'.$run.'-revoker-fixture.json');
        $evidencePath = storage_path('framework/testing/'.$run.'.json');
        file_put_contents($fixturePath, json_encode($fixture, JSON_THROW_ON_ERROR));
        $holder = DB::build([...config('database.connections.pgsql_migrations'), 'name' => $run.'-holder']);
        $revokerHolder = DB::build([...config('database.connections.pgsql_migrations'), 'name' => $run.'-revoker-holder']);
        $workers = [];
        $evidence = ['run' => $run, 'operation' => $operation, 'authority_change' => $authorityChange, 'before' => $workspace];
        try {
            $literal = $owner->getPdo()->quote($barrier);
            $owner->unprepared('CREATE FUNCTION builderad_test_effect_wait() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_advisory_xact_lock_shared(hashtextextended('.$literal.',0)); RETURN NEW; END $$');
            $owner->statement('CREATE TRIGGER test_effect_wait AFTER INSERT ON '.$eventTable.' FOR EACH ROW EXECUTE FUNCTION builderad_test_effect_wait()');
            $holder->beginTransaction();
            $evidence['blocker_pid'] = (int) $holder->scalar('SELECT pg_backend_pid()');
            $holder->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$barrier]);
            if ($authorityChange === 'revocation') {
                $revocationBarrier = $barrier.'-revoker';
                $revokerHolder->beginTransaction();
                $evidence['revoker_gate_pid'] = (int) $revokerHolder->scalar('SELECT pg_backend_pid()');
                $revokerHolder->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$revocationBarrier]);
                file_put_contents($revokerPath, json_encode([...$fixture, 'operation' => 'revoke_manage', 'revocation_barrier' => $revocationBarrier], JSON_THROW_ON_ERROR));
                $revoker = new Process([PHP_BINARY, base_path('tests/CurrentIntentCommandWorker.php'), $revokerPath], base_path());
                $revoker->setTimeout(60);
                $revoker->start();
                $deadline = microtime(true) + 25;
                do {
                    $readyRevoker = $owner->select('SELECT pid,wait_event_type,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE application_name=?', [$run.'-revoker']);
                    if (count($readyRevoker) === 1 && str_contains($readyRevoker[0]->blockers, (string) $evidence['revoker_gate_pid'])) {
                        break;
                    }
                    usleep(20000);
                } while (microtime(true) < $deadline);
                $evidence['revoker_ready_before_writer'] = $readyRevoker;
                $this->assertCount(1, $readyRevoker);
                $this->assertStringContainsString((string) $evidence['revoker_gate_pid'], $readyRevoker[0]->blockers);
            }
            $writer = new Process([PHP_BINARY, base_path('tests/CurrentIntentCommandWorker.php'), $fixturePath], base_path());
            $writer->setTimeout(60);
            $writer->start();
            $workers[] = $writer;
            $deadline = microtime(true) + 25;
            do {
                $activity = DB::select('SELECT pid,backend_xid::text AS transaction_id,wait_event_type,wait_event,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE application_name=?', [$run]);
                if (count($activity) === 1 && $activity[0]->wait_event_type === 'Lock' && str_contains($activity[0]->blockers, (string) $evidence['blocker_pid'])) {
                    break;
                }
                if (! $writer->isRunning()) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $evidence['writer_output_at_wait'] = $writer->getOutput();
            $evidence['activity_at_effect_wait'] = $activity;
            $this->assertCount(1, $activity, json_encode($evidence, JSON_THROW_ON_ERROR));
            $this->assertStringContainsString((string) $evidence['blocker_pid'], $activity[0]->blockers);
            $this->assertSame('Lock', $activity[0]->wait_event_type);
            $this->assertStringContainsString('SELECTED_XACT=', $writer->getOutput());
            $evidence['writer_pid'] = $activity[0]->pid;
            $this->assertEquals($workspace, $owner->table('workspaces')->where('id', $workspace->id)->first());
            $this->assertSame(0, $owner->table($eventTable)->where('workspace_id', $workspace->id)->count());
            if ($authorityChange === 'revocation') {
                $revokerHolder->commit();
                $workers[] = $revoker;
                $deadline = microtime(true) + 25;
                do {
                    // Observe the owner-role revoker as that same role; no global stats privilege.
                    $revocationActivity = $owner->select('SELECT pid,wait_event_type,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE application_name=?', [$run.'-revoker']);
                    if (count($revocationActivity) === 1 && $revocationActivity[0]->wait_event_type === 'Lock' && str_contains($revocationActivity[0]->blockers, (string) $evidence['writer_pid'])) {
                        break;
                    }
                    usleep(20000);
                } while (microtime(true) < $deadline);
                $evidence['revocation_waiting_on_writer'] = $revocationActivity;
                $this->assertCount(1, $revocationActivity);
                $this->assertStringContainsString((string) $evidence['writer_pid'], $revocationActivity[0]->blockers);
            } else {
                $sql = $authorityChange === 'grant'
                    ? "SELECT bool_and(expires_at<=clock_timestamp()) FROM security_action_grants WHERE workspace_id=? AND action='business.context.manage'"
                    : 'SELECT idle_expires_at<=clock_timestamp() FROM security_sessions WHERE id=?';
                $this->waitForClock($owner, $sql, $authorityChange === 'grant' ? $workspace->id : $session->id, 20);
            }
            $evidence['locks_before_release'] = DB::select('SELECT pid,locktype,mode,granted,relation::regclass::text AS relation,transactionid::text AS transaction_id FROM pg_locks WHERE pid IN (?,?) ORDER BY pid,locktype,mode', [$evidence['blocker_pid'], $evidence['writer_pid']]);
            $holder->commit();
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            }
            $evidence['outputs'] = array_map(fn ($worker) => $worker->getOutput(), $workers);
            $evidence['after'] = $owner->table('workspaces')->where('id', $workspace->id)->first();
            $evidence['codes_after'] = $owner->table('business_target_markets')->where('workspace_id', $workspace->id)->orderBy('country_code')->pluck('country_code')->all();
            $evidence['events_after'] = $owner->table($eventTable)->where('workspace_id', $workspace->id)->get()->all();
            $this->assertStringNotContainsString('DENIED=40P01', implode('', $evidence['outputs']));
            if ($authorityChange === 'revocation') {
                $this->assertStringContainsString('RESULT=1', $writer->getOutput());
                $this->assertStringContainsString('REVOCATION_COMMITTED', $workers[1]->getOutput());
                $this->assertSame(1, (int) $evidence['after']->business_context_version);
                $this->assertSame([], $evidence['codes_after']);
                $this->assertEquals($this->value(), json_decode($evidence['after']->current_intent, true));
                $this->assertEquals($this->value(), json_decode($evidence['events_after'][0]->after_value, true));
                $this->assertCount(1, $evidence['events_after']);
                $this->assertSame('revoked', $owner->table('security_action_grants')->where('workspace_id', $workspace->id)->where('action', 'business.context.manage')->value('status'));
            } else {
                $this->assertStringContainsString('DENIED=42501', $writer->getOutput());
                $this->assertEquals($workspace, $evidence['after']);
                $this->assertSame([], $evidence['codes_after']);
                $this->assertSame([], $evidence['events_after']);
            }
        } finally {
            if ($revokerHolder->transactionLevel() > 0) {
                $revokerHolder->rollBack();
            }
            if (isset($revoker) && $revoker->isRunning()) {
                $revoker->stop();
            }
            $revokerHolder->disconnect();
            DB::purge($run.'-revoker-holder');
            if ($holder->transactionLevel() > 0) {
                $holder->rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            $holder->disconnect();
            DB::purge($run.'-holder');
            $owner->statement('DROP TRIGGER IF EXISTS test_effect_wait ON '.$eventTable);
            $owner->statement('DROP FUNCTION IF EXISTS builderad_test_effect_wait()');
            file_put_contents($evidencePath, json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            foreach ([$fixturePath, $revokerPath] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            fwrite(STDOUT, 'EFFECT_PROOF_EVIDENCE='.$evidencePath.PHP_EOL);
            $owner->statement('TRUNCATE workspaces,users RESTART IDENTITY CASCADE');
            auth()->forgetUser();
        }
    }

    public function test_equal_noop_rechecks_grant_after_normalization_wait(): void
    {
        $this->noopNormalizationWait('grant');
    }

    public function test_equal_noop_rechecks_session_after_normalization_wait(): void
    {
        $this->noopNormalizationWait('session');
    }

    private function noopNormalizationWait(string $authorityChange): void
    {
        [$workspace,$session,$fixture] = $this->fixture();
        $owner = DB::connection('pgsql_migrations');
        $request = Request::create('/', 'POST');
        $request->setLaravelSession($this->app['session']->driver());
        $request->setUserResolver(fn () => auth()->user());
        app(WorkspaceContext::class)->runBusinessMutation(auth()->user(), $workspace->public_id,
            fn ($active) => app(BusinessContext::class)->replaceCurrentIntent($request, $active, $fixture['value'], $fixture['confirmations'], 0), $request);
        $facts = fn () => [(array) $owner->table('workspaces')->find($workspace->id), $owner->table('business_current_intent_events')->where('workspace_id', $workspace->id)->get()->toArray()];
        $before = $facts();
        $run = 'intent-noop-normalization-'.Str::uuid();
        $barrier = $run.'-barrier';
        $fixture = [...$fixture, 'run' => $run, 'expiry_after_ready' => $authorityChange];
        $definition = $owner->selectOne("SELECT pg_get_functiondef('builderad_current_intent_normalize(jsonb)'::regprocedure) AS body")->body;
        $literal = $owner->getPdo()->quote($barrier);
        $instrumented = preg_replace('/\bBEGIN\b/', 'BEGIN PERFORM pg_advisory_xact_lock_shared(hashtextextended('.$literal.',0));', $definition, 1, $replacements);
        $this->assertSame(1, $replacements);
        $holder = DB::build([...config('database.connections.pgsql_migrations'), 'name' => $run.'-holder']);
        $workers = $paths = [];
        $evidence = ['authority_change' => $authorityChange, 'before' => $before, 'pause' => 'normalization after initial authority checks'];
        try {
            // Test-only barrier, restored exactly in finally; never durable production policy.
            $owner->unprepared($instrumented);
            $holder->beginTransaction();
            $pid = (int) $holder->scalar('SELECT pg_backend_pid()');
            $holder->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$barrier]);
            $writer = $this->start($fixture, $workers, $paths);
            $evidence['activity_at_normalization'] = $this->waitUntil(fn () => $this->waiting($run, $pid));
            $this->assertStringContainsString('SELECTED_XACT=', $writer->getOutput());
            $sql = $authorityChange === 'grant'
                ? "SELECT bool_and(expires_at<=clock_timestamp()) FROM security_action_grants WHERE workspace_id=? AND action='business.context.manage'"
                : 'SELECT idle_expires_at<=clock_timestamp() FROM security_sessions WHERE id=?';
            $this->waitForClock($owner, $sql, $authorityChange === 'grant' ? $workspace->id : $session->id, 20);
            $holder->commit();
            $writer->wait();
            $this->assertTrue($writer->isSuccessful(), $writer->getErrorOutput());
            $evidence['output'] = $writer->getOutput();
            $this->assertStringNotContainsString('DENIED=40P01', $evidence['output']);
            $this->assertStringContainsString('DENIED=42501', $evidence['output']);
            $this->assertEquals($before, $facts());
            $evidence['after'] = $facts();
        } finally {
            if ($holder->transactionLevel() > 0) {
                $holder->rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            $holder->disconnect();
            DB::purge($run.'-holder');
            $owner->unprepared($definition);
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $proof = storage_path('framework/testing/'.$run.'.json');
            file_put_contents($proof, json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            fwrite(STDOUT, 'NOOP_NORMALIZATION_EVIDENCE='.$proof.PHP_EOL);
        }
    }

    private function waitForClock($connection, string $sql, int $id, int $timeout = 5): void
    {
        $deadline = microtime(true) + $timeout;
        do {
            if ($connection->scalar($sql, [$id])) {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Database authority clock did not reach the recorded expiry.');
    }
}
