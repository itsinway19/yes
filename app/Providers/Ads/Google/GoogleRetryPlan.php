<?php

namespace App\Providers\Ads\Google;

use DateTimeImmutable;

/** Local scheduling bounds, never claims of provider quota. */
final class GoogleRetryPlan
{
    public static function next(GoogleReadAttempt $attempt, int $number, DateTimeImmutable $at, DateTimeImmutable $deadline): ?DateTimeImmutable
    {
        if ($number < 0 || $number >= 7) {
            return null;
        }
        $delay = match ($attempt->failure) {
            'google_rate_limited', 'google_native_cooldown' => max(min(120, 15 * (2 ** $number)), $attempt->retryAfterSeconds ?? 0),
            'google_service_unavailable', 'google_transport_failed' => max(min(60, 5 * (2 ** $number)), $attempt->retryAfterSeconds ?? 0),
            default => null,
        };
        if ($delay === null) {
            return null;
        }
        $next = $at->modify('+'.$delay.' seconds');

        return $next < $deadline ? $next : null;
    }
}
