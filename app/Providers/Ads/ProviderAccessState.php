<?php

namespace App\Providers\Ads;

use App\Sources\EffectiveCapability;
use DateTimeImmutable;
use InvalidArgumentException;

/** Uses BB1's independent evidence axes; neither a token nor a native status is effect authority. */
final class ProviderAccessState
{
    public function __construct(private readonly EffectiveCapability $capabilities) {}

    public function derive(
        string $sourceState,
        bool $currentAuthority,
        string $nativeState,
        array $evidence,
        DateTimeImmutable $at,
        string $commonFoundationState = 'UNKNOWN',
    ): array {
        if (! in_array($sourceState, ['UNVERIFIED', 'REVOKED'], true)
            || ! preg_match('/^[A-Z][A-Z0-9_]{0,79}$/D', $nativeState)
            || ! in_array($commonFoundationState, ['VERIFIED', 'DEGRADED', 'FAILED', 'UNKNOWN'], true)) {
            throw new InvalidArgumentException('Invalid provider/common state.');
        }
        $effective = $this->capabilities->derive($sourceState, $currentAuthority, $evidence, $at);
        if ($sourceState !== 'REVOKED' && $currentAuthority && $commonFoundationState !== 'VERIFIED') {
            $effective = ['state' => 'BLOCKED_BY_COMMON_FOUNDATION', 'reason' => 'common_foundation_'.strtolower($commonFoundationState),
                'last_verified_at' => null];
        }

        return ['common_foundation_state' => $commonFoundationState, 'provider_native_state' => $nativeState,
            'effective_operation' => $effective];
    }
}
