<?php

namespace App\Providers\Ads\Snapchat;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use JsonException;

/** Fixed Ads API host and GET-only allowlist. Native URLs never control the request target. */
final readonly class SnapchatClient
{
    public function __construct(private Factory $http, private \Closure $authority, private string $action)
    {
        if (! in_array($action, ['provider.connect', 'provider.read'], true)) {
            throw new InvalidArgumentException('Exact Snapchat transport action required.');
        }
    }

    public static function accountId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/iD', $id) === 1;
    }

    public function get(string $path, array $query, ProviderCredentials $credentials, ProviderReadBudget $budget): array
    {
        $scope = $budget->scope;
        $account = self::accountId($scope->accountId) ? 'adaccounts/'.$scope->accountId : null;
        $discovery = $path === 'me/organizations' && $this->action === 'provider.connect';
        $metadata = $account !== null && $path === $account && $this->action === 'provider.connect';
        $report = $account !== null && $path === $account.'/stats';
        $status = $account !== null && $path === $account.'/stats_report' && $this->action === 'provider.read';
        $async = $report && array_key_exists('async', $query);
        if ($scope->provider !== Provider::Snapchat || (! $discovery && ! $metadata && ! $report && ! $status)
            || ($status && (array_keys($query) !== ['report_run_id'] || ! SnapchatAsyncReport::runId($query['report_run_id'] ?? null, $scope->accountId)))
            || ($async && ($this->action !== 'provider.read' || ($query['async'] ?? null) !== 'true'
                || ($query['async_format'] ?? null) !== 'csv' || isset($query['limit']) || isset($query['cursor'])))
            || (! $async && array_key_exists('async_format', $query))
            || ($discovery && $query !== ['with_ad_accounts' => 'true']) || ($metadata && $query !== [])
            || ($report && (array_diff(array_keys($query), ['granularity', 'fields', 'start_time', 'end_time', 'breakdown', 'limit', 'cursor', 'async', 'async_format'])
                || ($query['granularity'] ?? null) !== 'TOTAL'
                || ! is_string($query['fields'] ?? null) || ! preg_match('/^[a-z_]+(?:,[a-z_]+)*$/D', $query['fields'])
                || ! is_string($query['start_time'] ?? null) || ! is_string($query['end_time'] ?? null)
                || (isset($query['breakdown']) && $query['breakdown'] !== 'campaign')
                || (isset($query['limit']) && $query['limit'] !== '200')
                || (isset($query['cursor']) && (! is_string($query['cursor']) || ! preg_match('/^[\x21-\x7e]{1,4096}$/D', $query['cursor'])))))) {
            throw new InvalidArgumentException('Snapchat GET cannot change its authorized resource or endpoint.');
        }
        ($this->authority)($scope, $this->action);
        $at = new DateTimeImmutable;
        $budget->claimRequest($at);
        $seconds = max(1, min(15, $budget->deadline->getTimestamp() - $at->getTimestamp()));
        try {
            $response = $this->http->acceptJson()->withToken($credentials->value('access_token'))
                ->connectTimeout(min(5, $seconds))->timeout($seconds)->withOptions(['allow_redirects' => false])
                ->get('https://adsapi.snapchat.com/v1/'.$path, $query);
            if (strlen($response->body()) > 4194304) {
                throw new SnapchatRequestFailed('snapchat_response_too_large');
            }
            if (! $response->successful()) {
                // Documented E9001 is a non-transient native size rejection, not a retryable outage.
                if ($response->status() === 400) {
                    $error = json_decode($response->body(), true, 32, JSON_BIGINT_AS_STRING);
                    if (is_array($error) && ($error['error_code'] ?? null) === 'E9001') {
                        throw new SnapchatRequestFailed('snapchat_async_report_required');
                    }
                }
                throw new SnapchatRequestFailed(match ($response->status()) {
                    401 => 'snapchat_credential_denied', 403 => 'snapchat_access_denied', 429 => 'snapchat_rate_limited',
                    500, 502, 503, 504 => 'snapchat_service_unavailable', default => 'snapchat_request_failed',
                }, $this->retryAfter($response->header('Retry-After')));
            }
            $body = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! is_array($body) || ! self::success($body['request_status'] ?? null)) {
                throw new SnapchatRequestFailed('snapchat_request_failed');
            }

            return $body;
        } catch (ConnectionException) {
            throw new SnapchatRequestFailed('snapchat_transport_failed');
        } catch (JsonException) {
            throw new SnapchatRequestFailed('snapchat_malformed_response');
        }
    }

    public static function success(mixed $status): bool
    {
        return is_string($status) && strcasecmp($status, 'SUCCESS') === 0;
    }

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
        // An unreadable native delay cannot be shortened to local backoff.
        throw new SnapchatRequestFailed('snapchat_retry_hint_unverified');
    }
}
