<?php

namespace App\Providers\Ads\TikTok;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use JsonException;

/** Fixed official host, explicit endpoint version, no arbitrary URLs or ad-management requests. */
final readonly class TikTokClient
{
    public function __construct(private Factory $http, private string $version, private \Closure $authority, private string $action)
    {
        if ($version !== 'v1.3' || ! in_array($action, ['provider.connect', 'provider.read'], true)) {
            throw new InvalidArgumentException('Explicit supported TikTok transport required.');
        }
    }

    public static function advertiserId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[1-9][0-9]{0,24}$/D', $id) === 1;
    }

    public function request(string $endpoint, array $fields, ?ProviderCredentials $credentials, ProviderReadBudget $budget): array
    {
        if ($budget->scope->provider !== Provider::TikTok || ! in_array($endpoint,
            ['oauth2/access_token/', 'oauth2/advertiser/get/', 'advertiser/info/', 'report/integrated/get/'], true)
            || ($this->action === 'provider.read' && $endpoint !== 'report/integrated/get/')
            || ($endpoint === 'oauth2/access_token/' && $credentials !== null)) {
            throw new InvalidArgumentException('TikTok request cannot change its authorized transport.');
        }
        if ($this->action === 'provider.read' && ($fields['advertiser_id'] ?? null) !== $budget->scope->accountId) {
            throw new InvalidArgumentException('TikTok report account mismatch.');
        }
        ($this->authority)($budget->scope, $this->action);
        $at = new DateTimeImmutable;
        $budget->claimRequest($at);
        $seconds = max(1, min(15, $budget->deadline->getTimestamp() - $at->getTimestamp()));
        $url = 'https://business-api.tiktok.com/open_api/'.$this->version.'/'.$endpoint;
        try {
            $pending = $this->http->acceptJson()->connectTimeout(min(5, $seconds))->timeout($seconds)->withOptions(['allow_redirects' => false]);
            if ($credentials !== null) {
                $pending = $pending->withHeaders(['Access-Token' => $credentials->value('access_token')]);
            }
            $response = $endpoint === 'oauth2/access_token/' ? $pending->post($url, $fields) : $pending->get($url, $fields);
            if (strlen($response->body()) > 4194304) {
                throw new TikTokRequestFailed('tiktok_response_too_large');
            }
            // Gateways can return empty or HTML failures; HTTP authority must survive JSON parsing.
            if (! $response->successful()) {
                throw new TikTokRequestFailed(match ($response->status()) {
                    401 => 'tiktok_credential_denied', 403 => 'tiktok_access_denied', 429 => 'tiktok_rate_limited',
                    500, 502, 503, 504 => 'tiktok_service_unavailable', default => 'tiktok_request_failed',
                }, $this->retryAfter($response->header('Retry-After')));
            }
            $body = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! is_array($body) || ($body['code'] ?? null) !== 0) {
                throw new TikTokRequestFailed('tiktok_request_failed', $this->retryAfter($response->header('Retry-After')));
            }
            if (! is_array($body['data'] ?? null)) {
                throw new TikTokRequestFailed('tiktok_malformed_response');
            }

            return $body['data'];
        } catch (ConnectionException) {
            throw new TikTokRequestFailed('tiktok_transport_failed');
        } catch (JsonException) {
            throw new TikTokRequestFailed('tiktok_malformed_response');
        }
    }

    /** HTTP Retry-After syntax; bounds are local scheduling limits, not TikTok quota claims. */
    private function retryAfter(?string $header): ?int
    {
        if ($header === null || $header === '') {
            return null;
        }
        if (preg_match('/^[0-9]{1,8}$/D', $header) && (int) $header <= 31536000) {
            return (int) $header;
        }
        $format = 'D, d M Y H:i:s \G\M\T';
        $date = DateTimeImmutable::createFromFormat('!'.$format, $header, new \DateTimeZone('UTC'));
        if ($date !== false && $date->format($format) === $header) {
            $seconds = max(0, $date->getTimestamp() - time());
            if ($seconds <= 31536000) {
                return $seconds;
            }
        }
        throw new TikTokRequestFailed('tiktok_retry_hint_unverified');
    }
}
