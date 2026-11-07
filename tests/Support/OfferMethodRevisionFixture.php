<?php

namespace Tests\Support;

use App\MarketKnowledge\Processing\MarketMethod;
use App\MarketKnowledge\Processing\MarketProcessingTask;
use App\MarketKnowledge\Processing\OfferEquivalence;
use LogicException;

/** Test-only coexistence probe; no future semantic method is installed or approved. */
final class OfferMethodRevisionFixture implements MarketMethod
{
    public int $probes = 0;

    public int $executions = 0;

    public function __construct(private string $revision, private bool $available = true) {}

    public function question(): string
    {
        return 'compare_offers';
    }

    public function reference(): string
    {
        return 'offer_equivalence:'.$this->revision;
    }

    public function available(): bool
    {
        $this->probes++;

        return $this->available;
    }

    public function execute(MarketProcessingTask $task): array
    {
        $this->executions++;
        if ($this->revision !== '1') {
            throw new LogicException('An unadmitted method revision must never execute.');
        }

        return (new OfferEquivalence)->execute($task);
    }
}
