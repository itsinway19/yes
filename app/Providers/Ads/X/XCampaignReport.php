<?php

namespace App\Providers\Ads\X;

use App\Providers\Ads\AdObservation;
use App\Providers\Ads\NativeAmount;
use App\Providers\Ads\ObservationBatch;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadResult;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReportWindow;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Pure native request/mapping contract. No HTTP, credentials, grants or accepted progress. */
final readonly class XCampaignReport
{
    private const BASIS = 'https://docs.x.com/x-ads-api/analytics';

    public ReportWindow $window;

    public ReportWindow $businessWindow;

    public int $requestOffset;

    public string $requestDay;

    private array $ids;

    public function __construct(public ProviderScope $scope, ReportWindow $window, array $campaignIds, public string $placement, public bool $asynchronous = false,
        ?DateTimeImmutable $requestedAt = null, public string $timezoneSwitchAt = 'UNKNOWN')
    {
        if ($scope->provider !== Provider::X || $scope->operation !== 'report.read' || $scope->purpose !== 'advertising_analysis'
            || ! preg_match('/^[a-zA-Z0-9]{1,180}$/D', $scope->accountId) || $window->timezone === null || $window->grain !== 'TOTAL'
            || ! in_array($placement, ['ALL_ON_TWITTER', 'SPOTLIGHT', 'TREND'], true)
            || $window->boundary !== 'INCLUSIVE_DATES'
            || ! array_is_list($campaignIds) || count($campaignIds) < 1 || count($campaignIds) > 20) {
            throw new InvalidArgumentException('X campaign TOTAL requires an exact account, window, placement and bounded entity batch.');
        }
        foreach ($campaignIds as $id) {
            if (! is_string($id) || ! preg_match('/^[a-zA-Z0-9]{1,180}$/D', $id)) {
                throw new InvalidArgumentException('X entity identifiers must be bounded native strings.');
            }
        }
        if (count(array_unique($campaignIds, SORT_STRING)) !== count($campaignIds)) {
            throw new InvalidArgumentException('X campaign batch must not contain duplicate entities.');
        }
        sort($campaignIds, SORT_STRING);
        $this->ids = $campaignIds;
        $zone = new DateTimeZone($window->timezone);
        $requestedAt ??= CarbonImmutable::now()->toDateTimeImmutable();
        $this->requestDay = $requestedAt->setTimezone($zone)->format('Y-m-d');
        if ($timezoneSwitchAt !== 'NONE') {
            $switch = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $timezoneSwitchAt, new DateTimeZone('UTC'));
            if (! $switch || $switch->format('Y-m-d\TH:i:s\Z') !== $timezoneSwitchAt
                || $switch->setTimezone($zone)->format('H:i:s') !== '00:00:00'
                || $switch > $requestedAt || $window->start <= $switch->setTimezone($zone)->format('Y-m-d')) {
                throw new InvalidArgumentException('X timezone switch applicability is unverified.');
            }
        }
        // X requires the current day's offset for BOTH boundaries, even for historical dates.
        $this->requestOffset = $zone->getOffset($requestedAt);
        $utc = new DateTimeZone('UTC');
        $start = (new DateTimeImmutable($window->start.' 00:00:00', $utc))->modify(sprintf('%+d seconds', -$this->requestOffset));
        $end = (new DateTimeImmutable($window->end.' 00:00:00', $utc))->modify('+1 day')->modify(sprintf('%+d seconds', -$this->requestOffset));
        if ($end->getTimestamp() - $start->getTimestamp() > ($asynchronous ? 90 : 7) * 86400 || $start->format('i:s.u') !== '00:00.000000'
            || $end->format('i:s.u') !== '00:00.000000') {
            throw new InvalidArgumentException('X analytics requires whole hours and bounded native time ranges.');
        }
        // Placement is part of the measurement identity; native metrics from different placements never overwrite each other.
        $this->window = new ReportWindow($start->format('Y-m-d\TH:i:s\Z'), $end->format('Y-m-d\TH:i:s\Z'), 'HALF_OPEN_INSTANTS', 'TOTAL',
            $window->timezone, 'x:campaign:'.$placement.':'.hash('sha256', $window->semantics));
        $this->businessWindow = new ReportWindow($window->start, $window->end, 'INCLUSIVE_DATES', 'TOTAL', $window->timezone, $this->window->semantics);
    }

    public function requireCurrentTimezone(ProviderCredentials $credentials): void
    {
        $values = $credentials->custodyPayload();
        if (($values['account_timezone'] ?? null) !== $this->window->timezone
            || ($values['timezone_switch_at'] ?? 'UNKNOWN') !== $this->timezoneSwitchAt) {
            throw new InvalidArgumentException('X native timezone context changed before execution.');
        }
        $this->requireCurrentOffset();
    }

    public function requireCurrentOffset(): void
    {
        if ((new DateTimeZone($this->window->timezone))->getOffset(CarbonImmutable::now()->toDateTimeImmutable()) !== $this->requestOffset) {
            throw new InvalidArgumentException('X current-day offset changed before execution.');
        }
    }

    public function url(): string
    {
        return 'https://ads-api.x.com/12/stats/accounts/'.$this->scope->accountId;
    }

    public function query(): array
    {
        return ['entity' => 'CAMPAIGN', 'entity_ids' => implode(',', $this->ids), 'start_time' => $this->window->start,
            'end_time' => $this->window->end, 'granularity' => 'TOTAL', 'placement' => $this->placement, 'metric_groups' => 'BILLING,ENGAGEMENT'];
    }

    public function binding(): string
    {
        return hash('sha256', json_encode([$this->scope->jsonSerialize(), $this->businessWindow->jsonSerialize(), $this->window->jsonSerialize(),
            $this->query(), '12', $this->asynchronous, $this->timezoneSwitchAt], JSON_THROW_ON_ERROR));
    }

    public function map(array $body, XCampaignCurrency $currencies): ProviderReadResult
    {
        try {
            if (($body['data_type'] ?? null) !== 'stats' || ($body['time_series_length'] ?? null) !== 1 || ! empty($body['next_cursor'])) {
                throw new InvalidArgumentException;
            }
            $params = $body['request']['params'] ?? null;
            if (! is_array($params)) {
                throw new InvalidArgumentException;
            }
            foreach ($this->query() as $key => $value) {
                if (in_array($key, ['entity_ids', 'metric_groups'], true)) {
                    $actual = $params[$key] ?? null;
                    if (! is_array($actual) || ! array_is_list($actual)) {
                        throw new InvalidArgumentException;
                    }
                    foreach ($actual as $entry) {
                        if (! is_string($entry)) {
                            throw new InvalidArgumentException;
                        }
                    }
                    $expected = explode(',', $value);
                    sort($actual, SORT_STRING);
                    sort($expected, SORT_STRING);
                    if ($actual !== $expected) {
                        throw new InvalidArgumentException;
                    }
                } elseif (($params[$key] ?? null) !== $value) {
                    throw new InvalidArgumentException;
                }
            }
            foreach (['segmentation_type', 'country', 'platform'] as $key) {
                if (($params[$key] ?? null) !== null) {
                    throw new InvalidArgumentException;
                }
            }
            $rows = $body['data'] ?? null;
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 20) {
                throw new InvalidArgumentException;
            }
            $seen = [];
            $observations = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    throw new InvalidArgumentException;
                }
                $id = $row['id'] ?? null;
                if (! is_string($id) || ! in_array($id, $this->ids, true) || isset($seen[$id])) {
                    throw new InvalidArgumentException;
                }
                $seen[$id] = true;
                $data = $row['id_data'] ?? null;
                if (! is_array($data) || ! array_is_list($data) || count($data) !== 1 || ! is_array($data[0])
                    || ! array_key_exists('segment', $data[0]) || $data[0]['segment'] !== null || ! is_array($data[0]['metrics'] ?? null)) {
                    throw new InvalidArgumentException;
                }
                $metrics = $data[0]['metrics'];
                $facts = [];
                foreach (['impressions', 'clicks'] as $metric) {
                    $facts[$metric] = ['value' => $this->metric($metrics[$metric] ?? null), 'unit' => 'count',
                        'meaning' => 'X provider-reported '.$metric, 'basis' => 'PROVIDER_REPORTED'];
                }
                $facts['placement'] = ['value' => $this->placement, 'unit' => 'native_placement', 'meaning' => 'X native analytics placement', 'basis' => 'PROVIDER_REPORTED'];
                $money = $currencies->forCampaign($this, $id);
                $facts['funding_instrument_id'] = ['value' => $money['funding_instrument_id'], 'unit' => 'native_identifier',
                    'meaning' => 'X campaign funding instrument from native campaign metadata', 'basis' => 'PROVIDER_REPORTED'];
                $observations[] = new AdObservation($this->scope, $id, $this->businessWindow,
                    new NativeAmount($this->metric($metrics['billed_charge_local_micro'] ?? null), $money['currency'],
                        'x.billed_charge_local_micro', 6, 'https://docs.x.com/x-ads-api/fundamentals/currency'),
                    $facts, $this->provenance(), new DateTimeImmutable, null);
            }
            if (count($seen) !== count($this->ids)) {
                return $this->result([], 'PARTIAL', 'x_entities_incomplete');
            }

            return $this->result($observations, 'COMPLETE');
        } catch (InvalidArgumentException) {
            return $this->result([], 'FAILED', 'x_report_unverified');
        }
    }

    private function metric(mixed $series): ?string
    {
        if ($series === null) {
            return null;
        }
        if (! is_array($series) || ! array_is_list($series) || count($series) !== 1) {
            throw new InvalidArgumentException;
        }
        $value = $series[0];
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || ! preg_match('/^[0-9]{1,60}$/D', $value)) {
            throw new InvalidArgumentException;
        }

        return $value;
    }

    private function provenance(): array
    {
        return ['api_version' => '12', 'report' => 'X '.($this->asynchronous ? 'asynchronous' : 'synchronous').' campaign TOTAL '.$this->placement
            .'; business='.$this->businessWindow->start.'/'.$this->businessWindow->end.' '.$this->window->timezone
            .'; native=['.$this->window->start.','.$this->window->end.'); request_day='.$this->requestDay.'; current_offset='.$this->requestOffset.'; switch='.$this->timezoneSwitchAt,
            'documentation_basis' => self::BASIS.'; https://docs.x.com/x-ads-api/fundamentals/timezones; '.XCampaignCurrency::BASIS];
    }

    private function result(array $rows, string $coverage, ?string $failure = null): ProviderReadResult
    {
        return new ProviderReadResult(new ObservationBatch($this->scope, $rows, $coverage, null, $failure), $this->provenance(), null);
    }
}
