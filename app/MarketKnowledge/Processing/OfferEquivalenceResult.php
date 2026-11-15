<?php

namespace App\MarketKnowledge\Processing;

use DomainException;

/** Independent v1 result contract; never calls the selected executor. */
final class OfferEquivalenceResult
{
    public static function validate(MarketProcessingTask $task, array $finding): string
    {
        [$status, $reason] = self::meaning($task);
        Findings::requireMeaning($finding, $status, $reason, ['SOURCE_ASSERTED_TERMS_NOT_COMPLETED_SALES']);
        if ((array) $finding['values'] !== []) {
            throw new DomainException('Unexpected value disclosure.');
        }

        return match ($status) {
            'equivalent', 'different' => 'validated',
            'unresolved' => 'abstained',
        };
    }

    private static function meaning(MarketProcessingTask $task): array
    {
        if (count($task->materials) !== 2) {
            return ['unresolved', 'TWO_OFFERS_REQUIRED'];
        }
        [$left, $right] = $task->materials;
        foreach ([$left, $right] as $material) {
            if (($material['evidence']['schema'] ?? null) !== 'market.offer:2') {
                return ['unresolved', 'QUALIFIED_PRODUCT_AND_SERVICE_TERMS_REQUIRED'];
            }
        }
        foreach (['product', 'service', 'geography'] as $field) {
            if (! isset($left['fields'][$field], $right['fields'][$field])) {
                return ['unresolved', 'LICENSED_COMPARISON_FIELDS_REQUIRED'];
            }
        }
        $a = $left['fields'];
        $b = $right['fields'];
        foreach (['manufacturer', 'model', 'variant', 'unit', 'condition'] as $key) {
            foreach ([$a, $b] as $fields) {
                if (! is_string($fields['product'][$key] ?? null) || $fields['product'][$key] === '') {
                    return ['unresolved', 'PRODUCT_IDENTITY_INCOMPLETE'];
                }
            }
            if ($a['product'][$key] !== $b['product'][$key]) {
                return ['different', 'PRODUCT_'.strtoupper($key).'_DIFFERS'];
            }
        }
        if (($a['geography']['ref'] ?? null) === null || ($b['geography']['ref'] ?? null) === null) {
            return ['unresolved', 'COVERAGE_UNKNOWN'];
        }
        if ($a['geography'] != $b['geography']) {
            return ['different', 'SERVICE_COVERAGE_DIFFERS'];
        }
        foreach (['inclusions', 'exclusions', 'warranty_months', 'fulfillment_days'] as $key) {
            $values = [$a['service'][$key] ?? null, $b['service'][$key] ?? null];
            if (in_array(null, $values, true)) {
                return ['unresolved', 'SERVICE_TERMS_INCOMPLETE'];
            }
            if (is_array($values[0]) && is_array($values[1])) {
                sort($values[0], SORT_STRING);
                sort($values[1], SORT_STRING);
            }
            if ($values[0] !== $values[1]) {
                return ['different', 'SERVICE_'.strtoupper($key).'_DIFFER'];
            }
        }

        return ['equivalent', 'QUALIFIED_IDENTITY_AND_TERMS_MATCH'];
    }
}
