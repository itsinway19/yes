<?php

namespace Tests\Unit;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\TikTok\TikTokClient;
use App\Providers\Ads\TikTok\TikTokReadAttempt;
use App\Providers\Ads\TikTok\TikTokRequestFailed;
use App\Providers\Ads\TikTok\TikTokRetryPlan;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TikTokClientTest extends TestCase
{
    public static function httpFailures(): array
    {
        return [
            'empty unauthorized' => [401, '', 'tiktok_credential_denied'],
            'html forbidden' => [403, '<html>Forbidden</html>', 'tiktok_access_denied'],
            'empty throttled' => [429, '', 'tiktok_rate_limited'],
            'html unavailable' => [503, '<html>Unavailable</html>', 'tiktok_service_unavailable'],
            'redirect denied' => [302, '', 'tiktok_request_failed'],
        ];
    }

    private function failure(int $status, string $body, string $retry): TikTokRequestFailed
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response($body, $status, ['Retry-After' => $retry])]);
        $scope = new ProviderScope(7, '11111111-1111-7111-8111-111111111111', Provider::TikTok,
            '1234567890123456789', 'report.read', 'advertising_analysis');
        $client = new TikTokClient($http, 'v1.3', static fn () => null, 'provider.read');
        try {
            $client->request('report/integrated/get/', ['advertiser_id' => $scope->accountId],
                new ProviderCredentials(['access_token' => 'synthetic-private-token']),
                new ProviderReadBudget($scope, 'tiktok-http-test', 1, 0, 1, 100,
                    new DateTimeImmutable('+30 seconds'), 1, 5));
            $this->fail('Native HTTP failure must not yield data.');
        } catch (TikTokRequestFailed $failure) {
            $http->assertSentCount(1);

            return $failure;
        }
    }

    #[DataProvider('httpFailures')]
    public function test_http_status_survives_non_json_error_body(int $status, string $body, string $reason): void
    {
        $failure = $this->failure($status, $body, '90');
        $this->assertSame($reason, $failure->reason);
        $this->assertSame(90, $failure->retryAfterSeconds);
    }

    public function test_http_date_retry_hint_is_not_shortened_by_local_backoff(): void
    {
        $at = new DateTimeImmutable;
        $hint = $at->modify('+5 minutes')->setTimezone(new \DateTimeZone('UTC'))->format('D, d M Y H:i:s \G\M\T');
        $failure = $this->failure(429, '', $hint);
        $this->assertSame('tiktok_rate_limited', $failure->reason);
        $this->assertGreaterThanOrEqual(295, $failure->retryAfterSeconds);
        $this->assertLessThanOrEqual(300, $failure->retryAfterSeconds);
        $next = TikTokRetryPlan::next(new TikTokReadAttempt([], $failure->reason, $failure->retryAfterSeconds),
            0, $at, $at->modify('+10 minutes'));
        $this->assertSame($at->getTimestamp() + $failure->retryAfterSeconds, $next?->getTimestamp());
    }

    public function test_unreadable_retry_hint_does_not_fall_back_to_a_shorter_retry(): void
    {
        $failure = $this->failure(429, '', 'not-a-delay');
        $this->assertSame('tiktok_retry_hint_unverified', $failure->reason);
        $this->assertNull(TikTokRetryPlan::next(new TikTokReadAttempt([], $failure->reason, $failure->retryAfterSeconds),
            0, new DateTimeImmutable, new DateTimeImmutable('+10 minutes')));
    }
}
