<?php

namespace Tests\Unit;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\Snapchat\SnapchatAccessAdapter;
use App\Providers\Ads\Snapchat\SnapchatClient;
use App\Providers\Ads\Snapchat\SnapchatOAuthClient;
use App\Providers\Ads\Snapchat\SnapchatRequestFailed;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SnapchatAccessAdapterTest extends TestCase
{
    private const ACCOUNT = '8b8e40af-fc64-455d-925b-ca80f7af6914';

    private function scope(string $account = self::ACCOUNT): ProviderScope
    {
        return new ProviderScope(7, '11111111-1111-7111-8111-111111111111', Provider::Snapchat,
            $account, 'report.read', 'advertising_analysis');
    }

    private function credentials(): ProviderCredentials
    {
        return new ProviderCredentials(['access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh',
            'client_id' => 'synthetic-client', 'expires_at' => (string) (time() + 300)]);
    }

    private function adapter(Factory $http, ?\Closure $guard = null): SnapchatAccessAdapter
    {
        $guard ??= static fn () => null;

        return new SnapchatAccessAdapter(new SnapchatOAuthClient($http, 'synthetic-client', 'synthetic-secret',
            'https://builderad.example/providers/snapchat/callback', $guard), new SnapchatClient($http, $guard, 'provider.connect'));
    }

    private function account(): array
    {
        return ['id' => self::ACCOUNT, 'name' => 'Synthetic advertiser', 'currency' => 'USD',
            'timezone' => 'America/Los_Angeles', 'roles' => ['reports'], 'billing_secret' => 'omit-this'];
    }

    private function metadata(): array
    {
        return ['request_status' => 'SUCCESS', 'adaccounts' => [['sub_request_status' => 'success', 'adaccount' => $this->account()]]];
    }

    private function probe(string $id = self::ACCOUNT): array
    {
        return ['request_status' => 'success', 'total_stats' => [['sub_request_status' => 'SUCCESS',
            'total_stat' => ['id' => $id, 'type' => 'AD_ACCOUNT', 'granularity' => 'TOTAL', 'stats' => ['impressions' => 0]]]]];
    }

    public function test_discovery_minimizes_embedded_accounts_and_preserves_failed_native_coverage(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['request_status' => 'SUCCESS', 'organizations' => [
            ['sub_request_status' => 'SUCCESS', 'organization' => ['id' => 'synthetic-org', 'ad_accounts' => [$this->account()]]],
            ['sub_request_status' => 'ERROR', 'error' => 'private-provider-message'],
        ]])]);
        $discovery = $this->adapter($http)->discover($this->scope(), $this->credentials(), '{}');
        $this->assertSame('PARTIAL', $discovery->coverage);
        $this->assertNull($discovery->continuation);
        $this->assertSame([['id' => self::ACCOUNT, 'label' => 'Synthetic advertiser', 'native_role' => 'reports']], $discovery->accounts);
        $http->assertSent(fn ($r) => $r->method() === 'GET' && $r['with_ad_accounts'] === 'true'
            && str_starts_with($r->url(), 'https://adsapi.snapchat.com/v1/me/organizations?')
            && $r->hasHeader('Authorization', 'Bearer synthetic-access'));
    }

    public function test_selected_account_requires_native_metadata_and_account_report_proof_without_policy_inference(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push($this->metadata())->push($this->probe())]);
        $calls = [];
        $material = $this->adapter($http, static function ($scope, $action) use (&$calls): void {
            $calls[] = [$scope->accountId, $action];
        })->verifySelection($this->scope(), $this->credentials(), json_encode(['account_id' => self::ACCOUNT]));
        $this->assertSame('CONNECTED', $material->nativeState);
        $this->assertSame('America/Los_Angeles', $material->credentials->value('account_timezone'));
        $this->assertSame('VERIFIED', $material->verification['provider_permission']['state']);
        foreach (['provider_support', 'privacy_purpose', 'freshness', 'completeness', 'product_exposure'] as $axis) {
            $this->assertSame('UNKNOWN', $material->verification[$axis]['state']);
        }
        $this->assertSame([[self::ACCOUNT, 'provider.connect'], [self::ACCOUNT, 'provider.connect']], $calls);
        $http->assertSent(function ($r): bool {
            if (! str_contains($r->url(), '/stats?')) {
                return false;
            }
            $start = new DateTimeImmutable($r['start_time']);
            $end = new DateTimeImmutable($r['end_time']);

            return $r['granularity'] === 'TOTAL' && $r['fields'] === 'impressions'
                && $end > $start && $start->format('H:i:s') === '00:00:00'
                && $start->format('P') === (new DateTimeImmutable('yesterday', new \DateTimeZone('America/Los_Angeles')))->format('P');
        });
    }

    public function test_foreign_report_identity_is_never_permission_proof(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push($this->metadata())->push($this->probe('497979f0-ea17-4971-8288-054883f1caca'))]);
        $this->expectException(SnapchatRequestFailed::class);
        $this->adapter($http)->verifySelection($this->scope(), $this->credentials(), json_encode(['account_id' => self::ACCOUNT]));
    }

    public function test_refresh_rotates_tokens_and_rechecks_selected_account_instead_of_inheriting_proof(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push(['access_token' => 'synthetic-new-access', 'refresh_token' => 'synthetic-new-refresh',
            'expires_in' => 120, 'token_type' => 'Bearer'])->push($this->metadata())->push($this->probe())]);
        $values = $this->credentials()->custodyPayload() + ['account_id' => self::ACCOUNT];
        $renewed = $this->adapter($http)->renew($this->scope(), new ProviderCredentials($values), '{}');
        $this->assertSame('synthetic-new-refresh', $renewed->credentials->value('refresh_token'));
        $this->assertSame('CONNECTED', $renewed->nativeState);
        $this->assertEqualsWithDelta(time() + 120, $renewed->expiresAt->getTimestamp(), 2);
        $http->assertSentCount(3);
        $http->assertSent(fn ($r) => $r->method() === 'GET' && $r->hasHeader('Authorization', 'Bearer synthetic-new-access'));
    }

    public function test_transport_rejects_sibling_account_and_arbitrary_endpoints_before_http(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $client = new SnapchatClient($http, static fn () => null, 'provider.read');
        $budget = new ProviderReadBudget($this->scope(), 'test', 2, 0, 2, 5, new DateTimeImmutable('+30 seconds'), 1, 5);
        foreach (['https://evil.example/private', 'adaccounts/497979f0-ea17-4971-8288-054883f1caca/stats', 'me/organizations'] as $path) {
            try {
                $client->get($path, [], $this->credentials(), $budget);
                $this->fail('Unauthorized path must fail.');
            } catch (InvalidArgumentException) {
                $http->assertNothingSent();
            }
        }
    }

    public function test_current_authority_is_checked_before_every_native_get(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $this->expectException(AuthorizationException::class);
        try {
            $this->adapter($http, static fn () => throw new AuthorizationException)->discover($this->scope(), $this->credentials(), '{}');
        } finally {
            $http->assertNothingSent();
        }
    }

    public function test_native_next_url_is_never_followed_or_reported_as_complete(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['request_status' => 'SUCCESS', 'organizations' => [],
            'paging' => ['next_link' => 'https://evil.example/token?secret=private']])]);
        $result = $this->adapter($http)->discover($this->scope(), $this->credentials(), '{}');
        $this->assertSame('PARTIAL', $result->coverage);
        $this->assertNull($result->continuation);
        $http->assertSentCount(1);
    }

    public function test_discovery_continuation_cannot_cross_workspace_or_token_snapshot(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $rows = [];
        for ($i = 1; $i <= 101; $i++) {
            $rows[] = ['id' => sprintf('%08x-fc64-455d-925b-ca80f7af6914', $i), 'name' => 'Synthetic '.$i, 'roles' => ['reports']];
        }
        $body = ['request_status' => 'SUCCESS', 'organizations' => [['sub_request_status' => 'success',
            'organization' => ['ad_accounts' => $rows]]]];
        $http->fake(['*' => $http->response($body)]);
        $adapter = $this->adapter($http);
        $first = $adapter->discover($this->scope(), $this->credentials(), '{}');
        $this->assertCount(100, $first->accounts);
        $this->assertSame('PARTIAL', $first->coverage);
        $next = $first->continuation->envelope()['payload'];
        $last = $adapter->discover($this->scope(), $this->credentials(), $next);
        $this->assertCount(1, $last->accounts);
        $this->assertSame('COMPLETE', $last->coverage);
        $foreign = new ProviderScope(8, $this->scope()->sourceId, Provider::Snapchat, self::ACCOUNT, 'report.read', 'advertising_analysis');
        $this->expectException(InvalidArgumentException::class);
        $adapter->discover($foreign, $this->credentials(), $next);
    }

    public function test_acquisition_uses_native_expiry_but_does_not_claim_connected_or_permission(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh',
            'expires_in' => 73, 'token_type' => 'Bearer'])]);
        $material = $this->adapter($http)->acquire($this->scope(), new ProviderCredentials(['code' => 'synthetic-code']), '{}');
        $this->assertSame('TOKEN_ACQUIRED', $material->nativeState);
        $this->assertEqualsWithDelta(time() + 73, $material->expiresAt->getTimestamp(), 2);
        $this->assertSame('VERIFIED', $material->verification['credential']['state']);
        $this->assertSame('UNKNOWN', $material->verification['provider_permission']['state']);
        $this->assertSame('UNKNOWN', $material->verification['privacy_purpose']['state']);
    }

    public function test_expired_access_and_bound_sibling_selection_fail_before_http(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push($this->metadata())->push($this->probe())]);
        $adapter = $this->adapter($http);
        foreach ([['expires_at' => (string) (time() - 1)], ['account_id' => '497979f0-ea17-4971-8288-054883f1caca']] as $change) {
            try {
                $adapter->verifySelection($this->scope(), new ProviderCredentials(array_replace($this->credentials()->custodyPayload(), $change)),
                    json_encode(['account_id' => self::ACCOUNT]));
                $this->fail('Expired or sibling-bound credentials cannot verify selection.');
            } catch (SnapchatRequestFailed|InvalidArgumentException) {
                $http->assertNothingSent();
            }
        }
    }

    public function test_refresh_failure_does_not_fall_back_to_old_connected_credentials(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push(['access_token' => 'synthetic-new-access', 'refresh_token' => 'synthetic-new-refresh',
            'expires_in' => 120, 'token_type' => 'Bearer'])->push($this->metadata())
            ->push(['request_status' => 'ERROR', 'debug_message' => 'synthetic-private-secret'], 403)]);
        try {
            $this->adapter($http)->renew($this->scope(), new ProviderCredentials($this->credentials()->custodyPayload() + ['account_id' => self::ACCOUNT]), '{}');
            $this->fail('A denied report probe must fail replacement.');
        } catch (SnapchatRequestFailed $failure) {
            $this->assertSame('snapchat_access_denied', $failure->reason);
            $this->assertNull($failure->getPrevious());
            $this->assertStringNotContainsString('synthetic-private-secret', $failure->getMessage());
        }
        $http->assertSentCount(3);
    }
}
