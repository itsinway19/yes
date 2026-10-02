<?php

namespace App\Providers\Ads;

use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

/** Trusted operator evidence admission, not a policy approval or a grant writer. */
final class ProviderReportingEvidence
{
    private const AXES = ['provider_support', 'privacy_purpose'];

    public function configuration(ProviderScope $scope): string
    {
        $settings = config('services.'.$scope->provider->value.'_ads', []);
        ksort($settings);

        return hash_hmac('sha256', json_encode([$settings, match ($scope->provider) {
            Provider::X => '12', Provider::Snapchat => 'v1', default => $settings['version'] ?? null,
        }], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function withdraw(ProviderScope $scope, string $operator): void
    {
        if ($operator === '' || strlen($operator) > 128 || preg_match('/[\x00-\x1f\x7f]/', $operator)) {
            throw new InvalidArgumentException('Attributable operator required.');
        }
        $path = $this->path($scope);
        if (is_file($path)) {
            $record = json_decode(Crypt::decryptString(file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);
            $record['withdrawn_by'] = $operator;
            $record['withdrawn_at'] = (new DateTimeImmutable)->format(DATE_ATOM);
            if (file_put_contents($path, Crypt::encryptString(json_encode($record, JSON_THROW_ON_ERROR)), LOCK_EX) === false) {
                throw new \RuntimeException('Evidence withdrawal failed.');
            }
        }
    }

    /** Called only by a trusted installation operator after reviewing actual approval artifacts. */
    public function admit(ProviderScope $scope, array $record, string $operator): void
    {
        if ($operator === '' || strlen($operator) > 128 || preg_match('/[\x00-\x1f\x7f]/', $operator)
            || $scope->operation !== 'report.read' || $scope->purpose !== 'advertising_analysis'
            || ($record['scope'] ?? null) !== $scope->jsonSerialize()
            || ($record['configuration'] ?? null) !== $this->configuration($scope)
            || array_diff(array_keys($record), ['scope', 'configuration', 'provider_support', 'privacy_purpose'])) {
            throw new InvalidArgumentException('Evidence must bind the exact scope and current installation configuration.');
        }
        foreach (self::AXES as $axis) {
            $fact = $record[$axis] ?? null;
            if (! is_array($fact) || array_diff(array_keys($fact), ['provenance', 'issuer', 'authority_basis', 'approval_reference', 'artifact_sha256', 'verified_at', 'valid_until'])) {
                throw new InvalidArgumentException('Separate attributable evidence is required for each reporting prerequisite.');
            }
            foreach (['provenance', 'issuer', 'authority_basis', 'approval_reference', 'artifact_sha256', 'verified_at', 'valid_until'] as $key) {
                if (! is_string($fact[$key] ?? null) || $fact[$key] === '' || strlen($fact[$key]) > 512 || preg_match('/[\x00-\x1f\x7f]/', $fact[$key])) {
                    throw new InvalidArgumentException('Evidence provenance and explicit effect period are required.');
                }
            }
            if (! preg_match('/^[a-f0-9]{64}$/D', $fact['artifact_sha256']) || ! $this->current($fact)) {
                throw new InvalidArgumentException('Evidence artifact identity or validity is unestablished.');
            }
        }
        $record['admitted_by'] = $operator;
        $record['admitted_at'] = (new DateTimeImmutable)->format(DATE_ATOM);
        $path = $this->path($scope);
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0700, true) && ! is_dir(dirname($path))) {
            throw new \RuntimeException('Private evidence custody unavailable.');
        }
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            if (file_put_contents($temporary, Crypt::encryptString(json_encode($record, JSON_THROW_ON_ERROR)), LOCK_EX) === false
                || ! rename($temporary, $path)) {
                throw new \RuntimeException('Evidence admission failed.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function apply(ProviderScope $scope, array $native): array
    {
        // Existing trusted internal verifiers may supply explicit evidence; no browser accepts it.
        if (($native['provider_support']['state'] ?? 'UNKNOWN') !== 'UNKNOWN'
            && ($native['privacy_purpose']['state'] ?? 'UNKNOWN') !== 'UNKNOWN') {
            return $native;
        }
        $proof = $this->proof($scope);
        foreach (self::AXES as $axis) {
            if (($native[$axis]['state'] ?? 'UNKNOWN') === 'UNKNOWN') {
                $native[$axis] = $proof[$axis] ?? ['state' => 'UNKNOWN'];
            }
        }
        $native['reporting_admission'] = ['configuration' => $this->configuration($scope), 'digest' => $proof['digest'] ?? null];

        return $native;
    }

    public function applicable(ProviderScope $scope, array $stored): array
    {
        if (! array_key_exists('reporting_admission', $stored)) {
            return $stored;
        }
        $current = $this->proof($scope);
        if (($stored['reporting_admission']['configuration'] ?? null) !== $this->configuration($scope)
            || ($stored['reporting_admission']['digest'] ?? null) === null
            || ($stored['reporting_admission']['digest'] ?? null) !== ($current['digest'] ?? null)) {
            foreach (self::AXES as $axis) {
                $stored[$axis] = ['state' => 'UNKNOWN'];
            }
        }

        return $stored;
    }

    public function requireApplicable(ProviderScope $scope, array $stored): void
    {
        if ($this->applicable($scope, $stored) !== $stored) {
            throw new AuthorizationException('Current reporting evidence must be readmitted and connection reverified.');
        }
    }

    private function proof(ProviderScope $scope): array
    {
        try {
            $path = $this->path($scope);
            if (! is_file($path) || filesize($path) > 16384) {
                return [];
            }
            $record = json_decode(Crypt::decryptString(file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);
            if (isset($record['withdrawn_at']) || ($record['scope'] ?? null) !== $scope->jsonSerialize() || ($record['configuration'] ?? null) !== $this->configuration($scope)) {
                return [];
            }
            $proof = [];
            foreach (self::AXES as $axis) {
                if (! $this->current($record[$axis] ?? [])) {
                    return [];
                }
                $proof[$axis] = ['state' => 'VERIFIED', ...$record[$axis],
                    'admitted_by' => $record['admitted_by'], 'admitted_at' => $record['admitted_at']];
            }
            $proof['digest'] = hash('sha256', json_encode($record, JSON_THROW_ON_ERROR));

            return $proof;
        } catch (\Throwable) {
            return [];
        }
    }

    private function current(array $fact): bool
    {
        try {
            foreach (['verified_at', 'valid_until'] as $key) {
                if (! is_string($fact[$key] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $fact[$key])) {
                    return false;
                }
            }
            $at = new DateTimeImmutable;
            $verified = new DateTimeImmutable($fact['verified_at'] ?? '');
            $until = new DateTimeImmutable($fact['valid_until'] ?? '');

            return $verified->format(DATE_ATOM) === preg_replace('/Z$/D', '+00:00', $fact['verified_at'])
                && $until->format(DATE_ATOM) === preg_replace('/Z$/D', '+00:00', $fact['valid_until'])
                && $verified <= $at && $until > $at && $until > $verified;
        } catch (\Throwable) {
            return false;
        }
    }

    private function path(ProviderScope $scope): string
    {
        return storage_path('app/private/bb2-reporting-evidence/'.hash('sha256', $scope->key('reporting-evidence')).'.enc');
    }
}
