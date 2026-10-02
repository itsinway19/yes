<?php

namespace Tests\Unit;

use App\Providers\Ads\X\XAuthorizationCoordinator;
use PHPUnit\Framework\TestCase;

final class XDiscoveryProjectionContractTest extends TestCase
{
    public function test_discovery_projection_accepts_only_an_opaque_continuation_handle(): void
    {
        $this->assertTrue(method_exists(XAuthorizationCoordinator::class, 'discoverPage'), 'Browser-safe X discovery projection must exist.');
        $method = new \ReflectionMethod(XAuthorizationCoordinator::class, 'discoverPage');
        $parameter = $method->getParameters()[4];
        $this->assertSame('continuation', $parameter->getName());
        $this->assertSame('?string', (string) $parameter->getType());
        $this->assertSame('array', (string) $method->getReturnType());
    }
}
