<?php

namespace App\Providers\Ads;

use InvalidArgumentException;
use LogicException;

/** Server-only material; never an Inertia/model/queue payload. */
final readonly class ProviderCredentials
{
    public function __construct(#[\SensitiveParameter] private array $values)
    {
        if ($values === [] || count($values) > 12) {
            throw new InvalidArgumentException('Invalid provider credential shape.');
        }
        foreach ($values as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $key)
                || ! is_string($value) || $value === '' || strlen($value) > 8192 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new InvalidArgumentException('Invalid provider credential field.');
            }
        }
    }

    public function value(string $field): string
    {
        return $this->values[$field] ?? throw new LogicException('Required provider credential is unavailable.');
    }

    /** Internal custody/staging writers only; sorted fields give stable retry fingerprints. */
    public function custodyPayload(): array
    {
        $values = $this->values;
        ksort($values);

        return $values;
    }

    public function __debugInfo(): array
    {
        return ['credentials' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('Provider credentials cannot be serialized.');
    }
}
