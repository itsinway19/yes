<?php

namespace Tests\Feature;

use App\MarketKnowledge\Processing\MarketProcessingTask;
use App\MarketKnowledge\Processing\OfferEquivalence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\BusinessAuthorityFixture as Fixture;
use Tests\Support\MarketKnowledgeFixture;
use Tests\TestCase;

final class MarketKnowledgeConcurrencyTest extends TestCase
{
    use MarketKnowledgeFixture;

    public function test_observed_source_withdrawal_wait_cannot_publish(): void
    {
        $this->contend('source');
    }

    public function test_selected_right_expiry_during_observed_final_receipt_wait_rolls_back(): void
    {
        $this->contend('receipt');
    }

    public function test_ticket_expiry_during_observed_final_receipt_wait_rolls_back(): void
    {
        $this->contend('ticket');
    }

    public function test_replay_requalifies_after_an_observed_run_row_wait(): void
    {
        $this->contend('replay');
    }

    public function test_capture_replay_requalifies_after_an_observed_command_lock_wait(): void
    {
        $originals = $this->knowledgeOriginals();
        $operator = $this->operator();
        $capture = $this->captureBody($operator, $originals);
        $this->actingAsInternal($operator)->postJson('/internal/market-knowledge/capture', $capture)->assertOk();
        $tag = 'knowledge-capture-replay-'.Str::uuid();
        $path = storage_path('framework/testing/'.$tag.'.json');
        $release = $path.'.release';
        file_put_contents($path, json_encode(['tag' => $tag, 'actor' => $operator->id, 'operation' => 'capture',
            'session_id' => app('session')->driver()->getId(), 'generation' => (int) app('session')->driver()->get('a001_generation'),
            'body' => $capture, 'release' => $release], JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/Support/MarketKnowledgeCommandWorker.php'), $path], base_path());
        $worker->setTimeout(45);
        $owner = DB::connection('pgsql_migrations');
        $evidence = ['mode' => 'capture_replay_after_advisory_wait'];
        try {
            $worker->start();
            $this->waitFor(fn () => str_contains($worker->getOutput(), 'READY_PID='), $worker);
            $grant = $capture['authority_selection']['requirements'][0]['leaf_grant_id'];
            $evidence['expiry'] = Fixture::expireQualificationSoon($owner->table('security_action_grants')->where('id', $grant)->value('qualification_id'), $operator->id);
            $owner->beginTransaction();
            $blocker = (int) $owner->scalar('SELECT pg_backend_pid()');
            $owner->select("SELECT pg_advisory_xact_lock(hashtextextended('builderad.market.knowledge.capture.'||?::uuid,0))", [$capture['command_id']]);
            file_put_contents($release, 'ready');
            $evidence['wait'] = $this->waitFor(fn () => DB::selectOne("SELECT pid,backend_xid::text AS xid,wait_event_type,wait_event,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock' AND wait_event='advisory' AND ?=ANY(pg_blocking_pids(pid))", [$tag, $blocker]), $worker);
            $this->assertNotSame($blocker, $evidence['wait']->pid);
            $this->assertNotEmpty($evidence['wait']->xid);
            $this->waitFor(fn () => $owner->scalar('SELECT clock_timestamp()>=?::timestamptz', [$evidence['expiry']]), $worker);
            $owner->commit();
            $worker->wait();
            $evidence['output'] = $worker->getOutput();
            $this->assertSame(2, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
            $this->assertStringNotContainsString('COMMITTED=', $worker->getOutput());
            $this->assertSame(1, $owner->table('market_knowledge_captures')->count());
            $this->assertSame('prepared', $owner->table('market_knowledge_captures')->value('state'));
            $this->assertSame(1, $owner->table('business_command_receipts')->where('command_id', $capture['command_id'])->count());
        } finally {
            if ($owner->transactionLevel() > 0) {
                $owner->rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
            $evidence['stdout'] = $worker->getOutput();
            $evidence['stderr'] = $worker->getErrorOutput();
            $evidence['exit'] = $worker->getExitCode();
            file_put_contents(storage_path('framework/testing/'.$tag.'-evidence.json'), json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            foreach ([$path, $release] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function test_private_capture_replay_requalifies_after_an_observed_command_lock_wait(): void
    {
        $originals = $this->knowledgeOriginals();
        $consumer = $this->processingConsumer($originals);
        $processing = $this->processingBody($originals, $consumer);
        $this->prepareProcessing($consumer, $processing)->assertOk();
        $this->completeProcessing($consumer, $processing['command_id'])->assertOk();
        $business = $consumer['business']['business_public_id'];
        $read = (string) Str::uuid();
        $this->postJson('/api/businesses/'.$business.'/materials/read', ['command_id' => $read,
            'material_refs' => array_column($originals['records'], 'ref'), 'operation' => 'process', 'purpose' => 'market_research',
            'recipient_ref' => $consumer['user']->public_id, 'authority_selection' => $consumer['selection']])->assertOk();
        $artifact = (string) Str::uuid();
        $output = $this->knowledgeRight($consumer['user'], $originals['source'], $artifact, 'market.business_finding', 'display', ['finding']);
        $continuation = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => []];
        foreach ($originals['records'] as $record) {
            foreach (['forward', 'retain'] as $operation) {
                $right = $this->marketRight($consumer['user'], $record['source'], $record['evidence'], $operation, array_keys($record['payload']['fields']));
                $continuation['requirements'][] = ['requirement_kind' => 'data_use', 'leaf_grant_id' => $right['grant_id']];
            }
        }
        $body = ['command_id' => $artifact, 'processing_run_ref' => $processing['command_id'], 'read_command_id' => $read, 'output_scope_id' => $output['scope_id'],
            'authority_selection' => $consumer['selection'], 'context_selection' => null, 'continuation_selection' => $continuation];
        $url = '/api/businesses/'.$business.'/market-findings/capture';
        $captured = $this->postJson($url, $body)->assertOk()->json('data');
        $this->assertSame($captured, $this->postJson($url, $body)->assertOk()->json('data'));
        $tag = 'private-capture-replay-'.Str::uuid();
        $path = storage_path('framework/testing/'.$tag.'.json');
        $release = $path.'.release';
        file_put_contents($path, json_encode(['tag' => $tag, 'actor' => $consumer['user']->id, 'operation' => 'private_capture', 'business' => $business,
            'session_id' => app('session')->driver()->getId(), 'generation' => (int) app('session')->driver()->get('a001_generation'),
            'body' => $body, 'release' => $release], JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/Support/MarketKnowledgeCommandWorker.php'), $path], base_path());
        $worker->setTimeout(45);
        $owner = DB::connection('pgsql_migrations');
        $evidence = ['mode' => 'private_capture_replay_after_advisory_wait'];
        try {
            $worker->start();
            $this->waitFor(fn () => str_contains($worker->getOutput(), 'READY_PID='), $worker);
            $selected = array_values(array_filter($consumer['selection']['requirements'], fn ($requirement) => $requirement['requirement_kind'] === 'data_use'));
            $evidence['expiry'] = Fixture::expireQualificationSoon($owner->table('security_action_grants')->where('id', $selected[0]['leaf_grant_id'])->value('qualification_id'), $consumer['user']->id);
            $owner->beginTransaction();
            $blocker = (int) $owner->scalar('SELECT pg_backend_pid()');
            $owner->select("SELECT pg_advisory_xact_lock(hashtextextended('builderad.private.finding.'||?::uuid,0))", [$artifact]);
            file_put_contents($release, 'ready');
            $evidence['wait'] = $this->waitFor(fn () => DB::selectOne("SELECT pid,backend_xid::text AS xid,wait_event_type,wait_event,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock' AND wait_event='advisory' AND ?=ANY(pg_blocking_pids(pid))", [$tag, $blocker]), $worker);
            $this->assertNotSame($blocker, $evidence['wait']->pid);
            $this->assertNotEmpty($evidence['wait']->xid);
            $this->waitFor(fn () => $owner->scalar('SELECT clock_timestamp()>=?::timestamptz', [$evidence['expiry']]), $worker);
            $owner->commit();
            $worker->wait();
            $this->assertSame(2, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
            $this->assertStringNotContainsString('COMMITTED=', $worker->getOutput());
            $this->assertSame(1, $owner->table('market_business_finding_captures')->count());
            $this->assertSame('prepared', $owner->table('market_business_finding_captures')->value('state'));
            $this->assertSame(1, $owner->table('business_command_receipts')->where('command_id', $artifact)->count());
        } finally {
            if ($owner->transactionLevel() > 0) {
                $owner->rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
            $evidence['stdout'] = $worker->getOutput();
            $evidence['stderr'] = $worker->getErrorOutput();
            $evidence['exit'] = $worker->getExitCode();
            file_put_contents(storage_path('framework/testing/'.$tag.'-evidence.json'), json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            foreach ([$path, $release] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function test_foreign_source_grant_cannot_even_enter_the_resource_lock_footprint(): void
    {
        $originals = $this->knowledgeOriginals();
        $ownerActor = $this->operator();
        $body = $this->captureBody($ownerActor, $originals);
        $intruder = $this->operator();
        $this->actingAsInternal($intruder);
        $tag = 'knowledge-preflight-'.Str::uuid();
        $path = storage_path('framework/testing/'.$tag.'.json');
        $release = $path.'.release';
        file_put_contents($path, json_encode(['tag' => $tag, 'actor' => $intruder->id, 'operation' => 'capture', 'session_id' => app('session')->driver()->getId(),
            'generation' => (int) app('session')->driver()->get('a001_generation'), 'body' => $body, 'release' => $release], JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/Support/MarketKnowledgeCommandWorker.php'), $path], base_path());
        $worker->setTimeout(45);
        $owner = DB::connection('pgsql_migrations');
        $observed = [];
        try {
            $owner->beginTransaction();
            $blocker = (int) $owner->scalar('SELECT pg_backend_pid()');
            $owner->select("SELECT fence_id FROM resource_right_fences WHERE kind='source' AND source_id=?::uuid FOR UPDATE", [$originals['source']]);
            $worker->start();
            $this->waitFor(fn () => str_contains($worker->getOutput(), 'READY_PID='), $worker);
            file_put_contents($release, 'ready');
            $until = microtime(true) + 15;
            while ($worker->isRunning() && microtime(true) < $until) {
                foreach (DB::select('SELECT pid,wait_event_type,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE application_name=?', [$tag]) as $row) {
                    if ($row->wait_event_type === 'Lock' && str_contains($row->blockers, (string) $blocker)) {
                        $observed[] = (array) $row;
                        break 2;
                    }
                }
                usleep(15000);
            }
            $owner->commit();
            $worker->wait();
            $this->assertSame(2, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
            $this->assertSame([], $observed, 'A source licence belonging to another actor must be rejected before attempting its resource lock.');
            $this->assertSame(0, $owner->table('market_knowledge_captures')->count());
        } finally {
            if ($owner->transactionLevel() > 0) {
                $owner->rollBack();
            }if ($worker->isRunning()) {
                $worker->stop(1);
            }
            file_put_contents(storage_path('framework/testing/'.$tag.'-evidence.json'), json_encode(['observed_foreign_wait' => $observed, 'output' => $worker->getOutput()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            foreach ([$path, $release] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    private function contend(string $mode): void
    {
        $originals = $this->knowledgeOriginals();
        $operator = $this->operator(['market.knowledge.capture', 'market.knowledge.publish']);
        $capture = $this->captureBody($operator, $originals);
        $body = $this->knowledgePublication($operator, $originals, $capture);
        $this->actingAsInternal($operator)->postJson('/internal/market-knowledge/capture', $capture)->assertOk();
        if ($mode === 'replay') {
            $this->postJson('/internal/market-knowledge/publish', $body)->assertOk();
        }
        $task = new MarketProcessingTask('compare_offers', array_map(fn ($record) => ['ref' => $record['ref'], 'fields' => $record['payload']['fields'], 'evidence' => ['schema' => 'market.offer:2']], $originals['records']));
        $finding = (new OfferEquivalence)->execute($task);
        $tag = 'knowledge-'.Str::uuid();
        $path = storage_path('framework/testing/'.$tag.'.json');
        $release = $path.'.release';
        file_put_contents($path, json_encode(['tag' => $tag, 'actor' => $operator->id, 'session_id' => app('session')->driver()->getId(),
            'generation' => (int) app('session')->driver()->get('a001_generation'), 'body' => $body, 'finding' => $finding, 'release' => $release,
            'replay' => $mode === 'replay', 'short_ticket' => $mode === 'ticket'], JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/Support/MarketKnowledgeCommandWorker.php'), $path], base_path());
        $worker->setTimeout(45);
        $owner = DB::connection('pgsql_migrations');
        $evidence = ['mode' => $mode];
        try {
            $worker->start();
            $this->waitFor(fn () => str_contains($worker->getOutput(), 'READY_PID='), $worker);
            if (in_array($mode, ['receipt', 'replay'], true)) {
                $grant = $capture['authority_selection']['requirements'][0]['leaf_grant_id'];
                $evidence['expiry'] = Fixture::expireQualificationSoon($owner->table('security_action_grants')->where('id', $grant)->value('qualification_id'), $operator->id);
            }
            $owner->beginTransaction();
            $blocker = (int) $owner->scalar('SELECT pg_backend_pid()');
            if ($mode === 'source') {
                $owner->select("SELECT fence_id FROM resource_right_fences WHERE kind='source' AND source_id=?::uuid FOR UPDATE", [$originals['source']]);
            } elseif ($mode === 'replay') {
                $owner->select('SELECT run_id FROM market_knowledge_captures WHERE run_id=?::uuid FOR UPDATE', [$capture['command_id']]);
            } else {
                $owner->statement('LOCK TABLE business_command_receipts IN SHARE MODE');
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
            $this->assertNotSame($blocker, $evidence['wait']['pid']);
            $this->assertNotEmpty($evidence['wait']['xid']);
            if ($mode === 'source') {
                $this->admit('restrict_source', $originals['source'], 1, ['reason' => 'SYNTHETIC withdrawal during observed knowledge resource wait']);
            } else {
                if ($mode === 'ticket') {
                    $this->assertSame(1, preg_match('/ATTESTATION_EXPIRES=([^\r\n]+)/', $worker->getOutput(), $match));
                    $evidence['expiry'] = $match[1];
                }
                $this->waitFor(fn () => $owner->scalar('SELECT clock_timestamp()>=?::timestamptz', [$evidence['expiry']]), $worker);
            }
            $owner->commit();
            $worker->wait();
            $evidence['output'] = $worker->getOutput();
            $this->assertSame(2, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
            $this->assertStringNotContainsString('COMMITTED=', $worker->getOutput());
            $this->assertSame($mode === 'replay' ? 'published' : 'prepared', $owner->table('market_knowledge_captures')->value('state'));
            $this->assertSame($mode === 'replay' ? 1 : 0, $owner->table('material_lineage_manifests')->count());
            $this->assertSame($mode === 'replay' ? 1 : 0, $owner->table('business_command_receipts')->where('command_id', $body['command_id'])->count());
            if ($mode !== 'replay') {
                $this->assertSame(0, $owner->table('market_knowledge_completion_tickets')->whereNotNull('consumed_at')->count());
            }
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
