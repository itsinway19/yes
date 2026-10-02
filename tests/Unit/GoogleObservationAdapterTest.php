<?php

namespace Tests\Unit;

use App\Providers\Ads\Google\GoogleOAuthClient;
use App\Providers\Ads\Google\GoogleObservationAdapter;
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

class GoogleObservationAdapterTest extends TestCase
{
    private function scope(): ProviderScope
    {
        return new ProviderScope(1, '019a0022-0000-7000-8000-000000000001', Provider::Google,
            '1234567890', 'report.read', 'advertising_analysis');
    }

    private function window(string $grain = 'TOTAL'): ReportWindow
    {
        return new ReportWindow('2026-09-01', '2026-09-02', 'INCLUSIVE_DATES', $grain, 'Asia/Amman', 'synthetic:native-account-timezone');
    }

    private function credentials(array $replace = []): ProviderCredentials
    {
        return new ProviderCredentials(array_replace(['access_token' => 'synthetic-private-access', 'account_id' => '1234567890',
            'granted_scope' => GoogleOAuthClient::SCOPE, 'expires_at' => (string) (time() + 600), 'login_customer_id' => '9876543210'], $replace));
    }

    private function budget(int $requests = 8, int $rows = 100): ProviderReadBudget
    {
        return new ProviderReadBudget($this->scope(), 'google-synthetic-report', $requests, 0, 8, $rows,
            new DateTimeImmutable('+1 minute'), 1, 5);
    }

    public function test_conversion_metrics_preserve_native_decimal_tokens_and_do_not_invent_missing_values(): void
    {
        $page = $this->page([$this->row(), $this->row('457')]);
        $body = json_encode($page, JSON_THROW_ON_ERROR);
        $body = preg_replace('/"clicks":"0"/', '"clicks":"0","conversions":1.25,"allConversions":0,"conversionsValue":9007199254740993.001', $body, 1);
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push($this->metadata())->push($body)]);
        $result = $this->adapter($http)->read($this->scope(), $this->credentials(), $this->window(), $this->budget(), null);
        $this->assertSame('COMPLETE', $result->batch->coverage);
        $facts = $result->batch->observations[0]->nativeFacts;
        $this->assertArrayHasKey('conversions', $facts);
        $this->assertSame('1.25', $facts['conversions']['value']);
        $this->assertSame('0', $facts['all_conversions']['value']);
        $this->assertSame('9007199254740993.001', $facts['conversions_value']['value']);
        $this->assertSame('PROVIDER_REPORTED', $facts['conversions_value']['basis']);
        $this->assertNull($result->batch->observations[1]->nativeFacts['conversions']['value']);
        $this->assertSame('UNKNOWN', $result->batch->observations[1]->nativeFacts['conversions']['basis']);
        $http->assertSent(fn ($r) => str_contains($r['query'], 'metrics.conversions_value') && str_contains($r['query'], 'metrics.all_conversions'));
        $http->assertNotSent(fn ($r) => $r->hasHeader('developer-token'));
    }

    public function test_campaign_status_keeps_native_enum_and_missing_status_unknown(): void
    {
        $paused = $this->row();
        $paused['campaign']['status'] = 'PAUSED';
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push($this->metadata())->push($this->page([$paused, $this->row('457')]))]);
        $result = $this->adapter($http)->read($this->scope(), $this->credentials(), $this->window(), $this->budget(), null);
        $this->assertSame('COMPLETE', $result->batch->coverage);
        $this->assertArrayHasKey('campaign_status', $result->batch->observations[0]->nativeFacts);
        $this->assertSame('PAUSED', $result->batch->observations[0]->nativeFacts['campaign_status']['value']);
        $this->assertSame('PROVIDER_REPORTED', $result->batch->observations[0]->nativeFacts['campaign_status']['basis']);
        $this->assertNull($result->batch->observations[1]->nativeFacts['campaign_status']['value']);
        $this->assertSame('UNKNOWN', $result->batch->observations[1]->nativeFacts['campaign_status']['basis']);
        $http->assertSent(fn ($r) => str_contains($r['query'], 'campaign.status'));
    }

    public function test_invalid_campaign_status_discards_the_range_and_never_advances_progress(): void
    {
        foreach (['NOT_A_NATIVE_STATUS', '=HYPERLINK("https://invalid")', ['PAUSED']] as $status) {
            $row = $this->row();
            $row['campaign']['status'] = $status;
            $http = new Factory;
            $http->fake(['*' => $http->sequence()->push($this->metadata())->push($this->page([$row]))]);
            $result = $this->adapter($http)->read($this->scope(), $this->credentials(), $this->window(), $this->budget(), null);
            $this->assertSame('FAILED', $result->batch->coverage);
            $this->assertSame([], $result->batch->observations);
            $this->assertNull($result->progress);
        }
    }

    public function test_malformed_native_json_is_not_a_retryable_transport_failure(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push($this->metadata())->push('{broken')]);
        $result = $this->adapter($http)->read($this->scope(), $this->credentials(), $this->window(), $this->budget(), null);
        $this->assertSame('FAILED', $result->batch->coverage);
        $this->assertSame('google_malformed_response', $result->batch->failure);
        $this->assertSame([], $result->batch->observations);
        $this->assertNull($result->progress);
    }

    private function adapter(Factory $http, ?\Closure $guard = null): GoogleObservationAdapter
    {
        return new GoogleObservationAdapter($http, 'v25', '', $guard ?? static fn () => null);
    }

    private function metadata(array $replace = []): array
    {
        return ['fieldMask' => 'customer.id,customer.manager,customer.currencyCode,customer.timeZone',
            'results' => [['customer' => array_replace(['id' => '1234567890', 'manager' => false, 'currencyCode' => 'JOD', 'timeZone' => 'Asia/Amman'], $replace)]]];
    }

    private function row(string $id = '456'): array
    {
        return ['customer' => ['id' => '1234567890', 'currencyCode' => 'JOD', 'timeZone' => 'Asia/Amman'],
            'campaign' => ['id' => $id, 'resourceName' => 'customers/1234567890/campaigns/'.$id],
            'metrics' => ['costMicros' => '9007199254740993', 'impressions' => '10', 'clicks' => '0']];
    }

    private function page(array $rows = [], ?string $token = null, bool $day = false): array
    {
        $page = ['fieldMask' => 'customer.id,customer.currencyCode,customer.timeZone,campaign.id,campaign.resourceName,campaign.status,metrics.costMicros,metrics.impressions,metrics.clicks,metrics.conversions,metrics.allConversions,metrics.conversionsValue'.($day ? ',segments.date' : ''),
            'results' => $rows];
        if ($token !== null) {
            $page['nextPageToken'] = $token;
        }

        return $page;
    }

    public function test_fixed_host_paging_preserves_exact_micros_unknown_and_zero_without_changing_query_or_manager(): void
    {
        $unknown = $this->row('457');
        unset($unknown['metrics']['costMicros'], $unknown['metrics']['clicks']);
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push($this->metadata())->push($this->page([$this->row()], 'https://untrusted.invalid/native-token'))
            ->push($this->page([$unknown]))]);
        $budget = $this->budget();
        $result = $this->adapter($http)->read($this->scope(), $this->credentials(), $this->window(), $budget, null);
        $this->assertSame('COMPLETE', $result->batch->coverage);
        $this->assertCount(2, $result->batch->observations);
        $one = $result->batch->observations[0];
        $this->assertSame('9007199254740993', $one->spend->value);
        $this->assertSame('9007199254.740993', $one->spend->majorUnits());
        $this->assertSame('JOD', $one->spend->currency);
        $this->assertSame('0', $one->nativeFacts['clicks']['value']);
        $this->assertNull($result->batch->observations[1]->spend->value);
        $this->assertSame('UNKNOWN', $result->batch->observations[1]->nativeFacts['clicks']['basis']);
        $this->assertSame('google.accepted_range', $result->progress->kind);
        $this->assertSame(3, $budget->usage()['requests']);
        $sent = $http->recorded();
        $this->assertSame($sent[1][0]['query'], $sent[2][0]['query']);
        $this->assertSame('https://untrusted.invalid/native-token', $sent[2][0]['pageToken']);
        foreach ($sent as [$request]) {
            $this->assertSame('https://googleads.googleapis.com/v25/customers/1234567890/googleAds:search', $request->url());
            $this->assertTrue($request->hasHeader('login-customer-id', '9876543210'));
            $this->assertArrayNotHasKey('pageSize', $request->data());
            $this->assertStringNotContainsString('synthetic-private-access', $request->url());
        }
    }

    public function test_changed_currency_is_rejected_even_before_an_empty_report(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response($this->metadata(['currencyCode' => 'USD']))]);
        $result = $this->adapter($http)->read($this->scope(), $this->credentials(['account_currency' => 'JOD']), $this->window(), $this->budget(), null);
        $this->assertSame('FAILED', $result->batch->coverage);
        $this->assertSame('google_account_context_changed', $result->batch->failure);
        $this->assertNull($result->progress);
        $http->assertSentCount(1);
    }

    public function test_native_empty_report_is_complete_without_fabricating_campaigns_or_zero(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push($this->metadata())->push($this->page())]);
        $result = $this->adapter($http)->read($this->scope(), $this->credentials(), $this->window(), $this->budget(), null);
        $this->assertSame('COMPLETE', $result->batch->coverage);
        $this->assertSame([], $result->batch->observations);
        $this->assertNotNull($result->progress);
    }

    public function test_daily_reporting_uses_current_account_timezone_and_keeps_native_day_boundaries(): void
    {
        $row = $this->row();
        $row['segments'] = ['date' => '2026-09-02'];
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push($this->metadata())->push($this->page([$row], null, true))]);
        $result = $this->adapter($http)->read($this->scope(), $this->credentials(), $this->window('DAY'), $this->budget(), null);
        $this->assertSame('2026-09-02', $result->batch->observations[0]->window->start);
        $this->assertSame('2026-09-02', $result->batch->observations[0]->window->end);
        $this->assertSame('Asia/Amman', $result->batch->observations[0]->window->timezone);
        $http->assertSent(fn ($r) => str_contains($r['query'], "segments.date BETWEEN '2026-09-01' AND '2026-09-02'")
            && str_contains(explode(' FROM ', $r['query'])[0], 'segments.date'));
    }

    public static function badRows(): array
    {
        return [['customer', 'id', '2234567890'], ['customer', 'currencyCode', 'SAR'], ['customer', 'timeZone', 'Asia/Riyadh'],
            ['campaign', 'resourceName', 'customers/2234567890/campaigns/456'], ['metrics', 'costMicros', 12.3],
            ['metrics', 'clicks', -1], ['metrics', 'costMicros', '9223372036854775808']];
    }

    #[DataProvider('badRows')]
    public function test_malformed_or_cross_account_native_row_discards_entire_unresolved_range(string $object, string $key, mixed $value): void
    {
        $row = $this->row('457');
        $row[$object][$key] = $value;
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push($this->metadata())->push($this->page([$this->row()], 'next'))
            ->push($this->page([$row]))]);
        $result = $this->adapter($http)->read($this->scope(), $this->credentials(), $this->window(), $this->budget(), null);
        $this->assertSame('FAILED', $result->batch->coverage);
        $this->assertSame([], $result->batch->observations);
        $this->assertNull($result->progress);
    }

    public function test_duplicate_campaign_period_across_pages_cannot_be_accepted_twice(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push($this->metadata())->push($this->page([$this->row()], 'next'))
            ->push($this->page([$this->row()]))]);
        $result = $this->adapter($http)->read($this->scope(), $this->credentials(), $this->window(), $this->budget(), null);
        $this->assertSame('google_duplicate_measurement_slot', $result->batch->failure);
        $this->assertNull($result->progress);
    }

    public function test_repeated_page_token_is_failed_and_never_advances_progress(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push($this->metadata())->push($this->page([$this->row()], 'next'))
            ->push($this->page([$this->row('457')], 'next'))]);
        $result = $this->adapter($http)->read($this->scope(), $this->credentials(), $this->window(), $this->budget(), null);
        $this->assertSame('google_invalid_or_repeated_page_token', $result->batch->failure);
        $this->assertSame([], $result->batch->observations);
    }

    public function test_budget_exhaustion_retains_only_resolved_prefix_as_partial_without_progress(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push($this->metadata())->push($this->page([$this->row()], 'next'))]);
        $result = $this->adapter($http)->read($this->scope(), $this->credentials(), $this->window(), $this->budget(2), null);
        $this->assertSame('PARTIAL', $result->batch->coverage);
        $this->assertCount(1, $result->batch->observations);
        $this->assertNull($result->progress);
        $http->assertSentCount(2);
    }

    public function test_missing_field_mask_is_rejected_instead_of_becoming_an_empty_report(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push($this->metadata())->push([])]);
        $result = $this->adapter($http)->read($this->scope(), $this->credentials(), $this->window(), $this->budget(), null);
        $this->assertSame('FAILED', $result->batch->coverage);
        $this->assertSame('google_malformed_search_page', $result->batch->failure);
    }

    public function test_current_native_timezone_change_stops_report_before_observations(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->response($this->metadata(['timeZone' => 'Asia/Riyadh']))]);
        $result = $this->adapter($http)->read($this->scope(), $this->credentials(), $this->window(), $this->budget(), null);
        $this->assertSame('google_account_context_changed', $result->batch->failure);
        $http->assertSentCount(1);
    }

    public function test_current_authority_denial_before_later_page_propagates_without_a_provider_failure_or_more_http(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push($this->metadata())->push($this->page([$this->row()], 'next'))]);
        $calls = 0;
        $adapter = $this->adapter($http, static function () use (&$calls): void {
            if (++$calls === 3) {
                throw new AuthorizationException('synthetic-current-authority-denied');
            }
        });
        try {
            $adapter->read($this->scope(), $this->credentials(), $this->window(), $this->budget(), null);
            $this->fail('Revoked authority must stop the later page.');
        } catch (AuthorizationException) {
            $http->assertSentCount(2);
            $this->assertNull($adapter->failure());
        }
    }

    public function test_native_rate_limit_keeps_retry_hint_and_discards_prefix_without_retaining_error_text(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push($this->metadata())->push($this->page([$this->row()], 'next'))
            ->push(['error' => ['status' => 'RESOURCE_EXHAUSTED', 'message' => 'synthetic-private-access']], 429, ['Retry-After' => '120'])]);
        $adapter = $this->adapter($http);
        $result = $adapter->read($this->scope(), $this->credentials(), $this->window(), $this->budget(), null);
        $this->assertSame('google_rate_limited', $result->batch->failure);
        $this->assertSame(120, $adapter->failure()->retryAfterSeconds);
        $this->assertSame([], $result->batch->observations);
        $this->assertStringNotContainsString('synthetic-private-access', $adapter->failure()->getMessage());
        $this->assertNull($result->progress);
    }

    public function test_selected_scope_mismatch_is_denied_before_any_native_request(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $this->expectException(InvalidArgumentException::class);
        $this->adapter($http)->read($this->scope(), $this->credentials(['account_id' => '2234567890']), $this->window(), $this->budget(), null);
    }

    public function test_expired_credential_cannot_read_even_when_other_native_context_is_present(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $result = $this->adapter($http)->read($this->scope(), $this->credentials(['expires_at' => '1']), $this->window(), $this->budget(), null);
        $this->assertSame('google_credential_reverification_required', $result->batch->failure);
        $http->assertNothingSent();
    }

    public function test_accepted_range_receipt_does_not_skip_native_reread_and_restated_content_keeps_same_identity(): void
    {
        $changed = $this->row();
        $changed['metrics']['costMicros'] = '9007199254741993';
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push($this->metadata())->push($this->page([$this->row()]))
            ->push($this->metadata())->push($this->page([$changed]))]);
        $adapter = $this->adapter($http);
        $first = $adapter->read($this->scope(), $this->credentials(), $this->window(), $this->budget(), null);
        $second = $adapter->read($this->scope(), $this->credentials(), $this->window(), $this->budget(), $first->progress);
        $this->assertSame($first->batch->observations[0]->identity(), $second->batch->observations[0]->identity());
        $this->assertNotSame($first->batch->observations[0]->contentHash(), $second->batch->observations[0]->contentHash());
        $http->assertSentCount(4);
    }

    public function test_daily_date_outside_selected_range_is_rejected(): void
    {
        $row = $this->row();
        $row['segments'] = ['date' => '2026-09-03'];
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push($this->metadata())->push($this->page([$row], null, true))]);
        $result = $this->adapter($http)->read($this->scope(), $this->credentials(), $this->window('DAY'), $this->budget(), null);
        $this->assertSame('google_out_of_range_report_date', $result->batch->failure);
        $this->assertNull($result->progress);
    }

    public function test_row_budget_does_not_accept_half_a_page_or_advance_progress(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push($this->metadata())->push($this->page([$this->row(), $this->row('457')]))]);
        $result = $this->adapter($http)->read($this->scope(), $this->credentials(), $this->window(), $this->budget(8, 2), null);
        $this->assertSame('PARTIAL', $result->batch->coverage);
        $this->assertSame([], $result->batch->observations);
        $this->assertNull($result->progress);
    }
}
