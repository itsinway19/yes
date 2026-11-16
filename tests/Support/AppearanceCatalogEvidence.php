<?php

namespace Tests\Support;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/** Read-only catalog evidence from the same protected expression as the installer. */
final class AppearanceCatalogEvidence
{
    public static function read(ConnectionInterface $db): array
    {
        $source = file_get_contents(database_path('migration-sql/0001_01_01_000000_business_native_baseline/catalog_seal.sql'));
        if (! preg_match("~SELECT sha256\\(convert_to\\((jsonb_build_object\\([\\s\\S]+?)::text,'UTF8'\\)\\)~", $source, $match)) {
            throw new RuntimeException('Canonical catalog expression unavailable');
        }

        return json_decode($db->scalar('SELECT '.$match[1]), true, flags: JSON_THROW_ON_ERROR);
    }
}
