<?php

namespace App\Providers\Ads\Google;

use InvalidArgumentException;
use LengthException;

/** A complete stored campaign snapshot; no account aggregation or live provider fetch. */
final class GoogleCampaignCsv
{
    public const MAX_ROWS = 10000;

    public const METRICS = ['clicks', 'impressions', 'conversions', 'all_conversions', 'conversions_value'];

    public const REQUIRED_METRICS = ['clicks', 'impressions', 'conversions', 'all_conversions'];

    public static function selectedMetrics(?array $selection): array
    {
        if ($selection === null) {
            return self::METRICS;
        }
        if (! array_is_list($selection) || $selection === [] || count($selection) > count(self::METRICS)) {
            throw new InvalidArgumentException('Invalid Google metric selection.');
        }
        foreach ($selection as $metric) {
            if (! is_string($metric) || ! in_array($metric, self::METRICS, true)) {
                throw new InvalidArgumentException('Unsupported Google metric selection.');
            }
        }
        if (count(array_unique($selection)) !== count($selection)) {
            throw new InvalidArgumentException('Duplicate Google metric selection.');
        }

        return array_values(array_intersect(self::METRICS, array_unique([...self::REQUIRED_METRICS, ...$selection])));
    }

    public static function render(array $observations, ?array $selection = null): string
    {
        if (count($observations) > self::MAX_ROWS) {
            throw new LengthException('Campaign download exceeds the local row bound.');
        }
        $metrics = self::selectedMetrics($selection);
        $header = ['customer.id', 'campaign.id', 'campaign.status', 'campaign.status.basis', 'start_date', 'end_date', 'account_timezone', 'currency',
            'metrics.cost_micros', 'cost_account_currency', 'cost.basis'];
        foreach ($metrics as $metric) {
            $header[] = 'metrics.'.$metric;
            $header[] = 'metrics.'.$metric.'.basis';
        }
        $header = [...$header, 'conversion_interpretation', 'read_at', 'api_version', 'report', 'documentation_basis'];
        $file = fopen('php://memory', 'w+');
        try {
            fputcsv($file, $header, escape: '', eol: "\r\n");
            foreach ($observations as $o) {
                if (($o['scope']['provider'] ?? null) !== 'google'
                    || ! preg_match('/^[0-9]{10}$/D', $o['scope']['account_id'] ?? '')
                    || ! preg_match('/^[1-9][0-9]{0,18}$/D', $o['campaign_id'] ?? '')
                    || ($o['window']['grain'] ?? null) !== 'TOTAL' || ($o['window']['boundary'] ?? null) !== 'INCLUSIVE_DATES'
                    || ($o['spend']['native_unit'] ?? null) !== 'google.currency_micros' || ($o['spend']['decimal_scale'] ?? null) !== 6
                    || ! preg_match('/^[A-Z]{3}$/D', $o['spend']['currency'] ?? '')) {
                    throw new InvalidArgumentException('Unsupported stored Google campaign observation.');
                }
                $cost = self::number($o['spend']['native_value'] ?? null);
                $statusFact = $o['native_facts']['campaign_status'] ?? ['value' => null, 'basis' => 'UNKNOWN'];
                $status = $statusFact['value'] ?? null;
                if (($status !== null && (! is_string($status) || ! in_array($status, ['ENABLED', 'PAUSED', 'REMOVED', 'UNKNOWN', 'UNSPECIFIED'], true)))
                    || ($statusFact['basis'] ?? null) !== ($status === null ? 'UNKNOWN' : 'PROVIDER_REPORTED')) {
                    throw new InvalidArgumentException('Invalid stored native Google campaign status.');
                }
                $row = [$o['scope']['account_id'], $o['campaign_id'], $status ?? '', $statusFact['basis'], $o['window']['start'], $o['window']['end'],
                    self::text($o['window']['timezone']), $o['spend']['currency'], $cost,
                    self::number($o['spend']['major_units'] ?? null), $cost === '' ? 'UNKNOWN' : 'PROVIDER_REPORTED'];
                foreach ($metrics as $metric) {
                    $fact = $o['native_facts'][$metric] ?? ['value' => null, 'basis' => 'UNKNOWN'];
                    $value = self::number($fact['value'] ?? null);
                    if (($fact['basis'] ?? null) !== ($value === '' ? 'UNKNOWN' : 'PROVIDER_REPORTED')) {
                        throw new InvalidArgumentException('Unproved Google native fact.');
                    }
                    $row[] = $value;
                    $row[] = $fact['basis'];
                }
                $row = [...$row, 'PROVIDER_ATTRIBUTION_NOT_ACCEPTED_BUSINESS_OUTCOME', self::text($o['read_at']),
                    self::text($o['provenance']['api_version']), self::text($o['provenance']['report']), self::text($o['provenance']['documentation_basis'])];
                fputcsv($file, $row, escape: '', eol: "\r\n");
                if (ftell($file) > 8388608) {
                    throw new LengthException('Campaign download exceeds the local byte bound.');
                }
            }
            rewind($file);

            return stream_get_contents($file);
        } finally {
            fclose($file);
        }
    }

    private static function number(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (! is_string($value) || strlen($value) > 128 || ! preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?$/D', $value)) {
            throw new InvalidArgumentException('Invalid stored Google numeric value.');
        }

        return $value;
    }

    private static function text(mixed $value): string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 512 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Invalid stored Google metadata.');
        }

        // Spreadsheet formula mitigation applies only to text; native signed numbers stay exact.
        return preg_match('/^[=+@-]/', ltrim($value)) ? "'".$value : $value;
    }
}
