<?php

namespace App\Providers\Ads\Snapchat;

use DateTimeImmutable;

/** Local scheduling bounds, never claims of provider quota. */
final class SnapchatRetryPlan
{
    public static function next(SnapchatReadAttempt $attempt, int $number, DateTimeImmutable $at, DateTimeImmutable $deadline): ?DateTimeImmutable
    {
        if ($number < 0 || $number >= 7) {
            return null;
        }
        $delay = match (true) {
            ($attempt->receipt['coverage'] ?? null) === 'PENDING' => max(5, $attempt->retryAfterSeconds ?? 0),
            in_array($attempt->failure, ['snapchat_rate_limited', 'snapchat_native_cooldown'], true) => max(min(120, 15 * (2 ** $number)), $attempt->retryAfterSeconds ?? 0),
            in_array($attempt->failure, ['snapchat_service_unavailable', 'snapchat_transport_failed'], true) => max(min(60, 5 * (2 ** $number)), $attempt->retryAfterSeconds ?? 0),
            default => null,
        };
        if ($delay === null) {
            return null;
        }
        $next = $at->modify('+'.$delay.' seconds');

        return $next < $deadline ? $next : null;
    }
}
