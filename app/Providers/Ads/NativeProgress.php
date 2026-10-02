<?php

namespace App\Providers\Ads;

use InvalidArgumentException;

/** Opaque adapter-owned progress. No common cursor, watermark or URL interpretation. */
final readonly class NativeProgress
{
    public function __construct(public string $kind, public string $basis, #[\SensitiveParameter] private string $payload)
    {
        if (! preg_match('/^[a-z][a-z0-9_.]{0,79}$/D', $kind) || $basis === '' || strlen($basis) > 512
            || $payload === '' || strlen($payload) > 32768) {
            throw new InvalidArgumentException('Bounded provider-native progress and provenance are required.');
        }
    }

    public function envelope(): array
    {
        return ['kind' => $this->kind, 'basis' => $this->basis, 'payload' => $this->payload];
    }

    public function __debugInfo(): array
    {
        return ['kind' => $this->kind, 'payload' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Native provider progress requires encrypted custody.');
    }
}
