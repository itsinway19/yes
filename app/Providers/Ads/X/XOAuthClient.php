<?php

namespace App\Providers\Ads\X;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/** Three-legged OAuth transport only. The coordinator must match and consume callback state before exchange. */
final readonly class XOAuthClient
{
    private XOAuthSigner $signer;

    public function __construct(private Factory $http, public string $consumerKey,
        #[\SensitiveParameter] private string $consumerSecret, private string $redirectUri, private \Closure $currentAuthority, private string $noticeBinding = '')
    {
        if (strlen($redirectUri) > 8192 || filter_var($redirectUri, FILTER_VALIDATE_URL) === false
            || parse_url($redirectUri, PHP_URL_SCHEME) !== 'https'
            || preg_match('/[\x00-\x20\x7f]/', $redirectUri)
            || array_intersect(array_keys(parse_url($redirectUri)), ['user', 'pass', 'query', 'fragment'])) {
            throw new InvalidArgumentException('Exact HTTPS X callback required.');
        }
        $this->signer = new XOAuthSigner($consumerKey, $consumerSecret);
    }

    public function configurationBinding(): string
    {
        return hash_hmac('sha256', json_encode([$this->consumerKey, $this->consumerSecret, $this->redirectUri, $this->noticeBinding], JSON_THROW_ON_ERROR),
            (string) config('app.key'));
    }

    public function forAuthority(Factory $http, \Closure $authority): self
    {
        return new self($http, $this->consumerKey, $this->consumerSecret, $this->redirectUri, $authority, $this->noticeBinding);
    }

    public function accountDiscovery(): XAccountDiscovery
    {
        return new XAccountDiscovery($this->http, $this->signer, $this->consumerKey, $this->currentAuthority);
    }

    public function accountSelection(): XAccountSelection
    {
        return new XAccountSelection($this->http, $this->signer, $this->currentAuthority);
    }

    public function campaignReports(): XCampaignReportReader
    {
        return new XCampaignReportReader($this->http, $this->signer, $this->currentAuthority);
    }

    public function requestToken(ProviderReadBudget $budget): ProviderCredentials
    {
        return $this->request('request_token', ['oauth_callback' => $this->redirectUri], null, $budget);
    }

    public function authorizationUrl(#[\SensitiveParameter] ProviderCredentials $temporary): string
    {
        $this->requireTokens($temporary);

        return 'https://api.x.com/oauth/authorize?'.http_build_query([
            'oauth_token' => $temporary->value('oauth_token'),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchange(#[\SensitiveParameter] ProviderCredentials $temporary,
        #[\SensitiveParameter] string $verifier, ProviderReadBudget $budget): ProviderCredentials
    {
        $this->requireTokens($temporary);
        if ($verifier === '' || strlen($verifier) > 8192 || preg_match('/[\x00-\x20\x7f]/', $verifier)) {
            throw new InvalidArgumentException('Bounded X callback verifier required.');
        }

        return $this->request('access_token', ['oauth_verifier' => $verifier], $temporary, $budget);
    }

    private function requireTokens(ProviderCredentials $credentials): void
    {
        foreach (['oauth_token', 'oauth_token_secret'] as $key) {
            if (preg_match('/[\x00-\x20\x7f]/', $credentials->value($key))) {
                throw new InvalidArgumentException('Bounded X OAuth token pair required.');
            }
        }
    }

    private function request(string $endpoint, array $parameters, #[\SensitiveParameter] ?ProviderCredentials $temporary,
        ProviderReadBudget $budget): ProviderCredentials
    {
        if ($budget->scope->provider !== Provider::X || $budget->scope->accountId !== $this->consumerKey
            || $budget->scope->operation !== 'discovery.read' || $budget->scope->purpose !== 'advertising_analysis') {
            throw new InvalidArgumentException('Exact X application discovery budget required.');
        }
        $url = 'https://api.x.com/oauth/'.$endpoint;
        $header = $this->signer->header('POST', $url, $parameters, $temporary);
        ($this->currentAuthority)($budget->scope, 'provider.connect');
        $at = new DateTimeImmutable;
        $budget->claimRequest($at);
        $seconds = max(1, min(15, $budget->deadline->getTimestamp() - $at->getTimestamp()));
        try {
            $response = $this->http->asForm()->withHeaders(['Authorization' => $header, 'Accept' => 'application/x-www-form-urlencoded'])
                ->connectTimeout(min(5, $seconds))->timeout($seconds)->withOptions(['allow_redirects' => false])->post($url, []);
            if ($response->status() !== 200) {
                throw new RuntimeException('x_oauth_exchange_failed');
            }
            $fields = $this->parseResponse($response->body());
            if ($endpoint === 'request_token' && ($fields['oauth_callback_confirmed'] ?? null) !== 'true') {
                throw new RuntimeException('x_oauth_response_unverified');
            }
            $tokens = [];
            foreach (['oauth_token', 'oauth_token_secret'] as $key) {
                $value = $fields[$key] ?? '';
                if ($value === '' || strlen($value) > 8192 || preg_match('/[\x00-\x20\x7f]/', $value)) {
                    throw new RuntimeException('x_oauth_response_unverified');
                }
                $tokens[$key] = $value;
            }

            // Native tokens have no expiry or refresh field. Optional identity fields are discarded.
            return new ProviderCredentials($tokens);
        } catch (ConnectionException) {
            throw new RuntimeException('x_oauth_exchange_failed');
        }
    }

    private function parseResponse(#[\SensitiveParameter] string $body): array
    {
        if ($body === '' || strlen($body) > 65536) {
            throw new RuntimeException('x_oauth_response_unverified');
        }
        $fields = [];
        foreach (explode('&', $body) as $pair) {
            if (! str_contains($pair, '=') || preg_match('/%(?![a-fA-F0-9]{2})/', $pair)) {
                throw new RuntimeException('x_oauth_response_unverified');
            }
            [$key, $value] = array_map('urldecode', explode('=', $pair, 2));
            if (! in_array($key, ['oauth_token', 'oauth_token_secret', 'oauth_callback_confirmed', 'user_id', 'screen_name'], true)
                || array_key_exists($key, $fields) || strlen($value) > 8192 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new RuntimeException('x_oauth_response_unverified');
            }
            $fields[$key] = $value;
        }

        return $fields;
    }

    public function __debugInfo(): array
    {
        return ['provider' => 'x', 'consumer_credentials' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('X OAuth transport contains server secrets.');
    }
}
