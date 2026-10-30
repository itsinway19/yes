<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\BusinessAuthorityFixture as Fixture;
use Tests\Support\NativeMaterialFixture;
use Tests\Support\RegisteredProducerFixture;
use Tests\TestCase;

final class BusinessMaterialPublishTest extends TestCase
{
    use ApplicationContractAssertions;
    use NativeMaterialFixture;

    private bool $producerInstalled = false;

    protected function tearDown(): void
    {
        try {
            if ($this->producerInstalled) {
                RegisteredProducerFixture::uninstall();
            }
        } finally {
            parent::tearDown();
        }
    }

    /** Synthetic producer program: every actual F09 read result is captured; clients cannot submit this record. */
    private function prepared(int $count = 1): array
    {
        $f = $this->original('process', ['spend'], $count);
        RegisteredProducerFixture::install();
        $this->producerInstalled = true;

        return $this->captureOutput($f, $f['body']);
    }

    /** Fixed test producer captures every actual requested read, including a saved output or a mixed batch. */
    private function captureOutput(array $f, array $readBody): array
    {
        $db = DB::connection('pgsql_migrations');
        $read = $this->postJson('/api/businesses/'.$f['a']['business_public_id'].'/materials/read', $readBody)->assertOk()->json('data');
        $actualInputs = array_map(fn ($material) => ['input' => $material['ref'], 'data_use_scope_id' => $material['data_use_scope_id'], 'policy_id' => 'test.synthetic.qualified_root', 'policy_version' => 1, 'read_command_id' => $readBody['command_id']], $read['materials']);
        $payload = ['summary' => 'Synthetic result; not an advertiser recommendation', 'raw_inputs' => 'Never exposed through summary entitlement'];
        $version = $db->scalar("SELECT encode(sha256(convert_to(?::jsonb::text,'UTF8')),'hex')", [json_encode($payload)]);
        $ref = ['namespace' => 'test.fixture_report', 'key' => (string) Str::uuid7(), 'version' => $version];
        $outSource = (string) Str::uuid7();
        $workspace = $db->table('workspaces')->first();
        $db->table('source_registry')->insert(['id' => $outSource, 'workspace_id' => $workspace->id, 'family' => 'test', 'namespace' => 'test.fixture_report', 'declared_external_id' => $ref['key'], 'label' => 'SYNTHETIC OUTPUT CUSTODY', 'version' => 1, 'status' => 'UNVERIFIED']);
        Fixture::insert('resource_right_fences', ['fence_id' => (string) Str::uuid7(), 'kind' => 'source', 'source_id' => $outSource, 'boundary_id' => null, 'rights_epoch' => 0, 'adoption_state' => 'v2_adopted'], $f['user']->id);
        $selector = ['native_namespaces' => [$ref['namespace']], 'native_keys' => [$ref['key']], 'field_keys' => ['summary'], 'partition_ref' => null];
        $scope = (string) Str::uuid7();
        $digest = $db->transaction(function () use ($db, $f, $outSource, $selector) {
            $db->statement('SET LOCAL ROLE builderad_scope_executor');

            return $db->scalar("SELECT encode(builderad_private.data_scope_digest(?::uuid,?::uuid,'fixture_report','display','market_research',?::jsonb),'hex')", [$f['boundary']['boundary_id'], $outSource, json_encode($selector)]);
        });
        Fixture::insert('data_use_scopes', ['scope_id' => $scope, 'boundary_id' => $f['boundary']['boundary_id'], 'source_id' => $outSource, 'resource' => 'fixture_report', 'selection' => $selector, 'selection_schema_version' => 1, 'operation' => 'display', 'purpose' => 'market_research', 'scope_digest' => '\\x'.$digest, 'status' => 'active', 'revision' => 1], $f['user']->id);
        $outputGrant = Fixture::root($scope, $f['user']->id, 'data_use.display', kind: 'data_use');
        Fixture::insert('business_data_use_bindings', ['binding_id' => (string) Str::uuid7(), 'business_id' => $f['business'], 'data_use_scope_id' => $scope, 'status' => 'active', 'revision' => 1, 'qualification_ref' => $outputGrant->qualification_id], $f['user']->id);
        $descriptor = ['ref' => $ref, 'material_digest' => $version, 'resolver_contract_version' => 1, 'producer_receipt_ref' => 'test:trusted-producer:'.$ref['key'],
            'business_public_id' => $f['a']['business_public_id'], 'processor_user_id' => $f['user']->id, 'purpose' => 'market_research', 'processing_operation' => 'process', 'input_reads' => $actualInputs,
            'output_scope_id' => $scope, 'coverage' => 'PARTIAL', 'provenance' => ['basis' => 'SYNTHETIC_TEST_ONLY', 'captured_input_coverage' => $read['materials'][0]['coverage']]];
        $db->table('builderad_private.test_registered_producer')->insert(['artifact_key' => $ref['key'], 'descriptor' => json_encode($descriptor), 'payload' => json_encode($payload)]);
        $selection = $readBody['authority_selection'];
        $selection['requirements'][] = ['requirement_kind' => 'data_use', 'leaf_grant_id' => $outputGrant->id];
        $body = ['command_id' => (string) Str::uuid(), 'artifact_ref' => $ref, 'dependencies' => array_map(fn ($r) => array_diff_key($r, ['read_command_id' => true]), $actualInputs), 'purpose' => 'market_research', 'recipient_ref' => $f['user']->public_id, 'authority_selection' => $selection];

        return array_replace($f, compact('ref', 'outputGrant', 'descriptor', 'selection'), ['output_scope' => $scope, 'publish_body' => $body]);
    }

    /** Synthetic current processing licence for an existing saved output; never inferred from display. */
    private function processOutput(array $f, array $requirements, bool $mixed = false): array
    {
        $db = DB::connection('pgsql_migrations');
        $scope = (array) $db->table('data_use_scopes')->where('scope_id', $f['output_scope'])->first();
        $scope['scope_id'] = (string) Str::uuid7();
        $scope['operation'] = 'process';
        $scope['selection'] = json_decode($scope['selection'], true, flags: JSON_THROW_ON_ERROR);
        $digest = $db->transaction(function () use ($db, $scope) {
            $db->statement('SET LOCAL ROLE builderad_scope_executor');

            return $db->scalar("SELECT encode(builderad_private.data_scope_digest(?::uuid,?::uuid,?,'process',?,?::jsonb),'hex')", [$scope['boundary_id'], $scope['source_id'], $scope['resource'], $scope['purpose'], json_encode($scope['selection'])]);
        });
        $scope['scope_digest'] = '\\x'.$digest;
        Fixture::insert('data_use_scopes', $scope, $f['user']->id);
        $grant = Fixture::root($scope['scope_id'], $f['user']->id, 'data_use.process', kind: 'data_use');
        Fixture::insert('business_data_use_bindings', ['binding_id' => (string) Str::uuid7(), 'business_id' => $f['business'], 'data_use_scope_id' => $scope['scope_id'], 'status' => 'active', 'revision' => 1, 'qualification_ref' => $grant->qualification_id], $f['user']->id);
        $requirements[] = ['requirement_kind' => 'data_use', 'leaf_grant_id' => $grant->id];

        return ['grant' => $grant, 'body' => ['command_id' => (string) Str::uuid(), 'material_refs' => $mixed ? [$f['ref'], $f['body']['material_refs'][0]] : [$f['ref']], 'operation' => 'process',
            'purpose' => 'market_research', 'recipient_ref' => $f['user']->public_id,
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => $requirements]]];
    }

    public function test_mixed_inputs_form_a_sealed_two_generation_chain_with_current_deep_processing_and_independent_display(): void
    {
        $d1 = $this->prepared();
        $this->publish($d1)->assertOk();
        $processingD1 = $this->processOutput($d1, $d1['body']['authority_selection']['requirements'], true);
        $d2 = $this->captureOutput($d1, $processingD1['body']);
        $this->assertCount(2, $d2['descriptor']['input_reads']);
        $url = '/api/businesses/'.$d1['a']['business_public_id'].'/materials/read';
        $omitted = $d2['publish_body'];
        array_pop($omitted['dependencies']);
        $this->publish($d2, $omitted)->assertForbidden();
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('material_lineage_manifests')->count());
        $published = $this->publish($d2)->assertOk();
        $this->assertSame(2, DB::connection('pgsql_migrations')->table('material_lineage_manifests')->count());
        $this->assertSame(3, DB::connection('pgsql_migrations')->table('material_use_dependencies')->count());
        $this->assertSame($published->json(), $this->publish($d2)->assertOk()->json());

        $processingD2 = $this->processOutput($d2, $processingD1['body']['authority_selection']['requirements']);
        $read = $this->postJson($url, $processingD2['body'])->assertOk();
        $this->assertApplicationContract('BusinessMaterialReadResult', $read->json());
        $read->assertJsonCount(1, 'data.materials');
        foreach ($d2['ref'] as $key => $value) {
            $read->assertJsonPath('data.materials.0.ref.'.$key, $value);
        }
        $this->assertStringNotContainsString('raw_inputs', $read->getContent());
        $this->assertStringNotContainsString('currency', $read->getContent(), 'Transitive processing rights do not release unrequested original fields.');
        $missingDeep = $processingD2['body'];
        $missingDeep['command_id'] = (string) Str::uuid();
        $missingDeep['authority_selection']['requirements'] = array_values(array_filter($missingDeep['authority_selection']['requirements'], fn ($r) => $r['leaf_grant_id'] !== $d1['use']->id));
        $this->postJson($url, $missingDeep)->assertForbidden();
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('business_command_receipts')->where('command_id', $missingDeep['command_id'])->count());

        Fixture::revokeQualification($d1['use']->qualification_id, $d1['user']->id);
        $this->postJson($url, $processingD2['body'])->assertForbidden();
        $viewer = User::factory()->create();
        $business = Fixture::root($d1['business'], $viewer->id, 'business.data.use');
        $display = Fixture::root($d2['output_scope'], $viewer->id, 'data_use.display', kind: 'data_use');
        $this->actingAs($viewer);
        $body = ['command_id' => (string) Str::uuid(), 'material_refs' => [$d2['ref']], 'operation' => 'display', 'purpose' => 'market_research', 'recipient_ref' => $viewer->public_id,
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => $business->id], ['requirement_kind' => 'data_use', 'leaf_grant_id' => $display->id]]]];
        $this->postJson($url, $body)->assertOk()->assertJsonCount(1, 'data.materials');
        DB::connection('pgsql_migrations')->table('builderad_private.test_registered_producer')->where('artifact_key', $d1['ref']['key'])->update(['policy_current' => false]);
        $this->postJson($url, $body)->assertForbidden();
    }

    public function test_saved_output_processing_requalifies_the_deep_input_right_after_an_observed_resource_wait(): void
    {
        $d1 = $this->prepared();
        $this->publish($d1)->assertOk();
        $processingD1 = $this->processOutput($d1, $d1['body']['authority_selection']['requirements']);
        $d2 = $this->captureOutput($d1, $processingD1['body']);
        $this->publish($d2)->assertOk();
        $processingD2 = $this->processOutput($d2, $processingD1['body']['authority_selection']['requirements']);
        $body = $processingD2['body'];
        $db = DB::connection('pgsql_migrations');
        $run = 'derived-deep-'.Str::uuid();
        $path = storage_path('framework/testing/'.$run.'.json');
        $release = $path.'.release';
        $request = Request::create('/api/businesses');
        $request->setLaravelSession(app('session')->driver());
        $input = ['run' => $run, 'actor' => $d1['user']->id, 'business' => $d1['a']['business_public_id'], 'session' => SessionAuthority::sessionKey($request),
            'command' => $body['command_id'], 'operation' => 'material', 'material_operation' => 'process', 'refs' => $body['material_refs'],
            'purpose' => $body['purpose'], 'recipient' => $body['recipient_ref'], 'selection' => $body['authority_selection'], 'release' => $release];
        file_put_contents($path, json_encode($input, JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $path], base_path());
        $worker->setTimeout(45);
        $evidence = ['run' => $run, 'deep_input_scope' => $d1['scope'], 'output' => $d2['ref']];
        $wait = function (callable $condition) use ($worker) {
            $until = microtime(true) + 15;
            do {
                if ($result = $condition()) {
                    return $result;
                }
                if (! $worker->isRunning()) {
                    throw new \RuntimeException($worker->getOutput().$worker->getErrorOutput());
                }
                usleep(10000);
            } while (microtime(true) < $until);
            throw new \RuntimeException('Required deep-resource wait was not observed: '.$worker->getOutput().$worker->getErrorOutput());
        };
        try {
            $worker->start();
            $wait(fn () => str_contains($worker->getOutput(), 'READY_PID='));
            $db->beginTransaction();
            $blocker = (int) $db->scalar('SELECT pg_backend_pid()');
            $db->select('SELECT fence_id FROM resource_right_fences WHERE fence_id=?::uuid FOR UPDATE', [$d1['boundary']['fence_id']]);
            file_put_contents($release, 'ready');
            $evidence['wait'] = $wait(function () use ($run, $blocker) {
                $rows = DB::select('SELECT pid,backend_xid::text AS xid,wait_event_type,wait_event,pg_blocking_pids(pid)::text blockers FROM pg_stat_activity WHERE application_name=? AND ?=ANY(pg_blocking_pids(pid))', [$run, $blocker]);

                return $rows ? (array) $rows[0] : false;
            });
            Fixture::revokeQualification($d1['use']->qualification_id, $d1['user']->id);
            $db->commit();
            $worker->wait();
            $this->assertSame(2, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
            $this->assertStringNotContainsString('COMMITTED=', $worker->getOutput());
            $this->assertSame(0, $db->table('business_command_receipts')->where('command_id', $body['command_id'])->count());
            $this->assertSame(2, $db->table('material_lineage_manifests')->count(), 'Withdrawal denies new use without deleting existing licensed artifacts.');
        } finally {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $evidence['worker'] = ['stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput(), 'exit' => $worker->getExitCode()];
            file_put_contents(storage_path('framework/testing/'.$run.'-evidence.json'), json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            foreach ([$path, $release] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    private function publish(array $f, ?array $body = null)
    {
        return $this->postJson('/api/businesses/'.$f['a']['business_public_id'].'/materials/publish', $body ?? $f['publish_body']);
    }

    public function test_registered_native_capture_is_published_atomically_and_same_command_does_not_duplicate_lineage(): void
    {
        $f = $this->prepared();
        $published = $this->publish($f)->assertOk();
        $this->assertApplicationContract('BusinessMaterialPublishResult', $published->json());
        $this->assertSame($published->json(), $this->publish($f)->assertOk()->json());
        $db = DB::connection('pgsql_migrations');
        $this->assertSame(1, $db->table('material_lineage_manifests')->count());
        $this->assertSame(1, $db->table('material_use_dependencies')->count());
        $this->assertSame($published->json('data.manifest_id'), $db->table('builderad_private.test_registered_producer')->value('publication_manifest_id'));
        $this->assertSame(1, $db->table('business_command_receipts')->where('command_id', $f['publish_body']['command_id'])->count());
    }

    public function test_mismatched_capture_and_owner_failure_cannot_leave_a_manifest_dependency_or_success_receipt(): void
    {
        $f = $this->prepared();
        $db = DB::connection('pgsql_migrations');
        $body = $f['publish_body'];
        $body['dependencies'][0]['input']['version'] = hash('sha256', 'not captured');
        $this->publish($f, $body)->assertForbidden();
        $body = $f['publish_body'];
        $body['dependencies'][] = $body['dependencies'][0];
        $this->publish($f, $body)->assertForbidden();
        $db->table('builderad_private.test_registered_producer')->update(['fail_publish' => true]);
        $this->publish($f)->assertConflict();
        $this->assertSame(0, $db->table('material_lineage_manifests')->count());
        $this->assertSame(0, $db->table('material_use_dependencies')->count());
        $this->assertNull($db->table('builderad_private.test_registered_producer')->value('publication_manifest_id'));
        $this->assertSame(0, $db->table('business_command_receipts')->where('command_id', $f['publish_body']['command_id'])->count());
    }

    public function test_current_processing_right_is_requalified_after_capture_before_publication(): void
    {
        $f = $this->prepared();
        Fixture::revokeQualification($f['use']->qualification_id, $f['user']->id);
        $this->publish($f)->assertForbidden();
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('material_lineage_manifests')->count());
    }

    public function test_independent_output_viewer_needs_no_raw_input_grant_and_retains_licensed_display_after_processing_withdrawal(): void
    {
        $f = $this->prepared();
        $this->publish($f)->assertOk();
        $viewer = User::factory()->create();
        $business = Fixture::root($f['business'], $viewer->id, 'business.data.use');
        $display = Fixture::root($f['output_scope'], $viewer->id, 'data_use.display', kind: 'data_use');
        $this->actingAs($viewer);
        $selection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
            ['requirement_kind' => 'business', 'leaf_grant_id' => $business->id], ['requirement_kind' => 'data_use', 'leaf_grant_id' => $display->id]]];
        $body = ['command_id' => (string) Str::uuid(), 'material_refs' => [$f['ref']], 'operation' => 'display', 'purpose' => 'market_research', 'recipient_ref' => $viewer->public_id, 'authority_selection' => $selection];
        $url = '/api/businesses/'.$f['a']['business_public_id'].'/materials/read';
        $read = $this->postJson($url, $body)->assertOk();
        $this->assertApplicationContract('BusinessMaterialReadResult', $read->json());
        $read->assertJsonPath('data.materials.0.fields.summary', 'Synthetic result; not an advertiser recommendation');
        $this->assertStringNotContainsString('raw_inputs', $read->getContent());
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('security_action_grants')->where('subject_user_id', $viewer->id)->whereIn('scope_kind', ['source', 'client_data'])->count());
        $raw = $body;
        $raw['material_refs'] = $f['body']['material_refs'];
        $this->postJson($url, $raw)->assertForbidden();
        Fixture::revokeQualification($f['use']->qualification_id, $f['user']->id);
        $this->postJson($url, $body)->assertOk();
        $body['operation'] = 'reuse';
        $this->postJson($url, $body)->assertForbidden();
        $body['operation'] = 'display';
        Fixture::revokeQualification($display->qualification_id, $viewer->id);
        $this->postJson($url, $body)->assertForbidden();
    }

    public function test_a_real_two_input_capture_cannot_be_published_with_one_input_omitted(): void
    {
        $f = $this->prepared(2);
        $this->assertCount(2, $f['publish_body']['dependencies']);
        $body = $f['publish_body'];
        array_pop($body['dependencies']);
        $this->publish($f, $body)->assertForbidden();
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('material_use_dependencies')->count());
        $this->publish($f)->assertOk();
        $this->assertSame(2, DB::connection('pgsql_migrations')->table('material_use_dependencies')->count());
    }

    public function test_an_unavailable_current_output_policy_never_reuses_a_cached_display_success(): void
    {
        $f = $this->prepared();
        $this->publish($f)->assertOk();
        $body = ['command_id' => (string) Str::uuid(), 'material_refs' => [$f['ref']], 'operation' => 'display', 'purpose' => 'market_research', 'recipient_ref' => $f['user']->public_id,
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                $f['body']['authority_selection']['requirements'][0], ['requirement_kind' => 'data_use', 'leaf_grant_id' => $f['outputGrant']->id]]]];
        $url = '/api/businesses/'.$f['a']['business_public_id'].'/materials/read';
        $this->postJson($url, $body)->assertOk();
        DB::connection('pgsql_migrations')->table('builderad_private.test_registered_producer')->update(['policy_current' => false]);
        $this->postJson($url, $body)->assertForbidden();
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('business_command_receipts')->where('command_id', $body['command_id'])->count());
    }

    public function test_no_production_producer_or_synthetic_namespace_is_registered_by_the_foundation(): void
    {
        $f = $this->prepared();
        RegisteredProducerFixture::uninstall();
        $this->producerInstalled = false;
        $this->publish($f)->assertForbidden();
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('material_lineage_manifests')->count());
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('material_use_dependencies')->count());
    }

    public function test_expiry_after_the_last_publication_receipt_wait_rolls_back_core_and_owner_records_together(): void
    {
        $f = $this->prepared();
        $db = DB::connection('pgsql_migrations');
        $run = 'publish-final-'.Str::uuid();
        $path = storage_path('framework/testing/'.$run.'.json');
        $release = $path.'.release';
        $request = Request::create('/api/businesses');
        $request->setLaravelSession(app('session')->driver());
        $body = $f['publish_body'];
        $input = ['run' => $run, 'actor' => $f['user']->id, 'business' => $f['a']['business_public_id'], 'session' => SessionAuthority::sessionKey($request), 'command' => $body['command_id'],
            'operation' => 'publish', 'artifact' => $f['ref'], 'dependencies' => $body['dependencies'], 'purpose' => $body['purpose'], 'recipient' => $body['recipient_ref'], 'selection' => $body['authority_selection'], 'release' => $release];
        file_put_contents($path, json_encode($input, JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $path], base_path());
        $worker->setTimeout(45);
        $evidence = ['run' => $run];
        $wait = function (callable $condition) use ($worker) {
            $until = microtime(true) + 15;
            do {
                if ($result = $condition()) {
                    return $result;
                }
                if (! $worker->isRunning()) {
                    throw new \RuntimeException($worker->getOutput().$worker->getErrorOutput());
                }
                usleep(10000);
            } while (microtime(true) < $until);
            throw new \RuntimeException('Required observed publication wait missing: '.$worker->getOutput().$worker->getErrorOutput());
        };
        try {
            $worker->start();
            $wait(fn () => str_contains($worker->getOutput(), 'READY_PID='));
            $expires = Fixture::expireQualificationSoon($f['use']->qualification_id, $f['user']->id);
            $evidence['expires'] = $expires;
            $db->beginTransaction();
            $blocker = (int) $db->scalar('SELECT pg_backend_pid()');
            $db->statement('LOCK TABLE business_command_receipts IN SHARE MODE');
            file_put_contents($release, 'ready');
            $evidence['wait'] = $wait(function () use ($run, $blocker) {
                $rows = DB::select("SELECT a.pid,a.backend_xid::text AS xid,a.wait_event_type,a.wait_event,pg_blocking_pids(a.pid)::text blockers FROM pg_stat_activity a WHERE application_name=? AND ?=ANY(pg_blocking_pids(a.pid)) AND EXISTS(SELECT 1 FROM pg_locks WHERE pid=a.pid AND relation='business_command_receipts'::regclass AND mode='RowExclusiveLock' AND NOT granted)", [$run, $blocker]);

                return $rows ? (array) $rows[0] : false;
            });
            $wait(fn () => (bool) $db->scalar('SELECT clock_timestamp()>=?::timestamptz', [$expires]));
            $db->commit();
            $worker->wait();
            $this->assertSame(2, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
            $this->assertStringNotContainsString('COMMITTED=', $worker->getOutput());
            $this->assertSame(0, $db->table('material_lineage_manifests')->count());
            $this->assertSame(0, $db->table('material_use_dependencies')->count());
            $this->assertNull($db->table('builderad_private.test_registered_producer')->value('publication_manifest_id'));
            $this->assertSame(0, $db->table('business_command_receipts')->where('command_id', $body['command_id'])->count());
        } finally {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            } if ($worker->isRunning()) {
                $worker->stop();
            }
            $evidence['worker'] = ['stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput(), 'exit' => $worker->getExitCode()];
            file_put_contents(storage_path('framework/testing/'.$run.'-evidence.json'), json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            foreach ([$path, $release] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function test_malformed_producer_truth_metadata_is_unavailable_before_any_publication(): void
    {
        $f = $this->prepared();
        $db = DB::connection('pgsql_migrations');
        $descriptor = $f['descriptor'];
        $descriptor['coverage'] = 'CERTAIN_BUSINESS_SUCCESS';
        $db->table('builderad_private.test_registered_producer')->update(['descriptor' => json_encode($descriptor)]);
        $this->publish($f)->assertForbidden();
        $descriptor = $f['descriptor'];
        $descriptor['provenance'] = 'unqualified marketing statement';
        $db->table('builderad_private.test_registered_producer')->update(['descriptor' => json_encode($descriptor)]);
        $this->publish($f)->assertForbidden();
        $this->assertSame(0, $db->table('material_lineage_manifests')->count());
        $this->assertSame(0, $db->table('material_use_dependencies')->count());
    }
}
