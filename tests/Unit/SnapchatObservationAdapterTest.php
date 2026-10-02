<?php

namespace Tests\Unit;

use App\Providers\Ads\NativeProgress;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderReadResult;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReportWindow;
use App\Providers\Ads\Snapchat\SnapchatObservationAdapter;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SnapchatObservationAdapterTest extends TestCase
{
    private const ACCOUNT = '8b8e40af-fc64-455d-925b-ca80f7af6914';

    private const CAMPAIGN = '497979f0-ea17-4971-8288-054883f1caca';

    private function report(Factory $http, int $rowLimit = 100, ?NativeProgress $progress = null): ProviderReadResult
    {
        $scope = new ProviderScope(7, '11111111-1111-7111-8111-111111111111', Provider::Snapchat,
            self::ACCOUNT, 'report.read', 'advertising_analysis');

        return (new SnapchatObservationAdapter($http, static fn () => null))->read($scope,
            new ProviderCredentials(['access_token' => 'synthetic-token', 'account_id' => self::ACCOUNT,
                'account_currency' => 'USD', 'account_timezone' => 'America/Los_Angeles', 'expires_at' => (string) (time() + 300)]),
            new ReportWindow('2026-03-08', '2026-03-08', 'INCLUSIVE_DATES', 'TOTAL', 'America/Los_Angeles', 'synthetic:local_day'),
            new ProviderReadBudget($scope, 'snapchat-test', 2, 0, 2, $rowLimit, new DateTimeImmutable('+30 seconds'), 1, 5), $progress);
    }

    private function row(): array
    {
        return ['id' => self::CAMPAIGN, 'type' => 'CAMPAIGN', 'granularity' => 'TOTAL',
            'stats' => ['spend' => '9007199254740993001', 'impressions' => 0, 'swipes' => 7]];
    }

    private function payload(?array $rows = null): array
    {
        return ['request_status' => 'SUCCESS', 'total_stats' => [['sub_request_status' => 'success', 'total_stat' => [
            'id' => self::ACCOUNT, 'type' => 'AD_ACCOUNT', 'granularity' => 'TOTAL',
            'start_time' => '2026-03-08T00:00:00.000-08:00', 'end_time' => '2026-03-09T00:00:00.000-07:00',
            'finalized_data_end_time' => '2026-03-08T23:00:00.000-07:00',
            'breakdown_stats' => ['campaign' => $rows ?? [$this->row()]],
        ]]]];
    }

    public function test_total_campaign_report_preserves_dst_boundaries_integer_spend_and_native_swipes(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response($this->payload())]);
        $result = $this->report($http);
        $this->assertSame('COMPLETE', $result->batch->coverage);
        $row = $result->batch->observations[0];
        $this->assertSame('9007199254740993001', $row->spend->value);
        $this->assertSame('9007199254740.993001', $row->spend->majorUnits());
        $this->assertSame(6, $row->spend->decimalScale);
        $this->assertSame('https://developers.snap.com/marketing-api/Ads-API/monetary-values', $row->spend->scaleBasis);
        $this->assertSame('0', $row->nativeFacts['impressions']['value']);
        $this->assertSame('PROVIDER_REPORTED', $row->nativeFacts['impressions']['basis']);
        $this->assertSame('7', $row->nativeFacts['swipes']['value']);
        $this->assertArrayNotHasKey('clicks', $row->nativeFacts);
        $this->assertSame('HALF_OPEN_INSTANTS', $row->window->boundary);
        $this->assertSame('2026-03-08T00:00:00-08:00', $row->window->start);
        $this->assertSame('2026-03-09T00:00:00-07:00', $row->window->end);
        $this->assertNull($row->freshUntil);
        $this->assertNotNull($result->progress);
        $http->assertSent(fn ($r) => $r['start_time'] === $row->window->start && $r['end_time'] === $row->window->end
            && $r['fields'] === 'impressions,swipes,spend' && $r['breakdown'] === 'campaign' && $r->method() === 'GET');
        $repeated = $this->report($http, 100, $result->progress);
        $this->assertSame($row->identity(), $repeated->batch->observations[0]->identity());
        $this->assertSame($row->contentHash(), $repeated->batch->observations[0]->contentHash());
    }

    public function test_duplicate_campaigns_and_foreign_account_or_range_never_advance_progress(): void
    {
        $duplicate = $this->payload([$this->row(), $this->row()]);
        $foreign = $this->payload();
        $foreign['total_stats'][0]['total_stat']['id'] = self::CAMPAIGN;
        $wrongRange = $this->payload();
        $wrongRange['total_stats'][0]['total_stat']['end_time'] = '2026-03-10T00:00:00-07:00';
        foreach ([$duplicate, $foreign, $wrongRange] as $payload) {
            $http = new Factory;
            $http->preventStrayRequests();
            $http->fake(['*' => $http->response($payload)]);
            $result = $this->report($http);
            $this->assertSame('FAILED', $result->batch->coverage);
            $this->assertSame([], $result->batch->observations);
            $this->assertNull($result->progress);
        }
    }

    public function test_missing_metrics_are_unknown_and_empty_campaigns_do_not_fabricate_zero_rows(): void
    {
        $row = $this->row();
        unset($row['stats']['spend'], $row['stats']['swipes']);
        $http = new Factory;
        $http->fake(['*' => $http->response($this->payload([$row]))]);
        $result = $this->report($http);
        $this->assertNull($result->batch->observations[0]->spend->value);
        $this->assertNull($result->batch->observations[0]->nativeFacts['swipes']['value']);
        $this->assertSame('UNKNOWN', $result->batch->observations[0]->nativeFacts['swipes']['basis']);
        $http = new Factory;
        $http->fake(['*' => $http->response($this->payload([]))]);
        $empty = $this->report($http);
        $this->assertSame('COMPLETE', $empty->batch->coverage);
        $this->assertSame([], $empty->batch->observations);
    }

    public function test_partial_native_response_or_budget_exhaustion_does_not_accept_a_range(): void
    {
        $paging = $this->payload();
        $paging['paging'] = ['next_link' => 'https://evil.example/private'];
        $nestedPaging = $this->payload();
        $nestedPaging['total_stats'][0]['total_stat']['paging'] = ['next_link' => 'https://evil.example/private'];
        $subrequest = $this->payload();
        $subrequest['total_stats'][0]['sub_request_status'] = 'ERROR';
        foreach ([$paging, $nestedPaging, $subrequest] as $payload) {
            $http = new Factory;
            $http->preventStrayRequests();
            $http->fake(['*' => $http->response($payload)]);
            $result = $this->report($http);
            $this->assertSame('PARTIAL', $result->batch->coverage);
            $this->assertSame([], $result->batch->observations);
            $this->assertNull($result->progress);
            $http->assertSentCount(1);
        }
        $row = $this->row();
        $row['id'] = '22ada972-f2aa-4d06-a45a-a7a80f53ae34';
        $http = new Factory;
        $http->fake(['*' => $http->response($this->payload([$this->row(), $row]))]);
        $limited = $this->report($http, 1);
        $this->assertSame('PARTIAL', $limited->batch->coverage);
        $this->assertNull($limited->progress);
    }

    public function test_native_float_metrics_are_rejected_instead_of_rounding(): void
    {
        $row = $this->row();
        $row['stats']['spend'] = 1.1;
        $http = new Factory;
        $http->fake(['*' => $http->response($this->payload([$row]))]);
        $result = $this->report($http);
        $this->assertSame('snapchat_metric_unverified', $result->batch->failure);
        $this->assertSame([], $result->batch->observations);
        $this->assertNull($result->progress);
    }

    public function test_native_json_integer_beyond_php_range_is_preserved_without_float_conversion(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $raw = str_replace('"9007199254740993001"', '90071992547409930012345', json_encode($this->payload(), JSON_THROW_ON_ERROR));
        $http->fake(['*' => $http->response($raw, 200, ['Content-Type' => 'application/json'])]);
        $result = $this->report($http);
        $this->assertSame('COMPLETE', $result->batch->coverage);
        $this->assertSame('90071992547409930012345', $result->batch->observations[0]->spend->value);
    }

    public function test_foreign_accepted_range_fails_before_any_native_request(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->report($http, 100, new NativeProgress('snapchat.accepted_range',
                'https://developers.snap.com/api/marketing-api/Ads-API/measurement', '{"binding":"foreign"}'));
        } finally {
            $http->assertNothingSent();
        }
    }

    private function nextLink(string $cursor = 'synthetic-page-2', array $overrides = []): string
    {
        return 'https://adsapi.snapchat.com/v1/adaccounts/'.self::ACCOUNT.'/stats?'.http_build_query(array_replace([
            'granularity' => 'TOTAL', 'breakdown' => 'campaign', 'fields' => 'impressions,swipes,spend',
            'start_time' => '2026-03-08T00:00:00-08:00', 'end_time' => '2026-03-09T00:00:00-07:00',
            'limit' => '200', 'cursor' => $cursor,
        ], $overrides), '', '&', PHP_QUERY_RFC3986);
    }

    public function test_campaign_pages_are_bound_and_accepted_only_after_the_final_page(): void
    {
        $first = $this->payload();
        $first['paging'] = ['next_link' => $this->nextLink()];
        $row = $this->row();
        $row['id'] = '22ada972-f2aa-4d06-a45a-a7a80f53ae34';
        $row['stats']['spend'] = '500000';
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push($first)->push($this->payload([$row]))]);
        $result = $this->report($http);
        $this->assertSame('COMPLETE', $result->batch->coverage);
        $this->assertCount(2, $result->batch->observations);
        $this->assertSame('0.5', $result->batch->observations[1]->spend->majorUnits());
        $this->assertNotNull($result->progress);
        $http->assertSent(fn ($r) => ($r->data()['cursor'] ?? null) === 'synthetic-page-2' && $r['limit'] === '200'
            && $r['end_time'] === '2026-03-09T00:00:00-07:00' && $r->method() === 'GET');
        $http->assertSentCount(2);
    }

    public function test_untrusted_or_changed_page_links_never_send_another_request(): void
    {
        $valid = $this->nextLink();
        $links = [str_replace('adsapi.snapchat.com', 'evil.example', $valid),
            str_replace('https://', 'http://', $valid), str_replace('/'.self::ACCOUNT.'/', '/'.self::CAMPAIGN.'/', $valid),
            str_replace('adsapi.snapchat.com', 'adsapi.snapchat.com:443', $valid),
            str_replace('adsapi.snapchat.com', 'user@adsapi.snapchat.com', $valid),
            $valid.'#fragment', $valid.'&cursor=duplicate', $valid.'&unexpected=true',
            $this->nextLink('next', ['fields' => 'impressions']), $this->nextLink('next', ['limit' => '201']),
            $this->nextLink('next', ['end_time' => '2026-03-10T00:00:00-07:00']),
            $this->nextLink("bad\ncontrol"), $valid.'&cursor%5B%5D=value'];
        foreach ($links as $link) {
            $first = $this->payload();
            $first['paging'] = ['next_link' => $link];
            $http = new Factory;
            $http->preventStrayRequests();
            $http->fake(['*' => $http->response($first)]);
            $result = $this->report($http);
            $this->assertSame('PARTIAL', $result->batch->coverage);
            $this->assertSame([], $result->batch->observations);
            $this->assertNull($result->progress);
            $http->assertSentCount(1);
        }
    }

    public function test_failed_or_duplicate_second_page_discards_first_page_without_progress(): void
    {
        foreach (['failed', 'duplicate', 'cycle', 'budget'] as $case) {
            $first = $this->payload();
            $first['paging'] = ['next_link' => $this->nextLink()];
            $secondRow = $this->row();
            if ($case !== 'duplicate') {
                $secondRow['id'] = '22ada972-f2aa-4d06-a45a-a7a80f53ae34';
            }
            $second = $this->payload([$secondRow]);
            if ($case === 'cycle') {
                $second['paging'] = ['next_link' => $this->nextLink()];
            }
            $http = new Factory;
            $http->preventStrayRequests();
            $http->fake(['*' => $http->sequence()->push($first)->push($case === 'failed' ? 'unavailable' : $second, $case === 'failed' ? 503 : 200)]);
            $result = $this->report($http, $case === 'budget' ? 1 : 100);
            $this->assertSame(in_array($case, ['cycle', 'budget'], true) ? 'PARTIAL' : 'FAILED', $result->batch->coverage);
            $this->assertSame([], $result->batch->observations);
            $this->assertNull($result->progress);
            $http->assertSentCount(2);
        }
    }

    public function test_native_large_report_error_is_explicit_and_does_not_advance_or_retry(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['request_status' => 'ERROR', 'error_code' => 'E9001',
            'display_message' => 'synthetic-private-size-message'], 400)]);
        $result = $this->report($http);
        $this->assertSame('snapchat_async_report_required', $result->batch->failure);
        $this->assertSame([], $result->batch->observations);
        $this->assertNull($result->progress);
        $http->assertSentCount(1);
    }
}
