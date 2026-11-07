<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\Ads\Provider;
use App\Security\SessionAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\BusinessAuthorityFixture;
use Tests\Support\BusinessNativeMaterialFixture;
use Tests\Support\DisposableDatabaseReset;
use Tests\Support\NativeMaterialFixture;
use Tests\TestCase;

final class DataUseLicenseTest extends TestCase
{
    use ApplicationContractAssertions;
    use BusinessNativeMaterialFixture;
    use NativeMaterialFixture;

    public static function providers(): array
    {
        return array_map(fn ($provider) => [$provider], Provider::cases());
    }

    private function declaration(array $f, ?User $recipient = null): array
    {
        $entry = DB::connection('pgsql_migrations')->table('provider_observation_entries')->sole();

        return ['command_id' => (string) Str::uuid(), 'license_id' => (string) Str::uuid7(), 'effect' => 'issue', 'expected_revision' => 0,
            'declaration' => ['source_id' => $f['source'], 'resource' => '123', 'operation' => 'retained_read', 'purpose' => 'market_research',
                'selection' => ['native_namespaces' => [$entry->namespace], 'native_keys' => [$entry->identity], 'field_keys' => ['spend'], 'partition_ref' => null],
                'recipient_public_id' => ($recipient ?? $f['user'])->public_id, 'valid_until' => now()->addHour()->toIso8601String(),
                'capacity' => 'data_holder', 'holder_name' => 'Synthetic business data holder', 'authority_reference' => 'Explicit synthetic licence, not verified legal ownership',
                'confirmed' => true, 'requires_review' => false],
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => (int) $f['business']['grant_refs']['business.context.read']],
                ['requirement_kind' => 'native_source', 'leaf_grant_id' => (int) $f['custody']['grant_refs']['source.manage']],
            ]]];
    }

    private function command(array $f, array $body)
    {
        return $this->postJson('/api/businesses/'.$f['business']['business_public_id'].'/data-use-licenses', $body);
    }

    /** Business qualification is a separate existing precondition, never created by the licence issuer. */
    private function bind(array $f, array $issued): array
    {
        $manager = BusinessAuthorityFixture::root($f['id'], $f['user']->id, 'business.link.manage');
        $businessUse = BusinessAuthorityFixture::root($f['id'], $f['user']->id, 'business.data.use');
        $selection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
            ['requirement_kind' => 'business', 'leaf_grant_id' => $manager->id],
            ['requirement_kind' => 'client_data', 'leaf_grant_id' => (int) $issued['boundary_grant_id']],
        ]];
        $this->postJson('/api/data-rights/commands', ['command_id' => (string) Str::uuid(), 'target_kind' => 'boundary_subject',
            'target_ref' => $issued['boundary_id'], 'effect' => 'attach_subject', 'payload' => ['business_public_id' => $f['business']['business_public_id'], 'branch_id' => null],
            'expected_revision' => 0, 'authority_selection' => $selection])->assertOk();
        $selection['requirements'][1] = ['requirement_kind' => 'data_use', 'leaf_grant_id' => (int) $issued['use_grant_id']];
        $this->postJson('/api/businesses/'.$f['business']['business_public_id'].'/data-use-bindings', ['command_id' => (string) Str::uuid(),
            'data_use_scope_id' => $issued['license_id'], 'effect' => 'add', 'expected_relation' => 0, 'authority_selection' => $selection])->assertOk();
        $body = $f['body'];
        $body['authority_selection']['requirements'] = [
            ['requirement_kind' => 'business', 'leaf_grant_id' => $businessUse->id],
            ['requirement_kind' => 'data_use', 'leaf_grant_id' => (int) $issued['use_grant_id']],
            ['requirement_kind' => 'native_source', 'leaf_grant_id' => $f['native']],
        ];

        return $body;
    }

    #[DataProvider('providers')]
    public function test_explicit_operating_licence_preserves_independent_business_authority_and_real_material_consumption(Provider $provider): void
    {
        $f = $this->nativeMaterial($provider, licensed: false);
        $owner = DB::connection('pgsql_migrations');
        $before = $owner->table('security_action_grants')->whereIn('scope_kind', ['business', 'source'])->orderBy('id')->get()->toJson();
        $body = $this->declaration($f);
        $response = $this->command($f, $body)->assertOk()->assertJsonPath('data.assurance', 'DECLARED_AUTHORITY')
            ->assertJsonPath('data.license_id', $body['license_id'])->assertJsonPath('data.status', 'active');
        $this->assertApplicationContract('BusinessDataUseLicenseResult', $response->json());
        $issued = $response->json('data');
        $this->assertSame($before, $owner->table('security_action_grants')->whereIn('scope_kind', ['business', 'source'])->orderBy('id')->get()->toJson());
        $this->assertSame(0, $owner->table('business_data_use_bindings')->count());
        $this->assertSame(0, $owner->table('boundary_subjects')->count());
        $this->assertSame($issued, $this->command($f, $body)->assertOk()->json('data'));
        $this->assertSame(1, $owner->table('data_use_scopes')->count());
        $this->assertSame(1, $owner->table('client_data_boundaries')->count());
        $this->postJson('/api/businesses/'.$f['business']['business_public_id'].'/materials/read', $f['body'])->assertForbidden();
        $read = $this->bind($f, $issued);
        $this->postJson('/api/businesses/'.$f['business']['business_public_id'].'/materials/read', $read)->assertOk()
            ->assertJsonPath('data.materials.0.fields.spend.native_value', '12');
        $changed = $body;
        $changed['declaration']['purpose'] = 'other_purpose';
        $this->command($f, $changed)->assertConflict();
    }

    public function test_issuer_can_withdraw_after_source_management_loss_without_reviving_the_original_licence(): void
    {
        $f = $this->nativeMaterial(licensed: false);
        $body = $this->declaration($f);
        $issued = $this->command($f, $body)->assertOk()->json('data');
        $read = $this->bind($f, $issued);
        $manager = (int) $f['custody']['grant_refs']['source.manage'];
        $this->postJson('/api/data-rights/commands', ['command_id' => (string) Str::uuid(), 'target_kind' => 'source', 'target_ref' => $f['source'],
            'effect' => 'revoke', 'payload' => ['grant_id' => (string) $manager], 'expected_revision' => 1,
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'native_source', 'leaf_grant_id' => $manager],
            ]]])->assertOk();
        $withdraw = [...$body, 'command_id' => (string) Str::uuid(), 'effect' => 'withdraw', 'expected_revision' => 1, 'declaration' => (object) []];
        $result = $this->command($f, $withdraw)->assertOk()->assertJsonPath('data.status', 'withdrawn')->json('data');
        $this->assertSame($result, $this->command($f, $withdraw)->assertOk()->json('data'));
        $this->postJson('/api/businesses/'.$f['business']['business_public_id'].'/materials/read', $read)->assertForbidden();
        $this->command($f, $body)->assertForbidden();
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('data_use_scopes')->count());
    }

    public function test_unconfirmed_ambiguous_or_incomplete_declarations_and_missing_independent_paths_issue_nothing(): void
    {
        $f = $this->nativeMaterial(licensed: false);
        $original = $this->declaration($f);
        foreach (['confirmed' => false, 'requires_review' => true, 'capacity' => 'unknown', 'authority_reference' => '', 'valid_until' => '2000-01-01T00:00:00Z', 'operation' => 'train'] as $field => $value) {
            $body = $original;
            $body['declaration'][$field] = $value;
            $this->command($f, $body)->assertUnprocessable();
        }
        foreach ([0, 1] as $missing) {
            $body = $original;
            array_splice($body['authority_selection']['requirements'], $missing, 1);
            $this->command($f, $body)->assertForbidden();
        }
        foreach ([(string) Str::uuid(), '00000000-0000-0000-0000-000000000000'] as $invalidLicense) {
            $body = [...$original, 'license_id' => $invalidLicense];
            $this->command($f, $body)->assertUnprocessable();
        }
        $this->actingAs(User::factory()->create());
        $this->command($f, $original)->assertForbidden();
        $owner = DB::connection('pgsql_migrations');
        $this->assertSame(0, $owner->table('data_use_scopes')->count());
        $this->assertSame(0, $owner->table('client_data_boundaries')->count());
    }

    public function test_licence_cannot_cover_another_business_or_name_an_unqualified_different_recipient(): void
    {
        $f = $this->nativeMaterial(licensed: false);
        $body = $this->declaration($f);
        $foreign = $this->declaration($f, User::factory()->create());
        $this->command($f, $foreign)->assertUnprocessable();
        $issued = $this->command($f, $body)->assertOk()->json('data');
        $b = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Separate licensed customer'])->assertCreated()->json('data');
        $owner = DB::connection('pgsql_migrations');
        $id = $owner->table('businesses')->where('public_id', $b['business_public_id'])->value('id');
        $manager = BusinessAuthorityFixture::root($id, $f['user']->id, 'business.link.manage');
        $this->postJson('/api/data-rights/commands', ['command_id' => (string) Str::uuid(), 'target_kind' => 'boundary_subject',
            'target_ref' => $issued['boundary_id'], 'effect' => 'attach_subject', 'expected_revision' => 0,
            'payload' => ['business_public_id' => $b['business_public_id'], 'branch_id' => null],
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => $manager->id],
                ['requirement_kind' => 'client_data', 'leaf_grant_id' => (int) $issued['boundary_grant_id']],
            ]]])->assertForbidden();
        $this->assertSame(0, $owner->table('boundary_subjects')->count());
        $substitute = $body;
        $substitute['command_id'] = (string) Str::uuid();
        $substitute['license_id'] = (string) Str::uuid7();
        $substitute['declaration']['resource'] = 'other-resource';
        $this->command($f, $substitute)->assertForbidden();
    }

    public function test_licence_expiry_and_own_surrender_do_not_reissue_old_rights(): void
    {
        $f = $this->nativeMaterial(licensed: false);
        $body = $this->declaration($f);
        $body['declaration']['valid_until'] = now()->addSeconds(5)->toIso8601String();
        $issued = $this->command($f, $body)->assertOk()->json('data');
        DB::connection('pgsql_migrations')->scalar('SELECT pg_sleep(greatest(0,extract(epoch FROM (?::timestamptz-clock_timestamp())))+0.01)', [$body['declaration']['valid_until']]);
        $this->getJson('/api/data-rights/use-scopes')->assertOk()->assertJsonCount(0, 'data.scopes');
        $this->assertSame('active', DB::connection('pgsql_migrations')->table('security_action_grants')->where('id', $issued['use_grant_id'])->value('status'));
        $withdrawExpired = [...$body, 'command_id' => (string) Str::uuid(), 'effect' => 'withdraw', 'expected_revision' => 1, 'declaration' => (object) []];
        $this->command($f, $withdrawExpired)->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $second = $this->declaration($f);
        $secondIssued = $this->command($f, $second)->assertOk()->json('data');
        $this->postJson('/api/data-rights/commands', ['command_id' => (string) Str::uuid(), 'target_kind' => 'data_use', 'target_ref' => $secondIssued['license_id'],
            'effect' => 'revoke', 'payload' => ['grant_id' => $secondIssued['use_grant_id']], 'expected_revision' => 1,
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'data_use', 'leaf_grant_id' => (int) $secondIssued['use_grant_id']],
            ]]])->assertOk();
        $this->getJson('/api/data-rights/use-scopes')->assertOk()->assertJsonCount(0, 'data.scopes');
        $replacement = [...$second, 'command_id' => (string) Str::uuid()];
        $this->command($f, $replacement)->assertConflict();
    }

    public static function lockWaits(): array
    {
        return ['source_withdrawal' => [false], 'withdrawal_freshness' => [true]];
    }

    #[DataProvider('lockWaits')]
    public function test_source_authority_is_requalified_after_a_real_resource_lock_wait(bool $freshness): void
    {
        $f = $this->nativeMaterial(licensed: false);
        $body = $this->declaration($f);
        $issued = null;
        if ($freshness) {
            $issued = $this->command($f, $body)->assertOk()->json('data');
            $body = [...$body, 'command_id' => (string) Str::uuid(), 'effect' => 'withdraw', 'expected_revision' => 1, 'declaration' => (object) []];
        }
        $request = Request::create('/');
        $request->setLaravelSession(app('session')->driver());
        $run = 'data-licence-'.Str::uuid();
        $file = storage_path('framework/testing/'.$run.'.json');
        $ready = $file.'.ready';
        $release = $file.'.release';
        file_put_contents($file, json_encode(['actor' => $f['user']->id, 'run' => $run, 'operation' => 'data-use-license', 'business' => $f['business']['business_public_id'],
            'body' => $body, 'session' => SessionAuthority::sessionKey($request), 'ready' => $ready, 'release' => $release], JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $file], base_path());
        $worker->setTimeout(45);
        $owner = DB::connection('pgsql_migrations');
        $evidence = [];
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
            throw new \RuntimeException('Actual licence issuer lock wait was not witnessed.');
        };
        try {
            $this->assertFileDoesNotExist($ready);
            $worker->start();
            $evidence['ready'] = $wait(function () use ($ready, $run) {
                if (! is_file($ready)) {
                    return null;
                }
                $value = json_decode(file_get_contents($ready), true);

                return is_array($value) && ($value['run'] ?? null) === $run && is_int($value['pid'] ?? null) && $value['pid'] > 0 ? $value : null;
            });
            if ($freshness) {
                $owner->table('security_sessions')->where('session_key_hash', SessionAuthority::sessionKey($request))->update(['authenticated_at' => DB::raw("clock_timestamp()-interval '15 minutes'+interval '3 seconds'")]);
                $evidence['fresh_until'] = $owner->scalar("SELECT (authenticated_at+interval '15 minutes')::text FROM security_sessions WHERE session_key_hash=?", [SessionAuthority::sessionKey($request)]);
            }
            $owner->beginTransaction();
            $blocker = (int) $owner->scalar('SELECT pg_backend_pid()');
            if ($freshness) {
                $owner->statement('LOCK TABLE business_command_receipts IN SHARE MODE');
            } else {
                $owner->select('SELECT fence_id FROM resource_right_fences WHERE kind=\'source\' AND source_id=?::uuid FOR UPDATE', [$f['source']]);
            }
            file_put_contents($release, 'go');
            $evidence['wait'] = $wait(fn () => DB::selectOne("SELECT pid,backend_xid,wait_event_type,wait_event,pg_blocking_pids(pid)::text blockers FROM pg_stat_activity WHERE application_name=? AND pid=? AND wait_event_type='Lock' AND ?=ANY(pg_blocking_pids(pid))", [$run, $evidence['ready']['pid'], $blocker]));
            $this->assertSame('Lock', $evidence['wait']->wait_event_type);
            $this->assertNotNull($evidence['wait']->backend_xid);
            if ($freshness) {
                $evidence['receipt_wait'] = DB::select("SELECT relation::regclass::text AS relation,mode,granted FROM pg_locks WHERE pid=? AND relation='business_command_receipts'::regclass AND NOT granted", [$evidence['wait']->pid]);
                $this->assertCount(1, $evidence['receipt_wait']);
                $this->assertTrue((bool) $owner->scalar('SELECT clock_timestamp()<?::timestamptz', [$evidence['fresh_until']]));
                $wait(fn () => (bool) $owner->scalar('SELECT clock_timestamp()>=?::timestamptz', [$evidence['fresh_until']]));
            } else {
                $owner->select('SELECT user_id FROM principal_lifecycles WHERE user_id=? FOR UPDATE NOWAIT', [$f['user']->id]);
                $qualification = $owner->table('security_action_grants')->where('id', $f['custody']['grant_refs']['source.manage'])->value('qualification_id');
                BusinessAuthorityFixture::revokeQualification($qualification, $f['user']->id);
            }
            $owner->commit();
            $worker->wait();
            $this->assertSame(2, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
            $this->assertSame($freshness ? 1 : 0, $owner->table('data_use_scopes')->count());
            $this->assertSame(0, $owner->table('business_command_receipts')->where('command_id', $body['command_id'])->count());
            if ($freshness) {
                $this->assertSame('active', $owner->table('client_data_boundaries')->where('boundary_id', $issued['boundary_id'])->value('status'));
                $this->assertSame('active', $owner->table('security_action_grants')->where('id', $issued['use_grant_id'])->value('status'));
            }
        } finally {
            if ($owner->transactionLevel() > 0) {
                $owner->rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $evidence['worker'] = ['stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput(), 'exit' => $worker->getExitCode()];
            file_put_contents($file.'.evidence', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            foreach ([$file, $ready, $release] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_populated660_and670_upgrades_preserve_history_and_final_runtime_privileges(): void
    {
        $this->usePersistentTestSessions();
        $reset = app(DisposableDatabaseReset::class);
        $reset->clear();
        try {
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => strcmp(basename($path), '0001_01_01_000670_enable_declared_data_licenses.php') < 0));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--path' => $paths, '--realpath' => true, '--force' => true]));
            $f = $this->nativeMaterial(licensed: false);
            $owner = DB::connection('pgsql_migrations');
            $before = [];
            foreach (['businesses', 'source_registry', 'provider_access', 'provider_observation_entries', 'security_action_grants', 'authority_qualification_receipts', 'business_command_receipts'] as $table) {
                $before[$table] = $owner->scalar("SELECT jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text)::text FROM {$table} r");
            }
            $definitions = [];
            foreach (['builderad_private.resolve_material(jsonb)', 'builderad_private.require_scope(text,text,bigint,text,jsonb,text,bigint[],bigint[])'] as $function) {
                $definitions[$function] = $owner->scalar('SELECT pg_get_functiondef(?::regprocedure)', [$function]);
            }
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations',
                '--path' => ['database/migrations/0001_01_01_000670_enable_declared_data_licenses.php'], '--force' => true]));
            foreach ($before as $table => $value) {
                $this->assertSame($value, $owner->scalar("SELECT jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text)::text FROM {$table} r"), $table);
            }
            foreach ($definitions as $function => $value) {
                $this->assertSame($value, $owner->scalar('SELECT pg_get_functiondef(?::regprocedure)', [$function]));
            }
            foreach (['client_data_boundaries', 'boundary_subjects', 'business_data_use_bindings'] as $table) {
                $this->assertFalse((bool) $owner->scalar("SELECT has_table_privilege('builderad_scope_executor',?,'TRIGGER')", [$table]));
            }
            $this->assertFalse((bool) $owner->scalar("SELECT has_schema_privilege('builderad_scope_executor','builderad_private','CREATE')"));
            foreach (['public', 'builderad_authority'] as $role) {
                $this->assertFalse((bool) $owner->scalar("SELECT has_function_privilege(?,'builderad_v2_data_use_license(text,uuid,uuid,uuid,text,bigint,jsonb,jsonb)','EXECUTE')", [$role]));
            }
            $this->assertTrue((bool) $owner->scalar("SELECT has_function_privilege('builderad_app','builderad_v2_data_use_license(text,uuid,uuid,uuid,text,bigint,jsonb,jsonb)','EXECUTE')"));
            $issued = $this->command($f, $this->declaration($f))->assertOk()->json('data');
            foreach (['client_data_boundaries', 'data_use_scopes', 'security_action_grants', 'authority_qualification_receipts', 'business_command_receipts'] as $table) {
                $before[$table] = $owner->scalar("SELECT jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text)::text FROM {$table} r");
            }
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            foreach ($before as $table => $value) {
                $this->assertSame($value, $owner->scalar("SELECT jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text)::text FROM {$table} r"), $table);
            }
            $this->getJson('/api/businesses/'.$f['business']['business_public_id'].'/data-use-journey?access='.$f['business']['grant_refs']['business.context.read'])
                ->assertOk()->assertJsonPath('data.licenses.0.license_id', $issued['license_id'])->assertJsonPath('data.licenses.0.status', 'active');
            foreach (['public', 'builderad_app', 'builderad_authority'] as $role) {
                $this->assertFalse((bool) $owner->scalar("SELECT has_function_privilege(?, 'builderad_private.verify_scope_path(text,text,bigint,text,jsonb,text,bigint[],bigint[],boolean)', 'EXECUTE')", [$role]));
            }
            foreach (['public.security_action_grants', 'builderad_private.invocations'] as $table) {
                $this->assertFalse((bool) $owner->scalar("SELECT has_table_privilege('builderad_scope_executor',?,'TRIGGER')", [$table]));
            }
            $this->assertFalse((bool) $owner->scalar("SELECT has_schema_privilege('builderad_scope_executor','builderad_private','CREATE')"));
            try {
                DB::table('data_use_scopes')->get();
                $this->fail('Direct app data-use read accepted.');
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->getCode());
            }
        } finally {
            DB::disconnect('pgsql');
            $reset->install();
        }
    }

    public function test670_rejects_an_unsealed_baseline_before_installing_any_issuer_or_trigger_privilege(): void
    {
        $reset = app(DisposableDatabaseReset::class);
        $reset->clear();
        try {
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => strcmp(basename($path), '0001_01_01_000670_enable_declared_data_licenses.php') < 0));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--path' => $paths, '--realpath' => true, '--force' => true]));
            $owner = DB::connection('pgsql_migrations');
            $owner->unprepared('CREATE OR REPLACE FUNCTION public.builderad_source_registration_available(p_workspace bigint,p_source uuid,p_resource text,p_operation text,p_purpose text) RETURNS boolean LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=public,pg_temp AS $$ SELECT false $$');
            try {
                Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]);
                $this->fail('An unsealed licence baseline was accepted.');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->getCode());
                $this->assertStringContainsString('DATA_LICENSE_BASELINE_REQUIRED', $error->getMessage());
            }
            $this->assertNull($owner->scalar("SELECT to_regprocedure('builderad_v2_data_use_license(text,uuid,uuid,uuid,text,bigint,jsonb,jsonb)')"));
            $this->assertFalse((bool) $owner->scalar("SELECT has_table_privilege('builderad_scope_executor','client_data_boundaries','TRIGGER')"));
            $this->assertFalse($owner->table('migrations')->where('migration', '0001_01_01_000670_enable_declared_data_licenses')->exists());
        } finally {
            DB::disconnect('pgsql');
            $reset->install();
        }
    }

    public function test_shared_workspace_licence_preserves_qualified_agency_realm_for_binding_and_actual_read(): void
    {
        $original = $this->original(adoptViaCommand: true);
        $owner = DB::connection('pgsql_migrations');
        $workspace = $owner->table('workspaces')->sole();
        $directManager = BusinessAuthorityFixture::root($original['business'], $original['user']->id, 'business.link.manage');
        $direct = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
            'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $directManager->id]]];
        $link = ['command_id' => (string) Str::uuid(), 'workspace_public_id' => $workspace->public_id, 'kind' => 'agency', 'effect' => 'add',
            'expected_revision' => 0, 'authority_selection' => $direct];
        $this->postJson('/api/businesses/'.$original['a']['business_public_id'].'/workspace-links', $link)->assertOk();
        // Fixture supplies only the previously admitted agency relationship and context authority.
        $root = BusinessAuthorityFixture::root($original['business'], $original['user']->id, 'business.context.read');
        $agencyRead = BusinessAuthorityFixture::insert('security_action_grants', [...(array) $root,
            'id' => (int) $owner->scalar("SELECT nextval('security_action_grants_id_seq')"),
            'delegation_realm_kind' => 'agency', 'delegation_workspace_id' => $workspace->id], $original['user']->id)->id;
        $base = '/api/businesses/'.$original['a']['business_public_id'];
        $agencyContext = ['realm_kind' => 'agency', 'realm_workspace_public_id' => $workspace->public_id,
            'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $agencyRead]]];
        $mandate = ['command_id' => (string) Str::uuid(), 'confirmed' => true, 'capacity' => 'client_mandate',
            'authority_reference' => 'Synthetic client mandate within the existing relationship', 'valid_until' => now()->addHour()->toIso8601String(),
            'previous_grant' => '0', 'authority_selection' => $agencyContext];
        $directContext = $direct;
        $directContext['requirements'][0]['leaf_grant_id'] = (int) $original['a']['grant_refs']['business.context.read'];
        $this->postJson($base.'/operating-mandate', [...$mandate, 'command_id' => (string) Str::uuid(),
            'capacity' => 'responsible_business', 'authority_selection' => $directContext])->assertOk();
        $this->getJson($base.'/data-use-journey?access='.$agencyRead)->assertOk()
            ->assertJsonPath('data.operating_mandate.previous_grant', '0');
        $this->postJson($base.'/operating-mandate', [...$mandate, 'capacity' => 'responsible_business'])->assertForbidden();
        $agency = $this->postJson($base.'/operating-mandate', $mandate)->assertOk()->json('data.grant_refs');
        $limited = $agencyContext;
        $limited['requirements'][0]['leaf_grant_id'] = (int) $agency['business.link.manage'];
        $this->postJson($base.'/workspace-links', [...$link, 'command_id' => (string) Str::uuid(), 'effect' => 'end',
            'expected_revision' => 1, 'authority_selection' => $limited])->assertForbidden();
        $f = ['user' => $original['user'], 'business' => $original['a'], 'id' => $original['business'], 'source' => $original['source'],
            'custody' => ['grant_refs' => ['source.manage' => $original['manager']]]];
        $body = $this->declaration($f);
        $body['declaration']['resource'] = 'account-1';
        $body['declaration']['capacity'] = 'authorized_representative';
        $body['declaration']['holder_name'] = 'Synthetic agency client';
        $body['authority_selection']['realm_kind'] = 'agency';
        $body['authority_selection']['realm_workspace_public_id'] = $workspace->public_id;
        $body['authority_selection']['requirements'][0]['leaf_grant_id'] = $agencyRead;
        $issued = $this->command($f, $body)->assertOk()->json('data');
        $scopes = $this->getJson('/api/data-rights/use-scopes')->assertOk()->json('data.scopes');
        $selected = array_values(array_filter($scopes, fn ($scope) => $scope['scope_id'] === $issued['license_id']));
        $this->assertCount(1, $selected);
        $this->assertSame('agency', $selected[0]['authority_selection']['realm_kind']);
        $selection = $body['authority_selection'];
        $selection['requirements'] = [['requirement_kind' => 'business', 'leaf_grant_id' => (int) $agency['business.link.manage']],
            ['requirement_kind' => 'client_data', 'leaf_grant_id' => (int) $issued['boundary_grant_id']]];
        $this->postJson('/api/data-rights/commands', ['command_id' => (string) Str::uuid(), 'target_kind' => 'boundary_subject', 'target_ref' => $issued['boundary_id'],
            'effect' => 'attach_subject', 'expected_revision' => 0, 'payload' => ['business_public_id' => $original['a']['business_public_id'], 'branch_id' => null], 'authority_selection' => $selection])->assertOk();
        $selection['requirements'][1] = ['requirement_kind' => 'data_use', 'leaf_grant_id' => (int) $issued['use_grant_id']];
        $this->postJson('/api/businesses/'.$original['a']['business_public_id'].'/data-use-bindings', ['command_id' => (string) Str::uuid(), 'data_use_scope_id' => $issued['license_id'],
            'effect' => 'add', 'expected_relation' => 0, 'authority_selection' => $selection])->assertOk();
        $read = $original['body'];
        $read['command_id'] = (string) Str::uuid();
        $selection['requirements'][0]['leaf_grant_id'] = (int) $agency['business.data.use'];
        $selection['requirements'][] = ['requirement_kind' => 'native_source', 'leaf_grant_id' => $original['native']];
        $read['authority_selection'] = $selection;
        $this->postJson('/api/businesses/'.$original['a']['business_public_id'].'/materials/read', $read)->assertOk()->assertJsonPath('data.materials.0.fields.spend.amount', '17');
        $link['command_id'] = (string) Str::uuid();
        $link['effect'] = 'end';
        $link['expected_revision'] = 1;
        $this->postJson('/api/businesses/'.$original['a']['business_public_id'].'/workspace-links', $link)->assertOk();
        $read['command_id'] = (string) Str::uuid();
        $this->postJson('/api/businesses/'.$original['a']['business_public_id'].'/materials/read', $read)->assertForbidden();
        $this->postJson($base.'/workspace-links', [...$link, 'command_id' => (string) Str::uuid(),
            'effect' => 'add', 'expected_revision' => 2])->assertOk()->assertJsonPath('data.versions.link', 3);
        $this->postJson($base.'/materials/read', $read)->assertForbidden();
        $this->getJson($base.'/data-use-journey?access='.$agencyRead)->assertForbidden();
        $replacementRoot = BusinessAuthorityFixture::root($original['business'], $original['user']->id,
            'business.context.read', agencyWorkspace: $workspace->id);
        // Grant timestamps have second precision. Keep a valid fresh qualification
        // while putting its grant at the start of the replacement's same second.
        $replacementStart = $owner->scalar("SELECT date_trunc('second',valid_from)::text FROM business_workspace_links WHERE business_id=? AND workspace_id=? AND kind='agency' AND status='active'",
            [$original['business'], $workspace->id]);
        $replacementRead = BusinessAuthorityFixture::insert('security_action_grants', [...(array) $replacementRoot,
            'id' => (int) $owner->scalar("SELECT nextval('security_action_grants_id_seq')"),
            'created_at' => $replacementStart], $original['user']->id)->id;
        $agencyContext['requirements'][0]['leaf_grant_id'] = $replacementRead;
        $replacement = $this->postJson($base.'/operating-mandate', [...$mandate, 'command_id' => (string) Str::uuid(),
            'previous_grant' => $agency['business.data.use'], 'authority_selection' => $agencyContext])->assertOk()->json('data.grant_refs');
        $read['authority_selection']['requirements'][0]['leaf_grant_id'] = (int) $replacement['business.data.use'];
        $this->postJson($base.'/materials/read', $read)->assertForbidden();
    }

    public function test_historical_non_delegable_scope_is_not_reinterpreted_as_a_named_licence(): void
    {
        $legacy = $this->original(adoptViaCommand: true);
        $actor = $legacy['user'];
        $recipient = User::factory()->create();
        $base = '/api/businesses/'.$legacy['a']['business_public_id'];
        $parent = $legacy['businessGrant']->id;
        $selection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
            ['requirement_kind' => 'business', 'leaf_grant_id' => $parent],
        ]];
        $this->postJson($base.'/grants', ['command_id' => (string) Str::uuid(), 'effect' => 'issue',
            'grantee_public_id' => $recipient->public_id, 'action' => 'business.data.use', 'parent_grant_id' => $parent,
            'grant_id' => null, 'expected_grant' => 0, 'expires_at' => now()->addHour()->toIso8601String(), 'delegable' => false,
            'realm_workspace_public_id' => null, 'authority_selection' => $selection])->assertOk();
        $nonDelegable = BusinessAuthorityFixture::root($legacy['scope'], $actor->id, 'data_use.retained_read', false, 'data_use');
        $this->assertFalse($nonDelegable->delegable);
        $selection['requirements'][] = ['requirement_kind' => 'client_data', 'leaf_grant_id' => $legacy['boundary']['grant_id']];
        $db = DB::connection('pgsql_migrations');
        $before = $db->table('security_action_grants')->where('data_use_scope_id', $legacy['scope'])->orderBy('id')->get()->all();
        $this->postJson($base.'/data-use-beneficiaries', ['command_id' => (string) Str::uuid(), 'license_id' => $legacy['scope'],
            'effect' => 'assign', 'recipient_public_id' => $recipient->public_id, 'valid_until' => now()->addMinutes(15)->toIso8601String(),
            'expected_revision' => 0, 'authority_selection' => $selection])->assertForbidden();
        $this->assertEquals($before, $db->table('security_action_grants')->where('data_use_scope_id', $legacy['scope'])->orderBy('id')->get()->all());
    }
}
