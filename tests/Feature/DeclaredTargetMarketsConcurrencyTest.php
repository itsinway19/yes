<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeclaredTargetMarketsConcurrencyTest extends TestCase
{
    public function test_simultaneous_canonical_context_writes_do_not_deadlock(): void
    {
        $this->race();
    }

    public function test_simultaneous_different_name_writes_conflict_without_lost_update(): void
    {
        $this->race(true);
    }

    public function test_grant_revocation_committed_before_authority_acquisition_denies_effect(): void
    {
        $this->race(authorityChange: 'grant_revocation');
    }

    public function test_grant_expiry_during_real_wait_denies_effect(): void
    {
        $this->race(authorityChange: 'grant_expiry');
    }

    public function test_session_expiry_during_real_wait_denies_effect(): void
    {
        $this->race(authorityChange: 'session_expiry');
    }

    public function test_lifecycle_generation_changed_before_selection_denies_effect(): void
    {
        $this->race(authorityChange: 'generation');
    }

    public function test_membership_deactivated_during_serialization_wait_denies_effect(): void
    {
        $this->race(authorityChange: 'membership');
    }

    public function test_responsible_owner_invalidated_during_serialization_wait_denies_effect(): void
    {
        $this->race(authorityChange: 'owner');
    }

    public function test_session_revoked_before_acquisition_denies_effect(): void
    {
        $this->race(authorityChange: 'session_revocation');
    }

    public function test_concurrent_equal_replacements(): void
    {
        $this->race(operation: 'dtm');
    }

    public function test_concurrent_different_replacements(): void
    {
        $this->race(true, operation: 'dtm');
    }

    public function test_concurrent_name_type_and_dtm_synchronized_entry(): void
    {
        $this->race(true, operation: 'mixed');
    }

    public function test_concurrent_name_type_and_dtm_name_winning_schedule(): void
    {
        $this->race(true, operation: 'mixed', first: 0);
    }

    public function test_concurrent_name_type_and_dtm_dtm_winning_schedule(): void
    {
        $this->race(true, operation: 'mixed', first: 1);
    }

    public function test_authority_expiry_after_wait_rolls_back(): void
    {
        $this->race(authorityChange: 'grant_expiry', operation: 'dtm');
    }

    public function test_dtm_session_expiry_after_wait_rolls_back(): void
    {
        $this->race(authorityChange: 'session_expiry', operation: 'dtm');
    }

    public function test_dtm_revocation_committed_before_acquisition_rolls_back(): void
    {
        $this->race(authorityChange: 'grant_revocation', operation: 'dtm');
    }

    public function test_dtm_grant_expiry_after_audit_wait_rolls_back_every_effect(): void
    {
        $this->effectWait('dtm', 'grant');
    }

    public function test_name_type_grant_expiry_after_audit_wait_preserves_original_semantics(): void
    {
        $this->effectWait('name', 'grant');
    }

    public function test_dtm_session_expiry_after_audit_wait_rolls_back_every_effect(): void
    {
        $this->effectWait('dtm', 'session');
    }

    public function test_revocation_waits_for_already_authorized_writer_and_linearizes_after_commit(): void
    {
        $this->effectWait('dtm', 'revocation');
    }

    private function effectWait(string $operation, string $authorityChange): void
    {
        $actor = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $this->actingAs($actor)->post('/workspaces', ['name' => 'Effect clock business', 'slug' => 'effect-clock'])->assertRedirect();
        $owner = DB::connection('pgsql_migrations');
        $workspace = $owner->table('workspaces')->where('slug', 'effect-clock')->first();
        $session = $owner->table('security_sessions')->where('user_id', $actor->id)->first();
        $run = 'dtm-effect-'.Str::uuid();
        $barrier = 'builderad.test.effect.'.$run;
        $eventTable = $operation === 'dtm' ? 'business_target_market_events' : 'business_context_events';
        $fixture = ['run' => $run, 'actor' => $actor->id, 'workspace' => $workspace->id,
            'session' => $session->session_key_hash, 'type' => $owner->table('business_types')->where('key', 'real_estate')->value('id'),
            'name' => $workspace->name, 'version' => 0, 'operation' => $operation, 'codes' => ['KW', 'SA'],
            ...($authorityChange === 'revocation' ? [] : ['expiry_after_ready' => $authorityChange])];
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
                $revoker = new Process([PHP_BINARY, base_path('tests/TargetMarketsCommandWorker.php'), $revokerPath], base_path());
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
            $writer = new Process([PHP_BINARY, base_path('tests/TargetMarketsCommandWorker.php'), $fixturePath], base_path());
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
                $this->assertSame(['KW', 'SA'], $evidence['codes_after']);
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
        }
    }

    private function race(bool $different = false, ?string $authorityChange = null, string $operation = 'name', ?int $first = null): void
    {
        $actor = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $this->actingAs($actor)->post('/workspaces', ['name' => 'Lock proof business', 'slug' => 'lock-proof'])->assertRedirect();
        $owner = DB::connection('pgsql_migrations');
        $workspace = $owner->table('workspaces')->where('slug', 'lock-proof')->first();
        $type = $owner->table('business_types')->where('key', 'real_estate')->value('id');
        $session = $owner->table('security_sessions')->where('user_id', $actor->id)->first();
        $run = 'dtm-lock-'.Str::uuid();
        $evidencePath = storage_path('framework/testing/'.$run.'.json');
        $fixturePath = storage_path('framework/testing/'.$run.'-fixture.json');
        $secondFixturePath = storage_path('framework/testing/'.$run.'-second-fixture.json');
        if (! is_dir(dirname($fixturePath))) {
            mkdir(dirname($fixturePath), 0755, true);
        }
        $entryBarrier = $first === null ? 'builderad.test.entry.'.$run : null;
        file_put_contents($fixturePath, json_encode([
            'run' => $run, 'actor' => $actor->id, 'workspace' => $workspace->id,
            'session' => $session->session_key_hash, 'type' => $type,
            'name' => $workspace->name, 'version' => 0,
            'operation' => $operation === 'dtm' ? 'dtm' : 'name', 'codes' => ['SA', 'KW', 'SA'],
            ...($entryBarrier === null ? [] : ['entry_barrier' => $entryBarrier]),
        ], JSON_THROW_ON_ERROR));
        $secondFixture = json_decode(file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
        if ($different) {
            $secondFixture['name'] = 'Other concurrent name';
        }
        if ($operation === 'dtm') {
            $secondFixture['codes'] = $different ? ['AE', 'JO'] : ['KW', 'SA'];
        } elseif ($operation === 'mixed') {
            $secondFixture['operation'] = 'dtm';
            $secondFixture['codes'] = ['SA', 'KW'];
        }
        file_put_contents($secondFixturePath, json_encode($secondFixture, JSON_THROW_ON_ERROR));
        $startupGates = [];
        foreach ([0 => $fixturePath, 1 => $secondFixturePath] as $index => $path) {
            $startupGates[$index] = ['ready' => storage_path('framework/testing/'.$run.'-entry-'.$index.'-ready.json'),
                'release' => storage_path('framework/testing/'.$run.'-entry-'.$index.'-release')];
            $participant = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $participant['entry_external_gate'] = $startupGates[$index];
            file_put_contents($path, json_encode($participant, JSON_THROW_ON_ERROR));
        }
        $holder = DB::build([...config('database.connections.pgsql_migrations'), 'name' => $run.'-holder']);
        $entryHolder = DB::build([...config('database.connections.pgsql_migrations'), 'name' => $run.'-entry-holder']);
        $workers = [];
        $evidence = ['baseline' => '8ebd9a1f1ffd7b23307d277304203c706e67d95d', 'run' => $run];
        try {
            // Boot both independent participants before holding the finite
            // product lock. The prior fixture spent the first waiter's 3s SQL
            // budget on the second participant's PHP/DB bootstrap.
            foreach ([0 => $fixturePath, 1 => $secondFixturePath] as $index => $path) {
                $worker = new Process([PHP_BINARY, base_path('tests/TargetMarketsCommandWorker.php'), $path], base_path());
                $worker->setTimeout(45);
                $worker->start();
                $workers[$index] = $worker;
            }
            foreach ($workers as $index => $worker) {
                $deadline = microtime(true) + 25;
                do {
                    clearstatcache(true, $startupGates[$index]['ready']);
                    if (is_file($startupGates[$index]['ready']) && str_contains($worker->getOutput(), 'ENTRY_READY')) {
                        break;
                    }
                    if (! $worker->isRunning()) {
                        $this->fail('DTM worker exited before prepared entry: '.$worker->getOutput().$worker->getErrorOutput());
                    }
                    $this->assertLessThan($deadline, microtime(true), 'Prepared DTM entry was not observed.');
                    usleep(10000);
                } while (true);
                $evidence['pre_ready'][$index] = json_decode(file_get_contents($startupGates[$index]['ready']), true, flags: JSON_THROW_ON_ERROR);
                $this->assertNull($evidence['pre_ready'][$index]['xid']);
            }
            if ($entryBarrier !== null) {
                $entryHolder->beginTransaction();
                $evidence['entry_blocker_pid'] = (int) $entryHolder->scalar('SELECT pg_backend_pid()');
                $entryHolder->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$entryBarrier]);
            }
            $holder->beginTransaction();
            $evidence['blocker_pid'] = (int) $holder->scalar('SELECT pg_backend_pid()');
            if (in_array($authorityChange, ['generation', 'membership', 'owner'], true)) {
                $holder->selectOne("SELECT pg_advisory_xact_lock(hashtextextended('builderad.business.context.'||?::text,0))", [$workspace->id]);
            } else {
                $holder->table('security_sessions')->where('id', $session->id)->lockForUpdate()->first();
            }
            if ($entryBarrier !== null) {
                $this->assertNotSame($evidence['entry_blocker_pid'], $evidence['blocker_pid']);
                $this->assertGreaterThan(0, (int) DB::scalar("SELECT count(*) FROM pg_locks WHERE pid=? AND locktype='advisory' AND granted", [$evidence['entry_blocker_pid']]));
            }
            $startOrder = $first === null ? [0, 1] : [$first, 1 - $first];
            foreach ($startOrder as $position => $i) {
                file_put_contents($startupGates[$i]['release'], 'RELEASE');
                if ($first !== null && $position === 0) {
                    $deadline = microtime(true) + 30;
                    do {
                        $firstWait = DB::select('SELECT pid,wait_event_type,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE application_name=? AND pid=?', [$run, $evidence['pre_ready'][$i]['pid']]);
                        if (count($firstWait) === 1 && $firstWait[0]->wait_event_type === 'Lock') {
                            break;
                        }
                        usleep(20000);
                    } while (microtime(true) < $deadline);
                    $evidence['preferred_first'] = $first;
                    $evidence['first_wait_before_second_entry'] = $firstWait;
                    $this->assertCount(1, $firstWait);
                    $this->assertSame('Lock', $firstWait[0]->wait_event_type);
                }
            }
            if ($entryBarrier !== null) {
                $deadline = microtime(true) + 30;
                do {
                    $entryActivity = DB::select('SELECT pid,backend_xid::text AS transaction_id,wait_event_type,wait_event,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE application_name=? ORDER BY pid', [$run]);
                    $ready = count(array_filter($workers, fn ($worker) => str_contains($worker->getOutput(), 'READY_XACT=')));
                    $entryWaiting = count(array_filter($entryActivity, fn ($row) => $row->wait_event_type === 'Lock' && str_contains($row->blockers, (string) $evidence['entry_blocker_pid'])));
                    if ($ready === 2 && $entryWaiting === 2) {
                        break;
                    }
                    if (count(array_filter($workers, fn ($worker) => $worker->isRunning())) === 0) {
                        break;
                    }
                    usleep(20000);
                } while (microtime(true) < $deadline);
                $evidence['activity_before_synchronized_entry'] = $entryActivity;
                $evidence['outputs_before_synchronized_entry'] = array_map(fn ($worker) => $worker->getOutput(), $workers);
                $evidence['errors_before_synchronized_entry'] = array_map(fn ($worker) => $worker->getErrorOutput(), $workers);
                $this->assertSame(2, $ready);
                $this->assertSame(2, $entryWaiting);
                $entryHolder->commit();
            }
            $deadline = microtime(true) + 30;
            do {
                // Capture real XIDs, waits and locks together. Extra Windows
                // stdout/file/catalog probes must not consume the finite SQL
                // wait after both actual database participants are observed.
                $sample = DB::selectOne(<<<'SQL'
                    WITH participants AS MATERIALIZED (
                      SELECT pid,backend_xid::text AS transaction_id,wait_event_type,wait_event,pg_blocking_pids(pid)::text AS blockers
                      FROM pg_stat_activity WHERE application_name=?
                    ), observed_locks AS MATERIALIZED (
                      SELECT pid,locktype,mode,granted,relation::regclass::text AS relation,transactionid::text AS transaction_id,page,tuple
                      FROM pg_locks WHERE pid IN(SELECT pid FROM participants) OR pid=?
                    ) SELECT clock_timestamp()::text captured_at,
                        (SELECT json_agg(p ORDER BY pid) FROM participants p) AS participants,
                        (SELECT json_agg(l ORDER BY pid,locktype,mode) FROM observed_locks l) AS locks
                    SQL, [$run, $evidence['blocker_pid']]);
                $activity = json_decode($sample->participants ?? '[]', flags: JSON_THROW_ON_ERROR);
                $selected = count(array_filter($activity, fn ($row) => $row->transaction_id !== null));
                $waiting = count(array_filter($activity, fn ($row) => $row->wait_event_type === 'Lock'));
                if ($selected === 2 && $waiting === 2) {
                    break;
                }
                if (count(array_filter($workers, fn ($worker) => $worker->isRunning())) === 0) {
                    $evidence['worker_outputs'] = array_map(fn ($worker) => $worker->getOutput(), $workers);
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $evidence['both_entered'] = $selected;
            $evidence['both_lock_waiting'] = $waiting;
            $evidence['activity_before_release'] = $activity;
            $evidence['locks_before_release'] = json_decode($sample->locks ?? '[]', flags: JSON_THROW_ON_ERROR);
            $evidence['captured_before_release_at'] = $sample->captured_at;
            $this->assertSame(2, $selected, json_encode($evidence, JSON_THROW_ON_ERROR));
            $this->assertSame(2, $waiting, json_encode($evidence, JSON_THROW_ON_ERROR));
            if ($authorityChange === 'generation') {
                $owner->table('principal_lifecycles')->where('user_id', $actor->id)->increment('generation');
            } elseif ($authorityChange === 'membership') {
                $owner->table('workspace_memberships')->where('workspace_id', $workspace->id)->where('user_id', $actor->id)->update(['status' => 'revoked', 'revoked_at' => DB::raw('clock_timestamp()')]);
            } elseif ($authorityChange === 'owner') {
                $owner->table('users')->where('id', $actor->id)->update(['email_verified_at' => null]);
            } elseif ($authorityChange === 'session_revocation') {
                $holder->table('security_sessions')->where('id', $session->id)->update(['revoked_at' => DB::raw('clock_timestamp()')]);
            } elseif ($authorityChange === 'grant_revocation') {
                $owner->table('security_action_grants')->where('subject_user_id', $actor->id)
                    ->where('workspace_id', $workspace->id)->where('action', 'business.context.manage')
                    ->update(['status' => 'revoked', 'revoked_at' => DB::raw('clock_timestamp()')]);
            } elseif ($authorityChange === 'grant_expiry') {
                $owner->table('security_action_grants')->where('subject_user_id', $actor->id)
                    ->where('workspace_id', $workspace->id)->where('action', 'business.context.manage')
                    ->update(['expires_at' => DB::raw("clock_timestamp() + interval '250 milliseconds'")]);
                $this->waitForClock($owner, 'SELECT bool_and(expires_at <= clock_timestamp()) FROM security_action_grants WHERE workspace_id=? AND action=\'business.context.manage\'', $workspace->id);
            } elseif ($authorityChange === 'session_expiry') {
                $holder->table('security_sessions')->where('id', $session->id)
                    ->update(['idle_expires_at' => DB::raw("clock_timestamp() + interval '250 milliseconds'")]);
                $this->waitForClock($holder, 'SELECT idle_expires_at <= clock_timestamp() FROM security_sessions WHERE id=?', $session->id);
            }
            $evidence['authority_change'] = $authorityChange;
            $holder->commit();
            $outputs = [];
            foreach ($workers as $index => $worker) {
                $worker->wait();
                $outputs[$index] = $worker->getOutput();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            }
            ksort($outputs);
            $evidence['worker_outputs'] = $outputs;
            $evidence['workspace_after'] = $owner->table('workspaces')->where('id', $workspace->id)->first();
            $evidence['context_events_after'] = $owner->table('business_context_events')->where('workspace_id', $workspace->id)->get()->all();
            $evidence['dtm_events_after'] = $owner->table('business_target_market_events')->where('workspace_id', $workspace->id)->get()->all();
            $evidence['codes_after'] = $owner->table('business_target_markets')->where('workspace_id', $workspace->id)->orderBy('country_code')->pluck('country_code')->all();
            $evidence['operation'] = $operation;
            fwrite(STDOUT, '\nLOCK_PROOF_EVIDENCE='.$evidencePath.PHP_EOL);
            $this->assertSame(0, count(array_filter($outputs, fn ($output) => str_contains($output, 'DENIED=40P01'))), json_encode($evidence, JSON_THROW_ON_ERROR));
            if ($authorityChange !== null) {
                $this->assertSame(2, count(array_filter($outputs, fn ($output) => str_contains($output, 'DENIED=42501'))), json_encode($evidence, JSON_THROW_ON_ERROR));
                $this->assertSame(0, (int) $evidence['workspace_after']->business_context_version);
                $this->assertSame($workspace->name, $evidence['workspace_after']->name);
                $this->assertEquals($workspace, $evidence['workspace_after']);
                $this->assertSame([], $evidence['context_events_after']);
                $this->assertSame([], $evidence['dtm_events_after']);
                $this->assertSame([], $evidence['codes_after']);
            } else {
                $this->assertSame($different ? 1 : 2, count(array_filter($outputs, fn ($output) => str_contains($output, 'RESULT=1'))), json_encode($evidence, JSON_THROW_ON_ERROR));
                $this->assertSame($different ? 1 : 0, count(array_filter($outputs, fn ($output) => str_contains($output, 'DENIED=40001'))), json_encode($evidence, JSON_THROW_ON_ERROR));
                $this->assertSame(1, (int) $evidence['workspace_after']->business_context_version);
                $this->assertSame(1, count($evidence['context_events_after']) + count($evidence['dtm_events_after']));
                if ($first !== null) {
                    $this->assertStringContainsString('RESULT=1', $outputs[$first]);
                }
                if ($operation === 'name') {
                    $this->assertSame($evidence['workspace_after']->name, $evidence['context_events_after'][0]->new_name);
                    $this->assertSame([], $evidence['codes_after']);
                } elseif ($operation === 'dtm') {
                    $winningCodes = str_contains($outputs[0], 'RESULT=1') ? ['KW', 'SA'] : ($different ? ['AE', 'JO'] : ['KW', 'SA']);
                    $this->assertSame($winningCodes, $evidence['codes_after']);
                    $this->assertSame($workspace->name, $evidence['workspace_after']->name);
                    $this->assertNull($evidence['workspace_after']->business_type_id);
                    $this->assertCount(1, $evidence['dtm_events_after']);
                    $this->assertSame('{}', $evidence['dtm_events_after'][0]->before_codes);
                    $this->assertSame('{'.implode(',', $winningCodes).'}', $evidence['dtm_events_after'][0]->after_codes);
                } elseif (str_contains($outputs[0], 'RESULT=1')) {
                    $this->assertSame((int) $type, (int) $evidence['workspace_after']->business_type_id);
                    $this->assertSame([], $evidence['codes_after']);
                    $this->assertCount(1, $evidence['context_events_after']);
                } else {
                    $this->assertSame(['KW', 'SA'], $evidence['codes_after']);
                    $this->assertNull($evidence['workspace_after']->business_type_id);
                    $this->assertSame($workspace->name, $evidence['workspace_after']->name);
                    $this->assertCount(1, $evidence['dtm_events_after']);
                }
            }
        } finally {
            if ($entryHolder->transactionLevel() > 0) {
                $entryHolder->rollBack();
            }
            $entryHolder->disconnect();
            if ($holder->transactionLevel() > 0) {
                $holder->rollBack();
            }
            $holder->disconnect();
            DB::purge($run.'-holder');
            DB::purge($run.'-entry-holder');
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            file_put_contents($evidencePath, json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            foreach ($startupGates as $gate) {
                foreach ($gate as $path) {
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
            }
            if (is_file($fixturePath)) {
                unlink($fixturePath);
            }
            if (is_file($secondFixturePath)) {
                unlink($secondFixturePath);
            }
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
