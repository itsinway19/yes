<?php

namespace App\Providers\Ads\Snapchat;

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
use DateTimeZone;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;

/** Bounded campaign TOTAL pages. Only a fully validated range may become durable progress. */
final class SnapchatObservationAdapter implements ProviderObservationAdapter
{
    private const BASIS = 'https://developers.snap.com/api/marketing-api/Ads-API/measurement';

    private readonly SnapchatClient $client;

    private ?SnapchatRequestFailed $failure = null;

    public function __construct(Factory $http, \Closure $authority)
    {
        $this->client = new SnapchatClient($http, $authority, 'provider.read');
    }

    public function provider(): Provider
    {
        return Provider::Snapchat;
    }

    public function failure(): ?SnapchatRequestFailed
    {
        return $this->failure;
    }

    public function read(ProviderScope $scope, ProviderCredentials $credentials, ReportWindow $window,
        ProviderReadBudget $budget, ?NativeProgress $progress): ProviderReadResult
    {
        $this->failure = null;
        $values = $credentials->custodyPayload();
        if ($scope->provider !== Provider::Snapchat || $scope->operation !== 'report.read' || $scope->purpose !== 'advertising_analysis'
            || $scope->jsonSerialize() !== $budget->scope->jsonSerialize() || ! SnapchatClient::accountId($scope->accountId)
            || ($values['account_id'] ?? null) !== $scope->accountId || ($values['account_timezone'] ?? null) !== $window->timezone
            || ! is_string($values['account_currency'] ?? null) || ! preg_match('/^[A-Z]{3}$/D', $values['account_currency'])
            || $window->timezone === null || $window->grain !== 'TOTAL') {
            throw new InvalidArgumentException('Exact selected Snapchat campaign TOTAL scope required.');
        }
        $nativeWindow = $window;
        if ($window->boundary === 'INCLUSIVE_DATES') {
            $zone = new DateTimeZone($window->timezone);
            $start = new DateTimeImmutable($window->start.' 00:00:00', $zone);
            $end = (new DateTimeImmutable($window->end.' 00:00:00', $zone))->modify('+1 day');
            $nativeWindow = new ReportWindow($start->format(DATE_ATOM), $end->format(DATE_ATOM), 'HALF_OPEN_INSTANTS',
                'TOTAL', $window->timezone, $window->semantics);
        }
        $binding = hash('sha256', json_encode([$scope->jsonSerialize(), $nativeWindow->jsonSerialize(), 'v1'], JSON_THROW_ON_ERROR));
        if ($progress !== null && ($progress->kind !== 'snapchat.accepted_range'
            || $progress->basis !== self::BASIS || $progress->envelope()['payload'] !== json_encode(['binding' => $binding], JSON_THROW_ON_ERROR))) {
            throw new InvalidArgumentException('Snapchat accepted range cannot change scope or native window.');
        }
        try {
            if (! is_string($values['expires_at'] ?? null) || ! preg_match('/^[1-9][0-9]{0,10}$/D', $values['expires_at'])
                || (int) $values['expires_at'] <= time()) {
                throw new SnapchatRequestFailed('snapchat_credential_denied');
            }
            $path = 'adaccounts/'.$scope->accountId.'/stats';
            $baseQuery = ['granularity' => 'TOTAL',
                'breakdown' => 'campaign', 'fields' => 'impressions,swipes,spend', 'start_time' => $nativeWindow->start,
                'end_time' => $nativeWindow->end, 'limit' => '200'];
            $query = $baseQuery;
            $observations = [];
            $seen = [];
            $cursors = [];
            do {
                $body = $this->client->get($path, $query, $credentials, $budget);
                $totals = $body['total_stats'] ?? null;
                if (! is_array($totals) || ! array_is_list($totals) || count($totals) !== 1 || ! is_array($totals[0])) {
                    throw new SnapchatRequestFailed('snapchat_report_unverified');
                }
                $wrapper = $totals[0];
                if (! SnapchatClient::success($wrapper['sub_request_status'] ?? null)) {
                    return $this->result($scope, [], 'PARTIAL', 'snapchat_native_subrequest_incomplete');
                }
                $total = $wrapper['total_stat'] ?? null;
                if (! is_array($total) || ($total['id'] ?? null) !== $scope->accountId || ($total['type'] ?? null) !== 'AD_ACCOUNT'
                    || ($total['granularity'] ?? null) !== 'TOTAL') {
                    throw new SnapchatRequestFailed('snapchat_report_account_mismatch');
                }
                $this->range($total, $nativeWindow, true);
                if (! empty($total['paging']) || ! empty($total['breakdown_stats']['paging'])) {
                    return $this->result($scope, [], 'PARTIAL', 'snapchat_native_paging_unverified');
                }
                $rows = $total['breakdown_stats']['campaign'] ?? null;
                if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 200) {
                    throw new SnapchatRequestFailed('snapchat_campaign_breakdown_unverified');
                }
                $budget->acceptPage(count($rows), new DateTimeImmutable);
                foreach ($rows as $row) {
                    if (is_array($row) && ! empty($row['paging'])) {
                        return $this->result($scope, [], 'PARTIAL', 'snapchat_native_paging_unverified');
                    }
                    if (! is_array($row) || ! SnapchatClient::accountId($row['id'] ?? null) || ($row['type'] ?? null) !== 'CAMPAIGN'
                        || ($row['granularity'] ?? null) !== 'TOTAL' || ! is_array($row['stats'] ?? null)) {
                        throw new SnapchatRequestFailed('snapchat_campaign_breakdown_unverified');
                    }
                    if (array_key_exists('sub_request_status', $row) && ! SnapchatClient::success($row['sub_request_status'])) {
                        return $this->result($scope, [], 'PARTIAL', 'snapchat_native_subrequest_incomplete');
                    }
                    $this->range($row, $nativeWindow, false);
                    if (isset($seen[$row['id']])) {
                        throw new SnapchatRequestFailed('snapchat_duplicate_report_row');
                    }
                    $seen[$row['id']] = true;
                    $facts = [];
                    foreach (['impressions', 'swipes'] as $metric) {
                        $value = $this->metric($row['stats'][$metric] ?? null);
                        $facts[$metric] = ['value' => $value, 'unit' => 'count',
                            'meaning' => 'Snapchat provider-reported '.$metric, 'basis' => $value === null ? 'UNKNOWN' : 'PROVIDER_REPORTED'];
                    }
                    $observations[] = new AdObservation($scope, $row['id'], $nativeWindow,
                        new NativeAmount($this->metric($row['stats']['spend'] ?? null), $values['account_currency'],
                            'snapchat.report.spend.micro_currency', 6,
                            'https://developers.snap.com/marketing-api/Ads-API/monetary-values'), $facts, $this->provenance(), new DateTimeImmutable, null);
                }
                $cursor = $this->nextCursor($body['paging'] ?? null, $path, $baseQuery);
                if ($cursor === false || ($cursor !== null && isset($cursors[$cursor]))) {
                    return $this->result($scope, [], 'PARTIAL', 'snapchat_native_paging_unverified');
                }
                if ($cursor !== null) {
                    $cursors[$cursor] = true;
                    $query = $baseQuery + ['cursor' => $cursor];
                }
            } while ($cursor !== null);

            return $this->result($scope, $observations, 'COMPLETE', null,
                new NativeProgress('snapchat.accepted_range', self::BASIS, json_encode(['binding' => $binding], JSON_THROW_ON_ERROR)));
        } catch (ReadBudgetExhausted) {
            return $this->result($scope, [], 'PARTIAL', 'snapchat_local_budget_exhausted');
        } catch (SnapchatRequestFailed $failure) {
            $this->failure = $failure;

            return $this->result($scope, [], 'FAILED', $failure->reason);
        }
    }

    /** Treat native next_link as evidence for one cursor, never as a request target. */
    private function nextCursor(mixed $paging, string $path, array $query): string|false|null
    {
        if ($paging === null || $paging === []) {
            return null;
        }
        if (! is_array($paging) || array_diff(array_keys($paging), ['next_link']) || ! array_key_exists('next_link', $paging)) {
            return false;
        }
        $link = $paging['next_link'];
        if ($link === null || $link === '') {
            return null;
        }
        if (! is_string($link) || strlen($link) > 8192 || preg_match('/[\x00-\x20\x7f]/', $link)) {
            return false;
        }
        $parts = parse_url($link);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== 'adsapi.snapchat.com'
            || ($parts['path'] ?? null) !== '/v1/'.$path || ! isset($parts['query'])
            || array_intersect(array_keys($parts), ['user', 'pass', 'port', 'fragment'])) {
            return false;
        }
        $values = [];
        $pairs = explode('&', $parts['query']);
        if (count($pairs) > count($query) + 1) {
            return false;
        }
        foreach ($pairs as $pair) {
            if (! str_contains($pair, '=') || preg_match('/%(?![0-9a-f]{2})/i', $pair)) {
                return false;
            }
            [$key, $value] = array_map('urldecode', explode('=', $pair, 2));
            if (array_key_exists($key, $values) || (! array_key_exists($key, $query) && $key !== 'cursor')
                || ($key !== 'cursor' && $value !== $query[$key])) {
                return false;
            }
            $values[$key] = $value;
        }
        $cursor = $values['cursor'] ?? null;

        return is_string($cursor) && preg_match('/^[\x21-\x7e]{1,4096}$/D', $cursor) ? $cursor : false;
    }

    private function range(array $native, ReportWindow $window, bool $required): void
    {
        foreach (['start_time' => $window->start, 'end_time' => $window->end] as $field => $expected) {
            if (! $required && ! array_key_exists($field, $native)) {
                continue;
            }
            $value = $native[$field] ?? null;
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
                throw new SnapchatRequestFailed('snapchat_report_range_unverified');
            }
            try {
                $at = new DateTimeImmutable($value);
                $errors = DateTimeImmutable::getLastErrors();
                if (($errors && ($errors['warning_count'] || $errors['error_count'])) || $at != new DateTimeImmutable($expected)) {
                    throw new SnapchatRequestFailed('snapchat_report_range_mismatch');
                }
            } catch (\DateMalformedStringException) {
                throw new SnapchatRequestFailed('snapchat_report_range_unverified');
            }
        }
    }

    private function metric(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if ($value !== null && (! is_string($value) || ! preg_match('/^\d{1,60}$/D', $value))) {
            throw new SnapchatRequestFailed('snapchat_metric_unverified');
        }

        return $value;
    }

    private function provenance(): array
    {
        return ['api_version' => 'v1', 'report' => 'Snapchat synchronous campaign TOTAL breakdown', 'documentation_basis' => self::BASIS];
    }

    private function result(ProviderScope $scope, array $rows, string $coverage, ?string $failure = null, ?NativeProgress $progress = null): ProviderReadResult
    {
        return new ProviderReadResult(new ObservationBatch($scope, $rows, $coverage, null, $failure), $this->provenance(), $progress);
    }
}
