<?php

namespace App\Providers\Ads;

use DateTimeImmutable;

/** Evidence interpretation only; contains no executor, scope or authority decision. */
final readonly class ProviderEvidenceClassification
{
    public function __construct(public string $state, public string $reason, public ?DateTimeImmutable $observedAt = null) {}
}
