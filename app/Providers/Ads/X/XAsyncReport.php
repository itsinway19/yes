<?php

namespace App\Providers\Ads\X;

use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use JsonException;
use LogicException;

/** Native report creation/poll/download only; encrypted execution custody owns the ticket. */
final readonly class XAsyncReport
{
    public function __construct(private Factory $http, private XOAuthSigner $signer, private \Closure $authority,
        private ?XReadCooldown $cooldown = null) {}

    public function advance(XCampaignReport $report, #[\SensitiveParameter] ProviderCredentials $credentials, ProviderReadBudget $budget,
        #[\SensitiveParameter] ?array $ticket, ?\Closure $created = null): array
    {
        $report->requireCurrentTimezone($credentials);
        if (! $report->asynchronous || $budget->scope->jsonSerialize() !== $report->scope->jsonSerialize()
            || $credentials->value('account_id') !== $report->scope->accountId
            || $credentials->value('account_timezone') !== $report->window->timezone) {
            throw new InvalidArgumentException('Exact asynchronous account report required.');
        }
        $url = 'https://ads-api.x.com/12/stats/jobs/accounts/'.$report->scope->accountId;
        if ($ticket === null) {
            $body = $this->native('POST', $url, $report->query(), $report, $credentials, $budget);
            $row = $body['data'] ?? null;
        } elseif (($ticket['state'] ?? null) === 'SUCCESS') {
            $this->requireTicket($report, $ticket);
            $row = null;
        } else {
            $this->requireTicket($report, $ticket);
            $body = $this->native('GET', $url, ['job_ids' => $ticket['job_id']], $report, $credentials, $budget);
            $rows = $body['data'] ?? null;
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) !== 1) {
                throw new XRequestFailed('x_async_status_unverified');
            }
            $row = $rows[0];
        }
        $next = $ticket !== null && $row === null ? $ticket : $this->ticket($report, $row, $ticket['job_id'] ?? null);
        if ($ticket === null && $created !== null) {
            $created($next);
        }
        if ($next['state'] !== 'SUCCESS') {
            return ['ticket' => $next, 'result' => null];
        }
        $currencies = (new XCampaignCurrencyReader($this->http, $this->signer, $this->authority, $this->cooldown))->read($report, $credentials, $budget);
        $result = $report->map($this->download($report, $next, $budget), $currencies);
        if ($result->batch->coverage !== 'COMPLETE') {
            throw new XRequestFailed('x_async_result_unverified');
        }
        $budget->acceptPage(count($result->batch->observations), new DateTimeImmutable);

        return ['ticket' => null, 'result' => $result];
    }

    private function native(string $method, string $url, array $params, XCampaignReport $report,
        #[\SensitiveParameter] ProviderCredentials $credentials, ProviderReadBudget $budget): array
    {
        ($this->authority)($report->scope, 'provider.read');
        $report->requireCurrentTimezone($credentials);
        $this->cooldown?->before('async_stats');
        $budget->claimRequest(new DateTimeImmutable);
        $header = $this->signer->header($method, $url, $params, $credentials);
        $seconds = max(1, min(15, $budget->deadline->getTimestamp() - time()));
        try {
            $request = $this->http->acceptJson()->withHeaders(['Authorization' => $header])->connectTimeout(min(5, $seconds))
                ->timeout($seconds)->withOptions(['allow_redirects' => false]);
            $response = $method === 'POST' ? $request->asForm()->post($url, $params) : $request->get($url, $params);
            $this->cooldown?->observe($response, 'async_stats');
            if ($response->status() !== 200) {
                throw new XRequestFailed(match ($response->status()) {
                    401 => 'x_credential_denied', 403 => 'x_access_denied', 429 => 'x_rate_limited',
                    500, 502, 503, 504 => 'x_service_unavailable', default => 'x_async_read_failed',
                }, XRateLimit::exhausted($response, new DateTimeImmutable)['seconds'] ?? null);
            }
            if (strlen($response->body()) > 1048576) {
                throw new XRequestFailed('x_async_status_unverified');
            }
            $body = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! is_array($body) || ! empty($body['errors'])) {
                throw new XRequestFailed('x_async_status_unverified');
            }

            return $body;
        } catch (ConnectionException) {
            throw new XRequestFailed('x_transport_failed');
        } catch (JsonException) {
            throw new XRequestFailed('x_async_status_unverified');
        }
    }

    private function ticket(XCampaignReport $report, mixed $row, ?string $expected): array
    {
        if (! is_array($row) || ! is_string($row['id_str'] ?? null) || ! preg_match('/^[0-9]{1,30}$/D', $row['id_str'])
            || ($expected !== null && $row['id_str'] !== $expected) || ($row['account_id'] ?? null) !== $report->scope->accountId
            || ($row['segmentation_type'] ?? null) !== null || ($row['country'] ?? null) !== null || ($row['platform'] ?? null) !== null
            || ! in_array($row['status'] ?? null, ['PROCESSING', 'SUCCESS'], true)) {
            throw new XRequestFailed('x_async_status_unverified');
        }
        foreach ($report->query() as $key => $value) {
            $actual = $row[$key] ?? null;
            if (in_array($key, ['entity_ids', 'metric_groups'], true)) {
                if (! is_array($actual) || ! array_is_list($actual) || count($actual) !== count(explode(',', $value))) {
                    throw new XRequestFailed('x_async_status_unverified');
                }
                $want = explode(',', $value);
                sort($want, SORT_STRING);
                sort($actual, SORT_STRING);
                if ($actual !== $want) {
                    throw new XRequestFailed('x_async_status_unverified');
                }
            } elseif ($actual !== $value) {
                throw new XRequestFailed('x_async_status_unverified');
            }
        }
        $url = $row['url'] ?? null;
        $state = $row['status'];
        if (($state === 'PROCESSING' && $url !== null)
            || ($state === 'SUCCESS' && $url !== 'https://ton.twimg.com/advertiser-api-async-analytics/stats_job_'.$row['id_str'].'.json.gz')) {
            throw new XRequestFailed('x_async_result_unverified');
        }

        return ['binding' => $report->binding(), 'job_id' => $row['id_str'], 'state' => $state, 'url' => $url];
    }

    private function requireTicket(XCampaignReport $report, array $ticket): void
    {
        if (array_keys($ticket) !== ['binding', 'job_id', 'state', 'url'] || $ticket['binding'] !== $report->binding()
            || ! is_string($ticket['job_id']) || ! preg_match('/^[0-9]{1,30}$/D', $ticket['job_id'])
            || ! in_array($ticket['state'], ['PROCESSING', 'SUCCESS'], true)
            || ($ticket['state'] === 'PROCESSING' && $ticket['url'] !== null)
            || ($ticket['state'] === 'SUCCESS' && $ticket['url'] !== 'https://ton.twimg.com/advertiser-api-async-analytics/stats_job_'.$ticket['job_id'].'.json.gz')) {
            throw new InvalidArgumentException('X async ticket changed its authorized report.');
        }
    }

    private function download(XCampaignReport $report, array $ticket, ProviderReadBudget $budget): array
    {
        ($this->authority)($report->scope, 'provider.read');
        $report->requireCurrentOffset();
        $budget->claimRequest(new DateTimeImmutable);
        $seconds = max(1, min(15, $budget->deadline->getTimestamp() - time()));
        try {
            // The fixed native file host receives no OAuth header, token, cookie or redirect following.
            $response = $this->http->withHeaders(['Accept' => 'application/gzip'])->connectTimeout(min(5, $seconds))
                ->timeout($seconds)->withOptions(['allow_redirects' => false, 'stream' => true])->get($ticket['url']);
            if ($response->status() !== 200) {
                throw new XRequestFailed('x_async_download_failed');
            }
            $stream = $response->toPsrResponse()->getBody();
            $bytes = '';
            try {
                while (! $stream->eof()) {
                    $bytes .= $stream->read(65536);
                    if (strlen($bytes) > 4194304 || time() >= $budget->deadline->getTimestamp()) {
                        throw new XRequestFailed('x_async_result_unverified');
                    }
                }
            } finally {
                $stream->close();
            }
            // Malformed provider files produce a bounded failure, never a PHP warning in the response/log.
            set_error_handler(static fn (): bool => true, E_WARNING);
            try {
                $decoded = gzdecode($bytes, 4194304);
            } finally {
                restore_error_handler();
            }
            if ($decoded === false) {
                throw new XRequestFailed('x_async_result_unverified');
            }
            $body = json_decode($decoded, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! is_array($body) || ! empty($body['errors'])) {
                throw new XRequestFailed('x_async_result_unverified');
            }
            // X's native download example omits request echo. Verified job metadata supplies it;
            // any echo actually present must still pass the ordinary exact-request mapper.
            if (! array_key_exists('request', $body)) {
                $params = $report->query();
                $params['entity_ids'] = explode(',', $params['entity_ids']);
                $params['metric_groups'] = explode(',', $params['metric_groups']);
                $body['request'] = ['params' => $params];
            }

            return $body;
        } catch (ConnectionException) {
            throw new XRequestFailed('x_transport_failed');
        } catch (JsonException) {
            throw new XRequestFailed('x_async_result_unverified');
        }
    }

    public function __debugInfo(): array
    {
        return ['provider' => 'x', 'signing_context' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('X async signing context cannot be serialized.');
    }
}
