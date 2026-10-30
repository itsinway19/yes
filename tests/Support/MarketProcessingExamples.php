<?php

namespace Tests\Support;

final class MarketProcessingExamples
{
    public static function offer(): array
    {
        return ['subject' => ['key' => 'synthetic:product-model', 'label' => 'SYNTHETIC', 'identity_state' => 'source_asserted'],
            'geography' => ['ref' => 'SA', 'level' => 'country'], 'currency' => 'SAR', 'price' => 200,
            'inclusions' => ['delivery', 'installation'],
            'product' => ['manufacturer' => 'synthetic-maker', 'model' => 'model-a', 'variant' => 'base', 'unit' => 'one-unit', 'condition' => 'new'],
            'service' => ['inclusions' => ['delivery', 'installation'], 'exclusions' => [], 'warranty_months' => 12, 'fulfillment_days' => 2]];
    }

    public static function signal(bool $after = false): array
    {
        return ['subject' => ['key' => 'synthetic:interest', 'label' => 'SYNTHETIC INTEREST; NOT DEMAND', 'identity_state' => 'source_asserted'],
            'geography' => ['ref' => 'SA', 'level' => 'country'],
            'metric' => ['name' => 'synthetic-interest', 'unit' => 'index', 'definition_version' => '1'], 'value' => $after ? 50 : 40,
            'comparison_basis' => ['sampling' => 'same-defined-sample', 'normalization' => 'same-fixed-base', 'population' => 'same-qualified-population',
                'aggregation' => 'daily_mean', 'ratio_scale' => true, 'period_start' => $after ? '2020-01-02T00:00:00Z' : '2020-01-01T00:00:00Z',
                'period_end' => $after ? '2020-01-03T00:00:00Z' : '2020-01-02T00:00:00Z']];
    }

    public static function material(array $fields, string $schema, int $key = 1): array
    {
        return ['ref' => ['namespace' => 'market.evidence', 'key' => 'synthetic-'.$key, 'version' => str_repeat((string) $key, 64)],
            'fields' => $fields, 'coverage' => 'COMPLETE', 'evidence' => ['schema' => $schema]];
    }
}
