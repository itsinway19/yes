<?php

namespace App\Providers\Ads;

use RuntimeException;

final class ReadBudgetExhausted extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Provider read stopped: '.$reason);
    }
}
