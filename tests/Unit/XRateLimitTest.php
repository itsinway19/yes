<?php

namespace Tests\Unit;

use App\Providers\Ads\X\XRateLimit;
use App\Providers\Ads\X\XRetryPlan;
use DateTimeImmutable;
use GuzzleHttp\Psr7\Response as NativeResponse;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\TestCase;

final class XRateLimitTest extends TestCase
{
    public function test_account_headers_take_precedence_and_never_fall_back_when_malformed(): void
    {
        $at = new DateTimeImmutable('@1800000000');
        $user = ['x-rate-limit-limit' => '250', 'x-rate-limit-remaining' => '0', 'x-rate-limit-reset' => '1800000900'];
        $account = ['x-account-rate-limit-limit' => '10000', 'x-account-rate-limit-remaining' => '0', 'x-account-rate-limit-reset' => '1800000300'];
        $this->assertSame(['level' => 'account', 'until' => 1800000300, 'seconds' => 300], XRateLimit::exhausted(new Response(new NativeResponse(429, $user + $account)), $at));
        $this->assertSame(['level' => 'user', 'until' => 1800000900, 'seconds' => 900], XRateLimit::exhausted(new Response(new NativeResponse(429, $user)), $at));
        foreach (['positive', 'missing', 'duplicate', 'past', 'huge', 'comma'] as $case) {
            $headers = $account;
            if ($case === 'positive') {
                $headers['x-account-rate-limit-remaining'] = '1';
            }
            if ($case === 'missing') {
                unset($headers['x-account-rate-limit-reset']);
            }
            if ($case === 'duplicate') {
                $headers['x-account-rate-limit-reset'] = ['1800000300', '1800000400'];
            }
            if ($case === 'past') {
                $headers['x-account-rate-limit-reset'] = '1799999999';
            }
            if ($case === 'huge') {
                $headers['x-account-rate-limit-reset'] = '9999999999';
            }
            if ($case === 'comma') {
                $headers['x-account-rate-limit-reset'] = '1800000300,1800000400';
            }
            $this->assertNull(XRateLimit::exhausted(new Response(new NativeResponse(429, $headers + $user)), $at), $case);
        }
    }

    public function test_retry_never_shortens_native_delay_or_crosses_execution_deadline(): void
    {
        $at = new DateTimeImmutable('2026-10-02T00:00:00Z');
        $deadline = $at->modify('+5 minutes');
        $this->assertSame($at->modify('+200 seconds')->getTimestamp(), XRetryPlan::next('x_rate_limited', 200, 0, $at, $deadline)?->getTimestamp());
        $this->assertNull(XRetryPlan::next('x_rate_limited', 300, 0, $at, $deadline));
        $this->assertNull(XRetryPlan::next('x_rate_limited', null, 0, $at, $deadline));
        $this->assertNull(XRetryPlan::next('x_credential_denied', 1, 0, $at, $deadline));
        $this->assertNull(XRetryPlan::next('x_service_unavailable', null, 7, $at, $deadline));
        $this->assertSame($at->modify('+5 seconds')->getTimestamp(), XRetryPlan::next('x_transport_failed', null, 0, $at, $deadline)?->getTimestamp());
    }
}
