<?php

namespace App\Providers\Ads\Google;

use App\Providers\Ads\AdObservation;
use App\Providers\Ads\NativeAmount;
use App\Providers\Ads\NativeProgress;
use App\Providers\Ads\ObservationBatch;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderObservationAdapter;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderReadResult;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReadBudgetExhausted;
use App\Providers\Ads\ReportWindow;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;

/** Campaign-period GAQL Search; native micros, dates and manager context remain Google-owned. */
final class GoogleObservationAdapter implements ProviderObservationAdapter
{
    private readonly GoogleAdsClient $client;

    private ?GoogleRequestFailed $lastFailure = null;

    private const BASIS = 'https://developers.google.com/google-ads/api/fields/v25/campaign';

    public function __construct(Factory $http, private readonly string $version,
        #[\SensitiveParameter] string $developerToken, \Closure $currentAuthority)
    {
        $this->client = new GoogleAdsClient($http, $version, $developerToken, $currentAuthority, 'provider.read');
    }

    public function provider(): Provider
    {
        return Provider::Google;
    }

    public function failure(): ?GoogleRequestFailed
    {
        return $this->lastFailure;
    }

    public function read(ProviderScope $scope, #[\SensitiveParameter] ProviderCredentials $credentials,
        ReportWindow $window, ProviderReadBudget $budget, ?NativeProgress $progress): ProviderReadResult
    {
        $this->lastFailure = null;
        $values = $credentials->custodyPayload();
        $login = $values['login_customer_id'] ?? null;
        if ($scope->provider !== Provider::Google || $scope->operation !== 'report.read'
            || $scope->purpose !== 'advertising_analysis' || ! GoogleAdsClient::customerId($scope->accountId)
            || $scope->jsonSerialize() !== $budget->scope->jsonSerialize()
            || ($values['account_id'] ?? null) !== $scope->accountId
            || ($values['granted_scope'] ?? null) !== GoogleOAuthClient::SCOPE
            || ($login !== null && ! GoogleAdsClient::customerId($login))
            || $window->boundary !== 'INCLUSIVE_DATES' || $window->timezone === null
            || ! in_array($window->grain, ['DAY', 'TOTAL'], true)
            || ($progress !== null && $progress->kind !== 'google.accepted_range')) {
            throw new InvalidArgumentException('Exact selected Google reporting account, route and native date window required.');
        }
        $fields = ['customer.id', 'customer.currency_code', 'customer.time_zone', 'campaign.id', 'campaign.resource_name', 'campaign.status',
            'metrics.cost_micros', 'metrics.impressions', 'metrics.clicks', 'metrics.conversions', 'metrics.all_conversions', 'metrics.conversions_value'];
        $mask = ['customer.id', 'customer.currencyCode', 'customer.timeZone', 'campaign.id', 'campaign.resourceName', 'campaign.status',
            'metrics.costMicros', 'metrics.impressions', 'metrics.clicks', 'metrics.conversions', 'metrics.allConversions', 'metrics.conversionsValue'];
        if ($window->grain === 'DAY') {
            $fields[] = 'segments.date';
            $mask[] = 'segments.date';
        }
        $query = 'SELECT '.implode(', ', $fields)." FROM campaign WHERE segments.date BETWEEN '".$window->start."' AND '".$window->end."'";
        $observations = [];
        $seen = [];
        $tokens = [];
        $token = null;
        try {
            if (! preg_match('/^[0-9]{1,12}$/D', $values['expires_at'] ?? '') || (int) $values['expires_at'] <= time()) {
                throw new GoogleRequestFailed('google_credential_reverification_required');
            }
            // Reverify current native account context even for an empty report or a historic reread.
            $metadata = $this->client->search($scope->accountId,
                'SELECT customer.id, customer.manager, customer.currency_code, customer.time_zone FROM customer LIMIT 1',
                $credentials, $budget, $login);
            $accounts = GoogleAdsClient::rows($metadata, ['customer.id', 'customer.manager', 'customer.currencyCode', 'customer.timeZone']);
            $budget->acceptPage(count($accounts), new DateTimeImmutable);
            $account = $accounts[0]['customer'] ?? null;
            if (count($accounts) !== 1 || ! empty($metadata['nextPageToken']) || ! is_array($account)
                || ($account['id'] ?? null) !== $scope->accountId || ($account['manager'] ?? null) !== false
                || ($account['timeZone'] ?? null) !== $window->timezone
                || (isset($values['account_currency']) && ($account['currencyCode'] ?? null) !== $values['account_currency'])
                || ! is_string($account['currencyCode'] ?? null) || ! preg_match('/^[A-Z]{3}$/D', $account['currencyCode'])) {
                throw new GoogleRequestFailed('google_account_context_changed');
            }
            do {
                $page = $this->client->search($scope->accountId, $query, $credentials, $budget, $login, $token);
                $rows = GoogleAdsClient::rows($page, $mask);
                $budget->acceptPage(count($rows), new DateTimeImmutable);
                foreach ($rows as $row) {
                    $observation = $this->map($row, $scope, $window, $account);
                    if (isset($seen[$observation->identity()])) {
                        throw new GoogleRequestFailed('google_duplicate_measurement_slot');
                    }
                    $seen[$observation->identity()] = true;
                    $observations[] = $observation;
                }
                $token = $page['nextPageToken'] ?? null;
                if ($token === '') {
                    $token = null;
                }
                if ($token !== null) {
                    if (! is_string($token) || strlen($token) > 8192 || isset($tokens[$token])) {
                        throw new GoogleRequestFailed('google_invalid_or_repeated_page_token');
                    }
                    $tokens[$token] = true;
                }
            } while ($token !== null);
        } catch (ReadBudgetExhausted) {
            return $this->result($scope, $observations, 'PARTIAL', 'google_local_budget_exhausted');
        } catch (GoogleRequestFailed $error) {
            $this->lastFailure = $error;

            return $this->result($scope, [], 'FAILED', $error->reason);
        }
        // Page tokens are transient. Full accepted ranges are reread because native metrics can restate.
        $accepted = new NativeProgress('google.accepted_range', 'https://developers.google.com/google-ads/api/docs/reporting/paging',
            json_encode(['request_fingerprint' => hash('sha256', json_encode([$scope->jsonSerialize(), $query, $login,
                $this->version, $window->jsonSerialize()], JSON_THROW_ON_ERROR)),
                'read_at' => (new DateTimeImmutable)->format(DATE_ATOM)], JSON_THROW_ON_ERROR));

        return $this->result($scope, $observations, 'COMPLETE', null, $accepted);
    }

    private function map(mixed $row, ProviderScope $scope, ReportWindow $window, array $account): AdObservation
    {
        $id = is_array($row) ? ($row['campaign']['id'] ?? null) : null;
        if (! is_string($id) || ! preg_match('/^[1-9][0-9]{0,18}$/D', $id)
            || ($row['campaign']['resourceName'] ?? null) !== 'customers/'.$scope->accountId.'/campaigns/'.$id
            || ($row['customer']['id'] ?? null) !== $scope->accountId
            || ($row['customer']['currencyCode'] ?? null) !== $account['currencyCode']
            || ($row['customer']['timeZone'] ?? null) !== $window->timezone
            || ! is_array($row['metrics'] ?? null)) {
            throw new GoogleRequestFailed('google_malformed_or_cross_account_row');
        }
        $start = $window->start;
        $end = $window->end;
        if ($window->grain === 'DAY') {
            $start = $end = $row['segments']['date'] ?? null;
            if (! is_string($start) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $start)
                || $start < $window->start || $start > $window->end
                || ! ($date = DateTimeImmutable::createFromFormat('!Y-m-d', $start)) || $date->format('Y-m-d') !== $start) {
                throw new GoogleRequestFailed('google_out_of_range_report_date');
            }
        } elseif (isset($row['segments'])) {
            throw new GoogleRequestFailed('google_unrequested_report_segmentation');
        }
        $facts = [];
        $status = $row['campaign']['status'] ?? null;
        if ($status !== null && (! is_string($status) || ! in_array($status, ['ENABLED', 'PAUSED', 'REMOVED', 'UNKNOWN', 'UNSPECIFIED'], true))) {
            throw new GoogleRequestFailed('google_malformed_campaign_status');
        }
        $facts['campaign_status'] = ['value' => $status, 'unit' => 'google.campaign_status',
            'meaning' => 'Google Ads campaign.status; native campaign state, not connection or analysis readiness',
            'basis' => $status === null ? 'UNKNOWN' : 'PROVIDER_REPORTED'];
        foreach (['impressions', 'clicks'] as $field) {
            $value = $this->integer($row['metrics'][$field] ?? null, false);
            $facts[$field] = ['value' => $value, 'unit' => 'count', 'meaning' => 'Google Ads metrics.'.$field,
                'basis' => $value === null ? 'UNKNOWN' : 'PROVIDER_REPORTED'];
        }

        foreach (['conversions' => 'conversions', 'allConversions' => 'all_conversions', 'conversionsValue' => 'conversions_value'] as $field => $name) {
            $value = $this->decimal($row['metrics'][$field] ?? null);
            $facts[$name] = ['value' => $value, 'unit' => $name === 'conversions_value' ? 'provider_conversion_value' : 'provider_conversion_count',
                'meaning' => 'Google Ads metrics.'.$name.'; provider attribution, not an accepted business outcome',
                'basis' => $value === null ? 'UNKNOWN' : 'PROVIDER_REPORTED'];
        }

        return new AdObservation($scope, $id,
            new ReportWindow($start, $end, 'INCLUSIVE_DATES', $window->grain, $window->timezone,
                'Google Ads account-local segments.date BETWEEN inclusive dates; absent segmented rows do not establish campaign zero'),
            new NativeAmount($this->integer($row['metrics']['costMicros'] ?? null, true), $account['currencyCode'],
                'google.currency_micros', 6, 'https://developers.google.com/google-ads/api/reference/rpc/v25/Money'),
            $facts, $this->provenance(), new DateTimeImmutable, null);
    }

    private function decimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || strlen($value) > 128
            || ! preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?$/D', $value)
            || ! is_finite((float) $value)) {
            throw new GoogleRequestFailed('google_malformed_metric');
        }

        return $value;
    }

    private function integer(mixed $value, bool $signed): ?string
    {
        if ($value !== null && (! is_string($value)
            || ! preg_match($signed ? '/^-?(?:0|[1-9]\d{0,18})$/D' : '/^(?:0|[1-9]\d{0,18})$/D', $value))) {
            throw new GoogleRequestFailed('google_malformed_metric');
        }
        if ($value !== null) {
            $digits = ltrim($value, '-');
            $limit = str_starts_with($value, '-') ? '9223372036854775808' : '9223372036854775807';
            if (strlen($digits) === 19 && strcmp($digits, $limit) > 0) {
                throw new GoogleRequestFailed('google_malformed_metric');
            }
        }

        return $value;
    }

    private function provenance(): array
    {
        return ['api_version' => $this->version, 'report' => 'Google Ads GAQL campaign Search', 'documentation_basis' => self::BASIS];
    }

    private function result(ProviderScope $scope, array $observations, string $coverage, ?string $failure = null,
        ?NativeProgress $progress = null): ProviderReadResult
    {
        return new ProviderReadResult(new ObservationBatch($scope, $observations, $coverage, null, $failure), $this->provenance(), $progress);
    }

    public function __debugInfo(): array
    {
        return ['version' => $this->version, 'failure' => $this->lastFailure?->reason, 'credentials' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Google report transport cannot enter a browser or queue payload.');
    }
}
