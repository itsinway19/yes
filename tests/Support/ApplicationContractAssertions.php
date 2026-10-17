<?php

namespace Tests\Support;

use Symfony\Component\Process\Process;

trait ApplicationContractAssertions
{
    private function assertApplicationContract(string $schema, array $payload): void
    {
        $process = new Process([getenv('BUILDERAD_NODE') ?: (PHP_OS_FAMILY === 'Windows' ? 'C:\\tools\\node24\\node.exe' : 'node'), base_path('tests/Support/validate-application-contract.mjs'), $schema], base_path());
        $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }
}
