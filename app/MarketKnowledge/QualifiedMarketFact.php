<?php

namespace App\MarketKnowledge;

use App\MarketKnowledge\Processing\Findings;
use App\MarketKnowledge\Processing\MarketMethod;
use App\MarketKnowledge\Processing\MarketProcessingTask;
use DomainException;
use InvalidArgumentException;

/** Two explicit source-observation products for field-level correction; no market-price or demand claim. */
final readonly class QualifiedMarketFact implements MarketMethod
{
    public function __construct(private string $kind)
    {
        if (! in_array($kind, ['price', 'geography'], true)) {
            throw new InvalidArgumentException('Unsupported qualified observation.');
        }
    }

    public function question(): string
    {
        return 'record_'.$this->kind;
    }

    public function reference(): string
    {
        return 'qualified_'.$this->kind.'_observation:1';
    }

    public function available(): bool
    {
        return true;
    }

    public function execute(MarketProcessingTask $task): array
    {
        $values = $this->projection($task);

        return Findings::make($task, $this->reference(), $values === null ? 'requires_information' : 'observed',
            [$values === null ? 'QUALIFIED_FACT_FIELDS_REQUIRED' : 'QUALIFIED_SOURCE_OBSERVATION'], $values ?? [],
            [$this->kind === 'price' ? 'OBSERVED_PRICE_NOT_COMPLETED_SALE' : 'GEOGRAPHY_IS_NOT_AUDIENCE_REACH']);
    }

    public function validate(MarketProcessingTask $task, array $finding): void
    {
        $keys = array_keys($finding);
        sort($keys);
        if ($keys !== ['evidence_refs', 'limitations', 'method_ref', 'reason_codes', 'status', 'values']
            || $finding['method_ref'] !== $this->reference() || $finding['evidence_refs'] !== $task->evidenceRefs()
            || ! is_object($finding['values']) || ! in_array($finding['status'], ['observed', 'requires_information'], true)
            || $finding['limitations'] !== [$this->kind === 'price' ? 'OBSERVED_PRICE_NOT_COMPLETED_SALE' : 'GEOGRAPHY_IS_NOT_AUDIENCE_REACH']) {
            throw new DomainException('Invalid bounded observation contract.');
        }
        $expected = $this->projection($task);
        if ($finding['status'] === 'observed' && ($expected === null || (array) $finding['values'] !== $expected
            || $finding['reason_codes'] !== ['QUALIFIED_SOURCE_OBSERVATION'])) {
            throw new DomainException('Observation differs from qualified input.');
        }
        if ($finding['status'] === 'requires_information' && ((array) $finding['values'] !== []
            || $finding['reason_codes'] !== ['QUALIFIED_FACT_FIELDS_REQUIRED'])) {
            throw new DomainException('Abstention cannot disclose unqualified values.');
        }
    }

    private function projection(MarketProcessingTask $task): ?array
    {
        if (count($task->materials) !== 1) {
            return null;
        }
        $material = $task->materials[0];
        $fields = $material['fields'];
        if ($this->kind === 'geography') {
            $geography = $fields['geography'] ?? null;

            return is_array($geography) && is_string($geography['ref'] ?? null) && $geography['ref'] !== '' && ($geography['level'] ?? 'unknown') !== 'unknown'
                ? ['geography' => $geography] : null;
        }
        if (($material['evidence']['schema'] ?? null) !== 'market.offer:2') {
            return null;
        }
        foreach (['manufacturer', 'model', 'variant', 'unit', 'condition'] as $key) {
            if (! is_string($fields['product'][$key] ?? null) || $fields['product'][$key] === '') {
                return null;
            }
        }
        $price = $fields['price'] ?? null;
        $currency = $fields['currency'] ?? null;

        return (is_int($price) || is_float($price)) && is_finite((float) $price) && $price >= 0 && $price <= 1000000000000
            && is_string($currency) && preg_match('/\A[A-Z]{3}\z/', $currency) === 1 ? ['price' => $price, 'currency' => $currency] : null;
    }
}
