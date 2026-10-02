<?php

namespace Tests\Unit;

use App\Http\Controllers\XConnectionController;
use App\Http\Controllers\XReportController;
use PHPUnit\Framework\TestCase;

final class XWebBoundaryTest extends TestCase
{
    public function test_x_has_connection_and_report_boundaries_without_an_invented_refresh_endpoint(): void
    {
        $this->assertTrue(class_exists(XConnectionController::class));
        foreach (['index', 'connect', 'callback', 'discovery', 'select', 'disconnect'] as $method) {
            $this->assertTrue(method_exists(XConnectionController::class, $method));
        }
        $this->assertFalse(method_exists(XConnectionController::class, 'renew'));
        $this->assertTrue(method_exists(XReportController::class, 'store'));
    }
}
