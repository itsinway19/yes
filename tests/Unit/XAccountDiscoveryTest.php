<?php

namespace Tests\Unit;

use App\Providers\Ads\NativeProgress;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReadBudgetExhausted;
use App\Providers\Ads\X\XAccountDiscovery;
use App\Providers\Ads\X\XOAuthSigner;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;

final class XAccountDiscoveryTest extends TestCase
{
    private function budget(string $key = 'synthetic-key'): ProviderReadBudget
    {
        return new ProviderReadBudget(new ProviderScope(7, '11111111-1111-7111-8111-111111111111', Provider::X,
            $key, 'discovery.read', 'advertising_analysis'), 'x-discovery', 4, 0, 4, 800, new DateTimeImmutable('+30 seconds'), 1, 5);
    }

    private function credentials(string $token = 'synthetic-token'): ProviderCredentials
    {
        return new ProviderCredentials(['oauth_token' => $token, 'oauth_token_secret' => 'synthetic-token-secret']);
    }

    private function reader(Factory $http, ?\Closure $guard = null): XAccountDiscovery
    {
        return new XAccountDiscovery($http, new XOAuthSigner('synthetic-key', 'synthetic-app-secret'), 'synthetic-key', $guard ?? static fn () => null);
    }

    private function payload(string $id = 'abc123', ?string $cursor = null): array
    {
        return ['request' => ['params' => ['count' => 200]], 'next_cursor' => $cursor,
            'data' => [['id' => $id, 'name' => 'Synthetic account', 'deleted' => false, 'approval_status' => 'PENDING', 'business_name' => 'discard-private-name']]];
    }

    public function test_signed_native_pages_use_fixed_target_and_minimized_accounts_without_admin_requirement(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push($this->payload('abc123', 'opaque+/=cursor'))->push($this->payload('def456'))]);
        $calls = 0;
        $reader = $this->reader($http, function ($scope, $action) use (&$calls): void {
            $this->assertSame('synthetic-key', $scope->accountId);
            $this->assertSame('provider.connect', $action);
            $calls++;
        });
        $budget = $this->budget();
        $first = $reader->page($budget, $this->credentials());
        $this->assertSame('PARTIAL', $first->coverage);
        $this->assertSame([['id' => 'abc123', 'label' => 'Synthetic account', 'native_role' => null]], $first->accounts);
        $second = $reader->page($budget, $this->credentials(), $first->continuation);
        $this->assertSame('COMPLETE', $second->coverage);
        $this->assertNull($second->continuation);
        $this->assertSame('def456', $second->accounts[0]['id']);
        $this->assertSame(2, $calls);
        $http->assertSent(fn ($r) => str_starts_with($r->url(), 'https://ads-api.x.com/12/accounts?') && $r->method() === 'GET'
            && ($r->data()['cursor'] ?? null) === 'opaque+/=cursor' && str_starts_with(implode('', $r->header('Authorization')), 'OAuth '));
        $this->assertSame(2, $budget->usage()['rows']);
    }

    public function test_changed_credentials_scope_or_cursor_binding_prevent_continuation_http(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response($this->payload('abc123', 'opaque-next'))]);
        $reader = $this->reader($http);
        $first = $reader->page($this->budget(), $this->credentials());
        foreach ([[$this->budget(), $this->credentials('changed-token')], [$this->budget('foreign-key'), $this->credentials()]] as [$budget, $tokens]) {
            try {
                $reader->page($budget, $tokens, $first->continuation);
                $this->fail('Changed continuation context must fail.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $record = json_decode($first->continuation->envelope()['payload'], true);
        $record['cursor'] = 'tampered-next';
        $tampered = new NativeProgress($first->continuation->kind, $first->continuation->basis, json_encode($record));
        try {
            $reader->page($this->budget(), $this->credentials(), $tampered);
            $this->fail('Tampered cursor must fail.');
        } catch (\InvalidArgumentException) {
            $http->assertSentCount(1);
        }
    }

    public function test_malformed_duplicate_pages_and_cyclic_native_cursors_are_rejected(): void
    {
        $duplicate = $this->payload();
        $duplicate['data'][] = $duplicate['data'][0];
        $missing = $this->payload();
        unset($missing['next_cursor']);
        foreach ([$duplicate, $missing, ['data' => []], $this->payload('foreign/path'), $this->payload('abc123', '')] as $payload) {
            $http = new Factory;
            $http->preventStrayRequests();
            $http->fake(['*' => $http->response($payload)]);
            try {
                $this->reader($http)->page($this->budget(), $this->credentials());
                $this->fail('Unverified native page must fail.');
            } catch (\RuntimeException $error) {
                $this->assertSame('x_discovery_unverified', $error->getMessage());
            }
        }
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push($this->payload('abc123', 'cursor-a'))->push($this->payload('def456', 'cursor-b'))->push($this->payload('ghi789', 'cursor-a'))]);
        $reader = $this->reader($http);
        $budget = $this->budget();
        $first = $reader->page($budget, $this->credentials());
        $second = $reader->page($budget, $this->credentials(), $first->continuation);
        try {
            $reader->page($budget, $this->credentials(), $second->continuation);
            $this->fail('Native cursor cycles must fail.');
        } catch (\RuntimeException $error) {
            $this->assertSame('x_discovery_unverified', $error->getMessage());
        }
    }

    public function test_current_authority_denial_prevents_http_and_native_error_body_is_redacted(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        try {
            $this->reader($http, static fn () => throw new \RuntimeException('denied'))->page($this->budget(), $this->credentials());
            $this->fail('Denied request must fail.');
        } catch (\RuntimeException $error) {
            $this->assertSame('denied', $error->getMessage());
        }
        $http->assertNothingSent();
        $http->fake(['*' => $http->response('private-provider-body', 403)]);
        try {
            $this->reader($http)->page($this->budget(), $this->credentials());
            $this->fail('Native denial must fail.');
        } catch (\RuntimeException $error) {
            $this->assertSame('x_access_denied', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
    }

    public function test_long_native_labels_are_shortened_without_rejecting_accounts_or_changing_identity(): void
    {
        $payload = $this->payload();
        $payload['data'][0]['name'] = "اسم\n".str_repeat('معلن', 100);
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response($payload)]);
        $page = $this->reader($http)->page($this->budget(), $this->credentials());
        $this->assertSame('abc123', $page->accounts[0]['id']);
        $this->assertLessThanOrEqual(200, strlen($page->accounts[0]['label']));
        $this->assertTrue(mb_check_encoding($page->accounts[0]['label'], 'UTF-8'));
        $this->assertStringNotContainsString("\n", $page->accounts[0]['label']);
    }

    public function test_expired_run_cannot_send_and_excess_rows_cannot_be_accepted(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $scope = $this->budget()->scope;
        $expired = new ProviderReadBudget($scope, 'expired', 1, 0, 1, 1, new DateTimeImmutable('-1 second'), 1, 5);
        try {
            $this->reader($http)->page($expired, $this->credentials());
            $this->fail('Expired run cannot send.');
        } catch (ReadBudgetExhausted) {
            $http->assertNothingSent();
        }
        $payload = $this->payload();
        $payload['data'][] = ['id' => 'def456', 'name' => 'Second', 'deleted' => false];
        $http->fake(['*' => $http->response($payload)]);
        $budget = new ProviderReadBudget($scope, 'small-row-budget', 1, 0, 1, 1, new DateTimeImmutable('+30 seconds'), 1, 5);
        try {
            $this->reader($http)->page($budget, $this->credentials());
            $this->fail('Oversized page cannot be accepted.');
        } catch (ReadBudgetExhausted) {
            $this->assertSame(0, $budget->usage()['rows']);
            $this->assertSame(0, $budget->usage()['pages']);
            $http->assertSentCount(1);
        }
    }
}
