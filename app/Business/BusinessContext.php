<?php

namespace App\Business;

use App\Models\Workspace;
use App\Providers\Ads\Provider;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class BusinessContext
{
    public function replaceCurrentIntent(Request $request, Workspace $workspace, ?array $value, array $confirmations, int $version): int
    {
        return (int) DB::scalar('SELECT builderad_current_intent_write(?,?,?::jsonb,?::jsonb,?)', [
            $workspace->id, SessionAuthority::sessionKey($request),
            $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR),
            json_encode((object) $confirmations, JSON_THROW_ON_ERROR), $version,
        ]);
    }

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

    public function replaceTargetMarkets(Request $request, Workspace $workspace, array $codes, int $version): int
    {
        return (int) DB::scalar('SELECT builderad_business_target_markets_write(?,?,ARRAY(SELECT jsonb_array_elements_text(?::jsonb)),?)', [
            $workspace->id, SessionAuthority::sessionKey($request), json_encode($codes, JSON_THROW_ON_ERROR), $version,
        ]);
    }

    public function types(): array
    {
        return DB::table('business_types')->orderBy('id')->get(['id', 'key', 'name_ar', 'name_en', 'active', 'version'])
            ->map(fn ($row) => (array) $row)->all();
    }

    public function canManage(Request $request, Workspace $workspace): bool
    {
        return app(GrantAuthority::class)->has($request->user(), (int) $request->session()->get('a001_generation', 0),
            'business.context.manage', 'workspace', $workspace->id);
    }

    public function write(Request $request, Workspace $workspace, array $input): void
    {
        DB::scalar('SELECT builderad_business_context_write(?,?,?,?,?)', [
            $workspace->id, SessionAuthority::sessionKey($request), $input['business_type_id'],
            $input['name'] ?? $workspace->name, $input['version'],
        ]);
    }

    public function projection(Request $request, Workspace $workspace): array
    {
        $current = DB::table('workspaces')->where('id', $workspace->id)->first(['current_intent', 'business_context_version']);
        abort_unless($current, 403);
        $version = (int) $current->business_context_version;
        $intent = $current->current_intent === null ? null : json_decode($current->current_intent, true, flags: JSON_THROW_ON_ERROR);
        $type = $workspace->business_type_id ? DB::table('business_types')->where('id', $workspace->business_type_id)
            ->first(['id', 'key', 'name_ar', 'name_en', 'active']) : null;

        $markets = DB::table('business_target_markets as m')->join('country_references as c', 'c.code', '=', 'm.country_code')
            ->where('m.workspace_id', $workspace->id)->orderByRaw('c.code COLLATE "C"')->get(['c.code', 'c.name_ar', 'c.name_en'])
            ->map(fn ($row) => (array) $row)->all();

        return ['type' => $type, 'version' => $version, 'currentIntent' => ['state' => $intent === null ? 'NOT_DECLARED' : 'PRESENT', 'value' => $intent, 'version' => $version],
            'canManage' => $this->canManage($request, $workspace),
            'targetMarketsDeclared' => $markets !== [], 'targetMarkets' => $markets];
    }

    public function guidance(Workspace $workspace): array
    {
        $locale = app()->getLocale() === 'ar' ? 'ar' : 'en';
        $active = $workspace->business_type_id && DB::table('business_types')->where('id', $workspace->business_type_id)->value('active');
        $configured = $active ? DB::table('business_source_guidance')->where('business_type_id', $workspace->business_type_id)
            ->where('active', true)->get()->keyBy('provider') : collect();
        $sources = collect(Provider::cases())->map(function (Provider $provider) use ($configured, $locale): array {
            $row = $configured->get($provider->value);

            return ['provider' => $provider->value, 'label' => $provider === Provider::Google ? 'Google Ads' : $provider->name,
                'priority' => $row?->priority ?? 1001, 'rationale' => $row?->{'rationale_'.$locale}];
        })->sortBy(fn ($row) => [$row['priority'], $row['provider']])->values()->all();
        $priors = $active ? DB::table('business_promotion_priors')->where('business_type_id', $workspace->business_type_id)
            ->where('approved', true)->where('active', true)
            ->orderBy('id')->get(['id', 'provider', 'product', 'basis', 'applicability', 'limits', 'rationale_'.$locale])
            ->filter(fn ($row) => PromotionProduct::tryFrom($row->product)?->provider()->value === $row->provider)
            ->map(fn ($row) => ['id' => $row->id, 'provider' => $row->provider, 'product' => $row->product,
                'basis' => $row->basis, 'label' => PromotionProduct::from($row->product)->labels()[$locale],
                'rationale' => $row->{'rationale_'.$locale}, 'applicability' => $row->applicability, 'limits' => $row->limits])->values()->all() : [];

        return ['sources' => $sources, 'priors' => $priors];
    }
}
