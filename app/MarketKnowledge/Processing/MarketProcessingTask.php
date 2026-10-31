<?php

namespace App\MarketKnowledge\Processing;

/** Domain input only: no transport, credentials or reusable authority. */
final readonly class MarketProcessingTask
{
    public function __construct(public string $question, public array $materials, public ?array $businessContext = null) {}

    public function evidenceRefs(): array
    {
        return array_column($this->materials, 'ref');
    }
}
