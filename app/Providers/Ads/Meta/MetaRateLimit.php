<?php

namespace App\Providers\Ads\Meta;

use App\Providers\Ads\ProviderCredentials;
use Illuminate\Http\Client\Response;

/** Native optional hints only; percentages and delays are not an application quota or readiness proof. */
final class MetaRateLimit
{
    public const BASIS = 'https://developers.facebook.com/docs/graph-api/overview/rate-limiting/';

    public static function delay(Response $response, ProviderCredentials $credentials, string $path, bool $throttled = true): ?int
    {
        $delays = [];
        // This header describes the account of the current account-specific response.
        if (preg_match('#^act_[1-9][0-9]*(?:/insights)?$#D', $path)) {
            $account = self::header($response, 'X-Ad-Account-Usage');
            $seconds = $account['reset_time_duration'] ?? null;
            $usage = $account['acc_id_util_pct'] ?? null;
            $active = $throttled || ((is_int($usage) || is_float($usage)) && $usage >= 100);
            if ($active && is_int($seconds) && $seconds >= 0 && $seconds <= 31536000) {
                $delays[] = $seconds;
            }
        }
        $values = $credentials->custodyPayload();
        $businessId = $values['client_business_id'] ?? null;
        if ($businessId !== null) {
            $usage = self::header($response, 'X-Business-Use-Case-Usage');
            $entries = $usage[$businessId] ?? null;
            if (is_array($entries) && array_is_list($entries) && count($entries) <= 32) {
                foreach ($entries as $entry) {
                    // Ads Insights is distinct from campaign-management, page and other business usage.
                    $minutes = is_array($entry) ? ($entry['estimated_time_to_regain_access'] ?? null) : null;
                    if (($entry['type'] ?? null) === 'ads_insights' && is_int($minutes) && $minutes >= 0 && $minutes <= 525600) {
                        $delays[] = $minutes * 60;
                    }
                }
            }
        }

        return $delays === [] ? null : max($delays);
    }

    private static function header(Response $response, string $name): array
    {
        $value = $response->header($name);
        if (! is_string($value) || $value === '' || strlen($value) > 32768) {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
