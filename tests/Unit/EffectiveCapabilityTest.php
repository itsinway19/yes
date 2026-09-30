<?php

namespace Tests\Unit;

use App\Sources\EffectiveCapability;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class EffectiveCapabilityTest extends TestCase
{
    public function test_unknown_is_not_unsupported_and_no_missing_axis_can_yield_ready(): void
    {
        $deriver = new EffectiveCapability;
        $at = new DateTimeImmutable('2026-09-30T12:00:00Z');
        $facts = array_fill_keys(EffectiveCapability::AXES, ['state' => 'VERIFIED', 'provenance' => 'synthetic:independent',
            'verified_at' => '2026-09-30T11:00:00Z', 'valid_until' => '2026-09-30T13:00:00Z']);
        $this->assertSame('READY', $deriver->derive('UNVERIFIED', true, $facts, $at)['state']);
        foreach (EffectiveCapability::AXES as $axis) {
            $missing = $facts;
            unset($missing[$axis]);
            $this->assertSame('UNKNOWN', $deriver->derive('UNVERIFIED', true, $missing, $at)['state']);
        }
        foreach (['UNKNOWN', 'UNSUPPORTED', 'REVOKED', 'PARTIAL', 'FAILED', 'VERIFICATION_NEEDED'] as $state) {
            $changed = $facts;
            $changed['provider_support']['state'] = $state;
            $this->assertSame($state, $deriver->derive('UNVERIFIED', true, $changed, $at)['state']);
        }
        $facts['freshness']['valid_until'] = '2026-09-30T11:30:00Z';
        $this->assertSame('STALE', $deriver->derive('UNVERIFIED', true, $facts, $at)['state']);
        $this->assertSame('REVOKED', $deriver->derive('REVOKED', true, [], $at)['state']);
        $this->assertSame('VERIFICATION_NEEDED', $deriver->derive('UNVERIFIED', false, [], $at)['state']);
        $this->assertSame('UNKNOWN', $deriver->derive('UNVERIFIED', true, [], $at)['state']);
        $this->assertSame('REVOKED', $deriver->derive('UNVERIFIED', true, ['provider_permission' => [
            'state' => 'REVOKED', 'provenance' => 'synthetic:revocation', 'verified_at' => '2026-09-30T11:00:00Z',
            'valid_until' => '2026-09-30T13:00:00Z',
        ]], $at)['state']);
    }
}
