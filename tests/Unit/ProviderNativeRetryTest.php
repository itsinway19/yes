<?php

namespace Tests\Unit;

use App\Providers\Ads\Google\GoogleReadAttempt;
use App\Providers\Ads\Google\GoogleRetryPlan;
use App\Providers\Ads\TikTok\TikTokReadAttempt;
use App\Providers\Ads\TikTok\TikTokRetryPlan;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ProviderNativeRetryTest extends TestCase
{
    public function test_native_delay_is_never_shortened_and_deadline_stops_retry(): void
    {
        $at = new DateTimeImmutable('2026-10-02T00:00:00Z');
        foreach (['google', 'tiktok'] as $provider) {
            $attemptClass = $provider === 'google' ? GoogleReadAttempt::class : TikTokReadAttempt::class;
            $plan = $provider === 'google' ? GoogleRetryPlan::class : TikTokRetryPlan::class;
            $attempt = new $attemptClass([], $provider.'_rate_limited', 240);
            $this->assertEquals($at->modify('+240 seconds'), $plan::next($attempt, 0, $at, $at->modify('+300 seconds')));
            $this->assertNull($plan::next($attempt, 0, $at, $at->modify('+240 seconds')));
            $this->assertNull($plan::next($attempt, 7, $at, $at->modify('+300 seconds')));
        }
    }

    public function test_denial_and_malformed_data_are_not_automatically_retried(): void
    {
        $at = new DateTimeImmutable('2026-10-02T00:00:00Z');
        foreach (['google', 'tiktok'] as $provider) {
            $attemptClass = $provider === 'google' ? GoogleReadAttempt::class : TikTokReadAttempt::class;
            $plan = $provider === 'google' ? GoogleRetryPlan::class : TikTokRetryPlan::class;
            foreach (['_credential_denied', '_access_denied', '_malformed_response', '_metric_unverified'] as $failure) {
                $this->assertNull($plan::next(new $attemptClass([], $provider.$failure, 1), 0, $at, $at->modify('+300 seconds')));
            }
        }
    }
}
