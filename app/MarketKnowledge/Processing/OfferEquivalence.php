<?php

namespace App\MarketKnowledge\Processing;

final class OfferEquivalence implements MarketMethod
{
    public function question(): string
    {
        return 'compare_offers';
    }

    public function reference(): string
    {
        return 'offer_equivalence:1';
    }

    public function available(): bool
    {
        return true;
    }

    public function execute(MarketProcessingTask $task): array
    {
        $make = fn ($status, $reason) => Findings::make($task, $this->reference(), $status, [$reason], limits: ['SOURCE_ASSERTED_TERMS_NOT_COMPLETED_SALES']);
        if (count($task->materials) !== 2) {
            return $make('unresolved', 'TWO_OFFERS_REQUIRED');
        }
        [$a, $b] = $task->materials;
        if (($a['evidence']['schema'] ?? null) !== 'market.offer:2' || ($b['evidence']['schema'] ?? null) !== 'market.offer:2') {
            return $make('unresolved', 'QUALIFIED_PRODUCT_AND_SERVICE_TERMS_REQUIRED');
        }
        foreach (['product', 'service', 'geography'] as $field) {
            if (! isset($a['fields'][$field], $b['fields'][$field])) {
                return $make('unresolved', 'LICENSED_COMPARISON_FIELDS_REQUIRED');
            }
        }
        foreach (['manufacturer', 'model', 'variant', 'unit', 'condition'] as $key) {
            $left = $a['fields']['product'][$key] ?? null;
            $right = $b['fields']['product'][$key] ?? null;
            if (! is_string($left) || $left === '' || ! is_string($right) || $right === '') {
                return $make('unresolved', 'PRODUCT_IDENTITY_INCOMPLETE');
            }
            if ($left !== $right) {
                return $make('different', 'PRODUCT_'.strtoupper($key).'_DIFFERS');
            }
        }
        if (($a['fields']['geography']['ref'] ?? null) === null || ($b['fields']['geography']['ref'] ?? null) === null) {
            return $make('unresolved', 'COVERAGE_UNKNOWN');
        }
        if ($a['fields']['geography'] != $b['fields']['geography']) {
            return $make('different', 'SERVICE_COVERAGE_DIFFERS');
        }
        foreach (['inclusions', 'exclusions', 'warranty_months', 'fulfillment_days'] as $key) {
            $left = $a['fields']['service'][$key] ?? null;
            $right = $b['fields']['service'][$key] ?? null;
            if ($left === null || $right === null) {
                return $make('unresolved', 'SERVICE_TERMS_INCOMPLETE');
            }
            if (is_array($left) && is_array($right)) {
                sort($left, SORT_STRING);
                sort($right, SORT_STRING);
            }
            if ($left !== $right) {
                return $make('different', 'SERVICE_'.strtoupper($key).'_DIFFER');
            }
        }

        return $make('equivalent', 'QUALIFIED_IDENTITY_AND_TERMS_MATCH');
    }
}
