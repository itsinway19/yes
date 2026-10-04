<?php

namespace Tests\Unit;

use App\Providers\Ads\ProviderEvidenceValidity;
use App\Providers\Ads\ProviderReportPrerequisites;
use App\Sources\EffectiveCapability;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ProviderEvidenceValidityTest extends TestCase
{
    public function test_classification_never_substitutes_for_authority_and_fetch_is_distinct_from_readiness(): void
    {
        $at = new DateTimeImmutable('2026-10-04T12:00:00Z');
        $fact = ['state' => 'VERIFIED', 'provenance' => 'synthetic:proof', 'verified_at' => '2026-10-04T11:00:00Z', 'valid_until' => '2026-10-04T13:00:00Z'];
        $facts = array_fill_keys(EffectiveCapability::AXES, $fact);
        $classifier = new ProviderEvidenceValidity;
        $this->assertSame('VERIFIED', $classifier->classify($fact, $at)->state);
        $this->assertSame('VERIFICATION_NEEDED', (new EffectiveCapability)->derive('UNVERIFIED', false, $facts, $at)['state']);
        unset($facts['completeness'], $facts['freshness'], $facts['product_exposure']);
        $this->assertSame('UNKNOWN', (new EffectiveCapability)->derive('UNVERIFIED', true, $facts, $at)['state']);
        $axes = $classifier->axes($facts, $at, EffectiveCapability::AXES);
        $projection = new ProviderReportPrerequisites;
        $this->assertSame(['can_report' => true, 'why_not' => null], $projection->derive('CONNECTED', $axes));
        $this->assertFalse($projection->derive('VERIFICATION_NEEDED', $axes)['can_report']);
        foreach (ProviderReportPrerequisites::AXES as $axis) {
            $blocked = $axes;
            $blocked[$axis] = 'FAILED';
            $this->assertSame(['can_report' => false, 'why_not' => $axis.'_failed'], $projection->derive('CONNECTED', $blocked));
        }
    }
}
