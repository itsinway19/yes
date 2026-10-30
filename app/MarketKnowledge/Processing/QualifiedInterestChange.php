<?php

namespace App\MarketKnowledge\Processing;

use DateTimeImmutable;

final class QualifiedInterestChange implements MarketMethod
{
    public function question(): string
    {
        return 'compare_interest';
    }

    public function reference(): string
    {
        return 'qualified_interest_change:1';
    }

    public function available(): bool
    {
        return true;
    }

    public function execute(MarketProcessingTask $task): array
    {
        $no = fn ($reason) => Findings::make($task, $this->reference(), 'not_comparable', [$reason], limits: ['INTEREST_IS_NOT_BUYERS']);
        if (count($task->materials) !== 2) {
            return $no('TWO_SIGNALS_REQUIRED');
        }
        [$a, $b] = $task->materials;
        if (($a['evidence']['schema'] ?? null) !== 'market.interest_signal:2' || ($b['evidence']['schema'] ?? null) !== 'market.interest_signal:2') {
            return $no('QUALIFIED_COMPARISON_BASIS_REQUIRED');
        }
        $a = $a['fields'];
        $b = $b['fields'];
        foreach (['subject', 'geography', 'metric', 'comparison_basis', 'value'] as $key) {
            if (! array_key_exists($key, $a) || ! array_key_exists($key, $b)) {
                return $no('LICENSED_COMPARISON_FIELDS_REQUIRED');
            }
        }
        if (($a['subject']['identity_state'] ?? null) !== 'source_asserted' || ($b['subject']['identity_state'] ?? null) !== 'source_asserted'
            || ! is_string($a['subject']['key']) || $a['subject']['key'] === '' || $a['subject']['key'] !== $b['subject']['key']) {
            return $no('SUBJECT_NOT_EQUIVALENT');
        }
        if ($a['geography']['ref'] === null || $a['geography'] != $b['geography']) {
            return $no('GEOGRAPHY_NOT_EQUIVALENT');
        }
        if ($a['metric'] != $b['metric']) {
            return $no('METRIC_NOT_EQUIVALENT');
        }
        foreach (['sampling', 'normalization', 'population', 'aggregation', 'ratio_scale'] as $key) {
            if (! isset($a['comparison_basis'][$key], $b['comparison_basis'][$key]) || $a['comparison_basis'][$key] !== $b['comparison_basis'][$key]) {
                return $no('COMPARISON_BASIS_NOT_EQUIVALENT');
            }
        }
        $from = new DateTimeImmutable($a['comparison_basis']['period_start']);
        $until = new DateTimeImmutable($a['comparison_basis']['period_end']);
        $next = new DateTimeImmutable($b['comparison_basis']['period_start']);
        $end = new DateTimeImmutable($b['comparison_basis']['period_end']);
        if ($until <= $from || $end <= $next || $until > $next || ($until->getTimestamp() - $from->getTimestamp()) !== ($end->getTimestamp() - $next->getTimestamp())) {
            return $no('PERIODS_NOT_COMPARABLE');
        }
        if ($a['value'] === null || $b['value'] === null) {
            return $no('VALUE_UNKNOWN');
        }
        foreach ([$a['value'], $b['value']] as $value) {
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < 0 || $value > 1000000000000) {
                return $no('VALUE_OUTSIDE_METHOD_RANGE');
            }
        }
        if ($a['value'] == 0) {
            return $no('ZERO_BASELINE');
        }
        $delta = (float) $b['value'] - $a['value'];
        $ratio = $a['comparison_basis']['ratio_scale'];

        return Findings::make($task, $this->reference(), 'comparable', ['QUALIFIED_COMPARABLE_PERIODS'],
            ['delta' => round($delta, 8), 'percent_change' => $ratio ? round($delta / $a['value'] * 100, 8) : null],
            ['INTEREST_IS_NOT_BUYERS', 'ROUNDED_TO_EIGHT_DECIMALS']);
    }
}
