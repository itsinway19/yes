<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\RowLockReadiness;

class RowLockReadinessTest extends TestCase
{
    public function test_buffered_readiness_is_consumed_while_the_holder_is_still_active(): void
    {
        $input = new InputStream;
        $holder = new Process([PHP_BINARY, '-r', "echo 'LOCK_'; fflush(STDOUT); echo 'READY'; fflush(STDOUT); fgets(STDIN);"]);
        $holder->setInput($input);
        $holder->setTimeout(5);
        $holder->start();
        try {
            do {
                $buffer = $holder->getOutput();
                $holder->checkTimeout();
                if (str_contains($buffer, 'LOCK_READY')) {
                    break;
                }
                usleep(1000);
            } while ($holder->isRunning());
            $this->assertStringContainsString('LOCK_READY', $buffer);
            RowLockReadiness::await($holder);
            $this->assertTrue($holder->isRunning());
            $input->write("release\n");
            $input->close();
            $holder->wait();
            $this->assertTrue($holder->isSuccessful(), $holder->getErrorOutput());
        } finally {
            if ($holder->isRunning()) {
                $holder->stop();
            }
        }
    }

    public function test_an_exited_holder_never_authorizes_the_consuming_phase(): void
    {
        $holder = new Process([PHP_BINARY, '-r', "echo 'LOCK_READY';"]);
        $holder->run();
        $this->expectException(RuntimeException::class);
        RowLockReadiness::await($holder);
    }
}
