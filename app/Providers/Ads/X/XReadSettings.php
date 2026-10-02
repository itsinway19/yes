<?php

namespace App\Providers\Ads\X;

use LogicException;

/** Server-only signing configuration; advertiser tokens remain in common encrypted custody. */
final readonly class XReadSettings
{
    private XOAuthSigner $signer;

    public function __construct(private string $consumerKey, #[\SensitiveParameter] private string $consumerSecret)
    {
        $this->signer = new XOAuthSigner($consumerKey, $consumerSecret);
    }

    public static function configured(): self
    {
        return new self((string) config('services.x_ads.consumer_key'), (string) config('services.x_ads.consumer_secret'));
    }

    public function signer(): XOAuthSigner
    {
        return $this->signer;
    }

    public function binding(): string
    {
        return hash_hmac('sha256', json_encode(['12', $this->consumerKey, $this->consumerSecret], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function __debugInfo(): array
    {
        return ['provider' => 'x', 'credentials' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('X signing settings cannot enter queued commands.');
    }
}
