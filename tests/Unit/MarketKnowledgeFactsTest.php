<?php

namespace Tests\Unit;

use App\MarketKnowledge\Processing\MarketProcessingTask;
use App\MarketKnowledge\QualifiedMarketFact;
use DomainException;
use PHPUnit\Framework\TestCase;
use Tests\Support\MarketProcessingExamples;

final class MarketKnowledgeFactsTest extends TestCase
{
    public function test_observed_zero_is_preserved_but_unknown_price_never_becomes_zero(): void
    {
        $method = new QualifiedMarketFact('price');
        foreach ([0, 543.25, null] as $price) {
            $fields = MarketProcessingExamples::offer();
            $fields['price'] = $price;
            $task = new MarketProcessingTask('record_price', [MarketProcessingExamples::material($fields, 'market.offer:2')]);
            $finding = $method->execute($task);
            $method->validate($task, $finding);
            $this->assertSame($price === null ? 'requires_information' : 'observed', $finding['status']);
            $this->assertSame($price === null ? [] : ['price' => $price, 'currency' => 'SAR'], (array) $finding['values']);
        }
    }

    public function test_geography_is_a_source_observation_and_cannot_be_upgraded_to_audience_reach(): void
    {
        $method = new QualifiedMarketFact('geography');
        $task = new MarketProcessingTask('record_geography', [MarketProcessingExamples::material(MarketProcessingExamples::offer(), 'market.offer:2')]);
        $finding = $method->execute($task);
        $method->validate($task, $finding);
        $this->assertSame(['geography' => ['ref' => 'SA', 'level' => 'country']], (array) $finding['values']);
        $this->assertSame(['GEOGRAPHY_IS_NOT_AUDIENCE_REACH'], $finding['limitations']);
        $finding['values']->geography['ref'] = 'UNSUPPORTED';
        $this->expectException(DomainException::class);
        $method->validate($task, $finding);
    }
}
