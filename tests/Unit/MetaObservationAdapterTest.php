<?php

namespace Tests\Unit;

use App\Providers\Ads\Meta\MetaObservationAdapter;
use App\Providers\Ads\Meta\MetaReportTask;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReportWindow;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MetaObservationAdapterTest extends TestCase
{
    private function scope(string $account = '123'): ProviderScope
    {
        return new ProviderScope(1, '019a0022-0000-7000-8000-000000000001', Provider::Meta,
            $account, 'report.read', 'advertising_analysis');
    }

    private function window(): ReportWindow
    {
        return new ReportWindow('2026-09-01', '2026-09-02', 'INCLUSIVE_DATES', 'TOTAL',
            'America/New_York', 'synthetic:verified-account-timezone');
    }

    private function budget(int $requests = 8): ProviderReadBudget
    {
        return new ProviderReadBudget($this->scope(), 'meta-test', $requests, 0, 8, 200,
            new DateTimeImmutable('+1 minute'), 1, 5);
    }

    private function credentials(): ProviderCredentials
    {
        return new ProviderCredentials(['access_token' => 'synthetic-private-token']);
    }

    private function row(string $campaign = '456'): array
    {
        return ['account_id' => '123', 'account_currency' => 'USD', 'campaign_id' => $campaign,
            'date_start' => '2026-09-01', 'date_stop' => '2026-09-02', 'spend' => '9007199254740993.01',
            'impressions' => '10', 'clicks' => '0'];
    }

    public function test_paging_rebuilds_fixed_host_and_scope_without_following_untrusted_next_or_losing_precision(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()
            ->push(['data' => [$this->row()], 'paging' => ['next' => 'https://untrusted.invalid/?access_token=leak',
                'cursors' => ['after' => 'opaque-cursor']]])
            ->push(['data' => [$this->row('457')], 'paging' => ['cursors' => ['after' => 'last-cursor']]])]);
        $result = (new MetaObservationAdapter($http, 'v26.0', 'synthetic-app-secret', static fn () => null))->read(
            $this->scope(), $this->credentials(), $this->window(), $this->budget(), null);
        $this->assertSame('COMPLETE', $result->batch->coverage);
        $this->assertCount(2, $result->batch->observations);
        $this->assertSame('9007199254740993.01', $result->batch->observations[0]->spend->majorUnits());
        $this->assertSame('0', $result->batch->observations[0]->nativeFacts['clicks']['value']);
        $this->assertSame('UNKNOWN', $result->batch->observations[0]->freshness(new DateTimeImmutable));
        $this->assertSame('meta.accepted_range', $result->progress->kind);
        $http->assertSentCount(2);
        foreach ($http->recorded() as [$request]) {
            $this->assertStringStartsWith('https://graph.facebook.com/v26.0/act_123/insights?', $request->url());
            $this->assertSame('Bearer synthetic-private-token', $request->header('Authorization')[0]);
            $this->assertStringNotContainsString('synthetic-private-token', $request->url());
            $this->assertSame(hash_hmac('sha256', 'synthetic-private-token', 'synthetic-app-secret'), $request['appsecret_proof']);
            $this->assertSame('campaign', $request['level']);
            $this->assertSame('all_days', $request['time_increment']);
        }
        $http->assertSent(fn ($request) => ($request->data()['after'] ?? null) === 'opaque-cursor');
    }

    public function test_empty_complete_range_has_provenance_and_progress_without_invented_zero_campaigns(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->response(['data' => []])]);
        $result = (new MetaObservationAdapter($http, 'v26.0', 'secret', static fn () => null))->read(
            $this->scope(), $this->credentials(), $this->window(), $this->budget(), null);
        $this->assertSame('COMPLETE', $result->batch->coverage);
        $this->assertSame([], $result->batch->observations);
        $this->assertNotNull($result->progress);
        $this->assertSame('v26.0', $result->readProvenance['api_version']);
    }

    public static function rejectedRows(): array
    {
        return [['account_id', '124'], ['spend', 12.34], ['date_stop', '2026-09-03'],
            ['impressions', '1.5'], ['account_currency', 'usd'], ['date_start', 'invalid']];
    }

    #[DataProvider('rejectedRows')]
    public function test_malformed_or_cross_account_row_rejects_the_range_without_progress(string $field, mixed $value): void
    {
        $row = $this->row();
        $row[$field] = $value;
        $http = new Factory;
        $http->fake(['*' => $http->response(['data' => [$row]])]);
        $result = (new MetaObservationAdapter($http, 'v26.0', 'secret', static fn () => null))->read(
            $this->scope(), $this->credentials(), $this->window(), $this->budget(), null);
        $this->assertSame('FAILED', $result->batch->coverage);
        $this->assertSame([], $result->batch->observations);
        $this->assertNull($result->progress);
    }

    public function test_omitted_spend_and_delivery_remain_unknown_while_known_zero_stays_zero(): void
    {
        $row = $this->row();
        unset($row['spend'], $row['impressions']);
        $http = new Factory;
        $http->fake(['*' => $http->response(['data' => [$row]])]);
        $result = (new MetaObservationAdapter($http, 'v26.0', 'secret', static fn () => null))->read(
            $this->scope(), $this->credentials(), $this->window(), $this->budget(), null);
        $observation = $result->batch->observations[0];
        $this->assertNull($observation->spend->value);
        $this->assertSame('UNKNOWN', $observation->nativeFacts['impressions']['basis']);
        $this->assertSame('0', $observation->nativeFacts['clicks']['value']);
    }

    public function test_request_budget_leaves_partial_prefix_without_advancing_progress(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->response(['data' => [$this->row()], 'paging' => [
            'next' => 'https://graph.facebook.com/next', 'cursors' => ['after' => 'opaque']]])]);
        $result = (new MetaObservationAdapter($http, 'v26.0', 'secret', static fn () => null))->read(
            $this->scope(), $this->credentials(), $this->window(), $this->budget(1), null);
        $this->assertSame('PARTIAL', $result->batch->coverage);
        $this->assertCount(1, $result->batch->observations);
        $this->assertNull($result->progress);
        $http->assertSentCount(1);
    }

    public function test_duplicate_slots_or_repeated_cursor_cannot_loop_or_create_complete_result(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push(['data' => [$this->row()], 'paging' => [
            'next' => 'next', 'cursors' => ['after' => 'same']]])->push(['data' => [$this->row('457')],
                'paging' => ['next' => 'next', 'cursors' => ['after' => 'same']]])]);
        $result = (new MetaObservationAdapter($http, 'v26.0', 'secret', static fn () => null))->read(
            $this->scope(), $this->credentials(), $this->window(), $this->budget(), null);
        $this->assertSame('FAILED', $result->batch->coverage);
        $this->assertNull($result->progress);
        $http->assertSentCount(2);
    }

    public function test_provider_failure_message_is_not_retained_and_cannot_advance_progress(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->response(['error' => ['code' => 190, 'message' => 'synthetic-private-token']], 400)]);
        $result = (new MetaObservationAdapter($http, 'v26.0', 'secret', static fn () => null))->read(
            $this->scope(), $this->credentials(), $this->window(), $this->budget(), null);
        $this->assertSame('FAILED', $result->batch->coverage);
        $this->assertSame('meta_token_expired', $result->batch->failure);
        $this->assertNull($result->progress);
    }

    public function test_async_task_requires_both_completed_status_and_100_percent_before_paged_results(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push(['report_run_id' => '999'])
            ->push(['id' => '999', 'account_id' => '123', 'async_status' => 'Job Completed', 'async_percent_completion' => 99])
            ->push(['id' => '999', 'account_id' => '123', 'async_status' => 'Job Completed', 'async_percent_completion' => 100])
            ->push(['data' => [$this->row()]])]);
        $adapter = new MetaObservationAdapter($http, 'v26.0', 'secret', static fn () => null);
        $budget = $this->budget();
        $task = $adapter->startAsync($this->scope(), $this->credentials(), $this->window(), $budget);
        $pending = $adapter->readAsync($this->scope(), $this->credentials(), $this->window(), $budget, $task);
        $this->assertSame('PENDING', $pending->batch->coverage);
        $this->assertNull($pending->progress);
        $result = $adapter->readAsync($this->scope(), $this->credentials(), $this->window(), $budget, $task);
        $this->assertSame('COMPLETE', $result->batch->coverage);
        $http->assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/act_123/insights'));
        $http->assertSent(fn ($request) => str_contains($request->url(), '/999/insights?'));
        $http->assertSentCount(4);
    }

    public function test_async_task_cannot_cross_workspace_scope_or_survive_documented_lifetime(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $adapter = new MetaObservationAdapter($http, 'v26.0', 'secret', static fn () => null);
        $this->expectException(InvalidArgumentException::class);
        $adapter->readAsync($this->scope(), $this->credentials(), $this->window(), $this->budget(),
            new MetaReportTask('999', str_repeat('a', 64), new DateTimeImmutable('-31 days')));
    }

    public function test_current_authority_is_rechecked_before_each_page_and_later_denial_sends_no_request(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->response(['data' => [$this->row()], 'paging' => [
            'next' => 'next', 'cursors' => ['after' => 'opaque']]])]);
        $calls = 0;
        $guard = function (ProviderScope $scope, string $action) use (&$calls): void {
            $this->assertSame($this->scope()->jsonSerialize(), $scope->jsonSerialize());
            $this->assertSame('provider.read', $action);
            if (++$calls === 2) {
                throw new AuthorizationException('synthetic permission expired');
            }
        };
        try {
            (new MetaObservationAdapter($http, 'v26.0', 'secret', $guard))->read(
                $this->scope(), $this->credentials(), $this->window(), $this->budget(), null);
            $this->fail('Expired second-page authority must abort before transport.');
        } catch (AuthorizationException) {
            $this->assertSame(2, $calls);
            $http->assertSentCount(1);
        }
    }
}
