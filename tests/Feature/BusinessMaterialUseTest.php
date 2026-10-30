<?php

namespace Tests\Feature;

use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\BusinessAuthorityFixture as Fixture;
use Tests\Support\NativeMaterialFixture;
use Tests\TestCase;

final class BusinessMaterialUseTest extends TestCase
{
    use ApplicationContractAssertions;
    use NativeMaterialFixture;

    private function read(array $f, ?array $body = null)
    {
        return $this->postJson('/api/businesses/'.$f['a']['business_public_id'].'/materials/read', $body ?? $f['body']);
    }

    public function test_actual_source_adoption_and_exact_native_right_withdrawal_control_material_use_without_silent_recovery(): void
    {
        $f = $this->original(adoptViaCommand: true);
        $this->read($f)->assertOk();
        $selection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
            'requirements' => [['requirement_kind' => 'native_source', 'leaf_grant_id' => $f['manager']]]];
        $right = ['command_id' => (string) Str::uuid(), 'target_kind' => 'source', 'target_ref' => $f['source'], 'effect' => 'revoke',
            'payload' => ['grant_id' => (string) $f['native']], 'expected_revision' => 1, 'authority_selection' => $selection];
        $this->postJson('/api/data-rights/commands', $right)->assertOk();
        $db = DB::connection('pgsql_migrations');
        $count = $db->table('business_command_receipts')->count();
        $this->read($f)->assertForbidden();
        $this->assertSame($count, $db->table('business_command_receipts')->count());
        $right = [...$right, 'command_id' => (string) Str::uuid(), 'effect' => 'qualify_issue', 'expected_revision' => 0,
            'payload' => ['action' => 'source.read', 'reason' => 'explicit_native_reissue', 'expires_at' => null]];
        $new = $this->postJson('/api/data-rights/commands', $right)->assertOk()->json('data.grant_id');
        $this->read($f)->assertForbidden(); // A new right never revives the explicitly selected revoked ID.
        $body = $f['body'];
        $body['authority_selection']['requirements'][2]['leaf_grant_id'] = $new;
        $this->read($f, $body)->assertConflict(); // Same command with a different selection is not reconciliation.
        $body['command_id'] = (string) Str::uuid();
        $this->read($f, $body)->assertOk()->assertJsonPath('data.materials.0.coverage', 'PARTIAL');
        $this->assertSame('revoked', $db->table('security_action_grants')->where('id', $f['native'])->value('status'));
    }

    public function test_exact_native_original_read_filters_fields_and_preserves_partial_evidence_without_payload_in_receipts(): void
    {
        $f = $this->original();
        $result = $this->read($f)->assertOk();
        $this->assertApplicationContract('BusinessMaterialReadResult', $result->json());
        $result->assertJsonPath('data.materials.0.fields', ['spend' => ['amount' => '17', 'currency' => 'SAR']])->assertJsonPath('data.materials.0.coverage', 'PARTIAL');
        $this->assertStringNotContainsString('private_lead', $result->getContent());
        $receipt = json_decode(DB::connection('pgsql_migrations')->table('business_command_receipts')->where('command_id', $f['body']['command_id'])->value('result_ref'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('materials', $receipt);
        $this->assertArrayHasKey('material_refs', $receipt);
        $this->assertSame($result->json(), $this->read($f)->assertOk()->json());
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('business_command_receipts')->where('command_id', $f['body']['command_id'])->count());
    }

    public function test_read_right_cannot_be_promoted_into_processing_export_or_delivery_to_a_different_recipient(): void
    {
        $f = $this->original();
        foreach (['process', 'reuse', 'combine', 'display', 'export'] as $operation) {
            $body = $f['body'];
            $body['operation'] = $operation;
            $this->read($f, $body)->assertForbidden();
        }
        $body = $f['body'];
        $body['recipient_ref'] = (string) Str::uuid7();
        $this->read($f, $body)->assertForbidden();
        $body = $f['body'];
        $body['purpose'] = 'other_purpose';
        $this->read($f, $body)->assertForbidden();
        foreach ([0, 1, 2] as $missing) {
            $body = $f['body'];
            array_splice($body['authority_selection']['requirements'], $missing, 1);
            $this->read($f, $body)->assertForbidden();
        }
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('business_command_receipts')->where('action', 'like', 'material.%')->count());
    }

    public function test_withdrawal_requalifies_the_same_command_and_does_not_allow_a_saved_result_to_bypass_it(): void
    {
        $f = $this->original();
        $this->read($f)->assertOk();
        Fixture::revokeQualification($f['use']->qualification_id, $f['user']->id);
        $this->read($f)->assertForbidden();
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('business_command_receipts')->where('command_id', $f['body']['command_id'])->count());
    }

    public function test_absent_fields_and_unregistered_or_changed_materials_never_return_partial_success(): void
    {
        $f = $this->original(fields: ['spend', 'missing_field']);
        $this->read($f)->assertForbidden();
        $body = $f['body'];
        $body['material_refs'][0]['version'] = hash('sha256', 'other-version');
        $this->read($f, $body)->assertForbidden();
        $body = $f['body'];
        $body['material_refs'][0]['namespace'] = 'test.synthetic_material';
        $this->read($f, $body)->assertForbidden();
        $body = $f['body'];
        $body['material_refs'][] = ['namespace' => 'unknown.artifact', 'key' => 'not-owned', 'version' => '1'];
        $this->read($f, $body)->assertForbidden();
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('business_command_receipts')->where('action', 'like', 'material.%')->count());
    }

    public function test_two_businesses_can_use_one_native_original_without_sharing_client_boundaries_or_revocation(): void
    {
        $f = $this->original();
        $db = DB::connection('pgsql_migrations');
        $a = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Second material client'])->assertCreated()->json('data');
        $business = $db->table('businesses')->where('public_id', $a['business_public_id'])->value('id');
        $businessGrant = Fixture::root($business, $f['user']->id, 'business.data.use');
        $boundary = Fixture::boundary($f['user']->id);
        Fixture::insert('boundary_subjects', ['membership_id' => (string) Str::uuid7(), 'boundary_id' => $boundary['boundary_id'], 'business_id' => $business, 'branch_id' => null, 'status' => 'active', 'revision' => 1], $f['user']->id);
        $scope = (array) $db->table('data_use_scopes')->where('scope_id', $f['scope'])->first();
        $scope['scope_id'] = (string) Str::uuid7();
        $scope['boundary_id'] = $boundary['boundary_id'];
        $scope['selection'] = json_decode($scope['selection'], true, flags: JSON_THROW_ON_ERROR);
        $hash = $db->transaction(function () use ($db, $scope) {
            $db->statement('SET LOCAL ROLE builderad_scope_executor');

            return $db->scalar("SELECT encode(builderad_private.data_scope_digest(?::uuid,?::uuid,?,?,?,?::jsonb),'hex')", [$scope['boundary_id'], $scope['source_id'], $scope['resource'], $scope['operation'], $scope['purpose'], json_encode($scope['selection'])]);
        });
        $scope['scope_digest'] = '\\x'.$hash;
        Fixture::insert('data_use_scopes', $scope, $f['user']->id);
        $use = Fixture::root($scope['scope_id'], $f['user']->id, 'data_use.retained_read', kind: 'data_use');
        Fixture::insert('business_data_use_bindings', ['binding_id' => (string) Str::uuid7(), 'business_id' => $business, 'data_use_scope_id' => $scope['scope_id'], 'status' => 'active', 'revision' => 1, 'qualification_ref' => $use->qualification_id], $f['user']->id);
        $second = $f;
        $second['a'] = $a;
        $second['body']['command_id'] = (string) Str::uuid();
        $second['body']['authority_selection']['requirements'][0]['leaf_grant_id'] = $businessGrant->id;
        $second['body']['authority_selection']['requirements'][1]['leaf_grant_id'] = $use->id;
        $this->read($f)->assertOk();
        $this->read($second)->assertOk()->assertJsonPath('data.materials.0.data_use_scope_id', $scope['scope_id']);
        // Business B cannot borrow A's independently qualified scope even for identical source bytes.
        $borrowed = $second['body'];
        $borrowed['command_id'] = (string) Str::uuid();
        $borrowed['authority_selection']['requirements'][1]['leaf_grant_id'] = $f['use']->id;
        $this->read($second, $borrowed)->assertForbidden();
        Fixture::revokeQualification($f['use']->qualification_id, $f['user']->id);
        $this->read($f)->assertForbidden();
        $this->read($second)->assertOk();
    }

    public function test_processing_needs_its_own_current_operation_scope_and_cannot_replace_display_or_infer_draft_rights(): void
    {
        $f = $this->original('process');
        $this->read($f)->assertOk()->assertJsonPath('data.operation', 'process');
        $body = $f['body'];
        $body['operation'] = 'retained_read';
        $this->read($f, $body)->assertForbidden();
        $body = $f['body'];
        $body['authority_selection']['requirements'][0]['leaf_grant_id'] = $f['a']['grant_refs']['business.context.read'];
        $this->read($f, $body)->assertForbidden();
        $this->assertSame(0, DB::table('provider_observation_entries')->count(), 'No signed Workspace selection or public material helper means no raw payload access.');
        $body = $f['body'];
        $body['authority_selection']['requirements'][] = $body['authority_selection']['requirements'][1];
        $this->read($f, $body)->assertForbidden();
        $body = $f['body'];
        $body['unexpected_sql'] = 'SELECT observation';
        $this->read($f, $body)->assertUnprocessable();
    }

    public function test_late_receipt_wait_expiry_denies_every_field_and_rolls_back_the_read_receipt(): void
    {
        $this->materialWait('receipt_expiry');
    }

    public function test_reader_queued_behind_resource_withdrawal_requalifies_after_the_observed_wait(): void
    {
        $this->materialWait('boundary_withdrawal');
    }

    public function test_source_surrender_rechecks_fresh_authentication_after_the_last_receipt_wait(): void
    {
        $this->materialWait('source_freshness');
    }

    private function materialWait(string $mode): void
    {
        $f = $this->original();
        $db = DB::connection('pgsql_migrations');
        $run = 'material-use-'.Str::uuid();
        $release = storage_path('framework/testing/'.$run.'.release');
        $path = storage_path('framework/testing/'.$run.'.json');
        $request = Request::create('/api/businesses');
        $request->setLaravelSession(app('session')->driver());
        $body = $f['body'];
        $input = ['run' => $run, 'actor' => $f['user']->id, 'business' => $f['a']['business_public_id'], 'session' => SessionAuthority::sessionKey($request),
            'command' => $body['command_id'], 'operation' => 'material', 'material_operation' => $body['operation'], 'refs' => $body['material_refs'],
            'purpose' => $body['purpose'], 'recipient' => $body['recipient_ref'], 'selection' => $body['authority_selection'], 'release' => $release];
        if ($mode === 'source_freshness') {
            $input = array_merge($input, ['operation' => 'resource', 'kind' => 'source', 'target' => $f['source'], 'effect' => 'revoke', 'expected' => 1, 'payload' => ['grant_id' => $f['native']],
                'selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'native_source', 'leaf_grant_id' => $f['native']]]]]);
        }
        file_put_contents($path, json_encode($input, JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $path], base_path());
        $worker->setTimeout(45);
        $evidence = ['mode' => $mode, 'run' => $run, 'expiry' => $f['until']];
        try {
            $worker->start();
            $this->waitFor(fn () => str_contains($worker->getOutput(), 'READY_PID='), $worker);
            if ($mode === 'receipt_expiry') {
                $f['until'] = Fixture::expireQualificationSoon($f['use']->qualification_id, $f['user']->id);
                $evidence['expiry'] = $f['until'];
            }
            if ($mode === 'source_freshness') {
                $db->table('security_sessions')->where('session_key_hash', $input['session'])->update(['authenticated_at' => DB::raw("date_trunc('second',clock_timestamp())-interval '15 minutes'+interval '2 seconds'")]);
                $f['until'] = $db->scalar("SELECT (authenticated_at+interval '15 minutes')::text FROM security_sessions WHERE session_key_hash=?", [$input['session']]);
                $evidence['expiry'] = $f['until'];
            }
            $db->beginTransaction();
            $blocker = (int) $db->scalar('SELECT pg_backend_pid()');
            if (in_array($mode, ['receipt_expiry', 'source_freshness'], true)) {
                $db->statement('LOCK TABLE business_command_receipts IN SHARE MODE');
                $this->assertTrue((bool) $db->scalar('SELECT clock_timestamp()<?::timestamptz', [$f['until']]), 'Authority must be current before the receipt wait.');
            } else {
                $db->select('SELECT fence_id FROM resource_right_fences WHERE fence_id=?::uuid FOR UPDATE', [$f['boundary']['fence_id']]);
            }
            file_put_contents($release, 'ready');
            $evidence['wait'] = $this->waitFor(function () use ($run, $blocker) {
                $rows = DB::select('SELECT pid,backend_xid::text AS xid,wait_event_type,wait_event,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE application_name=?', [$run]);
                foreach ($rows as $row) {
                    if ($row->wait_event_type === 'Lock' && in_array($blocker, json_decode(str_replace(['{', '}'], ['[', ']'], $row->blockers), true), true)) {
                        return (array) $row;
                    }
                }

                return false;
            }, $worker);
            if (in_array($mode, ['receipt_expiry', 'source_freshness'], true)) {
                $evidence['receipt_wait'] = DB::select('SELECT relation::regclass::text AS relation,mode,granted FROM pg_locks WHERE pid=? AND relation=\'business_command_receipts\'::regclass AND NOT granted', [$evidence['wait']['pid']]);
                $this->assertCount(1, $evidence['receipt_wait'], 'The last receipt insert, not an earlier authority lock, must be waiting.');
                $this->waitFor(fn () => (bool) $db->scalar('SELECT clock_timestamp()>=?::timestamptz', [$f['until']]), $worker, 25);
            } else {
                Fixture::revokeQualification($f['use']->qualification_id, $f['user']->id);
            }
            $db->commit();
            $worker->wait();
            $this->assertSame(2, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
            $this->assertStringNotContainsString('COMMITTED=', $worker->getOutput());
            $this->assertSame(0, $db->table('business_command_receipts')->where('command_id', $body['command_id'])->count());
            if ($mode === 'source_freshness') {
                $this->assertSame('active', $db->table('security_action_grants')->where('id', $f['native'])->value('status'));
                $this->assertSame(0, $db->table('resource_right_fences')->where('source_id', $f['source'])->value('rights_epoch'));
            }
        } finally {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $evidence['worker'] = ['exit' => $worker->getExitCode(), 'stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput()];
            file_put_contents(storage_path('framework/testing/'.$run.'-evidence.json'), json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
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
