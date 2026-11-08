<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\BusinessAuthorityFixture as Fixture;
use Tests\Support\PurposeDeclarationFixture;
use Tests\TestCase;

final class BusinessFactFinalAuthorityTest extends TestCase
{
    public function test_context_final_wait_preserves_original_facts_after_expiry(): void
    {
        $this->race('context');
    }

    public function test_markets_final_wait_preserves_original_facts_after_expiry(): void
    {
        $this->race('markets');
    }

    public function test_intent_final_wait_preserves_original_facts_after_expiry(): void
    {
        $this->race('intent');
    }

    public function test_preferences_final_wait_preserves_original_facts_after_expiry(): void
    {
        $this->race('preferences');
    }

    public function test_purpose_final_wait_rolls_back_native_revision_context_reference_and_receipts_after_expiry(): void
    {
        $this->race('purpose');
    }

    public function test_grant_issue_final_wait_leaves_no_authority_after_qualification_expiry(): void
    {
        $this->race('grant_issue');
    }

    public function test_grant_revoke_final_wait_cannot_commit_after_qualification_expiry(): void
    {
        $this->race('grant_revoke');
    }

    private function race(string $kind): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $a = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Temporal fact client'])->assertCreated()->json('data');
        $db = DB::connection('pgsql_migrations');
        $action = match ($kind) {
            'context' => 'business.context.manage', 'markets' => 'business.markets.declare', 'intent' => 'business.intent.declare', 'preferences' => 'business.preferences.manage',
            'grant_issue', 'grant_revoke' => 'business.context.read', 'purpose' => 'business.purpose.declare'
        };
        $grant = $a['grant_refs'][$action] ?? null;
        if ($kind === 'purpose') {
            $basisSelection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
                'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $a['grant_refs']['business.context.read']]]];
            $enrollment = $this->postJson('/api/businesses/'.$a['business_public_id'].'/purpose-enrollment', ['command_id' => (string) Str::uuid(),
                'confirmed' => true, 'previous_grant' => '0', 'authority_selection' => $basisSelection])->assertOk()->json('data');
            $grant = (int) $enrollment['grant_refs']['business.purpose.declare'];
        }
        $recipient = null;
        $issued = null;
        if (str_starts_with($kind, 'grant_')) {
            $recipient = User::factory()->create();
            $grant = Fixture::root($db->table('businesses')->where('public_id', $a['business_public_id'])->value('id'), $user->id, $action)->id;
        }
        $qualification = $db->table('security_action_grants')->where('id', $grant)->value('qualification_id');
        if ($kind === 'purpose') {
            // The operating policy also depends on the original creator basis;
            // its expiry during the actual final receipt wait must roll back all
            // Purpose rows even while the new declaration grant itself is live.
            $qualification = $db->table('security_action_grants')->where('id', $a['grant_refs']['business.context.read'])->value('qualification_id');
        }
        $selection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $grant]]];
        if ($kind === 'grant_revoke') {
            $issued = $this->postJson('/api/businesses/'.$a['business_public_id'].'/grants', ['command_id' => (string) Str::uuid(),
                'effect' => 'issue', 'grantee_public_id' => $recipient->public_id, 'action' => $action, 'parent_grant_id' => $grant,
                'grant_id' => null, 'expected_grant' => 0, 'expires_at' => null, 'delegable' => false, 'realm_workspace_public_id' => null,
                'authority_selection' => $selection])->assertOk()->json('data.grant_id');
        }
        $request = Request::create('/api/businesses');
        $request->setLaravelSession(app('session')->driver());
        $run = 'fact-final-'.$kind.'-'.Str::uuid();
        $path = storage_path('framework/testing/'.$run.'.json');
        $release = $path.'.release';
        $ready = $path.'.ready';
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        $input = ['run' => $run, 'ready' => $ready, 'actor' => $user->id, 'business' => $a['business_public_id'], 'session' => SessionAuthority::sessionKey($request), 'command' => (string) Str::uuid(),
            'operation' => $kind, 'name' => 'Changed temporal client', 'expected' => 0, 'selection' => $selection, 'release' => $release, 'require_release_content' => true, 'codes' => ['SA'],
            'value' => $kind === 'preferences' ? ['goals' => ['sales'], 'priority' => 'sales', 'results' => ['sales' => ['actual_sales']]]
                : ['description' => null, 'direction' => 'increase_value', 'scope' => 'business_wide', 'outcome' => ['meaning' => 'Qualified purchases', 'clarification' => null, 'count_unit' => null], 'quantity' => null, 'horizon' => null],
            'confirmations' => ['declaration' => true, 'business_wide' => true, 'outcome' => true, 'acceptance_clear' => true]];
        if ($recipient) {
            $input += ['grant_effect' => $kind === 'grant_issue' ? 'issue' : 'revoke', 'recipient' => $recipient->public_id, 'issuer' => $grant, 'grant_id' => $issued];
            $input['operation'] = 'grant';
        }
        if ($kind === 'purpose') {
            $input['purpose_command'] = PurposeDeclarationFixture::command();
            $input['purpose_command']['command_id'] = $input['command'];
        }
        file_put_contents($path, json_encode($input, JSON_THROW_ON_ERROR));
        // Creating/opening a new signal file consumed 1.27 seconds on this host. Prepare
        // that I/O before qualification expiry; an empty signal must not release the worker.
        $releaseHandle = fopen($release, 'x+b');
        $tables = ['business_context_heads', 'business_current_intents', 'business_goal_preferences', 'business_declared_markets',
            'business_context_transitions', 'business_intent_transitions', 'business_preference_transitions', 'business_market_transitions'];
        if ($recipient) {
            $tables = [...$tables, 'security_action_grants', 'business_authority_fences'];
        }
        if ($kind === 'purpose') {
            $tables = [...$tables, 'object_purposes', 'object_purpose_revisions', 'object_purpose_command_receipts', 'object_purpose_business_context_refs'];
        }
        $qualificationCount = $db->table('authority_qualification_receipts')->count();
        $snapshot = fn () => array_map(fn ($table) => json_encode($db->table($table)->get()->all(), JSON_THROW_ON_ERROR), array_combine($tables, $tables));
        $before = $snapshot();
        $evidence = ['run' => $run, 'kind' => $kind];
        // Prepare the independent marked-disposable receipt blocker before starting the
        // existing two-second qualification window; do not spend that lease on setup.
        config(['database.connections.business_final_barrier' => config('database.connections.pgsql_migrations')]);
        $barrier = DB::connection('business_final_barrier');
        $barrier->getPdo();
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $path], base_path());
        $worker->setTimeout(45);
        $wait = function (callable $condition, string $phase = 'final_receipt_contention') use ($worker) {
            $deadline = microtime(true) + 15;
            do {
                if ($value = $condition()) {
                    return $value;
                } if (! $worker->isRunning()) {
                    throw new \RuntimeException($worker->getOutput().$worker->getErrorOutput());
                } usleep(10000);
            } while (microtime(true) < $deadline);
            throw new \RuntimeException('Required final fact wait missing ['.$phase.']: '.$worker->getOutput().$worker->getErrorOutput());
        };
        try {
            $evidence['worker_started_at'] = microtime(true);
            $worker->start();
            // Use the existing worker's durable post-bootstrap/guard handshake.
            // Windows pipe delivery is not participant readiness. The bootstrap
            // deadline, expiry window and required real receipt wait stay intact.
            $participant = $wait(function () use ($ready, $run) {
                clearstatcache(true, $ready);
                if (! is_file($ready)) {
                    return false;
                }
                $value = json_decode(file_get_contents($ready), true);

                return is_array($value) && ($value['run'] ?? null) === $run && ($value['pid'] ?? 0) > 0 ? $value : false;
            }, 'worker_bootstrap');
            $evidence['ready_at'] = microtime(true);
            $evidence['participant'] = $participant;
            $evidence['stdout_ready_at_handshake'] = str_contains($worker->getOutput(), 'READY_PID=');
            $barrier->beginTransaction();
            $blocker = (int) $barrier->scalar('SELECT pg_backend_pid()');
            $barrier->statement('LOCK TABLE business_command_receipts IN SHARE MODE');
            $evidence['barrier_locked_at'] = microtime(true);
            $expires = Fixture::expireQualificationSoon($qualification, $user->id);
            $evidence['expires'] = $expires;
            $evidence['expiry_issued_at'] = microtime(true);
            fwrite($releaseHandle, $kind === 'grant_issue' ? $expires : 'ready');
            fflush($releaseHandle);
            $evidence['released_at'] = microtime(true);
            $evidence['wait'] = $wait(function () use ($run, $blocker) {
                $rows = DB::select("SELECT a.pid,a.backend_xid::text AS xid,a.wait_event_type,a.wait_event,pg_blocking_pids(a.pid)::text blockers FROM pg_stat_activity a WHERE application_name=? AND ?=ANY(pg_blocking_pids(a.pid)) AND EXISTS(SELECT 1 FROM pg_locks WHERE pid=a.pid AND relation='business_command_receipts'::regclass AND mode='RowExclusiveLock' AND NOT granted)", [$run, $blocker]);

                return $rows ? (array) $rows[0] : false;
            });
            $this->assertSame((int) $participant['pid'], (int) $evidence['wait']['pid']);
            $this->assertNotSame($blocker, (int) $evidence['wait']['pid']);
            $wait(fn () => (bool) $db->scalar('SELECT clock_timestamp()>=?::timestamptz', [$expires]));
            $barrier->commit();
            $worker->wait();
            $this->assertSame(2, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
            $this->assertStringContainsString('Selected authority expired after final receipt wait', $worker->getOutput());
            $this->assertStringNotContainsString('COMMITTED=', $worker->getOutput());
            $this->assertSame($before, $snapshot(), 'No fact, version or immutable event can survive the failed final authority check.');
            $this->assertSame(0, $db->table('business_command_receipts')->where('command_id', $input['command'])->count());
            $this->assertSame($qualificationCount, $db->table('authority_qualification_receipts')->count());
        } finally {
            if ($barrier->transactionLevel() > 0) {
                $barrier->rollBack();
            }
            DB::disconnect('business_final_barrier');
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            } if ($worker->isRunning()) {
                $worker->stop();
            }
            $evidence['worker'] = ['stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput(), 'exit' => $worker->getExitCode()];
            fclose($releaseHandle);
            file_put_contents(storage_path('framework/testing/'.$run.'-evidence.json'), json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            foreach ([$path, $release, $ready] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
