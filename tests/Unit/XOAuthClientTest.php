<?php

namespace Tests\Unit;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReadBudgetExhausted;
use App\Providers\Ads\X\XOAuthClient;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;

final class XOAuthClientTest extends TestCase
{
    private function budget(): ProviderReadBudget
    {
        return new ProviderReadBudget(new ProviderScope(7, '11111111-1111-7111-8111-111111111111', Provider::X,
            'synthetic-key', 'discovery.read', 'advertising_analysis'), 'oauth-test', 2, 0, 2, 20, new DateTimeImmutable('+30 seconds'), 1, 5);
    }

    private function client(Factory $http, ?\Closure $guard = null): XOAuthClient
    {
        return new XOAuthClient($http, 'synthetic-key', 'synthetic-private-secret', 'https://builderad.example/providers/x/callback', $guard ?? static fn () => null);
    }

    public function test_three_legged_tokens_are_signed_and_no_expiry_or_refresh_token_is_invented(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push('oauth_token=synthetic-request&oauth_token_secret=synthetic-request-secret&oauth_callback_confirmed=true')
            ->push('oauth_token=synthetic-user&oauth_token_secret=synthetic-user-secret&user_id=123&screen_name=private-name')]);
        $calls = 0;
        $client = $this->client($http, function ($scope, $action) use (&$calls): void {
            $this->assertSame('synthetic-key', $scope->accountId);
            $this->assertSame('provider.connect', $action);
            $calls++;
        });
        $budget = $this->budget();
        $temporary = $client->requestToken($budget);
        $this->assertSame('https://api.x.com/oauth/authorize?oauth_token=synthetic-request', $client->authorizationUrl($temporary));
        $access = $client->exchange($temporary, 'synthetic-verifier', $budget);
        $this->assertSame(['oauth_token' => 'synthetic-user', 'oauth_token_secret' => 'synthetic-user-secret'], $access->custodyPayload());
        $this->assertSame(2, $calls);
        $http->assertSent(fn ($r) => $r->url() === 'https://api.x.com/oauth/request_token'
            && str_contains(implode('', $r->header('Authorization')), 'oauth_callback='));
        $http->assertSent(fn ($r) => $r->url() === 'https://api.x.com/oauth/access_token'
            && str_contains(implode('', $r->header('Authorization')), 'oauth_verifier="synthetic-verifier"'));
        $http->assertSentCount(2);
    }

    public function test_unconfirmed_duplicate_or_malformed_temporary_tokens_are_rejected(): void
    {
        foreach (['oauth_token=a&oauth_token_secret=b&oauth_callback_confirmed=false',
            'oauth_token=a&oauth_token=a&oauth_token_secret=b&oauth_callback_confirmed=true',
            'oauth_token%5B%5D=a&oauth_token_secret=b&oauth_callback_confirmed=true',
            'oauth_token=%ZZ&oauth_token_secret=b&oauth_callback_confirmed=true'] as $body) {
            $http = new Factory;
            $http->preventStrayRequests();
            $http->fake(['*' => $http->response($body)]);
            try {
                $this->client($http)->requestToken($this->budget());
                $this->fail('Invalid native token response must fail.');
            } catch (\RuntimeException $error) {
                $this->assertSame('x_oauth_response_unverified', $error->getMessage());
            }
            $http->assertSentCount(1);
        }
    }

    public function test_denied_authority_prevents_exchange_and_error_bodies_are_not_exposed(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        try {
            $this->client($http, static fn () => throw new \RuntimeException('authority-denied'))->requestToken($this->budget());
            $this->fail('Denied native acquisition must not be sent.');
        } catch (\RuntimeException $error) {
            $this->assertSame('authority-denied', $error->getMessage());
        }
        $http->assertNothingSent();
        $http->fake(['*' => $http->response('synthetic-private-provider-error', 401)]);
        try {
            $this->client($http)->requestToken($this->budget());
            $this->fail('Native error must fail.');
        } catch (\RuntimeException $error) {
            $this->assertSame('x_oauth_exchange_failed', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
    }

    public function test_wrong_scope_exhausted_budget_and_invalid_verifier_cannot_send(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $client = $this->client($http);
        $budget = new ProviderReadBudget(new ProviderScope(7, '11111111-1111-7111-8111-111111111111', Provider::X,
            'foreign-key', 'discovery.read', 'advertising_analysis'), 'oauth-test', 2, 0, 2, 20, new DateTimeImmutable('+30 seconds'), 1, 5);
        try {
            $client->requestToken($budget);
            $this->fail('Foreign app budget must fail.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $budget = $this->budget();
        $budget->claimRequest(new DateTimeImmutable);
        $budget->claimRequest(new DateTimeImmutable);
        try {
            $client->requestToken($budget);
            $this->fail('Exhausted budget must fail.');
        } catch (ReadBudgetExhausted) {
            $this->addToAssertionCount(1);
        }
        $tokens = new ProviderCredentials(['oauth_token' => 'synthetic-token', 'oauth_token_secret' => 'synthetic-secret']);
        try {
            $client->exchange($tokens, "invalid\nverifier", $this->budget());
            $this->fail('Invalid verifier must fail.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $http->assertNothingSent();
    }

    public function test_transport_failure_is_redacted_and_client_cannot_serialize_secrets(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(static fn () => throw new ConnectionException('private-request-header'));
        $client = $this->client($http);
        try {
            $client->requestToken($this->budget());
            $this->fail('Connection failure must fail.');
        } catch (\RuntimeException $error) {
            $this->assertSame('x_oauth_exchange_failed', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
        $this->assertStringNotContainsString('synthetic-private-secret', print_r($client, true));
        $this->expectException(\LogicException::class);
        serialize($client);
    }
}
