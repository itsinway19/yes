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
use RuntimeException;

/** Campaign metadata read only; no funding-account/financial endpoint or write permission is requested. */
final readonly class XCampaignCurrencyReader
{
    public function __construct(private Factory $http, private XOAuthSigner $signer, private \Closure $currentAuthority, private ?XReadCooldown $cooldown = null) {}

    public function read(XCampaignReport $report, #[\SensitiveParameter] ProviderCredentials $credentials, ProviderReadBudget $budget): XCampaignCurrency
    {
        if ($budget->scope->jsonSerialize() !== $report->scope->jsonSerialize()
            || $credentials->value('account_id') !== $report->scope->accountId
            || $credentials->value('account_timezone') !== $report->window->timezone) {
            throw new InvalidArgumentException('Exact selected X account and native timezone required for currency lookup.');
        }
        $url = 'https://ads-api.x.com/12/accounts/'.$report->scope->accountId.'/campaigns';
        $query = ['campaign_ids' => $report->query()['entity_ids'], 'count' => '200', 'with_deleted' => 'true', 'with_draft' => 'true'];
        $header = $this->signer->header('GET', $url, $query, $credentials);
        ($this->currentAuthority)($budget->scope, 'provider.read');
        $report->requireCurrentTimezone($credentials);
        $this->cooldown?->before('campaigns');
        $at = new DateTimeImmutable;
        $budget->claimRequest($at);
        $seconds = max(1, min(15, $budget->deadline->getTimestamp() - $at->getTimestamp()));
        try {
            $response = $this->http->acceptJson()->withHeaders(['Authorization' => $header])->connectTimeout(min(5, $seconds))
                ->timeout($seconds)->withOptions(['allow_redirects' => false])->get($url, $query);
            $this->cooldown?->observe($response, 'campaigns');
            if ($response->status() !== 200) {
                throw new XRequestFailed(match ($response->status()) {
                    401 => 'x_credential_denied', 403 => 'x_access_denied', 429 => 'x_rate_limited',
                    500, 502, 503, 504 => 'x_service_unavailable', default => 'x_currency_lookup_failed',
                }, XRateLimit::exhausted($response, new DateTimeImmutable)['seconds'] ?? null);
            }
            if (strlen($response->body()) > 4194304) {
                throw new RuntimeException('x_currency_metadata_unverified');
            }
            $body = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! is_array($body)) {
                throw new RuntimeException('x_currency_metadata_unverified');
            }
            $proof = new XCampaignCurrency($report, $body);
            $budget->acceptPage(count(explode(',', $report->query()['entity_ids'])), new DateTimeImmutable);

            return $proof;
        } catch (ConnectionException) {
            throw new XRequestFailed('x_transport_failed');
        } catch (JsonException|InvalidArgumentException) {
            throw new RuntimeException('x_currency_metadata_unverified');
        }
    }

    public function __debugInfo(): array
    {
        return ['provider' => 'x', 'credentials' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('X currency signing context cannot be serialized.');
    }
}
