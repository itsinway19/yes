<?php

namespace Tests\Unit;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReportWindow;
use App\Providers\Ads\X\XCampaignCurrency;
use App\Providers\Ads\X\XCampaignReport;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class XNativeWindowTest extends TestCase
{
    private function report(string $start, string $end, string $now, string $switch = 'NONE', string $grain = 'TOTAL'): XCampaignReport
    {
        return new XCampaignReport(new ProviderScope(7, '11111111-1111-7111-8111-111111111111', Provider::X, 'abc123', 'report.read', 'advertising_analysis'),
            new ReportWindow($start, $end, 'INCLUSIVE_DATES', $grain, 'America/Los_Angeles', 'synthetic:native'),
            ['campaign1'], 'ALL_ON_TWITTER', false, new DateTimeImmutable($now), $switch);
    }

    public function test_historical_summer_uses_current_winter_offset_and_business_dates_remain_separate(): void
    {
        $report = $this->report('2026-07-05', '2026-07-05', '2026-12-01T12:00:00Z');
        $this->assertSame('2026-07-05T08:00:00Z', $report->query()['start_time']);
        $this->assertSame('2026-07-06T08:00:00Z', $report->query()['end_time']);
        $this->assertSame('2026-07-05', $report->businessWindow->start);
        $this->assertSame('INCLUSIVE_DATES', $report->businessWindow->boundary);
    }

    public function test_dst_transition_dates_use_one_current_offset_and_exclusive_end(): void
    {
        foreach (['2026-03-08', '2026-11-01'] as $day) {
            $report = $this->report($day, $day, '2026-12-01T12:00:00Z');
            $this->assertSame(86400, strtotime($report->window->end) - strtotime($report->window->start));
            $this->assertSame($day.'T08:00:00Z', $report->window->start);
        }
    }

    public function test_established_switch_before_range_is_recorded(): void
    {
        $report = $this->report('2026-07-05', '2026-07-05', '2026-12-01T12:00:00Z', '2026-01-01T08:00:00Z');
        $this->assertSame('2026-01-01T08:00:00Z', $report->timezoneSwitchAt);
    }

    public function test_business_observation_identity_survives_current_offset_change_with_native_provenance_preserved(): void
    {
        $rows = [];
        foreach (['2026-07-01T12:00:00Z', '2026-12-01T12:00:00Z'] as $now) {
            $report = $this->report('2026-01-05', '2026-01-05', $now);
            $params = $report->query();
            $params['entity_ids'] = ['campaign1'];
            $params['metric_groups'] = ['BILLING', 'ENGAGEMENT'];
            $currency = new XCampaignCurrency($report, ['request' => ['params' => ['account_id' => 'abc123', 'campaign_ids' => ['campaign1']]],
                'next_cursor' => null,
                'data' => [['id' => 'campaign1', 'currency' => 'JOD', 'funding_instrument_id' => 'fund1']]]);
            $result = $report->map(['data_type' => 'stats', 'time_series_length' => 1, 'request' => ['params' => $params],
                'data' => [['id' => 'campaign1', 'id_data' => [['segment' => null, 'metrics' => ['billed_charge_local_micro' => ['0']]]]]]], $currency);
            $this->assertSame('COMPLETE', $result->batch->coverage);
            $this->assertStringContainsString('native=['.$report->window->start, $result->readProvenance['report']);
            $this->assertStringContainsString('switch=NONE', $result->readProvenance['report']);
            $rows[] = $result->batch->observations[0];
        }
        $this->assertSame($rows[0]->identity(), $rows[1]->identity());
        $this->assertNotSame($rows[0]->provenance, $rows[1]->provenance);
    }

    public function test_queued_report_rejects_changed_current_offset_or_switch_metadata(): void
    {
        $report = $this->report('2026-01-05', '2026-01-05', '2026-07-01T12:00:00Z');
        $credentials = new ProviderCredentials(['account_timezone' => 'America/Los_Angeles', 'timezone_switch_at' => 'NONE']);
        CarbonImmutable::setTestNow('2026-12-01T12:00:00Z');
        try {
            $report->requireCurrentTimezone($credentials);
            $this->fail('Queued request cannot keep an obsolete current-day offset.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_unknown_inside_or_range_before_switch_is_rejected_and_day_remains_unsupported(): void
    {
        foreach ([['UNKNOWN', 'TOTAL'], ['2026-07-05T07:00:00Z', 'TOTAL'], ['2026-08-05T07:00:00Z', 'TOTAL'], ['NONE', 'DAY']] as [$switch, $grain]) {
            try {
                $this->report('2026-07-05', '2026-07-06', '2026-12-01T12:00:00Z', $switch, $grain);
                $this->fail('Unestablished switch or unsupported grain cannot produce native boundaries.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_historical_winter_uses_review_days_summer_offset(): void
    {
        $scope = new ProviderScope(7, '11111111-1111-7111-8111-111111111111', Provider::X,
            'abc123', 'report.read', 'advertising_analysis');
        $report = new XCampaignReport($scope,
            new ReportWindow('2026-01-05', '2026-01-05', 'INCLUSIVE_DATES', 'TOTAL', 'America/Los_Angeles', 'synthetic:native'),
            ['campaign1'], 'ALL_ON_TWITTER', false, new DateTimeImmutable('2026-07-01T12:00:00Z'), 'NONE');
        $this->assertSame('2026-01-05T07:00:00Z', $report->query()['start_time']);
        $this->assertSame('2026-01-06T07:00:00Z', $report->query()['end_time']);
    }
}
