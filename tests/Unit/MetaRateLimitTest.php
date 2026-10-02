<?php

namespace Tests\Unit;

use App\Providers\Ads\Meta\MetaGraphClient;
use App\Providers\Ads\Meta\MetaRateLimit;
use App\Providers\Ads\Meta\MetaReadAttempt;
use App\Providers\Ads\Meta\MetaRequestFailed;
use App\Providers\Ads\Meta\MetaRetryPlan;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderScope;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MetaRateLimitTest extends TestCase
{
    public function test_native_units_and_business_type_binding_preserve_the_longest_actual_delay(): void
    {
        $http = new Factory;
        $response = $http->response([], 400, ['X-Ad-Account-Usage' => '{"reset_time_duration":100}',
            'X-Business-Use-Case-Usage' => json_encode(['555' => [
                ['type' => 'ads_insights', 'estimated_time_to_regain_access' => 19],
                ['type' => 'ads_management', 'estimated_time_to_regain_access' => 600],
            ], '999' => [['type' => 'ads_insights', 'estimated_time_to_regain_access' => 700]]])]);
        $response = new Response($response->wait());
        $credentials = new ProviderCredentials(['access_token' => 'synthetic-token', 'client_business_id' => '555']);
        $this->assertSame(1140, MetaRateLimit::delay($response, $credentials, 'act_123/insights'));
        $this->assertSame(100, MetaRateLimit::delay($response, new ProviderCredentials(['access_token' => 'synthetic-token']), 'act_123/insights'));
        $this->assertNull(MetaRateLimit::delay($response, new ProviderCredentials(['access_token' => 'synthetic-token']), '888/insights'));
    }

    #[DataProvider('invalidHints')]
    public function test_malformed_or_unbounded_hints_do_not_become_scheduling_truth(string $hint): void
    {
        $http = new Factory;
        $response = new Response($http->response([], 400, ['X-Ad-Account-Usage' => $hint])->wait());
        $this->assertNull(MetaRateLimit::delay($response, new ProviderCredentials(['access_token' => 'synthetic']), 'act_123/insights'));
    }

    public static function invalidHints(): array
    {
        return [['not-json'], ['{"reset_time_duration":-1}'], ['{"reset_time_duration":"100"}'],
            ['{"reset_time_duration":31536001}'], [str_repeat('x', 32769)]];
    }

    public function test_native_throttling_code_keeps_safe_hint_without_raw_messages_or_request_secret(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->response(['error' => ['code' => 80000, 'error_subcode' => 2446079,
            'message' => 'synthetic-private-token']], 400, ['X-Ad-Account-Usage' => '{"reset_time_duration":100}'])]);
        $scope = new ProviderScope(1, '019a0022-0000-7000-8000-000000000001', Provider::Meta, '123', 'report.read', 'advertising_analysis');
        $budget = new ProviderReadBudget($scope, 'meta-test', 2, 0, 2, 10, new DateTimeImmutable('+30 seconds'), 1, 5);
        try {
            (new MetaGraphClient($http, 'v26.0', 'synthetic-app-secret', static fn () => null, 'provider.read'))
                ->request('GET', 'act_123/insights', [], new ProviderCredentials(['access_token' => 'synthetic-private-token']), $budget);
            $this->fail('A native throttle cannot return an accepted report.');
        } catch (MetaRequestFailed $error) {
            $this->assertSame('meta_rate_limited', $error->reason);
            $this->assertSame(100, $error->retryAfterSeconds);
            $this->assertStringNotContainsString('synthetic-private-token', $error->getMessage());
            $this->assertStringNotContainsString('synthetic-app-secret', $error->getTraceAsString());
        }
    }

    public function test_native_delay_is_never_shortened_to_fit_deadline_and_nonrecoverable_failures_do_not_retry(): void
    {
        $at = new DateTimeImmutable('2026-10-01T12:00:00Z');
        $attempt = new MetaReadAttempt(['coverage' => 'FAILED'], 'meta_rate_limited', 1140);
        $this->assertSame('2026-10-01T12:19:00+00:00', MetaRetryPlan::next($attempt, 0, $at, $at->modify('+30 minutes'))->format(DATE_ATOM));
        $this->assertNull(MetaRetryPlan::next($attempt, 0, $at, $at->modify('+5 minutes')));
        $this->assertNull(MetaRetryPlan::next(new MetaReadAttempt(['coverage' => 'PENDING'], null, 1140), 0, $at, $at->modify('+5 minutes')));
        $this->assertNull(MetaRetryPlan::next(new MetaReadAttempt(['coverage' => 'FAILED'], 'meta_permission_lost', null), 0, $at, $at->modify('+5 minutes')));
        $this->assertNull(MetaRetryPlan::next(new MetaReadAttempt(['coverage' => 'PENDING'], null, null), 7, $at, $at->modify('+5 minutes')));
        $this->assertSame('2026-10-01T12:00:05+00:00', MetaRetryPlan::next(new MetaReadAttempt(['coverage' => 'PENDING'], null, null), 0, $at, $at->modify('+5 minutes'))->format(DATE_ATOM));
    }

    public function test_success_header_below_limit_does_not_pause_but_active_limit_stops_before_the_next_request(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->push(['data' => []], 200,
            ['X-Ad-Account-Usage' => '{"acc_id_util_pct":9.67,"reset_time_duration":100}'])
            ->push(['data' => []], 200, ['X-Ad-Account-Usage' => '{"acc_id_util_pct":100,"reset_time_duration":100}'])]);
        $scope = new ProviderScope(1, '019a0022-0000-7000-8000-000000000001', Provider::Meta, '123', 'report.read', 'advertising_analysis');
        $budget = new ProviderReadBudget($scope, 'meta-test', 4, 0, 4, 10, new DateTimeImmutable('+30 seconds'), 1, 5);
        $checks = 0;
        $client = new MetaGraphClient($http, 'v26.0', 'synthetic-secret', function () use (&$checks): void {
            $checks++;
        }, 'provider.read');
        $credentials = new ProviderCredentials(['access_token' => 'synthetic-token']);
        $client->request('GET', 'act_123/insights', [], $credentials, $budget);
        $client->request('GET', 'act_123/insights', [], $credentials, $budget);
        try {
            $client->request('GET', 'act_123/insights', [], $credentials, $budget);
            $this->fail('An active native cooldown must stop before another network request.');
        } catch (MetaRequestFailed $error) {
            $this->assertSame('meta_rate_limited', $error->reason);
            $this->assertGreaterThanOrEqual(99, $error->retryAfterSeconds);
            $http->assertSentCount(2);
            $this->assertSame(3, $checks);
        }
    }
}
