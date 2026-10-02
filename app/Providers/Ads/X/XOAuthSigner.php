<?php

namespace App\Providers\Ads\X;

use Abraham\TwitterOAuth\Consumer;
use Abraham\TwitterOAuth\HmacSha1;
use Abraham\TwitterOAuth\Request;
use Abraham\TwitterOAuth\Token;
use App\Providers\Ads\ProviderCredentials;
use InvalidArgumentException;
use LogicException;

/** Known OAuth1 library signing only; it never performs HTTP or grants provider access. */
final readonly class XOAuthSigner
{
    public function __construct(private string $consumerKey, #[\SensitiveParameter] private string $consumerSecret)
    {
        foreach ([$consumerKey, $consumerSecret] as $value) {
            if ($value === '' || strlen($value) > 8192 || preg_match('/[\x00-\x20\x7f]/', $value)) {
                throw new InvalidArgumentException('Explicit bounded X consumer credentials required.');
            }
        }
    }

    public function header(string $method, string $url, array $parameters, #[\SensitiveParameter] ?ProviderCredentials $credentials = null): string
    {
        $parts = parse_url($url);
        $oauth = $method === 'POST' && in_array($url, ['https://api.x.com/oauth/request_token', 'https://api.x.com/oauth/access_token'], true);
        $adsMethod = $method === 'GET' || ($method === 'POST' && preg_match('#^/12/stats/jobs/accounts/[a-zA-Z0-9]{1,180}$#D', $parts['path'] ?? ''));
        $ads = $adsMethod && is_array($parts) && ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? null) === 'ads-api.x.com' && preg_match('#^/12/[a-zA-Z0-9_/]+$#D', $parts['path'] ?? '')
            && ! array_intersect(array_keys($parts), ['user', 'pass', 'port', 'query', 'fragment']);
        if ((! $oauth && ! $ads) || count($parameters) > 30) {
            throw new InvalidArgumentException('X signing cannot change its fixed HTTPS OAuth or read-only Ads API target.');
        }
        foreach ($parameters as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[a-z][a-z_]{0,79}$/D', $key) || ! is_string($value)
                || strlen($value) > 8192 || preg_match('/[\x00-\x1f\x7f]/', $value)
                || (str_starts_with($key, 'oauth') && ! ($oauth && in_array($key, ['oauth_callback', 'oauth_verifier'], true)))) {
                throw new InvalidArgumentException('X OAuth control fields and non-scalar parameters cannot be overridden.');
            }
        }
        if ($ads && $credentials === null) {
            throw new InvalidArgumentException('X Ads reads require user-context OAuth token and secret.');
        }
        try {
            $consumer = new Consumer($this->consumerKey, $this->consumerSecret);
            $token = $credentials === null ? null : new Token($credentials->value('oauth_token'), $credentials->value('oauth_token_secret'));
            $request = Request::fromConsumerAndToken($consumer, $method, $url, $token, $parameters);
            $request->setParameter('oauth_nonce', bin2hex(random_bytes(24)));
            $request->signRequest(new HmacSha1, $consumer, $token);

            return substr($request->toHeader(), strlen('Authorization: '));
        } catch (\Exception) {
            // Library Consumer/Token string representations contain secrets. Never attach their exceptions or objects.
            throw new LogicException('X OAuth request signing failed.');
        }
    }

    public function __debugInfo(): array
    {
        return ['provider' => 'x', 'consumer_credentials' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('X consumer credentials cannot be serialized.');
    }
}
