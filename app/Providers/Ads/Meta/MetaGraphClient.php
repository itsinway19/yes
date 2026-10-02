<?php

namespace App\Providers\Ads\Meta;

use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;

/** Meta-only server transport. Never follows returned URLs, redirects or exposes raw transport errors. */
final class MetaGraphClient
{
    private ?DateTimeImmutable $cooldownUntil = null;

    public function __construct(private readonly Factory $http, private readonly string $version,
        #[\SensitiveParameter] private readonly string $appSecret, private readonly \Closure $currentAuthority, private readonly string $action,
        private readonly ?MetaReadCooldown $sharedCooldown = null)
    {
        if (! preg_match('/^v[1-9][0-9]\.0$/D', $version) || $appSecret === ''
            || ! in_array($action, ['provider.connect', 'provider.read'], true)) {
            throw new InvalidArgumentException('Explicit Meta API version and server app secret required.');
        }
    }

    public function request(string $method, string $path, #[\SensitiveParameter] array $parameters,
        #[\SensitiveParameter] ProviderCredentials $credentials, ProviderReadBudget $budget): array
    {
        if (! preg_match('#^(?:act_[1-9][0-9]*(?:/insights)?|[1-9][0-9]*(?:/(?:insights|adaccounts))?|me|debug_token|oauth/access_token)$#D', $path)
            || ! in_array($method, ['GET', 'POST'], true)
            || ($method === 'POST' && ! preg_match('#^act_[1-9][0-9]*/insights$#D', $path))) {
            throw new InvalidArgumentException('Only documented Meta access/reporting operations are allowed.');
        }
        // Required trusted server guard runs at every request, including a later page or poll.
        // The integration layer must recheck signed Workspace/source/session authority here.
        ($this->currentAuthority)($budget->scope, $this->action);
        $sharedDelay = $this->sharedCooldown?->remaining();
        if ($sharedDelay !== null && $sharedDelay > 0) {
            throw new MetaRequestFailed('meta_rate_limited', $sharedDelay);
        }
        $now = new DateTimeImmutable;
        if ($this->cooldownUntil !== null && $now < $this->cooldownUntil) {
            throw new MetaRequestFailed('meta_rate_limited', $this->cooldownUntil->getTimestamp() - $now->getTimestamp());
        }
        $budget->claimRequest($now);
        $seconds = max(1, min(15, $budget->deadline->getTimestamp() - $now->getTimestamp()));
        $token = $credentials->value('access_token');
        $parameters['appsecret_proof'] = hash_hmac('sha256', $token, $this->appSecret);
        try {
            $request = $this->http->acceptJson()->connectTimeout(min(5, $seconds))
                ->timeout($seconds)->withOptions(['allow_redirects' => false]);
            if ($path === 'oauth/access_token') {
                unset($parameters['appsecret_proof']);
            } else {
                $request = $request->withToken($token);
            }
            $url = 'https://graph.facebook.com/'.$this->version.'/'.$path;
            $response = $method === 'POST' ? $request->asForm()->post($url, $parameters) : $request->get($url, $parameters);
            if (strlen($response->body()) > 2097152) {
                throw new MetaRequestFailed('meta_response_too_large');
            }
            $payload = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (is_array($payload) && isset($payload['error'])) {
                $code = $payload['error']['code'] ?? null;
                $reason = match (true) {
                    $code === 190 => 'meta_token_expired',
                    $code === 10 || (is_int($code) && $code >= 200 && $code <= 299) => 'meta_permission_lost',
                    in_array($code, [4, 17, 341, 613, 80000, 80004], true) => 'meta_rate_limited',
                    default => 'meta_request_failed',
                };
                $delay = $reason === 'meta_rate_limited' ? MetaRateLimit::delay($response, $credentials, $path) : null;
                if ($delay !== null && $delay > 0) {
                    $this->sharedCooldown?->observe($delay);
                }
                throw new MetaRequestFailed($reason, $delay);
            }
            if (! $response->successful() || ! is_array($payload)) {
                throw new MetaRequestFailed('meta_request_failed');
            }

            $delay = MetaRateLimit::delay($response, $credentials, $path, false);
            if ($delay !== null && $delay > 0) {
                $this->cooldownUntil = (new DateTimeImmutable)->modify('+'.$delay.' seconds');
                $this->sharedCooldown?->observe($delay);
            }

            return $payload;
        } catch (MetaRequestFailed $exception) {
            throw $exception;
        } catch (\LogicException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new MetaRequestFailed('meta_request_failed');
        }
    }

    public function __debugInfo(): array
    {
        return ['version' => $this->version, 'app_secret' => '[REDACTED]'];
    }

    public function cooldownSeconds(): ?int
    {
        $sharedDelay = $this->sharedCooldown?->remaining();
        if ($sharedDelay !== null && $sharedDelay > 0) {
            return $sharedDelay;
        }
        if ($this->cooldownUntil === null) {
            return null;
        }

        return max(0, $this->cooldownUntil->getTimestamp() - (new DateTimeImmutable)->getTimestamp());
    }

    public function __serialize(): array
    {
        throw new \LogicException('Meta transport contains server credentials.');
    }
}
