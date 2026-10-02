<?php

namespace Tests\Unit;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\Snapchat\SnapchatClient;
use App\Providers\Ads\Snapchat\SnapchatReadAttempt;
use App\Providers\Ads\Snapchat\SnapchatRequestFailed;
use App\Providers\Ads\Snapchat\SnapchatRetryPlan;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;

final class SnapchatRetryTest extends TestCase
{
    public function test_plain_http_throttle_preserves_retry_after_without_logging_provider_body(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response('synthetic-private-throttle-body', 429, ['Retry-After' => '240'])]);
        $scope = new ProviderScope(7, '11111111-1111-7111-8111-111111111111', Provider::Snapchat,
            'synthetic-client', 'discovery.read', 'advertising_analysis');
        try {
            (new SnapchatClient($http, static fn () => null, 'provider.connect'))->get('me/organizations', ['with_ad_accounts' => 'true'],
                new ProviderCredentials(['access_token' => 'synthetic-token']),
                new ProviderReadBudget($scope, 'test', 2, 0, 2, 100, new DateTimeImmutable('+30 seconds'), 1, 5));
            $this->fail('Native throttle must fail safely.');
        } catch (SnapchatRequestFailed $failure) {
            $this->assertSame('snapchat_rate_limited', $failure->reason);
            $this->assertSame(240, $failure->retryAfterSeconds);
            $this->assertStringNotContainsString('synthetic-private-throttle-body', $failure->getMessage());
        }
        $http->assertSentCount(1);
    }

    public function test_native_delay_is_never_shortened_and_expired_or_terminal_attempts_do_not_retry(): void
    {
        $at = new DateTimeImmutable('2026-10-02T00:00:00Z');
        $attempt = new SnapchatReadAttempt([], 'snapchat_rate_limited', 240);
        $this->assertEquals($at->modify('+240 seconds'), SnapchatRetryPlan::next($attempt, 0, $at, $at->modify('+300 seconds')));
        $this->assertNull(SnapchatRetryPlan::next($attempt, 0, $at, $at->modify('+240 seconds')));
        $this->assertNull(SnapchatRetryPlan::next($attempt, 7, $at, $at->modify('+300 seconds')));
        foreach (['snapchat_credential_denied', 'snapchat_access_denied', 'snapchat_malformed_response', 'snapchat_metric_unverified', 'snapchat_async_report_required'] as $failure) {
            $this->assertNull(SnapchatRetryPlan::next(new SnapchatReadAttempt([], $failure, 1), 0, $at, $at->modify('+300 seconds')));
        }
    }

    public function test_http_date_delay_is_preserved_and_unreadable_delay_is_not_replaced_with_earlier_backoff(): void
    {
        $date = (new DateTimeImmutable('+180 seconds', new \DateTimeZone('UTC')))->format('D, d M Y H:i:s \G\M\T');
        foreach ([$date, '99999999', 'invalid-native-delay'] as $header) {
            $http = new Factory;
            $http->preventStrayRequests();
            $http->fake(['*' => $http->response('', 503, ['Retry-After' => $header])]);
            $scope = new ProviderScope(7, '11111111-1111-7111-8111-111111111111', Provider::Snapchat,
                'synthetic-client', 'discovery.read', 'advertising_analysis');
            try {
                (new SnapchatClient($http, static fn () => null, 'provider.connect'))->get('me/organizations', ['with_ad_accounts' => 'true'],
                    new ProviderCredentials(['access_token' => 'synthetic-token']),
                    new ProviderReadBudget($scope, 'test', 2, 0, 2, 100, new DateTimeImmutable('+30 seconds'), 1, 5));
                $this->fail('Service failure must not return native data.');
            } catch (SnapchatRequestFailed $failure) {
                if ($header === $date) {
                    $this->assertSame('snapchat_service_unavailable', $failure->reason);
                    $this->assertGreaterThanOrEqual(178, $failure->retryAfterSeconds);
                    $this->assertLessThanOrEqual(180, $failure->retryAfterSeconds);
                } else {
                    $this->assertSame('snapchat_retry_hint_unverified', $failure->reason);
                    $this->assertNull($failure->retryAfterSeconds);
                }
            }
        }
    }

    public function test_pending_metadata_polls_with_native_delay_and_schema_blocker_never_retries(): void
    {
        $at = new DateTimeImmutable('2026-10-02T00:00:00Z');
        $deadline = $at->modify('+300 seconds');
        $this->assertEquals($at->modify('+5 seconds'), SnapchatRetryPlan::next(new SnapchatReadAttempt(['coverage' => 'PENDING'], null, null), 0, $at, $deadline));
        $this->assertEquals($at->modify('+40 seconds'), SnapchatRetryPlan::next(new SnapchatReadAttempt(['coverage' => 'PENDING'], null, 40), 0, $at, $deadline));
        $this->assertNull(SnapchatRetryPlan::next(new SnapchatReadAttempt(['coverage' => 'FAILED'], 'snapchat_async_schema_unverified', null), 0, $at, $deadline));
        $this->assertNull(SnapchatRetryPlan::next(new SnapchatReadAttempt(['coverage' => 'PENDING'], null, 300), 0, $at, $deadline));
    }
}
