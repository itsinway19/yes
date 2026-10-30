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

    public static function validate(MarketProcessingTask $task, MarketMethod $method, array $finding): void
    {
        $keys = array_keys($finding);
        sort($keys);
        if ($keys !== ['evidence_refs', 'limitations', 'method_ref', 'reason_codes', 'status', 'values']
            || $finding['method_ref'] !== $method->reference() || $finding['evidence_refs'] !== $task->evidenceRefs()
            || ! is_object($finding['values']) || ! is_array($finding['reason_codes']) || $finding['reason_codes'] === []
            || ! is_array($finding['limitations'])) {
            throw new DomainException('Invalid method output contract.');
        }
        $statuses = match ($method->reference()) {
            'offer_equivalence:1' => ['equivalent', 'different', 'unresolved'],
            'qualified_interest_change:1' => ['comparable', 'not_comparable'],
            'business_feasibility:1' => ['within_declared_market', 'outside_declared_market', 'requires_information'],
            default => [],
        };
        if (! in_array($finding['status'], $statuses, true)) {
            throw new DomainException('Unsupported finding state.');
        }
        foreach ([...$finding['reason_codes'], ...$finding['limitations']] as $code) {
            if (! is_string($code) || ! preg_match('/\A[A-Z][A-Z0-9_]{1,100}\z/', $code)) {
                throw new DomainException('Bounded non-sensitive reason required.');
            }
        }
        if ($method->reference() === 'qualified_interest_change:1' && $finding['status'] === 'comparable') {
            $values = (array) $finding['values'];
            $before = $task->materials[0]['fields']['value'];
            $after = $task->materials[1]['fields']['value'];
            foreach ([$before, $after] as $value) {
                if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < 0 || $value > 1000000000000) {
                    throw new DomainException('Independent arithmetic validation failed.');
                }
            }
            if ($before <= 0 || ! isset($values['delta']) || (! is_int($values['delta']) && ! is_float($values['delta']))
                || ! is_finite((float) $values['delta'])
                || abs($values['delta'] - ($after - $before)) > 0.00001) {
                throw new DomainException('Independent arithmetic validation failed.');
            }
            $ratio = $task->materials[0]['fields']['comparison_basis']['ratio_scale'];
            if (! is_bool($ratio) || $ratio !== $task->materials[1]['fields']['comparison_basis']['ratio_scale']) {
                throw new DomainException('Ratio requires a qualified ratio scale.');
            }
            if ($ratio) {
                // Validate the declared rounded percentage directly, using original
                // values rather than reconstructing a value from a rounded ratio.
                $expected = round(((float) $after - $before) / $before * 100, 8);
                // At most one relative machine epsilon, capped at 1% of a
                // declared decimal unit so a 0.00000001 alteration cannot pass.
                $tolerance = min(0.0000000001, PHP_FLOAT_EPSILON * max(1.0, abs($expected)));
                if (! is_finite($expected) || ! isset($values['percent_change'])
                    || (! is_int($values['percent_change']) && ! is_float($values['percent_change']))
                    || ! is_finite((float) $values['percent_change'])
                    || abs($values['percent_change'] - $expected) > $tolerance) {
                    throw new DomainException('Independent ratio validation failed.');
                }
            } elseif (! array_key_exists('percent_change', $values) || $values['percent_change'] !== null) {
                throw new DomainException('Ratio requires a qualified ratio scale.');
            }
        } elseif ((array) $finding['values'] !== []) {
            throw new DomainException('Unexpected value disclosure.');
        }
    }
}
