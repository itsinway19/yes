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
        $this->assertNull($selector->select('optional_identity_enrichment'));
        $this->assertNull($selector->select('arbitrary\\ClassName'));
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
        $local = $fallback->select('compare_offers');
        $this->assertInstanceOf(OfferEquivalence::class, $local);
        $task = new MarketProcessingTask('compare_offers', [E::material(E::offer(), 'market.offer:2'), E::material(E::offer(), 'market.offer:2', 2)]);
        $this->assertSame('equivalent', $local->execute($task)['status']);
        $this->assertNull((new MarketMethodSelector([$unavailableEquivalent]))->select('compare_offers'));

        foreach (['compare_offers' => 'offer_equivalence:1', 'compare_interest' => 'qualified_interest_change:1', 'assess_business_feasibility' => 'business_feasibility:1'] as $question => $ref) {
            $this->assertSame($ref, $selector->select($question)->reference());
        }
    }
}
