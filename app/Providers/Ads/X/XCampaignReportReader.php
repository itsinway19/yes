<?php

namespace App\Providers\Ads\X;

use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderReadResult;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use JsonException;
use LogicException;
use RuntimeException;

/** Internal synchronous read: campaign currency proof first, then the exact signed stats batch. */
final readonly class XCampaignReportReader
{
    public function __construct(private Factory $http, private XOAuthSigner $signer, private \Closure $currentAuthority, private ?XReadCooldown $cooldown = null) {}

    public function read(XCampaignReport $report, #[\SensitiveParameter] ProviderCredentials $credentials, ProviderReadBudget $budget): ProviderReadResult
    {
        $report->requireCurrentTimezone($credentials);
        if ($report->asynchronous) {
            throw new \InvalidArgumentException('Native async reports cannot use the synchronous endpoint.');
        }
        $currencies = (new XCampaignCurrencyReader($this->http, $this->signer, $this->currentAuthority, $this->cooldown))->read($report, $credentials, $budget);
        $header = $this->signer->header('GET', $report->url(), $report->query(), $credentials);
        ($this->currentAuthority)($report->scope, 'provider.read');
        $report->requireCurrentTimezone($credentials);
        $this->cooldown?->before('sync_stats');
        $at = new DateTimeImmutable;
        $budget->claimRequest($at);
        $seconds = max(1, min(15, $budget->deadline->getTimestamp() - $at->getTimestamp()));
        try {
            $response = $this->http->acceptJson()->withHeaders(['Authorization' => $header])->connectTimeout(min(5, $seconds))
                ->timeout($seconds)->withOptions(['allow_redirects' => false])->get($report->url(), $report->query());
            $this->cooldown?->observe($response, 'sync_stats');
            if ($response->status() !== 200) {
                throw new XRequestFailed(match ($response->status()) {
                    401 => 'x_credential_denied', 403 => 'x_access_denied', 429 => 'x_rate_limited',
                    500, 502, 503, 504 => 'x_service_unavailable', default => 'x_report_read_failed',
                }, XRateLimit::exhausted($response, new DateTimeImmutable)['seconds'] ?? null);
            }
            if (strlen($response->body()) > 4194304) {
                throw new RuntimeException('x_report_unverified');
            }
            $body = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! is_array($body) || ! empty($body['errors'])) {
                throw new RuntimeException('x_report_unverified');
            }
            $result = $report->map($body, $currencies);
            if ($result->batch->coverage === 'COMPLETE') {
                $budget->acceptPage(count($result->batch->observations), new DateTimeImmutable);
            }

            return $result;
        } catch (ConnectionException) {
            throw new XRequestFailed('x_transport_failed');
        } catch (JsonException) {
            throw new RuntimeException('x_report_unverified');
        }
    }

    public function __debugInfo(): array
    {
        return ['provider' => 'x', 'credentials' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('X report signing context cannot be serialized.');
    }
}
