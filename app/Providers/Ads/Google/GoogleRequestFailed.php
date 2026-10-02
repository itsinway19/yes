<?php

namespace App\Providers\Ads\Google;

/** Safe technical reason only; never retain a native message, request body or transport exception. */
final class GoogleRequestFailed extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $retryAfterSeconds = null)
    {
        if (! preg_match('/^google_[a-z_]{1,65}$/D', $reason)
            || ($retryAfterSeconds !== null && ($retryAfterSeconds < 0 || $retryAfterSeconds > 31536000))) {
            throw new \InvalidArgumentException('Invalid Google technical failure.');
        }
        parent::__construct($reason);
    }
}
