<?php

namespace App\Providers\Ads\TikTok;

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

/** Synchronous campaign reports. Re-read accepted ranges for native restatements; no durable page-number acceptance. */
final class TikTokObservationAdapter implements ProviderObservationAdapter
{
    private readonly TikTokClient $client;

    private ?TikTokRequestFailed $failure = null;

    private const BASIS = 'https://business-api.tiktok.com/portal/docs?id=1740302848100353';

    public function __construct(Factory $http, private readonly string $version, \Closure $authority)
    {
        $this->client = new TikTokClient($http, $version, $authority, 'provider.read');
    }

    public function provider(): Provider
    {
        return Provider::TikTok;
    }

    public function failure(): ?TikTokRequestFailed
    {
        return $this->failure;
    }

    public function read(ProviderScope $scope, ProviderCredentials $credentials, ReportWindow $window,
        ProviderReadBudget $budget, ?NativeProgress $progress): ProviderReadResult
    {
        $this->failure = null;
        $v = $credentials->custodyPayload();
        if ($scope->provider !== Provider::TikTok || $scope->operation !== 'report.read' || $scope->purpose !== 'advertising_analysis'
            || $scope->jsonSerialize() !== $budget->scope->jsonSerialize() || ! TikTokClient::advertiserId($scope->accountId)
            || ($v['account_id'] ?? null) !== $scope->accountId || ($v['account_timezone'] ?? null) !== $window->timezone
            || ! preg_match('/^[A-Z]{3}$/D', $v['account_currency'] ?? '') || $window->boundary !== 'INCLUSIVE_DATES'
            || ! in_array($window->grain, ['DAY', 'TOTAL'], true)) {
            throw new InvalidArgumentException('Exact selected TikTok report scope required.');
        }
        $binding = hash('sha256', json_encode([$scope->jsonSerialize(), $window->jsonSerialize(), $this->version], JSON_THROW_ON_ERROR));
        if ($progress !== null && ($progress->kind !== 'tiktok.accepted_range' || $progress->envelope()['payload'] !== json_encode(['binding' => $binding], JSON_THROW_ON_ERROR))) {
            throw new InvalidArgumentException('TikTok accepted range cannot change scope.');
        }
        $observations = [];
        $seen = [];
        try {
            if (isset($v['expires_at']) && (int) $v['expires_at'] <= time()) {
                throw new TikTokRequestFailed('tiktok_credential_denied');
            }
            $totalPages = null;
            for ($page = 1; ; $page++) {
                if ($page > 32) {
                    throw new TikTokRequestFailed('tiktok_local_page_limit');
                }
                $dimensions = $window->grain === 'DAY' ? ['campaign_id', 'stat_time_day'] : ['campaign_id'];
                $data = $this->client->request('report/integrated/get/', ['advertiser_id' => $scope->accountId, 'report_type' => 'BASIC',
                    'data_level' => 'AUCTION_CAMPAIGN', 'dimensions' => json_encode($dimensions),
                    'metrics' => '["spend","impressions","clicks"]', 'start_date' => $window->start, 'end_date' => $window->end,
                    'page' => $page, 'page_size' => 100], $credentials, $budget);
                $rows = $data['list'] ?? null;
                $info = $data['page_info'] ?? null;
                if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100 || ! is_array($info)
                    || ($info['page'] ?? null) !== $page || ! is_int($info['total_page'] ?? null) || $info['total_page'] < 0
                    || ($totalPages !== null && $totalPages !== $info['total_page']) || ($info['total_page'] === 0 && $rows !== [])) {
                    throw new TikTokRequestFailed('tiktok_report_page_unverified');
                }
                $totalPages = $info['total_page'];
                $budget->acceptPage(count($rows), new DateTimeImmutable);
                foreach ($rows as $row) {
                    $id = $row['dimensions']['campaign_id'] ?? null;
                    if (! TikTokClient::advertiserId($id) || ! is_array($row['metrics'] ?? null)) {
                        throw new TikTokRequestFailed('tiktok_malformed_report');
                    }
                    $start = $window->start;
                    $end = $window->end;
                    if ($window->grain === 'DAY') {
                        $day = $row['dimensions']['stat_time_day'] ?? null;
                        if (! is_string($day) || ! preg_match('/^\d{4}-\d{2}-\d{2}(?: 00:00:00)?$/D', $day)) {
                            throw new TikTokRequestFailed('tiktok_malformed_report');
                        }
                        $start = $end = substr($day, 0, 10);
                        if ($start < $window->start || $start > $window->end) {
                            throw new TikTokRequestFailed('tiktok_report_range_mismatch');
                        }
                    }
                    $identity = $id.':'.$start.':'.$end;
                    if (isset($seen[$identity])) {
                        throw new TikTokRequestFailed('tiktok_duplicate_report_row');
                    }
                    $seen[$identity] = true;
                    $spend = $this->metric($row['metrics']['spend'] ?? null, true);
                    $facts = [];
                    foreach (['impressions', 'clicks'] as $name) {
                        $facts[$name] = ['value' => $this->metric($row['metrics'][$name] ?? null, false), 'unit' => 'count',
                            'meaning' => 'TikTok provider-reported '.$name, 'basis' => 'PROVIDER_REPORTED'];
                    }
                    $observations[] = new AdObservation($scope, $id,
                        new ReportWindow($start, $end, 'INCLUSIVE_DATES', $window->grain, $window->timezone, $window->semantics),
                        // Unit conversion proof is deliberately absent until the current metric guide is accepted.
                        new NativeAmount($spend, $v['account_currency'], 'tiktok.report.spend', null, null),
                        $facts, $this->provenance(), new DateTimeImmutable, null);
                }
                if ($page >= $totalPages) {
                    break;
                }
                if ($rows === []) {
                    throw new TikTokRequestFailed('tiktok_report_page_unverified');
                }
            }

            return $this->result($scope, $observations, 'COMPLETE', null,
                new NativeProgress('tiktok.accepted_range', self::BASIS, json_encode(['binding' => $binding], JSON_THROW_ON_ERROR)));
        } catch (ReadBudgetExhausted) {
            return $this->result($scope, [], 'PARTIAL', 'tiktok_local_budget_exhausted');
        } catch (TikTokRequestFailed $failure) {
            $this->failure = $failure;

            return $this->result($scope, [], 'FAILED', $failure->reason);
        }
    }

    private function metric(mixed $value, bool $decimal): ?string
    {
        if ($value !== null && (! is_string($value) || ! preg_match($decimal ? '/^\d{1,60}(?:\.\d{1,18})?$/D' : '/^\d{1,60}$/D', $value))) {
            throw new TikTokRequestFailed('tiktok_metric_unverified');
        }

        return $value;
    }

    private function provenance(): array
    {
        return ['api_version' => $this->version, 'report' => 'TikTok synchronous campaign report', 'documentation_basis' => self::BASIS];
    }

    private function result(ProviderScope $scope, array $rows, string $coverage, ?string $failure = null, ?NativeProgress $progress = null): ProviderReadResult
    {
        return new ProviderReadResult(new ObservationBatch($scope, $rows, $coverage, null, $failure), $this->provenance(), $progress);
    }
}
