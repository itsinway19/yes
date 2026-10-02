<?php

namespace App\Providers\Ads\Meta;

use DateTimeImmutable;

/** Local bounded scheduling; native delays are never shortened to fit the local execution window. */
final class MetaRetryPlan
{
    public static function next(MetaReadAttempt $attempt, int $number, DateTimeImmutable $at, DateTimeImmutable $deadline): ?DateTimeImmutable
    {
        if ($number < 0 || $number >= 7) {
            return null;
        }
        $delay = match (true) {
            ($attempt->receipt['coverage'] ?? null) === 'PENDING' => max(5, $attempt->retryAfterSeconds ?? 0),
            $attempt->failure === 'meta_rate_limited' => max(min(120, 15 * (2 ** $number)), $attempt->retryAfterSeconds ?? 0),
            $attempt->failure === 'meta_request_failed' => min(60, 5 * (2 ** $number)),
            default => null,
        };
        if ($delay === null) {
            return null;
        }
        $next = $at->modify('+'.$delay.' seconds');

        return $next < $deadline ? $next : null;
    }
}
