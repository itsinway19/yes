<?php

namespace App\MarketKnowledge\Processing;

use LogicException;

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
            if ($method->available()) {
                return $method;
            }
        }

        return null;
    }
}
