<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Process\Process;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\BusinessAuthorityFixture;
use Tests\Support\ObjectPurposeTestCase;
use Tests\TestCase;

final class BusinessPurposeTest extends TestCase
{
    use ApplicationContractAssertions;

    private function setupPurpose(): array
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $business = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Independent Purpose owner'])->assertCreated()->json('data');
        $id = DB::connection('pgsql_migrations')->table('businesses')->where('public_id', $business['business_public_id'])->value('id');
        $grant = BusinessAuthorityFixture::root($id, $actor->id, 'business.purpose.declare');

        return [$business, ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $grant->id]]], $actor];
    }

    private function url(array $b): string
    {
        return '/api/businesses/'.$b['business_public_id'].'/purposes';
    }

    private function read(array $b, string $query = ''): TestResponse
    {
        return $this->getJson($this->url($b).'?access='.$b['grant_refs']['business.context.read'].$query);
    }

    private function write(array $b, array $selection, array $command, int $context = 0): TestResponse
    {
        return $this->postJson($this->url($b), ['command' => $command, 'expected_context' => $context, 'authority_selection' => $selection]);
    }

    public function test_independent_native_owner_opaque_objects_current_projection_and_exact_replay(): void
    {
        [$b, $selection] = $this->setupPurpose();
        $command = ObjectPurposeTestCase::command();
        $command['declaration']['end_condition'] = null;
        $result = $this->write($b, $selection, $command)->assertCreated()->json('data');
        $this->assertSame($result, $this->write($b, $selection, $command)->assertCreated()->json('data'));
        $read = $this->read($b)->assertOk()->assertJsonPath('data.scope', 'native_business_purposes_only')
            ->assertJsonPath('data.projection.state', 'DECLARED')->assertJsonPath('data.projection.items.0.continuity', 'DECLARED_EFFECTIVE_STANDING');
        $this->assertJsonStringEqualsJsonString(json_encode($command['declaration']), json_encode($read->json('data.projection.items.0.applicable.snapshot')));
        $this->assertApplicationContract('BusinessPurposesResult', $read->json());
        $owner = DB::connection('pgsql_migrations');
        $row = $owner->table('object_purpose_revisions')->where('revision_id', $result['revision_id'])->first();
        $this->assertNull($row->workspace_id);
        $this->assertNotNull($row->business_id);
        $this->assertSame(1, $owner->table('object_purpose_revisions')->where('purpose_id', $result['purpose_id'])->count());
        $this->assertSame('v2', $owner->table('object_purpose_business_context_refs')->where('native_revision_id', $result['revision_id'])->value('reference_origin'));
        foreach (['actor_user_id', 'actor_generation', 'ownership_version', 'business_authority_epoch', 'command_id'] as $private) {
            $this->assertStringNotContainsString($private, $read->getContent());
        }
        $command['declaration']['meaning'] = 'Reinterpreted receipt';
        $this->write($b, $selection, $command)->assertStatus(409);
    }

    public function test_no_implicit_draft_authority_wrong_business_or_direct_sql_write(): void
    {
        [$b, $selection] = $this->setupPurpose();
        $wrong = $selection;
        $wrong['requirements'][0]['leaf_grant_id'] = $b['grant_refs']['business.context.manage'];
        $this->write($b, $wrong, ObjectPurposeTestCase::command())->assertForbidden();
        $other = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Other business'])->assertCreated()->json('data');
        $this->write($other, $selection, ObjectPurposeTestCase::command())->assertForbidden();
        $this->read($b)->assertOk()->assertJsonCount(0, 'data.projection.items');
        foreach (['object_purposes', 'object_purpose_revisions', 'object_purpose_command_receipts'] as $table) {
            $this->assertFalse((bool) DB::scalar("SELECT has_table_privilege(current_user, ?, 'INSERT,UPDATE,DELETE')", [$table]));
        }
    }

    public function test_correct_and_withdraw_preserve_temporal_history_and_known_time(): void
    {
        [$b, $selection] = $this->setupPurpose();
        $command = ObjectPurposeTestCase::command();
        $first = $this->write($b, $selection, $command)->assertCreated()->json('data');
        $known = $this->read($b)->assertOk()->json('data.projection.known_at');
        $command = array_replace($command, ['command_id' => (string) Str::uuid(), 'purpose_id' => $first['purpose_id'], 'expected_version' => 1,
            'operation' => 'correct', 'supersedes_revision_id' => $first['revision_id'], 'affected_revision_ids' => [$first['revision_id']], 'correction_reason' => 'Owner correction']);
        $command['declaration']['meaning'] = 'Corrected qualified meaning';
        $command['confirmations']['transition'] = true;
        $second = $this->write($b, $selection, $command)->assertCreated()->json('data');
        $this->read($b)->assertOk()->assertJsonCount(2, 'data.projection.items.0.history')->assertJsonPath('data.projection.items.0.applicable.snapshot.meaning', 'Corrected qualified meaning');
        $this->read($b, '&known_at='.rawurlencode($known))->assertOk()->assertJsonCount(1, 'data.projection.items.0.history');
        $command = array_replace($command, ['command_id' => (string) Str::uuid(), 'expected_version' => 2, 'operation' => 'withdraw', 'declaration' => null,
            'supersedes_revision_id' => $second['revision_id'], 'affected_revision_ids' => [$first['revision_id'], $second['revision_id']], 'correction_reason' => null, 'withdrawal_reason' => 'Not applicable now']);
        $this->write($b, $selection, $command)->assertCreated();
        $this->read($b)->assertOk()->assertJsonPath('data.projection.state', 'UNKNOWN')->assertJsonCount(3, 'data.projection.items.0.history');
    }

    public function test_revoked_path_and_stale_context_never_publish_or_confirm_success(): void
    {
        [$b, $selection, $actor] = $this->setupPurpose();
        $this->write($b, $selection, ObjectPurposeTestCase::command(), 1)->assertStatus(409);
        $id = $selection['requirements'][0]['leaf_grant_id'];
        $q = DB::connection('pgsql_migrations')->table('security_action_grants')->where('id', $id)->value('qualification_id');
        BusinessAuthorityFixture::revokeQualification($q, $actor->id);
        $this->write($b, $selection, ObjectPurposeTestCase::command())->assertForbidden();
        $this->read($b)->assertOk()->assertJsonCount(0, 'data.projection.items');
    }

    public function test_current_markets_reference_revalidates_and_stale_head_or_reference_cannot_write(): void
    {
        [$b, $selection] = $this->setupPurpose();
        $command = ObjectPurposeTestCase::command();
        $command['declaration']['dtm'] = ['mode' => 'current_alignment', 'version' => 0, 'countries' => [], 'subset' => [], 'statement' => 'Reviewed current markets'];
        $command['confirmations']['dtm'] = true;
        $first = $this->write($b, $selection, $command)->assertCreated()->json('data');
        $marketPath = $selection;
        $marketPath['requirements'][0]['leaf_grant_id'] = $b['grant_refs']['business.markets.declare'];
        $this->putJson('/api/businesses/'.$b['business_public_id'].'/markets', ['command_id' => (string) Str::uuid(), 'codes' => ['SA'], 'expected_context' => 0, 'authority_selection' => $marketPath])->assertOk();
        $this->read($b)->assertOk()->assertJsonPath('data.projection.items.0.relationship_revalidation_required', true);
        $command = array_replace($command, ['command_id' => (string) Str::uuid(), 'purpose_id' => $first['purpose_id'], 'expected_version' => 1, 'operation' => 'replace', 'supersedes_revision_id' => $first['revision_id'], 'affected_revision_ids' => [$first['revision_id']]]);
        $command['confirmations']['transition'] = true;
        $this->write($b, $selection, $command, 1)->assertStatus(409);
        $command['declaration']['dtm']['version'] = 1;
        $command['declaration']['dtm']['countries'] = ['SA'];
        $this->write($b, $selection, $command, 1)->assertCreated();
        $this->read($b)->assertOk()->assertJsonPath('data.projection.items.0.relationship_revalidation_required', false);
        $command['command_id'] = (string) Str::uuid();
        $this->write($b, $selection, $command, 1)->assertStatus(409);
    }

    public function test_future_declaration_is_scheduled_and_never_inferred_current_before_its_effective_time(): void
    {
        [$b, $selection] = $this->setupPurpose();
        $command = ObjectPurposeTestCase::command();
        $command['effective_from'] = now()->addHour()->toIso8601String();
        $result = $this->write($b, $selection, $command)->assertCreated()->json('data');
        $this->read($b)->assertOk()->assertJsonPath('data.projection.state', 'UNKNOWN')->assertJsonPath('data.projection.items.0.scheduled_revision_ids', [$result['revision_id']]);
        $this->read($b, '&effective_at='.rawurlencode(now()->addHours(2)->toIso8601String()))->assertOk()->assertJsonPath('data.projection.state', 'DECLARED');
    }

    public function test_two_real_deliveries_of_one_new_purpose_command_commit_one_native_identity_and_same_receipt(): void
    {
        [$b, $selection, $actor] = $this->setupPurpose();
        $command = ObjectPurposeTestCase::command();
        $request = Request::create('/api/businesses');
        $request->setLaravelSession(app('session')->driver());
        $run = 'purpose-replay-'.Str::uuid();
        $base = storage_path('framework/testing/'.$run);
        if (! is_dir(dirname($base))) {
            mkdir(dirname($base), 0755, true);
        }
        $release = $base.'.release';
        $workers = [];
        $evidence = [];
        config(['database.connections.purpose_replay_barrier' => config('database.connections.pgsql_migrations')]);
        $barrier = DB::connection('purpose_replay_barrier');
        $barrier->beginTransaction();
        $pid = (int) $barrier->scalar('SELECT pg_backend_pid()');
        $barrier->select('SELECT 1 FROM business_context_heads WHERE business_id=(SELECT id FROM businesses WHERE public_id=?::uuid) FOR UPDATE', [$b['business_public_id']]);
        try {
            foreach ([0, 1] as $index) {
                file_put_contents($base.'-'.$index.'.json', json_encode(['run' => $run.'-'.$index, 'actor' => $actor->id,
                    'session' => SessionAuthority::sessionKey($request), 'business' => $b['business_public_id'], 'operation' => 'purpose', 'command' => $command['command_id'],
                    'purpose_command' => $command, 'expected' => 0, 'selection' => $selection, 'release' => $release], JSON_THROW_ON_ERROR));
                $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $base.'-'.$index.'.json'], base_path());
                $worker->setTimeout(40);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 15;
            while (! array_all($workers, fn ($worker) => str_contains($worker->getOutput(), 'READY_PID='))) {
                $this->assertLessThan($deadline, microtime(true), 'Real workers must be ready before release.');
                usleep(10000);
            }
            file_put_contents($release, 'ready');
            do {
                // A second row-lock waiter can queue behind the first waiter's
                // tuple lock. Observe the real blocking graph, not only direct
                // edges to the external blocker (which cannot see that chain).
                $waiting = DB::select('WITH RECURSIVE chain(origin,pid,depth) AS (
                    SELECT pid,pid,0 FROM pg_stat_activity WHERE application_name IN (?,?)
                    UNION ALL SELECT chain.origin,blocking.pid,chain.depth+1 FROM chain CROSS JOIN LATERAL unnest(pg_blocking_pids(chain.pid)) blocking(pid) WHERE chain.depth<8
                ) SELECT origin AS pid,array_agg(DISTINCT pid) AS blocking_path FROM chain GROUP BY origin HAVING bool_or(pid=?)', [$run.'-0', $run.'-1', $pid]);
                if (count($waiting) === 2) {
                    break;
                }
                $this->assertLessThan($deadline, microtime(true), 'Both independent deliveries must reach the real context lock.');
                usleep(10000);
            } while (true);
            $evidence['observed_waiters'] = $waiting;
            $barrier->commit();
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
                $this->assertMatchesRegularExpression('/COMMITTED=(.+)/', $worker->getOutput());
                preg_match('/COMMITTED=(.+)/', $worker->getOutput(), $match);
                $results[] = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertSame($results[0], $results[1]);
            $db = DB::connection('pgsql_migrations');
            $this->assertSame(1, $db->table('object_purpose_revisions')->where('purpose_id', $results[0]['purpose_id'])->count());
            $this->assertSame(1, $db->table('object_purpose_command_receipts')->where('command_id', $command['command_id'])->count());
            $this->assertSame(1, $db->table('business_command_receipts')->where('command_id', $command['command_id'])->count());
        } finally {
            if ($barrier->transactionLevel()) {
                $barrier->rollBack();
            }
            DB::disconnect('purpose_replay_barrier');
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                } $evidence['workers'][] = ['out' => $worker->getOutput(), 'error' => $worker->getErrorOutput(), 'exit' => $worker->getExitCode()];
            }
            file_put_contents($base.'-evidence.json', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            foreach ([$base.'-0.json', $base.'-1.json', $release] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
