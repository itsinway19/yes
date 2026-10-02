<?php

namespace Tests\Unit;

use App\Providers\Ads\Google\GoogleAdsClient;
use App\Providers\Ads\Google\GoogleReadAttempt;
use App\Providers\Ads\Google\GoogleRequestFailed;
use App\Providers\Ads\Google\GoogleRetryPlan;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderScope;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GoogleHttpFailureTest extends TestCase
{
    public static function failures(): array
    {
        return [
            [401, '', '90', 'google_credential_denied', 90],
            [401, 'plain denied', 'invalid', 'google_credential_denied', null],
            [403, '{broken', '', 'google_access_denied', null],
            [403, '<html>Denied</html>', '90', 'google_access_denied', 90],
            [429, '', '90', 'google_rate_limited', 90],
            [429, '{"error":{"status":"RESOURCE_EXHAUSTED"}}', '90', 'google_rate_limited', 90],
            [429, 'not-json', 'invalid', 'google_retry_hint_unverified', null],
            [500, '<html>Failure</html>', '90', 'google_service_unavailable', 90],
            [503, '<html>Unavailable</html>', '90', 'google_service_unavailable', 90],
            [503, 'plain unavailable', '', 'google_service_unavailable', null],
            [503, '{broken', '', 'google_service_unavailable', null],
            [503, '{"error":{"status":"UNAVAILABLE"}}', '', 'google_service_unavailable', null],
            [200, '{broken', '', 'google_malformed_response', null],
        ];
    }

    private function request(int $status, string $body, string $hint): array
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response($body, $status, ['Retry-After' => $hint])]);
        $scope = new ProviderScope(7, '11111111-1111-7111-8111-111111111111', Provider::Google,
            '1234567890', 'discovery.read', 'advertising_analysis');

        return (new GoogleAdsClient($http, 'v25', '', static fn () => null, 'provider.connect'))->accessible(
            new ProviderCredentials(['access_token' => 'synthetic-token', 'expires_at' => (string) (time() + 600)]),
            new ProviderReadBudget($scope, 'google-http-proof', 1, 0, 1, 100, new DateTimeImmutable('+30 seconds'), 1, 5));
    }

    #[DataProvider('failures')]
    public function test_http_failure_and_success_body_have_separate_classification(int $status, string $body, string $hint, string $reason, ?int $delay): void
    {
        try {
            $this->request($status, $body, $hint);
            $this->fail('Expected transport failure.');
        } catch (GoogleRequestFailed $failure) {
            $this->assertSame($reason, $failure->reason);
            $this->assertSame($delay, $failure->retryAfterSeconds);
        }
    }

    public function test_dated_retry_hint_is_preserved_and_never_shortened(): void
    {
        $now = new DateTimeImmutable;
        $target = $now->modify('+5 minutes')->setTimezone(new \DateTimeZone('UTC'));
        try {
            $this->request(429, '{}', $target->format('D, d M Y H:i:s \G\M\T'));
            $this->fail('Expected rate limit.');
        } catch (GoogleRequestFailed $failure) {
            $this->assertSame('google_rate_limited', $failure->reason);
            $this->assertGreaterThanOrEqual($target->getTimestamp() - time(), $failure->retryAfterSeconds);
            $this->assertLessThanOrEqual(300, $failure->retryAfterSeconds);
            $next = GoogleRetryPlan::next(new GoogleReadAttempt([], $failure->reason, $failure->retryAfterSeconds),
                0, $now, $now->modify('+10 minutes'));
            $this->assertSame($now->getTimestamp() + $failure->retryAfterSeconds, $next?->getTimestamp());
        }
    }

    public function test_valid_success_json_is_preserved(): void
    {
        $this->assertSame(['resourceNames' => ['customers/1234567890']],
            $this->request(200, '{"resourceNames":["customers/1234567890"]}', ''));
    }
}
