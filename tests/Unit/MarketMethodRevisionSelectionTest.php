<?php

namespace Tests\Unit;

use App\MarketKnowledge\Processing\MarketMethodSelector;
use App\MarketKnowledge\Processing\OfferEquivalence;
use PHPUnit\Framework\TestCase;
use Tests\Support\OfferMethodRevisionFixture;

final class MarketMethodRevisionSelectionTest extends TestCase
{
    public function test_captured_revision_selects_its_available_method_without_probing_another_revision(): void
    {
        $future = new OfferMethodRevisionFixture('2');
        $current = new OfferEquivalence;
        $selector = new MarketMethodSelector([$future, $current]);
        $this->assertSame($current, $selector->select('compare_offers', 'offer_equivalence:1'));
        $this->assertSame(0, $future->probes);
        $this->assertSame(0, $future->executions);
    }

    public function test_absent_captured_revision_never_falls_through_to_another_revision_or_question(): void
    {
        $future = new OfferMethodRevisionFixture('2');
        $selector = new MarketMethodSelector([$future]);
        $this->assertNull($selector->select('compare_offers', 'offer_equivalence:1'));
        $this->assertNull($selector->select('arbitrary_question', 'offer_equivalence:2'));
        $this->assertSame(0, $future->probes);
        $this->assertSame(0, $future->executions);
    }

    public function test_matching_revision_retains_ordered_availability_fallback(): void
    {
        $unavailable = new OfferMethodRevisionFixture('1', false);
        $current = new OfferEquivalence;
        $selector = new MarketMethodSelector([$unavailable, $current]);
        $this->assertSame($current, $selector->select('compare_offers', 'offer_equivalence:1'));
        $this->assertSame(1, $unavailable->probes);
        $this->assertSame(0, $unavailable->executions);
    }
}
