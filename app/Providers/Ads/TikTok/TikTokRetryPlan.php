<?php

namespace App\Providers\Ads\TikTok;

use DateTimeImmutable;

/** Local scheduling bounds, never claims of provider quota. */
final class TikTokRetryPlan
{
    public static function next(TikTokReadAttempt $attempt, int $number, DateTimeImmutable $at, DateTimeImmutable $deadline): ?DateTimeImmutable
    {
        if ($number < 0 || $number >= 7) {
            return null;
        }
        $delay = match ($attempt->failure) {
            'tiktok_rate_limited', 'tiktok_native_cooldown' => max(min(120, 15 * (2 ** $number)), $attempt->retryAfterSeconds ?? 0),
            'tiktok_service_unavailable', 'tiktok_transport_failed' => max(min(60, 5 * (2 ** $number)), $attempt->retryAfterSeconds ?? 0),
            default => null,
        };
        if ($delay === null) {
            return null;
        }
        $next = $at->modify('+'.$delay.' seconds');

        return $next < $deadline ? $next : null;
    }
}
