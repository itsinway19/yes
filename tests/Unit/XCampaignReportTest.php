<?php

namespace Tests\Unit;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReportWindow;
use App\Providers\Ads\X\XCampaignCurrency;
use App\Providers\Ads\X\XCampaignReport;
use PHPUnit\Framework\TestCase;

final class XCampaignReportTest extends TestCase
{
    private function request(string $placement = 'ALL_ON_TWITTER', array $ids = ['campaign1']): XCampaignReport
    {
        return new XCampaignReport(new ProviderScope(7, '11111111-1111-7111-8111-111111111111', Provider::X,
            '18ce54d4x5t', 'report.read', 'advertising_analysis'),
            new ReportWindow('2026-03-08', '2026-03-08', 'INCLUSIVE_DATES', 'TOTAL', 'America/Los_Angeles', 'synthetic:native'), $ids, $placement, false, new \DateTimeImmutable('2026-07-01T12:00:00Z'), 'NONE');
    }

    private function payload(XCampaignReport $request): array
    {
        $params = $request->query();
        $params['entity_ids'] = explode(',', $params['entity_ids']);
        $params['metric_groups'] = explode(',', $params['metric_groups']);

        return ['data_type' => 'stats', 'time_series_length' => 1, 'request' => ['params' => $params],
            'data' => [['id' => 'campaign1', 'id_data' => [['segment' => null, 'metrics' => [
                'impressions' => [0], 'clicks' => null, 'billed_charge_local_micro' => ['9007199254740993001'],
            ]]]]]];
    }

    private function currency(XCampaignReport $request, string $currency): XCampaignCurrency
    {
        $ids = explode(',', $request->query()['entity_ids']);

        return new XCampaignCurrency($request, ['request' => ['params' => ['account_id' => $request->scope->accountId, 'campaign_ids' => $ids]],
            'next_cursor' => null, 'data' => array_map(fn ($id) => ['id' => $id, 'currency' => $currency, 'funding_instrument_id' => 'fund1'], $ids)]);
    }

    public function test_native_query_preserves_exclusive_dst_window_and_one_placement(): void
    {
        $request = $this->request();
        $this->assertSame('https://ads-api.x.com/12/stats/accounts/18ce54d4x5t', $request->url());
        $this->assertSame('2026-03-08T07:00:00Z', $request->query()['start_time']);
        $this->assertSame('2026-03-09T07:00:00Z', $request->query()['end_time']);
        $this->assertSame('CAMPAIGN', $request->query()['entity']);
        $this->assertSame('BILLING,ENGAGEMENT', $request->query()['metric_groups']);
        $this->assertSame('ALL_ON_TWITTER', $request->query()['placement']);
        $this->assertNotSame($request->binding(), $this->request('SPOTLIGHT')->binding());
    }

    public function test_native_total_mapping_preserves_exact_money_zero_and_unknown_metrics(): void
    {
        $request = $this->request();
        $result = $request->map($this->payload($request), $this->currency($request, 'JOD'));
        $this->assertSame('COMPLETE', $result->batch->coverage);
        $row = $result->batch->observations[0];
        $this->assertSame('9007199254740993001', $row->spend->value);
        $this->assertSame('9007199254740.993001', $row->spend->majorUnits());
        $this->assertSame('0', $row->nativeFacts['impressions']['value']);
        $this->assertNull($row->nativeFacts['clicks']['value']);
        $this->assertSame('ALL_ON_TWITTER', $row->nativeFacts['placement']['value']);
        $this->assertNull($row->freshUntil);
        $this->assertNull($result->progress);
        $other = $this->request('SPOTLIGHT');
        $this->assertNotSame($row->identity(), $other->map($this->payload($other), $this->currency($other, 'JOD'))->batch->observations[0]->identity());
    }

    public function test_changed_echo_segments_duplicate_rows_or_float_metrics_never_produce_observations(): void
    {
        $request = $this->request();
        foreach (['placement', 'time', 'segment', 'duplicate', 'float', 'array', 'foreign', 'malformed-row', 'malformed-echo'] as $case) {
            $payload = $this->payload($request);
            if ($case === 'placement') {
                $payload['request']['params']['placement'] = 'TREND';
            }
            if ($case === 'time') {
                $payload['request']['params']['end_time'] = '2026-03-10T07:00:00Z';
            }
            if ($case === 'segment') {
                $payload['data'][0]['id_data'][0]['segment'] = ['country' => 'SA'];
            }
            if ($case === 'duplicate') {
                $payload['data'][] = $payload['data'][0];
            }
            if ($case === 'float') {
                $payload['data'][0]['id_data'][0]['metrics']['billed_charge_local_micro'] = [1.5];
            }
            if ($case === 'array') {
                $payload['data'][0]['id_data'][0]['metrics']['impressions'] = [1, 2];
            }
            if ($case === 'foreign') {
                $payload['data'][0]['id'] = 'other';
            }
            if ($case === 'malformed-row') {
                $payload['data'][0] = 'not-an-object';
            }
            if ($case === 'malformed-echo') {
                $payload['request']['params']['entity_ids'] = [['injected']];
            }
            $result = $request->map($payload, $this->currency($request, 'SAR'));
            $this->assertNotSame('COMPLETE', $result->batch->coverage);
            $this->assertSame([], $result->batch->observations);
            $this->assertNull($result->progress);
        }
    }

    public function test_missing_entities_are_partial_and_never_fabricated_as_zero(): void
    {
        $request = $this->request('ALL_ON_TWITTER', ['campaign1', 'campaign2']);
        $result = $request->map($this->payload($request), $this->currency($request, 'USD'));
        $this->assertSame('PARTIAL', $result->batch->coverage);
        $this->assertSame([], $result->batch->observations);
    }

    public function test_native_batch_and_synchronous_time_limits_are_enforced(): void
    {
        foreach ([array_fill(0, 21, 'campaign1'), ['campaign1', 'campaign1'], []] as $ids) {
            try {
                $this->request('ALL_ON_TWITTER', $ids);
                $this->fail('Invalid entity list must be rejected.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $scope = new ProviderScope(7, '11111111-1111-7111-8111-111111111111', Provider::X, '18ce54d4x5t', 'report.read', 'advertising_analysis');
        $this->expectException(\InvalidArgumentException::class);
        new XCampaignReport($scope, new ReportWindow('2026-09-01', '2026-09-08', 'INCLUSIVE_DATES', 'TOTAL', 'Asia/Amman', 'synthetic:native'), ['campaign1'], 'ALL_ON_TWITTER', false, null, 'NONE');
    }
}
