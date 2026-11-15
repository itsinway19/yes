<?php

namespace Tests\Unit;

use App\MarketKnowledge\Processing\BusinessFeasibility;
use App\MarketKnowledge\Processing\Findings;
use App\MarketKnowledge\Processing\MarketProcessingTask;
use App\MarketKnowledge\Processing\OfferEquivalence;
use App\MarketKnowledge\Processing\QualifiedInterestChange;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\MarketProcessingExamples as E;
use Tests\Support\OfferMethodRevisionFixture;

final class MarketResultValidationTest extends TestCase
{
    public static function falseJudgments(): array
    {
        return [
            'different product reported equivalent' => ['offer'],
            'incompatible metrics reported comparable' => ['interest'],
            'outside country reported within declared market' => ['business'],
            'unsupported numeric disclosure' => ['extra_value'],
        ];
    }

    #[DataProvider('falseJudgments')]
    public function test_independent_validation_rejects_structurally_valid_false_judgments(string $case): void
    {
        $method = match ($case) {
            'offer' => new OfferEquivalence,
            'business' => new BusinessFeasibility,
            default => new QualifiedInterestChange,
        };
        $materials = $case === 'offer' || $case === 'business'
            ? [E::material(E::offer(), 'market.offer:2'), E::material(E::offer(), 'market.offer:2', 2)]
            : [E::material(E::signal(), 'market.interest_signal:2'), E::material(E::signal(true), 'market.interest_signal:2', 2)];
        if ($case === 'business') {
            $materials = [$materials[0]];
        }
        $context = $case === 'business' ? ['context_version' => 1, 'declared_markets' => ['SA']] : null;
        $task = new MarketProcessingTask($method->question(), $materials, $context);
        $finding = $method->execute($task);
        if ($case === 'offer') {
            $materials[1]['fields']['product']['model'] = 'different-model';
        } elseif ($case === 'interest') {
            $materials[1]['fields']['metric']['definition_version'] = '2';
        } elseif ($case === 'business') {
            $context['declared_markets'] = ['JO'];
        } else {
            $finding['values']->unlicensed_observation = 123;
        }
        $task = new MarketProcessingTask($method->question(), $materials, $context);
        $this->expectException(DomainException::class);
        Findings::validate($task, $method, $finding);
    }

    public static function changedComparisonBasis(): array
    {
        return [
            'source' => ['evidence', 'source_ref', 'another-source'],
            'schema' => ['evidence', 'schema', 'market.interest_signal:1'],
            'subject' => ['subject', 'key', 'another-subject'],
            'unknown identity' => ['subject', 'identity_state', 'unresolved'],
            'geography' => ['geography', 'ref', 'JO'],
            'unit' => ['metric', 'unit', 'people'],
            'definition' => ['metric', 'definition_version', '2'],
            'sample' => ['comparison_basis', 'sampling', 'different-sample'],
            'normalization' => ['comparison_basis', 'normalization', 'different-base'],
            'population' => ['comparison_basis', 'population', null],
            'aggregation' => ['comparison_basis', 'aggregation', 'total'],
            'scale' => ['comparison_basis', 'ratio_scale', false],
            'overlapping periods' => ['comparison_basis', 'period_start', '2020-01-01T00:00:00Z'],
            'unequal periods' => ['comparison_basis', 'period_end', '2020-01-04T00:00:00Z'],
        ];
    }

    #[DataProvider('changedComparisonBasis')]
    public function test_correct_numbers_cannot_validate_an_ineligible_comparison(string $field, string $key, mixed $value): void
    {
        $method = new QualifiedInterestChange;
        $materials = [E::material(E::signal(), 'market.interest_signal:2'), E::material(E::signal(true), 'market.interest_signal:2', 2)];
        $finding = $method->execute(new MarketProcessingTask($method->question(), $materials));
        if ($field === 'evidence') {
            $materials[1]['evidence'][$key] = $value;
        } else {
            $materials[1]['fields'][$field][$key] = $value;
        }
        $this->expectException(DomainException::class);
        Findings::validate(new MarketProcessingTask($method->question(), $materials), $method, $finding);
    }

    public function test_each_admitted_status_has_an_explicit_terminal_classification(): void
    {
        $offer = [E::material(E::offer(), 'market.offer:2'), E::material(E::offer(), 'market.offer:2', 2)];
        $different = $offer;
        $different[1]['fields']['product']['model'] = 'different-model';
        $interest = [E::material(E::signal(), 'market.interest_signal:2'), E::material(E::signal(true), 'market.interest_signal:2', 2)];
        $cases = [
            [new OfferEquivalence, $offer, null, 'equivalent', 'validated'],
            [new OfferEquivalence, $different, null, 'different', 'validated'],
            [new OfferEquivalence, [], null, 'unresolved', 'abstained'],
            [new QualifiedInterestChange, $interest, null, 'comparable', 'validated'],
            [new QualifiedInterestChange, [], null, 'not_comparable', 'abstained'],
            [new BusinessFeasibility, [$offer[0]], ['declared_markets' => ['SA']], 'within_declared_market', 'validated'],
            [new BusinessFeasibility, [$offer[0]], ['declared_markets' => ['JO']], 'outside_declared_market', 'validated'],
            [new BusinessFeasibility, [$offer[0]], null, 'requires_information', 'abstained'],
        ];
        foreach ($cases as [$method, $materials, $context, $status, $state]) {
            $task = new MarketProcessingTask($method->question(), $materials, $context);
            $finding = $method->execute($task);
            $this->assertSame($status, $finding['status']);
            $this->assertSame($state, Findings::validate($task, $method, $finding));
        }
    }

    public static function unsupportedHandoffs(): array
    {
        return ['status' => ['status'], 'version' => ['version'], 'question' => ['question'],
            'evidence' => ['evidence'], 'reason' => ['reason'], 'limitation' => ['limitation']];
    }

    #[DataProvider('unsupportedHandoffs')]
    public function test_unknown_or_incompatible_handoffs_never_default_to_validated(string $case): void
    {
        $method = new OfferEquivalence;
        $materials = [E::material(E::offer(), 'market.offer:2'), E::material(E::offer(), 'market.offer:2', 2)];
        $task = new MarketProcessingTask($method->question(), $materials);
        $finding = $method->execute($task);
        if ($case === 'version') {
            $method = new OfferMethodRevisionFixture('2');
            $finding['method_ref'] = $method->reference();
        } elseif ($case === 'question') {
            $task = new MarketProcessingTask('compare_interest', $materials);
        } elseif ($case === 'evidence') {
            $finding['evidence_refs'][0]['version'] = str_repeat('3', 64);
        } elseif ($case === 'reason') {
            $finding['reason_codes'] = ['UNSUPPORTED_BUT_WELL_FORMED'];
        } elseif ($case === 'limitation') {
            $finding['limitations'] = [];
        } else {
            $finding['status'] = 'new_abstention_state';
        }
        $this->expectException(DomainException::class);
        Findings::validate($task, $method, $finding);
    }
}
