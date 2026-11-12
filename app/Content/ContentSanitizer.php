<?php

namespace App\Content;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Server-side canonical HTML sanitation. Stored and previewed HTML is always
 * the output of this purifier, never the submitted input.
 */
final class ContentSanitizer
{
    private static ?HTMLPurifier $purifier = null;

    public static function html(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        return self::purifier()->purify($value);
    }

    public static function plain(?string $value, int $maxLength = 600): ?string
    {
        if ($value === null) {
            return null;
        }

        $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags($value)));
        if ($plain === '') {
            return null;
        }

        return mb_substr($plain, 0, $maxLength);
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier instanceof HTMLPurifier) {
            return self::$purifier;
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.DefinitionImpl', null);
        $config->set('HTML.Allowed', 'p,br,h1,h2,h3,h4,ul,ol,li,strong,em,u,s,blockquote,'
            .'a[href|title|target|rel],img[src|alt|width|height],'
            .'table,thead,tbody,tr,th,td,hr,code,pre');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.TargetNoopener', true);
        $config->set('HTML.TargetNoreferrer', true);
        $config->set('AutoFormat.RemoveEmpty', true);
        $config->set('AutoFormat.Linkify', false);

        return self::$purifier = new HTMLPurifier($config);
    }
}
