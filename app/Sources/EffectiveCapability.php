<?php

namespace App\Sources;

use App\Providers\Ads\ProviderEvidenceValidity;
use DateTimeImmutable;

/** No assertion from a browser is accepted as provider evidence by BB1. */
final class EffectiveCapability
{
    public const AXES = ['provider_support', 'provider_permission', 'credential', 'privacy_purpose', 'freshness', 'completeness', 'product_exposure'];

    public function derive(string $registration, bool $authorized, array $evidence, DateTimeImmutable $at): array
    {
        if ($registration === 'REVOKED') {
            return $this->result('REVOKED', 'source_revoked');
        }
        if (! $authorized) {
            return $this->result('VERIFICATION_NEEDED', 'source_authority_absent');
        }
        $verifiedAt = null;
        $blocked = [];
        $classifier = new ProviderEvidenceValidity;
        foreach (self::AXES as $axis) {
            $classification = $classifier->classify($evidence[$axis] ?? null, $at);
            if ($classification->state !== 'VERIFIED') {
                $blocked[] = $this->result($classification->state, $axis.'_'.$classification->reason, $classification->observedAt?->format(DATE_ATOM));

                continue;
            }
            $observed = $classification->observedAt;
            // Oldest relevant verification bounds the aggregate claim.
            $verifiedAt = $verifiedAt === null || $observed < $verifiedAt ? $observed : $verifiedAt;
        }

        if ($blocked) {
            // A known denial must not be hidden by a different axis that is still unknown.
            $priority = array_flip(['REVOKED', 'UNSUPPORTED', 'FAILED', 'STALE', 'PARTIAL', 'VERIFICATION_NEEDED', 'UNKNOWN']);
            usort($blocked, fn ($a, $b) => $priority[$a['state']] <=> $priority[$b['state']]);

            return $blocked[0];
        }

        return $this->result('READY', 'all_relevant_axes_verified', $verifiedAt?->format(DATE_ATOM));
    }

    private function result(string $state, string $reason, ?string $verifiedAt = null): array
    {
        return ['state' => $state, 'reason' => $reason, 'last_verified_at' => $verifiedAt];
    }
}
