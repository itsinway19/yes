<?php

namespace App\Appearance;

use Illuminate\Support\Facades\DB;

/** One public visual identity; never an identity or Workspace authority input. */
final class GlobalAppearance
{
    public const DEFAULTS = ['primary' => '#155f53', 'secondary' => '#334b73'];

    public function current(): array
    {
        // A checkout may be previewed before its additive migration is deployed.
        // Only an absent relation/record means defaults; connection failures propagate.
        $exists = DB::scalar("SELECT to_regclass('public.global_appearance') IS NOT NULL");
        $row = $exists ? DB::table('global_appearance')->where('id', 1)->first() : null;

        return ['revision' => (int) ($row->revision ?? 0),
            'primary' => $row->primary_color ?? self::DEFAULTS['primary'],
            'secondary' => $row->secondary_color ?? self::DEFAULTS['secondary'],
            'defaults' => self::DEFAULTS];
    }

    public static function tokens(array $appearance): array
    {
        $tokens = [];
        foreach (['primary', 'secondary'] as $key) {
            $color = $appearance[$key];
            if (! is_string($color) || ! preg_match('/^#[0-9a-fA-F]{6}$/D', $color)) {
                throw new \InvalidArgumentException('A six-digit HEX color is required.');
            }
            $color = strtolower($color);
            $luminance = self::luminance($color);
            $tokens['--ba-brand-'.$key] = $color;
            $tokens['--ba-'.$key.'-text'] = ($luminance + 0.05) / 0.05 >= 1.05 / ($luminance + 0.05) ? '#000000' : '#ffffff';
            $ink = $color;
            // Extra headroom preserves AA text contrast on the existing light neutral tints too.
            for ($step = 0; $step < 64 && 1.05 / (self::luminance($ink) + 0.05) < 7; $step++) {
                $ink = sprintf('#%02x%02x%02x', ...array_map(fn ($offset) => (int) round(hexdec(substr($ink, $offset, 2)) * 0.9), [1, 3, 5]));
            }
            $tokens['--ba-'.$key.'-ink'] = $ink;
        }

        return $tokens;
    }

    private static function luminance(string $color): float
    {
        $channels = array_map(function ($offset) use ($color) {
            $value = hexdec(substr($color, $offset, 2)) / 255;

            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, [1, 3, 5]);

        return $channels[0] * 0.2126 + $channels[1] * 0.7152 + $channels[2] * 0.0722;
    }
}
