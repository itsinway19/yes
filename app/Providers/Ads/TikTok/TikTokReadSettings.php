<?php

namespace App\Providers\Ads\TikTok;

final readonly class TikTokReadSettings
{
    public function __construct(public string $version)
    {
        if ($version !== 'v1.3') {
            throw new \LogicException('Explicit supported TikTok reporting version required.');
        }
    }

    public static function configured(): self
    {
        return new self((string) config('services.tiktok_ads.version'));
    }

    public function binding(): string
    {
        return hash('sha256', $this->version);
    }
}
