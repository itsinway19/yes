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
use Tests\Support\BusinessAuthorityFixture;
use Tests\TestCase;

/** Real creator/Source commands. No synthetic Source root is inserted. */
final class BusinessNativeSourceTest extends TestCase
{
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
                    'realm_kind' => 'direct', 'realm_workspace_public_id' => null,
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
        self::assertSame(0, $owner->table('workspaces')->count());
        self::assertSame(0, $owner->table('workspace_memberships')->count());
        self::assertSame(0, $owner->table('business_data_use_bindings')->count());
        self::assertSame(0, $owner->table('data_use_scopes')->count());
        self::assertSame(1, $owner->table('source_registry')->count());
        self::assertNull($owner->table('source_registry')->value('workspace_id'));
        self::assertSame($owner->table('businesses')->value('id'), $owner->table('source_registry')->value('business_id'));
        foreach (['source_capabilities', 'source_command_receipts', 'source_versions', 'source_audit_obligations', 'source_audit_delivery'] as $table) {
            self::assertSame(1, $owner->table($table)->count(), $table);
            self::assertNull($owner->table($table)->value('workspace_id'), $table);
        }
        self::assertSame('BB1-BUSINESS-V1', json_decode($owner->table('source_audit_obligations')->value('envelope'), true)['policy']);
        $this->signed($creator, function (Request $request) use ($source): void {
            DB::selectOne('SELECT builderad_source_read_scope(NULL,?,?::uuid,?,?,?)',
                [SessionAuthority::sessionKey($request), $source, '123', 'report.read', 'advertising_analysis']);
            self::assertSame(1, DB::table('source_registry')->count());
            self::assertSame(1, DB::table('source_versions')->count());
            self::assertSame(1, DB::table('source_audit_obligations')->count());
        });
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
        $this->signed($employee, function (Request $request): void {
            DB::selectOne('SELECT builderad_source_begin_read(NULL,?)', [SessionAuthority::sessionKey($request)]);
            foreach (['source_registry', 'source_capabilities', 'source_command_receipts', 'source_versions', 'source_audit_obligations', 'source_audit_delivery'] as $table) {
                self::assertSame(0, DB::table($table)->count(), $table.' private Source history must remain hidden');
            }
        });
        self::assertSame(1, DB::connection('pgsql_migrations')->table('source_registry')->count());
        self::assertSame(0, DB::connection('pgsql_migrations')->table('workspaces')->count());
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
        $this->denied(fn () => $this->signed($creator, fn (Request $request) => DB::selectOne(
            'SELECT builderad_source_read_scope(NULL,?,?::uuid,?,?,?)', [SessionAuthority::sessionKey($request), $source, '123', 'report.read', 'advertising_analysis'])));
        self::assertSame(1, $owner->table('source_versions')->count());
        self::assertSame(1, $owner->table('source_audit_obligations')->count());
        self::assertSame(0, $owner->table('workspaces')->count());
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
        $selection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
            'requirements' => [['requirement_kind' => 'native_source', 'leaf_grant_id' => (int) $manager]]];
        $this->getJson('/api/source-rights')->assertOk()->assertJsonPath('data.sources.0.source_id', $source);
        $this->getJson('/api/source-rights/'.$source.'?access='.$manager)->assertOk()->assertJsonPath('data.source.adopted', true)->assertJsonCount(7, 'data.rights');
        $this->getJson('/api/source-rights/'.$source.'?access='.$business['grant_refs']['business.context.read'])->assertForbidden();
        $command = fn (string $effect, array $payload, int $version) => ['command_id' => (string) Str::uuid(),
            'target_kind' => 'source', 'target_ref' => $source, 'effect' => $effect, 'payload' => $payload,
            'expected_revision' => $version, 'authority_selection' => $selection];
        $rename = $command('rename', ['label' => 'Renamed direct source', 'reason' => 'explicit_source_rename'], 1);
        $this->postJson('/api/data-rights/commands', $rename)->assertOk()->assertJsonPath('data.versions.source', 2);
        $this->postJson('/api/data-rights/commands', $rename)->assertOk()->assertJsonPath('data.versions.source', 2);
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
        $this->getJson('/api/source-rights/'.$source.'?access='.$manager)->assertOk()->assertJsonPath('data.source.status', 'REVOKED');
        self::assertSame(3, $owner->table('source_versions')->count());
        self::assertSame(0, $owner->table('workspaces')->count());
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
        self::assertSame(0, DB::connection('pgsql_migrations')->table('workspaces')->count());
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
        self::assertSame(0, DB::connection('pgsql_migrations')->table('workspaces')->count());
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
        self::assertSame(0, $owner->table('workspaces')->count());
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
        self::assertSame(0, $owner->table('workspaces')->count());
        self::assertSame(0, $owner->table('business_data_use_bindings')->count());
    }
}
