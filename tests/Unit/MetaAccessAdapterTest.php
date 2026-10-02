<?php

namespace Tests\Unit;

use App\Providers\Ads\Meta\MetaAccessAdapter;
use App\Providers\Ads\Meta\MetaRequestFailed;
use App\Providers\Ads\Meta\MetaTokenKind;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderScope;
use App\Sources\EffectiveCapability;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MetaAccessAdapterTest extends TestCase
{
    private function scope(): ProviderScope
    {
        return new ProviderScope(1, '019a0022-0000-7000-8000-000000000001', Provider::Meta,
            '123', 'report.read', 'advertising_analysis');
    }

    private function adapter(Factory $http): MetaAccessAdapter
    {
        return new MetaAccessAdapter($http, 'v26.0', '777', 'synthetic-app-secret', 'https://builderad.example/callback', '999', static fn () => null, MetaTokenKind::User);
    }

    private function debug(): array
    {
        return ['data' => ['is_valid' => true, 'type' => 'USER', 'app_id' => '777', 'user_id' => '888',
            'scopes' => ['ads_read'], 'expires_at' => time() + 600, 'data_access_expires_at' => time() + 300]];
    }

    public function test_one_button_url_uses_only_reporting_scope_code_and_bound_state(): void
    {
        $url = $this->adapter(new Factory)->authorizationUrl(str_repeat('a', 43));
        parse_str(parse_url($url, PHP_URL_QUERY), $parameters);
        $this->assertStringStartsWith('https://www.facebook.com/v26.0/dialog/oauth?', $url);
        $this->assertSame('999', $parameters['config_id']);
        $this->assertArrayNotHasKey('scope', $parameters);
        $this->assertSame('code', $parameters['response_type']);
        $this->assertSame(str_repeat('a', 43), $parameters['state']);
        $this->assertStringNotContainsString('synthetic-app-secret', $url);
    }

    public function test_server_code_exchange_replaces_short_token_and_inspects_exact_app_and_native_expiry(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push(['access_token' => 'synthetic-short', 'expires_in' => 3600])
            ->push(['access_token' => 'synthetic-long', 'expires_in' => 123456])->push($this->debug())]);
        $material = $this->adapter($http)->acquire($this->scope(), new ProviderCredentials(['code' => 'synthetic-code']), '{}');
        $this->assertSame('synthetic-long', $material->credentials->value('access_token'));
        $this->assertSame('888', $material->credentials->value('user_id'));
        $this->assertSame('CONNECTED', $material->nativeState);
        $this->assertLessThanOrEqual(time() + 300, $material->expiresAt->getTimestamp());
        $this->assertSame('VERIFIED', $material->verification['credential']['state']);
        $this->assertSame('UNKNOWN', $material->verification['provider_support']['state']);
        $this->assertSame('UNKNOWN', (new EffectiveCapability)->derive('UNVERIFIED', true,
            $material->verification, new DateTimeImmutable)['state']);
        $http->assertSent(fn ($request) => ($request->data()['redirect_uri'] ?? null) === 'https://builderad.example/callback', static fn () => null);
        $http->assertSent(fn ($request) => ($request->data()['grant_type'] ?? null) === 'fb_exchange_token');
        $http->assertSent(fn ($request) => ($request->data()['input_token'] ?? null) === 'synthetic-long');
        $this->assertStringNotContainsString('synthetic-long', print_r($material, true));
    }

    public static function invalidInspection(): array
    {
        return [['app_id', '999'], ['user_id', 'invalid'], ['type', 'PAGE'], ['is_valid', false],
            ['scopes', ['ads_management']], ['expires_at', 1], ['data_access_expires_at', 1]];
    }

    #[DataProvider('invalidInspection')]
    public function test_token_or_permission_mismatch_cannot_be_connected(string $field, mixed $value): void
    {
        $debug = $this->debug();
        $debug['data'][$field] = $value;
        $http = new Factory;
        $http->fake(['*' => $http->response($debug)]);
        $this->expectException(MetaRequestFailed::class);
        $this->adapter($http)->verifySelection($this->scope(), new ProviderCredentials(['access_token' => 'synthetic-token']), '123');
    }

    public function test_selected_account_rechecks_token_account_identity_native_timezone_currency_and_reporting_read(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push($this->debug())->push(['id' => 'act_123', 'account_id' => '123',
            'currency' => 'USD', 'timezone_name' => 'America/New_York'])->push(['data' => []])]);
        $material = $this->adapter($http)->verifySelection($this->scope(),
            new ProviderCredentials(['access_token' => 'synthetic-token', 'account_currency' => 'TRY']), '123');
        $this->assertSame('USD', $material->credentials->value('account_currency'));
        $this->assertSame('America/New_York', $material->credentials->value('account_timezone'));
        $this->assertSame('123', $material->credentials->value('account_id'));
        $this->assertSame('UNKNOWN', $material->verification['completeness']['state']);
        $http->assertSentCount(3);
    }

    public function test_discovery_keeps_native_partial_cursor_without_auto_selecting_or_inventing_role(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push($this->debug())->push(['data' => [['account_id' => '123', 'name' => 'One']],
            'paging' => ['next' => 'https://untrusted.invalid', 'cursors' => ['after' => 'opaque']]])
            ->push($this->debug())->push(['data' => [['account_id' => '124', 'name' => 'Two']]])]);
        $adapter = $this->adapter($http);
        $credentials = new ProviderCredentials(['access_token' => 'synthetic-token']);
        $first = $adapter->discover($this->scope(), $credentials, '{}');
        $this->assertSame('PARTIAL', $first->coverage);
        $this->assertNull($first->accounts[0]['native_role']);
        $second = $adapter->discover($this->scope(), $credentials, $first->continuation->envelope()['payload']);
        $this->assertSame('COMPLETE', $second->coverage);
        $this->assertSame('124', $second->accounts[0]['id']);
        $this->assertSame('123', $second->scope->accountId);
        $http->assertSent(fn ($request) => ($request->data()['after'] ?? null) === 'opaque'
            && str_starts_with($request->url(), 'https://graph.facebook.com/v26.0/888/adaccounts?'));
    }

    public function test_selection_cannot_silently_change_source_identity(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $this->expectException(InvalidArgumentException::class);
        $this->adapter($http)->verifySelection($this->scope(), new ProviderCredentials(['access_token' => 'synthetic-token']), '124');
    }

    public function test_renewal_requires_user_reconnect_without_inventing_refresh_token_or_remote_mutation(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $this->expectException(MetaRequestFailed::class);
        $this->expectExceptionMessage('meta_reconnect_required');
        $this->adapter($http)->renew($this->scope(), new ProviderCredentials(['access_token' => 'synthetic-token']), '{}');
    }

    public function test_preferred_business_configuration_exchanges_once_and_verifies_client_business_without_user_ttl(): void
    {
        $http = new Factory;
        $debug = $this->debug();
        $debug['data']['type'] = 'synthetic-system-native-type';
        $debug['data']['expires_at'] = 0;
        unset($debug['data']['data_access_expires_at']);
        $http->fake(['*' => $http->sequence()->push(['access_token' => 'synthetic-business-token'])
            ->push($debug)->push(['id' => '888', 'client_business_id' => '555'])
            ->push($debug)->push(['id' => '888', 'client_business_id' => '555'])]);
        $adapter = new MetaAccessAdapter($http, 'v26.0', '777', 'synthetic-app-secret',
            'https://builderad.example/callback', '1000', static fn () => null);
        $material = $adapter->acquire($this->scope(), new ProviderCredentials(['code' => 'synthetic-code']), '{}');
        $this->assertNull($material->expiresAt);
        $this->assertSame('555', $material->credentials->value('client_business_id'));
        $this->assertSame('UNKNOWN', $material->verification['provider_support']['state']);
        $refreshed = $adapter->renew($this->scope(), $material->credentials, '{}');
        $this->assertSame('synthetic-business-token', $refreshed->credentials->value('access_token'));
        $http->assertSentCount(5);
        $http->assertNotSent(fn ($request) => ($request->data()['grant_type'] ?? null) === 'fb_exchange_token');
    }

    public function test_business_token_requires_verified_client_business_identity_not_just_generic_valid_token(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push(['access_token' => 'synthetic-token'])
            ->push($this->debug())->push(['id' => '888'])]);
        $adapter = new MetaAccessAdapter($http, 'v26.0', '777', 'synthetic-app-secret',
            'https://builderad.example/callback', '1000', static fn () => null);
        $this->expectException(MetaRequestFailed::class);
        $this->expectExceptionMessage('meta_client_business_identity_unverified');
        $adapter->acquire($this->scope(), new ProviderCredentials(['code' => 'synthetic-code']), '{}');
    }

    public function test_business_discovery_uses_current_granted_targets_and_rejects_asset_changes_between_pages(): void
    {
        $http = new Factory;
        $debug = $this->debug();
        $debug['data']['granular_scopes'] = [['scope' => 'ads_read', 'target_ids' => ['101', '102', '103', '104', '105', '106']]];
        $sequence = $http->sequence()->push($debug)->push(['id' => '888', 'client_business_id' => '555']);
        foreach (['101', '102', '103', '104', '105'] as $id) {
            $sequence->push(['id' => 'act_'.$id, 'account_id' => $id, 'name' => 'Synthetic '.$id]);
        }
        $changed = $debug;
        $changed['data']['granular_scopes'][0]['target_ids'] = ['101', '102', '103', '104', '105'];
        $sequence->push($changed)->push(['id' => '888', 'client_business_id' => '555']);
        $http->fake(['*' => $sequence]);
        $adapter = new MetaAccessAdapter($http, 'v26.0', '777', 'synthetic-app-secret',
            'https://builderad.example/callback', '1000', static fn () => null);
        $credentials = new ProviderCredentials(['access_token' => 'synthetic-business-token']);
        $first = $adapter->discover($this->scope(), $credentials, '{}');
        $this->assertSame('PARTIAL', $first->coverage);
        $this->assertCount(5, $first->accounts);
        $this->assertSame('meta.granted_asset_enumeration', $first->continuation->kind);
        $this->assertNull($first->accounts[0]['native_role']);
        $http->assertNotSent(fn ($request) => str_contains($request->url(), '/888/adaccounts'));
        $this->expectException(InvalidArgumentException::class);
        $adapter->discover($this->scope(), $credentials, $first->continuation->envelope()['payload']);
    }

    public function test_business_discovery_does_not_infer_all_assets_when_granular_targets_are_absent(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push($this->debug())->push(['id' => '888', 'client_business_id' => '555'])]);
        $adapter = new MetaAccessAdapter($http, 'v26.0', '777', 'synthetic-app-secret',
            'https://builderad.example/callback', '1000', static fn () => null);
        $result = $adapter->discover($this->scope(), new ProviderCredentials(['access_token' => 'synthetic-token']), '{}');
        $this->assertSame('UNKNOWN', $result->coverage);
        $this->assertSame([], $result->accounts);
        $http->assertSentCount(2);
    }

    public function test_business_discovery_verifies_targets_as_accounts_before_returning_complete_catalog(): void
    {
        $http = new Factory;
        $debug = $this->debug();
        $debug['data']['granular_scopes'] = [['scope' => 'ads_read', 'target_ids' => ['123']]];
        $http->fake(['*' => $http->sequence()->push($debug)->push(['id' => '888', 'client_business_id' => '555'])
            ->push(['id' => 'act_123', 'account_id' => '123', 'name' => 'Synthetic account'])]);
        $adapter = new MetaAccessAdapter($http, 'v26.0', '777', 'synthetic-app-secret',
            'https://builderad.example/callback', '1000', static fn () => null);
        $result = $adapter->discover($this->scope(), new ProviderCredentials(['access_token' => 'synthetic-token']), '{}');
        $this->assertSame('COMPLETE', $result->coverage);
        $this->assertSame('123', $result->accounts[0]['id']);
        $this->assertNull($result->continuation);
        $http->assertSentCount(3);
    }
}
