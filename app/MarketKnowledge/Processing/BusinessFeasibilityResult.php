<?php

namespace App\MarketKnowledge\Processing;

use DomainException;

/** Validates declared-country applicability, not capacity or economic viability. */
final class BusinessFeasibilityResult
{
    public static function validate(MarketProcessingTask $task, array $finding): string
    {
        $status = 'requires_information';
        $reason = 'QUALIFIED_BUSINESS_CONTEXT_REQUIRED';
        if (count($task->materials) === 1 && $task->businessContext !== null) {
            $country = $task->materials[0]['fields']['geography'] ?? [];
            $markets = $task->businessContext['declared_markets'] ?? [];
            $reason = 'COUNTRY_OR_DECLARED_MARKET_UNKNOWN';
            if (($country['level'] ?? null) === 'country' && preg_match('/\A[A-Z]{2}\z/', $country['ref'] ?? '') && $markets !== []) {
                $within = in_array($country['ref'], $markets, true);
                $status = $within ? 'within_declared_market' : 'outside_declared_market';
                $reason = $within ? 'WITHIN_DECLARED_MARKET_ONLY' : 'OUTSIDE_DECLARED_MARKET_ONLY';
            }
        }
        Findings::requireMeaning($finding, $status, $reason, [
            'DECLARED_COUNTRY_IS_NOT_FULFILLMENT_COVERAGE', 'CAPACITY_NOT_ASSESSED', 'COST_NOT_ASSESSED', 'NO_COMMERCIAL_VIABILITY_CLAIM',
        ]);
        if ((array) $finding['values'] !== []) {
            throw new DomainException('Unexpected value disclosure.');
        }

        return match ($status) {
            'within_declared_market', 'outside_declared_market' => 'validated',
            'requires_information' => 'abstained',
        };
    }
}
