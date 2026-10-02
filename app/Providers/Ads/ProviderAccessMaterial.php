<?php

namespace App\Providers\Ads;

use DateTimeImmutable;
use InvalidArgumentException;

/** Adapter output, not a grant. No default lifetime or inherited proof on replacement credentials. */
final readonly class ProviderAccessMaterial
{
    public function __construct(
        public ProviderScope $scope,
        #[\SensitiveParameter] public ProviderCredentials $credentials,
        public ?DateTimeImmutable $expiresAt,
        public string $nativeState,
        public array $verification,
    ) {
        if (! preg_match('/^[A-Z][A-Z0-9_]{0,79}$/D', $nativeState)) {
            throw new InvalidArgumentException('Native access state is required.');
        }
    }

    public function __debugInfo(): array
    {
        return ['scope' => $this->scope->jsonSerialize(), 'native_state' => $this->nativeState, 'credentials' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Access material cannot enter a queue or browser projection.');
    }
}
