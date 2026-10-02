<?php

namespace App\Providers\Ads\TikTok;

final class TikTokRequestFailed extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $retryAfterSeconds = null)
    {
        if (! preg_match('/^tiktok_[a-z_]{1,65}$/D', $reason) || ($retryAfterSeconds !== null && ($retryAfterSeconds < 0 || $retryAfterSeconds > 31536000))) {
            throw new \InvalidArgumentException('Invalid TikTok failure.');
        }
        parent::__construct($reason);
    }
}
