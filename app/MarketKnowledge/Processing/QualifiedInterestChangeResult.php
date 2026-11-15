<?php

namespace App\MarketKnowledge\Processing;

use DateTimeImmutable;
use DomainException;

/** Requalifies v1 comparability and recomputes arithmetic independently of the executor. */
final class QualifiedInterestChangeResult
{
    public static function validate(MarketProcessingTask $task, array $finding): string
    {
        $reason = self::incomparability($task);
        Findings::requireMeaning($finding, $reason === null ? 'comparable' : 'not_comparable', $reason ?? 'QUALIFIED_COMPARABLE_PERIODS',
            $reason === null ? ['INTEREST_IS_NOT_BUYERS', 'ROUNDED_TO_EIGHT_DECIMALS'] : ['INTEREST_IS_NOT_BUYERS']);
        if ($reason !== null) {
            if ((array) $finding['values'] !== []) {
                throw new DomainException('Unexpected value disclosure.');
            }

            return 'abstained';
        }
        self::arithmetic($task, (array) $finding['values']);

        return 'validated';
    }

    private static function incomparability(MarketProcessingTask $task): ?string
    {
        if (count($task->materials) !== 2) {
            return 'TWO_SIGNALS_REQUIRED';
        }
        [$a, $b] = $task->materials;
        foreach ([$a, $b] as $material) {
            if (($material['evidence']['schema'] ?? null) !== 'market.interest_signal:2') {
                return 'QUALIFIED_COMPARISON_BASIS_REQUIRED';
            }
        }
        $source = $a['evidence']['source_ref'] ?? null;
        if (! is_string($source) || $source === '' || $source !== ($b['evidence']['source_ref'] ?? null)) {
            return 'SOURCE_COMPARABILITY_UNQUALIFIED';
        }
        $a = $a['fields'];
        $b = $b['fields'];
        foreach (['subject', 'geography', 'metric', 'comparison_basis', 'value'] as $field) {
            if (! array_key_exists($field, $a) || ! array_key_exists($field, $b)) {
                return 'LICENSED_COMPARISON_FIELDS_REQUIRED';
            }
        }
        if (($a['subject']['identity_state'] ?? null) !== 'source_asserted' || ($b['subject']['identity_state'] ?? null) !== 'source_asserted'
            || ! is_string($a['subject']['key']) || $a['subject']['key'] === '' || $a['subject']['key'] !== $b['subject']['key']) {
            return 'SUBJECT_NOT_EQUIVALENT';
        }
        if ($a['geography']['ref'] === null || $a['geography'] != $b['geography']) {
            return 'GEOGRAPHY_NOT_EQUIVALENT';
        }
        if ($a['metric'] != $b['metric']) {
            return 'METRIC_NOT_EQUIVALENT';
        }
        foreach (['sampling', 'normalization', 'population', 'aggregation', 'ratio_scale'] as $key) {
            if (! isset($a['comparison_basis'][$key], $b['comparison_basis'][$key]) || $a['comparison_basis'][$key] !== $b['comparison_basis'][$key]) {
                return 'COMPARISON_BASIS_NOT_EQUIVALENT';
            }
        }
        $periods = [];
        foreach ([$a, $b] as $fields) {
            $periods[] = [new DateTimeImmutable($fields['comparison_basis']['period_start']), new DateTimeImmutable($fields['comparison_basis']['period_end'])];
        }
        [[$from, $until], [$next, $end]] = $periods;
        if ($until <= $from || $end <= $next || $until > $next || $until->getTimestamp() - $from->getTimestamp() !== $end->getTimestamp() - $next->getTimestamp()) {
            return 'PERIODS_NOT_COMPARABLE';
        }
        if ($a['value'] === null || $b['value'] === null) {
            return 'VALUE_UNKNOWN';
        }
        foreach ([$a['value'], $b['value']] as $value) {
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < 0 || $value > 1000000000000) {
                return 'VALUE_OUTSIDE_METHOD_RANGE';
            }
        }

        return $a['value'] == 0 ? 'ZERO_BASELINE' : null;
    }

    private static function arithmetic(MarketProcessingTask $task, array $values): void
    {
        $keys = array_keys($values);
        sort($keys);
        if ($keys !== ['delta', 'percent_change']) {
            throw new DomainException('Exact numeric result fields required.');
        }
        $before = $task->materials[0]['fields']['value'];
        $after = $task->materials[1]['fields']['value'];
        // Compare the declared rounded result, not an unrounded difference
        // with a tolerance that can accept a false direction near zero.
        $expectedDelta = round((float) $after - $before, 8);
        if (! isset($values['delta']) || (! is_int($values['delta']) && ! is_float($values['delta']))
            || ! is_finite((float) $values['delta']) || (float) $values['delta'] !== $expectedDelta) {
            throw new DomainException('Independent arithmetic validation failed.');
        }
        $ratio = $task->materials[0]['fields']['comparison_basis']['ratio_scale'];
        if (! is_bool($ratio) || $ratio !== $task->materials[1]['fields']['comparison_basis']['ratio_scale']) {
            throw new DomainException('Ratio requires a qualified ratio scale.');
        }
        if ($ratio) {
            // Recompute from original values, not from a rounded ratio or delta.
            $expected = round(((float) $after - $before) / $before * 100, 8);
            // Preserve the existing relative epsilon, capped below one decimal unit.
            $tolerance = min(0.0000000001, PHP_FLOAT_EPSILON * max(1.0, abs($expected)));
            if (! is_finite($expected) || ! isset($values['percent_change'])
                || (! is_int($values['percent_change']) && ! is_float($values['percent_change']))
                || ! is_finite((float) $values['percent_change']) || abs($values['percent_change'] - $expected) > $tolerance) {
                throw new DomainException('Independent ratio validation failed.');
            }
        } elseif ($values['percent_change'] !== null) {
            throw new DomainException('Ratio requires a qualified ratio scale.');
        }
    }
}
