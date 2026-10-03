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
        $type = $workspace->business_type_id ? DB::table('business_types')->where('id', $workspace->business_type_id)
            ->first(['id', 'key', 'name_ar', 'name_en', 'active']) : null;

        return ['type' => $type, 'version' => $workspace->business_context_version, 'canManage' => $this->canManage($request, $workspace)];
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
