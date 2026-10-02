<?php

namespace Tests\Unit;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReportWindow;
use App\Providers\Ads\X\XCampaignCurrency;
use App\Providers\Ads\X\XCampaignCurrencyReader;
use App\Providers\Ads\X\XCampaignReport;
use App\Providers\Ads\X\XOAuthSigner;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;

final class XCampaignCurrencyTest extends TestCase
{
    private function report(string $account = 'abc123'): XCampaignReport
    {
        return new XCampaignReport(new ProviderScope(7, '11111111-1111-7111-8111-111111111111', Provider::X, $account, 'report.read', 'advertising_analysis'),
            new ReportWindow('2026-09-01', '2026-09-01', 'INCLUSIVE_DATES', 'TOTAL', 'Asia/Amman', 'synthetic:native'), ['campaign1', 'campaign2'], 'ALL_ON_TWITTER', false, null, 'NONE');
    }

    private function metadata(): array
    {
        return ['request' => ['params' => ['account_id' => 'abc123', 'campaign_ids' => ['campaign1', 'campaign2']]], 'next_cursor' => null,
            'data' => [['id' => 'campaign1', 'currency' => 'SAR', 'funding_instrument_id' => 'fund1', 'deleted' => true],
                ['id' => 'campaign2', 'currency' => 'JOD', 'funding_instrument_id' => 'fund2']]];
    }

    public function test_each_campaign_retains_native_currency_funding_identity_and_exact_micros(): void
    {
        $report = $this->report();
        $money = new XCampaignCurrency($report, $this->metadata());
        $params = $report->query();
        $params['entity_ids'] = ['campaign1', 'campaign2'];
        $params['metric_groups'] = ['BILLING', 'ENGAGEMENT'];
        $body = ['request' => ['params' => $params], 'data_type' => 'stats', 'time_series_length' => 1,
            'data' => array_map(fn ($id) => ['id' => $id, 'id_data' => [['segment' => null, 'metrics' => ['billed_charge_local_micro' => ['9007199254740993001']]]]], ['campaign1', 'campaign2'])];
        $result = $report->map($body, $money);
        $this->assertSame('COMPLETE', $result->batch->coverage);
        $this->assertSame('SAR', $result->batch->observations[0]->spend->currency);
        $this->assertSame('JOD', $result->batch->observations[1]->spend->currency);
        $this->assertSame('9007199254740.993001', $result->batch->observations[1]->spend->majorUnits());
        $this->assertSame('fund2', $result->batch->observations[1]->nativeFacts['funding_instrument_id']['value']);
        $this->assertStringContainsString(XCampaignCurrency::BASIS, $result->batch->observations[0]->provenance['documentation_basis']);
        $this->assertNull($result->progress);
        $foreign = $this->report('foreign')->map($body, $money);
        $this->assertSame('FAILED', $foreign->batch->coverage);
        $this->assertSame([], $foreign->batch->observations);
        try {
            $money->forCampaign($this->report('foreign'), 'campaign1');
            $this->fail('Foreign report binding must fail.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_missing_duplicate_foreign_conflicting_and_paginated_metadata_are_not_currency_proof(): void
    {
        foreach (['missing', 'duplicate', 'foreign', 'conflict', 'cursor', 'currency', 'funding', 'echo'] as $case) {
            $body = $this->metadata();
            if ($case === 'missing') {
                array_pop($body['data']);
            }
            if ($case === 'duplicate') {
                $body['data'][1]['id'] = 'campaign1';
            }
            if ($case === 'foreign') {
                $body['request']['params']['account_id'] = 'foreign';
            }
            if ($case === 'conflict') {
                $body['data'][1]['funding_instrument_id'] = 'fund1';
            }
            if ($case === 'cursor') {
                $body['next_cursor'] = 'incomplete-next';
            }
            if ($case === 'currency') {
                $body['data'][0]['currency'] = null;
            }
            if ($case === 'funding') {
                unset($body['data'][0]['funding_instrument_id']);
            }
            if ($case === 'echo') {
                $body['request']['params']['campaign_ids'] = ['foreign'];
            }
            try {
                new XCampaignCurrency($this->report(), $body);
                $this->fail('Unverified currency must fail.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_signed_lookup_rechecks_read_authority_and_requests_no_financial_endpoint(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response($this->metadata())]);
        $report = $this->report();
        $tokens = new ProviderCredentials(['oauth_token' => 'synthetic-token', 'oauth_token_secret' => 'synthetic-secret', 'account_id' => 'abc123', 'account_timezone' => 'Asia/Amman', 'timezone_switch_at' => 'NONE']);
        $budget = new ProviderReadBudget($report->scope, 'currency', 1, 0, 1, 20, new DateTimeImmutable('+30 seconds'), 1, 5);
        $reader = new XCampaignCurrencyReader($http, new XOAuthSigner('synthetic-key', 'synthetic-app-secret'), function ($scope, $action): void {
            $this->assertSame('abc123', $scope->accountId);
            $this->assertSame('provider.read', $action);
        });
        $proof = $reader->read($report, $tokens, $budget);
        $this->assertSame('JOD', $proof->forCampaign($report, 'campaign2')['currency']);
        $http->assertSent(fn ($r) => str_starts_with($r->url(), 'https://ads-api.x.com/12/accounts/abc123/campaigns?')
            && $r->method() === 'GET' && $r['campaign_ids'] === 'campaign1,campaign2' && $r['with_deleted'] === 'true' && $r['with_draft'] === 'true');
        $http->assertSentCount(1);
        $http = new Factory;
        $http->preventStrayRequests();
        $reader = new XCampaignCurrencyReader($http, new XOAuthSigner('synthetic-key', 'synthetic-app-secret'), static fn () => throw new \RuntimeException('denied'));
        try {
            $reader->read($report, $tokens, new ProviderReadBudget($report->scope, 'currency', 1, 0, 1, 20, new DateTimeImmutable('+30 seconds'), 1, 5));
            $this->fail('Denied read cannot send.');
        } catch (\RuntimeException $error) {
            $this->assertSame('denied', $error->getMessage());
            $http->assertNothingSent();
        }
    }

    public function test_missing_currency_and_native_denial_are_safe_and_accept_no_metadata_rows(): void
    {
        foreach ([200, 403] as $status) {
            $http = new Factory;
            $http->preventStrayRequests();
            $payload = $this->metadata();
            unset($payload['data'][0]['currency']);
            $http->fake(['*' => $http->response($status === 200 ? $payload : 'private-provider-body', $status)]);
            $report = $this->report();
            $tokens = new ProviderCredentials(['oauth_token' => 'synthetic-token', 'oauth_token_secret' => 'synthetic-secret', 'account_id' => 'abc123', 'account_timezone' => 'Asia/Amman', 'timezone_switch_at' => 'NONE']);
            $budget = new ProviderReadBudget($report->scope, 'currency', 1, 0, 1, 20, new DateTimeImmutable('+30 seconds'), 1, 5);
            $reader = new XCampaignCurrencyReader($http, new XOAuthSigner('synthetic-key', 'synthetic-app-secret'), static fn () => null);
            try {
                $reader->read($report, $tokens, $budget);
                $this->fail('Unverified currency must fail.');
            } catch (\RuntimeException $error) {
                $this->assertSame($status === 200 ? 'x_currency_metadata_unverified' : 'x_access_denied', $error->getMessage());
                $this->assertNull($error->getPrevious());
                $this->assertSame(0, $budget->usage()['rows']);
                $this->assertSame(0, $budget->usage()['pages']);
            }
        }
    }
}
