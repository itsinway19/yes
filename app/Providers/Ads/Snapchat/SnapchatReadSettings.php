<?php

namespace App\Providers\Ads\Snapchat;

/** The fixed version matches SnapchatClient's allowlisted native host path, not OAuth readiness. */
final readonly class SnapchatReadSettings
{
    public function __construct(public string $version)
    {
        if ($version !== 'v1') {
            throw new \LogicException('Unsupported Snapchat reporting transport.');
        }
    }

    public static function configured(): self
    {
        return new self('v1');
    }

    public function binding(): string
    {
        return hash('sha256', $this->version);
    }
}
