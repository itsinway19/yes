<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\BusinessAuthorityFixture as Fixture;
use Tests\Support\MarketEvidenceFixture;
use Tests\TestCase;

final class MarketEvidenceConcurrencyTest extends TestCase
{
    use MarketEvidenceFixture;

    public function test_source_withdrawal_requalifies_a_writer_after_observed_contention(): void
    {
        $this->contendedWrite('source_withdrawal');
    }

    public function test_qualification_expiry_at_the_final_receipt_wait_rolls_back_every_effect(): void
    {
        $this->contendedWrite('receipt_expiry');
    }

    public function test_processing_rechecks_evidence_expiry_after_the_final_receipt_wait(): void
    {
        $this->contendedWrite('evidence_expiry');
    }

    public function test_licensed_retained_read_reports_release_time_validity_after_the_receipt_wait(): void
    {
        $this->contendedWrite('retained_expiry');
    }

    private function contendedWrite(string $mode): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $source = $this->marketSource();
        $evidence = (string) Str::uuid7();
        $right = $this->marketRight($user, $source, $evidence, 'ingest', array_keys($this->offer()['fields']));
        $run = 'market-write-'.Str::uuid();
        $release = storage_path('framework/testing/'.$run.'.release');
        $path = storage_path('framework/testing/'.$run.'.json');
        $request = Request::create('/api/market-evidence/'.$evidence);
        $request->setLaravelSession(app('session')->driver());
        $command = (string) Str::uuid();
        $input = ['run' => $run, 'actor' => $user->id, 'session' => SessionAuthority::sessionKey($request), 'command' => $command,
            'operation' => 'market-write', 'evidence' => $evidence, 'scope' => $right['scope_id'], 'expected' => 0,
            'payload' => $this->offer(), 'selection' => $this->marketSelection($right), 'release' => $release];
        $expiry = null;
        $evidenceExpires = in_array($mode, ['evidence_expiry', 'retained_expiry'], true);
        if ($evidenceExpires) {
            $expiry = now()->addSeconds(3)->toIso8601String();
            $payload = $this->offer();
            $payload['valid_until'] = $expiry;
            $ref = $this->writeEvidence($evidence, $right, $payload)->assertOk()->json('data.material_ref');
            $business = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Synthetic processing expiry'])->assertCreated()->json('data');
            $businessId = DB::connection('pgsql_migrations')->table('businesses')->where('public_id', $business['business_public_id'])->value('id');
            $businessRight = Fixture::root($businessId, $user->id, 'business.data.use');
            $link = Fixture::root($businessId, $user->id, 'business.link.manage');
            $process = $this->marketRight($user, $source, $evidence, $mode === 'retained_expiry' ? 'retained_read' : 'process', ['price']);
            $selection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => $link->id], ['requirement_kind' => 'data_use', 'leaf_grant_id' => $process['grant_id']],
            ]];
            $this->postJson('/api/businesses/'.$business['business_public_id'].'/data-use-bindings', ['command_id' => (string) Str::uuid(),
                'data_use_scope_id' => $process['scope_id'], 'effect' => 'add', 'expected_relation' => 0, 'authority_selection' => $selection])->assertOk();
            $selection['requirements'][0]['leaf_grant_id'] = $businessRight->id;
            $input = [...$input, 'operation' => 'material', 'business' => $business['business_public_id'], 'material_operation' => $process['operation'],
                'refs' => [$ref], 'purpose' => 'market_research', 'recipient' => $user->public_id, 'selection' => $selection];
        }
        file_put_contents($path, json_encode($input, JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $path], base_path());
        $worker->setTimeout(45);
        $db = DB::connection('pgsql_migrations');
        $proof = ['mode' => $mode, 'run' => $run, 'expiry' => $expiry];
        try {
            $worker->start();
            $this->waitFor(fn () => str_contains($worker->getOutput(), 'READY_PID='), $worker);
            if ($mode === 'receipt_expiry') {
                $proof['expiry'] = Fixture::expireQualificationSoon($right['qualification_id'], $user->id);
            }
            $db->beginTransaction();
            $blocker = (int) $db->scalar('SELECT pg_backend_pid()');
            if ($mode === 'receipt_expiry' || $evidenceExpires) {
                $db->statement('LOCK TABLE business_command_receipts IN SHARE MODE');
                $this->assertTrue($db->scalar('SELECT clock_timestamp()<?::timestamptz', [$proof['expiry']]));
            } else {
                $db->select("SELECT fence_id FROM resource_right_fences WHERE kind='source' AND source_id=?::uuid FOR UPDATE", [$source]);
            }
            file_put_contents($release, 'ready');
            $proof['wait'] = $this->waitFor(function () use ($run, $blocker) {
                foreach (DB::select('SELECT pid,backend_xid::text AS xid,wait_event_type,wait_event,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE application_name=?', [$run]) as $row) {
                    if ($row->wait_event_type === 'Lock' && in_array($blocker, json_decode(str_replace(['{', '}'], ['[', ']'], $row->blockers), true), true)) {
                        return (array) $row;
                    }
                }

                return false;
            }, $worker);
            $this->assertNotEmpty($proof['wait']['xid']);
            if ($mode === 'receipt_expiry' || $evidenceExpires) {
                $proof['receipt_wait'] = DB::select("SELECT relation::regclass::text AS relation,mode,granted FROM pg_locks WHERE pid=? AND relation='business_command_receipts'::regclass AND NOT granted", [$proof['wait']['pid']]);
                $this->assertCount(1, $proof['receipt_wait']);
                $this->waitFor(fn () => $db->scalar('SELECT clock_timestamp()>=?::timestamptz', [$proof['expiry']]), $worker, 25);
            } else {
                $this->admit('restrict_source', $source, 1, ['reason' => 'Synthetic withdrawal while writer waits']);
            }
            $db->commit();
            $worker->wait();
            if ($mode === 'retained_expiry') {
                $this->assertSame(0, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
                preg_match('/COMMITTED=(.+)/', $worker->getOutput(), $output);
                $result = json_decode($output[1], true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('review_required', $result['materials'][0]['validity']['status']);
                $this->assertGreaterThanOrEqual(strtotime($expiry), strtotime($result['materials'][0]['validity']['checked_at']));
                $this->assertSame(['price' => 0], $result['materials'][0]['fields']);
            } else {
                $this->assertSame(2, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
                $this->assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
                $this->assertStringNotContainsString('COMMITTED=', $worker->getOutput());
            }
            foreach (['market_evidence_heads', 'market_evidence_revisions', 'market_evidence_transitions'] as $table) {
                $this->assertSame($evidenceExpires ? 1 : 0, $db->table($table)->count());
            }
            $this->assertSame($mode === 'retained_expiry' ? 1 : 0, $db->table('business_command_receipts')->where('command_id', $command)->count());
        } finally {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $proof['worker'] = ['exit' => $worker->getExitCode(), 'stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput()];
            file_put_contents(storage_path('framework/testing/'.$run.'-evidence.json'), json_encode($proof, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            foreach ([$path, $release] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    private function waitFor(callable $condition, Process $worker, int $seconds = 15): mixed
    {
        $deadline = microtime(true) + $seconds;
        do {
            if ($value = $condition()) {
                return $value;
            }
            if (! $worker->isRunning()) {
                throw new \RuntimeException('Worker exited before required observation: '.$worker->getOutput().$worker->getErrorOutput());
            }
            usleep(15000);
        } while (microtime(true) < $deadline);
        throw new \RuntimeException('Expected actual lock/clock observation missing: '.$worker->getOutput().$worker->getErrorOutput());
    }
}
