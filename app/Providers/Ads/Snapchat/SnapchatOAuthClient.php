<?php

namespace App\Providers\Ads\Snapchat;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderReadBudget;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use JsonException;
use LogicException;

/** Confidential advertiser OAuth only. The coordinator must bind and consume callback state first. */
final readonly class SnapchatOAuthClient
{
    public function __construct(private Factory $http, public string $clientId,
        #[\SensitiveParameter] private string $clientSecret, private string $redirectUri, private \Closure $currentAuthority)
    {
        if (! preg_match('/^[a-zA-Z0-9_-]{1,180}$/D', $clientId) || $clientSecret === '' || strlen($clientSecret) > 8192
            || preg_match('/[\x00-\x1f\x7f]/', $clientSecret) || filter_var($redirectUri, FILTER_VALIDATE_URL) === false
            || parse_url($redirectUri, PHP_URL_SCHEME) !== 'https' || parse_url($redirectUri, PHP_URL_USER) !== null
            || parse_url($redirectUri, PHP_URL_PASS) !== null || parse_url($redirectUri, PHP_URL_QUERY) !== null
            || parse_url($redirectUri, PHP_URL_FRAGMENT) !== null) {
            throw new InvalidArgumentException('Explicit Snapchat web client and exact HTTPS callback required.');
        }
    }

    public function authorizationUrl(string $state): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]{43}$/D', $state)) {
            throw new InvalidArgumentException('Server-generated bound Snapchat state required.');
        }

        return 'https://accounts.snapchat.com/login/oauth2/authorize?'.http_build_query([
            'client_id' => $this->clientId, 'redirect_uri' => $this->redirectUri, 'response_type' => 'code',
            'scope' => 'snapchat-marketing-api', 'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** HMAC includes private configuration without projecting it into state or logs. */
    public function configurationBinding(): string
    {
        return hash_hmac('sha256', json_encode([$this->clientId, $this->clientSecret, $this->redirectUri], JSON_THROW_ON_ERROR),
            (string) config('app.key'));
    }

    public function forAuthority(Factory $http, \Closure $authority): self
    {
        return new self($http, $this->clientId, $this->clientSecret, $this->redirectUri, $authority);
    }

    public function exchange(#[\SensitiveParameter] string $code, ProviderReadBudget $budget): array
    {
        return $this->request(['grant_type' => 'authorization_code', 'code' => $code], $budget);
    }

    public function refresh(#[\SensitiveParameter] string $token, ProviderReadBudget $budget): array
    {
        return $this->request(['grant_type' => 'refresh_token', 'refresh_token' => $token], $budget);
    }

    private function request(array $fields, ProviderReadBudget $budget): array
    {
        if ($budget->scope->provider !== Provider::Snapchat) {
            throw new InvalidArgumentException('Snapchat OAuth cannot consume a sibling budget.');
        }
        foreach ($fields as $value) {
            if (! is_string($value) || $value === '' || strlen($value) > 8192 || preg_match('/[\x00-\x20\x7f]/', $value)) {
                throw new InvalidArgumentException('Bounded server-only Snapchat grant required.');
            }
        }
        ($this->currentAuthority)($budget->scope, 'provider.connect');
        $at = new DateTimeImmutable;
        $budget->claimRequest($at);
        $seconds = max(1, min(15, $budget->deadline->getTimestamp() - $at->getTimestamp()));
        try {
            $response = $this->http->acceptJson()->asForm()->connectTimeout(min(5, $seconds))->timeout($seconds)
                ->withOptions(['allow_redirects' => false])->post('https://accounts.snapchat.com/login/oauth2/access_token',
                    $fields + ['client_id' => $this->clientId, 'client_secret' => $this->clientSecret, 'redirect_uri' => $this->redirectUri]);
            if (strlen($response->body()) > 65536) {
                throw new SnapchatRequestFailed('snapchat_token_response_unverified');
            }
            $payload = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! $response->successful() || ! is_array($payload) || isset($payload['error'])) {
                throw new SnapchatRequestFailed(is_array($payload) && ($payload['error'] ?? null) === 'invalid_grant'
                    ? 'snapchat_reconnect_required' : 'snapchat_token_exchange_failed');
            }
            foreach (['access_token', 'refresh_token'] as $field) {
                if (! is_string($payload[$field] ?? null) || $payload[$field] === '' || strlen($payload[$field]) > 8192
                    || preg_match('/[\x00-\x1f\x7f]/', $payload[$field])) {
                    throw new SnapchatRequestFailed('snapchat_token_response_unverified');
                }
            }
            if (! is_string($payload['token_type'] ?? null) || strcasecmp($payload['token_type'], 'Bearer') !== 0
                || ! is_int($payload['expires_in'] ?? null) || $payload['expires_in'] < 1 || $payload['expires_in'] > 31536000) {
                throw new SnapchatRequestFailed('snapchat_token_response_unverified');
            }

            return $payload;
        } catch (ConnectionException) {
            throw new SnapchatRequestFailed('snapchat_token_exchange_failed');
        } catch (JsonException) {
            throw new SnapchatRequestFailed('snapchat_token_response_unverified');
        }
    }

    public function __debugInfo(): array
    {
        return ['client_id' => $this->clientId, 'client_secret' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('Snapchat OAuth transport contains server secrets.');
    }
}
