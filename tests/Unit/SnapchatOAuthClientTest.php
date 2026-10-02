<?php

namespace Tests\Unit;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\Snapchat\SnapchatOAuthClient;
use App\Providers\Ads\Snapchat\SnapchatRequestFailed;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SnapchatOAuthClientTest extends TestCase
{
    private function budget(Provider $provider = Provider::Snapchat): ProviderReadBudget
    {
        return new ProviderReadBudget(new ProviderScope(7, '11111111-1111-7111-8111-111111111111', $provider,
            'synthetic-snap-client', 'discovery.read', 'advertising_analysis'), 'snap-oauth', 2, 0, 2, 5,
            new DateTimeImmutable('+30 seconds'), 1, 5);
    }

    public function test_exchange_and_refresh_send_the_current_authority_and_callback_with_both_native_grants(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()
            ->push(['access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh', 'expires_in' => 99, 'token_type' => 'Bearer'])
            ->push(['access_token' => 'synthetic-renewed-access', 'refresh_token' => 'synthetic-rotated-refresh', 'expires_in' => 120, 'token_type' => 'Bearer'])]);
        $guards = [];
        $client = new SnapchatOAuthClient($http, 'synthetic-snap-client', 'synthetic-private-secret',
            'https://builderad.example/providers/snapchat/callback', static function ($scope, $action) use (&$guards): void {
                $guards[] = [$scope->provider->value, $action];
            });
        $acquired = $client->exchange('synthetic-code', $this->budget());
        $this->assertArrayHasKey('expires_in', $acquired);
        $this->assertSame(99, $acquired['expires_in']);
        $this->assertSame('synthetic-rotated-refresh', $client->refresh('synthetic-refresh', $this->budget())['refresh_token']);
        $this->assertSame([['snapchat', 'provider.connect'], ['snapchat', 'provider.connect']], $guards);
        $http->assertSent(fn ($r) => $r->url() === 'https://accounts.snapchat.com/login/oauth2/access_token'
            && $r['grant_type'] === 'authorization_code' && $r['code'] === 'synthetic-code'
            && $r['redirect_uri'] === 'https://builderad.example/providers/snapchat/callback');
        $http->assertSent(fn ($r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'synthetic-refresh'
            && $r['redirect_uri'] === 'https://builderad.example/providers/snapchat/callback');
    }

    public function test_advertiser_redirect_uses_only_documented_snap_scope_and_never_exposes_secret(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $client = new SnapchatOAuthClient($http, 'synthetic-snap-client', 'synthetic-private-secret',
            'https://builderad.example/providers/snapchat/callback', static fn () => null);
        $url = $client->authorizationUrl(str_repeat('a', 43));
        $this->assertSame('https://accounts.snapchat.com/login/oauth2/authorize', strtok($url, '?'));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('snapchat-marketing-api', $query['scope']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame(str_repeat('a', 43), $query['state']);
        $this->assertStringNotContainsString('synthetic-private-secret', $url);
        $this->assertArrayNotHasKey('access_type', $query);
        $http->assertNothingSent();
    }

    public function test_sibling_budget_cannot_exchange_snapchat_tokens(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $client = new SnapchatOAuthClient($http, 'synthetic-snap-client', 'synthetic-private-secret',
            'https://builderad.example/providers/snapchat/callback', static fn () => null);
        $this->expectException(InvalidArgumentException::class);
        try {
            $client->exchange('synthetic-code', $this->budget(Provider::Google));
        } finally {
            $http->assertNothingSent();
        }
    }

    public function test_expired_current_authority_prevents_native_token_exchange(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $client = new SnapchatOAuthClient($http, 'synthetic-snap-client', 'synthetic-private-secret',
            'https://builderad.example/providers/snapchat/callback', static fn () => throw new AuthorizationException);
        $this->expectException(AuthorizationException::class);
        try {
            $client->exchange('synthetic-code', $this->budget());
        } finally {
            $http->assertNothingSent();
        }
    }

    public function test_native_error_description_never_enters_safe_failure(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['error' => 'invalid_grant', 'error_description' => 'synthetic-private-secret'], 400)]);
        $client = new SnapchatOAuthClient($http, 'synthetic-snap-client', 'synthetic-private-secret',
            'https://builderad.example/providers/snapchat/callback', static fn () => null);
        try {
            $client->exchange('synthetic-code', $this->budget());
            $this->fail('A denied native grant must fail.');
        } catch (SnapchatRequestFailed $failure) {
            $this->assertSame('snapchat_reconnect_required', $failure->reason);
            $this->assertStringNotContainsString('synthetic-private-secret', $failure->getMessage());
            $this->assertNull($failure->getPrevious());
        }
        $http->assertSentCount(1);
    }
}
