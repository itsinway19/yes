<?php

namespace App\Business;

use Illuminate\Support\Facades\DB;

final class BusinessContext
{
    public function countries(): array
    {
        return DB::table('country_references')->orderByRaw('code COLLATE "C"')->get(['code', 'name_ar', 'name_en'])
            ->map(fn ($row) => (array) $row)->all();
    }

    public function currentIntentTimezones(): array
    {
        $candidates = array_values(array_unique([...\DateTimeZone::listIdentifiers(\DateTimeZone::ALL), 'UTC']));

        // Reuse the installed native-name reference. Writes still validate the current
        // PostgreSQL timezone and civil date independently of these display options.
        $accepted = array_map(fn ($row) => $row->timezone, DB::select('
            SELECT DISTINCT candidates.timezone
            FROM jsonb_array_elements_text(?::jsonb) AS candidates(timezone)
            JOIN builderad_current_intent_timezone_names() catalog ON catalog.name=candidates.timezone
            WHERE builderad_current_intent_timezone_name_allowed(candidates.timezone)',
            [json_encode($candidates, JSON_THROW_ON_ERROR)]));
        sort($accepted, SORT_STRING);

        return $accepted;
    }

    public function types(): array
    {
        return DB::table('business_types')->orderBy('id')->get(['id', 'key', 'name_ar', 'name_en', 'active', 'version'])
            ->map(fn ($row) => (array) $row)->all();
    }

    /** Public onboarding choices. Private configuration and source guidance are excluded. */
    public function setupCatalog(): array
    {
        $types = DB::table('business_types')->where('active', true)->orderBy('id')
            ->get(['id', 'key', 'name_ar', 'name_en', 'active'])
            ->map(fn ($row) => (array) $row)->all();

        return [
            'business_types' => $types,
            'countries' => $this->countries(),
            'goals' => AdvertiserGoalPreferences::catalog(),
        ];
    }
}
