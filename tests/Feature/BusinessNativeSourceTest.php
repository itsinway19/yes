<?php

namespace Tests\Feature;

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Providers\Ads\AdObservation;
use App\Providers\Ads\Google\GoogleOAuthClient;
use App\Providers\Ads\NativeAmount;
use App\Providers\Ads\NativeSourceContext;
use App\Providers\Ads\ObservationBatch;
use App\Providers\Ads\ObservationJournal;
use App\Providers\Ads\PersistObservationJob;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderAccessStore;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReportWindow;
use App\Security\SessionAuthority;
use App\Security\SourceAuditCustody;
use App\Sources\EffectiveCapability;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\BusinessAuthorityFixture;
use Tests\TestCase;

/** Real creator/Source commands. No synthetic Source root is inserted. */
final class BusinessNativeSourceTest extends TestCase
{
    use ApplicationContractAssertions;

    private function draft(User $actor, string $name): array
    {
        return $this->actingAs($actor)->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => $name])
            ->assertCreated()->json('data');
    }

    private function request(User $actor): Request
    {
        $request = Request::create('/api/businesses');
        $request->setUserResolver(fn () => $actor);
        $request->setLaravelSession(app('session')->driver());

        return $request;
    }

    private function signed(User $actor, callable $action): mixed
    {
        return DB::transaction(function () use ($actor, $action): mixed {
            app(PrincipalContext::class)->activate($actor);

            return $action($this->request($actor));
        });
    }

    private function payload(string $resource = '123'): array
    {
        return ['family' => 'meta', 'namespace' => 'ad_account', 'external_id' => $resource, 'label' => 'Private native account',
            'resource' => $resource, 'operation' => 'report.read', 'purpose' => 'advertising_analysis', 'expected_version' => 0,
            'reason' => 'explicit_meta_selection'];
    }

    private function bootstrap(User $actor, array $business, string $source, string $command, ?array $payload = null): array
    {
        return $this->signed($actor, fn (Request $request) => json_decode(DB::scalar(
            'SELECT builderad_v2_business_source_bootstrap(?,?::uuid,?::uuid,?::uuid,?::jsonb,?::jsonb)', [
                SessionAuthority::sessionKey($request), $business['business_public_id'], $command, $source,
                json_encode($payload ?? $this->payload(), JSON_THROW_ON_ERROR), json_encode([
                    'realm_kind' => 'direct',
                    'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => (int) $business['grant_refs']['business.context.read']]],
                ], JSON_THROW_ON_ERROR),
            ]), true, flags: JSON_THROW_ON_ERROR));
    }

    private function denied(callable $action, string $state = '42501'): void
    {
        try {
            $action();
            self::fail('An unauthorized or conflicting Source command committed.');
        } catch (QueryException $error) {
            self::assertSame($state, $error->getCode());
        }
    }

    private function callbackLanding(string $callbackUrl, array $business, Provider $provider): string
    {
        $callback = $this->get($callbackUrl)->assertRedirect();
        $target = $callback->headers->get('Location');
        self::assertSame('/businesses/'.$business['business_public_id'], parse_url($target, PHP_URL_PATH));
        parse_str(parse_url($target, PHP_URL_QUERY), $query);
        self::assertSame((string) $business['grant_refs']['business.context.read'], $query['access']);
        self::assertSame('sources', $query['section']);
        $this->get(strtok($target, '#'))->assertOk();
        parse_str(parse_url($target, PHP_URL_FRAGMENT), $fragment);
        self::assertArrayHasKey($provider->value.'-flow', $fragment);

        return $fragment[$provider->value.'-flow'];
    }

    public function test_original_creator_registers_exact_source_atomically_without_any_workspace_or_data_use(): void
    {
        $creator = User::factory()->create();
        $business = $this->draft($creator, 'Independent source custody');
        $source = (string) Str::uuid7();
        $command = (string) Str::uuid();
        $receipt = $this->bootstrap($creator, $business, $source, $command);
        self::assertSame($receipt, $this->bootstrap($creator, $business, $source, $command));
        self::assertSame('business_native', $receipt['custody_kind']);
        self::assertFalse($receipt['establishes_business_data_use']);
        self::assertCount(7, $receipt['grant_refs']);
        $owner = DB::connection('pgsql_migrations');
        self::assertNull($owner->scalar("SELECT to_regclass('public.workspaces')"));
        self::assertNull($owner->scalar("SELECT to_regclass('public.workspace_memberships')"));
        self::assertSame(0, $owner->table('business_data_use_bindings')->count());
        self::assertSame(0, $owner->table('data_use_scopes')->count());
        self::assertSame(1, $owner->table('source_registry')->count());
        self::assertNull($owner->scalar("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='source_registry' AND column_name='workspace_id'"));
        self::assertSame($owner->table('businesses')->value('id'), $owner->table('source_registry')->value('business_id'));
        foreach (['source_capabilities', 'source_command_receipts', 'source_versions', 'source_audit_obligations', 'source_audit_delivery'] as $table) {
            self::assertSame(1, $owner->table($table)->count(), $table);
            self::assertNull($owner->scalar("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name=? AND column_name='workspace_id'", [$table]), $table);
        }
        self::assertSame('BB1-BUSINESS-V1', json_decode($owner->table('source_audit_obligations')->value('envelope'), true)['policy']);
        $this->signed($creator, function (Request $request) use ($source, $owner): void {
            DB::selectOne('SELECT builderad_source_read_scope(?,?,?::uuid,?,?,?)',
                [$owner->table('source_registry')->where('id', $source)->value('business_id'), SessionAuthority::sessionKey($request), $source, '123', 'report.read', 'advertising_analysis']);
            self::assertSame(1, DB::table('source_registry')->count());
            self::assertSame(1, DB::table('source_versions')->count());
            self::assertSame(1, DB::table('source_audit_obligations')->count());
        });
    }

    public function test_registration_audit_failure_rolls_back_source_rights_and_exact_command_can_retry(): void
    {
        $creator = User::factory()->create();
        $business = $this->draft($creator, 'Atomic source registration');
        $db = DB::connection('pgsql_migrations');
        $body = [...$this->payload(), 'source_id' => (string) Str::uuid7(), 'command_id' => (string) Str::uuid7(), 'effect' => 'register'];
        $route = '/businesses/'.$business['business_public_id'].'/sources';
        $before = $db->table('security_action_grants')->count();
        $db->unprepared(<<<'SQL'
            CREATE FUNCTION public.synthetic_source_audit_failure() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'Synthetic audit rejection' USING ERRCODE='23514'; END $$;
            CREATE TRIGGER synthetic_source_audit_failure BEFORE INSERT ON public.source_audit_obligations
            FOR EACH ROW EXECUTE FUNCTION public.synthetic_source_audit_failure();
            SQL);
        try {
            $this->postJson($route, $body)->assertUnprocessable();
            foreach (['source_registry', 'source_versions', 'source_command_receipts', 'source_audit_obligations', 'source_audit_delivery'] as $table) {
                self::assertSame(0, $db->table($table)->count(), $table);
            }
            self::assertSame($before, $db->table('security_action_grants')->count());
            self::assertFalse($db->table('business_command_receipts')->where('command_id', $body['command_id'])->exists());
        } finally {
            $db->unprepared('DROP TRIGGER synthetic_source_audit_failure ON public.source_audit_obligations; DROP FUNCTION public.synthetic_source_audit_failure();');
        }
        $receipt = $this->postJson($route, $body)->assertOk()->json();
        $this->postJson($route, $body)->assertOk()->assertExactJson($receipt);
        self::assertSame(1, $db->table('source_versions')->count());
        self::assertSame(1, $db->table('source_audit_obligations')->count());
    }

    public function test_business_registry_registers_an_independent_non_advertising_source_without_provider_or_data_use_rights(): void
    {
        $creator = User::factory()->create();
        $business = $this->draft($creator, 'Local source declaration');
        $base = '/businesses/'.$business['business_public_id'];
        $candidate = $this->getJson($base.'/source-registry')->assertOk()->json('data.sourceCandidateId');
        $payload = ['command_id' => (string) Str::uuid(), 'source_id' => $candidate, 'effect' => 'register',
            'expected_version' => 0, 'resource' => 'inventory-1', 'operation' => 'inventory.read', 'purpose' => 'inventory_review',
            'reason' => 'owner_declared_identity', 'family' => 'inventory', 'namespace' => 'catalog',
            'external_id' => 'catalog-1', 'label' => 'Declared inventory'];
        $this->postJson($base.'/sources', $payload)->assertOk()->assertJsonPath('registration_state', 'UNVERIFIED');
        $this->postJson($base.'/sources', $payload)->assertOk()->assertJsonPath('source_id', $candidate);
        $this->getJson($base.'/source-registry')->assertOk()->assertJsonPath('data.sources.items.0.id', $candidate);
        $owner = DB::connection('pgsql_migrations');
        self::assertSame(0, $owner->table('security_action_grants')->where('source_target_id', $candidate)->where('action', 'like', 'provider.%')->count());
        self::assertSame(0, $owner->table('business_data_use_bindings')->count());
    }

    public function test_business_registry_replays_exact_registration_and_separates_natural_identity_by_business(): void
    {
        $creator = User::factory()->create();
        $a = $this->draft($creator, 'First source registry');
        $b = $this->draft($creator, 'Second source registry');
        $firstPath = '/businesses/'.$a['business_public_id'].'/sources';
        $secondPath = '/businesses/'.$b['business_public_id'].'/sources';
        foreach (['meta', 'google', 'snapchat', 'tiktok', 'x', 'business'] as $family) {
            $input = [...$this->payload(), 'family' => $family, 'source_id' => (string) Str::uuid7(),
                'command_id' => (string) Str::uuid7(), 'effect' => 'register'];
            $receipt = $this->postJson($firstPath, $input)->assertOk()->assertJsonPath('registration_state', 'UNVERIFIED')->json();
            $this->postJson($firstPath, array_reverse($input, true))->assertOk()->assertExactJson($receipt);
            $this->postJson($firstPath, [...$input, 'label' => 'Different request'])->assertConflict();
            $this->postJson($firstPath, [...$input, 'command_id' => (string) Str::uuid7(),
                'source_id' => (string) Str::uuid7()])->assertConflict();
        }
        $this->postJson($firstPath, [...$input, 'provider_permission' => 'VERIFIED'])->assertUnprocessable();
        $this->postJson($firstPath, [...$input, 'token' => 'synthetic-forbidden-secret'])->assertUnprocessable();
        $second = [...$input, 'source_id' => (string) Str::uuid7()];
        $this->postJson($secondPath, $second)->assertConflict();
        $this->postJson($secondPath, [...$second, 'command_id' => (string) Str::uuid7()])->assertOk();
        $this->getJson('/businesses/'.$a['business_public_id'].'/source-registry')->assertOk()->assertJsonCount(6, 'data.sources.items');
        $this->getJson('/businesses/'.$b['business_public_id'].'/source-registry')->assertOk()->assertJsonCount(1, 'data.sources.items')
            ->assertJsonPath('data.sources.items.0.id', $second['source_id']);
        $db = DB::connection('pgsql_migrations');
        foreach (['source_registry', 'source_command_receipts', 'source_audit_obligations'] as $table) {
            self::assertSame(7, $db->table($table)->count(), $table);
        }
    }

    public function test_business_registry_projects_exact_source_truth_without_renewing_passive_session(): void
    {
        $creator = User::factory()->create();
        $business = $this->draft($creator, 'Protected source registry');
        $base = '/businesses/'.$business['business_public_id'];
        $registry = $base.'/source-registry';
        $empty = $this->getJson($registry)->assertOk()->assertJsonCount(0, 'data.sources.items')
            ->assertJsonPath('data.business.public_id', $business['business_public_id'])->assertJsonPath('visibility.protected', true);
        $this->assertApplicationContract('BusinessSourceRegistryResource', $empty->json());
        $input = [...$this->payload(), 'source_id' => (string) Str::uuid7(), 'command_id' => (string) Str::uuid7(), 'effect' => 'register'];
        $receipt = $this->postJson($base.'/sources', $input)->assertOk()->json();
        $resource = $this->getJson($registry)->assertOk()->assertJsonCount(1, 'data.sources.items')
            ->assertJsonPath('data.sources.items.0.id', $input['source_id'])
            ->assertJsonPath('data.sources.items.0.identity_verification', 'UNVERIFIED')
            ->assertJsonPath('data.sources.items.0.capability.state', 'UNKNOWN')
            ->assertJsonPath('data.sources.items.0.capability.last_verified_at', null)
            ->assertJsonPath('data.sources.items.0.axes.provider_permission', 'UNKNOWN')
            ->assertJsonPath('data.sources.items.0.audit_delivery.0.state', 'PENDING');
        $this->assertApplicationContract('BusinessSourceRegistryResource', $resource->json());
        self::assertStringContainsString('no-store', $resource->headers->get('Cache-Control'));
        $sessions = DB::connection('pgsql_migrations')->table('security_sessions')->where('user_id', $creator->id);
        $before = $sessions->sole();
        $this->travel(30)->seconds();
        try {
            $this->getJson($registry, ['X-Builderad-Passive' => '1'])->assertOk();
            $this->getJson($registry, ['X-Builderad-Witness' => '1'])->assertOk()
                ->assertJsonPath('visibility.protected', true)->assertJsonMissingPath('data');
            $after = $sessions->sole();
            self::assertSame($before->idle_expires_at, $after->idle_expires_at);
            self::assertSame($before->absolute_expires_at, $after->absolute_expires_at);
        } finally {
            $this->travelBack();
        }
        $this->postJson($base.'/sources', $input)->assertOk()->assertExactJson($receipt);
        $this->postJson($base.'/source-grants', ['command_id' => (string) Str::uuid7(), 'source_id' => $input['source_id'],
            'resource' => $input['resource'], 'operation' => $input['operation'], 'purpose' => $input['purpose'],
            'reason' => 'owner_explicit_request', 'action' => 'source.read', 'decision' => 'revoke'])->assertOk();
        $this->getJson($registry)->assertOk()->assertJsonCount(0, 'data.sources.items');
        $this->actingAs(User::factory()->create())->getJson($registry)->assertForbidden();
    }

    public function test_source_revoke_commits_before_a_queued_command_using_the_old_grant(): void
    {
        $creator = User::factory()->create();
        $business = $this->draft($creator, 'Serialized source withdrawal');
        $source = (string) Str::uuid7();
        $created = $this->bootstrap($creator, $business, $source, (string) Str::uuid7());
        $grant = (string) $created['grant_refs']['source.read'];
        $db = DB::connection('pgsql_migrations');
        $fence = $db->table('resource_right_fences')->where('kind', 'source')->where('source_id', $source);
        $epoch = (int) $fence->sole()->rights_epoch;
        $run = 'source-revoke-wait-'.Str::uuid7();
        $path = storage_path('framework/testing/'.$run.'.json');
        $workerInput = ['run' => $run, 'ready' => $path.'.ready', 'release' => $path.'.release',
            'operation' => 'resource', 'actor' => $creator->id, 'kind' => 'source', 'target' => $source, 'effect' => 'revoke',
            'command' => (string) Str::uuid7(), 'session' => SessionAuthority::sessionKey($this->request($creator)),
            'payload' => ['grant_id' => $grant], 'expected' => 1,
            'selection' => ['realm_kind' => 'direct', 'requirements' => [['requirement_kind' => 'native_source', 'leaf_grant_id' => $grant]]]];
        file_put_contents($path, json_encode($workerInput, JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $path], base_path());
        $worker->setTimeout(45);
        $winner = (string) Str::uuid7();
        $evidence = [];
        try {
            $worker->start();
            $ready = $this->observeSourceWorker($worker, function () use ($workerInput) {
                clearstatcache(true, $workerInput['ready']);

                return is_file($workerInput['ready']) ? json_decode(file_get_contents($workerInput['ready']), true) : null;
            });
            DB::beginTransaction();
            app(PrincipalContext::class)->activate($creator);
            $blocker = (int) DB::scalar('SELECT pg_backend_pid()');
            $result = json_decode(DB::scalar("SELECT builderad_v2_resource_right_command(?,?::uuid,'source',?::uuid,'revoke',?::jsonb,1,?::jsonb)", [
                $workerInput['session'], $winner, $source, json_encode($workerInput['payload'], JSON_THROW_ON_ERROR),
                json_encode($workerInput['selection'], JSON_THROW_ON_ERROR),
            ]), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('revoked', $result['status']);
            file_put_contents($workerInput['release'], 'go');
            $evidence['wait'] = $this->observeSourceWorker($worker, fn () => $db->selectOne(
                'SELECT pid,wait_event,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE pid=? AND ?=ANY(pg_blocking_pids(pid))',
                [$ready['pid'], $blocker],
            ));
            DB::commit();
            $worker->wait();
            self::assertSame(2, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            self::assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
            self::assertSame($epoch + 1, (int) $fence->sole()->rights_epoch);
            $row = $db->table('security_action_grants')->where('id', $grant)->sole();
            self::assertSame('revoked', $row->status);
            self::assertSame(2, (int) $row->version);
            self::assertSame(1, $db->table('business_command_receipts')->where('command_id', $winner)->count());
            self::assertFalse($db->table('business_command_receipts')->where('command_id', $workerInput['command'])->exists());
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $evidence['worker'] = ['exit' => $worker->getExitCode(), 'stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput()];
            file_put_contents($path.'.evidence.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            foreach ([$path, $workerInput['ready'], $workerInput['release']] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function test_source_catalog_and_signed_read_deny_raw_mutation_and_forged_sessions(): void
    {
        $creator = User::factory()->create();
        $business = $this->draft($creator, 'Signed Source projection');
        $source = (string) Str::uuid7();
        $this->bootstrap($creator, $business, $source, (string) Str::uuid7());
        $owner = DB::connection('pgsql_migrations');
        foreach (['source_registry', 'source_capabilities', 'source_versions', 'source_command_receipts',
            'source_audit_obligations', 'source_audit_delivery', 'security_source_audit_custody'] as $table) {
            $catalog = $owner->selectOne('SELECT relrowsecurity,relforcerowsecurity FROM pg_class WHERE oid=?::regclass', [$table]);
            self::assertTrue($catalog->relrowsecurity && $catalog->relforcerowsecurity, $table);
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $verb) {
                self::assertFalse($owner->scalar("SELECT has_table_privilege('builderad_app', ?, ?)", [$table, $verb]), $table.' '.$verb);
            }
        }
        $businessId = $owner->table('businesses')->where('public_id', $business['business_public_id'])->value('id');
        self::assertSame(0, DB::table('source_registry')->count());
        DB::transaction(function () use ($creator): void {
            DB::selectOne("SELECT set_config('app.principal_user_id',?,true),set_config('app.authorization_phase','principal',true),set_config('app.source_session',?,true)",
                [(string) $creator->id, SessionAuthority::sessionKey($this->request($creator))]);
            self::assertSame(0, DB::table('source_registry')->count());
        });
        $this->signed($creator, function (Request $request) use ($businessId): void {
            self::assertSame(0, DB::table('source_registry')->count());
            DB::selectOne('SELECT builderad_source_begin_read(?,?)', [$businessId, SessionAuthority::sessionKey($request)]);
            self::assertSame(1, DB::table('source_registry')->count());
            DB::selectOne("SELECT set_config('app.source_session','forged',true)");
            self::assertSame(0, DB::table('source_registry')->count());
        });
        $this->denied(fn () => $this->signed($creator, fn () => DB::table('source_registry')
            ->where('id', $source)->update(['label' => 'Denied direct write'])));
        $this->denied(fn () => DB::statement('SET ROLE builderad_owner'));
    }

    public static function finalReceiptSourceEffects(): array
    {
        return [
            'rename' => ['rename', 1, ['label' => 'Uncommitted replacement', 'reason' => 'synthetic_final_freshness']],
            'retire' => ['retire', 1, ['reason' => 'synthetic_final_freshness']],
            'issue' => ['qualify_issue', 0, ['action' => 'source.read', 'expires_at' => null, 'reason' => 'synthetic_final_freshness']],
        ];
    }

    public static function protectedSourceReads(): array
    {
        return [['source-surface', 2], ['source-choices', 0]];
    }

    #[DataProvider('protectedSourceReads')]
    public function test_source_read_rechecks_withdrawn_basis_after_the_rights_fence(string $operation, int $exit): void
    {
        $creator = User::factory()->create();
        $business = $this->draft($creator, 'Source rights fence');
        $source = (string) Str::uuid7();
        $created = $this->bootstrap($creator, $business, $source, (string) Str::uuid7());
        $db = DB::connection('pgsql_migrations');
        $qualification = $db->table('security_action_grants')->where('id', $business['grant_refs']['business.context.read'])->value('qualification_id');
        $run = $operation.'-withdrawal-'.Str::uuid7();
        $path = storage_path('framework/testing/'.$run.'.json');
        $input = ['run' => $run, 'ready' => $path.'.ready', 'release' => $path.'.release',
            'operation' => $operation, 'actor' => $creator->id, 'source' => $source,
            'read_grant' => $created['grant_refs']['source.manage'], 'session' => SessionAuthority::sessionKey($this->request($creator))];
        file_put_contents($path, json_encode($input, JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $path], base_path());
        $worker->setTimeout(45);
        $evidence = [];
        try {
            $worker->start();
            $ready = $this->observeSourceWorker($worker, function () use ($input) {
                clearstatcache(true, $input['ready']);

                return is_file($input['ready']) ? json_decode(file_get_contents($input['ready']), true) : null;
            });
            $db->beginTransaction();
            $blocker = $db->scalar('SELECT pg_backend_pid()');
            $db->selectOne("SELECT fence_id FROM resource_right_fences WHERE kind='source' AND source_id=?::uuid FOR UPDATE", [$source]);
            file_put_contents($input['release'], 'go');
            $evidence['wait'] = $this->observeSourceWorker($worker, fn () => DB::selectOne(
                'SELECT pid,wait_event,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE pid=? AND ?=ANY(pg_blocking_pids(pid))',
                [$ready['pid'], $blocker],
            ));
            $db->selectOne('SELECT user_id FROM principal_lifecycles WHERE user_id=? FOR UPDATE NOWAIT', [$creator->id]);
            BusinessAuthorityFixture::revokeQualification($qualification, $creator->id);
            $db->commit();
            $worker->wait();
            $output = $worker->getOutput();
            self::assertSame($exit, $worker->getExitCode(), $output.$worker->getErrorOutput());
            if ($operation === 'source-surface') {
                self::assertStringContainsString('DENIED_STATE=42501', $output);
                self::assertStringNotContainsString('COMMITTED=', $output);
            } else {
                self::assertStringContainsString('COMMITTED=', $output);
                self::assertStringContainsString('"sources": []', $output);
            }
            self::assertStringNotContainsString('Private native account', $output);
        } finally {
            if ($db->transactionLevel()) {
                $db->rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $evidence['worker'] = ['exit' => $worker->getExitCode(), 'stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput()];
            file_put_contents($path.'.evidence.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            foreach ([$path, $input['ready'], $input['release']] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    private function observeSourceWorker(Process $worker, callable $probe): mixed
    {
        $deadline = microtime(true) + 15;
        do {
            if ($value = $probe()) {
                return $value;
            }
            if (! $worker->isRunning()) {
                self::fail('Source participant stopped: '.$worker->getOutput().$worker->getErrorOutput());
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        self::fail('Required Source worker condition was not observed.');
    }

    #[DataProvider('finalReceiptSourceEffects')]
    public function test_source_freshness_expires_at_the_final_receipt_wait_without_effects(string $effect, int $revision, array $payload): void
    {
        $creator = User::factory()->create();
        $business = $this->draft($creator, 'Source receipt freshness');
        $source = (string) Str::uuid7();
        $created = $this->bootstrap($creator, $business, $source, (string) Str::uuid7());
        $selection = ['realm_kind' => 'direct', 'requirements' => [
            ['requirement_kind' => 'native_source', 'leaf_grant_id' => $created['grant_refs']['source.manage']],
        ]];
        if ($effect === 'qualify_issue') {
            $this->postJson('/api/data-rights/commands', ['command_id' => (string) Str::uuid7(), 'target_kind' => 'source',
                'target_ref' => $source, 'effect' => 'revoke', 'payload' => ['grant_id' => (string) $created['grant_refs']['source.read']],
                'expected_revision' => 1, 'authority_selection' => $selection])->assertOk();
        }
        $db = DB::connection('pgsql_migrations');
        $snapshot = fn () => array_map(fn ($table) => $db->scalar("SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]'::jsonb)::text FROM {$table} r"),
            ['security_action_grants', 'resource_right_fences', 'source_registry', 'source_capabilities',
                'source_command_receipts', 'source_versions', 'source_audit_obligations', 'source_audit_delivery', 'business_command_receipts']);
        $before = $snapshot();
        $run = 'source-final-'.$effect.'-'.Str::uuid7();
        $path = storage_path('framework/testing/'.$run.'.json');
        $input = ['run' => $run, 'ready' => $path.'.ready', 'release' => $path.'.release', 'operation' => 'resource',
            'actor' => $creator->id, 'kind' => 'source', 'target' => $source, 'effect' => $effect,
            'session' => SessionAuthority::sessionKey($this->request($creator)), 'command' => (string) Str::uuid7(),
            'payload' => $payload, 'expected' => $revision, 'selection' => $selection];
        file_put_contents($path, json_encode($input, JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $path], base_path());
        $worker->setTimeout(45);
        $evidence = [];
        try {
            $worker->start();
            $ready = $this->observeSourceWorker($worker, function () use ($input) {
                clearstatcache(true, $input['ready']);

                return is_file($input['ready']) ? json_decode(file_get_contents($input['ready']), true) : null;
            });
            $expiry = $db->scalar("UPDATE security_sessions SET authenticated_at=clock_timestamp()-interval '15 minutes'+interval '2 seconds'
                WHERE session_key_hash=? RETURNING (authenticated_at+interval '15 minutes')::text", [$input['session']]);
            self::assertNotNull($expiry);
            $db->beginTransaction();
            $blocker = $db->scalar('SELECT pg_backend_pid()');
            $db->statement('LOCK TABLE business_command_receipts IN SHARE MODE');
            file_put_contents($input['release'], 'go');
            $evidence['wait'] = $this->observeSourceWorker($worker, fn () => DB::selectOne(
                "SELECT a.pid,pg_blocking_pids(a.pid)::text AS blockers,l.mode FROM pg_stat_activity a JOIN pg_locks l ON l.pid=a.pid
                WHERE a.pid=? AND ?=ANY(pg_blocking_pids(a.pid)) AND l.relation='business_command_receipts'::regclass AND NOT l.granted",
                [$ready['pid'], $blocker],
            ));
            self::assertSame('RowExclusiveLock', $evidence['wait']->mode);
            $this->observeSourceWorker($worker, fn () => (bool) DB::scalar('SELECT clock_timestamp()>?::timestamptz', [$expiry]));
            $db->rollBack();
            $worker->wait();
            self::assertSame(2, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            self::assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
            self::assertSame($before, $snapshot());
        } finally {
            if ($db->transactionLevel()) {
                $db->rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $evidence['worker'] = ['exit' => $worker->getExitCode(), 'stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput()];
            file_put_contents($path.'.evidence.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            foreach ([$path, $input['ready'], $input['release']] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function test_source_authority_decision_replays_exact_issue_and_revoke_without_reviving_rights(): void
    {
        $creator = User::factory()->create();
        $business = $this->draft($creator, 'Source decision receipts');
        $other = $this->draft($creator, 'Other decision scope');
        $source = (string) Str::uuid7();
        $this->bootstrap($creator, $business, $source, (string) Str::uuid7());
        $path = '/businesses/'.$business['business_public_id'].'/source-grants';
        $decision = ['command_id' => (string) Str::uuid7(), 'source_id' => $source,
            'resource' => '123', 'operation' => 'report.read', 'purpose' => 'advertising_analysis',
            'reason' => 'owner_explicit_request', 'action' => 'source.read', 'decision' => 'revoke', 'expires_at' => null];
        $withdrawn = $this->postJson($path, $decision)->assertOk()->json('receipt');
        self::assertSame('revoked', $withdrawn['status']);
        $this->postJson($path, $decision)->assertOk()->assertJsonPath('receipt.grant_id', $withdrawn['grant_id']);
        $this->postJson($path, [...$decision, 'reason' => 'different_reason'])->assertConflict();
        $this->postJson('/businesses/'.$other['business_public_id'].'/source-grants', $decision)->assertForbidden();
        $issue = [...$decision, 'command_id' => (string) Str::uuid7(), 'decision' => 'authorize',
            'expires_at' => now()->addMinutes(20)->toIso8601String()];
        $issued = $this->postJson($path, $issue)->assertOk()->json('receipt');
        self::assertSame('active', $issued['status']);
        $this->postJson($path, $issue)->assertOk()->assertJsonPath('receipt.grant_id', $issued['grant_id']);
        $this->postJson($path, [...$issue, 'action' => 'source.register'])->assertConflict();
        self::assertSame('revoked', DB::connection('pgsql_migrations')->table('security_action_grants')->where('id', $withdrawn['grant_id'])->value('status'));
        self::assertSame(1, DB::connection('pgsql_migrations')->table('security_action_grants')->where('id', $issued['grant_id'])->where('status', 'active')->count());
    }

    public function test_withdrawal_of_last_source_manager_keeps_its_effect_and_denies_unqualified_receipt_read(): void
    {
        $creator = User::factory()->create();
        $business = $this->draft($creator, 'Last manager source');
        $source = (string) Str::uuid7();
        $created = $this->bootstrap($creator, $business, $source, (string) Str::uuid7());
        $decision = ['command_id' => (string) Str::uuid7(), 'source_id' => $source,
            'resource' => '123', 'operation' => 'report.read', 'purpose' => 'advertising_analysis',
            'reason' => 'owner_explicit_request', 'action' => 'source.manage', 'decision' => 'revoke', 'expires_at' => null];
        $path = '/businesses/'.$business['business_public_id'].'/source-grants';
        $this->postJson($path, $decision)->assertOk()->assertJsonPath('receipt.status', 'revoked');
        $this->postJson($path, $decision)->assertForbidden();
        $db = DB::connection('pgsql_migrations');
        self::assertSame('revoked', $db->table('security_action_grants')->where('id', $created['grant_refs']['source.manage'])->value('status'));
        self::assertSame(0, $db->table('security_action_grants')->where('source_target_id', $source)
            ->where('action', 'source.manage')->where('status', 'active')->count());
        self::assertSame(1, $db->table('business_command_receipts')->where('command_id', $decision['command_id'])->count());
    }

    public function test_business_readers_cannot_bootstrap_or_read_private_sources_and_owner_cannot_substitute_another_business(): void
    {
        $creator = User::factory()->create();
        $employee = User::factory()->create();
        $a = $this->draft($creator, 'Source A');
        $b = $this->draft($creator, 'Source B');
        $source = (string) Str::uuid7();
        $this->bootstrap($creator, $a, $source, (string) Str::uuid());
        $this->denied(fn () => $this->bootstrap($creator, $b, $source, (string) Str::uuid()));
        $businessId = DB::connection('pgsql_migrations')->table('businesses')->where('public_id', $a['business_public_id'])->value('id');
        $read = BusinessAuthorityFixture::root($businessId, $employee->id, 'business.context.read');
        $this->actingAs($employee)->getJson('/api/businesses/'.$a['business_public_id'].'/surface?access='.$read->id)->assertOk();
        $employeeBusiness = [...$a, 'grant_refs' => ['business.context.read' => (string) $read->id]];
        $this->denied(fn () => $this->bootstrap($employee, $employeeBusiness, (string) Str::uuid7(), (string) Str::uuid()));
        $this->signed($employee, function (Request $request) use ($businessId): void {
            DB::selectOne('SELECT builderad_source_begin_read(?,?)', [$businessId, SessionAuthority::sessionKey($request)]);
            foreach (['source_registry', 'source_capabilities', 'source_command_receipts', 'source_versions', 'source_audit_obligations', 'source_audit_delivery'] as $table) {
                self::assertSame(0, DB::table($table)->count(), $table.' private Source history must remain hidden');
            }
        });
        self::assertSame(1, DB::connection('pgsql_migrations')->table('source_registry')->count());
        self::assertNull(DB::connection('pgsql_migrations')->scalar("SELECT to_regclass('public.workspaces')"));
    }

    public function test_stale_authentication_and_foreign_command_reuse_leave_no_alternative_source(): void
    {
        $creator = User::factory()->create();
        $a = $this->draft($creator, 'First source command');
        $b = $this->draft($creator, 'Second source command');
        $source = (string) Str::uuid7();
        $command = (string) Str::uuid();
        $this->bootstrap($creator, $a, $source, $command);
        $this->denied(fn () => $this->bootstrap($creator, $b, (string) Str::uuid7(), $command), '40001');
        DB::connection('pgsql_migrations')->table('security_sessions')->where('user_id', $creator->id)->update(['authenticated_at' => now()->subMinutes(16)]);
        $this->denied(fn () => $this->bootstrap($creator, $a, (string) Str::uuid7(), (string) Str::uuid(), $this->payload('456')));
        self::assertSame(1, DB::connection('pgsql_migrations')->table('source_registry')->count());
        self::assertSame(7, DB::connection('pgsql_migrations')->table('security_action_grants')->where('scope_kind', 'source')->count());
    }

    public function test_withdrawing_original_basis_denies_source_read_and_preserves_qualified_history(): void
    {
        $creator = User::factory()->create();
        $business = $this->draft($creator, 'Source withdrawal');
        $source = (string) Str::uuid7();
        $this->bootstrap($creator, $business, $source, (string) Str::uuid());
        $owner = DB::connection('pgsql_migrations');
        $qualification = $owner->table('security_action_grants')->where('id', $business['grant_refs']['business.context.read'])->value('qualification_id');
        BusinessAuthorityFixture::revokeQualification($qualification, $creator->id);
        $businessId = $owner->table('businesses')->where('public_id', $business['business_public_id'])->value('id');
        $this->denied(fn () => $this->signed($creator, fn (Request $request) => DB::selectOne(
            'SELECT builderad_source_read_scope(?,?,?::uuid,?,?,?)', [$businessId, SessionAuthority::sessionKey($request), $source, '123', 'report.read', 'advertising_analysis'])));
        self::assertSame(1, $owner->table('source_versions')->count());
        self::assertSame(1, $owner->table('source_audit_obligations')->count());
        self::assertNull($owner->scalar("SELECT to_regclass('public.workspaces')"));
    }

    public function test_business_source_administration_reuses_exact_native_rights_and_independent_audit_without_workspace(): void
    {
        $creator = User::factory()->create();
        $business = $this->draft($creator, 'Direct source administration');
        $other = $this->draft($creator, 'Other Business');
        $source = (string) Str::uuid7();
        $created = $this->bootstrap($creator, $business, $source, (string) Str::uuid());
        $manager = $created['grant_refs']['source.manage'];
        $reader = $created['grant_refs']['provider.read'];
        $selection = ['realm_kind' => 'direct',
            'requirements' => [['requirement_kind' => 'native_source', 'leaf_grant_id' => (int) $manager]]];
        $this->getJson('/api/source-rights')->assertOk()->assertJsonPath('data.sources.0.source_id', $source);
        $this->getJson('/api/source-rights/'.$source.'?access='.$manager)->assertOk()->assertJsonPath('data.source.adopted', true)->assertJsonCount(7, 'data.rights');
        $this->getJson('/api/source-rights/'.$source.'?access='.$business['grant_refs']['business.context.read'])->assertForbidden();
        $command = fn (string $effect, array $payload, int $version) => ['command_id' => (string) Str::uuid(),
            'target_kind' => 'source', 'target_ref' => $source, 'effect' => $effect, 'payload' => $payload,
            'expected_revision' => $version, 'authority_selection' => $selection];
        $rename = $command('rename', ['label' => 'Renamed direct source', 'reason' => 'explicit_source_rename'], 1);
        $renameReceipt = $this->postJson('/api/data-rights/commands', $rename)->assertOk()->assertJsonPath('data.versions.source', 2)->json();
        $this->assertApplicationContract('BusinessResourceRightResult', $renameReceipt);
        $this->postJson('/api/data-rights/commands', $rename)->assertOk()->assertExactJson($renameReceipt);
        $this->postJson('/api/data-rights/commands', [...$rename, 'payload' => [...$rename['payload'], 'label' => 'Conflicting rename']])->assertConflict();
        $this->postJson('/api/data-rights/commands', [...$rename, 'command_id' => (string) Str::uuid7()])->assertConflict();
        $revoke = $command('revoke', ['grant_id' => (string) $reader], 1);
        $this->postJson('/api/data-rights/commands', $revoke)->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->postJson('/api/data-rights/commands', $revoke)->assertOk()->assertJsonPath('data.versions.grant', 2);
        $issue = $command('qualify_issue', ['action' => 'provider.read', 'expires_at' => null, 'reason' => 'explicit_source_reissue'], 0);
        $new = $this->postJson('/api/data-rights/commands', $issue)->assertOk()->json('data.grant_id');
        self::assertNotSame((string) $reader, (string) $new);
        $this->postJson('/api/data-rights/commands', $issue)->assertOk()->assertJsonPath('data.grant_id', $new);
        $owner = DB::connection('pgsql_migrations');
        self::assertSame($owner->table('security_action_grants')->where('id', $manager)->value('qualification_id'), $owner->table('security_action_grants')->where('id', $new)->value('qualification_id'));
        $obligation = $created['native_receipt']['obligation_id'];
        $recover = '/businesses/'.$business['business_public_id'].'/source-audit/'.$obligation.'/recover';
        $this->postJson('/businesses/'.$other['business_public_id'].'/source-audit/'.$obligation.'/recover')->assertForbidden();
        self::assertSame(0, $owner->table('security_source_audit_custody')->count());
        $lost = new class extends SourceAuditCustody
        {
            public bool $first = true;

            public function accept(string $obligation, string $fingerprint, string $envelope): string
            {
                $ack = parent::accept($obligation, $fingerprint, $envelope);
                if ($this->first) {
                    $this->first = false;
                    throw new \RuntimeException('Synthetic lost independent audit acknowledgment');
                }

                return $ack;
            }
        };
        $this->app->instance(SourceAuditCustody::class, $lost);
        $this->postJson($recover)->assertOk()->assertJsonPath('delivery_state', 'FAILED');
        $this->postJson($recover)->assertOk()->assertJsonPath('delivery_state', 'ACKNOWLEDGED');
        $this->postJson($recover)->assertOk()->assertJsonPath('delivery_state', 'ACKNOWLEDGED');
        self::assertSame(1, $owner->table('security_source_audit_custody')->count());
        self::assertSame(2, $owner->table('source_audit_delivery')->where('obligation_id', $obligation)->value('attempts'));
        $this->denied(fn () => $this->signed($creator,
            fn () => DB::table('source_audit_delivery')->where('obligation_id', $obligation)->update(['state' => 'PENDING'])));
        $retire = $command('retire', ['reason' => 'explicit_source_retirement'], 2);
        $this->postJson('/api/data-rights/commands', $retire)->assertOk()->assertJsonPath('data.status', 'REVOKED');
        $this->postJson('/api/data-rights/commands', $retire)->assertOk()->assertJsonPath('data.versions.source', 3);
        $this->getJson('/api/source-rights/'.$source.'?access='.$manager)->assertOk()->assertJsonPath('data.source.status', 'REVOKED');
        self::assertSame(3, $owner->table('source_versions')->count());
        self::assertNull($owner->scalar("SELECT to_regclass('public.workspaces')"));
        self::assertSame(0, $owner->table('business_data_use_bindings')->count());
    }

    public static function nativeProviders(): array
    {
        return array_map(fn (Provider $provider) => [$provider], Provider::cases());
    }

    public static function otherNativeProviders(): array
    {
        return [[Provider::Google], [Provider::TikTok], [Provider::Snapchat], [Provider::X]];
    }

    #[DataProvider('otherNativeProviders')]
    public function test_other_native_http_flows_bind_business_discovery_selection_and_replay_without_workspace(Provider $provider): void
    {
        $creator = User::factory()->create();
        $business = $this->draft($creator, 'Direct '.$provider->value.' connection');
        $other = $this->draft($creator, 'Different direct connection');
        config()->set('app.url', 'https://builderad.example');
        $settings = match ($provider) {
            Provider::Google => ['version' => 'v25', 'client_id' => 'synthetic.apps.googleusercontent.com', 'client_secret' => 'synthetic-secret'],
            Provider::TikTok => ['version' => 'v1.3', 'app_id' => '5555555555555555555', 'app_secret' => 'synthetic-secret', 'authorization_url' => 'https://business-api.tiktok.com/portal/auth'],
            Provider::Snapchat => ['client_id' => 'synthetic-snap-client', 'client_secret' => 'synthetic-secret'],
            Provider::X => ['consumer_key' => 'synthetic-x-key', 'consumer_secret' => 'synthetic-secret'],
            default => throw new \LogicException('Separate Meta flow fixture'),
        };
        config()->set('services.'.$provider->value.'_ads', $settings + [
            'redirect_uri' => 'https://builderad.example/providers/'.$provider->value.'/callback',
            'privacy_url' => 'https://builderad.example/privacy', 'notice_revision' => 'synthetic:1',
        ]);
        $http = new Factory;
        $http->preventStrayRequests();
        $snapAccount = ['id' => '8b8e40af-fc64-455d-925b-ca80f7af6914', 'name' => 'Synthetic Snapchat advertiser',
            'currency' => 'USD', 'timezone' => 'America/Los_Angeles', 'roles' => ['reports']];
        $replies = match ($provider) {
            Provider::Google => [
                ['access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh', 'scope' => GoogleOAuthClient::SCOPE, 'token_type' => 'Bearer', 'expires_in' => 600],
                ['resourceNames' => ['customers/1234567890']],
                ['fieldMask' => 'customer.id,customer.manager,customer.currencyCode,customer.timeZone', 'results' => [['customer' => ['id' => '1234567890', 'manager' => false, 'currencyCode' => 'JOD', 'timeZone' => 'Asia/Amman']]]],
                ['fieldMask' => 'campaign.id', 'results' => []],
            ],
            Provider::TikTok => [
                ['code' => 0, 'data' => ['access_token' => 'synthetic-access']],
                ['code' => 0, 'data' => ['list' => [['advertiser_id' => '1234567890123456789', 'advertiser_name' => 'Synthetic TikTok']]]],
                ['code' => 0, 'data' => ['list' => [['advertiser_id' => '1234567890123456789', 'currency' => 'JOD', 'timezone' => 'Asia/Amman']]]],
                ['code' => 0, 'data' => ['list' => []]],
            ],
            Provider::Snapchat => [
                ['access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh', 'expires_in' => 300, 'token_type' => 'Bearer'],
                ['request_status' => 'SUCCESS', 'organizations' => [['sub_request_status' => 'SUCCESS', 'organization' => ['ad_accounts' => [$snapAccount]]]]],
                ['request_status' => 'SUCCESS', 'adaccounts' => [['sub_request_status' => 'success', 'adaccount' => $snapAccount]]],
                ['request_status' => 'SUCCESS', 'total_stats' => [['sub_request_status' => 'SUCCESS', 'total_stat' => ['id' => $snapAccount['id'], 'type' => 'AD_ACCOUNT', 'granularity' => 'TOTAL', 'stats' => ['impressions' => 0]]]]],
            ],
            Provider::X => [
                'oauth_token=synthetic-request&oauth_token_secret=synthetic-temporary-secret&oauth_callback_confirmed=true',
                'oauth_token=synthetic-user-token&oauth_token_secret=synthetic-user-secret',
                ['request' => ['params' => ['count' => 200]], 'next_cursor' => null, 'data' => [['id' => 'abc123', 'name' => 'Synthetic X advertiser', 'deleted' => false]]],
                ['request' => ['params' => ['account_id' => 'abc123']], 'data' => ['id' => 'abc123', 'deleted' => false, 'timezone' => 'Asia/Amman', 'timezone_switch_at' => '2026-01-01T21:00:00Z', 'approval_status' => 'PENDING']],
                ['request' => ['params' => ['account_id' => 'abc123']], 'data' => ['permissions' => ['CAMPAIGN_ANALYST']]],
            ],
            default => [],
        };
        $sequence = $http->sequence();
        foreach ($replies as $reply) {
            $sequence->push($reply);
        }
        $http->fake(['*' => $sequence]);
        $this->app->instance(Factory::class, $http);
        $base = '/businesses/'.$business['business_public_id'].'/providers/'.$provider->value;
        $otherBase = '/businesses/'.$other['business_public_id'].'/providers/'.$provider->value;
        $intent = $this->getJson($base)->assertOk()->assertJsonPath('availability', 'CONNECT_AVAILABLE')->json('connect_intent');
        $this->postJson($otherBase.'/connect', ['intent' => $intent])->assertForbidden();
        $url = $this->postJson($base.'/connect', ['intent' => $intent])->assertOk()->json('authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $callbackFields = $provider === Provider::X ? ['oauth_token' => $query['oauth_token'], 'oauth_verifier' => 'synthetic-verifier']
            : ['state' => $query['state'], $provider === Provider::TikTok ? 'auth_code' : 'code' => 'synthetic-code'];
        $callbackUrl = '/providers/'.$provider->value.'/callback?'.http_build_query($callbackFields);
        $handle = $this->callbackLanding($callbackUrl, $business, $provider);
        $this->getJson($callbackUrl)->assertForbidden();
        $page = $this->getJson($base.'/discovery/'.$handle)->assertOk();
        $choice = $page->json('accounts.0.selection_handle');
        $this->postJson($otherBase.'/select', ['selection' => $choice])->assertForbidden();
        $selected = $this->postJson($base.'/select', ['selection' => $choice])->assertOk()->assertJsonPath('receipt.version', 1);
        $this->postJson($base.'/select', ['selection' => $choice])->assertOk()->assertJsonPath('receipt', $selected->json('receipt'));
        $http->assertSentCount(count($replies));
        $this->getJson($base)->assertOk()->assertJsonCount(1, 'connections')->assertJsonPath('connections.0.access.axes.privacy_purpose', 'UNKNOWN');
        $this->getJson($otherBase)->assertOk()->assertJsonPath('connections', []);
        config()->set('services.'.$provider->value.'_ads', []);
        $command = ['command_id' => (string) Str::uuid(), 'expected_version' => 1];
        $disconnect = $base.'/'.$selected->json('source_id').'/disconnect';
        $this->postJson($disconnect, $command)->assertOk()->assertJsonPath('access.connection', 'LOCALLY_DISCONNECTED');
        $this->postJson($disconnect, $command)->assertOk()->assertJsonPath('receipt.version', 2);
        $http->assertSentCount(count($replies));
        foreach ([$page, $selected] as $response) {
            foreach (['synthetic-access', 'synthetic-refresh', 'synthetic-secret', 'synthetic-user-secret', 'synthetic-code', 'synthetic-verifier'] as $secret) {
                self::assertStringNotContainsString($secret, $response->getContent());
            }
        }
        self::assertNull(DB::connection('pgsql_migrations')->scalar("SELECT to_regclass('public.workspaces')"));
        self::assertSame(0, DB::connection('pgsql_migrations')->table('business_data_use_bindings')->count());
    }

    #[DataProvider('nativeProviders')]
    public function test_native_connection_surface_opens_in_its_business_without_workspace(Provider $provider): void
    {
        $creator = User::factory()->create();
        $business = $this->draft($creator, 'Independent connection surface');
        config()->set('services.'.$provider->value.'_ads', []);
        $this->getJson('/businesses/'.$business['business_public_id'].'/providers/'.$provider->value)
            ->assertOk()->assertJsonPath('connections', [])->assertJsonPath('availability', 'CONFIGURATION_REQUIRED');
        self::assertNull(DB::connection('pgsql_migrations')->scalar("SELECT to_regclass('public.workspaces')"));
        self::assertSame(0, DB::connection('pgsql_migrations')->table('source_registry')->count());
        $other = User::factory()->create();
        $this->actingAs($other)->getJson('/businesses/'.$business['business_public_id'].'/providers/'.$provider->value)->assertForbidden();
    }

    public function test_business_meta_http_flow_preserves_exact_custody_callback_replay_and_local_disconnect_without_workspace(): void
    {
        $creator = User::factory()->create();
        $business = $this->draft($creator, 'Independent Meta connection');
        $other = $this->draft($creator, 'Separate Business');
        config()->set('app.url', 'https://builderad.example');
        config()->set('services.meta_ads', ['version' => 'v26.0', 'app_id' => '777', 'app_secret' => 'synthetic-app-secret',
            'redirect_uri' => 'https://builderad.example/providers/meta/callback', 'configuration_id' => '999',
            'token_kind' => 'BUSINESS_INTEGRATION_SYSTEM_USER', 'privacy_url' => 'https://builderad.example/privacy',
            'deletion_instructions_url' => 'https://builderad.example/data-deletion', 'notice_revision' => 'synthetic-notice-v1']);
        $http = new Factory;
        $http->preventStrayRequests();
        $debug = ['data' => ['is_valid' => true, 'type' => 'SYSTEM_USER', 'app_id' => '777', 'user_id' => '888',
            'scopes' => ['ads_read'], 'expires_at' => 0, 'granular_scopes' => [['scope' => 'ads_read', 'target_ids' => ['123']]]]];
        $nativeBusiness = ['id' => '888', 'client_business_id' => '555'];
        $http->fake(['*' => $http->sequence()->push(['access_token' => 'synthetic-web-token'])->push($debug)->push($nativeBusiness)
            ->push($debug)->push($nativeBusiness)->push(['id' => 'act_123', 'account_id' => '123', 'name' => 'Synthetic granted account'])
            ->push($debug)->push($nativeBusiness)->push(['id' => 'act_123', 'account_id' => '123', 'currency' => 'USD', 'timezone_name' => 'UTC'])
            ->push(['data' => []])]);
        $this->app->instance(Factory::class, $http);
        $base = '/businesses/'.$business['business_public_id'].'/providers/meta';
        $otherBase = '/businesses/'.$other['business_public_id'].'/providers/meta';
        $intent = $this->getJson($base)->assertOk()->assertJsonPath('availability', 'CONNECT_AVAILABLE')->json('connect_intent');
        $this->postJson($otherBase.'/connect', ['intent' => $intent])->assertForbidden();
        $url = $this->postJson($base.'/connect', ['intent' => $intent])->assertOk()->json('authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $http->assertSentCount(0);
        $handle = $this->callbackLanding('/providers/meta/callback?'.http_build_query(['state' => $query['state'], 'code' => 'synthetic-private-code']), $business, Provider::Meta);
        $http->assertSentCount(3);
        $this->getJson('/providers/meta/callback?'.http_build_query(['state' => $query['state'], 'code' => 'replay']))->assertForbidden();
        $page = $this->getJson($base.'/discovery/'.$handle)->assertOk();
        $choice = $page->json('accounts.0.selection_handle');
        $this->postJson($otherBase.'/select', ['selection' => $choice])->assertForbidden();
        $selection = $this->postJson($base.'/select', ['selection' => $choice])->assertOk()
            ->assertJsonPath('receipt.version', 1)->assertJsonPath('access.axes.privacy_purpose', 'UNKNOWN');
        $this->postJson($base.'/select', ['selection' => $choice])->assertOk()->assertJsonPath('receipt', $selection->json('receipt'));
        $http->assertSentCount(10);
        $this->getJson($otherBase)->assertOk()->assertJsonPath('connections', []);
        config()->set('services.meta_ads', []);
        $command = ['command_id' => (string) Str::uuid(), 'expected_version' => 1];
        $disconnect = $base.'/'.$selection->json('source_id').'/disconnect';
        $this->postJson($disconnect, $command)->assertOk()->assertJsonPath('access.connection', 'LOCALLY_DISCONNECTED');
        $this->postJson($disconnect, $command)->assertOk()->assertJsonPath('receipt.version', 2);
        $http->assertSentCount(10);
        $owner = DB::connection('pgsql_migrations');
        self::assertNull($owner->scalar("SELECT to_regclass('public.workspaces')"));
        self::assertSame(0, $owner->table('business_data_use_bindings')->count());
        self::assertSame(2, $owner->table('source_registry')->where('custody_kind', 'business_native')->count());
        self::assertSame(1, $owner->table('provider_access')->count());
        self::assertStringNotContainsString('synthetic-web-token', $selection->getContent());
    }

    #[DataProvider('nativeProviders')]
    public function test_all_native_provider_stores_and_journals_preserve_business_custody_and_reject_cross_business_scope(Provider $provider): void
    {
        $creator = User::factory()->create();
        $business = $this->draft($creator, 'Native provider '.$provider->value);
        $other = $this->draft($creator, 'Separate native provider Business');
        $owner = DB::connection('pgsql_migrations');
        $businessId = $owner->table('businesses')->where('public_id', $business['business_public_id'])->value('id');
        $otherId = $owner->table('businesses')->where('public_id', $other['business_public_id'])->value('id');
        $source = (string) Str::uuid7();
        $payload = [...$this->payload(), 'family' => $provider->value];
        $this->bootstrap($creator, $business, $source, (string) Str::uuid(), $payload);
        $scope = ProviderScope::forBusiness($businessId, $source, $provider, '123', 'report.read', 'advertising_analysis');
        $wrong = ProviderScope::forBusiness($otherId, $source, $provider, '123', 'report.read', 'advertising_analysis');
        // Entirely synthetic verifier assertions, matching the existing internal
        // custody tests. They establish no actual provider approval or data use.
        $verification = array_fill_keys(EffectiveCapability::AXES, ['state' => 'VERIFIED', 'provenance' => 'synthetic:business-native-verifier',
            'verified_at' => '2026-01-01T00:00:00Z', 'valid_until' => '2099-01-01T00:00:00Z']);
        $command = (string) Str::uuid();
        $store = fn (ProviderScope $target) => $this->signed($creator, fn (Request $request) => app(ProviderAccessStore::class)->store(
            $request, $target, $command, 0, new ProviderCredentials(['access_token' => 'synthetic-business-secret']), null, 'SYNTHETIC_AUTHORIZED', $verification));
        $this->denied(fn () => $store($wrong));
        self::assertSame(0, $owner->table('provider_access')->count());
        self::assertSame(['version' => 1, 'local_state' => 'CREDENTIAL_STORED'], $store($scope));
        self::assertSame(['version' => 1, 'local_state' => 'CREDENTIAL_STORED'], $store($scope));
        self::assertSame(1, $owner->table('provider_access_events')->count());
        $this->denied(fn () => $this->signed($creator, fn (Request $request) => app(ProviderAccessStore::class)->credentials($request, $wrong)));
        self::assertSame('synthetic-business-secret', $this->signed($creator,
            fn (Request $request) => app(ProviderAccessStore::class)->credentials($request, $scope)->value('access_token')));
        $provenance = ['api_version' => 'synthetic-v1', 'report' => 'campaign-period', 'documentation_basis' => 'synthetic:report-contract'];
        $observation = new AdObservation($scope, 'campaign-1',
            new ReportWindow('2026-10-01', '2026-10-31', 'INCLUSIVE_DATES', 'TOTAL', 'UTC', 'synthetic:account-civil'),
            new NativeAmount('12', 'SAR', 'synthetic:expenditure', 0, 'synthetic:documented-scale'),
            ['impressions' => ['value' => '10', 'unit' => 'native_count', 'meaning' => 'Provider assertion', 'basis' => 'PROVIDER_REPORTED']],
            $provenance, new DateTimeImmutable('-1 second'), null);
        $batch = new ObservationBatch($scope, [$observation], 'COMPLETE', null, null);
        $observationCommand = (string) Str::uuid();
        $accepted = $this->signed($creator, fn (Request $request) => app(ObservationJournal::class)->accept(
            $request, $batch, 'synthetic-business-report', $observationCommand, 0, 1, $provenance));
        self::assertSame(1, $accepted['generation']);
        $snapshot = $this->signed($creator, fn (Request $request) => app(ObservationJournal::class)->snapshot($request, $scope, 'synthetic-business-report'));
        self::assertSame($scope->jsonSerialize(), $snapshot['scope']);
        self::assertCount(1, $snapshot['claims']);
        $this->denied(fn () => $this->signed($creator, fn (Request $request) => app(ObservationJournal::class)->snapshot($request, $wrong, 'synthetic-business-report')));
        $this->denied(fn () => $this->signed($creator, fn () => DB::table('provider_access')->value('encrypted_credentials')));
        $request = $this->request($creator);
        $job = app(NativeSourceContext::class)->runForReference($request,
            ['scope' => $scope, 'custody_kind' => 'business_native', 'business' => $business['business_public_id']],
            fn () => PersistObservationJob::capture($request, $batch, 'synthetic-queued-report', (string) Str::uuid(), 0, 1, $provenance));
        $job = unserialize(serialize($job));
        $job->handle(app(PrincipalContext::class), app(ObservationJournal::class));
        $job->handle(app(PrincipalContext::class), app(ObservationJournal::class));
        self::assertSame(2, $owner->table('provider_observation_runs')->count(), 'One direct and one queued generation; replay cannot duplicate acceptance');
        $this->signed($creator, function (Request $request) use ($scope): void {
            $snapshot = app(ObservationJournal::class)->snapshot($request, $scope, 'synthetic-queued-report');
            self::assertCount(1, $snapshot['claims']);
            self::assertSame($scope->jsonSerialize(), $snapshot['scope']);
        });
        $basis = (int) $business['grant_refs']['business.context.read'];
        $qualification = $owner->table('security_action_grants')->where('id', $basis)->value('qualification_id');
        BusinessAuthorityFixture::revokeQualification($qualification, $creator->id);
        $this->denied(fn () => $job->handle(app(PrincipalContext::class), app(ObservationJournal::class)));
        self::assertSame(2, $owner->table('provider_observation_runs')->count(), 'Withdrawn source basis must deny queued replay before publication');
        self::assertNull($owner->scalar("SELECT to_regclass('public.workspaces')"));
        self::assertSame(0, $owner->table('business_data_use_bindings')->count());
    }
}
