<?php

namespace App\Providers\Ads\Google;

/** Native scheduling metadata; the receipt alone describes durable observation acceptance. */
final readonly class GoogleReadAttempt
{
    public function __construct(public array $receipt, public ?string $failure, public ?int $retryAfterSeconds) {}

    public function envelope(): array
    {
        return ['receipt' => $this->receipt, 'failure' => $this->failure, 'retry_after_seconds' => $this->retryAfterSeconds];
    }
}
