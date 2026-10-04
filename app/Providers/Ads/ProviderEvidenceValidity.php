<?php

namespace App\Providers\Ads;

use DateTimeImmutable;
use Throwable;

/** Canonical common per-axis interpretation; provider-native overrides remain native-owned. */
final class ProviderEvidenceValidity
{
    public function classify(mixed $fact, DateTimeImmutable $at): ProviderEvidenceClassification
    {
        if (! is_array($fact) || empty($fact['provenance']) || empty($fact['verified_at']) || empty($fact['valid_until'])) {
            return new ProviderEvidenceClassification('UNKNOWN', 'unverified');
        }
        if (! is_string($fact['provenance']) || ! is_string($fact['verified_at']) || ! is_string($fact['valid_until']) ||
            (isset($fact['state']) && ! is_string($fact['state']))) {
            return new ProviderEvidenceClassification('UNKNOWN', 'invalid_evidence');
        }
        try {
            $observed = new DateTimeImmutable($fact['verified_at']);
            $expires = new DateTimeImmutable($fact['valid_until']);
        } catch (Throwable) {
            return new ProviderEvidenceClassification('UNKNOWN', 'invalid_evidence');
        }
        if ($observed > $at || $expires <= $observed) {
            return new ProviderEvidenceClassification('UNKNOWN', 'invalid_evidence');
        }
        if ($expires <= $at) {
            return new ProviderEvidenceClassification('STALE', 'expired', $observed);
        }
        $state = $fact['state'] ?? 'UNKNOWN';

        return new ProviderEvidenceClassification(in_array($state, ['VERIFIED', 'REVOKED', 'PARTIAL', 'FAILED', 'UNSUPPORTED', 'VERIFICATION_NEEDED'], true)
            ? $state : 'UNKNOWN', strtolower($state), $observed);
    }

    public function axes(array $evidence, DateTimeImmutable $at, array $axisNames): array
    {
        $axes = [];
        foreach ($axisNames as $axis) {
            $axes[$axis] = $this->classify($evidence[$axis] ?? null, $at)->state;
        }

        return $axes;
    }
}
