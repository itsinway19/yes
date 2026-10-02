<?php

namespace Tests\Unit;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReportWindow;
use App\Providers\Ads\X\XCampaignReport;
use App\Providers\Ads\X\XCampaignReportReader;
use App\Providers\Ads\X\XOAuthSigner;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;

final class XCampaignReportReaderTest extends TestCase
{
    private function fixture(): array
    {
        $report = new XCampaignReport(new ProviderScope(7, '11111111-1111-7111-8111-111111111111', Provider::X, 'abc123', 'report.read', 'advertising_analysis'),
            new ReportWindow('2026-09-01', '2026-09-01', 'INCLUSIVE_DATES', 'TOTAL', 'Asia/Amman', 'synthetic:native'), ['campaign1'], 'ALL_ON_TWITTER', false, null, 'NONE');
        $metadata = ['request' => ['params' => ['account_id' => 'abc123', 'campaign_ids' => ['campaign1']]], 'next_cursor' => null,
            'data' => [['id' => 'campaign1', 'currency' => 'JOD', 'funding_instrument_id' => 'fund1']]];
        $params = $report->query();
        $params['entity_ids'] = ['campaign1'];
        $params['metric_groups'] = ['BILLING', 'ENGAGEMENT'];
        $stats = ['request' => ['params' => $params], 'data_type' => 'stats', 'time_series_length' => 1,
            'data' => [['id' => 'campaign1', 'id_data' => [['segment' => null, 'metrics' => ['billed_charge_local_micro' => ['9007199254740993001'], 'clicks' => ['0']]]]]]];
        $credentials = new ProviderCredentials(['oauth_token' => 'synthetic-token', 'oauth_token_secret' => 'synthetic-secret', 'account_id' => 'abc123', 'account_timezone' => 'Asia/Amman', 'timezone_switch_at' => 'NONE']);
        $budget = new ProviderReadBudget($report->scope, 'stats', 2, 0, 2, 2, new DateTimeImmutable('+30 seconds'), 1, 5);

        return [$report, $metadata, $stats, $credentials, $budget];
    }

    public function test_currency_then_signed_stats_preserves_exact_money_and_explicit_zero(): void
    {
        [$report, $metadata, $stats, $credentials, $budget] = $this->fixture();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push($metadata)->push($stats)]);
        $calls = 0;
        $reader = new XCampaignReportReader($http, new XOAuthSigner('synthetic-key', 'synthetic-app-secret'), function ($scope, $action) use (&$calls, $report): void {
            $this->assertSame($report->scope, $scope);
            $this->assertSame('provider.read', $action);
            $calls++;
        });
        $result = $reader->read($report, $credentials, $budget);
        $this->assertSame(2, $calls);
        $this->assertSame('COMPLETE', $result->batch->coverage);
        $this->assertSame('9007199254740.993001', $result->batch->observations[0]->spend->majorUnits());
        $this->assertSame('JOD', $result->batch->observations[0]->spend->currency);
        $this->assertSame('0', $result->batch->observations[0]->nativeFacts['clicks']['value']);
        $this->assertNull($result->progress);
        $this->assertSame(2, $budget->usage()['pages']);
        $http->assertSentCount(2);
        $http->assertSent(fn ($r) => str_starts_with($r->url(), $report->url().'?') && $r->method() === 'GET'
            && str_starts_with($r->header('Authorization')[0], 'OAuth ') && ! str_contains($r->url(), 'synthetic-token'));
    }

    public function test_expired_authority_after_currency_prevents_stats_request(): void
    {
        [$report, $metadata, , $credentials, $budget] = $this->fixture();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response($metadata)]);
        $calls = 0;
        $reader = new XCampaignReportReader($http, new XOAuthSigner('synthetic-key', 'synthetic-app-secret'), function () use (&$calls): void {
            if (++$calls === 2) {
                throw new \RuntimeException('synthetic-expired');
            }
        });
        try {
            $reader->read($report, $credentials, $budget);
            $this->fail('Expired authority cannot fetch stats.');
        } catch (\RuntimeException $error) {
            $this->assertSame('synthetic-expired', $error->getMessage());
        }
        $http->assertSentCount(1);
    }

    public function test_incomplete_and_foreign_stats_never_return_observations(): void
    {
        foreach (['missing', 'foreign'] as $case) {
            [$report, $metadata, $stats, $credentials, $budget] = $this->fixture();
            if ($case === 'missing') {
                $stats['data'] = [];
            } else {
                $stats['data'][0]['id'] = 'foreign';
            }
            $http = new Factory;
            $http->preventStrayRequests();
            $http->fake(['*' => $http->sequence()->push($metadata)->push($stats)]);
            $result = (new XCampaignReportReader($http, new XOAuthSigner('synthetic-key', 'synthetic-app-secret'), static fn () => null))->read($report, $credentials, $budget);
            $this->assertSame($case === 'missing' ? 'PARTIAL' : 'FAILED', $result->batch->coverage);
            $this->assertSame([], $result->batch->observations);
            $this->assertNull($result->progress);
            $this->assertSame(1, $budget->usage()['pages']);
        }
    }

    public function test_native_denial_and_invalid_json_do_not_leak_private_response(): void
    {
        foreach ([403, 429, 200] as $status) {
            [$report, $metadata, , $credentials, $budget] = $this->fixture();
            $http = new Factory;
            $http->preventStrayRequests();
            $http->fake(['*' => $http->sequence()->push($metadata)->push('private-native-body', $status)]);
            try {
                (new XCampaignReportReader($http, new XOAuthSigner('synthetic-key', 'synthetic-app-secret'), static fn () => null))->read($report, $credentials, $budget);
                $this->fail('Invalid stats must fail.');
            } catch (\RuntimeException $error) {
                $this->assertSame(match ($status) {
                    403 => 'x_access_denied', 429 => 'x_rate_limited', default => 'x_report_unverified'
                }, $error->getMessage());
                $this->assertNull($error->getPrevious());
                $this->assertSame(1, $budget->usage()['pages']);
            }
        }
    }
}
