<?php

namespace App\MarketKnowledge\Processing;

interface MarketMethod
{
    public function question(): string;

    public function reference(): string;

    public function available(): bool;

    public function execute(MarketProcessingTask $task): array;
}
