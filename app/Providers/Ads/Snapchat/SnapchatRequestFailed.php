<?php

namespace App\Providers\Ads\Snapchat;

final class SnapchatRequestFailed extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $retryAfterSeconds = null)
    {
        if (! preg_match('/^snapchat_[a-z_]{1,65}$/D', $reason)
            || ($retryAfterSeconds !== null && ($retryAfterSeconds < 0 || $retryAfterSeconds > 31536000))) {
            throw new \InvalidArgumentException('Invalid Snapchat failure.');
        }
        parent::__construct($reason);
    }
}
