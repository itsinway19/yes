<?php

namespace App\Business;

use App\Models\Workspace;
use Illuminate\Http\Request;
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

        // Materialize catalog names once; only the cheap shared policy is applied
        // per row. Exact catalog-name equality is also the writer's membership rule.
        $accepted = array_map(fn ($row) => $row->timezone, DB::select('
            WITH catalog AS MATERIALIZED (SELECT name FROM pg_timezone_names)
            SELECT DISTINCT candidates.timezone
            FROM jsonb_array_elements_text(?::jsonb) AS candidates(timezone)
            JOIN catalog ON catalog.name=candidates.timezone
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

    public function originalHistoryProjection(Request $request, Workspace $workspace): array
    {
        $current = DB::table('workspaces')->where('id', $workspace->id)->first(['current_intent', 'business_context_version']);
        abort_unless($current, 403);
        $version = (int) $current->business_context_version;
        $intent = $current->current_intent === null ? null : json_decode($current->current_intent, true, flags: JSON_THROW_ON_ERROR);
        $typeId = DB::table('workspaces')->where('id', $workspace->id)->value('business_type_id');
        $type = $typeId ? DB::table('business_types')->where('id', $typeId)
            ->first(['id', 'key', 'name_ar', 'name_en', 'active']) : null;

        $markets = DB::table('business_target_markets as m')->join('country_references as c', 'c.code', '=', 'm.country_code')
            ->where('m.workspace_id', $workspace->id)->orderByRaw('c.code COLLATE "C"')->get(['c.code', 'c.name_ar', 'c.name_en'])
            ->map(fn ($row) => (array) $row)->all();

        return ['type' => $type, 'version' => $version, 'currentIntent' => ['state' => $intent === null ? 'NOT_DECLARED' : 'PRESENT', 'value' => $intent, 'version' => $version],
            'canManage' => false,
            'targetMarketsDeclared' => $markets !== [], 'targetMarkets' => $markets];
    }
}
