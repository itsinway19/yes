<?php

namespace App\Providers\Ads\X;

use DateTimeImmutable;

final class XRetryPlan
{
    public static function next(string $reason, ?int $seconds, int $number, DateTimeImmutable $at, DateTimeImmutable $deadline): ?DateTimeImmutable
    {
        if ($number < 0 || $number >= 7) {
            return null;
        }
        $delay = match ($reason) {
            'x_rate_limited', 'x_native_cooldown' => $seconds !== null && $seconds > 0 ? $seconds : null,
            'x_service_unavailable', 'x_transport_failed' => max(min(60, 5 * (2 ** $number)), $seconds ?? 0),
            default => null,
        };
        if ($delay === null || $delay > 31536000 || $delay < 1) {
            return null;
        }
        $next = $at->modify('+'.$delay.' seconds');

        return $next < $deadline ? $next : null;
    }
}
