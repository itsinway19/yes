<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\Ads\NativeAmount;
use App\Providers\Ads\Provider;
use App\Security\SessionAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\BusinessAuthorityFixture;
use Tests\Support\BusinessNativeMaterialFixture;
use Tests\Support\DisposableDatabaseReset;
use Tests\TestCase;

final class ExpenditureSummaryTest extends TestCase
{
    use BusinessNativeMaterialFixture;

    public static function providers(): array
    {
        return array_map(fn ($provider) => [$provider], Provider::cases());
    }

    private function assertExactReference(array $expected, array $actual): void
    {
        // JSON objects have no key order; retain exact keys, values and types.
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }

    public function test700_preserves_populated_history_and_rejects_unsealed_composition_without_upgrading_display_consent(): void
    {
        $this->usePersistentTestSessions();
        $reset = app(DisposableDatabaseReset::class);
        $reset->clear();
        try {
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($file) => strcmp(basename($file), '0001_01_01_000700_register_bounded_expenditure_summary.php') < 0));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--path' => $paths, '--realpath' => true, '--force' => true]));
            $f = $this->nativeMaterial(operation: 'display');
            $base = '/api/businesses/'.$f['business']['business_public_id'];
            $this->postJson($base.'/materials/read', $f['body'])->assertOk();
            $owner = DB::connection('pgsql_migrations');
            $before = [];
            foreach (['businesses', 'source_registry', 'provider_observation_entries', 'security_action_grants', 'authority_qualification_receipts', 'client_data_boundaries', 'data_use_scopes', 'business_command_receipts'] as $table) {
                $before[$table] = $owner->scalar("SELECT jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text)::text FROM {$table} r");
            }
            $signature = 'builderad_private.registered_producer(jsonb)';
            $definition = $owner->scalar('SELECT pg_get_functiondef(?::regprocedure)', [$signature]);
            $replace = function (string $sql) use ($owner): void {
                $owner->statement('GRANT CREATE ON SCHEMA builderad_private TO builderad_scope_executor');
                $owner->transaction(function () use ($owner, $sql): void {
                    $owner->statement('SET LOCAL ROLE builderad_scope_executor');
                    $owner->unprepared($sql);
                });
                $owner->statement('REVOKE CREATE ON SCHEMA builderad_private FROM builderad_scope_executor');
            };
            $replace(str_replace('no registered canonical producer', 'unsealed synthetic producer', $definition));
            try {
                Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]);
                $this->fail('Unsealed producer composition was accepted.');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->getCode());
                $this->assertStringContainsString('EXPENDITURE_SUMMARY_BASELINE_REQUIRED', $error->getMessage());
            }
            $replace($definition);
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            foreach ($before as $table => $rows) {
                $this->assertSame($rows, $owner->scalar("SELECT jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text)::text FROM {$table} r"), $table);
            }
            foreach (['business.expenditure_summary', 'market.business_finding', 'market.knowledge'] as $namespace) {
                $this->assertStringContainsString($namespace, $owner->scalar('SELECT pg_get_functiondef(?::regprocedure)', [$signature]));
            }
            foreach (['builderad_app', 'builderad_authority', 'public'] as $role) {
                $this->assertFalse((bool) $owner->scalar("SELECT has_table_privilege(?,'business_expenditure_summaries','SELECT')", [$role]));
                $this->assertFalse((bool) $owner->scalar("SELECT has_function_privilege(?,'builderad_private.business_source_basis_current(bigint,bigint)','EXECUTE')", [$role]));
            }
            $this->assertFalse((bool) $owner->scalar("SELECT has_function_privilege('builderad_owner','builderad_private.guard_effect()','EXECUTE')"));
            $this->assertFalse((bool) $owner->scalar("SELECT has_schema_privilege('builderad_scope_executor','builderad_private','CREATE')"));
            $this->assertTrue((bool) $owner->scalar("SELECT relrowsecurity AND relforcerowsecurity FROM pg_class WHERE oid='business_expenditure_summaries'::regclass"));
            $this->postJson($base.'/materials/read', $f['body'])->assertOk();
            $selection = $f['body']['authority_selection'];
            $selection['requirements'][] = ['requirement_kind' => 'client_data', 'leaf_grant_id' => $f['boundary']['grant_id']];
            $selection['requirements'][] = ['requirement_kind' => 'business', 'leaf_grant_id' => $f['manager']->id];
            $this->postJson($base.'/expenditure-summaries', ['command_id' => (string) Str::uuid(), 'summary_id' => (string) Str::uuid7(), 'input_ref' => $f['ref'],
                'input_license_id' => $f['useScope'], 'read_command_id' => $f['body']['command_id'], 'confirmed' => true,
                'valid_until' => now()->addMinutes(10)->toIso8601String(), 'consent' => ['derive' => true, 'deliver_summary' => true, 'retain_payload' => false],
                'authority_selection' => $selection])->assertForbidden();
            $this->assertSame(0, $owner->table('business_expenditure_summaries')->count());
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
        } finally {
            DB::disconnect('pgsql');
            $reset->install();
        }
    }

    #[DataProvider('providers')]
    public function test_declared_summary_reaches_named_recipient_without_source_rights_and_stops_on_withdrawal(Provider $provider): void
    {
        $f = $this->nativeMaterial($provider, licensed: false, records: $provider === Provider::Meta ? 2 : 1);
        $base = '/api/businesses/'.$f['business']['business_public_id'];
        $access = (int) $f['business']['grant_refs']['business.context.read'];
        $realm = static fn (array $requirements) => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => $requirements];
        $req = static fn (string $kind, int|string $id) => ['requirement_kind' => $kind, 'leaf_grant_id' => (int) $id];
        $mandate = $this->postJson($base.'/operating-mandate', ['command_id' => (string) Str::uuid(), 'confirmed' => true,
            'capacity' => 'responsible_business', 'authority_reference' => 'Explicit synthetic responsible Business authority',
            'valid_until' => now()->addHour()->toIso8601String(), 'previous_grant' => '0', 'authority_selection' => $realm([$req('business', $access)])])->assertOk()->json('data.grant_refs');
        $dataset = $this->getJson($base.'/data-use-journey?access='.$access)->assertOk()->json('data.sources.0.datasets.0');
        if ($provider === Provider::Meta) {
            $this->assertCount(2, $dataset['record_metadata']);
            $this->assertNotSame($dataset['record_metadata'][0]['campaign_id'], $dataset['record_metadata'][1]['campaign_id']);
            $f['ref'] = $dataset['material_refs'][1];
            $f['body']['material_refs'] = [$f['ref']];
            $this->assertSame($f['ref'], $dataset['record_metadata'][1]['ref']);
        }
        $license = ['command_id' => (string) Str::uuid(), 'license_id' => (string) Str::uuid7(), 'effect' => 'issue', 'expected_revision' => 0,
            'declaration' => ['source_id' => $f['source'], 'resource' => '123', 'operation' => 'process', 'purpose' => 'advertising_performance', 'selection' => $dataset['selection'],
                'recipient_public_id' => $f['user']->public_id, 'valid_until' => now()->addHour()->toIso8601String(), 'capacity' => 'data_holder', 'holder_name' => 'Synthetic data holder',
                'authority_reference' => 'Explicit synthetic data-holder authority', 'confirmed' => true, 'requires_review' => false],
            'authority_selection' => $realm([$req('business', $access), $req('native_source', $f['custody']['grant_refs']['source.manage'])])];
        $issued = $this->postJson($base.'/data-use-licenses', $license)->assertOk()->json('data');
        $this->postJson('/api/data-rights/commands', ['command_id' => (string) Str::uuid(), 'target_kind' => 'boundary_subject', 'target_ref' => $issued['boundary_id'],
            'effect' => 'attach_subject', 'payload' => ['business_public_id' => $f['business']['business_public_id'], 'branch_id' => null], 'expected_revision' => 0,
            'authority_selection' => $realm([$req('business', $mandate['business.link.manage']), $req('client_data', $issued['boundary_grant_id'])])])->assertOk();
        $this->postJson($base.'/data-use-bindings', ['command_id' => (string) Str::uuid(), 'data_use_scope_id' => $issued['license_id'], 'effect' => 'add', 'expected_relation' => 0,
            'authority_selection' => $realm([$req('business', $mandate['business.link.manage']), $req('data_use', $issued['use_grant_id'])])])->assertOk();
        $read = [...$f['body'], 'purpose' => 'advertising_performance', 'operation' => 'process',
            'authority_selection' => $realm([$req('business', $mandate['business.data.use']), $req('data_use', $issued['use_grant_id']), $req('native_source', $f['native'])])];
        $this->postJson($base.'/materials/read', $read)->assertOk();
        $summaryBody = ['command_id' => (string) Str::uuid(), 'summary_id' => (string) Str::uuid7(), 'input_ref' => $f['ref'], 'input_license_id' => $issued['license_id'],
            'read_command_id' => $read['command_id'], 'confirmed' => true, 'valid_until' => now()->addMinutes(10)->toIso8601String(),
            'consent' => ['derive' => true, 'deliver_summary' => true, 'retain_payload' => false],
            'authority_selection' => $realm([...$read['authority_selection']['requirements'], $req('client_data', $issued['boundary_grant_id']), $req('business', $mandate['business.link.manage'])])];
        $this->postJson($base.'/expenditure-summaries', [...$summaryBody, 'read_command_id' => (string) Str::uuid()])->assertForbidden();
        $summary = $this->postJson($base.'/expenditure-summaries', $summaryBody)->assertOk()->json('data');
        $this->assertExactReference($f['ref'], $summary['dependencies'][0]['input']);
        $this->postJson($base.'/data-use-licenses', [...$license, 'command_id' => (string) Str::uuid(), 'license_id' => $summary['license_id'],
            'effect' => 'withdraw', 'expected_revision' => 1, 'declaration' => (object) []])->assertForbidden();
        $this->assertSame('active', DB::connection('pgsql_migrations')->table('client_data_boundaries')->where('boundary_id', $issued['boundary_id'])->value('status'));
        if ($provider === Provider::Meta) {
            // One original consent can have multiple explicit outputs. Unassigned
            // siblings must neither make this inventory unbounded nor crowd a
            // beneficiary's older, independently qualified output off the page.
            for ($i = 0; $i < 33; $i++) {
                $this->postJson($base.'/expenditure-summaries', [...$summaryBody, 'command_id' => (string) Str::uuid(), 'summary_id' => (string) Str::uuid7()])->assertOk();
            }
            $bounded = $this->getJson($base.'/data-use-journey?access='.$access)->assertOk()->assertJsonPath('data.licenses_more', true)->json('data');
            $this->assertCount(32, $bounded['licenses']);
            $this->assertNotEmpty($bounded['business_use_options']);
            $this->assertNotEmpty($bounded['binding_options']);
        }
        $summaryRead = [...$read, 'command_id' => (string) Str::uuid(), 'material_refs' => [$summary['artifact_ref']], 'operation' => 'display',
            'authority_selection' => $realm([$req('business', $mandate['business.data.use']), $req('data_use', $summary['use_grant_id'])])];
        $this->postJson($base.'/materials/read', $summaryRead)->assertForbidden();
        $publication = ['command_id' => (string) Str::uuid(), 'artifact_ref' => $summary['artifact_ref'], 'dependencies' => $summary['dependencies'],
            'purpose' => $read['purpose'], 'recipient_ref' => $f['user']->public_id,
            'authority_selection' => $realm([...$read['authority_selection']['requirements'], $req('data_use', $summary['use_grant_id'])])];
        $this->postJson($base.'/materials/publish', $publication)->assertOk();
        $captured = DB::connection('pgsql_migrations')->table('business_expenditure_summaries')->where('summary_id', $summaryBody['summary_id'])->value('input_reads');
        $this->assertExactReference($f['ref'], json_decode($captured, true)[0]['input']);
        $this->postJson($base.'/materials/publish', $publication)->assertOk();
        $recipient = User::factory()->create();
        $roots = $this->postJson($base.'/direct-team', ['command_id' => (string) Str::uuid(), 'authority_selection' => $realm([$req('business', $access)])])->assertOk()->json('data.grant_refs');
        $delegate = function (string $action, int|string $parent) use ($recipient, $base, $realm, $req): int {
            return (int) $this->postJson($base.'/grants', ['command_id' => (string) Str::uuid(), 'effect' => 'issue', 'grantee_public_id' => $recipient->public_id,
                'action' => $action, 'parent_grant_id' => (int) $parent, 'grant_id' => null, 'expected_grant' => 0, 'expires_at' => now()->addMinutes(20)->toIso8601String(),
                'delegable' => false, 'realm_workspace_public_id' => null, 'authority_selection' => $realm([$req('business', $parent)])])->assertOk()->json('data.grant_id');
        };
        $recipientRead = $delegate('business.context.read', $roots['business.context.read']);
        $recipientUse = $delegate('business.data.use', $mandate['business.data.use']);
        $assignment = ['command_id' => (string) Str::uuid(), 'license_id' => $summary['license_id'], 'effect' => 'assign', 'recipient_public_id' => $recipient->public_id,
            'valid_until' => now()->addMinutes(5)->toIso8601String(), 'expected_revision' => 0,
            'authority_selection' => $realm([$req('business', $mandate['business.data.use']), $req('client_data', $issued['boundary_grant_id'])])];
        $this->postJson($base.'/data-use-beneficiaries', [...$assignment, 'valid_until' => now()->addMinutes(15)->toIso8601String()])->assertForbidden();
        $assigned = $this->postJson($base.'/data-use-beneficiaries', $assignment)->assertOk()->json('data');
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('security_action_grants')->where('subject_user_id', $recipient->id)->where('scope_kind', 'source')->count());
        $summaryRead['recipient_ref'] = $recipient->public_id;
        $summaryRead['authority_selection'] = $realm([$req('business', $recipientUse), $req('data_use', $assigned['grant_id'])]);
        $this->actingAs($recipient);
        $this->getJson('/api/data-rights/use-scopes')->assertOk()->assertJsonCount(0, 'data.scopes');
        $metadata = $this->getJson($base.'/data-use-journey?access='.$recipientRead)->assertOk()->assertJsonCount(0, 'data.sources')->assertJsonCount(0, 'data.licenses');
        $metadata->assertJsonPath('data.assigned_licenses.0.artifact_ref', $summary['artifact_ref'])->assertJsonMissingPath('data.assigned_licenses.0.source_id');
        $response = $this->postJson($base.'/materials/read', $summaryRead)->assertOk()->assertJsonPath('data.materials.0.fields.spend.major_units', '12');
        foreach ([$f['source'], 'DO_NOT_DISCLOSE', 'campaign-1', 'campaign-2', 'record_metadata', 'evidence_refs'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->postJson($base.'/materials/read', [...$summaryRead, 'command_id' => (string) Str::uuid(), 'material_refs' => [$f['ref']]])->assertForbidden();
        $this->postJson($base.'/materials/read', [...$summaryRead, 'command_id' => (string) Str::uuid(), 'operation' => 'process'])->assertForbidden();
        $this->actingAs(User::factory()->create())->postJson($base.'/materials/read', $summaryRead)->assertForbidden();
        $this->actingAs($f['user'])->postJson($base.'/data-use-beneficiaries', [...$assignment, 'command_id' => (string) Str::uuid(), 'effect' => 'revoke', 'expected_revision' => 1])->assertOk();
        $this->actingAs($recipient)->postJson($base.'/materials/read', $summaryRead)->assertForbidden();
        $this->actingAs($f['user']);
        $renewed = $this->postJson($base.'/data-use-beneficiaries', [...$assignment, 'command_id' => (string) Str::uuid(), 'expected_revision' => 2])->assertOk()->json('data');
        $this->actingAs($recipient)->postJson($base.'/materials/read', $summaryRead)->assertForbidden();
        $summaryRead['command_id'] = (string) Str::uuid();
        $summaryRead['authority_selection']['requirements'][1]['leaf_grant_id'] = (int) $renewed['grant_id'];
        $this->postJson($base.'/materials/read', $summaryRead)->assertOk();
        $this->actingAs($f['user'])->postJson($base.'/data-use-licenses', [...$license, 'command_id' => (string) Str::uuid(), 'effect' => 'withdraw', 'expected_revision' => 1, 'declaration' => (object) []])->assertOk();
        $this->actingAs($recipient)->postJson($base.'/materials/read', $summaryRead)->assertForbidden();
        $this->getJson($base.'/data-use-journey?access='.$recipientRead)->assertOk()->assertJsonCount(0, 'data.assigned_licenses');
    }

    public static function amounts(): array
    {
        return ['ordinary' => ['12', 0, '12', false], 'full_precision' => ['0.123456789012345678901234567891', 18, '0.000000000000000000123456789012345678901234567891', false],
            'receipt_expiry' => ['12', 0, '12', true]];
    }

    #[DataProvider('amounts')]
    public function test_explicit_summary_uses_registered_publication_and_existing_material_reader(string $native, int $scale, string $major, bool $receiptExpiry): void
    {
        $f = $this->nativeMaterial(operation: 'process', amount: new NativeAmount($native, 'SAR', 'synthetic:private-unit', $scale, 'DO_NOT_DISCLOSE_SCALE_PROVENANCE'));
        $this->postJson('/api/businesses/'.$f['business']['business_public_id'].'/materials/read', $f['body'])->assertOk();
        $owner = DB::connection('pgsql_migrations');
        $boundaryGrant = $f['boundary']['grant_id'];
        $selection = $f['body']['authority_selection'];
        $selection['requirements'][] = ['requirement_kind' => 'client_data', 'leaf_grant_id' => $boundaryGrant];
        $selection['requirements'][] = ['requirement_kind' => 'business', 'leaf_grant_id' => $f['manager']->id];
        $body = ['command_id' => (string) Str::uuid(), 'summary_id' => (string) Str::uuid7(),
            'input_ref' => $f['ref'], 'input_license_id' => $f['useScope'], 'read_command_id' => $f['body']['command_id'], 'confirmed' => true,
            'valid_until' => ($receiptExpiry ? now()->addSeconds(12) : now()->addMinutes(10))->toIso8601String(),
            'consent' => ['derive' => true, 'deliver_summary' => true, 'retain_payload' => false],
            'authority_selection' => $selection];
        $url = '/api/businesses/'.$f['business']['business_public_id'].'/expenditure-summaries';
        $beforeBusiness = $owner->table('business_authority_fences')->where('business_id', $f['id'])->value('auth_epoch');
        $beforeBoundary = $owner->table('resource_right_fences')->where('boundary_id', $f['boundary']['boundary_id'])->value('rights_epoch');
        $result = $this->postJson($url, $body)->assertOk()->json('data');
        $this->assertSame($beforeBusiness + 1, $owner->table('business_authority_fences')->where('business_id', $f['id'])->value('auth_epoch'));
        $this->assertSame($beforeBoundary + 1, $owner->table('resource_right_fences')->where('boundary_id', $f['boundary']['boundary_id'])->value('rights_epoch'));
        $this->assertSame('business.expenditure_summary', $result['artifact_ref']['namespace']);
        $this->assertSame($result, $this->postJson($url, $body)->assertOk()->json('data'));
        $this->assertSame($beforeBusiness + 1, $owner->table('business_authority_fences')->where('business_id', $f['id'])->value('auth_epoch'));
        $this->assertSame($beforeBoundary + 1, $owner->table('resource_right_fences')->where('boundary_id', $f['boundary']['boundary_id'])->value('rights_epoch'));
        $this->assertSame(1, $owner->table('business_expenditure_summaries')->count());
        $publishSelection = $f['body']['authority_selection'];
        $publishSelection['requirements'][] = ['requirement_kind' => 'data_use', 'leaf_grant_id' => (int) $result['use_grant_id']];
        $this->postJson('/api/businesses/'.$f['business']['business_public_id'].'/materials/publish', [
            'command_id' => (string) Str::uuid(), 'artifact_ref' => $result['artifact_ref'], 'dependencies' => $result['dependencies'],
            'purpose' => $f['body']['purpose'], 'recipient_ref' => $f['user']->public_id, 'authority_selection' => $publishSelection,
        ])->assertOk();
        $read = [...$f['body'], 'command_id' => (string) Str::uuid(), 'material_refs' => [$result['artifact_ref']], 'operation' => 'display'];
        $read['authority_selection']['requirements'] = [
            $f['body']['authority_selection']['requirements'][0],
            ['requirement_kind' => 'data_use', 'leaf_grant_id' => (int) $result['use_grant_id']],
        ];
        $response = $this->postJson('/api/businesses/'.$f['business']['business_public_id'].'/materials/read', $read)->assertOk();
        $response->assertJsonPath('data.materials.0.fields.spend.major_units', $major)
            ->assertJsonPath('data.materials.0.fields.spend.native_value', $native)
            ->assertJsonPath('data.materials.0.fields.spend.decimal_scale', $scale)
            ->assertJsonPath('data.materials.0.fields.spend.currency', 'SAR')
            ->assertJsonPath('data.materials.0.coverage', 'PARTIAL');
        $this->assertStringNotContainsString('campaign-1', $response->getContent());
        $this->assertStringNotContainsString('DO_NOT_DISCLOSE', $response->getContent());
        $this->assertStringNotContainsString($f['source'], $response->getContent());
        $bad = [...$body, 'command_id' => (string) Str::uuid(), 'summary_id' => (string) Str::uuid7(), 'confirmed' => false];
        $this->postJson($url, $bad)->assertUnprocessable();
        $qualification = $owner->table('security_action_grants')->where('id', $f['native'])->value('qualification_id');
        if ($scale === 0) {
            $this->assertSummarySourceWithdrawalWait($f, $read, $qualification, $receiptExpiry);
        } else {
            BusinessAuthorityFixture::revokeQualification($qualification, $f['user']->id);
        }
        $this->assertSame('active', $owner->table('security_action_grants')->where('id', $f['native'])->value('status'));
        $this->postJson('/api/businesses/'.$f['business']['business_public_id'].'/materials/read', $read)->assertForbidden();
        $bad = [...$body, 'command_id' => (string) Str::uuid(), 'consent' => ['derive' => true, 'deliver_summary' => true, 'retain_payload' => true]];
        $this->postJson($url, $bad)->assertUnprocessable();
    }

    private function assertSummarySourceWithdrawalWait(array $f, array $read, string $qualification, bool $receiptExpiry): void
    {
        $request = Request::create('/');
        $request->setLaravelSession(app('session')->driver());
        $run = 'expenditure-summary-'.Str::uuid();
        $file = storage_path('framework/testing/'.$run.'.json');
        $ready = $file.'.ready';
        $release = $file.'.release';
        $command = (string) Str::uuid();
        file_put_contents($file, json_encode(['actor' => $f['user']->id, 'run' => $run, 'operation' => 'material',
            'business' => $f['business']['business_public_id'], 'command' => $command, 'refs' => $read['material_refs'], 'material_operation' => 'display',
            'purpose' => $read['purpose'], 'recipient' => $read['recipient_ref'], 'selection' => $read['authority_selection'],
            'session' => SessionAuthority::sessionKey($request), 'ready' => $ready, 'release' => $release], JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $file], base_path());
        $worker->setTimeout(45);
        $owner = DB::connection('pgsql_migrations');
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
            throw new \RuntimeException('Actual summary Source lock wait was not witnessed.');
        };
        try {
            $this->assertFileDoesNotExist($ready);
            $worker->start();
            $identity = $wait(function () use ($ready, $run) {
                if (! is_file($ready)) {
                    return null;
                }
                $value = json_decode(file_get_contents($ready), true);

                return is_array($value) && ($value['run'] ?? null) === $run && is_int($value['pid'] ?? null) && $value['pid'] > 0 ? $value : null;
            });
            $owner->beginTransaction();
            $blocker = (int) $owner->scalar('SELECT pg_backend_pid()');
            if ($receiptExpiry) {
                $owner->statement('LOCK TABLE business_command_receipts IN SHARE MODE');
                $deadline = $owner->scalar('SELECT valid_until::text FROM business_expenditure_summaries WHERE summary_id=?::uuid', [$read['material_refs'][0]['key']]);
                // Release the live, bootstrapped worker near the real expiry,
                // preserving the original database lock timeout.
                $wait(fn () => (bool) $owner->scalar("SELECT clock_timestamp()>=?::timestamptz-interval '2 seconds'", [$deadline]));
            } else {
                $owner->select("SELECT fence_id FROM resource_right_fences WHERE kind='source' AND source_id=?::uuid FOR UPDATE", [$f['source']]);
            }
            file_put_contents($release, 'go');
            $observed = $wait(fn () => DB::selectOne("SELECT pid,backend_xid,wait_event_type,pg_blocking_pids(pid)::text blockers FROM pg_stat_activity WHERE application_name=? AND pid=? AND wait_event_type='Lock' AND ?=ANY(pg_blocking_pids(pid))", [$run, $identity['pid'], $blocker]));
            $this->assertNotNull($observed->backend_xid);
            $this->assertSame('Lock', $observed->wait_event_type);
            if ($receiptExpiry) {
                $deadline = $owner->scalar('SELECT valid_until::text FROM business_expenditure_summaries WHERE summary_id=?::uuid', [$read['material_refs'][0]['key']]);
                $this->assertTrue((bool) $owner->scalar('SELECT clock_timestamp()<?::timestamptz', [$deadline]));
                $locks = DB::select("SELECT relation::regclass::text relation,mode FROM pg_locks WHERE pid=? AND relation='business_command_receipts'::regclass AND NOT granted", [$identity['pid']]);
                $this->assertCount(1, $locks);
                $wait(fn () => (bool) $owner->scalar('SELECT clock_timestamp()>=?::timestamptz', [$deadline]));
                $this->assertSame('active', $owner->table('authority_qualification_receipts')->where('qualification_id', $qualification)->value('status'));
            } else {
                BusinessAuthorityFixture::revokeQualification($qualification, $f['user']->id);
            }
            $owner->commit();
            $worker->wait();
            $this->assertSame(2, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
            $this->assertSame(0, $owner->table('business_command_receipts')->where('command_id', $command)->count());
            file_put_contents($file.'.evidence', json_encode(['ready' => $identity, 'wait' => $observed, 'result' => $worker->getOutput()], JSON_THROW_ON_ERROR));
        } finally {
            if ($owner->transactionLevel() > 0) {
                $owner->rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop(0);
            }
        }
    }
}
