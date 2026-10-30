<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\SessionAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\BusinessAuthorityFixture;
use Tests\Support\DataUseSurfaceFixture;
use Tests\TestCase;

final class DataUseSurfaceTest extends TestCase
{
    use ApplicationContractAssertions;

    private function draft(User $actor, string $name): array
    {
        return $this->actingAs($actor)->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => $name])->assertCreated()->json('data');
    }

    private function selection(string $kind, int $grant): array
    {
        return ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => $kind, 'leaf_grant_id' => $grant]]];
    }

    private function coverage(array $scope, array $business): void
    {
        $selection = $this->selection('client_data', $scope['boundary']['grant_id']);
        $selection['requirements'][] = ['requirement_kind' => 'business', 'leaf_grant_id' => $business['grant_refs']['business.link.manage']];
        $this->postJson('/api/data-rights/commands', ['command_id' => (string) Str::uuid(), 'target_kind' => 'boundary_subject', 'target_ref' => $scope['boundary']['boundary_id'],
            'effect' => 'attach_subject', 'payload' => ['business_public_id' => $business['business_public_id'], 'branch_id' => null], 'expected_revision' => 0, 'authority_selection' => $selection])->assertOk();
    }

    private function binding(array $scope, array $business, string $effect = 'add', int $revision = 0): array
    {
        $selection = $this->selection('business', $business['grant_refs']['business.link.manage']);
        if ($effect === 'add') {
            $selection['requirements'][] = ['requirement_kind' => 'data_use', 'leaf_grant_id' => $scope['grant_id']];
        }

        return ['command_id' => (string) Str::uuid(), 'data_use_scope_id' => $scope['scope_id'], 'effect' => $effect, 'expected_relation' => $revision, 'authority_selection' => $selection];
    }

    public function test_scope_choices_are_independent_bounded_permission_metadata_without_source_or_customer_content(): void
    {
        $actor = User::factory()->create();
        $foreign = User::factory()->create();
        $this->draft($actor, 'Business alone is insufficient');
        $this->getJson('/api/data-rights/use-scopes')->assertOk()->assertJsonCount(0, 'data.scopes');
        $a = DataUseSurfaceFixture::scope($actor);
        DataUseSurfaceFixture::scope($foreign, $a['source']);
        $result = $this->getJson('/api/data-rights/use-scopes')->assertOk()->assertJsonCount(1, 'data.scopes')
            ->assertJsonPath('data.scopes.0.scope_id', $a['scope_id'])->assertJsonPath('data.scopes.0.record_count', 1)
            ->assertJsonPath('data.scopes.0.field_keys', ['city']);
        $this->assertApplicationContract('DataUseChoicesResult', $result->json());
        foreach (['private-customer-key', 'Private source display name', 'private-source-identifier', 'test.private'] as $private) {
            $this->assertStringNotContainsString($private, $result->getContent());
        }
        $this->getJson('/api/source-rights')->assertOk()->assertJsonCount(0, 'data.sources');
        $this->getJson('/api/data-rights/use-scopes?after='.$a['grant_id'])->assertOk()->assertJsonCount(0, 'data.scopes');
        try {
            DB::table('data_use_scopes')->get();
            $this->fail('Direct data-scope SQL was accepted');
        } catch (QueryException $e) {
            $this->assertSame('42501', $e->getCode());
        }
    }

    public function test_binding_metadata_survives_use_withdrawal_without_inventing_rights_and_requalification_does_not_revive_a_binding(): void
    {
        $actor = User::factory()->create();
        $a = $this->draft($actor, 'First data customer');
        $b = $this->draft($actor, 'Second data customer');
        $scope = DataUseSurfaceFixture::scope($actor);
        $this->coverage($scope, $a);
        $path = '/api/businesses/'.$a['business_public_id'].'/data-use-bindings';
        $this->postJson('/api/businesses/'.$b['business_public_id'].'/data-use-bindings', $this->binding($scope, $b))->assertForbidden();
        $first = $this->postJson($path, $this->binding($scope, $a))->assertOk()->json('data');
        $viewer = User::factory()->create();
        $businessId = DB::connection('pgsql_migrations')->table('businesses')->where('public_id', $a['business_public_id'])->value('id');
        $readOnly = BusinessAuthorityFixture::root($businessId, $viewer->id, 'business.context.read');
        $this->actingAs($viewer)->getJson('/api/businesses/'.$a['business_public_id'].'/surface?access='.$readOnly->id)
            ->assertOk()->assertJsonCount(0, 'data.data_use.bindings')->assertJsonCount(0, 'data.collaboration.options');
        $this->getJson('/api/data-rights/use-scopes')->assertOk()->assertJsonCount(0, 'data.scopes');
        $this->actingAs($actor);
        $revoke = ['command_id' => (string) Str::uuid(), 'target_kind' => 'data_use', 'target_ref' => $scope['scope_id'], 'effect' => 'revoke',
            'payload' => ['grant_id' => (string) $scope['grant_id']], 'expected_revision' => 1, 'authority_selection' => $this->selection('data_use', $scope['grant_id'])];
        $this->postJson('/api/data-rights/commands', $revoke)->assertOk();
        $this->getJson('/api/data-rights/use-scopes')->assertOk()->assertJsonCount(0, 'data.scopes');
        $surface = '/api/businesses/'.$a['business_public_id'].'/surface?access='.$a['grant_refs']['business.context.read'];
        $read = $this->getJson($surface)->assertOk()->assertJsonPath('data.data_use.bindings.0.binding_id', $first['binding_id']);
        $this->assertApplicationContract('BusinessSurfaceResult', $read->json());
        $this->postJson($path, $this->binding($scope, $a, 'end', 1))->assertOk()->assertJsonPath('data.versions.binding', 2);
        $old = $scope['grant_id'];
        $scope['grant_id'] = DataUseSurfaceFixture::requalify($scope, $actor);
        $this->assertNotSame($old, $scope['grant_id']);
        $this->assertSame('revoked', DB::connection('pgsql_migrations')->table('security_action_grants')->where('id', $old)->value('status'));
        $this->getJson($surface)->assertOk()->assertJsonPath('data.data_use.bindings.0.state', 'ended');
        $second = $this->postJson($path, $this->binding($scope, $a, 'add', 2))->assertOk()->assertJsonPath('data.versions.binding', 3)->json('data');
        $this->assertNotSame($first['binding_id'], $second['binding_id']);
        $this->postJson($path, $this->binding($scope, $a, 'end', 1))->assertConflict()->assertJsonPath('error', 'business_relation_revision_rejected');
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('security_action_grants')->where('scope_kind', 'source')->count());
    }

    public function test_choices_do_not_hold_k4_identity_while_waiting_at_k1_and_requalify_withdrawn_use_right(): void
    {
        $this->assertReadWait('withdraw');
    }

    public function test_choices_discard_a_scope_snapshot_retired_during_the_real_resource_wait(): void
    {
        $this->assertReadWait('retire');
    }

    private function assertReadWait(string $change): void
    {
        $actor = User::factory()->create();
        $this->draft($actor, 'Scope wait customer');
        $scope = DataUseSurfaceFixture::scope($actor);
        $this->getJson('/api/data-rights/use-scopes')->assertOk()->assertJsonCount(1, 'data.scopes');
        $request = Request::create('/api/data-rights/use-scopes');
        $request->setLaravelSession(app('session')->driver());
        $run = 'data-use-choice-'.$change.'-'.Str::uuid();
        $file = storage_path('framework/testing/'.$run.'.json');
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        $release = $file.'.release';
        file_put_contents($file, json_encode(['actor' => $actor->id, 'run' => $run, 'operation' => 'data-use-choices',
            'session' => SessionAuthority::sessionKey($request), 'release' => $release], JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $file], base_path());
        $worker->setTimeout(45);
        $db = DB::connection('pgsql_migrations');
        $evidence = ['change' => $change];
        $wait = function (callable $probe) use ($worker) {
            $deadline = microtime(true) + 15;
            do {
                if ($value = $probe()) {
                    return $value;
                }
                if (! $worker->isRunning()) {
                    throw new \RuntimeException($worker->getOutput().$worker->getErrorOutput());
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            throw new \RuntimeException('Actual data-use reader wait was not witnessed.');
        };
        try {
            $worker->start();
            $wait(fn () => str_contains($worker->getOutput(), 'READY_PID='));
            $db->beginTransaction();
            $blocker = (int) $db->scalar('SELECT pg_backend_pid()');
            $db->select('SELECT fence_id FROM resource_right_fences WHERE fence_id=?::uuid FOR UPDATE', [$scope['boundary']['fence_id']]);
            file_put_contents($release, 'go');
            $evidence['wait'] = $wait(fn () => DB::selectOne('SELECT pid,wait_event_type,wait_event,pg_blocking_pids(pid)::text blockers FROM pg_stat_activity WHERE application_name=? AND ?=ANY(pg_blocking_pids(pid))', [$run, $blocker]));
            $db->transaction(fn () => $db->select('SELECT user_id FROM principal_lifecycles WHERE user_id=? FOR UPDATE NOWAIT', [$actor->id]));
            $evidence['identity_available_at_k1'] = true;
            if ($change === 'withdraw') {
                BusinessAuthorityFixture::revokeQualification($scope['qualification'], $actor->id);
            } else {
                DataUseSurfaceFixture::retire($scope, $actor);
            }
            $db->commit();
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('"scopes": []', $worker->getOutput());
            $this->assertStringNotContainsString('qualified_records', $worker->getOutput());
        } finally {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $evidence['worker'] = ['stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput(), 'exit' => $worker->getExitCode()];
            file_put_contents(storage_path('framework/testing/'.$run.'-evidence.json'), json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            foreach ([$file, $release] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
