<?php

namespace Tests\Unit;

use App\Providers\Ads\Google\GoogleReadSettings;
use LogicException;
use PHPUnit\Framework\TestCase;

class GoogleReadSettingsTest extends TestCase
{
    public function test_reporting_accepts_an_empty_developer_token(): void
    {
        $settings = new GoogleReadSettings('v25', '');
        $this->assertSame('v25', $settings->version);
        $this->assertSame('', $settings->secret());
    }

    public function test_unsupported_version_is_rejected_without_requiring_a_token(): void
    {
        $this->expectException(LogicException::class);
        new GoogleReadSettings('v18', '');
    }

    public function test_optional_header_still_rejects_control_characters(): void
    {
        $this->expectException(LogicException::class);
        new GoogleReadSettings('v25', "\r\n");
    }
}
