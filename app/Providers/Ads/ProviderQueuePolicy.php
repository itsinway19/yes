<?php

namespace App\Providers\Ads;

use Illuminate\Queue\Middleware\WithoutOverlapping;
use InvalidArgumentException;

/** Reusable isolation for native provider jobs and common acceptance jobs; no provider quota claims. */
final class ProviderQueuePolicy
{
    public function queue(ProviderScope $scope): string
    {
        return 'bb2.provider.'.$scope->provider->value;
    }

    public function middleware(ProviderScope $scope, string $stream, int $timeoutSeconds,
        int $releaseSeconds, int $leaseSeconds): array
    {
        if ($timeoutSeconds < 1 || $releaseSeconds < 1 || $leaseSeconds <= $timeoutSeconds || $leaseSeconds > 3600) {
            throw new InvalidArgumentException('Bounded provider job timeout and lock lease are required.');
        }

        return [(new WithoutOverlapping($scope->key('queue:'.$stream)))
            ->shared()->releaseAfter($releaseSeconds)->expireAfter($leaseSeconds)];
    }
}
