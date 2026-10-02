<?php

namespace Tests\Unit;

use App\Providers\Ads\NativeProgress;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderReadResult;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReportWindow;
use App\Providers\Ads\TikTok\TikTokAccessAdapter;
use App\Providers\Ads\TikTok\TikTokObservationAdapter;
use App\Providers\Ads\TikTok\TikTokRequestFailed;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;

final class TikTokAdapterTest extends TestCase
{
    private function scope(string $operation = 'report.read'): ProviderScope
    {
        return new ProviderScope(7, '11111111-1111-7111-8111-111111111111', Provider::TikTok, '1234567890123456789', $operation, 'advertising_analysis');
    }

    private function credentials(): ProviderCredentials
    {
        return new ProviderCredentials(['access_token' => 'synthetic-private-token', 'app_id' => '5555555555555555555', 'token_kind' => 'ADVERTISER',
            'account_id' => '1234567890123456789', 'account_currency' => 'JOD', 'account_timezone' => 'Asia/Amman']);
    }

    public function test_advertiser_acquisition_does_not_invent_creator_expiry_or_refresh_tokens(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['code' => 0, 'data' => ['access_token' => 'synthetic-native-token']])]);
        $adapter = new TikTokAccessAdapter($http, 'v1.3', '5555555555555555555', 'synthetic-secret', 'https://builderad.example/providers/tiktok/callback',
            'https://business-api.tiktok.com/portal/auth', static fn () => null);
        $material = $adapter->acquire($this->scope('discovery.read'), new ProviderCredentials(['code' => 'synthetic-code']), '{}');
        $this->assertNull($material->expiresAt);
        $this->assertArrayNotHasKey('refresh_token', $material->credentials->custodyPayload());
        $this->assertSame('UNKNOWN', $material->verification['privacy_purpose']['state']);
        $this->assertSame('UNKNOWN', $material->verification['provider_permission']['state']);
        $http->assertSent(fn ($r) => $r->url() === 'https://business-api.tiktok.com/open_api/v1.3/oauth2/access_token/' && $r['auth_code'] === 'synthetic-code');
        $this->assertStringNotContainsString('synthetic-secret', $adapter->authorizationUrl(str_repeat('a', 43)));
    }

    public function test_malformed_account_currency_is_a_safe_native_failure_before_report_probe(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response(['code' => 0, 'data' => ['list' => [[
            'advertiser_id' => $this->scope()->accountId, 'currency' => ['unexpected'], 'timezone' => 'Asia/Amman',
        ]]]])]);
        $adapter = new TikTokAccessAdapter($http, 'v1.3', '5555555555555555555', 'synthetic-secret', 'https://builderad.example/providers/tiktok/callback',
            'https://business-api.tiktok.com/portal/auth', static fn () => null);
        try {
            $adapter->verifySelection($this->scope(), $this->credentials(), json_encode(['account_id' => $this->scope()->accountId], JSON_THROW_ON_ERROR));
            $this->fail('Malformed currency must not establish native account context.');
        } catch (TikTokRequestFailed $failure) {
            $this->assertSame('tiktok_account_metadata_unverified', $failure->reason);
        }
        $http->assertSentCount(1);
    }

    private function report(Factory $http, ?NativeProgress $progress = null): ProviderReadResult
    {
        $scope = $this->scope();

        return (new TikTokObservationAdapter($http, 'v1.3', static fn () => null))->read($scope, $this->credentials(),
            new ReportWindow('2026-09-01', '2026-09-01', 'INCLUSIVE_DATES', 'TOTAL', 'Asia/Amman', 'synthetic:native'),
            new ProviderReadBudget($scope, 'tiktok-test', 4, 0, 4, 100, new DateTimeImmutable('+30 seconds'), 1, 5), $progress);
    }

    private function page(array $rows, int $page = 1, int $total = 1): array
    {
        return ['code' => 0, 'data' => ['list' => $rows, 'page_info' => ['page' => $page, 'total_page' => $total]]];
    }

    private function row(): array
    {
        return ['dimensions' => ['campaign_id' => '1234567890123456789'], 'metrics' => ['spend' => '9007199254740993.001', 'impressions' => '10', 'clicks' => '0']];
    }

    public function test_report_preserves_exact_native_decimal_currency_zero_and_range_for_restatement(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response($this->page([$this->row()]))]);
        $result = $this->report($http);
        $this->assertSame('COMPLETE', $result->batch->coverage);
        $this->assertSame('9007199254740993.001', $result->batch->observations[0]->spend->value);
        $this->assertSame('JOD', $result->batch->observations[0]->spend->currency);
        $this->assertNull($result->batch->observations[0]->spend->majorUnits());
        $this->assertSame('0', $result->batch->observations[0]->nativeFacts['clicks']['value']);
        $this->assertSame('COMPLETE', $this->report($http, $result->progress)->batch->coverage);
        $http->assertSentCount(2);
    }

    public function test_duplicate_rows_fail_entire_range_without_progress(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push($this->page([$this->row()], 1, 2))->push($this->page([$this->row()], 2, 2))]);
        $result = $this->report($http);
        $this->assertSame('FAILED', $result->batch->coverage);
        $this->assertSame('tiktok_duplicate_report_row', $result->batch->failure);
        $this->assertNull($result->progress);
        $this->assertSame([], $result->batch->observations);
    }

    public function test_float_spend_is_rejected_instead_of_silently_losing_precision(): void
    {
        $row = $this->row();
        $row['metrics']['spend'] = 1.01;
        $http = new Factory;
        $http->fake(['*' => $http->response($this->page([$row]))]);
        $this->assertSame('tiktok_metric_unverified', $this->report($http)->batch->failure);
    }

    public function test_malformed_native_json_is_not_misclassified_as_retryable_transport(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response('{broken', 200)]);
        $result = $this->report($http);
        $this->assertSame('FAILED', $result->batch->coverage);
        $this->assertSame('tiktok_malformed_response', $result->batch->failure);
        $this->assertSame([], $result->batch->observations);
        $this->assertNull($result->progress);
        $http->assertSentCount(1);
    }

    public function test_empty_report_does_not_fabricate_campaign_zero(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->response($this->page([], 1, 0))]);
        $result = $this->report($http);
        $this->assertSame('COMPLETE', $result->batch->coverage);
        $this->assertSame([], $result->batch->observations);
    }
}
