<?php

namespace App\Providers\Ads\Meta;

use LogicException;

/** Read transport configuration is resolved on the worker, never copied into queued commands. */
final readonly class MetaReadSettings
{
    public function __construct(public string $version, #[\SensitiveParameter] private string $appSecret)
    {
        if (! preg_match('/^v[1-9][0-9]\.0$/D', $version) || $appSecret === '') {
            throw new LogicException('Explicit Meta read version and server app secret are unavailable.');
        }
    }

    public static function configured(): self
    {
        return new self((string) config('services.meta_ads.version'), (string) config('services.meta_ads.app_secret'));
    }

    public function secret(): string
    {
        return $this->appSecret;
    }

    public function binding(): string
    {
        return hash_hmac('sha256', json_encode([$this->version, $this->appSecret, config('services.meta_ads')], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function __debugInfo(): array
    {
        return ['version' => $this->version, 'app_secret' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('Meta server configuration cannot enter a queue or projection.');
    }
}
