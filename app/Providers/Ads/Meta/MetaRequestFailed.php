<?php

namespace App\Providers\Ads\Meta;

/** Safe native failure classification; no provider message, token, request or previous exception. */
final class MetaRequestFailed extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $retryAfterSeconds = null)
    {
        if (! preg_match('/^meta_[a-z0-9_]{1,74}$/D', $reason)
            || ($retryAfterSeconds !== null && ($retryAfterSeconds < 0 || $retryAfterSeconds > 31536000))) {
            throw new \InvalidArgumentException('Safe bounded Meta failure metadata is required.');
        }
        parent::__construct($reason);
    }
}
