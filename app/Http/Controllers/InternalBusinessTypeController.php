<?php

namespace App\Http\Controllers;

use App\Business\PromotionProduct;
use App\Foundation\Tenancy\PrincipalContext;
use App\Providers\Ads\Provider;
use App\Security\SessionAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class InternalBusinessTypeController
{
    public function index(): Response
    {
        $db = DB::connection('pgsql_authority');
        $guidance = $db->table('business_source_guidance')->get()->groupBy('business_type_id');
        $priors = $db->table('business_promotion_priors')->get()->groupBy('business_type_id');

        return Inertia::render('Internal/BusinessTypes', [
            'types' => $db->table('business_types')->orderBy('id')->get()->map(fn ($type) => [
                ...(array) $type, 'source_guidance' => $guidance->get($type->id, collect())->values(),
                'promotion_priors' => $priors->get($type->id, collect())->values(),
            ]),
            'providers' => array_map(fn (Provider $p) => ['key' => $p->value, 'label' => $p === Provider::Google ? 'Google Ads' : $p->name], Provider::cases()),
            'products' => array_map(fn (PromotionProduct $p) => ['key' => $p->value, 'provider' => $p->provider()->value,
                'label_ar' => $p->labels()['ar'], 'label_en' => $p->labels()['en']], PromotionProduct::cases()),
            'canManage' => true,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->write($request, 'type_create', null, $request->validate($this->typeRules()));
    }

    public function update(Request $request, int $type): RedirectResponse
    {
        return $this->write($request, 'type_update', $type, $request->validate([
            ...$this->typeRules(), 'version' => ['required', 'integer', 'min:1'],
        ]));
    }

    public function guidance(Request $request, int $type): RedirectResponse
    {
        return $this->write($request, 'guidance', $type, $request->validate([
            'provider' => ['required', Rule::enum(Provider::class)], 'priority' => ['required', 'integer', 'between:0,1000'],
            'rationale_ar' => ['required', 'string', 'max:600'], 'rationale_en' => ['required', 'string', 'max:600'],
            'active' => ['required', 'boolean'],
        ]));
    }

    public function prior(Request $request, int $type): RedirectResponse
    {
        $input = $request->validate([
            'id' => ['sometimes', 'integer', 'min:1'], 'version' => ['required_with:id', 'integer', 'min:1'],
            'provider' => ['required', Rule::enum(Provider::class)], 'product' => ['required', Rule::enum(PromotionProduct::class)],
            'basis' => ['required', 'in:ADMIN_APPROVED_HYPOTHESIS,EVIDENCE_SUPPORTED_PRIOR'],
            'documented_basis' => ['required', 'string', 'min:10', 'max:4000'],
            'applicability' => ['required', 'string', 'min:10', 'max:2000'], 'limits' => ['required', 'string', 'min:10', 'max:2000'],
            'rationale_ar' => ['required', 'string', 'min:10', 'max:600'], 'rationale_en' => ['required', 'string', 'min:10', 'max:600'],
            'internal_rationale' => ['required', 'string', 'min:10', 'max:4000'], 'approved' => ['required', 'boolean'], 'active' => ['required', 'boolean'],
        ]);
        if (PromotionProduct::from($input['product'])->provider()->value !== $input['provider']) {
            throw ValidationException::withMessages(['product' => 'المنتج الإعلاني لا يتبع هذا المزود.']);
        }

        return $this->write($request, 'prior', $type, $input);
    }

    private function typeRules(): array
    {
        return ['key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,63}$/'],
            'name_ar' => ['required', 'string', 'min:2', 'max:120'], 'name_en' => ['required', 'string', 'min:2', 'max:120'],
            'active' => ['required', 'boolean']];
    }

    private function write(Request $request, string $kind, ?int $type, array $input): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $kind, $type, $input): void {
                app(PrincipalContext::class)->activate($request->user());
                DB::scalar('SELECT builderad_business_config(?,?,?,?::jsonb)', [
                    SessionAuthority::sessionKey($request), $kind, $type, json_encode($input, JSON_THROW_ON_ERROR),
                ]);
            });
        } catch (QueryException $error) {
            if ($error->getCode() === '42501') {
                abort(403);
            }
            if (in_array($error->getCode(), ['22023', '23503', '23505', '23514', '40001'], true)) {
                throw ValidationException::withMessages(['key' => 'تعذّر حفظ الإعداد. تحقّق من المرجع أو التكرار أو ثبات المفتاح وأعد التحميل عند تعارض الإصدار.']);
            }
            throw $error;
        }

        return redirect()->route('internal.business-types.index');
    }
}
