<?php

namespace Tests\Unit;

use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\X\XOAuthSigner;
use PHPUnit\Framework\TestCase;

final class XOAuthSignerTest extends TestCase
{
    public function test_signatures_bind_query_method_and_tokens_with_unique_native_nonces(): void
    {
        $signer = new XOAuthSigner('synthetic-key', 'synthetic-private-secret');
        $tokens = new ProviderCredentials(['oauth_token' => 'synthetic-user-token', 'oauth_token_secret' => 'synthetic-private-token-secret']);
        $first = $signer->header('GET', 'https://ads-api.x.com/12/accounts', ['cursor' => 'a+b/c=', 'count' => '20'], $tokens);
        $second = $signer->header('GET', 'https://ads-api.x.com/12/accounts', ['cursor' => 'a+b/c=', 'count' => '20'], $tokens);
        $this->assertStringStartsWith('OAuth ', $first);
        $this->assertStringContainsString('oauth_signature_method="HMAC-SHA1"', $first);
        $this->assertStringContainsString('oauth_token="synthetic-user-token"', $first);
        $this->assertStringNotContainsString('synthetic-private-secret', $first);
        $this->assertStringNotContainsString('synthetic-private-token-secret', $first);
        $this->assertNotSame($first, $second);
        preg_match_all('/([a-z_]+)="([^"]*)"/', $first, $matches, PREG_SET_ORDER);
        $params = ['cursor' => 'a+b/c=', 'count' => '20'];
        foreach ($matches as $match) {
            $params[$match[1]] = rawurldecode($match[2]);
        }
        $signature = $params['oauth_signature'];
        unset($params['oauth_signature']);
        $encoded = [];
        foreach ($params as $key => $value) {
            $encoded[rawurlencode($key)] = rawurlencode($value);
        }
        ksort($encoded, SORT_STRING);
        $pairs = [];
        foreach ($encoded as $key => $value) {
            $pairs[] = $key.'='.$value;
        }
        $base = 'GET&'.rawurlencode('https://ads-api.x.com/12/accounts').'&'.rawurlencode(implode('&', $pairs));
        $this->assertSame(base64_encode(hash_hmac('sha1', $base, 'synthetic-private-secret&synthetic-private-token-secret', true)), $signature);
    }

    public function test_only_fixed_https_native_oauth_or_ads_reads_can_be_signed(): void
    {
        $signer = new XOAuthSigner('synthetic-key', 'synthetic-secret');
        foreach ([['GET', 'http://ads-api.x.com/12/accounts'], ['GET', 'https://evil.example/12/accounts'],
            ['POST', 'https://ads-api.x.com/12/accounts'], ['GET', 'https://user@ads-api.x.com/12/accounts'],
            ['GET', 'https://ads-api.x.com:443/12/accounts'], ['GET', 'https://ads-api.x.com/12/accounts?injected=true'],
            ['POST', 'https://api.x.com/oauth/other']] as [$method, $url]) {
            try {
                $signer->header($method, $url, []);
                $this->fail('Untrusted signing target must fail.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_oauth_control_fields_cannot_override_library_identity_or_signature(): void
    {
        $signer = new XOAuthSigner('synthetic-key', 'synthetic-secret');
        $this->expectException(\InvalidArgumentException::class);
        $signer->header('GET', 'https://ads-api.x.com/12/accounts', ['oauth_consumer_key' => 'injected']);
    }

    public function test_debug_and_serialization_do_not_expose_app_secrets(): void
    {
        $signer = new XOAuthSigner('synthetic-key', 'synthetic-private-secret');
        $this->assertStringNotContainsString('synthetic-private-secret', print_r($signer, true));
        $this->expectException(\LogicException::class);
        serialize($signer);
    }
}
