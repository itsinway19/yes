<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\Ads\Provider;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\BusinessAuthorityFixture;
use Tests\Support\BusinessNativeMaterialFixture;
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
        $entry = DB::connection('pgsql_migrations')->table('provider_observation_entries as e')->join('provider_observation_streams as stream', 'stream.namespace', '=', 'e.namespace')->where('stream.source_id', $f['source'])->sole(['e.*']);

        return ['command_id' => (string) Str::uuid(), 'license_id' => (string) Str::uuid7(), 'effect' => 'issue', 'expected_revision' => 0,
            'declaration' => ['source_id' => $f['source'], 'resource' => '123', 'operation' => 'retained_read', 'purpose' => 'market_research',
                'selection' => ['native_namespaces' => [$entry->namespace], 'native_keys' => [$entry->identity], 'field_keys' => ['spend'], 'partition_ref' => null],
                'recipient_public_id' => ($recipient ?? $f['user'])->public_id, 'valid_until' => now()->addHour()->toIso8601String(),
                'capacity' => 'data_holder', 'holder_name' => 'Synthetic business data holder', 'authority_reference' => 'Explicit synthetic licence, not verified legal ownership',
                'confirmed' => true, 'requires_review' => false],
            'authority_selection' => ['realm_kind' => 'direct', 'requirements' => [
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
        $selection = ['realm_kind' => 'direct', 'requirements' => [
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

    public function test_two_businesses_use_independent_licensed_sources_without_borrowing_raw_custody_or_sharing_revocation(): void
    {
        $first = $this->nativeMaterial(licensed: false);
        $firstDeclaration = $this->declaration($first);
        $firstLicence = $this->command($first, $firstDeclaration)->assertOk()->json('data');
        $firstRead = $this->bind($first, $firstLicence);
        $firstPath = '/api/businesses/'.$first['business']['business_public_id'].'/materials/read';
        $this->postJson($firstPath, $firstRead)->assertOk();
        $second = $this->nativeMaterial(licensed: false);
        $secondDeclaration = $this->declaration($second);
        $secondLicence = $this->command($second, $secondDeclaration)->assertOk()->json('data');
        $secondRead = $this->bind($second, $secondLicence);
        $secondPath = '/api/businesses/'.$second['business']['business_public_id'].'/materials/read';
        $this->postJson($secondPath, $secondRead)->assertOk()->assertJsonPath('data.materials.0.fields.spend.native_value', '12');
        $db = DB::connection('pgsql_migrations');
        $before = $db->table('business_command_receipts')->count();
        $borrowed = [...$secondRead, 'command_id' => (string) Str::uuid7(), 'material_refs' => $firstRead['material_refs']];
        $this->postJson($secondPath, $borrowed)->assertForbidden()->assertJsonMissingPath('data.materials');
        $this->assertSame($before, $db->table('business_command_receipts')->count());
        $this->assertNotSame($firstLicence['boundary_id'], $secondLicence['boundary_id']);
        $this->assertNotSame($first['source'], $second['source']);
        $this->actingAs($first['user']);
        $withdraw = [...$firstDeclaration, 'command_id' => (string) Str::uuid7(), 'effect' => 'withdraw', 'expected_revision' => 1, 'declaration' => (object) []];
        $this->command($first, $withdraw)->assertOk();
        $this->postJson($firstPath, $firstRead)->assertForbidden();
        $this->actingAs($second['user'])->postJson($secondPath, $secondRead)->assertOk();
        $this->assertSame('revoked', $db->table('security_action_grants')->where('id', $firstLicence['use_grant_id'])->value('status'));
        $this->assertSame('active', $db->table('security_action_grants')->where('id', $secondLicence['use_grant_id'])->value('status'));
        $this->actingAs($first['user'])->postJson($firstPath, [...$firstRead, 'command_id' => (string) Str::uuid7()])->assertForbidden();
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
            'authority_selection' => ['realm_kind' => 'direct', 'requirements' => [
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
            'authority_selection' => ['realm_kind' => 'direct', 'requirements' => [
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
            'authority_selection' => ['realm_kind' => 'direct', 'requirements' => [
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
        $wait = function (callable $probe, bool $startup = false) use ($worker) {
            $deadline = microtime(true) + 15;
            do {
                $worker->checkTimeout();
                if ($value = $probe()) {
                    return $value;
                }
                if (! $worker->isRunning()) {
                    throw new \RuntimeException($worker->getOutput().$worker->getErrorOutput());
                }
                usleep(10000);
            } while ($startup || microtime(true) < $deadline);
            throw new \RuntimeException('Actual licence issuer lock wait was not witnessed.');
        };
        try {
            $this->assertFileDoesNotExist($ready);
            $worker->start();
            $evidence['ready'] = $wait(function () use ($ready, $run) {
                clearstatcache(true, $ready);
                if (! is_file($ready)) {
                    return null;
                }
                $value = json_decode(file_get_contents($ready), true);

                return is_array($value) && ($value['run'] ?? null) === $run && is_int($value['pid'] ?? null) && $value['pid'] > 0 ? $value : null;
            }, startup: true);
            if ($freshness) {
                // Set and capture the committed fixture deadline in one round trip.
                // The receipt wait must cross it before PostgreSQL's 3s lock timeout.
                $evidence['fresh_until'] = $owner->scalar(<<<'SQL'
                    UPDATE security_sessions
                    SET authenticated_at = date_trunc('second', clock_timestamp()) - interval '15 minutes' + interval '2 seconds'
                    WHERE session_key_hash = ?
                    RETURNING (authenticated_at + interval '15 minutes')::text
                    SQL, [SessionAuthority::sessionKey($request)], false);
                $this->assertNotNull($evidence['fresh_until']);
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

    public function test_historical_non_delegable_scope_is_not_reinterpreted_as_a_named_licence(): void
    {
        $legacy = $this->original();
        $actor = $legacy['user'];
        $recipient = User::factory()->create();
        $base = '/api/businesses/'.$legacy['a']['business_public_id'];
        $parent = $legacy['businessGrant']->id;
        $selection = ['realm_kind' => 'direct', 'requirements' => [
            ['requirement_kind' => 'business', 'leaf_grant_id' => $parent],
        ]];
        $this->postJson($base.'/grants', ['command_id' => (string) Str::uuid(), 'effect' => 'issue',
            'grantee_public_id' => $recipient->public_id, 'action' => 'business.data.use', 'parent_grant_id' => $parent,
            'grant_id' => null, 'expected_grant' => 0, 'expires_at' => now()->addHour()->toIso8601String(), 'delegable' => false,
            'authority_selection' => $selection])->assertOk();
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
