<?php

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Row-holder readiness must survive Process status reads that drain stdout into its buffer. */
final class RowLockReadiness
{
    public static function await(Process $holder): void
    {
        do {
            $holder->checkTimeout();
            $ready = str_contains($holder->getOutput(), 'LOCK_READY');
            $running = $holder->isRunning();
            if ($ready && $running) {
                return;
            }
            if (! $running) {
                throw new RuntimeException('Row holder exited before active lock readiness: '.$holder->getErrorOutput());
            }
            usleep(1000);
        } while (true);
    }
}
