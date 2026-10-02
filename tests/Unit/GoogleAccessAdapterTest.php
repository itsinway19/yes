<?php

namespace Tests\Unit;

use App\Providers\Ads\Google\GoogleAccessAdapter;
use App\Providers\Ads\Google\GoogleOAuthClient;
use App\Providers\Ads\Google\GoogleRequestFailed;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GoogleAccessAdapterTest extends TestCase
{
    private function scope(): ProviderScope
    {
        return new ProviderScope(1, '019a0022-0000-7000-8000-000000000001', Provider::Google,
            '1234567890', 'report.read', 'advertising_analysis');
    }

    private function adapter(Factory $http, ?\Closure $guard = null): GoogleAccessAdapter
    {
        return new GoogleAccessAdapter($http, 'v25', 'synthetic.apps.googleusercontent.com', 'synthetic-client-secret',
            '', 'https://builderad.example/providers/google/callback', $guard ?? static fn () => null);
    }

    private function credentials(array $replace = []): ProviderCredentials
    {
        return new ProviderCredentials(array_replace(['access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh',
            'oauth_client_id' => 'synthetic.apps.googleusercontent.com', 'granted_scope' => GoogleOAuthClient::SCOPE,
            'expires_at' => (string) (time() + 600)], $replace));
    }

    private function token(array $replace = []): array
    {
        return array_replace(['access_token' => 'synthetic-new-access', 'refresh_token' => 'synthetic-new-refresh',
            'scope' => GoogleOAuthClient::SCOPE, 'token_type' => 'Bearer', 'expires_in' => 234], $replace);
    }

    private function metadata(string $id = '1234567890', bool $manager = false): array
    {
        return ['fieldMask' => 'customer.id,customer.manager,customer.currencyCode,customer.timeZone',
            'results' => [['customer' => ['id' => $id, 'manager' => $manager, 'currencyCode' => 'JOD', 'timeZone' => 'Asia/Amman']]]];
    }

    public function test_authorization_requests_only_ads_offline_access_without_repeated_permission_prompts_or_secret(): void
    {
        $adapter = $this->adapter(new Factory);
        $url = $adapter->authorizationUrl(str_repeat('a', 43));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(GoogleOAuthClient::SCOPE, $query['scope']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('https://accounts.google.com/o/oauth2/v2/auth', strtok($url, '?'));
        $this->assertSame('https://builderad.example/providers/google/callback', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame(str_repeat('a', 43), $query['state']);
        $this->assertArrayNotHasKey('prompt', $query);
        $this->assertStringNotContainsString('synthetic-client-secret', $url);
        $this->assertStringNotContainsString('synthetic-client-secret', print_r($adapter, true));
        parse_str(parse_url($adapter->authorizationUrl(str_repeat('b', 43), true), PHP_URL_QUERY), $retry);
        $this->assertSame('consent', $retry['prompt']);
    }

    public function test_exchange_uses_actual_expiry_and_never_promotes_account_or_policy_readiness(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response($this->token())]);
        $start = time();
        $material = $this->adapter($http)->acquire($this->scope(), new ProviderCredentials(['code' => 'synthetic-code']), '{}');
        $this->assertSame('TOKEN_ACQUIRED', $material->nativeState);
        $this->assertGreaterThanOrEqual($start + 234, $material->expiresAt->getTimestamp());
        $this->assertLessThanOrEqual(time() + 234, $material->expiresAt->getTimestamp());
        $this->assertSame('VERIFIED', $material->verification['credential']['state']);
        foreach (['provider_permission', 'provider_support', 'privacy_purpose', 'completeness', 'product_exposure'] as $axis) {
            $this->assertSame('UNKNOWN', $material->verification[$axis]['state']);
        }
        $http->assertSent(fn ($r) => $r->url() === 'https://oauth2.googleapis.com/token' && $r->method() === 'POST'
            && $r['code'] === 'synthetic-code' && $r['redirect_uri'] === 'https://builderad.example/providers/google/callback'
            && ! $r->hasHeader('developer-token'));
        $this->assertStringNotContainsString('synthetic-new-access', print_r($material, true));
    }

    public function test_refresh_retains_omitted_refresh_token_but_replaces_it_when_native_response_rotates_it(): void
    {
        $http = new Factory;
        $first = $this->token();
        unset($first['refresh_token'], $first['scope']);
        $http->fake(['*' => $http->sequence()->push($first)->push($this->token())]);
        $adapter = $this->adapter($http);
        $one = $adapter->renew($this->scope(), $this->credentials(), '{}');
        $this->assertSame('synthetic-refresh', $one->credentials->value('refresh_token'));
        $two = $adapter->renew($this->scope(), $one->credentials, '{}');
        $this->assertSame('synthetic-new-refresh', $two->credentials->value('refresh_token'));
        $this->assertSame('UNKNOWN', $two->verification['provider_permission']['state']);
        $http->assertSentCount(2);
    }

    public static function malformedTokens(): array
    {
        return [['scope', 'https://www.googleapis.com/auth/drive'], ['expires_in', 0], ['expires_in', '3600'],
            ['token_type', 'MAC'], ['refresh_token', ''], ['access_token', ''], ['refresh_token_expires_in', -1]];
    }

    #[DataProvider('malformedTokens')]
    public function test_malformed_native_token_response_cannot_create_usable_access(string $field, mixed $value): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->response($this->token([$field => $value]))]);
        $this->expectException(GoogleRequestFailed::class);
        $this->adapter($http)->acquire($this->scope(), new ProviderCredentials(['code' => 'synthetic-code']), '{}');
    }

    public function test_invalid_grant_requires_reconnect_without_leaking_native_error_message(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->response(['error' => 'invalid_grant', 'error_description' => 'synthetic-private-code'], 400)]);
        $this->expectException(GoogleRequestFailed::class);
        $this->expectExceptionMessage('google_reconnect_required');
        $this->adapter($http)->renew($this->scope(), $this->credentials(), '{}');
    }

    public function test_direct_selection_reverifies_exact_account_currency_timezone_and_actual_campaign_read(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push($this->metadata())->push(['fieldMask' => 'campaign.id'])]);
        $material = $this->adapter($http)->verifySelection($this->scope(), $this->credentials(), '{"account_id":"1234567890"}');
        $this->assertSame('CONNECTED', $material->nativeState);
        $this->assertSame('Asia/Amman', $material->credentials->value('account_timezone'));
        $this->assertSame('JOD', $material->credentials->value('account_currency'));
        $this->assertSame('1234567890', $material->credentials->value('account_id'));
        $this->assertSame('VERIFIED', $material->verification['provider_permission']['state']);
        $this->assertSame('UNKNOWN', $material->verification['privacy_purpose']['state']);
        $http->assertSentCount(2);
        $http->assertNotSent(fn ($r) => $r->hasHeader('login-customer-id'));
    }

    public function test_manager_selection_checks_direct_root_then_routes_target_through_that_exact_manager(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push(['resourceNames' => ['customers/9876543210']])
            ->push($this->metadata('9876543210', true))->push($this->metadata())->push(['fieldMask' => 'campaign.id'])]);
        $material = $this->adapter($http)->verifySelection($this->scope(), $this->credentials(),
            '{"account_id":"1234567890","login_customer_id":"9876543210"}');
        $this->assertSame('9876543210', $material->credentials->value('login_customer_id'));
        $http->assertSentCount(4);
        $http->assertSent(fn ($r) => str_contains($r->url(), '/1234567890/') && $r->hasHeader('login-customer-id', '9876543210'));
        $http->assertNotSent(fn ($r) => str_contains($r->url(), ':listAccessibleCustomers') && $r->hasHeader('login-customer-id'));
    }

    public function test_selection_cannot_change_declared_source_or_use_an_unverified_manager(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $this->expectException(InvalidArgumentException::class);
        $this->adapter($http)->verifySelection($this->scope(), $this->credentials(), '{"account_id":"2234567890"}');
    }

    public function test_discovery_is_direct_access_only_and_rechecks_current_list_between_bounded_pages(): void
    {
        $ids = array_map(static fn ($i) => 'customers/'.(string) (1234567800 + $i), range(0, 100));
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push(['resourceNames' => $ids])->push(['resourceNames' => $ids])]);
        $adapter = $this->adapter($http);
        $credentials = $this->credentials();
        $one = $adapter->discover($this->scope(), $credentials, '{}');
        $this->assertSame('PARTIAL', $one->coverage);
        $this->assertCount(100, $one->accounts);
        $this->assertNull($one->accounts[0]['native_role']);
        $two = $adapter->discover($this->scope(), $credentials, $one->continuation->envelope()['payload']);
        $this->assertSame('COMPLETE', $two->coverage);
        $this->assertCount(1, $two->accounts);
        $http->assertSentCount(2);
        $http->assertNotSent(fn ($r) => str_contains($r->url(), 'googleAds:search'));
        $http->assertNotSent(fn ($r) => $r->hasHeader('developer-token') || $r->hasHeader('login-customer-id'));
        $http->assertSent(fn ($r) => $r->url() === 'https://googleads.googleapis.com/v25/customers:listAccessibleCustomers'
            && $r->hasHeader('Authorization', 'Bearer synthetic-access'));
    }

    public function test_hierarchy_retains_root_and_submanager_context_instead_of_inventing_native_account_roles(): void
    {
        $mask = 'customerClient.id,customerClient.clientCustomer,customerClient.descriptiveName,customerClient.level,customerClient.manager';
        $row = ['customerClient' => ['id' => '1234567890', 'clientCustomer' => 'customers/1234567890',
            'descriptiveName' => 'Synthetic advertiser', 'level' => '1', 'manager' => false]];
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push(['resourceNames' => ['customers/9876543210']])
            ->push($this->metadata('9876543210', true))->push(['fieldMask' => $mask, 'results' => [$row], 'nextPageToken' => 'opaque-next'])]);
        $page = $this->adapter($http)->hierarchy($this->scope(), $this->credentials(), '9876543210', '8876543210');
        $this->assertSame('9876543210', $page->loginCustomerId);
        $this->assertSame('8876543210', $page->queriedManagerId);
        $this->assertFalse($page->customers[0]['manager']);
        $this->assertSame('google.manager_page', $page->continuation->kind);
        $http->assertSent(fn ($r) => str_contains($r->url(), '/8876543210/') && $r->hasHeader('login-customer-id', '9876543210'));
        $this->expectException(InvalidArgumentException::class);
        $this->adapter($http)->hierarchy($this->scope(), $this->credentials(['access_token' => 'changed']),
            '9876543210', '8876543210', $page->continuation);
    }

    public function test_current_authority_is_checked_again_before_selection_probe_and_denial_stops_http(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->response($this->metadata())]);
        $calls = 0;
        try {
            $this->adapter($http, static function () use (&$calls): void {
                if (++$calls === 2) {
                    throw new AuthorizationException('synthetic-current-authority-denied');
                }
            })->verifySelection($this->scope(), $this->credentials(), '{"account_id":"1234567890"}');
            $this->fail('Lost current authority must stop the next request.');
        } catch (AuthorizationException) {
            $http->assertSentCount(1);
            $this->assertSame(2, $calls);
        }
    }

    public function test_refresh_rejects_a_different_oauth_client_before_token_endpoint(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $this->expectException(GoogleRequestFailed::class);
        $this->expectExceptionMessage('google_reconnect_required');
        $this->adapter($http)->renew($this->scope(), $this->credentials(['oauth_client_id' => 'other.apps.googleusercontent.com']), '{}');
    }

    public function test_direct_discovery_continuation_cannot_survive_changed_native_account_access(): void
    {
        $ids = array_map(static fn ($i) => 'customers/'.(string) (1234567800 + $i), range(0, 100));
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push(['resourceNames' => $ids])->push(['resourceNames' => array_slice($ids, 1)])]);
        $adapter = $this->adapter($http);
        $credentials = $this->credentials();
        $first = $adapter->discover($this->scope(), $credentials, '{}');
        $this->expectException(InvalidArgumentException::class);
        $adapter->discover($this->scope(), $credentials, $first->continuation->envelope()['payload']);
    }

    public function test_unavailable_manager_context_is_not_substituted_with_another_root(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->response(['resourceNames' => ['customers/8876543210']])]);
        $this->expectException(GoogleRequestFailed::class);
        $this->expectExceptionMessage('google_manager_context_unverified');
        $this->adapter($http)->verifySelection($this->scope(), $this->credentials(),
            '{"account_id":"1234567890","login_customer_id":"9876543210"}');
    }

    public function test_manager_account_cannot_be_selected_as_a_campaign_reporting_account(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->response($this->metadata('1234567890', true))]);
        $this->expectException(GoogleRequestFailed::class);
        $this->expectExceptionMessage('google_manager_is_not_report_account');
        $this->adapter($http)->verifySelection($this->scope(), $this->credentials(), '{"account_id":"1234567890"}');
    }

    public function test_missing_refresh_token_requires_a_new_offline_grant_instead_of_fake_continuous_access(): void
    {
        $token = $this->token();
        unset($token['refresh_token']);
        $http = new Factory;
        $http->fake(['*' => $http->response($token)]);
        $this->expectException(GoogleRequestFailed::class);
        $this->expectExceptionMessage('google_offline_access_missing');
        $this->adapter($http)->acquire($this->scope(), new ProviderCredentials(['code' => 'synthetic-code']), '{}');
    }
}
