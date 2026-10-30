<?php

namespace App\MarketKnowledge\Processing;

/** Declared-country applicability only; operational facts retain their own owners. */
final class BusinessFeasibility implements MarketMethod
{
    public function question(): string
    {
        return 'assess_business_feasibility';
    }

    public function reference(): string
    {
        return 'business_feasibility:1';
    }

    public function available(): bool
    {
        return true;
    }

    public function execute(MarketProcessingTask $task): array
    {
        $make = fn ($status, $reason) => Findings::make($task, $this->reference(), $status, [$reason], limits: [
            'DECLARED_COUNTRY_IS_NOT_FULFILLMENT_COVERAGE', 'CAPACITY_NOT_ASSESSED', 'COST_NOT_ASSESSED', 'NO_COMMERCIAL_VIABILITY_CLAIM',
        ]);
        if (count($task->materials) !== 1 || $task->businessContext === null) {
            return $make('requires_information', 'QUALIFIED_BUSINESS_CONTEXT_REQUIRED');
        }
        $geography = $task->materials[0]['fields']['geography'] ?? null;
        if (($geography['level'] ?? null) !== 'country' || ! preg_match('/\A[A-Z]{2}\z/', $geography['ref'] ?? '') || ($task->businessContext['declared_markets'] ?? []) === []) {
            return $make('requires_information', 'COUNTRY_OR_DECLARED_MARKET_UNKNOWN');
        }

        return in_array($geography['ref'], $task->businessContext['declared_markets'], true)
            ? $make('within_declared_market', 'WITHIN_DECLARED_MARKET_ONLY')
            : $make('outside_declared_market', 'OUTSIDE_DECLARED_MARKET_ONLY');
    }
}
