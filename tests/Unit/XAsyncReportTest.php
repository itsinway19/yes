<?php

namespace Tests\Unit;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReportWindow;
use App\Providers\Ads\X\XAsyncReport;
use App\Providers\Ads\X\XCampaignReport;
use App\Providers\Ads\X\XOAuthSigner;
use App\Providers\Ads\X\XRequestFailed;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;

final class XAsyncReportTest extends TestCase
{
    private function fixture(): array
    {
        $report = new XCampaignReport(new ProviderScope(7, '11111111-1111-7111-8111-111111111111', Provider::X, 'abc123', 'report.read', 'advertising_analysis'),
            new ReportWindow('2026-09-01', '2026-09-30', 'INCLUSIVE_DATES', 'TOTAL', 'Asia/Amman', 'synthetic:native'), ['campaign1'], 'ALL_ON_TWITTER', true, null, 'NONE');
        $row = $report->query();
        $row['entity_ids'] = ['campaign1'];
        $row['metric_groups'] = ['BILLING', 'ENGAGEMENT'];
        $row += ['account_id' => 'abc123', 'id_str' => '1120829647711653888', 'status' => 'PROCESSING', 'url' => null];
        $credentials = new ProviderCredentials(['oauth_token' => 'synthetic-token', 'oauth_token_secret' => 'synthetic-secret', 'account_id' => 'abc123', 'account_timezone' => 'Asia/Amman', 'timezone_switch_at' => 'NONE']);

        return [$report, $row, $credentials];
    }

    private function budget(XCampaignReport $report): ProviderReadBudget
    {
        return new ProviderReadBudget($report->scope, 'async-test', 3, 0, 3, 40, new DateTimeImmutable('+45 seconds'), 1, 5);
    }

    public function test_native_job_binding_and_gzip_download_accept_exact_campaign_money_without_sending_credentials_to_file_host(): void
    {
        [$report, $row, $credentials] = $this->fixture();
        $success = array_replace($row, ['status' => 'SUCCESS', 'url' => 'https://ton.twimg.com/advertiser-api-async-analytics/stats_job_'.$row['id_str'].'.json.gz']);
        $metadata = ['request' => ['params' => ['account_id' => 'abc123', 'campaign_ids' => ['campaign1']]], 'next_cursor' => null,
            'data' => [['id' => 'campaign1', 'currency' => 'JOD', 'funding_instrument_id' => 'fund1']]];
        $file = ['data_type' => 'stats', 'time_series_length' => 1,
            'data' => [['id' => 'campaign1', 'id_data' => [['segment' => null, 'metrics' => ['billed_charge_local_micro' => ['9007199254740993001']]]]]]];
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push(['data' => $row])->push(['data' => [$success]])->push($metadata)->push(gzencode(json_encode($file)))]);
        $reader = new XAsyncReport($http, new XOAuthSigner('synthetic-key', 'synthetic-app-secret'), static fn () => null);
        $pending = $reader->advance($report, $credentials, $this->budget($report), null);
        $this->assertNull($pending['result']);
        $this->assertSame('PROCESSING', $pending['ticket']['state']);
        $complete = $reader->advance($report, $credentials, $this->budget($report), $pending['ticket']);
        $this->assertNull($complete['ticket']);
        $this->assertSame('COMPLETE', $complete['result']->batch->coverage);
        $this->assertSame('JOD', $complete['result']->batch->observations[0]->spend->currency);
        $http->assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://ads-api.x.com/12/stats/jobs/accounts/abc123' && $r->hasHeader('Authorization'));
        $http->assertSent(fn ($r) => str_starts_with($r->url(), 'https://ton.twimg.com/') && ! $r->hasHeader('Authorization') && ! $r->hasHeader('Cookie'));
        $http->assertSentCount(4);
    }

    public function test_foreign_report_metadata_and_download_hosts_never_accept_observations(): void
    {
        [$report, $row, $credentials] = $this->fixture();
        foreach (['account_id' => 'foreign', 'placement' => 'TREND', 'entity_ids' => ['foreign'], 'url' => 'https://evil.example/file.gz'] as $key => $value) {
            $http = new Factory;
            $http->preventStrayRequests();
            $http->fake(['*' => $http->response(['data' => array_replace($row, [$key => $value])])]);
            try {
                (new XAsyncReport($http, new XOAuthSigner('synthetic-key', 'synthetic-secret'), static fn () => null))->advance($report, $credentials, $this->budget($report), null);
                $this->fail('Changed native job metadata must fail.');
            } catch (XRequestFailed) {
                $this->addToAssertionCount(1);
            }
            $http->assertSentCount(1);
        }
    }

    public function test_changed_ticket_is_rejected_before_native_http(): void
    {
        [$report, , $credentials] = $this->fixture();
        $http = new Factory;
        $http->preventStrayRequests();
        try {
            (new XAsyncReport($http, new XOAuthSigner('synthetic-key', 'synthetic-secret'), static fn () => null))->advance($report, $credentials, $this->budget($report),
                ['binding' => str_repeat('a', 64), 'job_id' => '123', 'state' => 'PROCESSING', 'url' => null]);
            $this->fail('Foreign ticket must fail.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $http->assertNothingSent();
    }

    public function test_missing_native_create_data_fails_with_a_safe_provider_reason(): void
    {
        [$report, , $credentials] = $this->fixture();
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response([])]);
        try {
            (new XAsyncReport($http, new XOAuthSigner('synthetic-key', 'synthetic-secret'), static fn () => null))->advance($report, $credentials, $this->budget($report), null);
            $this->fail('Missing native metadata must fail.');
        } catch (XRequestFailed $failure) {
            $this->assertSame('x_async_status_unverified', $failure->reason);
        }
        $http->assertSentCount(1);
    }

    public function test_redirects_invalid_gzip_and_decompression_overflow_fail_without_a_result(): void
    {
        [$report, $row, $credentials] = $this->fixture();
        $row = array_replace($row, ['status' => 'SUCCESS', 'url' => 'https://ton.twimg.com/advertiser-api-async-analytics/stats_job_'.$row['id_str'].'.json.gz']);
        $metadata = ['request' => ['params' => ['account_id' => 'abc123', 'campaign_ids' => ['campaign1']]], 'next_cursor' => null,
            'data' => [['id' => 'campaign1', 'currency' => 'JOD', 'funding_instrument_id' => 'fund1']]];
        foreach ([[302, ''], [200, 'not-gzip'], [200, gzencode(str_repeat('a', 4194305))]] as [$status, $bytes]) {
            $http = new Factory;
            $http->preventStrayRequests();
            $http->fake(['*' => $http->sequence()->push(['data' => $row])->push($metadata)->push($bytes, $status, ['Location' => 'https://evil.example/file'])]);
            try {
                (new XAsyncReport($http, new XOAuthSigner('synthetic-key', 'synthetic-secret'), static fn () => null))->advance($report, $credentials, $this->budget($report), null);
                $this->fail('Unsafe file must not produce observations.');
            } catch (XRequestFailed) {
                $this->addToAssertionCount(1);
            }
            $http->assertSentCount(3);
        }
    }
}
