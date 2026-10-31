<?php

namespace App\MarketKnowledge\Processing;

use LogicException;
use Throwable;

/** Code-owned ordered alternatives; no caller-supplied class, tool or prompt. */
final class MarketMethodSelector
{
    private array $methods = [];

    public function __construct(?array $methods = null)
    {
        foreach ($methods ?? [new OfferEquivalence, new QualifiedInterestChange, new BusinessFeasibility] as $method) {
            if (! $method instanceof MarketMethod) {
                throw new LogicException('Domain-owned method required.');
            }
            $this->methods[$method->question()][] = $method;
        }
    }

    public function select(string $question): ?MarketMethod
    {
        foreach ($this->methods[$question] ?? [] as $method) {
            try {
                if ($method->available()) {
                    return $method;
                }
            } catch (Throwable) {
                // A failed optional availability probe is unavailable only for this
                // candidate. Never disclose adapter errors or skip a valid alternative.
                continue;
            }
        }

        return null;
    }
}
