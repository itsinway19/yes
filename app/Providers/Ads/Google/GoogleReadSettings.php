<?php

namespace App\Providers\Ads\Google;

use LogicException;

final readonly class GoogleReadSettings
{
    public function __construct(public string $version, #[\SensitiveParameter] private string $developerToken)
    {
        if (! preg_match('/^v(?:[2-9][0-9]|1[9])$/D', $version)
            || preg_match('/[\x00-\x1f\x7f]/', $developerToken)) {
            throw new LogicException('Explicit Google reporting settings required.');
        }
    }

    public static function configured(): self
    {
        return new self((string) config('services.google_ads.version'), (string) config('services.google_ads.developer_token'));
    }

    public function secret(): string
    {
        return $this->developerToken;
    }

    public function binding(): string
    {
        return hash_hmac('sha256', $this->version.':'.$this->developerToken, (string) config('app.key'));
    }

    public function __debugInfo(): array
    {
        return ['version' => $this->version, 'developer_token' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('Google reporting settings cannot enter queued commands.');
    }
}
