<?php

namespace Tests\Unit;

use App\MarketKnowledge\Processing\BusinessFeasibility;
use App\MarketKnowledge\Processing\Findings;
use App\MarketKnowledge\Processing\MarketMethod;
use App\MarketKnowledge\Processing\MarketMethodSelector;
use App\MarketKnowledge\Processing\MarketProcessingTask;
use App\MarketKnowledge\Processing\OfferEquivalence;
use App\MarketKnowledge\Processing\QualifiedInterestChange;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\MarketProcessingExamples as E;

final class MarketProcessingMethodsTest extends TestCase
{
    private function oracle(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/market-processing-oracles.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_offer_oracles_preserve_identity_and_material_terms_without_name_matching(): void
    {
        $method = new OfferEquivalence;
        foreach ($this->oracle()['offer'] as $case) {
            $other = E::offer();
            foreach ($case['change'] as $path => $value) {
                [$field, $key] = explode('.', $path);
                $other[$field][$key] = $value;
            }
            $task = new MarketProcessingTask($method->question(), [E::material(E::offer(), 'market.offer:2'), E::material($other, 'market.offer:2', 2)]);
            $finding = $method->execute($task);
            $this->assertSame($case['status'], $finding['status'], $case['case']);
            $this->assertContains($case['reason'], $finding['reason_codes']);
            Findings::validate($task, $method, $finding);
        }
        $old = new MarketProcessingTask($method->question(), [E::material(E::offer(), 'market.offer:1'), E::material(E::offer(), 'market.offer:1', 2)]);
        $this->assertSame('unresolved', $method->execute($old)['status']);
        $limited = E::material(['price' => 200], 'market.offer:2');
        $this->assertSame('unresolved', $method->execute(new MarketProcessingTask($method->question(), [$limited, $limited]))['status']);
    }

    public function test_interest_oracles_check_math_unknown_zero_and_independent_result_validation(): void
    {
        $method = new QualifiedInterestChange;
        foreach ($this->oracle()['interest'] as $case) {
            $a = E::signal();
            $a['value'] = $case['before'];
            $b = E::signal(true);
            $b['value'] = $case['after'];
            $task = new MarketProcessingTask($method->question(), [E::material($a, 'market.interest_signal:2'), E::material($b, 'market.interest_signal:2', 2)]);
            $finding = $method->execute($task);
            $this->assertSame($case['status'], $finding['status'], $case['case']);
            if (isset($case['delta'])) {
                $this->assertSame($case['delta'], $finding['values']->delta);
                $this->assertSame($case['percent'], $finding['values']->percent_change);
            } else {
                $this->assertContains($case['reason'], $finding['reason_codes']);
            }
            Findings::validate($task, $method, $finding);
        }
        $task = new MarketProcessingTask($method->question(), [E::material(E::signal(), 'market.interest_signal:2'), E::material(E::signal(true), 'market.interest_signal:2', 2)]);
        $forged = $method->execute($task);
        $forged['values']->delta = 99;
        $this->expectException(DomainException::class);
        Findings::validate($task, $method, $forged);
    }

    public function test_reported_large_drop_validates_the_eight_decimal_percentage(): void
    {
        $method = new QualifiedInterestChange;
        $before = E::signal();
        $after = E::signal(true);
        $before['value'] = 9876543;
        $after['value'] = 1;
        $task = new MarketProcessingTask($method->question(), [E::material($before, 'market.interest_signal:2'), E::material($after, 'market.interest_signal:2', 2)]);
        $finding = $method->execute($task);

        $this->assertSame('comparable', $finding['status']);
        $this->assertSame(-9876542.0, $finding['values']->delta);
        $this->assertSame(-99.99998987, $finding['values']->percent_change);
        Findings::validate($task, $method, $finding);
    }

    public static function roundedInterestCases(): array
    {
        return [
            'recurring increase' => [3, 4, 1.0, 33.33333333],
            'recurring decrease' => [3, 2, -1.0, -33.33333333],
            'round last decimal upward' => [6, 7, 1.0, 16.66666667],
            'upper baseline to zero' => [1000000000000, 0, -1000000000000.0, -100.0],
            'upper baseline unchanged' => [1000000000000, 1000000000000, 0.0, 0.0],
            'upper baseline tiny remainder' => [1000000000000, 1, -999999999999.0, -100.0],
            'small baseline to upper boundary' => [0.000000000001, 1000000000000, 1000000000000.0, 1.0e26],
            'small baseline doubles' => [0.000000000001, 0.000000000002, 0.0, 100.0],
            'near zero recurring ratio uses unrounded delta' => [0.000000000003, 0.000000000004, 0.0, 33.33333333],
            'minimum normal baseline' => [PHP_FLOAT_MIN, PHP_FLOAT_MIN * 2, 0.0, 100.0],
            'minimum subnormal baseline' => [5.0e-324, 1.0e-323, 0.0, 100.0],
            'minimum subnormal drops to zero' => [5.0e-324, 0, 0.0, -100.0],
        ];
    }

    #[DataProvider('roundedInterestCases')]
    public function test_legitimate_rounded_percentages_at_numeric_boundaries(int|float $before, int|float $after, float $delta, float $percent): void
    {
        $method = new QualifiedInterestChange;
        $task = $this->interestTask($before, $after);
        $finding = $method->execute($task);
        $this->assertSame('comparable', $finding['status']);
        $this->assertSame($delta, $finding['values']->delta);
        $this->assertSame($percent, $finding['values']->percent_change);
        Findings::validate($task, $method, $finding);
    }

    public static function alteredPercentageCases(): array
    {
        return [
            'positive last decimal' => [3, 4, 33.33333334],
            'negative last decimal' => [3, 2, -33.33333334],
            'reported example last decimal' => [9876543, 1, -99.99998986],
            'zero last decimal' => [1, 1, 0.00000001],
            'small baseline last decimal' => [0.000000000001, 0.000000000002, 100.00000001],
        ];
    }

    public static function alteredDeltaCases(): array
    {
        return [
            'small decline reported as growth' => [0.000002, 0.000001, 0.000001],
            'small growth reported as decline' => [0.000001, 0.000002, -0.000001],
            'unchanged reported as growth' => [1, 1, 0.00000001],
            'zero reported as minimum-normal growth' => [1, 1, PHP_FLOAT_MIN],
            'rounded zero reported as sub-quantum growth' => [0.000000000003, 0.000000000004, 0.00000000000000001],
            'last decimal increase removed' => [1, 1.00000001, 0.0],
            'positive last decimal altered' => [3, 4, 1.00000001],
            'negative last decimal altered' => [3, 2, -1.00000001],
            'sub-quantum increase rounded incorrectly' => [0.000000000003, 0.000000000004, 0.00000001],
            'large delta altered at representable precision' => [1000000000000, 0, -999999999999.9999],
        ];
    }

    #[DataProvider('alteredDeltaCases')]
    public function test_altered_delta_is_rejected_with_or_without_a_ratio_scale(int|float $before, int|float $after, float $delta): void
    {
        $method = new QualifiedInterestChange;
        foreach ([true, false] as $ratio) {
            $task = $this->interestTask($before, $after, $ratio);
            $finding = $method->execute($task);
            Findings::validate($task, $method, $finding);
            $finding['values']->delta = $delta;
            try {
                Findings::validate($task, $method, $finding);
                $this->fail('An altered delta must not validate, regardless of ratio-scale qualification.');
            } catch (DomainException $exception) {
                $this->assertSame('Independent arithmetic validation failed.', $exception->getMessage());
            }
        }
    }

    public static function roundedDeltaCases(): array
    {
        return [
            'small decline' => [0.000002, 0.000001, -0.000001],
            'small increase' => [0.000001, 0.000002, 0.000001],
            'eighth decimal retained' => [1, 1.00000001, 0.00000001],
            'ninth decimal rounded up' => [1, 1.123456789, 0.12345679],
            'negative ninth decimal rounded' => [1.123456789, 1, -0.12345679],
            'sub-quantum increase' => [0.000000000003, 0.000000000004, 0.0],
        ];
    }

    #[DataProvider('roundedDeltaCases')]
    public function test_legitimate_delta_rounding_preserves_ratio_and_non_ratio_outputs(int|float $before, int|float $after, float $delta): void
    {
        $method = new QualifiedInterestChange;
        foreach ([true, false] as $ratio) {
            $task = $this->interestTask($before, $after, $ratio);
            $finding = $method->execute($task);
            $this->assertSame('comparable', $finding['status']);
            $this->assertSame($delta, $finding['values']->delta);
            $this->assertSame($ratio ? round(((float) $after - $before) / $before * 100, 8) : null, $finding['values']->percent_change);
            Findings::validate($task, $method, $finding);
            $roundTrip = json_decode(json_encode($finding, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $roundTrip['values'] = (object) $roundTrip['values'];
            Findings::validate($task, $method, $roundTrip);
        }
    }

    #[DataProvider('alteredPercentageCases')]
    public function test_one_declared_decimal_unit_of_percentage_tampering_is_rejected(int|float $before, int|float $after, float $percent): void
    {
        $method = new QualifiedInterestChange;
        $task = $this->interestTask($before, $after);
        $finding = $method->execute($task);
        $finding['values']->percent_change = $percent;
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Independent ratio validation failed.');
        Findings::validate($task, $method, $finding);
    }

    public static function invalidArithmeticCases(): array
    {
        return [
            'infinite baseline' => ['before', INF], 'nan baseline' => ['before', NAN],
            'infinite after' => ['after', INF], 'nan after' => ['after', NAN],
            'negative baseline' => ['before', -1], 'zero baseline' => ['before', 0],
            'negative after' => ['after', -1], 'above baseline range' => ['before', 1000000000001],
            'above after range' => ['after', 1000000000001], 'numeric string baseline' => ['before', '40'],
            'unknown after' => ['after', null], 'boolean baseline' => ['before', true],
            'wrong delta' => ['delta', 11], 'infinite delta' => ['delta', INF], 'nan delta' => ['delta', NAN],
            'numeric string delta' => ['delta', '10'], 'infinite percentage' => ['percent_change', INF],
            'nan percentage' => ['percent_change', NAN], 'numeric string percentage' => ['percent_change', '25'],
            'boolean percentage' => ['percent_change', true], 'unknown percentage' => ['percent_change', null],
            'numeric ratio scale' => ['ratio_scale', 1], 'string ratio scale' => ['ratio_scale', 'true'],
            'unknown ratio scale' => ['ratio_scale', null], 'mismatched ratio scales' => ['other_ratio_scale', false],
            'percentage without ratio scale' => ['ratio_scale', false],
            'overflowing percentage' => ['before', 5.0e-324],
        ];
    }

    #[DataProvider('invalidArithmeticCases')]
    public function test_invalid_arithmetic_cannot_validate_a_comparable_finding(string $field, mixed $value): void
    {
        $method = new QualifiedInterestChange;
        $task = $this->interestTask(40, 50);
        $finding = $method->execute($task);
        $materials = $task->materials;
        if ($field === 'before' || $field === 'after') {
            $materials[$field === 'before' ? 0 : 1]['fields']['value'] = $value;
        } elseif ($field === 'ratio_scale') {
            $materials[0]['fields']['comparison_basis']['ratio_scale'] = $value;
            $materials[1]['fields']['comparison_basis']['ratio_scale'] = $value;
        } elseif ($field === 'other_ratio_scale') {
            $materials[1]['fields']['comparison_basis']['ratio_scale'] = $value;
        } else {
            $finding['values']->{$field} = $value;
        }
        $task = new MarketProcessingTask($method->question(), $materials);
        $this->expectException(DomainException::class);
        Findings::validate($task, $method, $finding);
    }

    public function test_zero_baseline_still_abstains_without_a_percentage(): void
    {
        $method = new QualifiedInterestChange;
        foreach ([0, 5.0e-324, 1000000000000] as $after) {
            $task = $this->interestTask(0, $after);
            $finding = $method->execute($task);
            $this->assertSame('not_comparable', $finding['status']);
            $this->assertSame(['ZERO_BASELINE'], $finding['reason_codes']);
            $this->assertEquals((object) [], $finding['values']);
            Findings::validate($task, $method, $finding);
        }
    }

    private function interestTask(int|float $before, int|float $after, bool $ratio = true): MarketProcessingTask
    {
        $a = E::signal();
        $b = E::signal(true);
        $a['value'] = $before;
        $b['value'] = $after;
        $a['comparison_basis']['ratio_scale'] = $ratio;
        $b['comparison_basis']['ratio_scale'] = $ratio;

        return new MarketProcessingTask('compare_interest', [E::material($a, 'market.interest_signal:2'), E::material($b, 'market.interest_signal:2', 2)]);
    }

    public function test_changes_to_metric_geography_sampling_normalization_and_period_reject_comparison(): void
    {
        $method = new QualifiedInterestChange;
        $changes = ['metric.definition_version' => '2', 'metric.unit' => 'people', 'geography.ref' => 'JO',
            'comparison_basis.sampling' => 'different-sample', 'comparison_basis.normalization' => 'different-base',
            'comparison_basis.population' => null, 'comparison_basis.period_end' => '2020-01-04T00:00:00Z'];
        foreach ($changes as $path => $value) {
            $b = E::signal(true);
            [$field, $key] = explode('.', $path);
            $b[$field][$key] = $value;
            $finding = $method->execute(new MarketProcessingTask($method->question(), [E::material(E::signal(), 'market.interest_signal:2'), E::material($b, 'market.interest_signal:2', 2)]));
            $this->assertSame('not_comparable', $finding['status'], $path);
            $this->assertEquals((object) [], $finding['values']);
        }
        $a = E::signal();
        $b = E::signal(true);
        $a['comparison_basis']['ratio_scale'] = $b['comparison_basis']['ratio_scale'] = false;
        $task = new MarketProcessingTask($method->question(), [E::material($a, 'market.interest_signal:2'), E::material($b, 'market.interest_signal:2', 2)]);
        $finding = $method->execute($task);
        $this->assertSame(10.0, $finding['values']->delta);
        $this->assertNull($finding['values']->percent_change);
        Findings::validate($task, $method, $finding);
    }

    public function test_interest_source_asserted_identity_never_becomes_global_from_equal_keys(): void
    {
        $method = new QualifiedInterestChange;
        $before = E::material(E::signal(), 'market.interest_signal:2');
        $after = E::material(E::signal(true), 'market.interest_signal:2', 2);
        $before['evidence']['source_ref'] = '01900000-0000-7000-8000-000000000001';
        $after['evidence']['source_ref'] = '01900000-0000-7000-8000-000000000002';
        $finding = $method->execute(new MarketProcessingTask($method->question(), [$before, $after]));
        $this->assertSame('not_comparable', $finding['status']);
        $this->assertSame(['SOURCE_COMPARABILITY_UNQUALIFIED'], $finding['reason_codes']);
        $this->assertEquals((object) [], $finding['values']);
        $after['evidence']['source_ref'] = $before['evidence']['source_ref'];
        $finding = $method->execute(new MarketProcessingTask($method->question(), [$before, $after]));
        $this->assertSame('comparable', $finding['status']);
        $this->assertSame(25.0, $finding['values']->percent_change);
        foreach ([null, '', [], 7] as $unknownSource) {
            $before['evidence']['source_ref'] = $after['evidence']['source_ref'] = $unknownSource;
            $finding = $method->execute(new MarketProcessingTask($method->question(), [$before, $after]));
            $this->assertSame('not_comparable', $finding['status']);
        }
    }

    public function test_availability_exception_does_not_disable_an_independent_local_alternative(): void
    {
        foreach ([new \RuntimeException('PRIVATE_TOOL_FAILURE'), new \TypeError('PRIVATE_ADAPTER_ERROR')] as $error) {
            $optional = new class($error) implements MarketMethod
            {
                public function __construct(private \Throwable $error) {}

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
                    throw $this->error;
                }

                public function execute(MarketProcessingTask $task): array
                {
                    throw new \LogicException('Unavailable executor ran');
                }
            };
            $selector = new MarketMethodSelector([$optional, new OfferEquivalence]);
            $this->assertInstanceOf(OfferEquivalence::class, $selector->select('compare_offers', 'offer_equivalence:1'));
            $this->assertNull((new MarketMethodSelector([$optional]))->select('compare_offers', 'offer_equivalence:1'));
        }
    }

    public function test_feasibility_is_limited_by_qualified_business_context_and_never_invents_cost_or_capacity(): void
    {
        $method = new BusinessFeasibility;
        foreach ($this->oracle()['business'] as $case) {
            $fields = E::offer();
            $fields['geography']['ref'] = $case['country'];
            $task = new MarketProcessingTask($method->question(), [E::material($fields, 'market.offer:2')], ['context_version' => 1, 'declared_markets' => $case['countries']]);
            $finding = $method->execute($task);
            $this->assertSame($case['status'], $finding['status']);
            $this->assertContains('CAPACITY_NOT_ASSESSED', $finding['limitations']);
            $this->assertContains('COST_NOT_ASSESSED', $finding['limitations']);
            $this->assertEquals((object) [], $finding['values']);
            Findings::validate($task, $method, $finding);
        }
    }

    public function test_selector_and_optional_tool_failure_do_not_disable_unrelated_local_methods(): void
    {
        $optional = new class implements MarketMethod
        {
            public function question(): string
            {
                return 'optional_identity_enrichment';
            }

            public function reference(): string
            {
                return 'test_optional:1';
            }

            public function available(): bool
            {
                return false;
            }

            public function execute(MarketProcessingTask $task): array
            {
                throw new \LogicException('Unavailable tool must not execute');
            }
        };
        $selector = new MarketMethodSelector([$optional, new OfferEquivalence, new QualifiedInterestChange, new BusinessFeasibility]);
        $this->assertNull($selector->select('optional_identity_enrichment', 'test_optional:1'));
        $this->assertNull($selector->select('arbitrary\\ClassName', 'offer_equivalence:1'));
        $unavailableEquivalent = new class implements MarketMethod
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
                return false;
            }

            public function execute(MarketProcessingTask $task): array
            {
                throw new \LogicException('Unavailable optional executor must not execute');
            }
        };
        $fallback = new MarketMethodSelector([$unavailableEquivalent, new OfferEquivalence]);
        $local = $fallback->select('compare_offers', 'offer_equivalence:1');
        $this->assertInstanceOf(OfferEquivalence::class, $local);
        $task = new MarketProcessingTask('compare_offers', [E::material(E::offer(), 'market.offer:2'), E::material(E::offer(), 'market.offer:2', 2)]);
        $this->assertSame('equivalent', $local->execute($task)['status']);
        $this->assertNull((new MarketMethodSelector([$unavailableEquivalent]))->select('compare_offers', 'offer_equivalence:1'));

        foreach (['compare_offers' => 'offer_equivalence:1', 'compare_interest' => 'qualified_interest_change:1', 'assess_business_feasibility' => 'business_feasibility:1'] as $question => $ref) {
            $this->assertSame($ref, $selector->select($question, $ref)->reference());
        }
    }
}
