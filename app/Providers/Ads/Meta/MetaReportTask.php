<?php

namespace App\Providers\Ads\Meta;

use DateTimeImmutable;
use InvalidArgumentException;

/** Transient reporting computation identity, not durable acceptance or a reusable provider cursor. */
final readonly class MetaReportTask
{
    public function __construct(public string $id, public string $requestFingerprint, public DateTimeImmutable $createdAt)
    {
        if (! preg_match('/^[1-9][0-9]{0,39}$/D', $id) || ! preg_match('/^[a-f0-9]{64}$/D', $requestFingerprint)) {
            throw new InvalidArgumentException('Invalid Meta report task identity.');
        }
    }
}
