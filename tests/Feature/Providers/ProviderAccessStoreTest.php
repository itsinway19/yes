<?php

namespace Tests\Feature\Providers;

use App\Foundation\Tenancy\PrincipalContext;
use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\User;
use App\Models\Workspace;
use App\Providers\Ads\AdObservation;
use App\Providers\Ads\Google\GoogleAccessAdapter;
use App\Providers\Ads\Google\GoogleAccessStatus;
use App\Providers\Ads\Google\GoogleAuthorizationCoordinator;
use App\Providers\Ads\Google\GoogleFlowState;
use App\Providers\Ads\Google\GoogleOAuthClient;
use App\Providers\Ads\Google\GoogleOAuthState;
use App\Providers\Ads\Google\GoogleObservationReader;
use App\Providers\Ads\Google\GooglePendingAccess;
use App\Providers\Ads\Google\GoogleReadJob;
use App\Providers\Ads\Google\GoogleRequestFailed;
use App\Providers\Ads\Meta\MetaAccessAdapter;
use App\Providers\Ads\Meta\MetaAccessStatus;
use App\Providers\Ads\Meta\MetaAsyncAcquisition;
use App\Providers\Ads\Meta\MetaAuthorizationCoordinator;
use App\Providers\Ads\Meta\MetaLoginBootstrap;
use App\Providers\Ads\Meta\MetaOAuthState;
use App\Providers\Ads\Meta\MetaObservationReader;
use App\Providers\Ads\Meta\MetaPendingAccess;
use App\Providers\Ads\Meta\MetaReadCooldown;
use App\Providers\Ads\Meta\MetaReadExecution;
use App\Providers\Ads\Meta\MetaReadJob;
use App\Providers\Ads\Meta\MetaReportTask;
use App\Providers\Ads\Meta\MetaRequestFailed;
use App\Providers\Ads\NativeAmount;
use App\Providers\Ads\NativeProgress;
use App\Providers\Ads\ObservationBatch;
use App\Providers\Ads\ObservationJournal;
use App\Providers\Ads\PersistObservationJob;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderAccessMaterial;
use App\Providers\Ads\ProviderAccessStore;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderReportingEvidence;
use App\Providers\Ads\ProviderRequestGuard;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReportWindow;
use App\Providers\Ads\Snapchat\SnapchatAccessAdapter;
use App\Providers\Ads\Snapchat\SnapchatAccessStatus;
use App\Providers\Ads\Snapchat\SnapchatAuthorizationCoordinator;
use App\Providers\Ads\Snapchat\SnapchatClient;
use App\Providers\Ads\Snapchat\SnapchatOAuthClient;
use App\Providers\Ads\Snapchat\SnapchatOAuthState;
use App\Providers\Ads\Snapchat\SnapchatObservationReader;
use App\Providers\Ads\Snapchat\SnapchatPendingAccess;
use App\Providers\Ads\Snapchat\SnapchatReadJob;
use App\Providers\Ads\Snapchat\SnapchatRequestFailed;
use App\Providers\Ads\TikTok\TikTokAccessStatus;
use App\Providers\Ads\TikTok\TikTokReadJob;
use App\Providers\Ads\X\XAccessStatus;
use App\Providers\Ads\X\XAccountDiscovery;
use App\Providers\Ads\X\XAsyncAcquisition;
use App\Providers\Ads\X\XAsyncReport;
use App\Providers\Ads\X\XAuthorizationCoordinator;
use App\Providers\Ads\X\XCampaignReport;
use App\Providers\Ads\X\XDiscoveryContinuation;
use App\Providers\Ads\X\XOAuthClient;
use App\Providers\Ads\X\XOAuthSigner;
use App\Providers\Ads\X\XOAuthState;
use App\Providers\Ads\X\XObservationReader;
use App\Providers\Ads\X\XPendingAccess;
use App\Providers\Ads\X\XReadExecution;
use App\Providers\Ads\X\XReadJob;
use App\Providers\Ads\X\XRequestFailed;
use App\Providers\Ads\X\XSourceBootstrap;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use App\Sources\EffectiveCapability;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Dotenv\Dotenv;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProviderAccessStoreTest extends TestCase
{
    private User $actor;

    private int $workspaceId;

    private string $workspace;

    private array $scopes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create();
        $this->workspaceId = $this->owner()->table('workspaces')->insertGetId(['name' => 'Synthetic BB2 workspace',
            'slug' => 'bb2-'.Str::uuid(), 'status' => 'active', 'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now()]);
        $this->workspace = $this->owner()->table('workspaces')->where('id', $this->workspaceId)->value('public_id');
        $this->owner()->table('workspace_memberships')->insert(['workspace_id' => $this->workspaceId, 'user_id' => $this->actor->id,
            'role' => 'owner', 'status' => 'active', 'created_at' => now()]);
        $this->owner()->table('workspace_ownerships')->insert(['workspace_id' => $this->workspaceId, 'responsible_owner_user_id' => $this->actor->id,
            'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->owner()->table('security_action_grants')->insert(['subject_user_id' => $this->actor->id, 'source' => 'system_workspace_creation',
            'scope_kind' => 'workspace', 'workspace_id' => $this->workspaceId, 'action' => 'workspace.view', 'subject_generation' => 1,
            'status' => 'active', 'reason' => 'synthetic_fixture', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->actor)->withCredentials();
        foreach (Provider::cases() as $provider) {
            $scope = new ProviderScope($this->workspaceId, (string) Str::uuid7(), $provider, 'synthetic-account', 'report.read', 'advertising_analysis');
            $this->scopes[$provider->value] = $scope;
            foreach (GrantAuthority::SOURCE_ACTIONS as $action) {
                $this->grant($scope, $action);
            }
            $this->postJson('/workspaces/'.$this->workspace.'/sources', ['source_id' => $scope->sourceId, 'command_id' => (string) Str::uuid(),
                'effect' => 'register', 'family' => $provider->value, 'namespace' => 'ad_account', 'external_id' => $scope->accountId,
                'label' => 'Synthetic '.$provider->value, 'resource' => $scope->accountId, 'operation' => $scope->operation,
                'purpose' => $scope->purpose, 'expected_version' => 0, 'reason' => 'synthetic_registration'])->assertOk();
        }
    }

    public function test_encrypted_custody_replay_conflict_and_independent_local_revocation(): void
    {
        $scope = $this->scopes['meta'];
        $command = (string) Str::uuid();
        $first = $this->store($scope, $command);
        $this->assertSame(['version' => 1, 'local_state' => 'CREDENTIAL_STORED'], $first);
        $this->assertSame($first, $this->store($scope, $command));
        $this->assertSame(1, $this->owner()->table('provider_access_events')->count());
        $this->assertSame(1, $this->owner()->table('provider_access_events')->value('actor_generation'));
        $cipher = $this->owner()->table('provider_access')->value('encrypted_credentials');
        $this->assertStringNotContainsString('synthetic-secret-token', $cipher);
        $this->assertSame('synthetic-secret-token', json_decode(Crypt::decryptString($cipher), true)['credentials']['access_token']);
        $this->denied(fn () => $this->store($scope, $command, token: 'changed-token'), '23505');
        $this->assertStringNotContainsString('synthetic-secret-token', $this->owner()->table('provider_access_receipts')->value('result'));
        $this->assertSame('synthetic-secret-token', $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->credentials($r, $scope))->value('access_token'));
        $revoke = (string) Str::uuid();
        $result = $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->revoke($r, $scope, $revoke, 1));
        $this->assertSame('REVOKED', $result['local_state']);
        $this->assertNull($this->owner()->table('provider_access')->value('encrypted_credentials'));
        $this->assertSame('SYNTHETIC_AUTHORIZED', $this->owner()->table('provider_access')->value('native_state'));
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->credentials($r, $scope)), '42501');
    }

    public function test_provider_geography_never_populates_or_overwrites_declared_markets(): void
    {
        $snapshot = fn () => [(array) $this->owner()->table('workspaces')->find($this->workspaceId),
            $this->owner()->table('business_target_markets')->where('workspace_id', $this->workspaceId)->orderBy('country_code')->get()->toArray(),
            $this->owner()->table('business_target_market_events')->where('workspace_id', $this->workspaceId)->orderBy('id')->get()->toArray()];
        $acceptGeography = function (ProviderScope $scope, int $generation, string $country): void {
            $native = $this->journalObservation($scope, '10.01');
            $observation = new AdObservation($scope, $native->campaignId, $native->window, $native->spend,
                [...$native->nativeFacts, 'account_country' => ['value' => $country, 'unit' => 'country_code',
                    'meaning' => 'synthetic:provider-native-account-country', 'basis' => 'PROVIDER_REPORTED']],
                $native->provenance, $native->readAt, $native->freshUntil);
            $result = $this->withinWorkspace(fn ($request) => app(ObservationJournal::class)->accept($request,
                new ObservationBatch($scope, [$observation], 'COMPLETE', null, null), 'synthetic-geography',
                (string) Str::uuid(), $generation, 1, $native->provenance, null));
            $this->assertSame(1, $result[$generation === 0 ? 'first_observations' : 'restatements']);
        };
        $before = $snapshot();
        foreach ($this->scopes as $scope) {
            $this->withinWorkspace(fn ($request) => app(ProviderAccessStore::class)->store($request, $scope, (string) Str::uuid(), 0,
                new ProviderCredentials(['access_token' => 'synthetic-private-token', 'account_country' => 'JO']), null, 'SYNTHETIC_AUTHORIZED', $this->evidence()));
            $acceptGeography($scope, 0, 'JO');
            $this->assertEquals($before, $snapshot());
        }
        $this->post('/workspaces/'.$this->workspace.'/business/authority',
            ['decision' => 'authorize', 'reason' => 'complete_business_context'])->assertRedirect();
        $this->post('/workspaces/'.$this->workspace.'/business/target-markets', ['country_codes' => ['SA', 'KW'], 'version' => 0])->assertRedirect();
        $before = $snapshot();
        foreach ($this->scopes as $scope) {
            $acceptGeography($scope, 1, 'AE');
            $this->assertEquals($before, $snapshot());
        }
        $this->assertSame(10, $this->owner()->table('provider_observation_entries')->count());
        $providerTables = ['source_registry', 'provider_access', 'provider_access_events', 'provider_observation_entries', 'provider_observation_runs', 'provider_observation_streams'];
        $providerBefore = [];
        foreach ($providerTables as $table) {
            $providerBefore[$table] = $this->owner()->table($table)->get()->toArray();
        }
        $this->post('/workspaces/'.$this->workspace.'/business/target-markets', ['country_codes' => [], 'version' => 1])->assertRedirect();
        foreach ($providerTables as $table) {
            $this->assertEquals($providerBefore[$table], $this->owner()->table($table)->get()->toArray(), $table);
        }
        $this->assertSame([], $this->owner()->table('business_target_markets')->pluck('country_code')->all());
    }

    public function test_stored_tokens_do_not_bypass_current_permission_expiry_or_missing_evidence(): void
    {
        $scope = $this->scopes['meta'];
        $this->store($scope, (string) Str::uuid(), evidence: []);
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->credentials($r, $scope)), '42501');
        $this->owner()->table('provider_access')->update(['verification' => json_encode($this->evidence())]);
        $this->owner()->table('security_action_grants')->where('action', 'provider.read')->where('source_target_id', $scope->sourceId)
            ->update(['expires_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->credentials($r, $scope)), '42501');
        $this->owner()->table('security_action_grants')->where('action', 'provider.read')->update(['expires_at' => null]);
        $this->owner()->table('provider_access')->update(['credential_expires_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->credentials($r, $scope)), '42501');
    }

    public function test_every_provider_failure_and_rollback_leave_siblings_unchanged(): void
    {
        foreach ($this->scopes as $scope) {
            $this->store($scope, (string) Str::uuid());
        }
        foreach ($this->scopes as $failed) {
            $before = $this->owner()->table('provider_access')->orderBy('provider')->get()->toJson();
            try {
                $this->withinWorkspace(function ($r) use ($failed): void {
                    app(ProviderAccessStore::class)->revoke($r, $failed, (string) Str::uuid(), 1);
                    throw new \RuntimeException('Synthetic provider transaction failure.');
                });
                $this->fail('Synthetic failure was not raised.');
            } catch (\RuntimeException) {
                $this->assertSame($before, $this->owner()->table('provider_access')->orderBy('provider')->get()->toJson());
            }
            $this->assertSame(5, $this->owner()->table('provider_access_events')->count());
        }
        $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->revoke($r, $this->scopes['snapchat'], (string) Str::uuid(), 1));
        $this->assertSame(4, $this->owner()->table('provider_access')->where('local_state', 'CREDENTIAL_STORED')->count());
    }

    public function test_runtime_raw_secret_access_writes_wrong_resource_and_workspace_are_denied(): void
    {
        $scope = $this->scopes['meta'];
        $this->store($scope, (string) Str::uuid());
        $this->denied(fn () => $this->withinWorkspace(fn () => DB::table('provider_access')->value('encrypted_credentials')), '42501');
        $this->denied(fn () => $this->withinWorkspace(fn () => DB::table('provider_access')->update(['native_state' => 'FAKE'])), '42501');
        foreach ([new ProviderScope($this->workspaceId + 1, $scope->sourceId, $scope->provider, $scope->accountId, $scope->operation, $scope->purpose),
            new ProviderScope($scope->workspaceId, $scope->sourceId, Provider::Google, $scope->accountId, $scope->operation, $scope->purpose),
            new ProviderScope($scope->workspaceId, $scope->sourceId, $scope->provider, 'another-account', $scope->operation, $scope->purpose)] as $other) {
            $this->denied(fn () => $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->credentials($r, $other)), '42501');
        }
        $this->owner()->table('source_registry')->where('id', $scope->sourceId)->update(['status' => 'REVOKED']);
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->credentials($r, $scope)), '42501');
    }

    public function test_named_provider_grant_uses_existing_authority_writer_without_implicit_source_manage_authority(): void
    {
        $scope = $this->scopes['meta'];
        $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)->where('action', 'provider.connect')->delete();
        $this->denied(fn () => $this->store($scope, (string) Str::uuid()), '42501');
        $this->postJson('/workspaces/'.$this->workspace.'/source-grants', ['source_id' => $scope->sourceId, 'resource' => $scope->accountId,
            'operation' => $scope->operation, 'purpose' => $scope->purpose, 'action' => 'provider.connect',
            'decision' => 'authorize', 'reason' => 'synthetic_explicit_connection'])->assertOk();
        $this->assertSame('CREDENTIAL_STORED', $this->store($scope, (string) Str::uuid())['local_state']);
    }

    public function test_replay_rechecks_current_authority_and_reconnect_requires_current_version(): void
    {
        $scope = $this->scopes['meta'];
        $command = (string) Str::uuid();
        $this->store($scope, $command);
        $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)
            ->where('action', 'provider.connect')->update(['expires_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
        $this->denied(fn () => $this->store($scope, $command), '42501');
        $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)
            ->where('action', 'provider.connect')->update(['expires_at' => null]);
        $reconnect = fn (int $version) => $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->store($r, $scope,
            (string) Str::uuid(), $version, new ProviderCredentials(['access_token' => 'synthetic-new-token']), null,
            'SYNTHETIC_RECONNECTED', [], reconnect: true));
        $this->denied(fn () => $reconnect(0), '23505');
        $this->assertSame(2, $reconnect(1)['version']);
        $this->assertSame(2, $this->owner()->table('provider_access_events')->count());
        // Replacement credentials cannot inherit the old provider permission/freshness assertions.
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->credentials($r, $scope)), '42501');
    }

    public function test_ciphertext_from_a_sibling_scope_cannot_be_used_even_with_valid_local_evidence(): void
    {
        $this->store($this->scopes['meta'], (string) Str::uuid());
        $this->store($this->scopes['google'], (string) Str::uuid());
        $cipher = $this->owner()->table('provider_access')->where('provider', 'meta')->value('encrypted_credentials');
        $this->owner()->table('provider_access')->where('provider', 'google')->update(['encrypted_credentials' => $cipher]);
        $this->expectException(AuthorizationException::class);
        $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->credentials($r, $this->scopes['google']));
    }

    public function test_audit_insert_failure_rolls_back_credentials_and_receipt_without_touching_siblings(): void
    {
        $this->store($this->scopes['google'], (string) Str::uuid());
        $before = $this->owner()->table('provider_access')->get()->toJson();
        $this->owner()->unprepared("CREATE OR REPLACE FUNCTION bb2_synthetic_audit_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Synthetic audit failure' USING ERRCODE='P0001'; END $$;
            CREATE TRIGGER bb2_synthetic_audit_failure BEFORE INSERT ON provider_access_events FOR EACH ROW EXECUTE FUNCTION bb2_synthetic_audit_failure();");
        try {
            $this->denied(fn () => $this->store($this->scopes['meta'], (string) Str::uuid()), 'P0001');
        } finally {
            $this->owner()->unprepared('DROP TRIGGER bb2_synthetic_audit_failure ON provider_access_events; DROP FUNCTION bb2_synthetic_audit_failure();');
        }
        $this->assertSame($before, $this->owner()->table('provider_access')->get()->toJson());
        $this->assertSame(1, $this->owner()->table('provider_access_receipts')->count());
        $this->assertSame(1, $this->owner()->table('provider_access_events')->count());
    }

    public function test_permission_expiry_after_actual_credential_row_wait_denies_release(): void
    {
        $scope = $this->scopes['meta'];
        $this->store($scope, (string) Str::uuid());
        $pid = DB::scalar('SELECT pg_backend_pid()');
        $code = <<<'PHP'
        $db=new PDO('pgsql:host=127.0.0.1;port=5433;dbname=builderad_test_disposable',getenv('DB_OWNER_USERNAME'),getenv('DB_OWNER_PASSWORD'));
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $db->beginTransaction();
        $q=$db->prepare('SELECT source_id FROM provider_access WHERE source_id=?::uuid FOR UPDATE'); $q->execute([$argv[1]]);
        $xid=$db->query('SELECT pg_current_xact_id()::text')->fetchColumn(); echo 'LOCK_READY'; fflush(STDOUT);
        $q=$db->prepare("SELECT EXISTS(SELECT 1 FROM pg_locks WHERE locktype='transactionid' AND transactionid=?::xid AND pid=? AND NOT granted)");
        $deadline=microtime(true)+5; $wait=false;
        do { $q->execute([$xid,$argv[2]]); $wait=in_array($q->fetchColumn(),[true,1,'1','t'],true); if($wait) break; usleep(20000); } while(microtime(true)<$deadline);
        if(!$wait) { $db->rollBack(); exit(1); } echo 'WAIT_OBSERVED'; fflush(STDOUT);
        $q=$db->prepare("SELECT expires_at<clock_timestamp() FROM security_action_grants WHERE source_target_id=?::uuid AND action='provider.read'");
        $deadline=microtime(true)+3; $expired=false;
        do { $q->execute([$argv[1]]); $expired=in_array($q->fetchColumn(),[true,1,'1','t'],true); if($expired) break; usleep(20000); } while(microtime(true)<$deadline);
        if(!$expired) { $db->rollBack(); exit(2); } echo 'EXPIRED_BEFORE_RELEASE'; fflush(STDOUT); $db->commit();
        PHP;
        $holder = new Process([PHP_BINARY, '-r', $code, $scope->sourceId, (string) $pid], base_path());
        $holder->setTimeout(10);
        $readinessOutput = '';
        $lockReady = false;
        // Install before launch so status/pipe reads cannot lose an early marker.
        $holder->start(function (string $type, string $data) use (&$readinessOutput, &$lockReady): void {
            if ($type === Process::OUT) {
                $readinessOutput .= $data;
                $lockReady = str_contains($readinessOutput, 'LOCK_READY');
            }
        });
        try {
            while ($holder->isRunning()) {
                $holder->checkTimeout();
                if ($lockReady) {
                    break;
                }
                usleep(1000); // Scheduling yield only; never establishes readiness.
            }
            // A marker drained from an exited holder cannot authorize the waiter.
            $ready = $lockReady && $holder->isRunning();
            $this->assertTrue($ready,
                'Credential-row holder did not publish readiness; exit='.(string) $holder->getExitCode().'; stderr_bytes='.strlen($holder->getErrorOutput()));
            $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)
                ->where('action', 'provider.read')->update(['expires_at' => DB::raw("clock_timestamp()+interval '2 seconds'")]);
            $this->denied(fn () => $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->credentials($r, $scope)), '42501');
            $holder->wait();
            $this->assertTrue($holder->isSuccessful(), $holder->getErrorOutput());
            $this->assertStringContainsString('WAIT_OBSERVED', $holder->getOutput());
            $this->assertStringContainsString('EXPIRED_BEFORE_RELEASE', $holder->getOutput());
        } finally {
            if ($holder->isRunning()) {
                $holder->stop();
            }
        }
    }

    public function test_catalog_keeps_custody_tables_forced_rls_and_runtime_functions_narrow(): void
    {
        foreach (['provider_access', 'provider_access_receipts', 'provider_access_events',
            'provider_observation_streams', 'provider_observation_runs', 'provider_observation_entries', 'meta_read_attempts'] as $table) {
            $row = $this->owner()->selectOne('SELECT relrowsecurity,relforcerowsecurity FROM pg_class WHERE oid=?::regclass', [$table]);
            $this->assertTrue($row->relrowsecurity);
            $this->assertTrue($row->relforcerowsecurity);
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $action) {
                $this->assertFalse($this->owner()->scalar("SELECT has_table_privilege('builderad_app',?,?)", [$table, $action]));
                $this->assertFalse($this->owner()->scalar("SELECT has_table_privilege('builderad_authority',?,?)", [$table, $action]));
            }
        }
        $this->assertFalse($this->owner()->scalar("SELECT has_column_privilege('builderad_app','provider_access','encrypted_credentials','SELECT')"));
        $this->assertFalse($this->owner()->scalar("SELECT has_column_privilege('builderad_app','provider_observation_streams','encrypted_progress','SELECT')"));
        foreach (['builderad_app', 'builderad_authority'] as $role) {
            $this->assertFalse($this->owner()->scalar("SELECT has_column_privilege(?,'meta_read_attempts','encrypted_result','SELECT')", [$role]));
        }
        foreach (['builderad_provider_access_command', 'builderad_provider_credential', 'builderad_observation_accept', 'builderad_observation_progress', 'builderad_provider_request_gate', 'builderad_meta_attempt_begin', 'builderad_meta_attempt_finish'] as $function) {
            $row = $this->owner()->selectOne("SELECT p.prosecdef,p.proconfig,has_function_privilege('builderad_app',p.oid,'EXECUTE') AS runtime_execute,
                has_function_privilege('builderad_authority',p.oid,'EXECUTE') AS authority_execute,
                EXISTS(SELECT 1 FROM aclexplode(coalesce(p.proacl,acldefault('f',p.proowner))) a WHERE a.grantee=0 AND a.privilege_type='EXECUTE') AS public_execute
                FROM pg_proc p WHERE p.proname=?", [$function]);
            $this->assertTrue($row->prosecdef);
            $this->assertStringContainsString('search_path=public, pg_temp', $row->proconfig);
            $this->assertTrue($row->runtime_execute);
            $this->assertFalse($row->authority_execute);
            $this->assertFalse($row->public_execute);
        }
        $this->assertFalse($this->owner()->scalar("SELECT has_function_privilege('builderad_app','builderad_provider_require(bigint,text,uuid,text,text,text,text,text)','EXECUTE')"));
    }

    public function test_native_request_gate_rechecks_exact_action_epoch_and_all_current_evidence_for_each_provider(): void
    {
        foreach ($this->scopes as $scope) {
            $this->withinWorkspace(fn ($request) => app(ProviderRequestGuard::class)->requireCurrent($request, $scope, 'provider.connect', 0));
            $this->store($scope, (string) Str::uuid());
            $this->withinWorkspace(fn ($request) => app(ProviderRequestGuard::class)->requireCurrent($request, $scope, 'provider.read', 1));
            $this->denied(fn () => $this->withinWorkspace(fn ($request) => app(ProviderRequestGuard::class)->requireCurrent($request, $scope, 'provider.read', 0)), '42501');
            $this->denied(fn () => $this->withinWorkspace(fn ($request) => app(ProviderRequestGuard::class)->requireCurrent($request, $scope, 'provider.revoke', 1)), '42501');
            $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)
                ->where('action', 'provider.read')->update(['expires_at' => now()->subSecond()]);
            $this->denied(fn () => $this->withinWorkspace(fn ($request) => app(ProviderRequestGuard::class)->requireCurrent($request, $scope, 'provider.read', 1)), '42501');
            // A read denial does not revoke independently authorized local connection authority.
            $this->withinWorkspace(fn ($request) => app(ProviderRequestGuard::class)->requireCurrent($request, $scope, 'provider.connect', 1));
        }
        $this->assertSame(5, $this->owner()->table('provider_access')->count());
        $scope = $this->scopes['meta'];
        try {
            $this->withinWorkspace(function ($request) use ($scope): void {
                $guard = app(ProviderRequestGuard::class)->bind($request, $scope, 'provider.connect', 1);
                $guard($this->scopes['google'], 'provider.connect');
            });
            $this->fail('A bound native guard cannot switch providers.');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
    }

    public function test_native_request_gate_cannot_bypass_incomplete_access_evidence(): void
    {
        $scope = $this->scopes['meta'];
        $evidence = $this->evidence();
        $evidence['provider_permission']['state'] = 'UNKNOWN';
        $this->store($scope, (string) Str::uuid(), evidence: $evidence);
        $this->denied(fn () => $this->withinWorkspace(fn ($request) => app(ProviderRequestGuard::class)->requireCurrent($request, $scope, 'provider.read', 1)), '42501');
    }

    public function test_journal_preserves_identical_rereads_restatements_and_idempotent_native_progress(): void
    {
        $scope = $this->scopes['meta'];
        $this->store($scope, (string) Str::uuid());
        $command = (string) Str::uuid();
        $progress = new NativeProgress('synthetic_cursor', 'synthetic:complete-range', 'opaque-private-cursor');
        $first = $this->journal($scope, $command, 0, '10.01', $progress);
        $this->assertSame($first, $this->journal($scope, $command, 0, '10.01', $progress));
        $this->assertSame(1, $first['first_observations']);
        $this->assertTrue($first['progress_advanced']);
        $same = $this->journal($scope, (string) Str::uuid(), 1, '10.01');
        $this->assertSame(1, $same['identical_rereads']);
        $changed = $this->journal($scope, (string) Str::uuid(), 2, '11.02');
        $this->assertSame(1, $changed['restatements']);
        $this->assertSame($first, $this->journal($scope, $command, 0, '10.01', $progress));
        $this->denied(fn () => $this->journal($scope, $command, 0, '12.02', $progress), '23505');
        $this->assertSame(['FIRST_OBSERVATION', 'IDENTICAL_REREAD', 'RESTATEMENT'],
            $this->owner()->table('provider_observation_entries')->orderBy('generation')->pluck('relation')->all());
        $history = $this->owner()->table('provider_observation_entries')->orderBy('generation')->pluck('observation')->all();
        $this->assertSame('10.01', json_decode($history[0], true)['spend']['native_value']);
        $this->assertSame('11.02', json_decode($history[2], true)['spend']['native_value']);
        $native = $this->withinWorkspace(fn ($r) => app(ObservationJournal::class)->progress($r, $scope, 'synthetic-range', 1));
        $this->assertSame($progress->envelope(), $native->envelope());
        $this->assertSame(1, $this->owner()->table('provider_observation_streams')->value('progress_generation'));
        $this->denied(fn () => $this->withinWorkspace(fn () => DB::table('provider_observation_streams')->value('encrypted_progress')), '42501');
    }

    public function test_partial_failed_and_rolled_back_reads_never_advance_progress(): void
    {
        $scope = $this->scopes['meta'];
        $this->store($scope, (string) Str::uuid());
        $this->journal($scope, (string) Str::uuid(), 0, '10', coverage: 'PARTIAL');
        $this->assertNull($this->owner()->table('provider_observation_streams')->value('progress_generation'));
        $this->journal($scope, (string) Str::uuid(), 1, null, coverage: 'FAILED');
        $this->assertNull($this->owner()->table('provider_observation_streams')->value('progress_generation'));
        $this->assertSame(1, $this->owner()->table('provider_observation_entries')->count());
        try {
            $this->withinWorkspace(function ($r) use ($scope): void {
                $this->acceptJournal($r, $scope, (string) Str::uuid(), 2, '11', new NativeProgress('synthetic_cursor', 'synthetic:complete-range', 'next'));
                throw new \RuntimeException('Synthetic interrupted acceptance.');
            });
            $this->fail('Rollback was not exercised.');
        } catch (\RuntimeException) {
            $this->assertSame(2, $this->owner()->table('provider_observation_runs')->count());
            $this->assertSame(1, $this->owner()->table('provider_observation_entries')->count());
            $this->assertNull($this->owner()->table('provider_observation_streams')->value('progress_generation'));
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->journal($scope, (string) Str::uuid(), 2, '12', new NativeProgress('synthetic_cursor', 'synthetic:basis', 'next'), 'PARTIAL');
    }

    public function test_progress_write_failure_rolls_back_corresponding_observation_and_run(): void
    {
        $scope = $this->scopes['meta'];
        $this->store($scope, (string) Str::uuid());
        $this->owner()->unprepared("CREATE OR REPLACE FUNCTION bb2_synthetic_progress_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
            IF NEW.progress_generation IS NOT NULL THEN RAISE EXCEPTION 'Synthetic progress failure' USING ERRCODE='P0001'; END IF; RETURN NEW; END $$;
            CREATE TRIGGER bb2_synthetic_progress_failure BEFORE UPDATE ON provider_observation_streams FOR EACH ROW EXECUTE FUNCTION bb2_synthetic_progress_failure();");
        try {
            $this->denied(fn () => $this->journal($scope, (string) Str::uuid(), 0, '10', new NativeProgress('synthetic_cursor', 'synthetic:basis', 'next')), 'P0001');
        } finally {
            $this->owner()->unprepared('DROP TRIGGER bb2_synthetic_progress_failure ON provider_observation_streams; DROP FUNCTION bb2_synthetic_progress_failure();');
        }
        foreach (['provider_observation_streams', 'provider_observation_entries', 'provider_observation_runs'] as $table) {
            $this->assertSame(0, $this->owner()->table($table)->count());
        }
    }

    public function test_journal_progress_history_and_reconnect_are_isolated_for_all_five_providers(): void
    {
        foreach ($this->scopes as $scope) {
            $this->store($scope, (string) Str::uuid());
            $this->journal($scope, (string) Str::uuid(), 0, '10', new NativeProgress('synthetic_marker', 'synthetic:basis', $scope->provider->value));
        }
        $before = $this->owner()->table('provider_observation_entries')->get()->toJson();
        $scope = $this->scopes['snapchat'];
        $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->revoke($r, $scope, (string) Str::uuid(), 1));
        $this->denied(fn () => $this->journal($scope, (string) Str::uuid(), 1, '20'), '42501');
        $this->assertSame($before, $this->owner()->table('provider_observation_entries')->get()->toJson());
        foreach ($this->scopes as $sibling) {
            if ($sibling->provider !== Provider::Snapchat) {
                $native = $this->withinWorkspace(fn ($r) => app(ObservationJournal::class)->progress($r, $sibling, 'synthetic-range', 1));
                $this->assertSame($sibling->provider->value, $native->envelope()['payload']);
            }
        }
        $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->store($r, $scope, (string) Str::uuid(), 2,
            new ProviderCredentials(['access_token' => 'synthetic-replacement']), null, 'SYNTHETIC_AUTHORIZED', $this->evidence(), reconnect: true));
        $this->assertNull($this->withinWorkspace(fn ($r) => app(ObservationJournal::class)->progress($r, $scope, 'synthetic-range', 3)));
        $this->denied(fn () => $this->journal($scope, (string) Str::uuid(), 1, '20'), '42501');
    }

    public function test_real_journal_workers_replay_once_conflict_and_recheck_authority_after_namespace_wait(): void
    {
        $scope = $this->scopes['meta'];
        $this->store($scope, (string) Str::uuid());
        $command = (string) Str::uuid();
        $out = $this->journalRace($scope, [$command, $command], 0);
        $this->assertSame($out[0], $out[1]);
        $this->assertStringStartsWith('RESULT=', $out[0]);
        $this->assertSame(1, $this->owner()->table('provider_observation_runs')->count());
        $out = $this->journalRace($scope, [(string) Str::uuid(), (string) Str::uuid()], 1);
        $this->assertCount(1, array_filter($out, fn ($value) => str_starts_with($value, 'RESULT=')));
        $this->assertCount(1, array_filter($out, fn ($value) => $value === 'DENIED=23505'));
        $before = $this->owner()->table('provider_observation_entries')->get()->toJson();
        $out = $this->journalRace($scope, [(string) Str::uuid(), (string) Str::uuid()], 2,
            fn () => $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)
                ->where('action', 'provider.read')->update(['expires_at' => DB::raw("clock_timestamp()-interval '1 second'")]));
        $this->assertSame(['DENIED=42501', 'DENIED=42501'], $out);
        $this->assertSame($before, $this->owner()->table('provider_observation_entries')->get()->toJson());
        $this->assertSame(2, $this->owner()->table('provider_observation_streams')->value('generation'));
        $this->assertNull($this->owner()->table('provider_observation_streams')->value('progress_generation'));
    }

    public function test_encrypted_provider_queues_commit_after_dispatch_and_execute_idempotently_without_extending_session(): void
    {
        config()->set('queue.default', 'database');
        foreach ($this->scopes as $scope) {
            $this->store($scope, (string) Str::uuid());
            $before = $this->owner()->table('jobs')->count();
            $this->withinWorkspace(function ($r) use ($scope, $before): void {
                $o = $this->journalObservation($scope, '10');
                $job = PersistObservationJob::capture($r, new ObservationBatch($scope, [$o], 'COMPLETE', null, null),
                    'synthetic-range', (string) Str::uuid(), 0, 1, $o->provenance,
                    new NativeProgress('synthetic_marker', 'synthetic:basis', 'private-queued-progress'));
                $this->assertSame(3, $job->tries);
                $this->assertSame([1, 5, 15], $job->backoff());
                Bus::dispatch($job);
                $this->assertSame($before, $this->owner()->table('jobs')->count());
            });
        }
        $this->assertSame(5, $this->owner()->table('jobs')->count());
        $payloads = $this->owner()->table('jobs')->pluck('payload')->all();
        foreach ($payloads as $payload) {
            $this->assertStringNotContainsString('private-queued-progress', $payload);
            $this->assertStringNotContainsString($this->app['session']->driver()->getId(), $payload);
            $this->assertStringContainsString(PersistObservationJob::class, str_replace('\\\\', '\\', $payload));
        }
        $idleBefore = $this->owner()->table('security_sessions')->where('user_id', $this->actor->id)->value('idle_expires_at');
        foreach ($this->scopes as $scope) {
            $queued = Queue::connection('database')->pop('bb2.provider.'.$scope->provider->value);
            $this->assertNotNull($queued);
            $queued->fire();
        }
        $this->assertSame(5, $this->owner()->table('provider_observation_runs')->count());
        $this->assertSame(0, $this->owner()->table('jobs')->count());
        $this->assertSame($idleBefore, $this->owner()->table('security_sessions')->where('user_id', $this->actor->id)->value('idle_expires_at'));
    }

    public function test_queued_permission_failure_does_not_consume_sibling_jobs_or_advance_any_progress(): void
    {
        config()->set('queue.default', 'database');
        foreach (['meta', 'google'] as $provider) {
            $scope = $this->scopes[$provider];
            $this->store($scope, (string) Str::uuid());
            $this->withinWorkspace(function ($r) use ($scope): void {
                $o = $this->journalObservation($scope, '10');
                Bus::dispatch(PersistObservationJob::capture($r, new ObservationBatch($scope, [$o], 'COMPLETE', null, null),
                    'synthetic-range', (string) Str::uuid(), 0, 1, $o->provenance,
                    new NativeProgress('synthetic_marker', 'synthetic:basis', 'private-progress')));
            });
        }
        $this->owner()->table('security_action_grants')->where('source_target_id', $this->scopes['meta']->sourceId)
            ->where('action', 'provider.read')->update(['expires_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
        $queued = Queue::connection('database')->pop('bb2.provider.meta');
        $this->denied(fn () => $queued->fire(), '42501');
        $this->assertSame(0, $this->owner()->table('provider_observation_streams')->count());
        $this->assertSame(0, (int) $this->owner()->table('jobs')->where('queue', 'bb2.provider.google')->value('attempts'));
        $sibling = Queue::connection('database')->pop('bb2.provider.google');
        $this->assertNotNull($sibling);
        $sibling->fire();
        $this->assertSame(1, $this->owner()->table('provider_observation_streams')->where('provider', 'google')->value('progress_generation'));
        $this->assertSame(1, $this->owner()->table('provider_observation_runs')->count());
    }

    public function test_malformed_or_stale_observations_do_not_advance_progress_and_claimed_hash_cannot_hide_correction(): void
    {
        $scope = $this->scopes['meta'];
        $this->store($scope, (string) Str::uuid());
        $this->journal($scope, (string) Str::uuid(), 0, '10', new NativeProgress('synthetic_marker', 'synthetic:basis', 'accepted-marker'));
        $o = $this->journalObservation($scope, '10');
        $payload = ['coverage' => 'COMPLETE', 'failure' => null, 'provenance' => $o->provenance,
            'encrypted_progress' => null, 'progress_fingerprint' => null,
            'observations' => [['identity' => $o->identity(), 'content_hash' => $o->contentHash(), 'observation' => $o->jsonSerialize()]]];
        $before = $this->owner()->table('provider_observation_streams')->first();
        $wire = fn ($data) => $this->withinWorkspace(fn ($r) => DB::scalar(
            'SELECT builderad_observation_accept(?, ?, ?::uuid, ?, ?, ?, ?, ?, ?::uuid, ?, ?, ?::jsonb)',
            [$scope->workspaceId, SessionAuthority::sessionKey($r), $scope->sourceId, $scope->provider->value,
                $scope->accountId, $scope->operation, $scope->purpose, 'synthetic-range', (string) Str::uuid(), 1, 1, json_encode($data, JSON_THROW_ON_ERROR)]));
        $malformed = $payload;
        $malformed['observations'][0]['observation']['spend']['native_value'] = 'invalid-provider-number';
        $this->denied(fn () => $wire($malformed), '22023');
        $stale = $payload;
        $stale['observations'][0]['observation']['read_at'] = '2000-01-01T00:00:00Z';
        $this->denied(fn () => $wire($stale), '22023');
        $this->assertEquals($before, $this->owner()->table('provider_observation_streams')->first());
        $this->assertSame(1, $this->owner()->table('provider_observation_entries')->count());
        $correction = $payload;
        $correction['observations'][0]['observation'] = $this->journalObservation($scope, '11')->jsonSerialize();
        // The claimed content_hash remains deliberately unchanged; native content governs reconciliation.
        $this->assertSame(1, json_decode($wire($correction), true)['restatements']);
        $this->assertSame(1, $this->owner()->table('provider_observation_streams')->value('progress_generation'));
        $this->assertSame(2, $this->owner()->table('provider_observation_entries')->count());
    }

    public function test_historical_observation_reads_remain_exact_scope_guarded_and_runtime_cannot_rewrite_history(): void
    {
        foreach ($this->scopes as $scope) {
            $this->store($scope, (string) Str::uuid());
            $this->journal($scope, (string) Str::uuid(), 0, '10');
        }
        $readCount = fn () => $this->withinWorkspace(function ($r) {
            DB::selectOne('SELECT builderad_source_begin_read(?,?)', [$this->workspaceId, SessionAuthority::sessionKey($r)]);

            return DB::table('provider_observation_entries')->count();
        });
        $this->assertSame(5, $readCount());
        $scope = $this->scopes['meta'];
        $history = $this->withinWorkspace(fn ($r) => app(ObservationJournal::class)->history($r, $scope, 'synthetic-range'));
        $this->assertCount(1, $history);
        $this->assertSame('meta', $history[0]['observation']['scope']['provider']);
        $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)->where('action', 'source.read')
            ->update(['expires_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
        $this->assertSame(4, $readCount());
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => app(ObservationJournal::class)->history($r, $scope, 'synthetic-range')), '42501');
        foreach (['provider_observation_streams', 'provider_observation_runs', 'provider_observation_entries'] as $table) {
            $this->denied(fn () => $this->withinWorkspace(fn () => DB::table($table)->delete()), '42501');
            $this->assertSame(5, $this->owner()->table($table)->count());
        }
        $other = new ProviderScope($scope->workspaceId + 1, $scope->sourceId, $scope->provider, $scope->accountId, $scope->operation, $scope->purpose);
        $this->denied(fn () => $this->journal($other, (string) Str::uuid(), 0, '10'), '42501');
    }

    private function journalRace(ProviderScope $scope, array $commands, int $generation, ?callable $whileBlocked = null): array
    {
        $run = 'bb2-journal-race-'.Str::uuid();
        $holder = DB::build(config('database.connections.pgsql_migrations'));
        $workers = [];
        $observation = $this->journalObservation($scope, '10');
        $payload = ['coverage' => 'COMPLETE', 'failure' => null, 'provenance' => $observation->provenance,
            'encrypted_progress' => null, 'progress_fingerprint' => null,
            'observations' => [['identity' => $observation->identity(), 'content_hash' => $observation->contentHash(), 'observation' => $observation->jsonSerialize()]]];
        $holder->beginTransaction();
        $holder->selectOne("SELECT pg_advisory_xact_lock(hashtextextended('bb2.observation.'||builderad_observation_namespace(?,?::uuid,?,?,?,?,?),0))",
            [$scope->workspaceId, $scope->sourceId, $scope->provider->value, $scope->accountId, $scope->operation, $scope->purpose, 'synthetic-range']);
        $request = Request::create('/');
        $request->setLaravelSession($this->app['session']->driver());
        try {
            foreach ($commands as $command) {
                $worker = new Process([PHP_BINARY, base_path('tests/ObservationCommandWorker.php'), json_encode([
                    'run' => $run, 'actor' => $this->actor->id, 'scope' => $scope->jsonSerialize(),
                    'session' => SessionAuthority::sessionKey($request), 'command' => $command, 'generation' => $generation, 'payload' => $payload,
                ], JSON_THROW_ON_ERROR)], base_path());
                $worker->setTimeout(120);
                $worker->start();
                $workers[] = $worker;
            }
            // Process/bootstrap latency is separate from the database lock-wait proof.
            $startupDeadline = microtime(true) + 60;
            do {
                $ready = (int) DB::scalar('SELECT count(*) FROM pg_stat_activity WHERE application_name=?', [$run]);
                if ($ready === 2 || count(array_filter($workers, fn ($worker) => $worker->isTerminated())) > 0) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $startupDeadline);
            $this->assertSame(2, $ready, 'Independent journal workers did not finish bootstrap.');
            $deadline = microtime(true) + 30;
            do {
                $waiters = (int) DB::scalar("SELECT count(*) FROM pg_stat_activity WHERE application_name=? AND lower(wait_event)='advisory'", [$run]);
                if ($waiters === 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $waiters, implode('\n', array_map(fn ($worker) => $worker->getOutput().' '.$worker->getErrorOutput(), $workers)));
            if ($whileBlocked) {
                $whileBlocked();
            }
            $holder->commit();
            $output = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $output[] = $worker->getOutput();
            }

            return $output;
        } finally {
            if ($holder->transactionLevel() > 0) {
                $holder->rollBack();
            }
            $holder->disconnect();
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }

    public function test_meta_native_read_accepts_exact_account_money_and_atomic_progress(): void
    {
        $scope = $this->metaReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['data' => [$this->metaReaderRow()]], 200)]);
        $result = $this->metaRead($scope, $http);
        $http->assertSentCount(1);
        $this->assertSame(1, $result['generation']);
        $this->assertSame(1, $this->owner()->table('provider_observation_streams')->value('progress_generation'));
        $history = $this->withinWorkspace(fn ($r) => app(ObservationJournal::class)->history($r, $scope, 'meta-integrated'));
        $this->assertCount(1, $history);
        $this->assertSame('9007199254740993.01', $history[0]['observation']['spend']['native_value']);
        $this->assertNull($history[0]['observation']['fresh_until']);
    }

    public function test_meta_native_read_denies_expired_authority_before_second_page_and_rolls_back(): void
    {
        $scope = $this->metaReaderScope();
        $grantId = $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)
            ->where('action', 'provider.read')->value('id');
        $this->owner()->table('security_action_grants')->where('id', $grantId)
            ->update(['expires_at' => DB::raw("clock_timestamp()+interval '2 seconds'")]);
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(function () use ($http, $grantId) {
            $deadline = microtime(true) + 5;
            do {
                $expired = $this->owner()->scalar('SELECT expires_at <= clock_timestamp() FROM security_action_grants WHERE id=?', [$grantId]);
                if ($expired) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertTrue((bool) $expired, 'Authority must actually expire before the next native request.');

            return $http->response(['data' => [$this->metaReaderRow()], 'paging' => ['next' => 'https://untrusted.invalid/',
                'cursors' => ['after' => 'synthetic-next']]], 200);
        });
        $this->denied(fn () => $this->metaRead($scope, $http), '42501');
        $http->assertSentCount(1);
        foreach (['provider_observation_streams', 'provider_observation_runs', 'provider_observation_entries'] as $table) {
            $this->assertSame(0, $this->owner()->table($table)->count());
        }
    }

    public function test_meta_native_read_currency_and_timezone_changes_require_reverification(): void
    {
        $scope = $this->metaReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['data' => [$this->metaReaderRow()]], 200)]);
        try {
            $this->metaRead($scope, $http, 'America/New_York');
            $this->fail('An unverified account timezone cannot be read.');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('timezone', $error->getMessage());
        }
        $http->assertSentCount(0);
        $row = $this->metaReaderRow();
        $row['account_currency'] = 'EUR';
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['data' => [$row]], 200)]);
        try {
            $this->metaRead($scope, $http);
            $this->fail('A changed native account currency cannot be accepted.');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('currency', $error->getMessage());
        }
        $this->assertSame(0, $this->owner()->table('provider_observation_runs')->count());
    }

    public function test_meta_callback_state_is_encrypted_exact_bound_and_consumed_even_after_rollback(): void
    {
        $scope = $this->scopes['meta'];
        $states = new MetaOAuthState(Cache::store(), app(ProviderRequestGuard::class));
        $configuration = hash('sha256', 'synthetic-configuration');
        $state = $this->withinWorkspace(fn ($r) => $states->issue($r, $scope, 0, $configuration));
        $key = 'bb2.meta.oauth.'.hash('sha256', $state);
        $ciphertext = Cache::get($key);
        $this->assertIsString($ciphertext);
        $this->assertStringNotContainsString($scope->sourceId, $ciphertext);
        $this->assertStringNotContainsString($configuration, $ciphertext);
        try {
            $this->withinWorkspace(fn ($r) => $states->consume($r, $scope, 0, hash('sha256', 'another-configuration'), $state));
            $this->fail('A changed Meta configuration must not consume callback state.');
        } catch (AuthorizationException) {
            $this->assertSame($ciphertext, Cache::get($key));
        }
        try {
            $this->withinWorkspace(function ($r) use ($states, $scope, $configuration, $state): void {
                $states->consume($r, $scope, 0, $configuration, $state);
                throw new \RuntimeException('Synthetic post-consumption provider failure.');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic post-consumption provider failure.', $error->getMessage());
        }
        $this->assertNull(Cache::get($key));
        try {
            $this->withinWorkspace(fn ($r) => $states->consume($r, $scope, 0, $configuration, $state));
            $this->fail('A callback state must never be replayed.');
        } catch (AuthorizationException) {
            $this->assertSame(0, $this->owner()->table('provider_access_events')->count());
        }
    }

    public function test_meta_callback_state_is_not_published_on_rollback_and_never_replaces_current_authority(): void
    {
        $scope = $this->scopes['meta'];
        $states = new MetaOAuthState(Cache::store(), app(ProviderRequestGuard::class));
        $configuration = hash('sha256', 'synthetic-configuration');
        $rolledBack = null;
        try {
            $this->withinWorkspace(function ($r) use ($states, $scope, $configuration, &$rolledBack): void {
                $rolledBack = $states->issue($r, $scope, 0, $configuration);
                throw new \RuntimeException('Synthetic initiation rollback.');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic initiation rollback.', $error->getMessage());
        }
        $this->assertNotNull($rolledBack);
        $this->assertNull(Cache::get('bb2.meta.oauth.'.hash('sha256', $rolledBack)));
        $state = $this->withinWorkspace(fn ($r) => $states->issue($r, $scope, 0, $configuration));
        $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)->where('action', 'provider.connect')
            ->update(['expires_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => $states->consume($r, $scope, 0, $configuration, $state)), '42501');
        $this->assertIsString(Cache::get('bb2.meta.oauth.'.hash('sha256', $state)));
        $this->assertSame(0, $this->owner()->table('provider_access_events')->count());
    }

    public function test_meta_pending_acquisition_is_encrypted_guarded_and_does_not_assert_ready_custody(): void
    {
        $scope = $this->scopes['meta'];
        $pending = new MetaPendingAccess(Cache::store(), app(ProviderRequestGuard::class));
        $configuration = hash('sha256', 'synthetic-configuration');
        $axes = array_fill_keys(EffectiveCapability::AXES, ['state' => 'UNKNOWN', 'provenance' => 'synthetic:unestablished']);
        $material = new ProviderAccessMaterial($scope, new ProviderCredentials(['access_token' => 'synthetic-pending-secret']), null, 'CONNECTED', $axes);
        $handle = $this->withinWorkspace(fn ($r) => $pending->stage($r, $material, 0, $configuration));
        $ciphertext = Cache::get('bb2.meta.pending.'.hash('sha256', $handle));
        $this->assertIsString($ciphertext);
        $this->assertStringNotContainsString('synthetic-pending-secret', $ciphertext);
        $loaded = $this->withinWorkspace(fn ($r) => $pending->material($r, $scope, 0, $configuration, $handle));
        $this->assertSame('synthetic-pending-secret', $loaded->credentials->value('access_token'));
        $this->assertNull($loaded->expiresAt);
        $this->assertSame($axes, $loaded->verification);
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
        try {
            $this->withinWorkspace(fn ($r) => $pending->material($r, $scope, 0, hash('sha256', 'changed-configuration'), $handle));
            $this->fail('A pending acquisition cannot change its configuration binding.');
        } catch (AuthorizationException) {
            $this->assertSame($ciphertext, Cache::get('bb2.meta.pending.'.hash('sha256', $handle)));
        }
        $this->withinWorkspace(fn ($r) => $pending->discard($r, $scope, 0, $configuration, $handle));
        $this->assertNull(Cache::get('bb2.meta.pending.'.hash('sha256', $handle)));
    }

    public function test_meta_pending_acquisition_rolls_back_publication_and_cannot_bypass_expired_connect_authority(): void
    {
        $scope = $this->scopes['meta'];
        $pending = new MetaPendingAccess(Cache::store(), app(ProviderRequestGuard::class));
        $configuration = hash('sha256', 'synthetic-configuration');
        $material = new ProviderAccessMaterial($scope, new ProviderCredentials(['access_token' => 'synthetic-pending-secret']), null, 'CONNECTED', []);
        $rolledBack = null;
        try {
            $this->withinWorkspace(function ($r) use ($pending, $material, $configuration, &$rolledBack): void {
                $rolledBack = $pending->stage($r, $material, 0, $configuration);
                throw new \RuntimeException('Synthetic staging rollback.');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic staging rollback.', $error->getMessage());
        }
        $this->assertNotNull($rolledBack);
        $this->assertNull(Cache::get('bb2.meta.pending.'.hash('sha256', $rolledBack)));
        $handle = $this->withinWorkspace(fn ($r) => $pending->stage($r, $material, 0, $configuration));
        $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)->where('action', 'provider.connect')
            ->update(['expires_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => $pending->material($r, $scope, 0, $configuration, $handle)), '42501');
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
    }

    public function test_meta_login_bootstrap_uses_real_configuration_namespace_and_only_explicit_a003_discovery_grants(): void
    {
        $sourceId = (string) Str::uuid7();
        $commandId = (string) Str::uuid();
        $workspace = $this->owner()->table('workspaces')->where('id', $this->workspaceId)->first();
        $model = new Workspace;
        $model->setRawAttributes((array) $workspace, true);
        $bootstrap = app(MetaLoginBootstrap::class);
        $scope = $this->withinWorkspace(fn ($r) => $bootstrap->requestConnection($r, $model, '999', $sourceId, $commandId));
        $this->assertSame('discovery.read', $scope->operation);
        $source = $this->owner()->table('source_registry')->where('id', $sourceId)->first();
        $this->assertSame('facebook_login_configuration', $source->namespace);
        $this->assertSame('999', $source->declared_external_id);
        $this->assertSame('UNVERIFIED', $source->status);
        $actions = $this->owner()->table('security_action_grants')->where('source_target_id', $sourceId)->orderBy('action')->pluck('action')->all();
        $this->assertSame(['provider.connect', 'source.read', 'source.register'], $actions);
        $this->assertSame(3, $this->owner()->table('security_audit_events')->where('event', 'source_grant_authorize')
            ->where('reason', 'like', '%'.$sourceId)->count());
        $again = $this->withinWorkspace(fn ($r) => $bootstrap->requestConnection($r, $model, '999', $sourceId, $commandId));
        $this->assertSame($scope->jsonSerialize(), $again->jsonSerialize());
        $this->assertSame(1, $this->owner()->table('source_command_receipts')->where('source_id', $sourceId)->count());
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => app(ProviderRequestGuard::class)->requireCurrent($r, $scope, 'provider.read', 0)), '42501');
    }

    public function test_native_fetch_accepts_unknown_quality_without_claiming_product_ready_and_requires_every_access_prerequisite(): void
    {
        foreach ($this->scopes as $scope) {
            $evidence = $this->evidence();
            foreach (['freshness', 'completeness', 'product_exposure'] as $axis) {
                $evidence[$axis]['state'] = 'UNKNOWN';
            }
            $this->store($scope, (string) Str::uuid(), evidence: $evidence);
            $this->withinWorkspace(fn ($r) => app(ProviderRequestGuard::class)->requireCurrent($r, $scope, 'provider.read', 1));
            $this->assertSame('UNKNOWN', (new EffectiveCapability)->derive('UNVERIFIED', true, $evidence, new DateTimeImmutable)['state']);
            foreach (['provider_support', 'provider_permission', 'credential', 'privacy_purpose'] as $axis) {
                $missing = $evidence;
                $missing[$axis]['state'] = 'UNKNOWN';
                $this->owner()->table('provider_access')->where('source_id', $scope->sourceId)->update(['verification' => json_encode($missing)]);
                $this->denied(fn () => $this->withinWorkspace(fn ($r) => app(ProviderRequestGuard::class)->requireCurrent($r, $scope, 'provider.read', 1)), '42501');
            }
            $this->owner()->table('provider_access')->where('source_id', $scope->sourceId)->update(['verification' => json_encode($evidence)]);
        }
        $this->assertSame(0, $this->owner()->table('provider_observation_runs')->count());
    }

    public function test_meta_authorization_orchestration_consumes_state_stages_discovers_and_verifies_separate_account(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $debug = ['data' => ['is_valid' => true, 'type' => 'SYSTEM_USER', 'app_id' => '777', 'user_id' => '888',
            'scopes' => ['ads_read'], 'expires_at' => 0, 'granular_scopes' => [['scope' => 'ads_read', 'target_ids' => ['123']]]]];
        $business = ['id' => '888', 'client_business_id' => '555'];
        $http->fake(['*' => $http->sequence()->push(['access_token' => 'synthetic-federated-token'])->push($debug)->push($business)
            ->push($debug)->push($business)->push(['id' => 'act_123', 'account_id' => '123', 'name' => 'Synthetic granted account'])
            ->push($debug)->push($business)->push(['id' => 'act_123', 'account_id' => '123', 'currency' => 'USD', 'timezone_name' => 'UTC'])
            ->push(['data' => []])]);
        [$coordinator, $installation] = $this->metaAuthorizationFixture($http);
        $url = $this->withinWorkspace(fn ($r) => $coordinator->authorizationUrl($r, $installation));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('999', $query['config_id']);
        $this->assertStringNotContainsString('synthetic-app-secret', $url);
        $handle = $this->withinWorkspace(fn ($r) => $coordinator->callback($r, $installation, 0, $query['state'], 'synthetic-code'));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $handle);
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
        $http->assertSentCount(3);
        $discovery = $this->withinWorkspace(fn ($r) => $coordinator->discover($r, $installation, 0, $handle));
        $this->assertSame('COMPLETE', $discovery->coverage);
        $this->assertSame('123', $discovery->accounts[0]['id']);
        $this->assertNull($discovery->accounts[0]['native_role']);
        $selected = $this->metaReaderScope(false);
        $receipt = $this->withinWorkspace(fn ($r) => $coordinator->select($r, $installation, 0, $handle, $selected, (string) Str::uuid(), 0));
        $this->assertSame(['version' => 1, 'local_state' => 'CREDENTIAL_STORED'], $receipt);
        $http->assertSentCount(10);
        $this->assertNull(Cache::get('bb2.meta.pending.'.hash('sha256', $handle)));
        $access = $this->owner()->table('provider_access')->where('source_id', $selected->sourceId)->first();
        $this->assertSame('CONNECTED', $access->native_state);
        $this->assertNull($access->credential_expires_at);
        $evidence = json_decode($access->verification, true);
        $this->assertSame('VERIFIED', $evidence['provider_permission']['state']);
        $this->assertSame('UNKNOWN', $evidence['completeness']['state']);
        $this->assertSame('UNKNOWN', $evidence['privacy_purpose']['state']);
        $this->assertStringNotContainsString('synthetic-federated-token', $access->encrypted_credentials);
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->credentials($r, $selected)), '42501');
        try {
            $this->withinWorkspace(fn ($r) => $coordinator->callback($r, $installation, 0, $query['state'], 'replayed-code'));
            $this->fail('A callback cannot exchange another code with consumed state.');
        } catch (AuthorizationException) {
            $http->assertSentCount(10);
        }
    }

    public function test_meta_authorization_orchestration_denies_expired_connect_before_code_exchange(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        [$coordinator, $installation] = $this->metaAuthorizationFixture($http);
        $url = $this->withinWorkspace(fn ($r) => $coordinator->authorizationUrl($r, $installation));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->owner()->table('security_action_grants')->where('source_target_id', $installation->sourceId)->where('action', 'provider.connect')
            ->update(['expires_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => $coordinator->callback($r, $installation, 0, $query['state'], 'synthetic-code')), '42501');
        $http->assertSentCount(0);
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
    }

    private function metaAuthorizationFixture(Factory $http): array
    {
        $model = new Workspace;
        $model->setRawAttributes((array) $this->owner()->table('workspaces')->where('id', $this->workspaceId)->first(), true);
        $installation = $this->withinWorkspace(fn ($r) => app(MetaLoginBootstrap::class)->requestConnection($r, $model,
            '999', (string) Str::uuid7(), (string) Str::uuid()));
        $requests = app(ProviderRequestGuard::class);
        $configuration = new MetaAccessAdapter($http, 'v26.0', '777', 'synthetic-app-secret',
            'https://builderad.example/meta/callback', '999', static fn () => throw new \LogicException('Unbound configuration cannot execute native requests.'));

        return [new MetaAuthorizationCoordinator($http, $configuration, new MetaOAuthState(Cache::store(), $requests),
            new MetaPendingAccess(Cache::store(), $requests), $requests, app(ProviderAccessStore::class)), $installation];
    }

    public function test_meta_report_http_queues_the_bound_period_and_replays_after_delay_without_native_read(): void
    {
        $scope = $this->metaReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['data' => [$this->metaReaderRow()]])]);
        $this->configureMetaQueue($http);
        $base = '/workspaces/'.$this->workspace.'/providers/meta/'.$scope->sourceId.'/report';
        $period = ['start_date' => '2026-09-01', 'end_date' => '2026-09-01'];
        $status = $this->getJson($base.'?'.http_build_query($period))->assertOk()->assertJsonPath('timezone', 'UTC');
        $command = [...$period, 'command_id' => (string) Str::uuid(), 'expected_version' => 1, 'expected_generation' => 0,
            'deadline' => $status->json('deadline')];
        $this->postJson($base, [...$command, 'access_token' => 'injected'])->assertUnprocessable();
        $this->postJson($base, [...$command, 'deadline' => now()->addMinutes(10)->format(DATE_ATOM)])->assertUnprocessable();
        $this->postJson('/workspaces/'.Str::uuid().'/providers/meta/'.$scope->sourceId.'/report', $command)->assertForbidden();
        $this->postJson($base, $command)->assertAccepted();
        $http->assertNothingSent();
        $payload = $this->owner()->table('jobs')->where('queue', 'bb2.provider.meta')->value('payload');
        $this->assertStringNotContainsString('synthetic-app-secret', $payload);
        Queue::connection('database')->pop('bb2.provider.meta')->fire();
        $this->getJson($base.'?'.http_build_query($period))->assertOk()->assertJsonPath('generation', 1)->assertJsonPath('last_run.coverage', 'COMPLETE');
        $this->travel(3)->seconds();
        $this->postJson($base, $command)->assertAccepted();
        Queue::connection('database')->pop('bb2.provider.meta')->fire();
        $http->assertSentCount(1);
        $this->assertSame(1, $this->owner()->table('meta_read_attempts')->count());
    }

    public function test_meta_report_http_denies_unknown_data_use_proof_without_queue_or_http(): void
    {
        $scope = $this->metaReaderScope();
        $proof = $this->evidence();
        $proof['privacy_purpose']['state'] = 'UNKNOWN';
        $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->store($r, $scope, (string) Str::uuid(), 1,
            new ProviderCredentials(['access_token' => 'synthetic-native-token', 'account_id' => '123',
                'account_timezone' => 'UTC', 'account_currency' => 'USD']), null, 'SYNTHETIC_AUTHORIZED', $proof, true));
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        $this->configureMetaQueue($http);
        $this->postJson('/workspaces/'.$this->workspace.'/providers/meta/'.$scope->sourceId.'/report',
            ['start_date' => '2026-09-01', 'end_date' => '2026-09-01', 'command_id' => (string) Str::uuid(),
                'expected_version' => 2, 'expected_generation' => 0, 'deadline' => now()->addMinutes(5)->format(DATE_ATOM)])->assertForbidden();
        $this->assertSame(0, $this->owner()->table('jobs')->where('queue', 'bb2.provider.meta')->count());
        $http->assertNothingSent();
    }

    public function test_meta_public_async_creates_once_polls_and_replays_without_duplicate_creation(): void
    {
        $scope = $this->metaReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push(['report_run_id' => '999'])
            ->push(['id' => '999', 'account_id' => '123', 'async_status' => 'Job Running', 'async_percent_completion' => 20])
            ->push(['id' => '999', 'account_id' => '123', 'async_status' => 'Job Completed', 'async_percent_completion' => 100])
            ->push(['data' => [$this->metaReaderRow()]])]);
        $this->configureMetaQueue($http);
        $base = '/workspaces/'.$this->workspace.'/providers/meta/'.$scope->sourceId.'/report';
        $period = ['start_date' => '2026-09-01', 'end_date' => '2026-09-01', 'mode' => 'ASYNC'];
        $status = $this->getJson($base.'?'.http_build_query($period))->assertOk();
        $command = [...$period, 'command_id' => (string) Str::uuid(), 'expected_version' => 1,
            'expected_generation' => 0, 'deadline' => $status->json('deadline')];
        $this->postJson($base, [...$command, 'report_run_id' => '888'])->assertUnprocessable();
        $this->postJson($base, $command)->assertAccepted();
        $http->assertNothingSent();
        Queue::connection('database')->pop('bb2.provider.meta')->fire();
        $this->assertSame('PENDING', $this->owner()->table('provider_observation_runs')->value('coverage'));
        $this->assertNull($this->owner()->table('provider_observation_streams')->value('progress_generation'));
        $this->travel(6)->seconds();
        Queue::connection('database')->pop('bb2.provider.meta')->fire();
        $this->getJson($base.'?'.http_build_query($period))->assertOk()->assertJsonPath('last_run.coverage', 'COMPLETE');
        $this->postJson($base, $command)->assertAccepted();
        Queue::connection('database')->pop('bb2.provider.meta')->fire();
        $http->assertSentCount(4);
        $this->assertSame(1, count(array_filter($http->recorded()->all(), fn ($record) => $record[0]->method() === 'POST')));
        $this->assertSame(2, $this->owner()->table('provider_observation_streams')->value('progress_generation'));
        $this->travelBack();
    }

    public function test_meta_async_creation_fence_retains_ticket_across_sql_rollback_and_blocks_uncertain_retry(): void
    {
        $scope = $this->metaReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['report_run_id' => '999'])]);
        $this->configureMetaQueue($http);
        $fence = new MetaAsyncAcquisition(Cache::store());
        $deadline = new DateTimeImmutable('+5 minutes');
        $create = fn () => $this->withinWorkspace(fn ($r) => app(MetaObservationReader::class)->startAsync($r, $scope, $this->metaQueueWindow(),
            new ProviderReadBudget($scope, 'meta-create', 2, 0, 2, 10, new DateTimeImmutable('+30 seconds'), 1, 5),
            'v26.0', 'synthetic-app-secret', 1));
        $binding = [$scope->jsonSerialize(), 'synthetic-root'];
        try {
            $this->withinWorkspace(function ($r) use ($fence, $binding, $deadline, $scope): void {
                $fence->acquire($binding, $deadline, fn () => app(MetaObservationReader::class)->startAsync($r, $scope, $this->metaQueueWindow(),
                    new ProviderReadBudget($scope, 'meta-create', 2, 0, 2, 10, new DateTimeImmutable('+30 seconds'), 1, 5),
                    'v26.0', 'synthetic-app-secret', 1));
                throw new \RuntimeException('synthetic-rollback');
            });
        } catch (\RuntimeException $failure) {
            $this->assertSame('synthetic-rollback', $failure->getMessage());
        }
        $this->assertSame('999', $fence->acquire($binding, $deadline, $create)->id);
        $http->assertSentCount(1);
        $calls = 0;
        $uncertain = function () use (&$calls): MetaReportTask {
            $calls++;
            throw new MetaRequestFailed('meta_request_failed');
        };
        foreach (['meta_request_failed', 'meta_async_creation_uncertain'] as $reason) {
            try {
                $fence->acquire(['synthetic-uncertain'], $deadline, $uncertain);
                $this->fail('Uncertain native creation must not be resubmitted.');
            } catch (MetaRequestFailed $failure) {
                $this->assertSame($reason, $failure->reason);
            }
        }
        $this->assertSame(1, $calls);
        $this->assertSame(0, $this->owner()->table('provider_observation_runs')->count());
    }

    public function test_meta_public_async_worker_sql_failure_retries_verified_task_without_another_post(): void
    {
        $scope = $this->metaReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push(['report_run_id' => '999'])
            ->push(['id' => '999', 'account_id' => '123', 'async_status' => 'Job Running', 'async_percent_completion' => 20])
            ->push(['id' => '999', 'account_id' => '123', 'async_status' => 'Job Completed', 'async_percent_completion' => 100])
            ->push(['data' => [$this->metaReaderRow()]])]);
        $this->configureMetaQueue($http);
        $this->postJson('/workspaces/'.$this->workspace.'/providers/meta/'.$scope->sourceId.'/report',
            ['start_date' => '2026-09-01', 'end_date' => '2026-09-01', 'mode' => 'ASYNC', 'command_id' => (string) Str::uuid(),
                'expected_version' => 1, 'expected_generation' => 0, 'deadline' => now()->addMinutes(5)->format(DATE_ATOM)])->assertAccepted();
        $this->owner()->unprepared("CREATE FUNCTION bb2_synthetic_meta_async_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
            RAISE EXCEPTION 'Synthetic async receipt failure' USING ERRCODE='P0001'; END $$;
            CREATE TRIGGER bb2_synthetic_meta_async_failure BEFORE INSERT ON meta_read_attempts FOR EACH ROW EXECUTE FUNCTION bb2_synthetic_meta_async_failure();");
        $queued = Queue::connection('database')->pop('bb2.provider.meta');
        try {
            $this->denied(fn () => $queued->fire(), 'P0001');
        } finally {
            $this->owner()->unprepared('DROP TRIGGER bb2_synthetic_meta_async_failure ON meta_read_attempts; DROP FUNCTION bb2_synthetic_meta_async_failure();');
        }
        $this->assertSame(0, $this->owner()->table('provider_observation_runs')->count());
        $queued->fire();
        $http->assertSentCount(4);
        $this->assertSame(1, count(array_filter($http->recorded()->all(), fn ($record) => $record[0]->method() === 'POST')));
        $this->assertSame('COMPLETE', $this->owner()->table('provider_observation_runs')->value('coverage'));
        $this->assertSame(1, $this->owner()->table('meta_read_attempts')->count());
    }

    public function test_meta_queued_read_rejects_rotated_private_configuration_before_native_http(): void
    {
        $scope = $this->metaReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        $this->configureMetaQueue($http);
        $job = $this->queueMetaRead($scope);
        config()->set('services.meta_ads.app_secret', 'synthetic-rotated-app-secret');
        try {
            $job->handle(app(PrincipalContext::class), app(MetaObservationReader::class), app(MetaReadExecution::class));
            $this->fail('Rotated private configuration must invalidate queued commands.');
        } catch (AuthorizationException) {
            $http->assertNothingSent();
            $this->assertSame(0, $this->owner()->table('provider_observation_runs')->count());
        }
    }

    public function test_meta_native_queue_encrypts_context_and_replayed_delivery_does_not_fetch_or_accept_twice(): void
    {
        $scope = $this->metaReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['data' => [$this->metaReaderRow()]], 200)]);
        $this->configureMetaQueue($http);
        $job = $this->queueMetaRead($scope);
        $payload = $this->owner()->table('jobs')->value('payload');
        $this->assertStringNotContainsString('synthetic-app-secret', $payload);
        $this->assertStringNotContainsString('synthetic-native-token', $payload);
        $this->assertStringNotContainsString($this->app['session']->driver()->getId(), $payload);
        $idleBefore = $this->owner()->table('security_sessions')->where('user_id', $this->actor->id)->value('idle_expires_at');
        Queue::connection('database')->pop('bb2.provider.meta')->fire();
        $http->assertSentCount(1);
        Bus::dispatch($job);
        Queue::connection('database')->pop('bb2.provider.meta')->fire();
        $http->assertSentCount(1);
        $this->assertSame(1, $this->owner()->table('provider_observation_runs')->count());
        $this->assertSame(1, $this->owner()->table('meta_read_attempts')->count());
        $this->assertSame(1, $this->owner()->table('provider_observation_streams')->value('progress_generation'));
        $this->assertSame($idleBefore, $this->owner()->table('security_sessions')->where('user_id', $this->actor->id)->value('idle_expires_at'));
        $this->denied(fn () => $this->withinWorkspace(fn () => DB::table('meta_read_attempts')->get()), '42501');
        $this->denied(fn () => $this->withinWorkspace(fn () => DB::table('meta_read_attempts')->delete()), '42501');
    }

    public function test_meta_native_queue_respects_delay_even_with_early_delivery_and_keeps_failure_progress_unadvanced(): void
    {
        $scope = $this->metaReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push(['error' => ['code' => 80000, 'message' => 'synthetic-private-error']], 400,
            ['X-Ad-Account-Usage' => '{"reset_time_duration":40}'])->push(['data' => [$this->metaReaderRow()]], 200)]);
        $this->configureMetaQueue($http);
        $root = $this->queueMetaRead($scope);
        $before = time();
        Queue::connection('database')->pop('bb2.provider.meta')->fire();
        $this->assertSame('FAILED', $this->owner()->table('provider_observation_runs')->value('coverage'));
        $this->assertNull($this->owner()->table('provider_observation_streams')->value('progress_generation'));
        $this->assertSame(0, $this->owner()->table('provider_observation_entries')->count());
        $this->assertGreaterThanOrEqual($before + 40, $this->owner()->table('jobs')->value('available_at'));
        Bus::dispatch($root);
        Queue::connection('database')->pop('bb2.provider.meta')->fire();
        $this->assertSame(2, $this->owner()->table('jobs')->count());
        $http->assertSentCount(1);
        // Simulate a queue delivering too early; the job's native not-before fence must still hold.
        $this->owner()->table('jobs')->update(['available_at' => time()]);
        Queue::connection('database')->pop('bb2.provider.meta')->fire();
        $http->assertSentCount(1);
        $this->travel(45)->seconds();
        Queue::connection('database')->pop('bb2.provider.meta')->fire();
        Queue::connection('database')->pop('bb2.provider.meta')->fire();
        $http->assertSentCount(2);
        $this->assertSame(0, $this->owner()->table('jobs')->count());
        $this->assertSame(2, $this->owner()->table('meta_read_attempts')->count());
        $this->assertSame(2, $this->owner()->table('provider_observation_streams')->value('progress_generation'));
        $this->assertSame(1, $this->owner()->table('provider_observation_entries')->count());
        $this->travelBack();
    }

    public function test_meta_async_queue_polls_transient_task_without_recreating_it_or_advancing_pending_progress(): void
    {
        $scope = $this->metaReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push(['report_run_id' => '999'])
            ->push(['id' => '999', 'account_id' => '123', 'async_status' => 'Job Running', 'async_percent_completion' => 20])
            ->push(['id' => '999', 'account_id' => '123', 'async_status' => 'Job Completed', 'async_percent_completion' => 100])
            ->push(['data' => [$this->metaReaderRow()]])]);
        $this->configureMetaQueue($http);
        $window = $this->metaQueueWindow();
        $task = $this->withinWorkspace(fn ($r) => app(MetaObservationReader::class)->startAsync($r, $scope, $window,
            new ProviderReadBudget($scope, 'meta-native-queue', 4, 0, 4, 10, new DateTimeImmutable('+30 seconds'), 1, 5),
            'v26.0', 'synthetic-app-secret', 1));
        $this->queueMetaRead($scope, $task);
        Queue::connection('database')->pop('bb2.provider.meta')->fire();
        $this->assertSame('PENDING', $this->owner()->table('provider_observation_runs')->value('coverage'));
        $this->assertNull($this->owner()->table('provider_observation_streams')->value('progress_generation'));
        $this->travel(6)->seconds();
        Queue::connection('database')->pop('bb2.provider.meta')->fire();
        $http->assertSentCount(4);
        $this->assertSame(1, count(array_filter($http->recorded()->all(), fn ($record) => $record[0]->method() === 'POST')));
        $this->assertSame(2, $this->owner()->table('provider_observation_streams')->value('progress_generation'));
        $this->assertSame(0, $this->owner()->table('jobs')->count());
        $this->travelBack();
    }

    public function test_meta_delayed_queue_rechecks_current_authority_and_does_not_consume_sibling_work(): void
    {
        $scope = $this->metaReaderScope();
        $google = $this->scopes['google'];
        $this->store($google, (string) Str::uuid());
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['error' => ['code' => 80000]], 400,
            ['X-Ad-Account-Usage' => '{"reset_time_duration":40}'])]);
        $this->configureMetaQueue($http);
        $this->queueMetaRead($scope);
        $this->withinWorkspace(function ($r) use ($google): void {
            $o = $this->journalObservation($google, '10');
            Bus::dispatch(PersistObservationJob::capture($r, new ObservationBatch($google, [$o], 'COMPLETE', null, null),
                'synthetic-range', (string) Str::uuid(), 0, 1, $o->provenance));
        });
        Queue::connection('database')->pop('bb2.provider.meta')->fire();
        $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)->where('action', 'provider.read')
            ->update(['expires_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
        $this->travel(45)->seconds();
        $queued = Queue::connection('database')->pop('bb2.provider.meta');
        $this->denied(fn () => $queued->fire(), '42501');
        $http->assertSentCount(1);
        $this->assertSame(1, $this->owner()->table('meta_read_attempts')->count());
        $this->assertSame(0, $this->owner()->table('jobs')->where('queue', 'bb2.provider.google')->value('attempts'));
        $this->travelBack();
        Queue::connection('database')->pop('bb2.provider.google')->fire();
        $this->assertSame(1, $this->owner()->table('provider_observation_runs')->where('coverage', 'COMPLETE')->count());
    }

    public function test_meta_native_attempt_receipt_failure_rolls_back_results_progress_and_followup_delivery(): void
    {
        $scope = $this->metaReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['data' => [$this->metaReaderRow()]], 200)]);
        $this->configureMetaQueue($http);
        $this->queueMetaRead($scope);
        $this->owner()->unprepared("CREATE FUNCTION bb2_synthetic_meta_attempt_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
            RAISE EXCEPTION 'Synthetic Meta receipt failure' USING ERRCODE='P0001'; END $$;
            CREATE TRIGGER bb2_synthetic_meta_attempt_failure BEFORE INSERT ON meta_read_attempts FOR EACH ROW EXECUTE FUNCTION bb2_synthetic_meta_attempt_failure();");
        try {
            $this->denied(fn () => Queue::connection('database')->pop('bb2.provider.meta')->fire(), 'P0001');
        } finally {
            $this->owner()->unprepared('DROP TRIGGER bb2_synthetic_meta_attempt_failure ON meta_read_attempts; DROP FUNCTION bb2_synthetic_meta_attempt_failure();');
        }
        $http->assertSentCount(1);
        foreach (['meta_read_attempts', 'provider_observation_runs', 'provider_observation_entries', 'provider_observation_streams'] as $table) {
            $this->assertSame(0, $this->owner()->table($table)->count());
        }
        $this->assertSame(1, $this->owner()->table('jobs')->count());
    }

    public function test_meta_native_throttle_beyond_execution_deadline_does_not_retry_earlier(): void
    {
        $scope = $this->metaReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['error' => ['code' => 80000]], 400,
            ['X-Ad-Account-Usage' => '{"reset_time_duration":1140}'])]);
        $this->configureMetaQueue($http);
        $this->queueMetaRead($scope);
        Queue::connection('database')->pop('bb2.provider.meta')->fire();
        $http->assertSentCount(1);
        $this->assertSame(0, $this->owner()->table('jobs')->count());
        $this->assertSame('FAILED', $this->owner()->table('provider_observation_runs')->value('coverage'));
        $this->assertNull($this->owner()->table('provider_observation_streams')->value('progress_generation'));
        $this->assertSame(1, $this->owner()->table('meta_read_attempts')->count());
    }

    public function test_meta_native_cooldown_survives_new_transport_and_stream_but_isolates_custody_epochs(): void
    {
        $scope = $this->metaReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push(['error' => ['code' => 80000]], 400,
            ['X-Ad-Account-Usage' => '{"reset_time_duration":40}'])->push(['data' => [$this->metaReaderRow()]], 200)]);
        $this->assertSame('FAILED', $this->metaRead($scope, $http)['coverage']);
        $this->assertSame('FAILED', $this->metaRead($scope, $http, 'UTC', 'other-meta-stream')['coverage']);
        $http->assertSentCount(1);
        $this->withinWorkspace(function ($r) use ($scope): void {
            $current = new MetaReadCooldown(Cache::store(), $r, $scope, 1, 'v26.0');
            $current->observe(5);
            $this->assertGreaterThanOrEqual(35, $current->remaining());
            $this->assertNull((new MetaReadCooldown(Cache::store(), $r, $scope, 2, 'v26.0'))->remaining());
        });
        $this->assertSame(0, $this->owner()->table('provider_observation_entries')->count());
        $this->travel(45)->seconds();
        $this->assertSame('COMPLETE', $this->metaRead($scope, $http, 'UTC', 'after-native-reset')['coverage']);
        $http->assertSentCount(2);
        $this->travelBack();
    }

    public function test_meta_native_cooldown_integrity_failure_stops_before_http_and_journal(): void
    {
        $scope = $this->metaReaderScope();
        Cache::put($scope->key('meta.cooldown:1:v26.0'), 'synthetic-corrupt-ciphertext', 40);
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        try {
            $this->metaRead($scope, $http);
            $this->fail('An unreadable native cooldown must not be bypassed.');
        } catch (\LogicException $error) {
            $this->assertSame('Meta native cooldown is unavailable.', $error->getMessage());
        }
        $http->assertNothingSent();
        $this->assertSame(0, $this->owner()->table('provider_observation_runs')->count());
    }

    public function test_meta_web_flow_binds_one_button_callback_discovery_selection_and_lost_response_replay(): void
    {
        $http = $this->metaWebTransport();
        $this->configureMetaWeb($http);
        $base = '/workspaces/'.$this->workspace.'/providers/meta';
        $before = $this->owner()->table('security_action_grants')->count();
        $index = $this->getJson($base)->assertOk()->assertJsonPath('availability', 'CONNECT_AVAILABLE');
        $this->assertSame($before, $this->owner()->table('security_action_grants')->count());
        $url = $this->postJson($base.'/connect', ['intent' => $index->json('connect_intent')])->assertOk()->json('authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('999', $query['config_id']);
        $this->assertSame('https://builderad.example/providers/meta/callback', $query['redirect_uri']);
        $http->assertSentCount(0);
        $callback = $this->getJson('/providers/meta/callback?'.http_build_query(['state' => $query['state'], 'code' => 'synthetic-private-code']))
            ->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertJsonPath('status', 'ACCOUNT_SELECTION_REQUIRED');
        $this->assertStringNotContainsString('synthetic-private-code', $callback->getContent());
        $this->assertStringNotContainsString('synthetic-web-token', $callback->getContent());
        $this->assertNull(session('_old_input.code'));
        $http->assertSentCount(3);
        $this->getJson('/providers/meta/callback?'.http_build_query(['state' => $query['state'], 'code' => 'replayed-private-code']))->assertForbidden();
        $http->assertSentCount(3);
        $page = $this->getJson($base.'/discovery/'.$callback->json('discovery_handle'))->assertOk()->assertJsonPath('coverage', 'COMPLETE');
        $this->assertSame('123', $page->json('accounts.0.account_id'));
        $choice = $page->json('accounts.0.selection_handle');
        $selection = $this->postJson($base.'/select', ['selection' => $choice])->assertOk()
            ->assertJsonPath('receipt.version', 1)->assertJsonPath('access.connection', 'CONNECTED')
            ->assertJsonPath('access.axes.privacy_purpose', 'UNKNOWN')->assertJsonPath('access.effective.state', 'UNKNOWN');
        $http->assertSentCount(10);
        $again = $this->postJson($base.'/select', ['selection' => $choice])->assertOk();
        $this->assertSame($selection->json('source_id'), $again->json('source_id'));
        $this->assertSame($selection->json('receipt'), $again->json('receipt'));
        $http->assertSentCount(10);
        $this->assertSame(1, $this->owner()->table('provider_access')->count());
        $this->assertStringNotContainsString('synthetic-web-token', $selection->getContent());
        $this->assertStringNotContainsString('synthetic-app-secret', $index->getContent());
        $this->assertSame(0, $this->owner()->table('provider_observation_runs')->count());
    }

    public function test_meta_web_flow_reconnects_visible_existing_source_and_preserves_its_identity(): void
    {
        $existing = $this->metaReaderScope();
        $http = $this->metaWebTransport();
        $this->configureMetaWeb($http);
        $base = '/workspaces/'.$this->workspace.'/providers/meta';
        $intent = $this->getJson($base)->assertOk()->json('connect_intent');
        $url = $this->postJson($base.'/connect', ['intent' => $intent])->assertOk()->json('authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $callback = $this->getJson('/providers/meta/callback?'.http_build_query(['state' => $query['state'], 'code' => 'synthetic-code']))->assertOk();
        $page = $this->getJson($base.'/discovery/'.$callback->json('discovery_handle'))->assertOk();
        $this->assertNotNull($page->json('accounts.0.existing_source_label'));
        $result = $this->postJson($base.'/select', ['selection' => $page->json('accounts.0.selection_handle')])->assertOk()
            ->assertJsonPath('source_id', $existing->sourceId)->assertJsonPath('receipt.version', 2)
            ->assertJsonPath('access.axes.privacy_purpose', 'UNKNOWN');
        $this->assertSame(1, $this->owner()->table('provider_access')->count());
        $this->assertSame(1, $this->owner()->table('source_registry')->where('family', 'meta')->where('namespace', 'ad_account')->where('declared_external_id', '123')->count());
        $this->assertSame('reconnect', $this->owner()->table('provider_access_events')->orderByDesc('version')->value('effect'));
        $this->assertStringNotContainsString('synthetic-native-token', $result->getContent());
    }

    public function test_meta_web_flow_configuration_notice_changes_and_extra_input_never_become_authority(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        $this->configureMetaWeb($http);
        $base = '/workspaces/'.$this->workspace.'/providers/meta';
        $before = $this->owner()->table('security_action_grants')->count();
        $intent = $this->getJson($base)->assertOk()->json('connect_intent');
        $this->postJson($base.'/connect', ['intent' => $intent, 'access_token' => 'synthetic-injected-token'])->assertUnprocessable();
        $this->assertSame($before, $this->owner()->table('security_action_grants')->count());
        $url = $this->postJson($base.'/connect', ['intent' => $intent])->assertOk()->json('authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        config()->set('services.meta_ads.notice_revision', 'synthetic-revised-notice');
        $this->getJson('/providers/meta/callback?'.http_build_query(['state' => $query['state'], 'code' => 'synthetic-code']))->assertForbidden();
        $this->postJson($base.'/connect', ['intent' => $intent])->assertForbidden();
        $http->assertSentCount(0);
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
        config()->set('services.meta_ads.privacy_url', null);
        $this->getJson($base)->assertOk()->assertJsonPath('availability', 'CONFIGURATION_REQUIRED')->assertJsonPath('connect_intent', null);
        $this->postJson($base.'/connect', ['intent' => $intent])->assertStatus(503);
    }

    public function test_meta_web_callback_cancellation_consumes_state_without_exchanging_or_flashing_provider_text(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        $this->configureMetaWeb($http);
        $base = '/workspaces/'.$this->workspace.'/providers/meta';
        $intent = $this->getJson($base)->assertOk()->json('connect_intent');
        $url = $this->postJson($base.'/connect', ['intent' => $intent])->assertOk()->json('authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $response = $this->getJson('/providers/meta/callback?'.http_build_query(['state' => $query['state'], 'error' => 'access_denied',
            'error_description' => 'synthetic-private-provider-text']))->assertOk()->assertJsonPath('status', 'AUTHORIZATION_NOT_COMPLETED');
        $this->assertStringNotContainsString('synthetic-private-provider-text', $response->getContent());
        $this->getJson('/providers/meta/callback?'.http_build_query(['state' => $query['state'], 'code' => 'synthetic-code']))->assertForbidden();
        $this->getJson('/providers/meta/callback?state=bad&code=synthetic-secret')->assertStatus(400);
        $this->assertNull(session('_old_input.code'));
        $http->assertSentCount(0);
    }

    public function test_meta_native_permission_loss_is_visible_and_blocks_future_transport_until_reconnect(): void
    {
        $scope = $this->metaReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['error' => ['code' => 10, 'message' => 'synthetic-private-denial']], 400)]);
        $this->assertSame('FAILED', $this->metaRead($scope, $http)['coverage']);
        $status = $this->withinWorkspace(fn ($r) => (new MetaAccessStatus)->snapshot($r, $scope));
        $this->assertSame('RECONNECT_REQUIRED', $status['connection']);
        $this->assertSame('REVOKED', $status['axes']['provider_permission']);
        $this->assertSame('VERIFICATION_NEEDED', $status['effective']['state']);
        try {
            $this->metaRead($scope, $http, 'UTC', 'attempt-after-native-denial');
            $this->fail('Known native permission loss must stop before another HTTP request.');
        } catch (AuthorizationException) {
            $http->assertSentCount(1);
        }
        $this->assertSame(1, $this->owner()->table('provider_observation_runs')->count());
        $this->assertSame(0, $this->owner()->table('provider_observation_entries')->count());
        // Current signed reconnect replaces this epoch; old native denials cannot poison its state.
        $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->store($r, $scope, (string) Str::uuid(), 1,
            new ProviderCredentials(['access_token' => 'synthetic-reverified-token']), null, 'CONNECTED', $this->evidence(), true));
        $status = $this->withinWorkspace(fn ($r) => (new MetaAccessStatus)->snapshot($r, $scope));
        $this->assertSame(2, $status['version']);
        $this->assertSame('CONNECTED', $status['connection']);
    }

    public function test_meta_web_browser_callback_returns_only_opaque_fragment_to_its_workspace(): void
    {
        $http = $this->metaWebTransport();
        $this->configureMetaWeb($http);
        $base = '/workspaces/'.$this->workspace.'/providers/meta';
        $intent = $this->getJson($base)->assertOk()->json('connect_intent');
        $url = $this->postJson($base.'/connect', ['intent' => $intent])->assertOk()->json('authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $result = $this->get('/providers/meta/callback?'.http_build_query(['state' => $query['state'], 'code' => 'synthetic-code']))
            ->assertRedirect()->assertHeader('Referrer-Policy', 'no-referrer');
        $location = $result->headers->get('Location');
        $this->assertStringStartsWith(route('workspaces.show', $this->workspace).'#meta-flow=', $location);
        $handle = substr($location, strpos($location, '#meta-flow=') + 11);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $handle);
        foreach (['synthetic-code', 'synthetic-native-token', $query['state']] as $secret) {
            $this->assertStringNotContainsString($secret, $location);
        }
        $this->getJson($base.'/discovery/'.$handle)->assertOk()->assertJsonPath('accounts.0.account_id', '123');
        $http->assertSentCount(6);
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
    }

    public function test_meta_web_disconnect_without_configuration_revokes_only_local_selected_custody_and_replays(): void
    {
        $scope = $this->metaReaderScope();
        $google = $this->scopes['google'];
        $this->store($google, (string) Str::uuid());
        config()->set('services.meta_ads', []);
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        $this->app->instance(Factory::class, $http);
        $base = '/workspaces/'.$this->workspace.'/providers/meta';
        $command = ['command_id' => (string) Str::uuid(), 'expected_version' => 1];
        $this->postJson($base.'/'.$google->sourceId.'/disconnect', $command)->assertNotFound();
        $this->postJson($base.'/'.$scope->sourceId.'/disconnect', $command + ['remote_revoke' => true])->assertUnprocessable();
        $first = $this->postJson($base.'/'.$scope->sourceId.'/disconnect', $command)->assertOk()
            ->assertJsonPath('receipt.version', 2)->assertJsonPath('receipt.local_state', 'REVOKED')
            ->assertJsonPath('access.connection', 'LOCALLY_DISCONNECTED')->assertJsonPath('access.effective.state', 'REVOKED');
        $again = $this->postJson($base.'/'.$scope->sourceId.'/disconnect', $command)->assertOk();
        $this->assertSame($first->json('receipt'), $again->json('receipt'));
        $this->assertNull($this->owner()->table('provider_access')->where('source_id', $scope->sourceId)->value('encrypted_credentials'));
        $this->assertNotNull($this->owner()->table('provider_access')->where('source_id', $google->sourceId)->value('encrypted_credentials'));
        $this->assertSame(1, $this->owner()->table('provider_access_events')->where('effect', 'revoke')->count());
        $this->assertSame(0, $this->owner()->table('provider_observation_runs')->count());
        $this->getJson($base)->assertOk()->assertJsonPath('availability', 'CONFIGURATION_REQUIRED');
        $http->assertNothingSent();
    }

    public function test_google_native_reader_accepts_exact_micros_and_encrypted_progress(): void
    {
        $scope = $this->googleReaderScope();
        $http = $this->googleReaderTransport();
        $receipt = $this->googleRead($scope, $http);
        $http->assertSentCount(2);
        $this->assertSame(1, $receipt['generation']);
        $history = $this->withinWorkspace(fn ($r) => app(ObservationJournal::class)->history($r, $scope, 'google-integrated'));
        $this->assertCount(1, $history);
        $this->assertSame('9007199254740993', $history[0]['observation']['spend']['native_value']);
        $this->assertSame('JOD', $history[0]['observation']['spend']['currency']);
        $progress = $this->withinWorkspace(fn ($r) => app(ObservationJournal::class)->progress($r, $scope, 'google-integrated', 1));
        $this->assertSame('google.accepted_range', $progress->kind);
        $this->assertSame(1, $this->owner()->table('provider_observation_streams')->value('progress_generation'));
    }

    public function test_google_native_reader_missing_purpose_verification_never_sends_http(): void
    {
        $proof = $this->evidence();
        $proof['privacy_purpose'] = ['state' => 'UNKNOWN'];
        $scope = $this->googleReaderScope($proof);
        $http = $this->googleReaderTransport();
        $this->denied(fn () => $this->googleRead($scope, $http), '42501');
        $http->assertNothingSent();
        $this->assertSame(0, $this->owner()->table('provider_observation_runs')->count());
    }

    public function test_google_native_reader_expired_authority_between_requests_rolls_back(): void
    {
        $scope = $this->googleReaderScope();
        $grantId = $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)
            ->where('action', 'provider.read')->value('id');
        $this->owner()->table('security_action_grants')->where('id', $grantId)
            ->update(['expires_at' => DB::raw("clock_timestamp()+interval '2 seconds'")]);
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(function () use ($http, $grantId) {
            $deadline = microtime(true) + 5;
            do {
                $expired = $this->owner()->scalar('SELECT expires_at <= clock_timestamp() FROM security_action_grants WHERE id=?', [$grantId]);
                if ($expired) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertTrue((bool) $expired);

            return $http->response($this->googleReaderMetadata());
        });
        $this->denied(fn () => $this->googleRead($scope, $http), '42501');
        $http->assertSentCount(1);
        foreach (['provider_observation_streams', 'provider_observation_runs', 'provider_observation_entries'] as $table) {
            $this->assertSame(0, $this->owner()->table($table)->count());
        }
    }

    private function googleReaderScope(?array $proof = null, bool $connect = true): ProviderScope
    {
        $scope = new ProviderScope($this->workspaceId, (string) Str::uuid7(), Provider::Google, '1234567890', 'report.read', 'advertising_analysis');
        foreach (GrantAuthority::SOURCE_ACTIONS as $action) {
            $this->grant($scope, $action);
        }
        $this->postJson('/workspaces/'.$this->workspace.'/sources', ['source_id' => $scope->sourceId, 'command_id' => (string) Str::uuid(),
            'effect' => 'register', 'family' => 'google', 'namespace' => 'ad_account', 'external_id' => $scope->accountId,
            'label' => 'Synthetic native Google account', 'resource' => $scope->accountId, 'operation' => $scope->operation,
            'purpose' => $scope->purpose, 'expected_version' => 0, 'reason' => 'synthetic_registration'])->assertOk();
        if (! $connect) {
            return $scope;
        }
        $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->store($r, $scope, (string) Str::uuid(), 0,
            new ProviderCredentials(['access_token' => 'synthetic-google-token', 'account_id' => $scope->accountId,
                'account_timezone' => 'Asia/Amman', 'account_currency' => 'JOD',
                'granted_scope' => GoogleOAuthClient::SCOPE, 'expires_at' => (string) (time() + 600)]),
            new DateTimeImmutable('+10 minutes'), 'SYNTHETIC_AUTHORIZED', $proof ?? $this->evidence()));

        return $scope;
    }

    private function googleReaderMetadata(): array
    {
        return ['fieldMask' => 'customer.id,customer.manager,customer.currencyCode,customer.timeZone',
            'results' => [['customer' => ['id' => '1234567890', 'manager' => false, 'currencyCode' => 'JOD', 'timeZone' => 'Asia/Amman']]]];
    }

    private function googleReaderTransport(): Factory
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push($this->googleReaderMetadata())->push([
            'fieldMask' => 'customer.id,customer.currencyCode,customer.timeZone,campaign.id,campaign.resourceName,campaign.status,metrics.costMicros,metrics.impressions,metrics.clicks,metrics.conversions,metrics.allConversions,metrics.conversionsValue',
            'results' => [['customer' => ['id' => '1234567890', 'currencyCode' => 'JOD', 'timeZone' => 'Asia/Amman'],
                'campaign' => ['id' => '456', 'resourceName' => 'customers/1234567890/campaigns/456'],
                'metrics' => ['costMicros' => '9007199254740993', 'impressions' => '10', 'clicks' => '0']]],
        ])]);

        return $http;
    }

    private function googleRead(ProviderScope $scope, Factory $http): array
    {
        $reader = new GoogleObservationReader($http, app(ObservationJournal::class),
            app(ProviderAccessStore::class), app(ProviderRequestGuard::class));

        return $this->withinWorkspace(fn ($r) => $reader->read($r, $scope,
            new ReportWindow('2026-09-01', '2026-09-02', 'INCLUSIVE_DATES', 'TOTAL', 'Asia/Amman', 'synthetic:native-timezone'),
            new ProviderReadBudget($scope, 'google-integrated', 4, 0, 4, 10, new DateTimeImmutable('+30 seconds'), 1, 5),
            'v25', '', 'google-integrated', (string) Str::uuid(), 0, 1));
    }

    public function test_google_campaign_status_restatement_keeps_immutable_prior_state_and_native_identity(): void
    {
        $scope = $this->googleReaderScope();
        $page = ['fieldMask' => 'customer.id,customer.currencyCode,customer.timeZone,campaign.id,campaign.resourceName,campaign.status,metrics.costMicros,metrics.impressions,metrics.clicks,metrics.conversions,metrics.allConversions,metrics.conversionsValue',
            'results' => [['customer' => ['id' => '1234567890', 'currencyCode' => 'JOD', 'timeZone' => 'Asia/Amman'],
                'campaign' => ['id' => '456', 'resourceName' => 'customers/1234567890/campaigns/456', 'status' => 'PAUSED'],
                'metrics' => ['costMicros' => '1', 'impressions' => '0', 'clicks' => '0']]]];
        $changed = $page;
        $changed['results'][0]['campaign']['status'] = 'ENABLED';
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push($this->googleReaderMetadata())->push($page)
            ->push($this->googleReaderMetadata())->push($changed)]);
        $this->googleRead($scope, $http);
        $reader = new GoogleObservationReader($http, app(ObservationJournal::class), app(ProviderAccessStore::class), app(ProviderRequestGuard::class));
        $this->withinWorkspace(fn ($r) => $reader->read($r, $scope,
            new ReportWindow('2026-09-01', '2026-09-02', 'INCLUSIVE_DATES', 'TOTAL', 'Asia/Amman', 'synthetic:native-timezone'),
            new ProviderReadBudget($scope, 'google-integrated', 4, 0, 4, 10, new DateTimeImmutable('+30 seconds'), 1, 5),
            'v25', '', 'google-integrated', (string) Str::uuid(), 1, 1));
        $history = $this->withinWorkspace(fn ($r) => app(ObservationJournal::class)->history($r, $scope, 'google-integrated'));
        $this->assertCount(2, $history);
        $this->assertSame('PAUSED', $history[1]['observation']['native_facts']['campaign_status']['value']);
        $this->assertSame('ENABLED', $history[0]['observation']['native_facts']['campaign_status']['value']);
        $this->assertSame($history[1]['identity'], $history[0]['identity']);
        $this->assertSame('RESTATEMENT', $history[0]['relation']);
        $this->assertSame(1, $history[0]['prior_generation']);
        $this->assertSame(2, $this->owner()->table('provider_observation_streams')->value('progress_generation'));
        $http->assertSentCount(4);
    }

    public function test_google_callback_state_is_encrypted_exact_bound_and_consumed_even_after_rollback(): void
    {
        $scope = $this->scopes['google'];
        $states = new GoogleOAuthState(Cache::store(), app(ProviderRequestGuard::class));
        $configuration = hash('sha256', 'synthetic-configuration');
        $state = $this->withinWorkspace(fn ($r) => $states->issue($r, $scope, 0, $configuration));
        $key = 'bb2.google.oauth.'.hash('sha256', $state);
        $ciphertext = Cache::get($key);
        $this->assertIsString($ciphertext);
        $this->assertStringNotContainsString($scope->sourceId, $ciphertext);
        $this->assertStringNotContainsString($configuration, $ciphertext);
        try {
            $this->withinWorkspace(fn ($r) => $states->consume($r, $scope, 0, hash('sha256', 'another-configuration'), $state));
            $this->fail('A changed Google configuration must not consume callback state.');
        } catch (AuthorizationException) {
            $this->assertSame($ciphertext, Cache::get($key));
        }
        try {
            $this->withinWorkspace(function ($r) use ($states, $scope, $configuration, $state): void {
                $states->consume($r, $scope, 0, $configuration, $state);
                throw new \RuntimeException('Synthetic post-consumption provider failure.');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic post-consumption provider failure.', $error->getMessage());
        }
        $this->assertNull(Cache::get($key));
        try {
            $this->withinWorkspace(fn ($r) => $states->consume($r, $scope, 0, $configuration, $state));
            $this->fail('A callback state must never be replayed.');
        } catch (AuthorizationException) {
            $this->assertSame(0, $this->owner()->table('provider_access_events')->count());
        }
    }

    public function test_google_callback_state_is_not_published_on_rollback_and_never_replaces_current_authority(): void
    {
        $scope = $this->scopes['google'];
        $states = new GoogleOAuthState(Cache::store(), app(ProviderRequestGuard::class));
        $configuration = hash('sha256', 'synthetic-configuration');
        $rolledBack = null;
        try {
            $this->withinWorkspace(function ($r) use ($states, $scope, $configuration, &$rolledBack): void {
                $rolledBack = $states->issue($r, $scope, 0, $configuration);
                throw new \RuntimeException('Synthetic initiation rollback.');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic initiation rollback.', $error->getMessage());
        }
        $this->assertNotNull($rolledBack);
        $this->assertNull(Cache::get('bb2.google.oauth.'.hash('sha256', $rolledBack)));
        $state = $this->withinWorkspace(fn ($r) => $states->issue($r, $scope, 0, $configuration));
        $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)->where('action', 'provider.connect')
            ->update(['expires_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => $states->consume($r, $scope, 0, $configuration, $state)), '42501');
        $this->assertIsString(Cache::get('bb2.google.oauth.'.hash('sha256', $state)));
        $this->assertSame(0, $this->owner()->table('provider_access_events')->count());
    }

    public function test_google_pending_acquisition_is_encrypted_guarded_and_does_not_assert_ready_custody(): void
    {
        $scope = $this->scopes['google'];
        $pending = new GooglePendingAccess(Cache::store(), app(ProviderRequestGuard::class));
        $configuration = hash('sha256', 'synthetic-configuration');
        $axes = array_fill_keys(EffectiveCapability::AXES, ['state' => 'UNKNOWN', 'provenance' => 'synthetic:unestablished']);
        $material = new ProviderAccessMaterial($scope, new ProviderCredentials(['access_token' => 'synthetic-pending-secret']), null, 'CONNECTED', $axes);
        $handle = $this->withinWorkspace(fn ($r) => $pending->stage($r, $material, 0, $configuration));
        $ciphertext = Cache::get('bb2.google.pending.'.hash('sha256', $handle));
        $this->assertIsString($ciphertext);
        $this->assertStringNotContainsString('synthetic-pending-secret', $ciphertext);
        $loaded = $this->withinWorkspace(fn ($r) => $pending->material($r, $scope, 0, $configuration, $handle));
        $this->assertSame('synthetic-pending-secret', $loaded->credentials->value('access_token'));
        $this->assertNull($loaded->expiresAt);
        $this->assertSame($axes, $loaded->verification);
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
        try {
            $this->withinWorkspace(fn ($r) => $pending->material($r, $scope, 0, hash('sha256', 'changed-configuration'), $handle));
            $this->fail('A pending acquisition cannot change its configuration binding.');
        } catch (AuthorizationException) {
            $this->assertSame($ciphertext, Cache::get('bb2.google.pending.'.hash('sha256', $handle)));
        }
        $this->withinWorkspace(fn ($r) => $pending->discard($r, $scope, 0, $configuration, $handle));
        $this->assertNull(Cache::get('bb2.google.pending.'.hash('sha256', $handle)));
    }

    public function test_google_pending_acquisition_rolls_back_publication_and_cannot_bypass_expired_connect_authority(): void
    {
        $scope = $this->scopes['google'];
        $pending = new GooglePendingAccess(Cache::store(), app(ProviderRequestGuard::class));
        $configuration = hash('sha256', 'synthetic-configuration');
        $material = new ProviderAccessMaterial($scope, new ProviderCredentials(['access_token' => 'synthetic-pending-secret']), null, 'CONNECTED', []);
        $rolledBack = null;
        try {
            $this->withinWorkspace(function ($r) use ($pending, $material, $configuration, &$rolledBack): void {
                $rolledBack = $pending->stage($r, $material, 0, $configuration);
                throw new \RuntimeException('Synthetic staging rollback.');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic staging rollback.', $error->getMessage());
        }
        $this->assertNotNull($rolledBack);
        $this->assertNull(Cache::get('bb2.google.pending.'.hash('sha256', $rolledBack)));
        $handle = $this->withinWorkspace(fn ($r) => $pending->stage($r, $material, 0, $configuration));
        $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)->where('action', 'provider.connect')
            ->update(['expires_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => $pending->material($r, $scope, 0, $configuration, $handle)), '42501');
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
    }

    public function test_google_connection_callback_selection_and_lost_response_replay_preserve_unknown_policy(): void
    {
        $installation = $this->googleInstallationScope();
        $selected = $this->googleReaderScope();
        // Reader helper's synthetic preexisting epoch makes this an explicit reconnect.
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push($this->googleConnectionToken())
            ->push(['resourceNames' => ['customers/1234567890']])->push($this->googleReaderMetadata())
            ->push(['fieldMask' => 'campaign.id', 'results' => []])]);
        $flow = $this->googleCoordinator($http);
        $url = $this->withinWorkspace(fn ($r) => $flow->authorizationUrl($r, $installation));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $handle = $this->withinWorkspace(fn ($r) => $flow->callback($r, $installation, 0, $query['state'], 'synthetic-code'));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $handle);
        $discovery = $this->withinWorkspace(fn ($r) => $flow->discover($r, $installation, 0, $handle));
        $this->assertSame('1234567890', $discovery->accounts[0]['id']);
        $command = (string) Str::uuid();
        $receipt = $this->withinWorkspace(fn ($r) => $flow->select($r, $installation, 0, $handle, $selected, $command, 1));
        $this->assertSame(2, $receipt['version']);
        $this->assertNull(Cache::get('bb2.google.pending.'.hash('sha256', $handle)));
        $replay = $this->withinWorkspace(fn ($r) => $flow->select($r, $installation, 0, $handle, $selected, $command, 1));
        $this->assertSame($receipt, $replay);
        $http->assertSentCount(4);
        // Native connection must not inherit the fixture's policy approval.
        $this->denied(fn () => $this->googleRead($selected, $http), '42501');
        $http->assertSentCount(4);
        $this->assertSame(0, $this->owner()->table('provider_observation_runs')->count());
        $http->assertNotSent(fn ($r) => $r->hasHeader('developer-token'));
    }

    public function test_google_callback_failure_consumes_state_and_never_publishes_credentials(): void
    {
        $installation = $this->googleInstallationScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['error' => 'invalid_grant'], 400)]);
        $flow = $this->googleCoordinator($http);
        $url = $this->withinWorkspace(fn ($r) => $flow->authorizationUrl($r, $installation));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        try {
            $this->withinWorkspace(fn ($r) => $flow->callback($r, $installation, 0, $query['state'], 'synthetic-code'));
            $this->fail('Failed exchange cannot stage credentials.');
        } catch (GoogleRequestFailed $error) {
            $this->assertSame('google_reconnect_required', $error->reason);
        }
        try {
            $this->withinWorkspace(fn ($r) => $flow->callback($r, $installation, 0, $query['state'], 'synthetic-code'));
            $this->fail('Failed callback cannot replay its state.');
        } catch (AuthorizationException) {
            $http->assertSentCount(1);
        }
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
    }

    public function test_google_callback_configuration_rotation_invalidates_state_before_exchange(): void
    {
        $installation = $this->googleInstallationScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        $flow = $this->googleCoordinator($http);
        $url = $this->withinWorkspace(fn ($r) => $flow->authorizationUrl($r, $installation));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $rotated = $this->googleCoordinator($http, 'synthetic-rotated-secret');
        $this->assertNotSame($flow->configurationBinding(), $rotated->configurationBinding());
        try {
            $this->withinWorkspace(fn ($r) => $rotated->callback($r, $installation, 0, $query['state'], 'synthetic-code'));
            $this->fail('Rotated OAuth configuration must invalidate in-flight state.');
        } catch (AuthorizationException) {
            $http->assertNothingSent();
            $this->assertIsString(Cache::get('bb2.google.oauth.'.hash('sha256', $query['state'])));
        }
    }

    public function test_google_callback_routing_rejects_changed_session_generation_and_expired_state(): void
    {
        $scope = $this->scopes['google'];
        $states = new GoogleOAuthState(Cache::store(), app(ProviderRequestGuard::class));
        $configuration = hash('sha256', 'synthetic-google-configuration');
        $state = $this->withinWorkspace(fn ($r) => $states->issue($r, $scope, 0, $configuration));
        $located = $this->withinWorkspace(fn ($r) => $states->locate($r, $configuration, $state));
        $this->assertSame($this->workspace, $located['workspace']);
        $this->assertSame($scope->jsonSerialize(), $located['scope']->jsonSerialize());
        try {
            $this->withinWorkspace(function ($r) use ($states, $configuration, $state): void {
                $generation = $r->session()->get('a001_generation');
                try {
                    $r->session()->put('a001_generation', (int) $generation + 1);
                    $states->locate($r, $configuration, $state);
                } finally {
                    $r->session()->put('a001_generation', $generation);
                }
            });
            $this->fail('Changed principal generation cannot route OAuth state.');
        } catch (AuthorizationException) {
            $this->assertIsString(Cache::get('bb2.google.oauth.'.hash('sha256', $state)));
        }
        $this->travel(301)->seconds();
        try {
            $this->withinWorkspace(fn ($r) => $states->locate($r, $configuration, $state));
            $this->fail('Expired correlation cannot route a callback.');
        } catch (AuthorizationException) {
            $this->assertSame(0, $this->owner()->table('provider_access')->count());
        } finally {
            $this->travelBack();
        }
    }

    public function test_google_web_flow_encrypts_native_continuation_and_requires_current_authority(): void
    {
        $this->assertTrue(class_exists(GoogleFlowState::class), 'Google flow state must be implemented.');
        $scope = $this->googleInstallationScope();
        $flow = new GoogleFlowState(Cache::store(), app(ProviderRequestGuard::class));
        $configuration = hash('sha256', 'synthetic-google-web-configuration');
        $data = ['pending' => 'synthetic-private-pending-reference', 'continuation' => ['kind' => 'google.manager_page',
            'payload' => 'synthetic-private-native-cursor'], 'login_customer_id' => '9876543210', 'manager_id' => '9876543210'];
        $handle = $this->withinWorkspace(fn ($r) => $flow->issue($r, $scope, 0, $configuration, 'hierarchy', $data));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $handle);
        $key = 'bb2.google.web.hierarchy.'.hash('sha256', $handle);
        $cipher = Cache::get($key);
        $this->assertIsString($cipher);
        foreach (['synthetic-private-native-cursor', 'synthetic-private-pending-reference', $scope->sourceId] as $private) {
            $this->assertStringNotContainsString($private, $cipher);
        }
        $record = $this->withinWorkspace(fn ($r) => $flow->load($r, $configuration, 'hierarchy', $handle));
        $this->assertSame($scope->jsonSerialize(), $record['scope']->jsonSerialize());
        $this->assertSame($data, $record['data']);
        $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)
            ->where('action', 'provider.connect')->update(['expires_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => $flow->load($r, $configuration, 'hierarchy', $handle)), '42501');
        $this->assertSame($cipher, Cache::get($key));
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
    }

    public function test_google_web_flow_rejects_wrong_kind_configuration_and_expiry(): void
    {
        $this->assertTrue(class_exists(GoogleFlowState::class), 'Google flow state must be implemented.');
        $scope = $this->googleInstallationScope();
        $flow = new GoogleFlowState(Cache::store(), app(ProviderRequestGuard::class));
        $configuration = hash('sha256', 'synthetic-google-web-configuration');
        $handle = $this->withinWorkspace(fn ($r) => $flow->issue($r, $scope, 0, $configuration, 'discovery', ['pending' => 'private']));
        foreach ([[$configuration, 'selection'], [hash('sha256', 'changed'), 'discovery']] as [$binding, $kind]) {
            try {
                $this->withinWorkspace(fn ($r) => $flow->load($r, $binding, $kind, $handle));
                $this->fail('Flow must reject changed configuration or kind.');
            } catch (AuthorizationException) {
                $this->assertSame(0, $this->owner()->table('provider_access')->count());
            }
        }
        $this->travel(301)->seconds();
        try {
            $this->withinWorkspace(fn ($r) => $flow->load($r, $configuration, 'discovery', $handle));
            $this->fail('Expired flow handle cannot restore a native cursor.');
        } catch (AuthorizationException) {
            $this->assertSame(0, $this->owner()->table('provider_access')->count());
        } finally {
            $this->travelBack();
        }
    }

    public function test_google_web_flow_rollback_never_publishes_handle_and_oversized_payload_is_rejected(): void
    {
        $this->assertTrue(class_exists(GoogleFlowState::class), 'Google flow state must be implemented.');
        $scope = $this->googleInstallationScope();
        $flow = new GoogleFlowState(Cache::store(), app(ProviderRequestGuard::class));
        $configuration = hash('sha256', 'synthetic-google-web-configuration');
        $handle = null;
        try {
            $this->withinWorkspace(function ($r) use ($flow, $scope, $configuration, &$handle): void {
                $handle = $flow->issue($r, $scope, 0, $configuration, 'discovery', ['pending' => 'private']);
                throw new \RuntimeException('Synthetic flow rollback.');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic flow rollback.', $error->getMessage());
        }
        $this->assertNotNull($handle);
        $this->assertNull(Cache::get('bb2.google.web.discovery.'.hash('sha256', $handle)));
        try {
            $this->withinWorkspace(fn ($r) => $flow->issue($r, $scope, 0, $configuration, 'discovery', ['pending' => str_repeat('x', 65536)]));
            $this->fail('Unbounded server correlation is forbidden.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('limit', $error->getMessage());
        }
    }

    public function test_google_http_flow_connects_existing_source_without_exposing_credentials_and_replays_selection(): void
    {
        $installation = $this->googleInstallationScope();
        $selected = $this->googleReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push($this->googleConnectionToken())
            ->push(['resourceNames' => ['customers/1234567890']])->push($this->googleReaderMetadata())
            ->push(['fieldMask' => 'campaign.id', 'results' => []])]);
        $this->configureGoogleHttp($http);
        $base = '/workspaces/'.$this->workspace.'/providers/google';
        $url = $this->postJson($base.'/connect', ['source_id' => $installation->sourceId, 'expected_version' => 0])
            ->assertOk()->json('authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $callback = $this->getJson('/providers/google/callback?'.http_build_query(['state' => $query['state'], 'code' => 'synthetic-code']))
            ->assertOk()->assertJsonPath('status', 'ACCOUNT_SELECTION_REQUIRED');
        $page = $this->getJson($base.'/discovery/'.$callback->json('discovery_handle'))->assertOk();
        $handle = $page->json('accounts.0.selection_handle');
        $this->assertIsString($handle);
        $first = $this->postJson($base.'/select', ['selection' => $handle])->assertOk()
            ->assertJsonPath('source_id', $selected->sourceId)->assertJsonPath('receipt.version', 2);
        $replay = $this->postJson($base.'/select', ['selection' => $handle])->assertOk();
        $this->assertSame($first->json('receipt'), $replay->json('receipt'));
        foreach ([$callback, $page, $first, $replay] as $response) {
            $response->assertHeader('Cache-Control', 'no-store, private');
            foreach (['synthetic-new-access', 'synthetic-new-refresh', 'synthetic-client-secret', 'synthetic-code'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
        }
        $http->assertSentCount(4);
        $this->assertSame(0, $this->owner()->table('provider_observation_runs')->count());
    }

    public function test_google_http_flow_rejects_injected_credentials_changed_configuration_and_other_workspace(): void
    {
        $installation = $this->googleInstallationScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response($this->googleConnectionToken())]);
        $this->configureGoogleHttp($http);
        $base = '/workspaces/'.$this->workspace.'/providers/google';
        $this->postJson($base.'/connect', ['source_id' => $installation->sourceId, 'expected_version' => 0, 'access_token' => 'injected'])->assertUnprocessable();
        $url = $this->postJson($base.'/connect', ['source_id' => $installation->sourceId, 'expected_version' => 0])->assertOk()->json('authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        config()->set('services.google_ads.client_secret', 'synthetic-rotated-secret');
        $this->getJson('/providers/google/callback?'.http_build_query(['state' => $query['state'], 'code' => 'synthetic-code']))->assertForbidden();
        $http->assertNothingSent();
        config()->set('services.google_ads.client_secret', 'synthetic-client-secret');
        $callback = $this->getJson('/providers/google/callback?'.http_build_query(['state' => $query['state'], 'code' => 'synthetic-code']))->assertOk();
        $this->getJson('/workspaces/'.Str::uuid().'/providers/google/discovery/'.$callback->json('discovery_handle'))->assertForbidden();
        $http->assertSentCount(1);
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
    }

    public function test_google_http_callback_cancel_consumes_state_and_scrubs_provider_input(): void
    {
        $installation = $this->googleInstallationScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        $this->configureGoogleHttp($http);
        $base = '/workspaces/'.$this->workspace.'/providers/google';
        $url = $this->postJson($base.'/connect', ['source_id' => $installation->sourceId, 'expected_version' => 0])->assertOk()->json('authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $response = $this->getJson('/providers/google/callback?'.http_build_query(['state' => $query['state'], 'error' => 'access_denied',
            'error_description' => 'private-provider-text']))->assertOk()->assertJsonPath('status', 'AUTHORIZATION_NOT_COMPLETED');
        $this->assertStringNotContainsString('private-provider-text', $response->getContent());
        $this->getJson('/providers/google/callback?'.http_build_query(['state' => $query['state'], 'code' => 'synthetic-code']))->assertForbidden();
        $this->getJson('/providers/google/callback?state=bad&code=secret')->assertStatus(400);
        $this->assertNull(session('_old_input.code'));
        $http->assertNothingSent();
    }

    public function test_google_http_missing_privacy_notice_blocks_connect_before_native_http(): void
    {
        $installation = $this->googleInstallationScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        $this->configureGoogleHttp($http);
        config()->set('services.google_ads.privacy_url', null);
        $this->postJson('/workspaces/'.$this->workspace.'/providers/google/connect',
            ['source_id' => $installation->sourceId, 'expected_version' => 0])->assertStatus(503);
        $http->assertNothingSent();
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
    }

    public function test_google_one_button_creates_real_oauth_source_and_new_selected_account_only_after_explicit_actions(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push($this->googleConnectionToken())
            ->push(['resourceNames' => ['customers/1234567890']])->push($this->googleReaderMetadata())
            ->push(['fieldMask' => 'campaign.id', 'results' => []])]);
        $this->configureGoogleHttp($http);
        $base = '/workspaces/'.$this->workspace.'/providers/google';
        $before = $this->owner()->table('security_action_grants')->count();
        $index = $this->getJson($base)->assertOk()->assertJsonPath('availability', 'CONNECT_AVAILABLE');
        $this->assertSame($before, $this->owner()->table('security_action_grants')->count());
        $this->assertSame(0, $this->owner()->table('source_registry')->where('namespace', 'oauth_client')->count());
        $intent = $index->json('connect_intent');
        $url = $this->postJson($base.'/connect', ['intent' => $intent])->assertOk()->json('authorization_url');
        $this->assertSame(1, $this->owner()->table('source_registry')->where('namespace', 'oauth_client')
            ->where('declared_external_id', 'synthetic.apps.googleusercontent.com')->count());
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $callback = $this->getJson('/providers/google/callback?'.http_build_query(['state' => $query['state'], 'code' => 'synthetic-code']))->assertOk();
        $page = $this->getJson($base.'/discovery/'.$callback->json('discovery_handle'))->assertOk();
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
        $choice = $page->json('accounts.0.selection_handle');
        $selected = $this->postJson($base.'/select', ['selection' => $choice])->assertOk()->assertJsonPath('receipt.version', 1);
        $again = $this->postJson($base.'/select', ['selection' => $choice])->assertOk();
        $this->assertSame($selected->json('receipt'), $again->json('receipt'));
        $this->assertSame(1, $this->owner()->table('source_registry')->where('family', 'google')->where('namespace', 'ad_account')
            ->where('declared_external_id', '1234567890')->count());
        $http->assertSentCount(4);
    }

    public function test_google_connect_intent_never_replaces_current_responsible_owner_authority(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        $this->configureGoogleHttp($http);
        $base = '/workspaces/'.$this->workspace.'/providers/google';
        $intent = $this->getJson($base)->assertOk()->json('connect_intent');
        $this->postJson($base.'/connect', ['intent' => $intent, 'source_id' => $this->scopes['google']->sourceId, 'expected_version' => 0])->assertUnprocessable();
        $anotherOwner = User::factory()->create();
        $this->owner()->table('workspace_ownerships')->where('workspace_id', $this->workspaceId)
            ->update(['responsible_owner_user_id' => $anotherOwner->id]);
        $before = $this->owner()->table('security_action_grants')->count();
        $this->getJson($base)->assertForbidden();
        $this->postJson($base.'/connect', ['intent' => $intent])->assertForbidden();
        $this->assertSame($before, $this->owner()->table('security_action_grants')->count());
        $this->assertSame(0, $this->owner()->table('source_registry')->where('namespace', 'oauth_client')->count());
        $http->assertNothingSent();
    }

    public function test_google_browser_callback_missing_offline_grant_returns_safe_recovery_fragment(): void
    {
        $installation = $this->googleInstallationScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(array_diff_key($this->googleConnectionToken(), ['refresh_token' => true]))]);
        $this->configureGoogleHttp($http);
        $url = $this->postJson('/workspaces/'.$this->workspace.'/providers/google/connect',
            ['source_id' => $installation->sourceId, 'expected_version' => 0])->assertOk()->json('authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $response = $this->get('/providers/google/callback?'.http_build_query(['state' => $query['state'], 'code' => 'synthetic-private-code']),
            ['Accept' => 'text/html'])->assertRedirect();
        $this->assertSame(route('workspaces.show', $this->workspace).'#google-result=OFFLINE_GRANT_REQUIRED', $response->headers->get('Location'));
        $this->assertStringNotContainsString('synthetic-private-code', $response->headers->get('Location'));
        $this->assertStringNotContainsString('synthetic-new-access', $response->headers->get('Location'));
        $http->assertSentCount(1);
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
    }

    public function test_google_manager_http_flow_preserves_root_across_nested_paging_and_selection(): void
    {
        $installation = $this->googleInstallationScope();
        $selected = $this->googleReaderScope();
        $root = '5555555555';
        $sub = '6666666666';
        $metadata = $this->googleReaderMetadata();
        $metadata['results'][0]['customer']['id'] = $root;
        $metadata['results'][0]['customer']['manager'] = true;
        $direct = ['resourceNames' => ['customers/'.$root]];
        $row = static fn (string $id, bool $manager, string $level) => ['customerClient' => [
            'id' => $id, 'clientCustomer' => 'customers/'.$id, 'descriptiveName' => 'Synthetic '.$id,
            'manager' => $manager, 'level' => $level]];
        $mask = 'customerClient.id,customerClient.clientCustomer,customerClient.descriptiveName,customerClient.level,customerClient.manager';
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push($this->googleConnectionToken())->push($direct)
            ->push($direct)->push($metadata)->push(['fieldMask' => $mask,
                'results' => [$row($root, true, '0'), $row($sub, true, '1')], 'nextPageToken' => 'synthetic-native-cursor'])
            ->push($direct)->push($metadata)->push(['fieldMask' => $mask, 'results' => [$row('7777777777', false, '1')]])
            ->push($direct)->push($metadata)->push(['fieldMask' => $mask,
                'results' => [$row($sub, true, '0'), $row($selected->accountId, false, '1')]])
            ->push($direct)->push($metadata)->push($this->googleReaderMetadata())->push(['fieldMask' => 'campaign.id', 'results' => []])]);
        $this->configureGoogleHttp($http);
        $base = '/workspaces/'.$this->workspace.'/providers/google';
        $url = $this->postJson($base.'/connect', ['source_id' => $installation->sourceId, 'expected_version' => 0])->assertOk()->json('authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $callback = $this->getJson('/providers/google/callback?'.http_build_query(['state' => $query['state'], 'code' => 'synthetic-code']))->assertOk();
        $directPage = $this->getJson($base.'/discovery/'.$callback->json('discovery_handle'))->assertOk();
        $rootHandle = $directPage->json('accounts.0.hierarchy_handle');
        $this->assertIsString($rootHandle);
        $this->getJson($base.'/discovery/'.$rootHandle)->assertForbidden();
        $this->getJson('/workspaces/'.Str::uuid().'/providers/google/hierarchy/'.$rootHandle)->assertForbidden();
        config()->set('services.google_ads.client_secret', 'synthetic-rotated-secret');
        $this->getJson($base.'/hierarchy/'.$rootHandle)->assertForbidden();
        $http->assertSentCount(2);
        config()->set('services.google_ads.client_secret', 'synthetic-client-secret');
        $sourcesBefore = $this->owner()->table('source_registry')->count();
        $page = $this->getJson($base.'/hierarchy/'.$rootHandle)->assertOk()
            ->assertJsonPath('accounts.0.manager', true)->assertJsonPath('accounts.0.selection_handle', null)
            ->assertJsonPath('coverage', 'PARTIAL');
        $this->assertCount(1, $page->json('accounts'));
        $this->assertStringNotContainsString('synthetic-native-cursor', $page->getContent());
        $this->getJson($base.'/hierarchy/'.$page->json('continuation'))->assertOk()->assertJsonPath('coverage', 'COMPLETE');
        $nested = $this->getJson($base.'/hierarchy/'.$page->json('accounts.0.hierarchy_handle'))->assertOk()
            ->assertJsonPath('accounts.0.account_id', $selected->accountId)->assertJsonPath('accounts.0.manager', false)
            ->assertJsonPath('accounts.0.hierarchy_handle', null);
        $this->assertSame($sourcesBefore, $this->owner()->table('source_registry')->count());
        $handle = $nested->json('accounts.0.selection_handle');
        $this->postJson($base.'/select', ['selection' => $handle, 'login_customer_id' => '9999999999'])->assertUnprocessable();
        $first = $this->postJson($base.'/select', ['selection' => $handle])->assertOk()->assertJsonPath('receipt.version', 2);
        $this->assertSame($first->json('receipt'), $this->postJson($base.'/select', ['selection' => $handle])->assertOk()->json('receipt'));
        $cipher = $this->owner()->table('provider_access')->where('source_id', $selected->sourceId)->value('encrypted_credentials');
        $stored = json_decode(Crypt::decryptString($cipher), true)['credentials'];
        $this->assertSame($root, $stored['login_customer_id']);
        $this->assertSame('JOD', $stored['account_currency']);
        $this->assertSame('Asia/Amman', $stored['account_timezone']);
        $http->assertSent(fn ($r) => str_contains($r->url(), '/customers/'.$sub.'/googleAds:search') && $r->hasHeader('login-customer-id', $root));
        $http->assertSent(fn ($r) => ($r->data()['pageToken'] ?? null) === 'synthetic-native-cursor' && $r->hasHeader('login-customer-id', $root));
        $http->assertSentCount(15);
        foreach ([$page, $nested, $first] as $response) {
            foreach (['synthetic-new-access', 'synthetic-new-refresh', 'synthetic-client-secret'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
        }
    }

    public function test_google_manager_http_flow_rejects_nonmanager_without_creating_sources(): void
    {
        $installation = $this->googleInstallationScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push($this->googleConnectionToken())
            ->push(['resourceNames' => ['customers/1234567890']])->push(['resourceNames' => ['customers/1234567890']])
            ->push($this->googleReaderMetadata())]);
        $this->configureGoogleHttp($http);
        $base = '/workspaces/'.$this->workspace.'/providers/google';
        $url = $this->postJson($base.'/connect', ['source_id' => $installation->sourceId, 'expected_version' => 0])->assertOk()->json('authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $callback = $this->getJson('/providers/google/callback?'.http_build_query(['state' => $query['state'], 'code' => 'synthetic-code']))->assertOk();
        $page = $this->getJson($base.'/discovery/'.$callback->json('discovery_handle'))->assertOk();
        $before = $this->owner()->table('source_registry')->count();
        $this->getJson($base.'/hierarchy/'.$page->json('accounts.0.hierarchy_handle'))->assertStatus(502)
            ->assertJsonPath('reason', 'google_manager_context_unverified');
        $this->assertSame($before, $this->owner()->table('source_registry')->count());
        $this->assertSame(0, $this->owner()->table('provider_access')->where('provider', 'google')->count());
        $http->assertSentCount(4);
    }

    public function test_google_local_disconnect_preserves_other_sources_and_replays_without_configuration(): void
    {
        $scope = $this->googleReaderScope();
        $other = $this->scopes['meta'];
        $this->store($other, (string) Str::uuid());
        config()->set('services.google_ads', []);
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        $this->app->instance(Factory::class, $http);
        $base = '/workspaces/'.$this->workspace.'/providers/google';
        $page = $this->getJson($base)->assertOk()->assertJsonPath('availability', 'CONFIGURATION_REQUIRED')
            ->json('connections');
        $this->assertSame('CONNECTED', collect($page)->firstWhere('source_id', $scope->sourceId)['access']['connection']);
        $command = ['command_id' => (string) Str::uuid(), 'expected_version' => 1];
        $this->postJson($base.'/'.$other->sourceId.'/disconnect', $command)->assertNotFound();
        $this->postJson($base.'/'.$scope->sourceId.'/disconnect', $command + ['remote_revoke' => true])->assertUnprocessable();
        $first = $this->postJson($base.'/'.$scope->sourceId.'/disconnect', $command)->assertOk()
            ->assertJsonPath('receipt.version', 2)->assertJsonPath('access.connection', 'LOCALLY_DISCONNECTED')
            ->assertJsonPath('access.effective.state', 'REVOKED')->assertJsonPath('remote_authorization_revoked', false);
        $this->assertSame($first->json('receipt'), $this->postJson($base.'/'.$scope->sourceId.'/disconnect', $command)->assertOk()->json('receipt'));
        $this->assertNull($this->owner()->table('provider_access')->where('source_id', $scope->sourceId)->value('encrypted_credentials'));
        $this->assertNotNull($this->owner()->table('provider_access')->where('source_id', $other->sourceId)->value('encrypted_credentials'));
        $this->assertSame(1, $this->owner()->table('provider_access_events')->where('effect', 'revoke')->count());
        $connections = $this->getJson($base)->assertOk()->json('connections');
        $this->assertSame('LOCALLY_DISCONNECTED', collect($connections)->firstWhere('source_id', $scope->sourceId)['access']['connection']);
        foreach ([$page, $first] as $response) {
            $body = is_array($response) ? json_encode($response) : $response->getContent();
            $this->assertStringNotContainsString('synthetic-google-token', $body);
            $this->assertStringNotContainsString('synthetic-secret-token', $body);
        }
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->credentials($r, $scope)), '42501');
        $http->assertNothingSent();
    }

    public function test_google_status_surfaces_native_denial_blocks_repeated_reads_and_resets_on_reconnect(): void
    {
        $scope = $this->googleReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'synthetic-private-denial']], 403)]);
        $this->assertSame('FAILED', $this->googleRead($scope, $http)['coverage']);
        $snapshot = fn () => $this->withinWorkspace(fn ($r) => (new GoogleAccessStatus)->snapshot($r, $scope));
        $status = $snapshot();
        $this->assertSame('VERIFICATION_NEEDED', $status['connection']);
        $this->assertSame('FAILED', $status['axes']['provider_permission']);
        $this->assertStringNotContainsString('synthetic-private-denial', json_encode($status));
        try {
            $this->googleRead($scope, $http);
            $this->fail('Known current denial must stop another report before HTTP.');
        } catch (AuthorizationException) {
            $http->assertSentCount(1);
        }
        $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->store($r, $scope, (string) Str::uuid(), 1,
            new ProviderCredentials(['access_token' => 'synthetic-reverified-token']), new DateTimeImmutable('+10 minutes'), 'CONNECTED', $this->evidence(), true));
        $this->assertSame('CONNECTED', $snapshot()['connection']);
        $this->owner()->table('provider_access')->where('source_id', $scope->sourceId)->update(['credential_expires_at' => now()->subMinute()]);
        $this->assertSame('TOKEN_REFRESH_REQUIRED', $snapshot()['connection']);
        $this->assertSame('STALE', $snapshot()['axes']['credential']);
    }

    public function test_google_renewal_uses_expired_custody_reverifies_account_and_replays_without_http(): void
    {
        $scope = $this->googleReaderScope();
        $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->store($r, $scope, (string) Str::uuid(), 1,
            new ProviderCredentials(['access_token' => 'synthetic-expired-access', 'refresh_token' => 'synthetic-old-refresh',
                'oauth_client_id' => 'synthetic.apps.googleusercontent.com', 'granted_scope' => GoogleOAuthClient::SCOPE,
                'account_id' => $scope->accountId, 'account_currency' => 'JOD', 'account_timezone' => 'Asia/Amman',
                'expires_at' => (string) (time() - 60)]), new DateTimeImmutable('-1 minute'), 'CONNECTED', $this->evidence(), true));
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->credentials($r, $scope)), '42501');
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push($this->googleConnectionToken())->push($this->googleReaderMetadata())
            ->push(['fieldMask' => 'campaign.id', 'results' => []])]);
        $this->configureGoogleHttp($http);
        $base = '/workspaces/'.$this->workspace.'/providers/google/'.$scope->sourceId;
        $command = ['command_id' => (string) Str::uuid(), 'expected_version' => 2];
        $this->postJson($base.'/renew', $command + ['refresh_token' => 'injected'])->assertUnprocessable();
        $first = $this->postJson($base.'/renew', $command)->assertOk()->assertJsonPath('receipt.version', 3)
            ->assertJsonPath('access.connection', 'CONNECTED')->assertJsonPath('access.axes.privacy_purpose', 'UNKNOWN');
        $this->assertSame($first->json('receipt'), $this->postJson($base.'/renew', $command)->assertOk()->json('receipt'));
        $http->assertSentCount(3);
        $http->assertSent(fn ($r) => $r->url() === 'https://oauth2.googleapis.com/token' && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'synthetic-old-refresh');
        $cipher = $this->owner()->table('provider_access')->where('source_id', $scope->sourceId)->value('encrypted_credentials');
        $stored = json_decode(Crypt::decryptString($cipher), true)['credentials'];
        $this->assertSame('synthetic-new-refresh', $stored['refresh_token']);
        $this->assertSame('JOD', $stored['account_currency']);
        $this->assertStringNotContainsString('synthetic-new-refresh', $first->getContent());
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->credentials($r, $scope)), '42501');
        $this->postJson($base.'/disconnect', ['command_id' => (string) Str::uuid(), 'expected_version' => 3])->assertOk();
        $this->postJson($base.'/renew', ['command_id' => (string) Str::uuid(), 'expected_version' => 4])->assertForbidden();
        $http->assertSentCount(3);
    }

    public function test_google_native_queue_encrypts_context_and_replay_never_fetches_twice(): void
    {
        $scope = $this->googleReaderScope();
        $http = $this->googleReaderTransport();
        $this->configureGoogleHttp($http);
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.connection', 'pgsql');
        $job = $this->withinWorkspace(function ($r) use ($scope) {
            $job = GoogleReadJob::capture($r, $scope,
                new ReportWindow('2026-09-01', '2026-09-01', 'INCLUSIVE_DATES', 'TOTAL', 'Asia/Amman', 'synthetic:native-timezone'),
                'google-native-queue', (string) Str::uuid(), 0, 1);
            Bus::dispatch($job);

            return $job;
        });
        $payload = $this->owner()->table('jobs')->value('payload');
        $this->assertStringNotContainsString('developer_token', $payload);
        $this->assertStringNotContainsString('synthetic-google-token', $payload);
        $this->assertStringNotContainsString($this->app['session']->driver()->getId(), $payload);
        $idleBefore = $this->owner()->table('security_sessions')->where('user_id', $this->actor->id)->value('idle_expires_at');
        Queue::connection('database')->pop('bb2.provider.google')->fire();
        $http->assertSentCount(2);
        Bus::dispatch($job);
        Queue::connection('database')->pop('bb2.provider.google')->fire();
        $http->assertSentCount(2);
        $this->assertSame(1, $this->owner()->table('google_read_attempts')->count());
        $this->assertSame(1, $this->owner()->table('provider_observation_runs')->count());
        $this->assertSame($idleBefore, $this->owner()->table('security_sessions')->where('user_id', $this->actor->id)->value('idle_expires_at'));
        $this->denied(fn () => $this->withinWorkspace(fn () => DB::table('google_read_attempts')->get()), '42501');
    }

    public function test_tiktok_one_button_flow_selects_native_account_and_disconnects_only_local_custody(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()
            ->push(['code' => 0, 'data' => ['access_token' => 'synthetic-tiktok-token']])
            ->push(['code' => 0, 'data' => ['list' => [['advertiser_id' => '1234567890123456789', 'advertiser_name' => 'Synthetic TikTok']]]])
            ->push(['code' => 0, 'data' => ['list' => [['advertiser_id' => '1234567890123456789', 'currency' => 'JOD', 'timezone' => 'Asia/Amman']]]])
            ->push(['code' => 0, 'data' => ['list' => []]])]);
        $this->configureTikTokHttp($http);
        $base = '/workspaces/'.$this->workspace.'/providers/tiktok';
        $before = $this->owner()->table('source_registry')->count();
        $index = $this->getJson($base)->assertOk()->assertJsonPath('availability', 'CONNECT_AVAILABLE');
        $this->assertSame($before, $this->owner()->table('source_registry')->count());
        $url = $this->postJson($base.'/connect', ['intent' => $index->json('connect_intent')])->assertOk()->json('authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('5555555555555555555', $query['app_id']);
        $callback = $this->getJson('/providers/tiktok/callback?'.http_build_query(['state' => $query['state'], 'auth_code' => 'synthetic-private-code']))->assertOk();
        $this->getJson('/providers/tiktok/callback?'.http_build_query(['state' => $query['state'], 'auth_code' => 'replayed-code']))->assertForbidden();
        $page = $this->getJson($base.'/discovery/'.$callback->json('discovery_handle'))->assertOk();
        $handle = $page->json('accounts.0.selection_handle');
        $this->postJson($base.'/select', ['selection' => $handle, 'access_token' => 'injected'])->assertUnprocessable();
        $selected = $this->postJson($base.'/select', ['selection' => $handle])->assertOk()->assertJsonPath('receipt.version', 1);
        $this->assertSame($selected->json('receipt'), $this->postJson($base.'/select', ['selection' => $handle])->assertOk()->json('receipt'));
        $http->assertSentCount(4);
        $current = $this->getJson($base)->assertOk()->assertJsonPath('connections.0.access.connection', 'CONNECTED')
            ->assertJsonPath('connections.0.access.axes.privacy_purpose', 'UNKNOWN')->assertJsonPath('connections.0.access.effective.state', 'UNKNOWN');
        foreach ([$index, $callback, $page, $selected, $current] as $response) {
            foreach (['synthetic-tiktok-token', 'synthetic-tiktok-secret', 'synthetic-private-code'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
        }
        config()->set('services.tiktok_ads', []);
        $command = ['command_id' => (string) Str::uuid(), 'expected_version' => 1];
        $first = $this->postJson($base.'/'.$selected->json('source_id').'/disconnect', $command)->assertOk()
            ->assertJsonPath('access.connection', 'LOCALLY_DISCONNECTED')->assertJsonPath('remote_authorization_revoked', false);
        $this->assertSame($first->json('receipt'), $this->postJson($base.'/'.$selected->json('source_id').'/disconnect', $command)->assertOk()->json('receipt'));
        $this->assertNull($this->owner()->table('provider_access')->where('source_id', $selected->json('source_id'))->value('encrypted_credentials'));
        $http->assertSentCount(4);
    }

    public function test_tiktok_callback_binding_rejects_changed_configuration_and_other_workspace_before_http(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        $this->configureTikTokHttp($http);
        $base = '/workspaces/'.$this->workspace.'/providers/tiktok';
        $intent = $this->getJson($base)->assertOk()->json('connect_intent');
        $this->postJson('/workspaces/'.Str::uuid().'/providers/tiktok/connect', ['intent' => $intent])->assertForbidden();
        $url = $this->postJson($base.'/connect', ['intent' => $intent])->assertOk()->json('authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        config()->set('services.tiktok_ads.app_secret', 'synthetic-rotated-secret');
        $this->getJson('/providers/tiktok/callback?'.http_build_query(['state' => $query['state'], 'auth_code' => 'synthetic-code']))->assertForbidden();
        $http->assertNothingSent();
    }

    public function test_tiktok_native_queue_replays_once_and_retains_exact_native_money(): void
    {
        $scope = $this->tiktokReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['code' => 0, 'data' => ['list' => [
            ['dimensions' => ['campaign_id' => '456'], 'metrics' => ['spend' => '9007199254740993.001', 'impressions' => '10', 'clicks' => '0']],
        ], 'page_info' => ['page' => 1, 'total_page' => 1]]])]);
        $this->configureTikTokHttp($http);
        config()->set('queue.default', 'database');
        $job = $this->withinWorkspace(function ($r) use ($scope) {
            $job = TikTokReadJob::capture($r, $scope,
                new ReportWindow('2026-09-01', '2026-09-01', 'INCLUSIVE_DATES', 'TOTAL', 'Asia/Amman', 'synthetic:native'),
                'tiktok-native-queue', (string) Str::uuid(), 0, 1);
            Bus::dispatch($job);

            return $job;
        });
        $payload = $this->owner()->table('jobs')->value('payload');
        $this->assertStringNotContainsString('synthetic-tiktok-token', $payload);
        $this->assertStringNotContainsString('synthetic-tiktok-secret', $payload);
        Queue::connection('database')->pop('bb2.provider.tiktok')->fire();
        Bus::dispatch($job);
        Queue::connection('database')->pop('bb2.provider.tiktok')->fire();
        $http->assertSentCount(1);
        $this->assertSame(1, $this->owner()->table('tiktok_read_attempts')->count());
        $history = $this->withinWorkspace(fn ($r) => app(ObservationJournal::class)->history($r, $scope, 'tiktok-native-queue'));
        $this->assertSame('9007199254740993.001', $history[0]['observation']['spend']['native_value']);
        $this->assertSame('JOD', $history[0]['observation']['spend']['currency']);
        $this->assertNull($history[0]['observation']['spend']['major_units']);
        $this->denied(fn () => $this->withinWorkspace(fn () => DB::table('tiktok_read_attempts')->get()), '42501');
    }

    public function test_google_native_retry_hint_blocks_other_streams_until_native_delay_expires(): void
    {
        $scope = $this->googleReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['error' => ['status' => 'RESOURCE_EXHAUSTED']], 429, ['Retry-After' => '40'])]);
        $this->assertSame('FAILED', $this->googleRead($scope, $http)['coverage']);
        $reader = new GoogleObservationReader($http, app(ObservationJournal::class), app(ProviderAccessStore::class), app(ProviderRequestGuard::class));
        $attempt = $this->withinWorkspace(fn ($r) => $reader->attempt($r, $scope,
            new ReportWindow('2026-09-01', '2026-09-02', 'INCLUSIVE_DATES', 'TOTAL', 'Asia/Amman', 'synthetic:native'),
            new ProviderReadBudget($scope, 'google-another-stream', 4, 0, 4, 10, new DateTimeImmutable('+30 seconds'), 1, 5),
            'v25', '', 'google-another-stream', (string) Str::uuid(), 0, 1));
        $this->assertSame('google_native_cooldown', $attempt->failure);
        $this->assertGreaterThan(0, $attempt->retryAfterSeconds);
        $this->assertNull($this->owner()->table('provider_observation_streams')->where('stream', 'google-another-stream')->value('progress_generation'));
        $http->assertSentCount(1);
    }

    public function test_google_report_http_command_queues_only_authorized_bound_window_and_replays_without_native_read(): void
    {
        $scope = $this->googleReaderScope();
        $http = $this->googleReaderTransport();
        $this->configureGoogleHttp($http);
        $base = '/workspaces/'.$this->workspace.'/providers/google/'.$scope->sourceId.'/report';
        $period = ['start_date' => '2026-09-01', 'end_date' => '2026-09-02'];
        $snapshot = $this->getJson($base.'?'.http_build_query($period))->assertOk()->assertJsonPath('generation', 0)->assertJsonPath('timezone', 'Asia/Amman');
        $command = [...$period, 'command_id' => (string) Str::uuid(), 'expected_version' => 1, 'expected_generation' => 0,
            'deadline' => $snapshot->json('deadline')];
        $this->postJson($base, [...$command, 'access_token' => 'injected'])->assertUnprocessable();
        $this->postJson($base, [...$command, 'deadline' => now()->addMinutes(10)->format(DATE_ATOM)])->assertUnprocessable();
        $this->postJson($base, [...$command, 'expected_version' => 2])->assertForbidden();
        $this->postJson($base, $command)->assertAccepted()->assertJsonPath('queued', true);
        $http->assertNothingSent();
        $payload = $this->owner()->table('jobs')->where('queue', 'bb2.provider.google')->value('payload');
        $this->assertStringNotContainsString('synthetic-private-access', $payload);
        Queue::connection('database')->pop('bb2.provider.google')->fire();
        $this->getJson($base.'?'.http_build_query($period))->assertOk()->assertJsonPath('generation', 1)->assertJsonPath('last_run.coverage', 'COMPLETE');
        $this->travel(3)->seconds();
        $this->postJson($base, $command)->assertAccepted();
        Queue::connection('database')->pop('bb2.provider.google')->fire();
        $http->assertSentCount(2);
        $this->assertSame(1, $this->owner()->table('google_read_attempts')->count());
    }

    public function test_google_campaign_download_is_a_complete_immutable_snapshot_under_current_source_read(): void
    {
        $scope = $this->googleReaderScope();
        $http = $this->googleReaderTransport();
        $this->configureGoogleHttp($http);
        $base = '/workspaces/'.$this->workspace.'/providers/google/'.$scope->sourceId.'/report';
        $period = ['start_date' => '2026-09-01', 'end_date' => '2026-09-02'];
        $status = $this->getJson($base.'?'.http_build_query($period))->assertOk();
        $this->assertNull($status->json('download'));
        $this->postJson($base, [...$period, 'command_id' => (string) Str::uuid(), 'expected_version' => 1,
            'expected_generation' => 0, 'deadline' => $status->json('deadline')])->assertAccepted();
        Queue::connection('database')->pop('bb2.provider.google')->fire();
        $url = $this->getJson($base.'?'.http_build_query($period))->assertOk()->json('download.url');
        $this->assertIsString($url);
        $download = $this->get($url)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));
        $csv = $download->getContent();
        $this->assertStringContainsString('9007199254740993', $csv);
        $this->assertStringContainsString('metrics.conversions_value', $csv);
        $this->assertStringNotContainsString('synthetic-google-token', $csv);
        $selectedUrl = $url.'&'.http_build_query(['metrics' => ['conversions_value', 'clicks']]);
        $selectedCsv = $this->get($selectedUrl)->assertOk()->getContent();
        $this->assertStringContainsString('metrics.clicks', $selectedCsv);
        $this->assertStringContainsString('metrics.conversions_value', $selectedCsv);
        $this->assertStringContainsString('metrics.impressions', $selectedCsv);
        $requiredOnlyCsv = $this->get($url.'&'.http_build_query(['metrics' => ['clicks']]))->assertOk()->getContent();
        $this->assertStringContainsString('metrics.all_conversions', $requiredOnlyCsv);
        $this->assertStringNotContainsString('metrics.conversions_value', $requiredOnlyCsv);
        $this->assertStringContainsString('metrics.cost_micros', $selectedCsv);
        $this->assertStringContainsString('documentation_basis', $selectedCsv);
        $this->assertSame($csv, $this->get($url)->assertOk()->getContent());
        foreach ([['clicks', 'clicks'], ['ctr'], ['clicks' => 'impressions'], ['clicks', ['impressions']]] as $invalidMetrics) {
            $this->getJson($url.'&'.http_build_query(['metrics' => $invalidMetrics]))->assertUnprocessable();
        }
        $this->getJson($url.'&metrics=clicks')->assertUnprocessable();
        // Download is historical source.read, independent of current live credentials/settings.
        $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->revoke($r, $scope, (string) Str::uuid(), 1));
        config()->set('services.google_ads.developer_token', null);
        $this->assertSame($csv, $this->get($url)->assertOk()->getContent());
        $this->assertSame($selectedCsv, $this->get($selectedUrl)->assertOk()->getContent());
        $this->getJson($url.'&access_token=injected')->assertUnprocessable();
        $this->get(str_replace('/report/1/download', '/report/2/download', $url))->assertNotFound();
        $this->get(str_replace($this->workspace, (string) Str::uuid(), $url))->assertForbidden();
        $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)
            ->where('action', 'source.read')->update(['status' => 'revoked']);
        $this->get($url)->assertNotFound();
        $this->get($selectedUrl)->assertNotFound();
        $http->assertSentCount(2);
        $this->assertSame(1, $this->owner()->table('provider_observation_runs')->count());
    }

    public function test_google_report_http_command_denies_unknown_policy_and_other_workspace_before_queue_or_http(): void
    {
        $scope = $this->googleReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        $this->configureGoogleHttp($http);
        $command = ['start_date' => '2026-09-01', 'end_date' => '2026-09-02', 'command_id' => (string) Str::uuid(), 'expected_version' => 1, 'expected_generation' => 0,
            'deadline' => now()->addMinutes(5)->format(DATE_ATOM)];
        $this->postJson('/workspaces/'.Str::uuid().'/providers/google/'.$scope->sourceId.'/report', $command)->assertForbidden();
        $proof = $this->evidence();
        $proof['privacy_purpose']['state'] = 'UNKNOWN';
        $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->store($r, $scope, (string) Str::uuid(), 1,
            new ProviderCredentials(['access_token' => 'synthetic-private-access', 'account_id' => $scope->accountId,
                'granted_scope' => GoogleOAuthClient::SCOPE, 'expires_at' => (string) (time() + 600), 'account_currency' => 'JOD', 'account_timezone' => 'Asia/Amman']),
            new DateTimeImmutable('+10 minutes'), 'SYNTHETIC_AUTHORIZED', $proof, true));
        $command['expected_version'] = 2;
        $this->postJson('/workspaces/'.$this->workspace.'/providers/google/'.$scope->sourceId.'/report', $command)->assertForbidden();
        $this->assertSame(0, $this->owner()->table('jobs')->where('queue', 'bb2.provider.google')->count());
        $http->assertNothingSent();
    }

    public function test_tiktok_report_http_command_has_its_own_queue_and_delayed_replay_sends_no_extra_http(): void
    {
        $scope = $this->tiktokReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['code' => 0, 'data' => ['list' => [], 'page_info' => ['page' => 1, 'total_page' => 0]]])]);
        $this->configureTikTokHttp($http);
        $base = '/workspaces/'.$this->workspace.'/providers/tiktok/'.$scope->sourceId.'/report';
        $period = ['start_date' => '2026-09-01', 'end_date' => '2026-09-02'];
        $snapshot = $this->getJson($base.'?'.http_build_query($period))->assertOk()->assertJsonPath('generation', 0)->assertJsonPath('timezone', 'Asia/Amman');
        $command = [...$period, 'command_id' => (string) Str::uuid(), 'expected_version' => 1, 'expected_generation' => 0, 'deadline' => $snapshot->json('deadline')];
        $this->postJson($base, [...$command, 'purpose' => 'injected'])->assertUnprocessable();
        $this->postJson($base, $command)->assertAccepted()->assertJsonPath('queued', true);
        $http->assertNothingSent();
        Queue::connection('database')->pop('bb2.provider.tiktok')->fire();
        $this->travel(3)->seconds();
        $this->postJson($base, $command)->assertAccepted();
        Queue::connection('database')->pop('bb2.provider.tiktok')->fire();
        $this->getJson($base.'?'.http_build_query($period))->assertOk()->assertJsonPath('generation', 1)->assertJsonPath('last_run.coverage', 'COMPLETE');
        $http->assertSentCount(1);
        $this->assertSame(1, $this->owner()->table('tiktok_read_attempts')->count());
        $this->assertSame(0, $this->owner()->table('google_read_attempts')->count());
    }

    private function tiktokReaderScope(): ProviderScope
    {
        $scope = new ProviderScope($this->workspaceId, (string) Str::uuid7(), Provider::TikTok, '1234567890123456789', 'report.read', 'advertising_analysis');
        foreach (GrantAuthority::SOURCE_ACTIONS as $action) {
            $this->grant($scope, $action);
        }
        $this->postJson('/workspaces/'.$this->workspace.'/sources', ['source_id' => $scope->sourceId, 'command_id' => (string) Str::uuid(),
            'effect' => 'register', 'family' => 'tiktok', 'namespace' => 'ad_account', 'external_id' => $scope->accountId, 'label' => 'Synthetic TikTok account',
            'resource' => $scope->accountId, 'operation' => $scope->operation, 'purpose' => $scope->purpose, 'expected_version' => 0, 'reason' => 'synthetic_registration'])->assertOk();
        $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->store($r, $scope, (string) Str::uuid(), 0,
            new ProviderCredentials(['access_token' => 'synthetic-tiktok-token', 'app_id' => '5555555555555555555', 'token_kind' => 'ADVERTISER',
                'account_id' => $scope->accountId, 'account_currency' => 'JOD', 'account_timezone' => 'Asia/Amman']), null, 'SYNTHETIC_AUTHORIZED', $this->evidence()));

        return $scope;
    }

    private function configureTikTokHttp(Factory $http): void
    {
        config()->set('app.url', 'https://builderad.example');
        config()->set('services.tiktok_ads', ['version' => 'v1.3', 'app_id' => '5555555555555555555', 'app_secret' => 'synthetic-tiktok-secret',
            'authorization_url' => 'https://business-api.tiktok.com/portal/auth', 'redirect_uri' => 'https://builderad.example/providers/tiktok/callback',
            'privacy_url' => 'https://builderad.example/privacy', 'notice_revision' => 'synthetic-notice-v1']);
        $this->app->instance(Factory::class, $http);
    }

    public function test_snapchat_coordinator_encrypts_pending_and_custody_and_replays_selection_without_native_http(): void
    {
        [$installation, $selected] = $this->snapchatScopes();
        $http = $this->snapchatTransport();
        $flow = $this->snapchatCoordinator($http);
        $url = $this->withinWorkspace(fn ($r) => $flow->authorizationUrl($r, $installation));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $handle = $this->withinWorkspace(fn ($r) => $flow->callback($r, $installation, 0, $query['state'], 'synthetic-snap-code'));
        $cipher = Cache::get('bb2.snapchat.pending.'.hash('sha256', $handle));
        $this->assertIsString($cipher);
        $this->assertStringNotContainsString('synthetic-snap-access', $cipher);
        $discovered = $this->withinWorkspace(fn ($r) => $flow->discover($r, $installation, 0, $handle));
        $this->assertSame($selected->accountId, $discovered->accounts[0]['id']);
        $this->assertSame('COMPLETE', $discovered->coverage);
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
        $command = (string) Str::uuid();
        $receipt = $this->withinWorkspace(fn ($r) => $flow->select($r, $installation, 0, $handle, $selected, $command, 0));
        $this->assertSame(['version' => 1, 'local_state' => 'CREDENTIAL_STORED'], $receipt);
        $this->assertNull(Cache::get('bb2.snapchat.pending.'.hash('sha256', $handle)));
        $this->assertSame($receipt, $this->withinWorkspace(fn ($r) => $flow->select($r, $installation, 0, $handle, $selected, $command, 0)));
        $http->assertSentCount(4);
        $stored = $this->owner()->table('provider_access')->where('source_id', $selected->sourceId)->first();
        $this->assertStringNotContainsString('synthetic-snap-access', $stored->encrypted_credentials);
        $envelope = json_decode(Crypt::decryptString($stored->encrypted_credentials), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($selected->jsonSerialize(), $envelope['scope']);
        $this->assertSame('synthetic-snap-refresh', $envelope['credentials']['refresh_token']);
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->credentials($r, $selected)), '42501');
        $this->assertSame(0, $this->owner()->table('provider_observation_runs')->count());
    }

    public function test_snapchat_callback_failure_consumes_state_even_when_database_transaction_rolls_back(): void
    {
        [$installation] = $this->snapchatScopes();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['error' => 'invalid_grant', 'error_description' => 'synthetic-secret'], 400)]);
        $flow = $this->snapchatCoordinator($http);
        $url = $this->withinWorkspace(fn ($r) => $flow->authorizationUrl($r, $installation));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        try {
            $this->withinWorkspace(fn ($r) => $flow->callback($r, $installation, 0, $query['state'], 'synthetic-code'));
            $this->fail('A denied Snapchat exchange cannot stage credentials.');
        } catch (SnapchatRequestFailed $error) {
            $this->assertSame('snapchat_reconnect_required', $error->reason);
        }
        $this->assertNull(Cache::get('bb2.snapchat.oauth.'.hash('sha256', $query['state'])));
        try {
            $this->withinWorkspace(fn ($r) => $flow->callback($r, $installation, 0, $query['state'], 'replayed-code'));
            $this->fail('Failed callback state must not replay.');
        } catch (AuthorizationException) {
            $http->assertSentCount(1);
        }
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
    }

    public function test_snapchat_configuration_rotation_and_revoked_connect_authority_prevent_exchange(): void
    {
        [$installation] = $this->snapchatScopes();
        $http = new Factory;
        $http->preventStrayRequests();
        $flow = $this->snapchatCoordinator($http);
        $url = $this->withinWorkspace(fn ($r) => $flow->authorizationUrl($r, $installation));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $rotated = $this->snapchatCoordinator($http, 'synthetic-rotated-secret');
        $this->assertNotSame($flow->configurationBinding(), $rotated->configurationBinding());
        try {
            $this->withinWorkspace(fn ($r) => $rotated->callback($r, $installation, 0, $query['state'], 'synthetic-code'));
            $this->fail('Changed configuration must invalidate state.');
        } catch (AuthorizationException) {
            $http->assertNothingSent();
        }
        $this->owner()->table('security_action_grants')->where('source_target_id', $installation->sourceId)->where('action', 'provider.connect')
            ->update(['expires_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => $flow->callback($r, $installation, 0, $query['state'], 'synthetic-code')), '42501');
        $http->assertNothingSent();
        $this->assertIsString(Cache::get('bb2.snapchat.oauth.'.hash('sha256', $query['state'])));
    }

    public function test_snapchat_pending_publication_and_selection_discard_follow_database_commit(): void
    {
        [$installation, $selected] = $this->snapchatScopes();
        $http = $this->snapchatTransport();
        $flow = $this->snapchatCoordinator($http);
        $url = $this->withinWorkspace(fn ($r) => $flow->authorizationUrl($r, $installation));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $handle = $this->withinWorkspace(fn ($r) => $flow->callback($r, $installation, 0, $query['state'], 'synthetic-code'));
        $key = 'bb2.snapchat.pending.'.hash('sha256', $handle);
        $before = Cache::get($key);
        try {
            $this->withinWorkspace(function ($r) use ($flow, $installation, $selected, $handle): void {
                $flow->discover($r, $installation, 0, $handle);
                $flow->select($r, $installation, 0, $handle, $selected, (string) Str::uuid(), 0);
                throw new \RuntimeException('Synthetic Snapchat selection rollback.');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic Snapchat selection rollback.', $error->getMessage());
        }
        $this->assertSame($before, Cache::get($key));
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
        $this->assertSame(0, $this->owner()->table('provider_access_events')->count());
        $http->assertSentCount(4);
    }

    public function test_snapchat_http_flow_creates_sources_only_on_explicit_actions_and_keeps_secrets_server_side(): void
    {
        $http = $this->snapchatTransport();
        $this->configureSnapchatHttp($http);
        $base = '/workspaces/'.$this->workspace.'/providers/snapchat';
        $before = $this->owner()->table('source_registry')->count();
        $index = $this->getJson($base)->assertOk()->assertJsonPath('availability', 'CONNECT_AVAILABLE');
        $this->assertSame($before, $this->owner()->table('source_registry')->count());
        $url = $this->postJson($base.'/connect', ['intent' => $index->json('connect_intent')])->assertOk()->json('authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('snapchat-marketing-api', $query['scope']);
        $this->assertSame('synthetic-snap-client', $query['client_id']);
        $callback = $this->getJson('/providers/snapchat/callback?'.http_build_query(['state' => $query['state'], 'code' => 'synthetic-private-code']))
            ->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->getJson('/providers/snapchat/callback?'.http_build_query(['state' => $query['state'], 'code' => 'replayed-code']))->assertForbidden();
        $page = $this->getJson($base.'/discovery/'.$callback->json('discovery_handle'))->assertOk();
        $this->assertSame($before + 1, $this->owner()->table('source_registry')->count());
        $choice = $page->json('accounts.0.selection_handle');
        $this->postJson($base.'/select', ['selection' => $choice, 'access_token' => 'injected'])->assertUnprocessable();
        $selected = $this->postJson($base.'/select', ['selection' => $choice])->assertOk()->assertJsonPath('receipt.version', 1)
            ->assertJsonPath('access.connection', 'CONNECTED')->assertJsonPath('access.axes.privacy_purpose', 'UNKNOWN');
        $this->assertSame($before + 2, $this->owner()->table('source_registry')->count());
        $this->assertSame($selected->json('receipt'), $this->postJson($base.'/select', ['selection' => $choice])->assertOk()->json('receipt'));
        foreach ([$index, $callback, $page, $selected] as $response) {
            foreach (['synthetic-snap-access', 'synthetic-snap-refresh', 'synthetic-snap-secret', 'synthetic-private-code'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
        }
        config()->set('services.snapchat_ads', []);
        $disconnect = $this->postJson($base.'/'.$selected->json('source_id').'/disconnect',
            ['command_id' => (string) Str::uuid(), 'expected_version' => 1])->assertOk()
            ->assertJsonPath('access.connection', 'LOCALLY_DISCONNECTED')->assertJsonPath('remote_authorization_revoked', false);
        $this->assertNull($this->owner()->table('provider_access')->where('source_id', $selected->json('source_id'))->value('encrypted_credentials'));
        $http->assertSentCount(4);
    }

    public function test_snapchat_http_context_and_configuration_changes_fail_before_native_http(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $this->configureSnapchatHttp($http);
        $base = '/workspaces/'.$this->workspace.'/providers/snapchat';
        $intent = $this->getJson($base)->assertOk()->json('connect_intent');
        $this->postJson('/workspaces/'.Str::uuid().'/providers/snapchat/connect', ['intent' => $intent])->assertForbidden();
        $url = $this->postJson($base.'/connect', ['intent' => $intent])->assertOk()->json('authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        config()->set('services.snapchat_ads.client_secret', 'synthetic-rotated-secret');
        $this->getJson('/providers/snapchat/callback?'.http_build_query(['state' => $query['state'], 'code' => 'synthetic-code']))->assertForbidden();
        $http->assertNothingSent();
    }

    private function configureSnapchatHttp(Factory $http): void
    {
        config()->set('app.url', 'https://builderad.example');
        config()->set('services.snapchat_ads', ['client_id' => 'synthetic-snap-client', 'client_secret' => 'synthetic-snap-secret',
            'redirect_uri' => 'https://builderad.example/providers/snapchat/callback', 'privacy_url' => 'https://builderad.example/synthetic-privacy',
            'notice_revision' => 'synthetic:1']);
        $this->app->instance(Factory::class, $http);
    }

    public function test_snapchat_cancel_consumes_callback_state_without_exchange_and_scrubs_provider_input(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $this->configureSnapchatHttp($http);
        $base = '/workspaces/'.$this->workspace.'/providers/snapchat';
        $intent = $this->getJson($base)->assertOk()->json('connect_intent');
        $url = $this->postJson($base.'/connect', ['intent' => $intent])->assertOk()->json('authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $cancelled = $this->getJson('/providers/snapchat/callback?'.http_build_query(['state' => $query['state'],
            'error' => 'access_denied', 'error_description' => 'synthetic-private-error', 'code' => 'synthetic-private-code']))
            ->assertOk()->assertJsonPath('status', 'AUTHORIZATION_NOT_COMPLETED')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringNotContainsString('synthetic-private-error', $cancelled->getContent());
        $this->assertStringNotContainsString('synthetic-private-code', $cancelled->getContent());
        $this->assertNull(session('_old_input.code'));
        $this->getJson('/providers/snapchat/callback?'.http_build_query(['state' => $query['state'], 'code' => 'replayed-code']))->assertForbidden();
        $http->assertNothingSent();
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
    }

    public function test_snapchat_callback_rollback_does_not_publish_pending_but_keeps_state_consumed(): void
    {
        [$installation] = $this->snapchatScopes();
        $http = $this->snapchatTransport();
        $flow = $this->snapchatCoordinator($http);
        $url = $this->withinWorkspace(fn ($r) => $flow->authorizationUrl($r, $installation));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $pending = null;
        try {
            $this->withinWorkspace(function ($r) use ($flow, $installation, $query, &$pending): void {
                $pending = $flow->callback($r, $installation, 0, $query['state'], 'synthetic-code');
                throw new \RuntimeException('Synthetic Snapchat callback rollback.');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic Snapchat callback rollback.', $error->getMessage());
        }
        $this->assertNotNull($pending);
        $this->assertNull(Cache::get('bb2.snapchat.pending.'.hash('sha256', $pending)));
        $this->assertNull(Cache::get('bb2.snapchat.oauth.'.hash('sha256', $query['state'])));
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
        $http->assertSentCount(1);
    }

    private function snapchatScopes(): array
    {
        $scopes = [];
        foreach ([['synthetic-snap-client', 'developer_app', 'discovery.read'],
            ['8b8e40af-fc64-455d-925b-ca80f7af6914', 'ad_account', 'report.read']] as [$id, $namespace, $operation]) {
            $scope = new ProviderScope($this->workspaceId, (string) Str::uuid7(), Provider::Snapchat, $id, $operation, 'advertising_analysis');
            foreach (GrantAuthority::SOURCE_ACTIONS as $action) {
                $this->grant($scope, $action);
            }
            $this->postJson('/workspaces/'.$this->workspace.'/sources', ['source_id' => $scope->sourceId, 'command_id' => (string) Str::uuid(),
                'effect' => 'register', 'family' => 'snapchat', 'namespace' => $namespace, 'external_id' => $id,
                'label' => 'Synthetic Snapchat source', 'resource' => $id, 'operation' => $operation, 'purpose' => $scope->purpose,
                'expected_version' => 0, 'reason' => 'synthetic_registration'])->assertOk();
            $scopes[] = $scope;
        }

        return $scopes;
    }

    private function snapchatCoordinator(Factory $http, string $secret = 'synthetic-snap-secret'): SnapchatAuthorizationCoordinator
    {
        $guard = app(ProviderRequestGuard::class);
        $unbound = static fn () => throw new \LogicException('Unbound Snapchat configuration cannot make HTTP.');
        $adapter = new SnapchatAccessAdapter(
            new SnapchatOAuthClient($http, 'synthetic-snap-client', $secret,
                'https://builderad.example/providers/snapchat/callback', $unbound),
            new SnapchatClient($http, $unbound, 'provider.connect'));

        return new SnapchatAuthorizationCoordinator($http, $adapter,
            new SnapchatOAuthState(Cache::store(), $guard),
            new SnapchatPendingAccess(Cache::store(), $guard), $guard, app(ProviderAccessStore::class));
    }

    private function snapchatTransport(): Factory
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $account = ['id' => '8b8e40af-fc64-455d-925b-ca80f7af6914', 'name' => 'Synthetic Snapchat advertiser',
            'currency' => 'USD', 'timezone' => 'America/Los_Angeles', 'roles' => ['reports']];
        $http->fake(['*' => $http->sequence()
            ->push(['access_token' => 'synthetic-snap-access', 'refresh_token' => 'synthetic-snap-refresh', 'expires_in' => 300, 'token_type' => 'Bearer'])
            ->push(['request_status' => 'SUCCESS', 'organizations' => [['sub_request_status' => 'SUCCESS', 'organization' => ['ad_accounts' => [$account]]]]])
            ->push(['request_status' => 'SUCCESS', 'adaccounts' => [['sub_request_status' => 'success', 'adaccount' => $account]]])
            ->push(['request_status' => 'SUCCESS', 'total_stats' => [['sub_request_status' => 'SUCCESS', 'total_stat' => [
                'id' => $account['id'], 'type' => 'AD_ACCOUNT', 'granularity' => 'TOTAL', 'stats' => ['impressions' => 0],
            ]]]])]);

        return $http;
    }

    public function test_snapchat_report_http_queue_preserves_exact_money_and_delayed_command_replay(): void
    {
        $scope = $this->snapchatReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response($this->snapchatReportPayload())]);
        $this->app->instance(Factory::class, $http);
        $base = '/workspaces/'.$this->workspace.'/providers/snapchat/'.$scope->sourceId.'/report';
        $period = ['start_date' => '2026-09-01', 'end_date' => '2026-09-02'];
        $snapshot = $this->getJson($base.'?'.http_build_query($period))->assertOk()->assertJsonPath('generation', 0)->assertJsonPath('timezone', 'Asia/Amman');
        $command = [...$period, 'command_id' => (string) Str::uuid(), 'expected_version' => 1,
            'expected_generation' => 0, 'deadline' => $snapshot->json('deadline')];
        $this->postJson($base, [...$command, 'access_token' => 'injected'])->assertUnprocessable();
        $this->postJson($base, $command)->assertAccepted()->assertJsonPath('queued', true);
        $http->assertNothingSent();
        $payload = $this->owner()->table('jobs')->value('payload');
        $this->assertStringNotContainsString('synthetic-snap-access', $payload);
        Queue::connection('database')->pop('bb2.provider.snapchat')->fire();
        $this->travel(3)->seconds();
        $this->postJson($base, $command)->assertAccepted();
        Queue::connection('database')->pop('bb2.provider.snapchat')->fire();
        $this->getJson($base.'?'.http_build_query($period))->assertOk()->assertJsonPath('generation', 1)->assertJsonPath('last_run.coverage', 'COMPLETE');
        $http->assertSentCount(1);
        $this->assertSame(1, $this->owner()->table('snapchat_read_attempts')->count());
        $this->assertSame(0, $this->owner()->table('tiktok_read_attempts')->count());
        $stream = $this->owner()->table('provider_observation_streams')->where('source_id', $scope->sourceId)->value('stream');
        $history = $this->withinWorkspace(fn ($r) => app(ObservationJournal::class)->history($r, $scope, $stream));
        $this->assertSame('9007199254740993001', $history[0]['observation']['spend']['native_value']);
        $this->assertSame('9007199254740.993001', $history[0]['observation']['spend']['major_units']);
        $this->assertSame(6, $history[0]['observation']['spend']['decimal_scale']);
        $this->assertSame('JOD', $history[0]['observation']['spend']['currency']);
        $this->assertSame('7', $history[0]['observation']['native_facts']['swipes']['value']);
        $this->assertSame('HALF_OPEN_INSTANTS', $history[0]['observation']['window']['boundary']);
        $this->denied(fn () => $this->withinWorkspace(fn () => DB::table('snapchat_read_attempts')->get()), '42501');
    }

    public function test_snapchat_paginated_queue_commits_all_campaigns_once_and_replay_sends_no_http(): void
    {
        $scope = $this->snapchatReaderScope();
        $first = $this->snapchatReportPayload();
        $first['paging'] = ['next_link' => 'https://adsapi.snapchat.com/v1/adaccounts/'.$scope->accountId.'/stats?cursor=synthetic-next&limit=200'];
        $second = $this->snapchatReportPayload();
        $second['total_stats'][0]['total_stat']['breakdown_stats']['campaign'][0]['id'] = '22ada972-f2aa-4d06-a45a-a7a80f53ae34';
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push($first)->push($second)]);
        $this->app->instance(Factory::class, $http);
        $command = (string) Str::uuid();
        $deadline = new DateTimeImmutable('+4 minutes');
        $capture = fn ($r) => Bus::dispatch(SnapchatReadJob::capture($r, $scope,
            new ReportWindow('2026-09-01', '2026-09-02', 'INCLUSIVE_DATES', 'TOTAL', 'Asia/Amman', 'synthetic:native'),
            'snapchat-paginated', $command, 0, 1, $deadline)->onConnection('database'));
        $this->withinWorkspace($capture);
        Queue::connection('database')->pop('bb2.provider.snapchat')->fire();
        $this->travel(3)->seconds();
        $this->withinWorkspace($capture);
        Queue::connection('database')->pop('bb2.provider.snapchat')->fire();
        $http->assertSentCount(2);
        $this->assertSame(1, $this->owner()->table('snapchat_read_attempts')->count());
        $this->assertSame(1, $this->owner()->table('provider_observation_streams')->value('progress_generation'));
        $history = $this->withinWorkspace(fn ($r) => app(ObservationJournal::class)->history($r, $scope, 'snapchat-paginated'));
        $this->assertCount(2, $history);
        $this->assertSame('9007199254740.993001', $history[0]['observation']['spend']['major_units']);
        $this->assertSame('COMPLETE', $this->owner()->table('provider_observation_runs')->value('coverage'));
        $http->assertSent(fn ($r) => ($r->data()['cursor'] ?? null) === 'synthetic-next' && $r['limit'] === '200');
    }

    public function test_snapchat_duplicate_second_page_keeps_durable_observations_and_progress_empty(): void
    {
        $scope = $this->snapchatReaderScope();
        $first = $this->snapchatReportPayload();
        $first['paging'] = ['next_link' => 'https://adsapi.snapchat.com/v1/adaccounts/'.$scope->accountId.'/stats?cursor=synthetic-next&limit=200'];
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push($first)->push($this->snapchatReportPayload())]);
        $this->app->instance(Factory::class, $http);
        $this->withinWorkspace(fn ($r) => Bus::dispatch(SnapchatReadJob::capture($r, $scope,
            new ReportWindow('2026-09-01', '2026-09-02', 'INCLUSIVE_DATES', 'TOTAL', 'Asia/Amman', 'synthetic:native'),
            'snapchat-duplicate-page', (string) Str::uuid(), 0, 1)->onConnection('database')));
        Queue::connection('database')->pop('bb2.provider.snapchat')->fire();
        $http->assertSentCount(2);
        $this->assertSame('FAILED', $this->owner()->table('provider_observation_runs')->value('coverage'));
        $this->assertSame('snapchat_duplicate_report_row', $this->owner()->table('provider_observation_runs')->value('failure'));
        $this->assertNull($this->owner()->table('provider_observation_streams')->value('progress_generation'));
        $this->assertSame([], $this->withinWorkspace(fn ($r) => app(ObservationJournal::class)->history($r, $scope, 'snapchat-duplicate-page')));
        $this->assertSame(0, $this->owner()->table('jobs')->count());
    }

    public function test_snapchat_report_unknown_policy_and_revoked_queued_authority_prevent_native_http(): void
    {
        $proof = $this->evidence();
        $proof['privacy_purpose']['state'] = 'UNKNOWN';
        $scope = $this->snapchatReaderScope($proof);
        $http = new Factory;
        $http->preventStrayRequests();
        $this->app->instance(Factory::class, $http);
        $base = '/workspaces/'.$this->workspace.'/providers/snapchat/'.$scope->sourceId.'/report';
        $this->getJson($base.'?start_date=2026-09-01&end_date=2026-09-02')->assertForbidden();
        $this->assertSame(0, $this->owner()->table('jobs')->count());
        $http->assertNothingSent();
        $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->store($r, $scope, (string) Str::uuid(), 1,
            new ProviderCredentials(['access_token' => 'synthetic-snap-access', 'account_id' => $scope->accountId,
                'account_currency' => 'JOD', 'account_timezone' => 'Asia/Amman', 'expires_at' => (string) (time() + 600)]),
            new DateTimeImmutable('+10 minutes'), 'SYNTHETIC_AUTHORIZED', $this->evidence(), true));
        $this->withinWorkspace(function ($r) use ($scope): void {
            Bus::dispatch(SnapchatReadJob::capture($r, $scope,
                new ReportWindow('2026-09-01', '2026-09-02', 'INCLUSIVE_DATES', 'TOTAL', 'Asia/Amman', 'synthetic:native'),
                'snapchat-revoked', (string) Str::uuid(), 0, 2)->onConnection('database'));
        });
        $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)->where('action', 'provider.read')
            ->update(['expires_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
        $this->denied(fn () => Queue::connection('database')->pop('bb2.provider.snapchat')->fire(), '42501');
        $http->assertNothingSent();
        $this->assertSame(0, $this->owner()->table('snapchat_read_attempts')->count());
        $this->assertSame(0, $this->owner()->table('provider_observation_runs')->count());
    }

    public function test_snapchat_report_attempt_receipt_failure_rolls_back_journal_and_keeps_queue_delivery(): void
    {
        $scope = $this->snapchatReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response($this->snapchatReportPayload())]);
        $this->app->instance(Factory::class, $http);
        $this->withinWorkspace(function ($r) use ($scope): void {
            Bus::dispatch(SnapchatReadJob::capture($r, $scope,
                new ReportWindow('2026-09-01', '2026-09-02', 'INCLUSIVE_DATES', 'TOTAL', 'Asia/Amman', 'synthetic:native'),
                'snapchat-rollback', (string) Str::uuid(), 0, 1)->onConnection('database'));
        });
        $this->owner()->unprepared("CREATE FUNCTION bb2_synthetic_snapchat_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
            RAISE EXCEPTION 'Synthetic Snapchat receipt failure' USING ERRCODE='P0001'; END $$;
            CREATE TRIGGER bb2_synthetic_snapchat_failure BEFORE INSERT ON snapchat_read_attempts FOR EACH ROW EXECUTE FUNCTION bb2_synthetic_snapchat_failure();");
        try {
            $this->denied(fn () => Queue::connection('database')->pop('bb2.provider.snapchat')->fire(), 'P0001');
        } finally {
            $this->owner()->unprepared('DROP TRIGGER bb2_synthetic_snapchat_failure ON snapchat_read_attempts; DROP FUNCTION bb2_synthetic_snapchat_failure();');
        }
        foreach (['snapchat_read_attempts', 'provider_observation_runs', 'provider_observation_entries', 'provider_observation_streams'] as $table) {
            $this->assertSame(0, $this->owner()->table($table)->count());
        }
        $this->assertSame(1, $this->owner()->table('jobs')->count());
        $http->assertSentCount(1);
    }

    public function test_snapchat_report_native_throttle_is_durable_and_retries_only_after_its_delay(): void
    {
        $scope = $this->snapchatReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push('synthetic-throttle', 429, ['Retry-After' => '45'])
            ->push($this->snapchatReportPayload())]);
        $this->app->instance(Factory::class, $http);
        $this->withinWorkspace(function ($r) use ($scope): void {
            Bus::dispatch(SnapchatReadJob::capture($r, $scope,
                new ReportWindow('2026-09-01', '2026-09-02', 'INCLUSIVE_DATES', 'TOTAL', 'Asia/Amman', 'synthetic:native'),
                'snapchat-throttle', (string) Str::uuid(), 0, 1)->onConnection('database'));
        });
        Queue::connection('database')->pop('bb2.provider.snapchat')->fire();
        $http->assertSentCount(1);
        $this->assertSame('FAILED', $this->owner()->table('provider_observation_runs')->value('coverage'));
        $this->assertNull($this->owner()->table('provider_observation_streams')->value('progress_generation'));
        $this->assertSame(1, $this->owner()->table('snapchat_read_attempts')->count());
        $this->assertSame(1, $this->owner()->table('jobs')->count());
        $this->assertNull(Queue::connection('database')->pop('bb2.provider.snapchat'));
        $this->travel(46)->seconds();
        Queue::connection('database')->pop('bb2.provider.snapchat')->fire();
        $http->assertSentCount(2);
        $this->assertSame(2, $this->owner()->table('snapchat_read_attempts')->count());
        $this->assertSame(2, $this->owner()->table('provider_observation_streams')->value('progress_generation'));
        $this->assertSame(0, $this->owner()->table('jobs')->count());
    }

    public function test_snapchat_report_native_delay_beyond_deadline_does_not_retry_earlier(): void
    {
        $scope = $this->snapchatReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response('', 503, ['Retry-After' => '400'])]);
        $this->app->instance(Factory::class, $http);
        $this->withinWorkspace(function ($r) use ($scope): void {
            Bus::dispatch(SnapchatReadJob::capture($r, $scope,
                new ReportWindow('2026-09-01', '2026-09-02', 'INCLUSIVE_DATES', 'TOTAL', 'Asia/Amman', 'synthetic:native'),
                'snapchat-deadline', (string) Str::uuid(), 0, 1)->onConnection('database'));
        });
        Queue::connection('database')->pop('bb2.provider.snapchat')->fire();
        $http->assertSentCount(1);
        $this->assertSame(0, $this->owner()->table('jobs')->count());
        $this->assertNull($this->owner()->table('provider_observation_streams')->value('progress_generation'));
        $this->assertSame(1, $this->owner()->table('snapchat_read_attempts')->count());
    }

    private function snapchatReaderScope(?array $proof = null): ProviderScope
    {
        [, $scope] = $this->snapchatScopes();
        $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->store($r, $scope, (string) Str::uuid(), 0,
            new ProviderCredentials(['access_token' => 'synthetic-snap-access', 'refresh_token' => 'synthetic-snap-refresh',
                'client_id' => 'synthetic-snap-client', 'account_id' => $scope->accountId, 'account_currency' => 'JOD',
                'account_timezone' => 'Asia/Amman', 'expires_at' => (string) (time() + 600)]),
            new DateTimeImmutable('+10 minutes'), 'SYNTHETIC_AUTHORIZED', $proof ?? $this->evidence()));

        return $scope;
    }

    public function test_snapchat_async_metadata_retains_native_ticket_after_rollback_and_never_accepts_csv_or_progress(): void
    {
        $scope = $this->snapchatReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $run = 'ASYNC_STATS:'.$scope->accountId.':1727755200000:7245f6e6-eda4-4650-9255-6e3ab38b95ce';
        $url = 'https://data-transfer.snapads.com/async-report/snap_ads/'.$run.'/9496dff0-07b9-44d8-8f0b-b44eb276cc9e.csv';
        $response = static fn ($state) => ['request_status' => 'SUCCESS', 'async_stats_reports' => [['sub_request_status' => 'SUCCESS',
            'async_stats_report' => ['report_run_id' => $run, 'async_status' => $state] + ($state === 'COMPLETED' ? ['result' => $url] : [])]]];
        $http->fake(['*' => $http->sequence()->push($response('STARTED'))->push($response('RUNNING'))->push($response('COMPLETED'))]);
        $this->configureSnapchatHttp($http);
        $window = new ReportWindow('2026-09-01T00:00:00+03:00', '2026-09-03T00:00:00+03:00', 'HALF_OPEN_INSTANTS', 'TOTAL', 'Asia/Amman', 'synthetic:native');
        $command = (string) Str::uuid();
        $deadline = new DateTimeImmutable('+5 minutes');
        $advance = fn ($r) => app(SnapchatObservationReader::class)->asyncTicket($r, $scope, $window,
            new ProviderReadBudget($scope, 'snap-native-async', 1, 0, 1, 100, new DateTimeImmutable('+30 seconds'), 1, 5), $command, 1, $deadline);
        try {
            $this->withinWorkspace(function ($r) use ($advance): void {
                $this->assertSame('STARTED', $advance($r)['state']);
                throw new \RuntimeException('synthetic-rollback-after-snap-create');
            });
        } catch (\RuntimeException $failure) {
            $this->assertSame('synthetic-rollback-after-snap-create', $failure->getMessage());
        }
        $this->assertSame('RUNNING', $this->withinWorkspace($advance)['state']);
        $completed = $this->withinWorkspace($advance);
        $this->assertSame($url, $completed['download_url']);
        $this->assertSame($completed, $this->withinWorkspace($advance));
        $http->assertSentCount(3);
        $this->assertSame(1, count(array_filter($http->recorded()->all(), fn ($record) => ($record[0]->data()['async'] ?? null) === 'true')));
        $this->assertSame(0, $this->owner()->table('provider_observation_runs')->count());
        $this->assertSame(0, $this->owner()->table('provider_observation_streams')->count());
        $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)->where('action', 'provider.read')
            ->update(['expires_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
        $this->denied(fn () => $this->withinWorkspace($advance), '42501');
        $http->assertSentCount(3);
    }

    public function test_snapchat_async_metadata_uncertain_creation_does_not_repeat_native_get(): void
    {
        $scope = $this->snapchatReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response('invalid-json', 200)]);
        $this->configureSnapchatHttp($http);
        $command = (string) Str::uuid();
        $deadline = new DateTimeImmutable('+5 minutes');
        $window = new ReportWindow('2026-09-01T00:00:00+03:00', '2026-09-03T00:00:00+03:00', 'HALF_OPEN_INSTANTS', 'TOTAL', 'Asia/Amman', 'synthetic:native');
        foreach (['snapchat_malformed_response', 'snapchat_async_creation_uncertain'] as $reason) {
            try {
                $this->withinWorkspace(fn ($r) => app(SnapchatObservationReader::class)->asyncTicket($r, $scope, $window,
                    new ProviderReadBudget($scope, 'snap-native-async', 1, 0, 1, 100, new DateTimeImmutable('+30 seconds'), 1, 5), $command, 1, $deadline));
                $this->fail('Ambiguous creation must not automatically create another native report.');
            } catch (SnapchatRequestFailed $failure) {
                $this->assertSame($reason, $failure->reason);
            }
        }
        $http->assertSentCount(1);
        $this->assertSame(0, $this->owner()->table('provider_observation_runs')->count());
    }

    public function test_snapchat_async_worker_polls_same_ticket_and_marks_schema_blocker_without_download_or_progress(): void
    {
        $scope = $this->snapchatReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $run = 'ASYNC_STATS:'.$scope->accountId.':1727755200000:7245f6e6-eda4-4650-9255-6e3ab38b95ce';
        $url = 'https://data-transfer.snapads.com/async-report/snap_ads/'.$run.'/9496dff0-07b9-44d8-8f0b-b44eb276cc9e.csv';
        $response = static fn ($state) => ['request_status' => 'SUCCESS', 'async_stats_reports' => [['sub_request_status' => 'SUCCESS',
            'async_stats_report' => ['report_run_id' => $run, 'async_status' => $state] + ($state === 'COMPLETED' ? ['result' => $url] : [])]]];
        $http->fake(['https://adsapi.snapchat.com/*' => $http->sequence()->push($response('STARTED'))->push($response('RUNNING'))->push($response('COMPLETED'))]);
        $this->configureSnapchatHttp($http);
        $command = (string) Str::uuid();
        $deadline = now()->addMinutes(5)->toDateTimeImmutable();
        $window = new ReportWindow('2026-09-01T00:00:00+03:00', '2026-09-03T00:00:00+03:00', 'HALF_OPEN_INSTANTS', 'TOTAL', 'Asia/Amman', 'synthetic:native');
        $capture = fn ($r) => Bus::dispatch(SnapchatReadJob::capture($r, $scope, $window, 'snap-async-worker', $command, 0, 1, $deadline, asynchronous: true)->onConnection('database'));
        $this->withinWorkspace($capture);
        Queue::connection('database')->pop('bb2.provider.snapchat')->fire();
        $this->assertSame('PENDING', $this->owner()->table('provider_observation_runs')->value('coverage'));
        $this->assertNull($this->owner()->table('provider_observation_streams')->value('progress_generation'));
        $payload = $this->owner()->table('jobs')->value('payload');
        $this->assertStringNotContainsString($run, $payload);
        $this->assertStringNotContainsString('synthetic-snap-access', $payload);
        $this->travel(6)->seconds();
        Queue::connection('database')->pop('bb2.provider.snapchat')->fire();
        $this->travel(6)->seconds();
        Queue::connection('database')->pop('bb2.provider.snapchat')->fire();
        $latest = $this->owner()->table('provider_observation_runs')->orderByDesc('generation')->first();
        $this->assertSame('FAILED', $latest->coverage);
        $this->assertSame('snapchat_async_schema_unverified', $latest->failure);
        $this->assertNull($this->owner()->table('provider_observation_streams')->value('progress_generation'));
        $this->assertSame(0, $this->owner()->table('provider_observation_entries')->count());
        $this->assertSame(0, $this->owner()->table('jobs')->count());
        $this->withinWorkspace($capture);
        Queue::connection('database')->pop('bb2.provider.snapchat')->fire();
        $http->assertSentCount(3);
        $this->assertSame(1, count(array_filter($http->recorded()->all(), fn ($record) => ($record[0]->data()['async'] ?? null) === 'true')));
        $this->travelBack();
    }

    public function test_snapchat_renew_rotates_expired_custody_rechecks_account_and_replays_without_exchange(): void
    {
        $scope = $this->snapchatExpiredScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $account = ['id' => $scope->accountId, 'currency' => 'JOD', 'timezone' => 'Asia/Amman'];
        $http->fake(['*' => $http->sequence()
            ->push(['access_token' => 'synthetic-renewed-snap-access', 'refresh_token' => 'synthetic-renewed-snap-refresh',
                'expires_in' => 300, 'token_type' => 'Bearer'])
            ->push(['request_status' => 'SUCCESS', 'adaccounts' => [['sub_request_status' => 'SUCCESS', 'adaccount' => $account]]])
            ->push(['request_status' => 'SUCCESS', 'total_stats' => [['sub_request_status' => 'SUCCESS', 'total_stat' => [
                'id' => $scope->accountId, 'type' => 'AD_ACCOUNT', 'granularity' => 'TOTAL', 'stats' => ['impressions' => 0],
            ]]]])]);
        $this->configureSnapchatHttp($http);
        $base = '/workspaces/'.$this->workspace.'/providers/snapchat/'.$scope->sourceId;
        $command = ['command_id' => (string) Str::uuid(), 'expected_version' => 2];
        $renewed = $this->postJson($base.'/renew', $command)->assertOk()->assertJsonPath('receipt.version', 3)
            ->assertJsonPath('access.connection', 'CONNECTED')->assertJsonPath('access.axes.privacy_purpose', 'UNKNOWN');
        $this->assertSame($renewed->json('receipt'), $this->postJson($base.'/renew', $command)->assertOk()->json('receipt'));
        $cipher = $this->owner()->table('provider_access')->where('source_id', $scope->sourceId)->value('encrypted_credentials');
        $stored = json_decode(Crypt::decryptString($cipher), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('synthetic-renewed-snap-refresh', $stored['credentials']['refresh_token']);
        $this->assertStringNotContainsString('synthetic-renewed-snap-access', $renewed->getContent());
        $this->getJson($base.'/report?start_date=2026-09-01&end_date=2026-09-02')->assertForbidden();
        $http->assertSentCount(3);
    }

    public function test_snapchat_renew_denied_grant_preserves_expired_custody_without_claiming_connection(): void
    {
        $scope = $this->snapchatExpiredScope();
        $old = $this->owner()->table('provider_access')->where('source_id', $scope->sourceId)->value('encrypted_credentials');
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['error' => 'invalid_grant', 'error_description' => 'synthetic-private-error'], 400)]);
        $this->configureSnapchatHttp($http);
        $base = '/workspaces/'.$this->workspace.'/providers/snapchat';
        $denied = $this->postJson($base.'/'.$scope->sourceId.'/renew', ['command_id' => (string) Str::uuid(), 'expected_version' => 2])
            ->assertStatus(502)->assertJsonPath('reason', 'snapchat_reconnect_required');
        $this->assertStringNotContainsString('synthetic-private-error', $denied->getContent());
        $this->assertSame($old, $this->owner()->table('provider_access')->where('source_id', $scope->sourceId)->value('encrypted_credentials'));
        $connections = $this->getJson($base)->assertOk()->json('connections');
        $this->assertSame('REAUTHORIZATION_REQUIRED', collect($connections)->firstWhere('source_id', $scope->sourceId)['access']['connection']);
        $http->assertSentCount(1);
    }

    public function test_snapchat_renew_denial_blocks_unexpired_reporting_and_replays_without_native_exchange(): void
    {
        $scope = $this->snapchatReaderScope();
        $old = $this->owner()->table('provider_access')->where('source_id', $scope->sourceId)->value('encrypted_credentials');
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['error' => 'invalid_grant', 'error_description' => 'synthetic-private-error'], 400)]);
        $this->configureSnapchatHttp($http);
        $base = '/workspaces/'.$this->workspace.'/providers/snapchat';
        $command = ['command_id' => (string) Str::uuid(), 'expected_version' => 1];
        $this->postJson($base.'/'.$scope->sourceId.'/renew', $command)->assertStatus(502)
            ->assertJsonPath('reason', 'snapchat_reconnect_required');
        $connections = $this->getJson($base)->assertOk()->json('connections');
        $this->assertSame('REAUTHORIZATION_REQUIRED', collect($connections)->firstWhere('source_id', $scope->sourceId)['access']['connection']);
        $this->getJson($base.'/'.$scope->sourceId.'/report?start_date=2026-09-01&end_date=2026-09-02')->assertForbidden();
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->credentials($r, $scope)), '42501');
        $this->postJson($base.'/'.$scope->sourceId.'/renew', $command)->assertStatus(502)
            ->assertJsonPath('reason', 'snapchat_reconnect_required');
        $this->assertSame($old, $this->owner()->table('provider_access')->where('source_id', $scope->sourceId)->value('encrypted_credentials'));
        $this->assertSame(1, $this->owner()->table('snapchat_renewal_failures')->where('source_id', $scope->sourceId)->count());
        $this->denied(fn () => $this->withinWorkspace(fn () => DB::table('snapchat_renewal_failures')->where('source_id', $scope->sourceId)->delete()), '42501');
        $record = fn ($r, $resource, $reason) => DB::selectOne('SELECT builderad_snapchat_renewal_failure(?, ?, ?::uuid, ?, ?, ?, ?, ?::uuid, ?)', [
            $scope->workspaceId, SessionAuthority::sessionKey($r), $scope->sourceId, $resource,
            $scope->operation, $scope->purpose, 1, $command['command_id'], $reason,
        ]);
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => $record($r, 'other-account', 'snapchat_reconnect_required')), '42501');
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => $record($r, $scope->accountId, 'snapchat_access_denied')), '23505');
        $http->assertSentCount(1);
    }

    public function test_snapchat_rotated_token_permission_denial_requires_verification_until_new_renewal_epoch(): void
    {
        $scope = $this->snapchatReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $account = ['id' => $scope->accountId, 'currency' => 'JOD', 'timezone' => 'Asia/Amman'];
        $tokens = ['access_token' => 'synthetic-rotated-access', 'refresh_token' => 'synthetic-rotated-refresh',
            'expires_in' => 300, 'token_type' => 'Bearer'];
        $http->fake(['*' => $http->sequence()->push($tokens)->push('synthetic-private-denial', 403)
            ->push($tokens)->push(['request_status' => 'SUCCESS', 'adaccounts' => [['sub_request_status' => 'SUCCESS', 'adaccount' => $account]]])
            ->push(['request_status' => 'SUCCESS', 'total_stats' => [['sub_request_status' => 'SUCCESS', 'total_stat' => [
                'id' => $scope->accountId, 'type' => 'AD_ACCOUNT', 'granularity' => 'TOTAL', 'stats' => ['impressions' => 0],
            ]]]])]);
        $this->configureSnapchatHttp($http);
        $base = '/workspaces/'.$this->workspace.'/providers/snapchat';
        $command = ['command_id' => (string) Str::uuid(), 'expected_version' => 1];
        $failed = $this->postJson($base.'/'.$scope->sourceId.'/renew', $command)->assertStatus(502)
            ->assertJsonPath('reason', 'snapchat_access_denied')->assertJsonPath('access.connection', 'VERIFICATION_NEEDED')
            ->assertJsonPath('access.axes.provider_permission', 'FAILED')->assertJsonPath('access.axes.credential', 'VERIFICATION_NEEDED');
        $this->assertStringNotContainsString('synthetic-private-denial', $failed->getContent());
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->credentials($r, $scope)), '42501');
        $this->postJson($base.'/'.$scope->sourceId.'/renew', $command)->assertStatus(502);
        $http->assertSentCount(2);
        $this->postJson($base.'/'.$scope->sourceId.'/renew', ['command_id' => (string) Str::uuid(), 'expected_version' => 1])
            ->assertOk()->assertJsonPath('receipt.version', 2)->assertJsonPath('access.connection', 'CONNECTED');
        $connections = $this->getJson($base)->assertOk()->json('connections');
        $this->assertSame('CONNECTED', collect($connections)->firstWhere('source_id', $scope->sourceId)['access']['connection']);
        $this->postJson($base.'/'.$scope->sourceId.'/renew', $command)->assertForbidden();
        $http->assertSentCount(5);
        $this->assertSame(1, $this->owner()->table('snapchat_renewal_failures')->where('source_id', $scope->sourceId)->count());
    }

    private function snapchatExpiredScope(): ProviderScope
    {
        $scope = $this->snapchatReaderScope();
        $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->store($r, $scope, (string) Str::uuid(), 1,
            new ProviderCredentials(['access_token' => 'synthetic-old-snap-access', 'refresh_token' => 'synthetic-old-snap-refresh',
                'client_id' => 'synthetic-snap-client', 'account_id' => $scope->accountId, 'account_currency' => 'JOD',
                'account_timezone' => 'Asia/Amman', 'expires_at' => (string) (time() - 1)]),
            new DateTimeImmutable('-1 second'), 'SYNTHETIC_EXPIRED', $this->evidence(), true));

        return $scope;
    }

    private function snapchatReportPayload(): array
    {
        return ['request_status' => 'SUCCESS', 'total_stats' => [['sub_request_status' => 'SUCCESS', 'total_stat' => [
            'id' => '8b8e40af-fc64-455d-925b-ca80f7af6914', 'type' => 'AD_ACCOUNT', 'granularity' => 'TOTAL',
            'start_time' => '2026-09-01T00:00:00+03:00', 'end_time' => '2026-09-03T00:00:00+03:00',
            'breakdown_stats' => ['campaign' => [['id' => '497979f0-ea17-4971-8288-054883f1caca', 'type' => 'CAMPAIGN',
                'granularity' => 'TOTAL', 'stats' => ['spend' => '9007199254740993001', 'swipes' => 7, 'impressions' => 100]]]],
        ]]]];
    }

    private function configureGoogleHttp(Factory $http): void
    {
        config()->set('app.url', 'https://builderad.example');
        config()->set('services.google_ads', ['version' => 'v25', 'client_id' => 'synthetic.apps.googleusercontent.com',
            'client_secret' => 'synthetic-client-secret',
            'redirect_uri' => 'https://builderad.example/providers/google/callback',
            'privacy_url' => 'https://builderad.example/privacy', 'notice_revision' => '1']);
        $this->app->instance(Factory::class, $http);
    }

    private function googleInstallationScope(): ProviderScope
    {
        $scope = new ProviderScope($this->workspaceId, (string) Str::uuid7(), Provider::Google,
            'synthetic.apps.googleusercontent.com', 'discovery.read', 'advertising_analysis');
        foreach (GrantAuthority::SOURCE_ACTIONS as $action) {
            $this->grant($scope, $action);
        }
        $this->postJson('/workspaces/'.$this->workspace.'/sources', ['source_id' => $scope->sourceId, 'command_id' => (string) Str::uuid(),
            'effect' => 'register', 'family' => 'google', 'namespace' => 'oauth_client', 'external_id' => $scope->accountId,
            'label' => 'Synthetic Google OAuth installation', 'resource' => $scope->accountId, 'operation' => $scope->operation,
            'purpose' => $scope->purpose, 'expected_version' => 0, 'reason' => 'synthetic_registration'])->assertOk();

        return $scope;
    }

    private function googleConnectionToken(): array
    {
        return ['access_token' => 'synthetic-new-access', 'refresh_token' => 'synthetic-new-refresh',
            'scope' => GoogleOAuthClient::SCOPE, 'token_type' => 'Bearer', 'expires_in' => 600];
    }

    private function googleCoordinator(Factory $http, string $clientSecret = 'synthetic-client-secret'): GoogleAuthorizationCoordinator
    {
        $requests = app(ProviderRequestGuard::class);
        $configuration = new GoogleAccessAdapter($http, 'v25', 'synthetic.apps.googleusercontent.com',
            $clientSecret, '', 'https://builderad.example/providers/google/callback',
            static fn () => throw new \LogicException('Unbound configuration cannot perform HTTP.'));

        return new GoogleAuthorizationCoordinator($http, $configuration,
            new GoogleOAuthState(Cache::store(), $requests), new GooglePendingAccess(Cache::store(), $requests),
            $requests, app(ProviderAccessStore::class));
    }

    private function configureMetaWeb(Factory $http): void
    {
        config()->set('app.url', 'https://builderad.example');
        config()->set('services.meta_ads', ['version' => 'v26.0', 'app_id' => '777', 'app_secret' => 'synthetic-app-secret',
            'redirect_uri' => 'https://builderad.example/providers/meta/callback', 'configuration_id' => '999',
            'token_kind' => 'BUSINESS_INTEGRATION_SYSTEM_USER', 'privacy_url' => 'https://builderad.example/privacy',
            'deletion_instructions_url' => 'https://builderad.example/data-deletion', 'notice_revision' => 'synthetic-notice-v1']);
        $this->app->instance(Factory::class, $http);
    }

    private function metaWebTransport(): Factory
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $debug = ['data' => ['is_valid' => true, 'type' => 'SYSTEM_USER', 'app_id' => '777', 'user_id' => '888',
            'scopes' => ['ads_read'], 'expires_at' => 0, 'granular_scopes' => [['scope' => 'ads_read', 'target_ids' => ['123']]]]];
        $business = ['id' => '888', 'client_business_id' => '555'];
        $http->fake(['*' => $http->sequence()->push(['access_token' => 'synthetic-web-token'])->push($debug)->push($business)
            ->push($debug)->push($business)->push(['id' => 'act_123', 'account_id' => '123', 'name' => 'Synthetic granted account'])
            ->push($debug)->push($business)->push(['id' => 'act_123', 'account_id' => '123', 'currency' => 'USD', 'timezone_name' => 'UTC'])
            ->push(['data' => []])]);

        return $http;
    }

    private function configureMetaQueue(Factory $http): void
    {
        config()->set('queue.default', 'database');
        config()->set('services.meta_ads.version', 'v26.0');
        config()->set('services.meta_ads.app_secret', 'synthetic-app-secret');
        $this->app->instance(Factory::class, $http);
    }

    private function metaQueueWindow(): ReportWindow
    {
        return new ReportWindow('2026-09-01', '2026-09-01', 'INCLUSIVE_DATES', 'DAY', 'UTC', 'synthetic:verified-native-timezone');
    }

    private function queueMetaRead(ProviderScope $scope, ?MetaReportTask $task = null): MetaReadJob
    {
        return $this->withinWorkspace(function ($r) use ($scope, $task): MetaReadJob {
            $before = $this->owner()->table('jobs')->count();
            $job = MetaReadJob::capture($r, $scope, $this->metaQueueWindow(), 'meta-native-queue', (string) Str::uuid(), 0, 1, $task);
            Bus::dispatch($job);
            $this->assertSame($before, $this->owner()->table('jobs')->count());

            return $job;
        });
    }

    private function metaReaderScope(bool $storeCredentials = true): ProviderScope
    {
        $scope = new ProviderScope($this->workspaceId, (string) Str::uuid7(), Provider::Meta, '123', 'report.read', 'advertising_analysis');
        foreach (GrantAuthority::SOURCE_ACTIONS as $action) {
            $this->grant($scope, $action);
        }
        $this->postJson('/workspaces/'.$this->workspace.'/sources', ['source_id' => $scope->sourceId, 'command_id' => (string) Str::uuid(),
            'effect' => 'register', 'family' => 'meta', 'namespace' => 'ad_account', 'external_id' => '123', 'label' => 'Synthetic native Meta account',
            'resource' => '123', 'operation' => $scope->operation, 'purpose' => $scope->purpose, 'expected_version' => 0,
            'reason' => 'synthetic_registration'])->assertOk();
        if ($storeCredentials) {
            $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->store($r, $scope, (string) Str::uuid(), 0,
                new ProviderCredentials(['access_token' => 'synthetic-native-token', 'account_id' => '123',
                    'account_timezone' => 'UTC', 'account_currency' => 'USD']), null, 'SYNTHETIC_AUTHORIZED', $this->evidence()));
        }

        return $scope;
    }

    private function metaReaderRow(): array
    {
        return ['account_id' => '123', 'account_currency' => 'USD', 'campaign_id' => '456', 'date_start' => '2026-09-01',
            'date_stop' => '2026-09-01', 'spend' => '9007199254740993.01', 'impressions' => '10', 'clicks' => '0'];
    }

    private function metaRead(ProviderScope $scope, Factory $http, string $timezone = 'UTC', string $stream = 'meta-integrated'): array
    {
        $reader = new MetaObservationReader($http, app(ObservationJournal::class), app(ProviderAccessStore::class), app(ProviderRequestGuard::class));

        return $this->withinWorkspace(fn ($r) => $reader->read($r, $scope,
            new ReportWindow('2026-09-01', '2026-09-01', 'INCLUSIVE_DATES', 'DAY', $timezone, 'synthetic:verified-native-timezone'),
            new ProviderReadBudget($scope, 'meta-integrated', 4, 0, 4, 10, new DateTimeImmutable('+30 seconds'), 1, 5),
            'v26.0', 'synthetic-app-secret', $stream, (string) Str::uuid(), 0, 1));
    }

    private function journal(ProviderScope $scope, string $command, int $generation, ?string $spend,
        ?NativeProgress $progress = null, string $coverage = 'COMPLETE'): array
    {
        return $this->withinWorkspace(fn ($r) => $this->acceptJournal($r, $scope, $command, $generation, $spend, $progress, $coverage));
    }

    private function acceptJournal(Request $r, ProviderScope $scope, string $command, int $generation, ?string $spend,
        ?NativeProgress $progress = null, string $coverage = 'COMPLETE'): array
    {
        $basis = ['api_version' => 'synthetic:v1', 'report' => 'synthetic:campaign', 'documentation_basis' => 'synthetic:fixture'];
        $observations = $spend === null ? [] : [$this->journalObservation($scope, $spend)];

        return app(ObservationJournal::class)->accept($r, new ObservationBatch($scope, $observations, $coverage,
            $coverage === 'PARTIAL' ? 'synthetic-continuation' : null, $coverage === 'FAILED' ? 'synthetic_read_failure' : null),
            'synthetic-range', $command, $generation, 1, $basis, $progress);
    }

    private function journalObservation(ProviderScope $scope, string $spend): AdObservation
    {
        return new AdObservation($scope, 'synthetic-campaign',
            new ReportWindow('2026-09-01', '2026-09-01', 'INCLUSIVE_DATES', 'DAY', 'UTC', 'synthetic:inclusive'),
            new NativeAmount($spend, 'USD', 'major', 0, 'synthetic:major-units'),
            ['impressions' => ['value' => '100', 'unit' => 'count', 'meaning' => 'synthetic:delivery', 'basis' => 'PROVIDER_REPORTED']],
            ['api_version' => 'synthetic:v1', 'report' => 'synthetic:campaign', 'documentation_basis' => 'synthetic:fixture'],
            new DateTimeImmutable('2026-10-01T08:00:00Z'), null);
    }

    private function store(ProviderScope $scope, string $command, string $token = 'synthetic-secret-token', ?array $evidence = null): array
    {
        return $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->store($r, $scope, $command, 0,
            new ProviderCredentials(['access_token' => $token]), null, 'SYNTHETIC_AUTHORIZED', $evidence ?? $this->evidence()));
    }

    private function admittedEvidence(ProviderScope $scope): array
    {
        $service = app(ProviderReportingEvidence::class);
        $record = ['scope' => $scope->jsonSerialize(), 'configuration' => $service->configuration($scope)];
        foreach (['provider_support', 'privacy_purpose'] as $axis) {
            $record[$axis] = ['provenance' => 'synthetic:reviewed:'.$axis, 'issuer' => 'synthetic-reviewer',
                'authority_basis' => 'synthetic-test-only', 'approval_reference' => 'synthetic:'.$axis,
                'artifact_sha256' => hash('sha256', 'synthetic:'.$axis),
                'verified_at' => (new DateTimeImmutable('-1 minute'))->format(DATE_ATOM),
                'valid_until' => (new DateTimeImmutable('+10 minutes'))->format(DATE_ATOM)];
        }
        $path = storage_path('app/private/bb2-reporting-evidence/'.hash('sha256', $scope->key('reporting-evidence')).'.enc');
        $this->beforeApplicationDestroyed(static function () use ($path): void {
            if (is_file($path)) {
                unlink($path);
            }
        });

        return $record;
    }

    private function runtimeProcess(array $fixture): Process
    {
        $path = storage_path('framework/testing/runtime-'.Str::uuid().'.json');
        file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));
        $this->beforeApplicationDestroyed(function () use ($path, $fixture): void {
            try {
                if (is_file($path.'.log')) {
                    $log = file_get_contents($path.'.log');
                    foreach (['synthetic-google-token', 'synthetic-new-access', 'synthetic-new-refresh', 'synthetic-client-secret',
                        'synthetic-temporary-secret', $fixture['state'] ?? 'synthetic-no-state'] as $secret) {
                        $this->assertStringNotContainsString($secret, $log, 'Synthetic confidential material appeared in worker logs.');
                    }
                }
            } finally {
                foreach ([$path, $path.'.log'] as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
        });
        $process = new Process([PHP_BINARY, base_path('tests/BB2SyntheticRuntimeWorker.php'), $path], base_path(), ['APP_ENV' => 'testing']);
        $process->setTimeout(50);

        return $process;
    }

    private function queueRuntimeFixture(bool $failure = false): array
    {
        // Match the existing adapter contract, without any real provider endpoint.
        $report = ['fieldMask' => 'customer.id,customer.currencyCode,customer.timeZone,campaign.id,campaign.resourceName,campaign.status,metrics.costMicros,metrics.impressions,metrics.clicks,metrics.conversions,metrics.allConversions,metrics.conversionsValue',
            'results' => [['customer' => ['id' => '1234567890', 'currencyCode' => 'JOD', 'timeZone' => 'Asia/Amman'],
                'campaign' => ['id' => '456', 'resourceName' => 'customers/1234567890/campaigns/456'],
                'metrics' => ['costMicros' => '9007199254740993', 'impressions' => '10', 'clicks' => '0']]]];

        return ['mode' => 'queue', 'google_configuration' => config('services.google_ads'),
            'metadata' => $this->googleReaderMetadata(), 'report' => $report, 'http_failure' => $failure];
    }

    public function test_real_redis_independent_consumers_fence_replay_expiry_wrong_binding_and_sql_rollback_for_all_five(): void
    {
        $env = is_file('C:/dev/builderad/.env') ? Dotenv::parse(file_get_contents('C:/dev/builderad/.env')) : [];
        $value = static fn ($name, $fallback = null) => in_array($env[$name] ?? null, ['', 'null', '(null)'], true) ? null : ($env[$name] ?? Env::get($name, $fallback));
        config()->set('database.redis.client', $value('REDIS_CLIENT', 'predis'));
        foreach (['default', 'cache'] as $connection) {
            foreach (['host' => 'REDIS_HOST', 'port' => 'REDIS_PORT', 'password' => 'REDIS_PASSWORD', 'username' => 'REDIS_USERNAME', 'url' => 'REDIS_URL'] as $key => $name) {
                config()->set('database.redis.'.$connection.'.'.$key, $value($name, $key === 'host' ? '127.0.0.1' : ($key === 'port' ? 6379 : null)));
            }
        }
        $prefix = 'bb2-remediation-'.Str::uuid().':';
        config()->set('database.redis.options.prefix', $prefix);
        config()->set('cache.prefix', $prefix);
        // Redis-backed setup sessions may have resolved stores before this proof's isolated prefix.
        Cache::forgetDriver('redis');
        app('redis')->purge('default');
        app('redis')->purge('cache');
        // RedisManager snapshots configuration at construction; purging connections alone retains it.
        app()->forgetInstance('redis');
        $redis = Cache::store('redis');
        $keys = [];
        $workers = [];
        try {
            foreach ($this->scopes as $scope) {
                if ($scope->provider === Provider::X) {
                    [, , , $scope] = $this->xOAuthFixture();
                }
                $family = ['meta' => 'Meta', 'google' => 'Google', 'snapchat' => 'Snapchat', 'tiktok' => 'TikTok', 'x' => 'X'][$scope->provider->value];
                $class = 'App\\Providers\\Ads\\'.$family.'\\'.$family.'OAuthState';
                $states = new $class($redis, app(ProviderRequestGuard::class));
                $configuration = hash('sha256', 'synthetic:redis:'.$scope->provider->value);
                $issue = function () use ($scope, $states, $configuration, $redis, &$keys): array {
                    $state = $scope->provider === Provider::X ? 'synthetic-'.bin2hex(random_bytes(16)) : null;
                    $state = $this->withinWorkspace(function ($r) use ($scope, $states, $configuration, $state) {
                        if ($scope->provider === Provider::X) {
                            $states->issue($r, $scope, 0, $configuration, new ProviderCredentials(['oauth_token' => $state, 'oauth_token_secret' => 'synthetic-temporary-secret']));

                            return $state;
                        }

                        return $states->issue($r, $scope, 0, $configuration);
                    });
                    $key = 'bb2.'.$scope->provider->value.'.oauth.'.hash('sha256', $state);
                    $keys[] = $key;
                    $cipher = $redis->get($key);
                    $this->assertIsString($cipher);
                    $this->assertStringNotContainsString('synthetic-temporary-secret', $cipher);
                    $this->assertStringNotContainsString($scope->accountId, $cipher);

                    return [$state, $key];
                };
                [$state, $key] = $issue();
                $fixture = ['mode' => 'redis', 'prefix' => $prefix, 'actor' => $this->actor->id, 'scope' => $scope->jsonSerialize(),
                    'session' => $this->app['session']->driver()->getId(), 'generation' => 1, 'configuration' => $configuration, 'state' => $state];
                $wrong = $this->runtimeProcess([...$fixture, 'configuration' => hash('sha256', 'synthetic-wrong')]);
                $wrong->mustRun();
                $this->assertStringContainsString('DENIED', $wrong->getOutput());
                $this->assertNotNull($redis->get($key));
                $pair = [$this->runtimeProcess($fixture), $this->runtimeProcess($fixture)];
                foreach ($pair as $process) {
                    $workers[] = $process;
                    $process->start();
                }
                $outputs = [];
                foreach ($pair as $process) {
                    $process->wait();
                    $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                    $outputs[] = strtok($process->getOutput(), "\r\n");
                    $this->assertStringContainsString('SESSION_KEYS=a001_generation', $process->getOutput());
                    $this->assertStringContainsString('HTTP_REQUESTS=0', $process->getOutput());
                    $this->assertStringNotContainsString($state, $process->getOutput().$process->getErrorOutput());
                    $this->assertStringNotContainsString('synthetic-temporary-secret', $process->getOutput().$process->getErrorOutput());
                }
                sort($outputs);
                $this->assertSame(['CONSUMED', 'DENIED'], $outputs);
                $this->assertNull($redis->get($key));
                [$expired, $key] = $issue();
                $record = json_decode(Crypt::decryptString($redis->get($key)), true);
                $record['expires_at'] = time() - 1;
                $redis->put($key, Crypt::encryptString(json_encode($record)), 30);
                $process = $this->runtimeProcess([...$fixture, 'state' => $expired]);
                $process->mustRun();
                $this->assertStringContainsString('DENIED', $process->getOutput());
                [$rollback, $key] = $issue();
                $process = $this->runtimeProcess([...$fixture, 'state' => $rollback, 'rollback' => true]);
                $process->mustRun();
                $this->assertStringContainsString('CONSUMED_THEN_ROLLED_BACK', $process->getOutput());
                $process = $this->runtimeProcess([...$fixture, 'state' => $rollback]);
                $process->mustRun();
                $this->assertStringContainsString('DENIED', $process->getOutput());
                $this->assertNull($redis->get($key));
            }
            $this->assertSame(0, $this->owner()->table('provider_access')->count());
            $this->assertSame(0, $this->owner()->table('provider_access_events')->count());
            foreach ($keys as $key) {
                $this->assertStringNotContainsString($key, json_encode(session()->all()));
            }
        } finally {
            foreach ($workers as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach ($keys as $key) {
                $redis->forget($key);
                $redis->getStore()->lock($key.'.consume', 10)->forceRelease();
            }
        }
    }

    public function test_database_queue_actual_worker_accepts_signed_public_report_replay_and_provider_retry_without_sibling_effect(): void
    {
        $scope = $this->googleReaderScope();
        $this->store($this->scopes['meta'], (string) Str::uuid());
        $sibling = $this->owner()->table('provider_access')->where('provider', 'meta')->first();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        $this->configureGoogleHttp($http);
        $base = '/workspaces/'.$this->workspace.'/providers/google/'.$scope->sourceId.'/report';
        $period = ['start_date' => '2026-09-01', 'end_date' => '2026-09-02'];
        $status = $this->getJson($base.'?'.http_build_query($period))->assertOk();
        $command = [...$period, 'command_id' => (string) Str::uuid(), 'expected_version' => 1, 'expected_generation' => 0, 'deadline' => $status->json('deadline')];
        $this->postJson($base, $command)->assertAccepted();
        $payload = json_decode($this->owner()->table('jobs')->value('payload'), true);
        $this->assertNotSame(serialize(new \stdClass), $payload['data']['command']);
        $this->assertStringNotContainsString('Google account-local', $payload['data']['command']);
        $this->assertStringNotContainsString('synthetic-google-token', json_encode($payload));
        $worker = $this->runtimeProcess($this->queueRuntimeFixture(true));
        $worker->mustRun();
        $this->assertStringContainsString('HTTP_REQUESTS=1', $worker->getOutput());
        $this->assertSame('google_service_unavailable', $this->owner()->table('provider_observation_runs')->value('failure'));
        $this->assertSame(1, $this->owner()->table('jobs')->count());
        // Respect the real child's not-before value; no shorter local retry.
        sleep(6);
        $worker = $this->runtimeProcess($this->queueRuntimeFixture());
        $worker->mustRun();
        $this->assertStringContainsString('HTTP_REQUESTS=2', $worker->getOutput());
        $this->assertSame(0, $this->owner()->table('jobs')->count());
        $this->getJson($base.'?'.http_build_query($period))->assertOk()->assertJsonPath('generation', 2)->assertJsonPath('last_run.coverage', 'COMPLETE');
        $this->assertSame(1, $this->owner()->table('provider_observation_entries')->count());
        $this->assertSame(2, $this->owner()->table('provider_observation_streams')->where('provider', 'google')->value('progress_generation'));
        $this->assertNotNull($this->owner()->table('provider_observation_streams')->where('provider', 'google')->value('encrypted_progress'));
        $this->postJson($base, $command)->assertAccepted();
        $worker = $this->runtimeProcess($this->queueRuntimeFixture());
        $worker->mustRun();
        $this->assertStringContainsString('HTTP_REQUESTS=0', $worker->getOutput());
        $this->assertSame(2, $this->owner()->table('google_read_attempts')->count());
        $this->assertEquals($sibling, $this->owner()->table('provider_access')->where('provider', 'meta')->first());
        $this->assertSame(0, $this->owner()->table('failed_jobs')->count());
        $http->assertNothingSent();
    }

    public static function queueNegativeCases(): array
    {
        return array_map(static fn ($case) => [$case], ['authority', 'epoch', 'workspace', 'source']);
    }

    #[DataProvider('queueNegativeCases')]
    public function test_database_queue_actual_worker_rechecks_expired_authority_changed_epoch_and_wrong_scope_before_http(string $case): void
    {
        $scope = $this->googleReaderScope();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        $this->configureGoogleHttp($http);
        $base = '/workspaces/'.$this->workspace.'/providers/google/'.$scope->sourceId.'/report';
        $period = ['start_date' => '2026-09-01', 'end_date' => '2026-09-02'];
        $status = $this->getJson($base.'?'.http_build_query($period))->assertOk();
        $this->postJson($base, [...$period, 'command_id' => (string) Str::uuid(), 'expected_version' => 1,
            'expected_generation' => 0, 'deadline' => $status->json('deadline')])->assertAccepted();
        if ($case === 'authority') {
            $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)->where('action', 'provider.read')
                ->update(['expires_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
        } elseif ($case === 'epoch') {
            $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->store($r, $scope, (string) Str::uuid(), 1,
                new ProviderCredentials(['access_token' => 'synthetic-rotated-token']), null, 'CONNECTED', $this->evidence(), true));
        } else {
            // Synthetic delivery tampering stays in disposable encrypted queue custody.
            $row = $this->owner()->table('jobs')->first();
            $payload = json_decode($row->payload, true);
            $job = unserialize(Crypt::decrypt($payload['data']['command']));
            if ($case === 'workspace') {
                (new \ReflectionProperty($job, 'workspacePublicId'))->setValue($job, (string) Str::uuid());
            } else {
                (new \ReflectionProperty($job, 'scope'))->setValue($job, new ProviderScope($scope->workspaceId, (string) Str::uuid7(), Provider::Google, $scope->accountId, $scope->operation, $scope->purpose));
            }
            $payload['data']['command'] = Crypt::encrypt(serialize($job));
            $this->owner()->table('jobs')->where('id', $row->id)->update(['payload' => json_encode($payload)]);
        }
        $worker = $this->runtimeProcess($this->queueRuntimeFixture());
        $worker->mustRun();
        $this->assertStringContainsString('HTTP_REQUESTS=0', $worker->getOutput());
        $this->assertSame(0, $this->owner()->table('provider_observation_runs')->count());
        $this->assertSame(1, $this->owner()->table('jobs')->count());
        $this->assertSame(0, $this->owner()->table('failed_jobs')->count());
        $this->assertSame(1, $this->owner()->table('jobs')->value('attempts'));
    }

    public function test_reporting_evidence_admission_fences_all_five_scopes_configuration_expiry_and_reconnect(): void
    {
        $service = app(ProviderReportingEvidence::class);
        foreach ($this->scopes as $scope) {
            $native = $this->evidence();
            foreach (['provider_support', 'privacy_purpose'] as $axis) {
                $native[$axis] = ['state' => 'UNKNOWN'];
            }
            $this->assertSame('UNKNOWN', $service->apply($scope, $native)['privacy_purpose']['state']);
            $record = $this->admittedEvidence($scope);
            foreach (['workspace_id', 'source_id', 'provider', 'account_id'] as $field) {
                $wrong = $record;
                $wrong['scope'][$field] = $field === 'workspace_id' ? $scope->workspaceId + 1 : 'foreign';
                try {
                    $service->admit($scope, $wrong, 'synthetic-operator');
                    $this->fail('Wrong evidence scope cannot be admitted.');
                } catch (\InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
            $expired = $record;
            $expired['privacy_purpose']['valid_until'] = (new DateTimeImmutable('-1 second'))->format(DATE_ATOM);
            try {
                $service->admit($scope, $expired, 'synthetic-operator');
                $this->fail('Expired evidence cannot be admitted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
            $service->admit($scope, $record, 'synthetic-operator');
            $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->store($r, $scope, (string) Str::uuid(), 0,
                new ProviderCredentials(['access_token' => 'synthetic-admission-token']), null, 'CONNECTED', $native));
            $stored = json_decode($this->owner()->table('provider_access')->where('source_id', $scope->sourceId)->value('verification'), true);
            $this->assertSame('VERIFIED', $stored['provider_support']['state']);
            $this->assertSame('synthetic:reviewed:privacy_purpose', $stored['privacy_purpose']['provenance']);
            $this->withinWorkspace(fn ($r) => app(ProviderRequestGuard::class)->requireCurrent($r, $scope, 'provider.read', 1));
            $path = storage_path('app/private/bb2-reporting-evidence/'.hash('sha256', $scope->key('reporting-evidence')).'.enc');
            $original = file_get_contents($path);
            $expiredRecord = json_decode(Crypt::decryptString($original), true);
            $expiredRecord['privacy_purpose']['valid_until'] = (new DateTimeImmutable('-1 second'))->format(DATE_ATOM);
            file_put_contents($path, Crypt::encryptString(json_encode($expiredRecord)));
            $this->assertSame('UNKNOWN', $service->applicable($scope, $stored)['privacy_purpose']['state']);
            try {
                $this->withinWorkspace(fn ($r) => app(ProviderRequestGuard::class)->requireCurrent($r, $scope, 'provider.read', 1));
                $this->fail('Expired admission cannot preserve current read eligibility.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
            file_put_contents($path, $original);
            $service->withdraw($scope, 'synthetic-operator');
            $this->assertSame('UNKNOWN', $service->applicable($scope, $stored)['provider_support']['state']);
            file_put_contents($path, $original);
            $settings = config('services.'.$scope->provider->value.'_ads');
            config()->set('services.'.$scope->provider->value.'_ads.version', 'synthetic-api-rotation');
            $this->assertSame('UNKNOWN', $service->applicable($scope, $stored)['provider_support']['state']);
            config()->set('services.'.$scope->provider->value.'_ads', $settings);
            config()->set('services.'.$scope->provider->value.'_ads.notice_revision', 'synthetic-rotation');
            $this->assertSame('UNKNOWN', $service->applicable($scope, $stored)['privacy_purpose']['state']);
            try {
                $this->withinWorkspace(fn ($r) => app(ProviderRequestGuard::class)->requireCurrent($r, $scope, 'provider.read', 1));
                $this->fail('Rotated configuration cannot reuse evidence.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
            $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->store($r, $scope, (string) Str::uuid(), 1,
                new ProviderCredentials(['access_token' => 'synthetic-reconnect-token']), null, 'CONNECTED', $native, true));
            $this->denied(fn () => $this->withinWorkspace(fn ($r) => app(ProviderRequestGuard::class)->requireCurrent($r, $scope, 'provider.read', 2)), '42501');
        }
    }

    public function test_google_public_oauth_selection_reaches_reporting_with_operator_admitted_evidence_and_renewal_rechecks_it(): void
    {
        $installation = $this->googleInstallationScope();
        $selected = $this->googleReaderScope(connect: false);
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push($this->googleConnectionToken())
            ->push(['resourceNames' => ['customers/1234567890']])->push($this->googleReaderMetadata())
            ->push(['fieldMask' => 'campaign.id', 'results' => []])
            ->push($this->googleConnectionToken())->push($this->googleReaderMetadata())->push(['fieldMask' => 'campaign.id', 'results' => []])]);
        $this->configureGoogleHttp($http);
        $record = $this->admittedEvidence($selected);
        $root = storage_path('framework/testing/evidence-'.Str::uuid());
        mkdir($root, 0700);
        try {
            file_put_contents($root.'/manifest.json', json_encode($record, JSON_THROW_ON_ERROR));
            file_put_contents($root.'/support.txt', 'synthetic:provider_support');
            file_put_contents($root.'/privacy.txt', 'synthetic:privacy_purpose');
            $this->artisan('bb2:admit-reporting-evidence', ['manifest' => $root.'/manifest.json', '--operator' => 'synthetic-operator',
                '--support-artifact' => $root.'/support.txt', '--privacy-artifact' => $root.'/privacy.txt', '--attest-reviewed-approvals' => true])->assertSuccessful();
            $base = '/workspaces/'.$this->workspace.'/providers/google';
            $url = $this->postJson($base.'/connect', ['source_id' => $installation->sourceId, 'expected_version' => 0])->assertOk()->json('authorization_url');
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            $callback = $this->getJson('/providers/google/callback?'.http_build_query(['state' => $query['state'], 'code' => 'synthetic-code']))->assertOk();
            $page = $this->getJson($base.'/discovery/'.$callback->json('discovery_handle'))->assertOk();
            $handle = $page->json('accounts.0.selection_handle');
            $this->postJson($base.'/select', ['selection' => $handle, 'provider_support' => 'VERIFIED'])->assertUnprocessable();
            $this->postJson($base.'/select', ['selection' => $handle])->assertOk()->assertJsonPath('receipt.version', 1);
            $this->assertSame('VERIFIED', $this->withinWorkspace(fn ($r) => (new GoogleAccessStatus)->snapshot($r, $selected))['axes']['privacy_purpose']);
            $this->assertNotSame('READY', $this->withinWorkspace(fn ($r) => (new GoogleAccessStatus)->snapshot($r, $selected))['effective']['state']);
            $report = $base.'/'.$selected->sourceId.'/report?'.http_build_query(['start_date' => '2026-09-01', 'end_date' => '2026-09-02']);
            $status = $this->getJson($report)->assertOk();
            $this->postJson($base.'/'.$selected->sourceId.'/report', ['start_date' => '2026-09-01', 'end_date' => '2026-09-02',
                'command_id' => (string) Str::uuid(), 'expected_version' => 1, 'expected_generation' => 0, 'deadline' => $status->json('deadline')])->assertAccepted();
            $worker = $this->runtimeProcess($this->queueRuntimeFixture());
            $worker->mustRun();
            $this->assertStringContainsString('HTTP_REQUESTS=2', $worker->getOutput());
            $this->getJson($report)->assertOk()->assertJsonPath('last_run.coverage', 'COMPLETE');
            config()->set('services.google_ads.notice_revision', 'synthetic-new-notice');
            $this->getJson($report)->assertForbidden();
            $this->postJson($base.'/'.$selected->sourceId.'/renew', ['command_id' => (string) Str::uuid(), 'expected_version' => 1])->assertOk();
            $this->assertSame('UNKNOWN', $this->withinWorkspace(fn ($r) => (new GoogleAccessStatus)->snapshot($r, $selected))['axes']['privacy_purpose']);
            $this->getJson($report)->assertForbidden();
            $http->assertSentCount(7);
        } finally {
            foreach (glob($root.'/*') as $file) {
                unlink($file);
            }
            rmdir($root);
        }
    }

    private function evidence(): array
    {
        // Fixed future verification avoids wall-clock-dependent retry fingerprints; entirely synthetic.
        return array_fill_keys(EffectiveCapability::AXES, ['state' => 'VERIFIED', 'provenance' => 'synthetic:provider-verifier',
            'verified_at' => '2026-01-01T00:00:00Z', 'valid_until' => '2099-01-01T00:00:00Z']);
    }

    public function test_x_oauth_state_encrypts_temporary_secret_and_consumes_callback_once(): void
    {
        [$flow, $states, $http, $scope, $token] = $this->xOAuthFixture();
        $url = $this->withinWorkspace(fn ($r) => $flow->authorizationUrl($r, $scope));
        $this->assertSame('https://api.x.com/oauth/authorize?oauth_token='.$token, $url);
        $cipher = Cache::get('bb2.x.oauth.'.hash('sha256', $token));
        $this->assertIsString($cipher);
        $this->assertStringNotContainsString('synthetic-temporary-secret', $cipher);
        $this->assertSame('synthetic-temporary-secret', json_decode(Crypt::decryptString($cipher), true)['credentials']['oauth_token_secret']);
        $routing = $this->withinWorkspace(fn ($r) => $states->locate($r, $flow->configurationBinding(), $token));
        $this->assertSame($this->workspace, $routing['workspace']);
        $this->assertSame($scope->jsonSerialize(), $routing['scope']->jsonSerialize());
        $this->assertArrayNotHasKey('credentials', $routing);
        $handle = $this->withinWorkspace(fn ($r) => $flow->callback($r, $scope, 0, $token, 'synthetic-verifier'));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $handle);
        $this->assertStringNotContainsString('synthetic-user', $handle);
        $pending = new XPendingAccess(Cache::store(), app(ProviderRequestGuard::class));
        $cipher = Cache::get('bb2.x.pending.'.hash('sha256', $handle));
        $this->assertIsString($cipher);
        $this->assertStringNotContainsString('synthetic-user-secret', $cipher);
        $this->assertArrayNotHasKey('credential_expires_at', json_decode(Crypt::decryptString($cipher), true));
        $tokens = $this->withinWorkspace(fn ($r) => $pending->credentials($r, $scope, 0, $flow->configurationBinding(), $handle));
        $this->assertSame('synthetic-user-token', $tokens->value('oauth_token'));
        $this->assertNull(Cache::get('bb2.x.oauth.'.hash('sha256', $token)));
        try {
            $this->withinWorkspace(fn ($r) => $flow->callback($r, $scope, 0, $token, 'synthetic-verifier'));
            $this->fail('Consumed X callback cannot replay.');
        } catch (AuthorizationException) {
            $http->assertSentCount(2);
        }
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
        $this->assertSame(0, $this->owner()->table('provider_observation_runs')->count());
    }

    public function test_x_oauth_changed_context_scope_configuration_and_expired_state_prevent_exchange(): void
    {
        [$flow, $states, $http, $scope, $token] = $this->xOAuthFixture();
        $this->withinWorkspace(fn ($r) => $flow->authorizationUrl($r, $scope));
        $foreign = new ProviderScope($scope->workspaceId, (string) Str::uuid7(), Provider::X, $scope->accountId, $scope->operation, $scope->purpose);
        foreach ([
            fn ($r) => $states->locate($r, str_repeat('0', 64), $token),
            fn ($r) => $flow->callback($r, $foreign, 0, $token, 'synthetic-verifier'),
            fn ($r) => $flow->callback($r, $scope, 1, $token, 'synthetic-verifier'),
            fn ($r) => $flow->callback($r, $scope, 0, 'foreign-token', 'synthetic-verifier'),
            function ($r) use ($states, $flow, $token) {
                $r->setUserResolver(fn () => new User(['id' => $this->actor->id + 10000]));

                return $states->locate($r, $flow->configurationBinding(), $token);
            },
            function ($r) use ($states, $flow, $token) {
                $session = new Store('synthetic-changed-session', new ArraySessionHandler(5));
                $session->start();
                $r->setLaravelSession($session);

                return $states->locate($r, $flow->configurationBinding(), $token);
            },
        ] as $attempt) {
            try {
                $this->withinWorkspace($attempt);
                $this->fail('Changed X context must fail.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $http->assertSentCount(1);
        $this->assertIsString(Cache::get('bb2.x.oauth.'.hash('sha256', $token)));
        $this->travel(301)->seconds();
        try {
            $this->withinWorkspace(fn ($r) => $flow->callback($r, $scope, 0, $token, 'synthetic-verifier'));
            $this->fail('Expired X correlation must fail.');
        } catch (AuthorizationException) {
            $http->assertSentCount(1);
        }
    }

    public function test_x_oauth_exchange_failure_keeps_state_consumed_despite_database_rollback(): void
    {
        [$flow, $states, $http, $scope, $token] = $this->xOAuthFixture(401);
        $this->withinWorkspace(fn ($r) => $flow->authorizationUrl($r, $scope));
        try {
            $this->withinWorkspace(fn ($r) => $flow->callback($r, $scope, 0, $token, 'synthetic-verifier'));
            $this->fail('Denied exchange must fail.');
        } catch (\RuntimeException $error) {
            $this->assertSame('x_oauth_exchange_failed', $error->getMessage());
        }
        $this->assertNull(Cache::get('bb2.x.oauth.'.hash('sha256', $token)));
        try {
            $this->withinWorkspace(fn ($r) => $flow->callback($r, $scope, 0, $token, 'synthetic-verifier'));
            $this->fail('Failed exchange cannot replay.');
        } catch (AuthorizationException) {
            $http->assertSentCount(2);
        }
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
    }

    public function test_x_oauth_publication_follows_commit_and_current_connect_grant_is_rechecked(): void
    {
        [$flow, $states, $http, $scope, $token] = $this->xOAuthFixture();
        try {
            $this->withinWorkspace(function ($r) use ($flow, $scope): void {
                $flow->authorizationUrl($r, $scope);
                throw new \RuntimeException('synthetic-rollback');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('synthetic-rollback', $error->getMessage());
        }
        $this->assertNull(Cache::get('bb2.x.oauth.'.hash('sha256', $token)));
        // Issue explicitly without another native token request, using the synthetic fixture pair.
        $this->withinWorkspace(fn ($r) => $states->issue($r, $scope, 0, $flow->configurationBinding(),
            new ProviderCredentials(['oauth_token' => $token, 'oauth_token_secret' => 'synthetic-temporary-secret'])));
        $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)->where('action', 'provider.connect')
            ->update(['expires_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => $flow->callback($r, $scope, 0, $token, 'synthetic-verifier')), '42501');
        $http->assertSentCount(1);
        $this->assertIsString(Cache::get('bb2.x.oauth.'.hash('sha256', $token)));
    }

    private function xOAuthFixture(int $exchangeStatus = 200, array $discoveryPages = []): array
    {
        $scope = new ProviderScope($this->workspaceId, (string) Str::uuid7(), Provider::X, 'synthetic-x-key', 'discovery.read', 'advertising_analysis');
        foreach (GrantAuthority::SOURCE_ACTIONS as $action) {
            $this->grant($scope, $action);
        }
        $this->postJson('/workspaces/'.$this->workspace.'/sources', ['source_id' => $scope->sourceId, 'command_id' => (string) Str::uuid(),
            'effect' => 'register', 'family' => 'x', 'namespace' => 'developer_app', 'external_id' => $scope->accountId,
            'label' => 'Synthetic X OAuth', 'resource' => $scope->accountId, 'operation' => $scope->operation,
            'purpose' => $scope->purpose, 'expected_version' => 0, 'reason' => 'synthetic_registration'])->assertOk();
        $token = 'synthetic-request-'.Str::uuid();
        $http = new Factory;
        $http->preventStrayRequests();
        $sequence = $http->sequence()->push('oauth_token='.$token.'&oauth_token_secret=synthetic-temporary-secret&oauth_callback_confirmed=true')
            ->push('oauth_token=synthetic-user-token&oauth_token_secret=synthetic-user-secret', $exchangeStatus);
        foreach ($discoveryPages as $page) {
            $sequence->push($page);
        }
        $http->fake(['*' => $sequence]);
        $guard = app(ProviderRequestGuard::class);
        $states = new XOAuthState(Cache::store(), $guard);
        $configuration = new XOAuthClient($http, 'synthetic-x-key', 'synthetic-app-secret',
            'https://builderad.example/providers/x/callback', static fn () => throw new \LogicException('Unbound configuration cannot send.'));
        $flow = new XAuthorizationCoordinator($http, $configuration, $states, $guard,
            new XPendingAccess(Cache::store(), $guard), app(ProviderAccessStore::class));

        return [$flow, $states, $http, $scope, $token];
    }

    public function test_x_pending_publication_and_discard_follow_database_commit(): void
    {
        [$flow, $states, $http, $scope, $token] = $this->xOAuthFixture();
        $this->withinWorkspace(fn ($r) => $flow->authorizationUrl($r, $scope));
        $handle = null;
        try {
            $this->withinWorkspace(function ($r) use ($flow, $scope, $token, &$handle): void {
                $handle = $flow->callback($r, $scope, 0, $token, 'synthetic-verifier');
                throw new \RuntimeException('synthetic-rollback');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('synthetic-rollback', $error->getMessage());
        }
        $this->assertIsString($handle);
        $this->assertNull(Cache::get('bb2.x.pending.'.hash('sha256', $handle)));
        $this->assertNull(Cache::get('bb2.x.oauth.'.hash('sha256', $token)));
        $pending = new XPendingAccess(Cache::store(), app(ProviderRequestGuard::class));
        $handle = $this->withinWorkspace(fn ($r) => $pending->stage($r, $scope, 0, $flow->configurationBinding(),
            new ProviderCredentials(['oauth_token' => 'synthetic-user-token', 'oauth_token_secret' => 'synthetic-user-secret'])));
        try {
            $this->withinWorkspace(function ($r) use ($pending, $scope, $flow, $handle): void {
                $pending->discard($r, $scope, 0, $flow->configurationBinding(), $handle);
                throw new \RuntimeException('synthetic-discard-rollback');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('synthetic-discard-rollback', $error->getMessage());
        }
        $this->assertIsString(Cache::get('bb2.x.pending.'.hash('sha256', $handle)));
        $this->withinWorkspace(fn ($r) => $pending->discard($r, $scope, 0, $flow->configurationBinding(), $handle));
        $this->assertNull(Cache::get('bb2.x.pending.'.hash('sha256', $handle)));
        $http->assertSentCount(2);
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
    }

    public function test_x_pending_context_expiry_and_revoked_authority_cannot_release_credentials(): void
    {
        [$flow, $states, $http, $scope, $token] = $this->xOAuthFixture();
        $this->withinWorkspace(fn ($r) => $flow->authorizationUrl($r, $scope));
        $handle = $this->withinWorkspace(fn ($r) => $flow->callback($r, $scope, 0, $token, 'synthetic-verifier'));
        $pending = new XPendingAccess(Cache::store(), app(ProviderRequestGuard::class));
        try {
            $this->withinWorkspace(fn ($r) => $pending->credentials($r, $scope, 0, str_repeat('0', 64), $handle));
            $this->fail('Changed configuration cannot release tokens.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)->where('action', 'provider.connect')
            ->update(['expires_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => $pending->credentials($r, $scope, 0, $flow->configurationBinding(), $handle)), '42501');
        $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)->where('action', 'provider.connect')->update(['expires_at' => null]);
        $cipher = Cache::get('bb2.x.pending.'.hash('sha256', $handle));
        $this->assertIsString($cipher);
        Cache::put('bb2.x.pending.'.hash('sha256', $handle), 'tampered-cipher', 300);
        try {
            $this->withinWorkspace(fn ($r) => $pending->credentials($r, $scope, 0, $flow->configurationBinding(), $handle));
            $this->fail('Tampered ciphertext cannot release tokens.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        Cache::put('bb2.x.pending.'.hash('sha256', $handle), $cipher, 600);
        $this->travel(301)->seconds();
        try {
            $this->withinWorkspace(fn ($r) => $pending->credentials($r, $scope, 0, $flow->configurationBinding(), $handle));
            $this->fail('Expired envelope cannot release tokens even when cache survives.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $http->assertSentCount(2);
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
    }

    public function test_x_discovery_reads_pending_tokens_under_current_authority_without_creating_account_grants(): void
    {
        $page = ['request' => ['params' => ['count' => 200]], 'next_cursor' => 'opaque-next',
            'data' => [['id' => 'abc123', 'name' => 'Synthetic advertiser', 'deleted' => false]]];
        $last = $page;
        $last['data'][0]['id'] = 'def456';
        $last['next_cursor'] = null;
        $last['request']['params']['cursor'] = 'opaque-next';
        [$flow, $states, $http, $scope, $token] = $this->xOAuthFixture(200, [$page, $last]);
        $sourceCount = $this->owner()->table('source_registry')->count();
        $grantCount = $this->owner()->table('security_action_grants')->count();
        $this->withinWorkspace(fn ($r) => $flow->authorizationUrl($r, $scope));
        $handle = $this->withinWorkspace(fn ($r) => $flow->callback($r, $scope, 0, $token, 'synthetic-verifier'));
        $first = $this->withinWorkspace(fn ($r) => $flow->discover($r, $scope, 0, $handle));
        $this->assertSame('PARTIAL', $first->coverage);
        $this->assertSame('abc123', $first->accounts[0]['id']);
        $this->assertNull($first->accounts[0]['native_role']);
        $second = $this->withinWorkspace(fn ($r) => $flow->discover($r, $scope, 0, $handle, $first->continuation));
        $this->assertSame('COMPLETE', $second->coverage);
        $this->assertSame('def456', $second->accounts[0]['id']);
        $this->assertNull($second->continuation);
        $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)->where('action', 'provider.connect')
            ->update(['expires_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => $flow->discover($r, $scope, 0, $handle)), '42501');
        $http->assertSentCount(4);
        $this->assertSame($sourceCount, $this->owner()->table('source_registry')->count());
        $this->assertSame($grantCount, $this->owner()->table('security_action_grants')->count());
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
        $this->assertSame(0, $this->owner()->table('provider_observation_runs')->count());
    }

    public function test_x_selected_campaign_analyst_custody_is_encrypted_replay_safe_and_not_analysis_ready(): void
    {
        $account = ['request' => ['params' => ['account_id' => 'abc123']],
            'data' => ['id' => 'abc123', 'deleted' => false, 'timezone' => 'Asia/Amman', 'timezone_switch_at' => '2026-01-01T21:00:00Z', 'approval_status' => 'PENDING']];
        $access = ['request' => ['params' => ['account_id' => 'abc123']], 'data' => ['user_id' => 'private-native-user', 'permissions' => ['CAMPAIGN_ANALYST']]];
        [$flow, $states, $http, $installation, $token] = $this->xOAuthFixture(200, [$account, $access]);
        $selected = new ProviderScope($this->workspaceId, (string) Str::uuid7(), Provider::X, 'abc123', 'report.read', 'advertising_analysis');
        foreach (GrantAuthority::SOURCE_ACTIONS as $action) {
            $this->grant($selected, $action);
        }
        $this->postJson('/workspaces/'.$this->workspace.'/sources', ['source_id' => $selected->sourceId, 'command_id' => (string) Str::uuid(),
            'effect' => 'register', 'family' => 'x', 'namespace' => 'ad_account', 'external_id' => $selected->accountId,
            'label' => 'Synthetic selected X account', 'resource' => $selected->accountId, 'operation' => $selected->operation,
            'purpose' => $selected->purpose, 'expected_version' => 0, 'reason' => 'synthetic_registration'])->assertOk();
        $this->withinWorkspace(fn ($r) => $flow->authorizationUrl($r, $installation));
        $handle = $this->withinWorkspace(fn ($r) => $flow->callback($r, $installation, 0, $token, 'synthetic-verifier'));
        $command = (string) Str::uuid();
        $receipt = $this->withinWorkspace(fn ($r) => $flow->select($r, $installation, 0, $handle, $selected, $command, 0));
        $this->assertSame(['version' => 1, 'local_state' => 'CREDENTIAL_STORED'], $receipt);
        $this->assertNull(Cache::get('bb2.x.pending.'.hash('sha256', $handle)));
        $this->assertSame($receipt, $this->withinWorkspace(fn ($r) => $flow->select($r, $installation, 0, $handle, $selected, $command, 0)));
        $http->assertSentCount(4);
        $row = $this->owner()->table('provider_access')->where('source_id', $selected->sourceId)->first();
        $this->assertSame('CONNECTED', $row->native_state);
        $this->assertNull($row->credential_expires_at);
        $this->assertStringNotContainsString('synthetic-user-secret', $row->encrypted_credentials);
        $this->assertStringNotContainsString('private-native-user', $row->encrypted_credentials);
        $envelope = json_decode(Crypt::decryptString($row->encrypted_credentials), true);
        $this->assertSame('CAMPAIGN_ANALYST', $envelope['credentials']['native_role']);
        $this->assertSame('Asia/Amman', $envelope['credentials']['account_timezone']);
        $this->assertArrayNotHasKey('account_currency', $envelope['credentials']);
        $this->assertSame('UNKNOWN', json_decode($row->verification, true)['privacy_purpose']['state']);
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->credentials($r, $selected)), '42501');
        $this->assertSame(1, $this->owner()->table('provider_access_events')->count());
        $this->assertSame(0, $this->owner()->table('provider_observation_runs')->count());
    }

    public function test_x_selected_custody_rollback_keeps_pending_for_retry_without_partial_events(): void
    {
        $account = ['request' => ['params' => ['account_id' => 'synthetic-account']],
            'data' => ['id' => 'synthetic-account', 'deleted' => false, 'timezone' => 'UTC']];
        // Use an alphanumeric synthetic ID compatible with the local X path validator.
        $selected = new ProviderScope($this->workspaceId, (string) Str::uuid7(), Provider::X, 'rollback123', 'report.read', 'advertising_analysis');
        foreach (GrantAuthority::SOURCE_ACTIONS as $action) {
            $this->grant($selected, $action);
        }
        $this->postJson('/workspaces/'.$this->workspace.'/sources', ['source_id' => $selected->sourceId, 'command_id' => (string) Str::uuid(),
            'effect' => 'register', 'family' => 'x', 'namespace' => 'ad_account', 'external_id' => $selected->accountId,
            'label' => 'Synthetic rollback X account', 'resource' => $selected->accountId, 'operation' => $selected->operation,
            'purpose' => $selected->purpose, 'expected_version' => 0, 'reason' => 'synthetic_registration'])->assertOk();
        $account['request']['params']['account_id'] = $selected->accountId;
        $account['data']['id'] = $selected->accountId;
        $access = ['request' => ['params' => ['account_id' => $selected->accountId]], 'data' => ['permissions' => ['CAMPAIGN_ANALYST']]];
        [$flow, $states, $http, $installation, $token] = $this->xOAuthFixture(200, [$account, $access, $account, $access]);
        $this->withinWorkspace(fn ($r) => $flow->authorizationUrl($r, $installation));
        $handle = $this->withinWorkspace(fn ($r) => $flow->callback($r, $installation, 0, $token, 'synthetic-verifier'));
        $command = (string) Str::uuid();
        try {
            $this->withinWorkspace(function ($r) use ($flow, $installation, $handle, $selected, $command): void {
                $flow->select($r, $installation, 0, $handle, $selected, $command, 0);
                throw new \RuntimeException('synthetic-selection-rollback');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('synthetic-selection-rollback', $error->getMessage());
        }
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
        $this->assertSame(0, $this->owner()->table('provider_access_events')->count());
        $this->assertSame(0, $this->owner()->table('provider_access_receipts')->count());
        $this->assertIsString(Cache::get('bb2.x.pending.'.hash('sha256', $handle)));
        $this->assertSame(1, $this->withinWorkspace(fn ($r) => $flow->select($r, $installation, 0, $handle, $selected, $command, 0))['version']);
        $this->assertNull(Cache::get('bb2.x.pending.'.hash('sha256', $handle)));
        $this->assertSame(1, $this->owner()->table('provider_access_events')->count());
        $http->assertSentCount(6);
    }

    private function xStatsFixture(): array
    {
        $scope = new ProviderScope($this->workspaceId, (string) Str::uuid7(), Provider::X, 'abc123', 'report.read', 'advertising_analysis');
        foreach (GrantAuthority::SOURCE_ACTIONS as $action) {
            $this->grant($scope, $action);
        }
        $this->postJson('/workspaces/'.$this->workspace.'/sources', ['source_id' => $scope->sourceId, 'command_id' => (string) Str::uuid(),
            'effect' => 'register', 'family' => 'x', 'namespace' => 'ad_account', 'external_id' => $scope->accountId,
            'label' => 'Synthetic X report account', 'resource' => $scope->accountId, 'operation' => $scope->operation,
            'purpose' => $scope->purpose, 'expected_version' => 0, 'reason' => 'synthetic_registration'])->assertOk();
        // Explicit synthetic evidence proves mechanics only; real privacy approval remains unestablished.
        $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->store($r, $scope, (string) Str::uuid(), 0,
            new ProviderCredentials(['oauth_token' => 'synthetic-token', 'oauth_token_secret' => 'synthetic-secret',
                'account_id' => $scope->accountId, 'account_timezone' => 'Asia/Amman', 'timezone_switch_at' => 'NONE']), null, 'SYNTHETIC_AUTHORIZED', $this->evidence()));
        $report = new XCampaignReport($scope,
            new ReportWindow('2026-09-01', '2026-09-01', 'INCLUSIVE_DATES', 'TOTAL', 'Asia/Amman', 'synthetic:native'), ['campaign1'], 'ALL_ON_TWITTER', false, null, 'NONE');
        $metadata = ['request' => ['params' => ['account_id' => 'abc123', 'campaign_ids' => ['campaign1']]], 'next_cursor' => null,
            'data' => [['id' => 'campaign1', 'currency' => 'JOD', 'funding_instrument_id' => 'fund1']]];
        $params = $report->query();
        $params['entity_ids'] = ['campaign1'];
        $params['metric_groups'] = ['BILLING', 'ENGAGEMENT'];
        $stats = ['request' => ['params' => $params], 'data_type' => 'stats', 'time_series_length' => 1,
            'data' => [['id' => 'campaign1', 'id_data' => [['segment' => null, 'metrics' => ['billed_charge_local_micro' => ['9007199254740993001']]]]]]];

        return [$scope, $report, $metadata, $stats];
    }

    public function test_x_stats_journal_accepts_only_complete_reads_and_rolls_back_atomically(): void
    {
        [$scope, $report, $metadata, $stats] = $this->xStatsFixture();
        foreach (['incomplete', 'rollback', 'complete', 'missing-purpose'] as $case) {
            $http = new Factory;
            $http->preventStrayRequests();
            $body = $stats;
            if ($case === 'incomplete') {
                $body['data'] = [];
            }
            $http->fake(['*' => $http->sequence()->push($metadata)->push($body)]);
            $signer = new XOAuthSigner('synthetic-key', 'synthetic-app-secret');
            $reader = new XObservationReader($http, $signer, app(ObservationJournal::class),
                app(ProviderAccessStore::class), app(ProviderRequestGuard::class));
            $read = fn ($r) => $reader->read($r, $report,
                new ProviderReadBudget($scope, 'x-integrated', 2, 0, 2, 2, new DateTimeImmutable('+30 seconds'), 1, 5),
                'x-integrated', (string) Str::uuid(), $case === 'missing-purpose' ? 1 : 0, 1);
            if ($case === 'missing-purpose') {
                $proof = $this->evidence();
                $proof['privacy_purpose'] = ['state' => 'UNKNOWN'];
                $this->owner()->table('provider_access')->where('source_id', $scope->sourceId)->update(['verification' => json_encode($proof)]);
                $this->denied(fn () => $this->withinWorkspace($read), '42501');
                $http->assertNothingSent();
                $this->assertSame(1, $this->owner()->table('provider_observation_runs')->count());

                continue;
            }
            try {
                $receipt = $this->withinWorkspace(function ($r) use ($read, $case) {
                    $receipt = $read($r);
                    if ($case === 'rollback') {
                        throw new \RuntimeException('synthetic-journal-rollback');
                    }

                    return $receipt;
                });
                $this->assertSame('complete', $case);
                $this->assertSame(1, $receipt['generation']);
            } catch (\RuntimeException $error) {
                $this->assertSame($case === 'incomplete' ? 'x_entities_incomplete' : 'synthetic-journal-rollback', $error->getMessage());
            }
            $http->assertSentCount(2);
            foreach (['provider_observation_streams', 'provider_observation_runs', 'provider_observation_entries'] as $table) {
                $this->assertSame($case === 'complete' ? 1 : 0, $this->owner()->table($table)->count());
            }
        }
        $history = $this->withinWorkspace(fn ($r) => app(ObservationJournal::class)->history($r, $scope, 'x-integrated'));
        $this->assertCount(1, $history);
        $this->assertSame('9007199254740993001', $history[0]['observation']['spend']['native_value']);
        $this->assertSame('JOD', $history[0]['observation']['spend']['currency']);
        $this->assertSame('fund1', $history[0]['observation']['native_facts']['funding_instrument_id']['value']);
    }

    public function test_x_native_queue_encrypts_context_and_committed_replay_sends_no_extra_http(): void
    {
        [$scope, $report, $metadata, $stats] = $this->xStatsFixture();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push($metadata)->push($stats)]);
        $this->app->instance(Factory::class, $http);
        config()->set('services.x_ads', ['consumer_key' => 'synthetic-x-key', 'consumer_secret' => 'synthetic-x-app-secret']);
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.connection', 'pgsql');
        $command = (string) Str::uuid();
        $dispatch = function ($r) use ($report, $command) {
            $job = XReadJob::capture($r, $report, 'x-native-queue', $command, 0, 1);
            Bus::dispatch($job);

            return $job;
        };
        try {
            $this->withinWorkspace(function ($r) use ($dispatch): void {
                $dispatch($r);
                throw new \RuntimeException('synthetic-dispatch-rollback');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('synthetic-dispatch-rollback', $error->getMessage());
        }
        $this->assertSame(0, $this->owner()->table('jobs')->count());
        $job = $this->withinWorkspace($dispatch);
        $payload = $this->owner()->table('jobs')->value('payload');
        foreach (['synthetic-x-key', 'synthetic-x-app-secret', 'synthetic-token', 'synthetic-secret', $this->app['session']->driver()->getId()] as $private) {
            $this->assertStringNotContainsString($private, $payload);
        }
        $idleBefore = $this->owner()->table('security_sessions')->where('user_id', $this->actor->id)->value('idle_expires_at');
        Queue::connection('database')->pop('bb2.provider.x')->fire();
        $http->assertSentCount(2);
        Bus::dispatch($job);
        Queue::connection('database')->pop('bb2.provider.x')->fire();
        $http->assertSentCount(2);
        $this->assertSame(1, $this->owner()->table('x_read_attempts')->count());
        $this->assertSame(1, $this->owner()->table('provider_observation_runs')->count());
        $this->assertSame($idleBefore, $this->owner()->table('security_sessions')->where('user_id', $this->actor->id)->value('idle_expires_at'));
        $row = $this->owner()->table('x_read_attempts')->first();
        $this->assertStringNotContainsString($command, $row->encrypted_result);
        $this->assertSame(1, json_decode(Crypt::decryptString($row->encrypted_result), true)['result']['receipt']['generation']);
        $this->denied(fn () => $this->withinWorkspace(fn () => DB::table('x_read_attempts')->get()), '42501');
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => app(XReadExecution::class)->execute(
            $r, $scope, 'x-native-queue', $command, 1, 0, str_repeat('a', 64), static fn () => throw new \RuntimeException('replay-must-not-read'))), '23505');
        config()->set('services.x_ads.consumer_secret', 'synthetic-rotated-secret');
        try {
            $this->app->call([$job, 'handle']);
            $this->fail('Configuration rotation must deny queued work.');
        } catch (AuthorizationException $error) {
            $this->assertSame('X queued signing configuration changed.', $error->getMessage());
        }
        config()->set('services.x_ads.consumer_secret', 'synthetic-x-app-secret');
        $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)->where('action', 'provider.read')
            ->update(['expires_at' => now()->subMinute()]);
        $this->denied(fn () => $this->app->call([$job, 'handle']), '42501');
        $http->assertSentCount(2);
        $this->assertSame(1, $this->owner()->table('provider_observation_runs')->count());
    }

    public function test_x_execution_receipt_and_observations_commit_together_after_rollback(): void
    {
        [$scope, $report, $metadata, $stats] = $this->xStatsFixture();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push($metadata)->push($stats)->push($metadata)->push($stats)]);
        $reader = new XObservationReader($http, new XOAuthSigner('synthetic-key', 'synthetic-app-secret'),
            app(ObservationJournal::class), app(ProviderAccessStore::class), app(ProviderRequestGuard::class));
        $execution = app(XReadExecution::class);
        $command = (string) Str::uuid();
        $fingerprint = $report->binding();
        $run = fn ($r) => $execution->execute($r, $scope, 'x-atomic-execution', $command, 1, 0, $fingerprint,
            fn () => $reader->read($r, $report,
                new ProviderReadBudget($scope, 'x-atomic-execution', 2, 0, 2, 2, new DateTimeImmutable('+30 seconds'), 1, 5),
                'x-atomic-execution', $command, 0, 1));
        try {
            $this->withinWorkspace(function ($r) use ($run): void {
                $run($r);
                throw new \RuntimeException('synthetic-execution-rollback');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('synthetic-execution-rollback', $error->getMessage());
        }
        foreach (['x_read_attempts', 'provider_observation_streams', 'provider_observation_runs', 'provider_observation_entries'] as $table) {
            $this->assertSame(0, $this->owner()->table($table)->count());
        }
        $http->assertSentCount(2);
        $receipt = $this->withinWorkspace($run);
        $this->assertSame(1, $receipt['generation']);
        $this->assertSame($receipt, $this->withinWorkspace($run));
        $http->assertSentCount(4);
        $this->assertSame(1, $this->owner()->table('x_read_attempts')->count());
        $this->assertSame(1, $this->owner()->table('provider_observation_entries')->count());
    }

    private function xRateQueueFixture(int $delay): array
    {
        [$scope, $report, $metadata, $stats] = $this->xStatsFixture();
        $http = new Factory;
        $http->preventStrayRequests();
        $until = time() + $delay;
        $http->fake(['*' => $http->sequence()->push($metadata)->push('private-rate-body', 429,
            ['x-rate-limit-limit' => '250', 'x-rate-limit-remaining' => '0', 'x-rate-limit-reset' => (string) $until])->push($metadata)->push($stats)]);
        $this->app->instance(Factory::class, $http);
        config()->set('services.x_ads', ['consumer_key' => 'synthetic-x-key', 'consumer_secret' => 'synthetic-x-app-secret']);
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.connection', 'pgsql');
        $job = $this->withinWorkspace(function ($r) use ($report) {
            $job = XReadJob::capture($r, $report, 'x-rate-queue', (string) Str::uuid(), 0, 1);
            Bus::dispatch($job);

            return $job;
        });

        return [$job, $http, $report, $until];
    }

    public function test_x_native_rate_reset_delays_retry_and_duplicate_child_delivery_reads_once(): void
    {
        [$job, $http, $report, $until] = $this->xRateQueueFixture(120);
        Queue::connection('database')->pop('bb2.provider.x')->fire();
        $http->assertSentCount(2);
        $this->assertSame(0, $this->owner()->table('provider_observation_entries')->count());
        $this->assertSame(1, $this->owner()->table('x_read_attempts')->count());
        $decision = json_decode(Crypt::decryptString($this->owner()->table('x_read_attempts')->value('encrypted_result')), true)['result'];
        $this->assertSame('x_rate_limited', $decision['failure']);
        $this->assertGreaterThanOrEqual($until, (new DateTimeImmutable($decision['next_at']))->getTimestamp());
        $this->assertGreaterThanOrEqual($until, (int) $this->owner()->table('jobs')->value('available_at'));
        $this->assertNull(Queue::connection('database')->pop('bb2.provider.x'));
        Bus::dispatch($job);
        Queue::connection('database')->pop('bb2.provider.x')->fire();
        $http->assertSentCount(2);
        $this->assertSame(2, $this->owner()->table('jobs')->count());
        $reader = new XObservationReader($http, new XOAuthSigner('synthetic-x-key', 'synthetic-x-app-secret'),
            app(ObservationJournal::class), app(ProviderAccessStore::class), app(ProviderRequestGuard::class));
        try {
            $this->withinWorkspace(fn ($r) => $reader->read($r, $report,
                new ProviderReadBudget($report->scope, 'x-other-stream', 2, 0, 2, 40, new DateTimeImmutable('+30 seconds'), 1, 5),
                'x-other-stream', (string) Str::uuid(), 0, 1));
            $this->fail('Shared token cooldown must prevent other streams.');
        } catch (XRequestFailed $error) {
            $this->assertSame('x_native_cooldown', $error->reason);
            $this->assertGreaterThan(0, $error->retryAfterSeconds);
        }
        $http->assertSentCount(2);
        try {
            CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp($until + 2));
            Queue::connection('database')->pop('bb2.provider.x')->fire();
            Queue::connection('database')->pop('bb2.provider.x')->fire();
        } finally {
            CarbonImmutable::setTestNow();
        }
        $http->assertSentCount(4);
        $this->assertSame(2, $this->owner()->table('x_read_attempts')->count());
        $this->assertSame(2, $this->owner()->table('provider_observation_runs')->count());
        $this->assertSame(1, $this->owner()->table('provider_observation_entries')->count());
    }

    public function test_x_native_reset_beyond_deadline_persists_without_early_retry(): void
    {
        [$job, $http, $report, $until] = $this->xRateQueueFixture(900);
        Queue::connection('database')->pop('bb2.provider.x')->fire();
        $decision = json_decode(Crypt::decryptString($this->owner()->table('x_read_attempts')->value('encrypted_result')), true)['result'];
        $this->assertSame('x_rate_limited', $decision['failure']);
        $this->assertNull($decision['next_at']);
        $this->assertGreaterThan(300, $decision['retry_after_seconds']);
        $this->assertSame(0, $this->owner()->table('jobs')->count());
        Bus::dispatch($job);
        Queue::connection('database')->pop('bb2.provider.x')->fire();
        $http->assertSentCount(2);
        $this->assertSame(1, $this->owner()->table('x_read_attempts')->count());
        $this->assertSame(0, $this->owner()->table('provider_observation_entries')->count());
    }

    public function test_x_discovery_continuation_is_encrypted_exact_bound_and_published_only_after_commit(): void
    {
        $this->assertTrue(class_exists(XDiscoveryContinuation::class), 'Encrypted X continuation store must exist.');
        $page = ['request' => ['params' => ['count' => 200]], 'next_cursor' => 'private-native-cursor',
            'data' => [['id' => 'abc123', 'name' => 'Synthetic advertiser', 'deleted' => false]]];
        $last = $page;
        $last['next_cursor'] = null;
        $last['data'][0]['id'] = 'def456';
        $last['request']['params']['cursor'] = 'private-native-cursor';
        [$flow, , $http, $scope, $token] = $this->xOAuthFixture(200, [$page, $last]);
        $this->withinWorkspace(fn ($r) => $flow->authorizationUrl($r, $scope));
        $pending = $this->withinWorkspace(fn ($r) => $flow->callback($r, $scope, 0, $token, 'synthetic-verifier'));
        $first = $this->withinWorkspace(fn ($r) => $flow->discover($r, $scope, 0, $pending));
        $store = new XDiscoveryContinuation(Cache::store(), app(ProviderRequestGuard::class));
        $rolledBack = null;
        try {
            $this->withinWorkspace(function ($r) use ($store, $scope, $flow, $pending, $first, &$rolledBack): void {
                $rolledBack = $store->stage($r, $scope, 0, $flow->configurationBinding(), $pending, $first->continuation);
                throw new \RuntimeException('synthetic-continuation-rollback');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('synthetic-continuation-rollback', $error->getMessage());
        }
        $this->assertNull(Cache::get('bb2.x.continuation.'.hash('sha256', $rolledBack)));
        $handle = $this->withinWorkspace(fn ($r) => $store->stage($r, $scope, 0, $flow->configurationBinding(), $pending, $first->continuation));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $handle);
        $cipher = Cache::get('bb2.x.continuation.'.hash('sha256', $handle));
        foreach (['private-native-cursor', 'synthetic-user-token', 'synthetic-user-secret', $pending] as $private) {
            $this->assertStringNotContainsString($private, $cipher);
        }
        foreach (['configuration', 'pending', 'epoch', 'session'] as $case) {
            try {
                $this->withinWorkspace(function ($r) use ($store, $scope, $flow, $pending, $handle, $case) {
                    if ($case === 'session') {
                        $session = new Store('changed-session', new ArraySessionHandler(120));
                        $session->setId(Str::random(40));
                        $session->put('a001_generation', 1);
                        $r->setLaravelSession($session);
                    }

                    return $store->restore($r, $scope, $case === 'epoch' ? 1 : 0,
                        $case === 'configuration' ? str_repeat('a', 64) : $flow->configurationBinding(),
                        $case === 'pending' ? str_repeat('b', 43) : $pending, $handle);
                });
                $this->fail('Changed continuation context must deny.');
            } catch (AuthorizationException|QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $restored = $this->withinWorkspace(fn ($r) => $store->restore($r, $scope, 0, $flow->configurationBinding(), $pending, $handle));
        $second = $this->withinWorkspace(fn ($r) => $flow->discover($r, $scope, 0, $pending, $restored));
        $this->assertSame('COMPLETE', $second->coverage);
        $this->assertSame('def456', $second->accounts[0]['id']);
        $http->assertSentCount(4);
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
        $this->assertSame(0, $this->owner()->table('provider_observation_runs')->count());
        $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)->where('action', 'provider.connect')
            ->update(['expires_at' => now()->subMinute()]);
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => $store->restore($r, $scope, 0, $flow->configurationBinding(), $pending, $handle)), '42501');
    }

    public function test_x_discovery_continuation_expiry_corruption_and_foreign_kind_never_restore(): void
    {
        $this->assertTrue(class_exists(XDiscoveryContinuation::class), 'Encrypted X continuation store must exist.');
        [$flow, , $http, $scope] = $this->xOAuthFixture();
        $store = new XDiscoveryContinuation(Cache::store(), app(ProviderRequestGuard::class));
        $pending = str_repeat('p', 43);
        $native = new NativeProgress('x.accounts.cursor', XAccountDiscovery::BASIS, '{"cursor":"private"}');
        $handle = $this->withinWorkspace(fn ($r) => $store->stage($r, $scope, 0, $flow->configurationBinding(), $pending, $native));
        $key = 'bb2.x.continuation.'.hash('sha256', $handle);
        $original = Cache::get($key);
        foreach (['expired', 'corrupt', 'kind'] as $case) {
            $envelope = json_decode(Crypt::decryptString($original), true);
            if ($case === 'expired') {
                $envelope['expires_at'] = now()->timestamp - 1;
            }
            if ($case === 'kind') {
                $envelope['native']['kind'] = 'foreign.cursor';
            }
            Cache::put($key, $case === 'corrupt' ? 'broken-cipher' : Crypt::encryptString(json_encode($envelope)), 600);
            try {
                $this->withinWorkspace(fn ($r) => $store->restore($r, $scope, 0, $flow->configurationBinding(), $pending, $handle));
                $this->fail('Invalid continuation must deny.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $http->assertNothingSent();
    }

    public function test_x_projected_discovery_exposes_only_opaque_continuation_and_denies_changed_context_before_http(): void
    {
        $page = ['request' => ['params' => ['count' => 200]], 'next_cursor' => 'private-project-cursor',
            'data' => [['id' => 'abc123', 'name' => 'Synthetic advertiser', 'deleted' => false]]];
        $last = $page;
        $last['next_cursor'] = null;
        $last['request']['params']['cursor'] = 'private-project-cursor';
        [$flow, , $http, $scope, $token] = $this->xOAuthFixture(200, [$page, $last]);
        $this->withinWorkspace(fn ($r) => $flow->authorizationUrl($r, $scope));
        $pending = $this->withinWorkspace(fn ($r) => $flow->callback($r, $scope, 0, $token, 'synthetic-verifier'));
        $first = $this->withinWorkspace(fn ($r) => $flow->discoverPage($r, $scope, 0, $pending));
        $this->assertSame(['accounts', 'basis', 'coverage', 'continuation'], array_keys($first));
        $this->assertSame('PARTIAL', $first['coverage']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $first['continuation']);
        foreach (['private-project-cursor', 'synthetic-user-token', 'synthetic-user-secret', $pending] as $private) {
            $this->assertStringNotContainsString($private, json_encode($first));
        }
        foreach (['invalid', 'pending', 'session'] as $case) {
            try {
                $this->withinWorkspace(function ($r) use ($flow, $scope, $pending, $first, $case) {
                    if ($case === 'session') {
                        $session = new Store('changed-projection-session', new ArraySessionHandler(120));
                        $session->setId(Str::random(40));
                        $r->setLaravelSession($session);
                    }

                    return $flow->discoverPage($r, $scope, 0, $case === 'pending' ? str_repeat('b', 43) : $pending,
                        $case === 'invalid' ? 'private-project-cursor' : $first['continuation']);
                });
                $this->fail('Changed projection context cannot send HTTP.');
            } catch (AuthorizationException|QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $http->assertSentCount(3);
        $second = $this->withinWorkspace(fn ($r) => $flow->discoverPage($r, $scope, 0, $pending, $first['continuation']));
        $this->assertSame('COMPLETE', $second['coverage']);
        $this->assertNull($second['continuation']);
        $http->assertSentCount(4);
        $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)->where('action', 'provider.connect')
            ->update(['expires_at' => now()->subMinute()]);
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => $flow->discoverPage($r, $scope, 0, $pending, $first['continuation'])), '42501');
        $http->assertSentCount(4);
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
        $this->assertSame(0, $this->owner()->table('provider_observation_runs')->count());
    }

    public function test_x_explicit_bootstrap_uses_sole_grant_writer_and_separates_installation_from_selected_account(): void
    {
        $model = new Workspace;
        $model->setRawAttributes((array) $this->owner()->table('workspaces')->where('id', $this->workspaceId)->first(), true);
        $bootstrap = app(XSourceBootstrap::class);
        $installationId = (string) Str::uuid7();
        $command = (string) Str::uuid();
        $installation = $this->withinWorkspace(fn ($r) => $bootstrap->requestConnection($r, $model, 'synthetic-x-key', $installationId, $command));
        $this->assertSame('developer_app', $this->owner()->table('source_registry')->where('id', $installationId)->value('namespace'));
        $this->assertSame(['provider.connect', 'source.read', 'source.register'], $this->owner()->table('security_action_grants')
            ->where('source_target_id', $installationId)->orderBy('action')->pluck('action')->all());
        $this->assertSame($installation->jsonSerialize(), $this->withinWorkspace(fn ($r) => $bootstrap->requestConnection($r, $model, 'synthetic-x-key', $installationId, $command))->jsonSerialize());
        $selected = new ProviderScope($this->workspaceId, (string) Str::uuid7(), Provider::X, 'abc123', 'report.read', 'advertising_analysis');
        $selectCommand = (string) Str::uuid();
        $this->withinWorkspace(fn ($r) => $bootstrap->requestAccount($r, $model, $selected, $selectCommand));
        $this->assertSame('ad_account', $this->owner()->table('source_registry')->where('id', $selected->sourceId)->value('namespace'));
        $this->assertSame(['provider.connect', 'provider.read', 'source.read', 'source.register'], $this->owner()->table('security_action_grants')
            ->where('source_target_id', $selected->sourceId)->orderBy('action')->pluck('action')->all());
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
        $this->denied(fn () => $this->withinWorkspace(fn ($r) => app(ProviderRequestGuard::class)->requireCurrent($r, $selected, 'provider.read', 0)), '42501');
        $this->assertSame(0, $this->owner()->table('provider_observation_runs')->count());
    }

    public function test_x_explicit_bootstrap_denies_stale_owner_and_preserves_independent_explicit_grants_after_source_rollback(): void
    {
        $model = new Workspace;
        $model->setRawAttributes((array) $this->owner()->table('workspaces')->where('id', $this->workspaceId)->first(), true);
        $bootstrap = app(XSourceBootstrap::class);
        $sourceId = (string) Str::uuid7();
        try {
            $this->withinWorkspace(function ($r) use ($bootstrap, $model, $sourceId): void {
                $bootstrap->requestConnection($r, $model, 'synthetic-x-key', $sourceId, (string) Str::uuid());
                throw new \RuntimeException('synthetic-bootstrap-rollback');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('synthetic-bootstrap-rollback', $error->getMessage());
        }
        $this->assertSame(0, $this->owner()->table('source_registry')->where('id', $sourceId)->count());
        $this->assertSame(3, $this->owner()->table('security_action_grants')->where('source_target_id', $sourceId)->count());
        $this->owner()->table('security_sessions')->where('user_id', $this->actor->id)->update(['authenticated_at' => now()->subHour()]);
        try {
            $this->withinWorkspace(fn ($r) => $bootstrap->requestConnection($r, $model, 'synthetic-x-key', $sourceId, (string) Str::uuid()));
            $this->fail('Stale owner cannot create connection grants.');
        } catch (AuthorizationException|QueryException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(0, $this->owner()->table('source_registry')->where('id', $sourceId)->count());
        $this->assertSame(3, $this->owner()->table('security_action_grants')->where('source_target_id', $sourceId)->count());
    }

    private function configureXWeb(Factory $http): void
    {
        config()->set('app.url', 'https://builderad.example');
        config()->set('services.x_ads', ['consumer_key' => 'synthetic-x-key', 'consumer_secret' => 'synthetic-app-secret',
            'redirect_uri' => 'https://builderad.example/providers/x/callback', 'privacy_url' => 'https://builderad.example/synthetic-privacy',
            'notice_revision' => 'synthetic:1']);
        $this->app->instance(Factory::class, $http);
    }

    public function test_x_web_one_button_select_replay_reconnect_and_local_disconnect(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $account = ['request' => ['params' => ['account_id' => 'abc123']],
            'data' => ['id' => 'abc123', 'deleted' => false, 'timezone' => 'Asia/Amman', 'timezone_switch_at' => '2026-01-01T21:00:00Z', 'approval_status' => 'PENDING']];
        $permission = ['request' => ['params' => ['account_id' => 'abc123']], 'data' => ['permissions' => ['CAMPAIGN_ANALYST']]];
        $accounts = ['request' => ['params' => ['count' => 200]], 'next_cursor' => null,
            'data' => [['id' => 'abc123', 'name' => 'Synthetic X advertiser', 'deleted' => false]]];
        $sequence = $http->sequence();
        foreach (['synthetic-request-one', 'synthetic-request-two'] as $token) {
            $sequence->push('oauth_token='.$token.'&oauth_token_secret=synthetic-temporary-secret&oauth_callback_confirmed=true')
                ->push('oauth_token=synthetic-user-token&oauth_token_secret=synthetic-user-secret')->push($accounts)->push($account)->push($permission);
        }
        $http->fake(['*' => $sequence]);
        $this->configureXWeb($http);
        $base = '/workspaces/'.$this->workspace.'/providers/x';
        $source = null;
        foreach ([1, 2] as $epoch) {
            $before = $this->owner()->table('source_registry')->count();
            $index = $this->getJson($base)->assertOk()->assertJsonPath('availability', 'CONNECT_AVAILABLE');
            $this->assertSame($before, $this->owner()->table('source_registry')->count());
            $url = $this->postJson($base.'/connect', ['intent' => $index->json('connect_intent')])->assertOk()->json('authorization_url');
            $this->assertSame('api.x.com', parse_url($url, PHP_URL_HOST));
            $this->assertSame('/oauth/authorize', parse_url($url, PHP_URL_PATH));
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            $callbackUrl = '/providers/x/callback?'.http_build_query(['oauth_token' => $query['oauth_token'], 'oauth_verifier' => 'synthetic-private-verifier']);
            $callback = $this->getJson($callbackUrl)->assertOk();
            $this->getJson($callbackUrl)->assertForbidden();
            $beforeDiscovery = $this->owner()->table('source_registry')->count();
            $grants = $this->owner()->table('security_action_grants')->count();
            $page = $this->getJson($base.'/discovery/'.$callback->json('discovery_handle'))->assertOk();
            $this->assertSame($beforeDiscovery, $this->owner()->table('source_registry')->count());
            $this->assertSame($grants, $this->owner()->table('security_action_grants')->count());
            $handle = $page->json('accounts.0.selection_handle');
            $this->postJson($base.'/select', ['selection' => $handle, 'oauth_token' => 'injected'])->assertUnprocessable();
            $selected = $this->postJson($base.'/select', ['selection' => $handle])->assertOk()->assertJsonPath('receipt.version', $epoch);
            $this->assertSame($selected->json('receipt'), $this->postJson($base.'/select', ['selection' => $handle])->assertOk()->json('receipt'));
            if ($source !== null) {
                $this->assertSame($source, $selected->json('source_id'));
            }
            $source = $selected->json('source_id');
            $this->assertSame('CONNECTED', $selected->json('access.connection'));
            $this->assertSame('UNKNOWN', $selected->json('access.axes.privacy_purpose'));
            foreach ([$callback, $page, $selected] as $response) {
                foreach (['synthetic-temporary-secret', 'synthetic-user-token', 'synthetic-user-secret', 'synthetic-private-verifier'] as $secret) {
                    $this->assertStringNotContainsString($secret, $response->getContent());
                }
            }
        }
        $http->assertSentCount(10);
        $this->getJson($base.'/'.$source.'/report?'.http_build_query(['start_date' => '2026-09-01', 'end_date' => '2026-09-01', 'campaign_ids' => ['campaign1'], 'placement' => 'ALL_ON_TWITTER']))->assertForbidden();
        config()->set('services.x_ads', []);
        $command = ['command_id' => (string) Str::uuid(), 'expected_version' => 2];
        $first = $this->postJson($base.'/'.$source.'/disconnect', $command)->assertOk()
            ->assertJsonPath('access.connection', 'LOCALLY_DISCONNECTED')->assertJsonPath('remote_authorization_revoked', false);
        $this->assertSame($first->json('receipt'), $this->postJson($base.'/'.$source.'/disconnect', $command)->assertOk()->json('receipt'));
        $this->assertNull($this->owner()->table('provider_access')->where('source_id', $source)->value('encrypted_credentials'));
        $http->assertSentCount(10);
    }

    public function test_x_web_cancel_rotation_and_unconfigured_status_never_exchange_credentials(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push('oauth_token=synthetic-cancel-token&oauth_token_secret=synthetic-temporary-secret&oauth_callback_confirmed=true')]);
        $base = '/workspaces/'.$this->workspace.'/providers/x';
        config()->set('services.x_ads', []);
        $this->getJson($base)->assertOk()->assertJsonPath('availability', 'CONFIGURATION_REQUIRED')->assertJsonPath('connect_intent', null);
        $this->configureXWeb($http);
        $intent = $this->getJson($base)->assertOk()->json('connect_intent');
        config()->set('services.x_ads.notice_revision', 'synthetic:changed');
        $this->postJson($base.'/connect', ['intent' => $intent])->assertForbidden();
        $http->assertNothingSent();
        config()->set('services.x_ads.notice_revision', 'synthetic:1');
        $this->postJson($base.'/connect', ['intent' => $intent])->assertOk();
        $this->getJson('/providers/x/callback?denied=synthetic-cancel-token')->assertOk()->assertJsonPath('status', 'AUTHORIZATION_NOT_COMPLETED');
        $this->getJson('/providers/x/callback?denied=synthetic-cancel-token')->assertForbidden();
        $this->getJson('/providers/x/callback?denied=a&oauth_token=b')->assertStatus(400);
        $this->getJson('/providers/x/callback?oauth_token[]=a&oauth_verifier=b')->assertStatus(400);
        $http->assertSentCount(1);
    }

    public function test_x_web_report_queues_exact_campaign_placement_and_replays_without_native_http(): void
    {
        [$scope, , $metadata, $stats] = $this->xStatsFixture();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push($metadata)->push($stats)]);
        $this->configureXWeb($http);
        config()->set('queue.default', 'database');
        $base = '/workspaces/'.$this->workspace.'/providers/x/'.$scope->sourceId.'/report';
        $period = ['start_date' => '2026-09-01', 'end_date' => '2026-09-01', 'campaign_ids' => ['campaign1'], 'placement' => 'ALL_ON_TWITTER'];
        $this->getJson($base.'?'.http_build_query($period + ['injected' => true]))->assertUnprocessable();
        $this->getJson($base.'?'.http_build_query(array_replace($period, ['campaign_ids' => ['campaign1', 'campaign1']])))->assertUnprocessable();
        $this->getJson($base.'?'.http_build_query(array_replace($period, ['end_date' => '2026-09-09'])))->assertUnprocessable();
        $status = $this->getJson($base.'?'.http_build_query($period))->assertOk()->assertJsonPath('generation', 0);
        $command = $period + ['command_id' => (string) Str::uuid(), 'expected_version' => 1, 'expected_generation' => 0, 'deadline' => $status->json('deadline')];
        $this->postJson($base, $command)->assertAccepted();
        $this->postJson($base, $command)->assertAccepted();
        $http->assertNothingSent();
        Queue::connection('database')->pop('bb2.provider.x')->fire();
        Queue::connection('database')->pop('bb2.provider.x')->fire();
        $http->assertSentCount(2);
        $this->getJson($base.'?'.http_build_query($period))->assertOk()->assertJsonPath('generation', 1)->assertJsonPath('last_run.coverage', 'COMPLETE');
        $this->assertSame(1, $this->owner()->table('x_read_attempts')->count());
    }

    public function test_x_web_async_queue_commits_processing_ticket_replays_and_accepts_only_completed_native_file(): void
    {
        [$scope, , $metadata, $stats] = $this->xStatsFixture();
        $report = new XCampaignReport($scope, new ReportWindow('2026-09-01', '2026-09-30', 'INCLUSIVE_DATES', 'TOTAL', 'Asia/Amman', 'X account-local inclusive campaign period'), ['campaign1'], 'ALL_ON_TWITTER', true, null, 'NONE');
        $row = $report->query();
        $row['entity_ids'] = ['campaign1'];
        $row['metric_groups'] = ['BILLING', 'ENGAGEMENT'];
        $row += ['id_str' => '1120829647711653888', 'account_id' => 'abc123', 'status' => 'PROCESSING', 'url' => null];
        $success = array_replace($row, ['status' => 'SUCCESS', 'url' => 'https://ton.twimg.com/advertiser-api-async-analytics/stats_job_'.$row['id_str'].'.json.gz']);
        unset($stats['request']);
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push(['data' => $row])->push(['data' => [$row]])->push(['data' => [$success]])->push($metadata)->push(gzencode(json_encode($stats)))]);
        $this->configureXWeb($http);
        config()->set('queue.default', 'database');
        $base = '/workspaces/'.$this->workspace.'/providers/x/'.$scope->sourceId.'/report';
        $period = ['start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'campaign_ids' => ['campaign1'], 'placement' => 'ALL_ON_TWITTER', 'mode' => 'ASYNC'];
        $status = $this->getJson($base.'?'.http_build_query($period))->assertOk();
        $command = $period + ['command_id' => (string) Str::uuid(), 'expected_version' => 1, 'expected_generation' => 0, 'deadline' => $status->json('deadline')];
        $this->postJson($base, $command)->assertAccepted();
        $this->postJson($base, $command)->assertAccepted();
        Queue::connection('database')->pop('bb2.provider.x')->fire();
        Queue::connection('database')->pop('bb2.provider.x')->fire();
        $http->assertSentCount(1);
        $this->assertSame(0, $this->owner()->table('provider_observation_entries')->count());
        $this->getJson($base.'?'.http_build_query($period))->assertOk()->assertJsonPath('last_run.coverage', 'PENDING');
        $cipher = $this->owner()->table('x_read_attempts')->value('encrypted_result');
        $this->assertStringNotContainsString($row['id_str'], $cipher);
        $this->assertSame($row['id_str'], json_decode(Crypt::decryptString($cipher), true)['result']['ticket']['job_id']);
        try {
            $this->travel(6)->seconds();
            Queue::connection('database')->pop('bb2.provider.x')->fire();
            Queue::connection('database')->pop('bb2.provider.x')->fire();
            $http->assertSentCount(2);
            $this->assertSame(0, $this->owner()->table('provider_observation_entries')->count());
            $this->travel(11)->seconds();
            Queue::connection('database')->pop('bb2.provider.x')->fire();
            Queue::connection('database')->pop('bb2.provider.x')->fire();
            $http->assertSentCount(5);
            $this->assertSame(1, $this->owner()->table('provider_observation_entries')->count());
            $this->getJson($base.'?'.http_build_query($period))->assertOk()->assertJsonPath('last_run.coverage', 'COMPLETE')->assertJsonPath('generation', 3);
            $this->assertSame(3, $this->owner()->table('x_read_attempts')->count());
        } finally {
            $this->travelBack();
        }
    }

    public function test_x_web_campaign_picker_keeps_cursor_server_side_and_denies_rotated_configuration(): void
    {
        [$scope] = $this->xStatsFixture();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push(['request' => ['params' => ['account_id' => 'abc123']], 'data' => [['id' => 'campaign1', 'name' => 'Synthetic campaign']], 'next_cursor' => 'private-campaign-cursor'])
            ->push(['request' => ['params' => ['account_id' => 'abc123', 'cursor' => 'private-campaign-cursor']], 'data' => [['id' => 'campaign2', 'name' => 'Another campaign']], 'next_cursor' => null])]);
        $this->configureXWeb($http);
        $base = '/workspaces/'.$this->workspace.'/providers/x/'.$scope->sourceId.'/campaigns';
        $page = $this->getJson($base)->assertOk()->assertJsonPath('campaigns.0.id', 'campaign1');
        $this->assertStringNotContainsString('private-campaign-cursor', $page->getContent());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $page->json('continuation'));
        config()->set('services.x_ads.consumer_secret', 'synthetic-rotated');
        $this->getJson($base.'?continuation='.$page->json('continuation'))->assertForbidden();
        $http->assertSentCount(1);
        config()->set('services.x_ads.consumer_secret', 'synthetic-app-secret');
        $this->getJson($base.'?continuation='.$page->json('continuation'))->assertOk()->assertJsonPath('campaigns.0.id', 'campaign2')->assertJsonPath('continuation', null);
        $http->assertSentCount(2);
    }

    public function test_x_async_creation_fence_retains_ticket_after_sql_rollback_and_blocks_uncertain_resubmission(): void
    {
        [$scope] = $this->xStatsFixture();
        $report = new XCampaignReport($scope, new ReportWindow('2026-09-01', '2026-09-30', 'INCLUSIVE_DATES', 'TOTAL', 'Asia/Amman', 'synthetic:native'), ['campaign1'], 'ALL_ON_TWITTER', true, null, 'NONE');
        $row = $report->query();
        $row['entity_ids'] = ['campaign1'];
        $row['metric_groups'] = ['BILLING', 'ENGAGEMENT'];
        $row += ['id_str' => '1120829647711653888', 'account_id' => 'abc123', 'status' => 'PROCESSING', 'url' => null];
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push(['data' => $row])->push(['data' => [$row]])]);
        $reader = new XAsyncReport($http, new XOAuthSigner('synthetic-key', 'synthetic-secret'), static fn () => null);
        $credentials = new ProviderCredentials(['oauth_token' => 'synthetic-token', 'oauth_token_secret' => 'synthetic-secret', 'account_id' => 'abc123', 'account_timezone' => 'Asia/Amman', 'timezone_switch_at' => 'NONE']);
        $binding = [$report->binding(), 'synthetic-root', 1];
        $deadline = new DateTimeImmutable('+5 minutes');
        $fence = new XAsyncAcquisition(Cache::store());
        $advance = fn ($ticket, $created) => $reader->advance($report, $credentials,
            new ProviderReadBudget($scope, 'async-acquire', 3, 0, 3, 40, new DateTimeImmutable('+45 seconds'), 1, 5), $ticket, $created);
        try {
            $this->withinWorkspace(function () use ($fence, $binding, $deadline, $advance): void {
                $fence->run($binding, $deadline, $advance);
                throw new \RuntimeException('synthetic-rollback-after-native-create');
            });
        } catch (\RuntimeException $failure) {
            $this->assertSame('synthetic-rollback-after-native-create', $failure->getMessage());
        }
        $this->withinWorkspace(fn () => $fence->run($binding, $deadline, $advance));
        $http->assertSentCount(2);
        $this->assertSame('POST', $http->recorded()[0][0]->method());
        $this->assertSame('GET', $http->recorded()[1][0]->method());
        $this->assertSame(0, $this->owner()->table('provider_observation_runs')->count());
        $calls = 0;
        $uncertain = function () use (&$calls): array {
            $calls++;
            throw new XRequestFailed('x_transport_failed');
        };
        try {
            $fence->run(['synthetic-uncertain'], $deadline, $uncertain);
        } catch (XRequestFailed $failure) {
            $this->assertSame('x_transport_failed', $failure->reason);
        }
        try {
            $fence->run(['synthetic-uncertain'], $deadline, $uncertain);
            $this->fail('Uncertain creation must not be resubmitted.');
        } catch (XRequestFailed $failure) {
            $this->assertSame('x_async_creation_uncertain', $failure->reason);
        }
        $this->assertSame(1, $calls);
        try {
            $fence->run(['synthetic-throttle'], $deadline, static fn () => throw new XRequestFailed('x_rate_limited', 30));
        } catch (XRequestFailed $failure) {
            $this->assertSame('x_rate_limited', $failure->reason);
        }
        $this->assertSame(['retried' => true], $fence->run(['synthetic-throttle'], $deadline, static fn () => ['retried' => true]));
    }

    public function test_common_evidence_equivalence_and_report_projection_across_all_five_native_statuses(): void
    {
        $at = new DateTimeImmutable;
        $fresh = ['state' => 'VERIFIED', 'provenance' => 'synthetic:common-proof',
            'verified_at' => $at->modify('-1 minute')->format(DATE_ATOM), 'valid_until' => $at->modify('+1 hour')->format(DATE_ATOM)];
        $cases = [
            'missing' => [null, 'UNKNOWN'],
            'malformed' => [['state' => 'VERIFIED'], 'UNKNOWN'],
            'malformed_timestamp_type' => [[...$fresh, 'verified_at' => ['unexpected']], 'UNKNOWN'],
            'malformed_state_type' => [[...$fresh, 'state' => ['unexpected']], 'UNKNOWN'],
            'invalid_date' => [[...$fresh, 'verified_at' => 'unparseable timestamp'], 'UNKNOWN'],
            'future_observation' => [[...$fresh, 'verified_at' => $at->modify('+1 minute')->format(DATE_ATOM)], 'UNKNOWN'],
            'invalid_order' => [[...$fresh, 'valid_until' => $fresh['verified_at']], 'UNKNOWN'],
            'expired' => [[...$fresh, 'verified_at' => $at->modify('-2 hours')->format(DATE_ATOM), 'valid_until' => $at->modify('-1 hour')->format(DATE_ATOM)], 'STALE'],
            'stale_state' => [[...$fresh, 'state' => 'STALE'], 'UNKNOWN'],
            'fresh' => [$fresh, 'VERIFIED'],
            'denied' => [[...$fresh, 'state' => 'FAILED'], 'FAILED'],
            'revoked' => [[...$fresh, 'state' => 'REVOKED'], 'REVOKED'],
            'nonaccepted' => [[...$fresh, 'state' => 'DENIED'], 'UNKNOWN'],
        ];
        foreach ($this->scopes as $scope) {
            $this->store($scope, (string) Str::uuid());
            $status = match ($scope->provider) {
                Provider::Google => new GoogleAccessStatus,
                Provider::Meta => new MetaAccessStatus,
                Provider::TikTok => new TikTokAccessStatus,
                Provider::Snapchat => new SnapchatAccessStatus,
                Provider::X => new XAccessStatus,
            };
            foreach ($cases as $case => [$fact, $expected]) {
                $facts = array_fill_keys(EffectiveCapability::AXES, $fact);
                $this->owner()->table('provider_access')->where('source_id', $scope->sourceId)->update(['verification' => json_encode($facts, JSON_THROW_ON_ERROR)]);
                $snapshot = $this->withinWorkspace(fn ($r) => $status->snapshot($r, $scope));
                foreach (EffectiveCapability::AXES as $axis) {
                    $this->assertSame($expected, $snapshot['axes'][$axis], $scope->provider->value.':'.$case.':'.$axis);
                }
                $this->assertSame($case === 'fresh', $snapshot['reporting']['can_report']);
                $this->assertSame($expected === 'VERIFIED' ? 'READY' : $expected, $snapshot['effective']['state']);
            }
            $facts = array_fill_keys(EffectiveCapability::AXES, $fresh);
            $this->owner()->table('provider_access')->where('source_id', $scope->sourceId)->update(['verification' => json_encode($facts, JSON_THROW_ON_ERROR)]);
            $grants = $this->owner()->table('security_action_grants')->where('source_target_id', $scope->sourceId)->where('action', 'provider.read');
            $this->assertSame(1, $grants->update(['status' => 'revoked', 'revoked_at' => now()]));
            $snapshot = $this->withinWorkspace(fn ($r) => $status->snapshot($r, $scope));
            $this->assertTrue($snapshot['reporting']['can_report']);
            $this->denied(fn () => $this->withinWorkspace(fn ($r) => app(ProviderRequestGuard::class)->requireCurrent($r, $scope, 'provider.read', 1)), '42501');
            $this->withinWorkspace(fn ($r) => app(ProviderAccessStore::class)->revoke($r, $scope, (string) Str::uuid(), 1));
            $snapshot = $this->withinWorkspace(fn ($r) => $status->snapshot($r, $scope));
            $this->assertSame('REVOKED', $snapshot['axes']['credential']);
            $this->assertFalse($snapshot['reporting']['can_report']);
            $this->assertSame('local_access_revoked', $snapshot['effective']['reason']);
        }
    }

    private function withinWorkspace(callable $callback): mixed
    {
        $request = Request::create('/workspaces/'.$this->workspace);
        $request->setUserResolver(fn () => $this->actor);
        $request->setLaravelSession($this->app['session']->driver());

        return app(WorkspaceContext::class)->run($this->actor, $this->workspace, fn () => $callback($request), $request);
    }

    private function grant(ProviderScope $scope, string $action): void
    {
        $this->owner()->table('security_action_grants')->insert(['subject_user_id' => $this->actor->id, 'granted_by' => $this->actor->id,
            'source' => 'source_owner_request', 'scope_kind' => 'source', 'workspace_id' => $scope->workspaceId, 'source_target_id' => $scope->sourceId,
            'source_resource' => $scope->accountId, 'source_operation' => $scope->operation, 'source_purpose' => $scope->purpose,
            'action' => $action, 'subject_generation' => 1, 'status' => 'active', 'reason' => 'synthetic_fixture', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function owner()
    {
        return DB::connection('pgsql_migrations');
    }

    private function denied(callable $operation, string $state): void
    {
        try {
            $operation();
            $this->fail('Forbidden provider operation accepted.');
        } catch (QueryException $error) {
            $this->assertSame($state, $error->getCode());
        }
    }
}
