<?php

namespace App\Sources;

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
        foreach (self::AXES as $axis) {
            $fact = $evidence[$axis] ?? null;
            if (! is_array($fact) || empty($fact['provenance']) || empty($fact['verified_at']) || empty($fact['valid_until'])) {
                $blocked[] = $this->result('UNKNOWN', $axis.'_unverified');

                continue;
            }
            try {
                $observed = new DateTimeImmutable($fact['verified_at']);
                $expires = new DateTimeImmutable($fact['valid_until']);
            } catch (\Exception) {
                $blocked[] = $this->result('UNKNOWN', $axis.'_invalid_evidence');

                continue;
            }
            if ($observed > $at || $expires <= $observed) {
                $blocked[] = $this->result('UNKNOWN', $axis.'_invalid_evidence');

                continue;
            }
            if ($expires <= $at) {
                $blocked[] = $this->result('STALE', $axis.'_expired', $observed->format(DATE_ATOM));

                continue;
            }
            $state = $fact['state'] ?? 'UNKNOWN';
            if ($state !== 'VERIFIED') {
                $blocked[] = $this->result(in_array($state, ['REVOKED', 'PARTIAL', 'FAILED', 'UNSUPPORTED', 'VERIFICATION_NEEDED'], true)
                    ? $state : 'UNKNOWN', $axis.'_'.strtolower($state), $observed->format(DATE_ATOM));

                continue;
            }
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
