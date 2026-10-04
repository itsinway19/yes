<?php

namespace App\Providers\Ads;

/** Four-axis fetch presentation, independent of seven-axis product readiness and execution authority. */
final class ProviderReportPrerequisites
{
    public const AXES = ['provider_support', 'provider_permission', 'credential', 'privacy_purpose'];

    public function derive(string $connection, array $axes): array
    {
        if ($connection !== 'CONNECTED') {
            return ['can_report' => false, 'why_not' => 'connection_'.strtolower($connection)];
        }
        foreach (self::AXES as $axis) {
            $state = $axes[$axis] ?? 'UNKNOWN';
            if ($state !== 'VERIFIED') {
                return ['can_report' => false, 'why_not' => $axis.'_'.strtolower($state)];
            }
        }

        return ['can_report' => true, 'why_not' => null];
    }
}
