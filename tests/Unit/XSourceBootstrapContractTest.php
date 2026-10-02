<?php

namespace Tests\Unit;

use App\Providers\Ads\X\XSourceBootstrap;
use PHPUnit\Framework\TestCase;

final class XSourceBootstrapContractTest extends TestCase
{
    public function test_x_explicit_source_bootstrap_is_available(): void
    {
        $this->assertTrue(class_exists(XSourceBootstrap::class), 'Explicit X source bootstrap must exist.');
    }
}
