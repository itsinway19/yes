<?php

namespace App\Providers\Ads\Google;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderReadBudget;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use JsonException;

/** Confidential web OAuth only. The caller must validate and consume session/Workspace-bound state first. */
final readonly class GoogleOAuthClient
{
    public const SCOPE = 'https://www.googleapis.com/auth/adwords';

    public function __construct(private Factory $http, public string $clientId,
        #[\SensitiveParameter] private string $clientSecret, private string $redirectUri, private \Closure $currentAuthority)
    {
        if (! preg_match('/^[a-zA-Z0-9_-]{1,180}\.apps\.googleusercontent\.com$/D', $clientId)
            || $clientSecret === '' || preg_match('/[\x00-\x1f\x7f]/', $clientSecret)
            || filter_var($redirectUri, FILTER_VALIDATE_URL) === false
            || parse_url($redirectUri, PHP_URL_SCHEME) !== 'https'
            || parse_url($redirectUri, PHP_URL_USER) !== null || parse_url($redirectUri, PHP_URL_PASS) !== null
            || parse_url($redirectUri, PHP_URL_QUERY) !== null || parse_url($redirectUri, PHP_URL_FRAGMENT) !== null) {
            throw new InvalidArgumentException('Explicit Google web client and exact HTTPS redirect required.');
        }
    }

    public function authorizationUrl(string $state, bool $requestNewOfflineGrant = false): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]{43,128}$/D', $state)) {
            throw new InvalidArgumentException('Server-generated bound Google OAuth state required.');
        }
        $query = ['client_id' => $this->clientId, 'redirect_uri' => $this->redirectUri, 'response_type' => 'code',
            'scope' => self::SCOPE, 'access_type' => 'offline', 'state' => $state];
        // Avoid repeated permission prompts. Re-request only when the existing offline grant is unavailable.
        if ($requestNewOfflineGrant) {
            $query['prompt'] = 'consent';
        }

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public function exchange(#[\SensitiveParameter] string $code, ProviderReadBudget $budget): array
    {
        return $this->request(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->redirectUri], $budget);
    }

    public function refresh(#[\SensitiveParameter] string $refreshToken, ProviderReadBudget $budget): array
    {
        return $this->request(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken], $budget);
    }

    private function request(array $fields, ProviderReadBudget $budget): array
    {
        if ($budget->scope->provider !== Provider::Google) {
            throw new InvalidArgumentException('Google OAuth requires a Google scope.');
        }
        ($this->currentAuthority)($budget->scope, 'provider.connect');
        $now = new DateTimeImmutable;
        $budget->claimRequest($now);
        $seconds = max(1, min(15, $budget->deadline->getTimestamp() - $now->getTimestamp()));
        try {
            $response = $this->http->acceptJson()->asForm()->connectTimeout(min(5, $seconds))->timeout($seconds)
                ->withOptions(['allow_redirects' => false])->post('https://oauth2.googleapis.com/token',
                    $fields + ['client_id' => $this->clientId, 'client_secret' => $this->clientSecret]);
            if (strlen($response->body()) > 65536) {
                throw new GoogleRequestFailed('google_token_response_too_large');
            }
            $payload = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! $response->successful() || ! is_array($payload) || isset($payload['error'])) {
                throw new GoogleRequestFailed(is_array($payload) && ($payload['error'] ?? null) === 'invalid_grant'
                    ? 'google_reconnect_required' : 'google_token_exchange_failed');
            }

            return $payload;
        } catch (ConnectionException|JsonException) {
            throw new GoogleRequestFailed('google_token_exchange_failed');
        }
    }

    public function __debugInfo(): array
    {
        return ['client_id' => $this->clientId, 'client_secret' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Google OAuth client contains server secrets.');
    }
}
