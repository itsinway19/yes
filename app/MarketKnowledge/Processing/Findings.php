<?php

namespace App\MarketKnowledge\Processing;

use DomainException;

final class Findings
{
    public static function make(MarketProcessingTask $task, string $method, string $status, array $reasons, array $values = [], array $limits = []): array
    {
        return ['method_ref' => $method, 'status' => $status, 'reason_codes' => $reasons,
            'values' => (object) $values, 'limitations' => $limits, 'evidence_refs' => $task->evidenceRefs()];
    }

    public static function validate(MarketProcessingTask $task, MarketMethod $method, array $finding): string
    {
        $keys = array_keys($finding);
        sort($keys);
        if ($keys !== ['evidence_refs', 'limitations', 'method_ref', 'reason_codes', 'status', 'values']
            || $finding['method_ref'] !== $method->reference() || $finding['evidence_refs'] !== $task->evidenceRefs()
            || ! is_object($finding['values']) || ! is_array($finding['reason_codes']) || $finding['reason_codes'] === []
            || ! is_array($finding['limitations'])) {
            throw new DomainException('Invalid method output contract.');
        }
        foreach ([...$finding['reason_codes'], ...$finding['limitations']] as $code) {
            if (! is_string($code) || ! preg_match('/\A[A-Z][A-Z0-9_]{1,100}\z/', $code)) {
                throw new DomainException('Bounded non-sensitive reason required.');
            }
        }
        $question = match ($method->reference()) {
            'offer_equivalence:1' => 'compare_offers',
            'qualified_interest_change:1' => 'compare_interest',
            'business_feasibility:1' => 'assess_business_feasibility',
            default => throw new DomainException('Unsupported method result contract.'),
        };
        if ($method->question() !== $question || $task->question !== $question) {
            throw new DomainException('Exact method question required.');
        }

        return match ($method->reference()) {
            'offer_equivalence:1' => OfferEquivalenceResult::validate($task, $finding),
            'qualified_interest_change:1' => QualifiedInterestChangeResult::validate($task, $finding),
            'business_feasibility:1' => BusinessFeasibilityResult::validate($task, $finding),
        };
    }

    /** Fixed-version meanings include the bounded reason and disclosure limits. */
    public static function requireMeaning(array $finding, string $status, string $reason, array $limits): void
    {
        if ($finding['status'] !== $status || $finding['reason_codes'] !== [$reason] || $finding['limitations'] !== $limits) {
            throw new DomainException('Finding does not match independently qualified meaning.');
        }
    }
}
