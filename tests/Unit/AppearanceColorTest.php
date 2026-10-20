<?php

namespace Tests\Unit;

use App\Appearance\GlobalAppearance;
use PHPUnit\Framework\TestCase;

class AppearanceColorTest extends TestCase
{
    public function test_arbitrary_bounded_colors_have_readable_button_foregrounds_and_white_surface_inks(): void
    {
        foreach (['#ffffff', '#000000', '#ffff00', '#00ffff', '#ff00ff', '#767676', '#155f53'] as $color) {
            $tokens = GlobalAppearance::tokens(['primary' => $color, 'secondary' => $color]);
            $this->assertGreaterThanOrEqual(4.5, $this->contrast($color, $tokens['--ba-primary-text']));
            $this->assertGreaterThanOrEqual(7, $this->contrast('#ffffff', $tokens['--ba-primary-ink']));
            $this->assertSame($tokens['--ba-primary-text'], $tokens['--ba-secondary-text']);
        }
    }

    public function test_tokens_cannot_contain_stylesheet_injection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GlobalAppearance::tokens(['primary' => '#ffffff;}body{display:none}', 'secondary' => '#000000']);
    }

    private function contrast(string $a, string $b): float
    {
        $luminance = function ($color) {
            $linear = array_map(function ($offset) use ($color) {
                $value = hexdec(substr($color, $offset, 2)) / 255;

                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }, [1, 3, 5]);

            return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
        };

        return (max($luminance($a), $luminance($b)) + 0.05) / (min($luminance($a), $luminance($b)) + 0.05);
    }
}
