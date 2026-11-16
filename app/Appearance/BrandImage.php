<?php

namespace App\Appearance;

use Illuminate\Validation\ValidationException;

/** Public platform identity images; never executable files or remote URLs. */
final class BrandImage
{
    public const MAX_BYTES = 524288;

    public static function parse(string $uri, string $field): array
    {
        $invalid = function () use ($field): never {
            throw ValidationException::withMessages([$field => 'Use a valid PNG up to 512 KB. Logos may be up to 2048 × 2048; the favicon must be square and no larger than 512 × 512.']);
        };
        if (strlen($uri) > 699074 || ! preg_match('~\Adata:image/png;base64,([A-Za-z0-9+/]+={0,2})\z~D', $uri, $match)) {
            $invalid();
        }
        $bytes = base64_decode($match[1], true);
        if ($bytes === false || strlen($bytes) > self::MAX_BYTES || base64_encode($bytes) !== $match[1]
            || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")
            || ! str_ends_with($bytes, "\x00\x00\x00\x00IEND\xae\x42\x60\x82")) {
            $invalid();
        }
        $size = @getimagesizefromstring($bytes);
        if ($size === false || $size[2] !== IMAGETYPE_PNG || min($size[0], $size[1]) < 1
            || max($size[0], $size[1]) > 2048
            || $field === 'favicon' && ($size[0] !== $size[1] || $size[0] > 512)) {
            $invalid();
        }

        return ['sha256' => hash('sha256', $bytes), 'content_base64' => $match[1], 'width' => $size[0], 'height' => $size[1]];
    }
}
