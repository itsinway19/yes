<?php

namespace App\Http\Controllers;

use App\Commercial\CommercialContext;
use App\Commercial\MarketRegistry;
use App\Http\Responses\SpaPage;
use App\Models\User;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class CommercialMarketPreparationController
{
    public function index(Request $request, GrantAuthority $grants, MarketRegistry $markets, CommercialContext $context)
    {
        $db = DB::connection('pgsql_authority');
        $canPrepare = $grants->canPrepareCommercialMarket($request);
        $administration = $db->table('security_action_grants')->where('subject_user_id', $request->user()->id)
            ->where('subject_generation', $request->session()->get('a001_generation'))->where('scope_kind', 'commercial')
            ->where('action', 'SA01CommercialGrantAdministrator')->whereNull('workspace_id')->whereNull('commercial_version_id')
            ->where('status', 'active')->whereNull('revoked_at')->whereRaw('(expires_at IS NULL OR expires_at > clock_timestamp())')
            ->pluck('commercial_target_id')->all();
        abort_unless($canPrepare || count($administration), 403);
        if (! SpaPage::needsData($request)) {
            if ($canPrepare && $request->expectsJson()) {
                // The Founder resource reads the registry in this signed runtime context.
                // Bind its witness the same way without reading records or allocating an intent.
                return $context->run($request, static fn () => new SpaPage([]));
            }

            return new SpaPage([]);
        }
        if ($canPrepare && ! $request->session()->has('commercial_market_preparation_intent')) {
            $request->session()->put('commercial_market_preparation_intent', (string) Str::uuid7());
        }
        if ($canPrepare && ! $request->session()->has('commercial_market_direct_target')) {
            $request->session()->put('commercial_market_direct_target', (string) Str::uuid7());
        }
        $approvals = $db->table('security_commercial_market_approvals as a')
            ->join('users as administrator', 'administrator.id', '=', 'a.administrator_id')
            ->join('security_action_grants as g', 'g.id', '=', 'a.grant_id')
            ->join('principal_lifecycles as lifecycle', 'lifecycle.user_id', '=', 'a.administrator_id')
            ->where(fn ($q) => $q->whereIn('a.target_id', $administration)
                ->when($canPrepare, fn ($q) => $q->orWhere('a.founder_id', $request->user()->id)))
            ->orderByDesc('a.approved_at')->get(['a.id', 'a.target_id', 'a.label', 'a.reason', 'a.expires_at', 'a.approved_at',
                'administrator.name as administrator_name', 'administrator.public_id as administrator_public_id', 'a.founder_id', 'a.administrator_id', 'g.status', 'g.revoked_at',
                DB::raw('builderad_commercial_preparation_consumed(a.target_id) as consumed'),
                DB::raw('(g.status = \'active\' AND g.revoked_at IS NULL AND g.expires_at > clock_timestamp() AND lifecycle.status = \'current\' AND lifecycle.generation = g.subject_generation) as active')])
            ->map(fn ($a) => [...(array) $a, 'canAuthorize' => in_array($a->target_id, $administration, true) && ! $a->consumed]);
        $people = $db->table('users as u')->join('internal_personnel as p', 'p.user_id', '=', 'u.id')
            ->join('principal_lifecycles as l', 'l.user_id', '=', 'u.id')->where('p.status', 'active')
            ->where('l.status', 'current')->where('l.principal_class', 'staff')->whereNotNull('u.email_verified_at')
            ->where('u.id', '<>', $request->user()->id)->whereExists(fn ($q) => $q->selectRaw('1')->from('passkeys')->whereColumn('passkeys.user_id', 'u.id'))
            ->orderBy('u.name')->get(['u.public_id', 'u.name', 'p.kind']);

        $data = [
            'canPrepare' => $canPrepare, 'intentId' => $canPrepare ? $request->session()->get('commercial_market_preparation_intent') : null,
            'intentBinding' => $canPrepare ? $this->issueIntentBinding($request) : null,
            'people' => $people, 'approvals' => $approvals,
            'countryOptions' => config('commercial.countries'), 'currencyOptions' => config('commercial.currencies'), 'currencyNames' => config('commercial.currency_names'),
            'freshAuthentication' => app(SessionAuthority::class)->hasFreshAuthentication($request, $request->user()),
            'directRecorded' => $canPrepare && $db->scalar('SELECT builderad_commercial_preparation_consumed(?::uuid)', [$request->session()->get('commercial_market_direct_target')]),
            'recordedMarkets' => $canPrepare ? $markets->inspect($request)['markets'] : [],
        ];

        return new SpaPage($data);
    }

    public function store(Request $request, GrantAuthority $grants, MarketRegistry $markets)
    {
        $this->restoreIntentBinding($request, $grants);
        if ($request->input('mode') === 'direct') {
            $input = $request->validate(['mode' => ['required', 'in:direct'], 'intent_id' => ['required', 'uuid'],
                'label' => ['required_without:country_code', Rule::prohibitedIf($request->filled('country_code')), 'string', 'max:120'],
                'country_code' => ['required_with:denomination_code', Rule::in(array_keys(config('commercial.countries')))],
                'denomination_code' => ['required_with:country_code', Rule::in(config('commercial.currencies'))], 'reason' => ['nullable', 'string', 'max:120'],
                'administrator' => ['prohibited'], 'expires_at' => ['prohibited'], 'target_id' => ['prohibited'], 'grant_id' => ['prohibited']]);
            $grants->requireCommercialFounder($request);
            abort_unless($input['intent_id'] === $request->session()->get('commercial_market_preparation_intent')
                && $request->session()->has('commercial_market_direct_target'), 403);
            abort_if(DB::connection('pgsql_authority')->table('security_commercial_market_approvals')->where('id', $input['intent_id'])->exists(), 409);
            // The saved intent is the command identity; retries reach the unchanged SQL fingerprint/receipt contract.
            $marketInput = ['command_id' => $input['intent_id'], 'market_id' => $request->session()->get('commercial_market_direct_target'),
                'label' => $input['label'] ?? config('commercial.countries.'.$input['country_code'].'.ar'),
                'reason' => ($input['reason'] ?? '') ?: 'Founder direct market creation'];
            if (isset($input['country_code'])) {
                $children = $request->session()->get('commercial_market_country_children', []);
                $child = $children[$input['intent_id']] ??= ['command_id' => (string) Str::uuid7(), 'configuration_id' => (string) Str::uuid7()];
                $request->session()->put('commercial_market_country_children', $children);
                $result = $markets->configureCountryAsFounder($request, [...$marketInput,
                    'country_code' => $input['country_code'], 'denomination_code' => $input['denomination_code'],
                    'configuration_command_id' => $child['command_id'], 'configuration_id' => $child['configuration_id']]);
            } else {
                $result = $markets->configureAsFounder($request, $marketInput);
            }
            $grants->requireCommercialFounder($request);

            return response()->json($result);
        }
        $input = $request->validate([
            'mode' => ['nullable', 'in:delegated'],
            'intent_id' => ['required', 'uuid'], 'administrator' => ['required', 'uuid'],
            'label' => ['required', 'string', 'max:120'], 'reason' => ['required', 'string', 'max:120'],
            'expires_at' => ['required', 'date'], 'target_id' => ['prohibited'], 'grant_id' => ['prohibited'],
        ]);
        abort_if($request->session()->has('commercial_market_direct_target') && DB::connection('pgsql_authority')
            ->scalar('SELECT builderad_commercial_preparation_consumed(?::uuid)', [$request->session()->get('commercial_market_direct_target')]), 409);
        $approval = $grants->prepareCommercialMarket($request, $input);

        return response()->json(['state' => 'MARKET_AUTHORITY_APPROVED', 'target_id' => $approval->target_id, 'approval_id' => $approval->id, 'label' => $approval->label]);
    }

    /** Carries generated identities only; current/fresh Founder authority is checked independently. */
    private function issueIntentBinding(Request $request): string
    {
        $intent = $request->session()->get('commercial_market_preparation_intent');
        $children = $request->session()->get('commercial_market_country_children', []);
        $child = $children[$intent] ??= ['command_id' => (string) Str::uuid7(), 'configuration_id' => (string) Str::uuid7()];
        $request->session()->put('commercial_market_country_children', $children);

        return Crypt::encryptString(json_encode([
            'version' => 1, 'actor_id' => $request->user()->id, 'intent_id' => $intent,
            'target_id' => $request->session()->get('commercial_market_direct_target'),
            'configuration_command_id' => $child['command_id'], 'configuration_id' => $child['configuration_id'],
        ], JSON_THROW_ON_ERROR));
    }

    private function restoreIntentBinding(Request $request, GrantAuthority $grants): void
    {
        $input = $request->validate(['intent_binding' => ['nullable', 'string', 'max:4096']]);
        if (empty($input['intent_binding'])) {
            return;
        }
        $grants->requireCommercialFounder($request);
        try {
            $binding = json_decode(Crypt::decryptString($input['intent_binding']), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $error) {
            abort(403, 'Invalid market identity binding.');
        }
        abort_unless(is_array($binding) && ! validator($binding, [
            'version' => ['required', 'in:1'], 'actor_id' => ['required', 'integer'],
            'intent_id' => ['required', 'uuid'], 'target_id' => ['required', 'uuid'],
            'configuration_command_id' => ['required', 'uuid'], 'configuration_id' => ['required', 'uuid'],
        ])->fails() && (int) $binding['actor_id'] === (int) $request->user()->id
            && $binding['intent_id'] === $request->input('intent_id'), 403);
        $request->session()->put('commercial_market_preparation_intent', $binding['intent_id']);
        $request->session()->put('commercial_market_direct_target', $binding['target_id']);
        $children = $request->session()->get('commercial_market_country_children', []);
        $children[$binding['intent_id']] = ['command_id' => $binding['configuration_command_id'], 'configuration_id' => $binding['configuration_id']];
        $request->session()->put('commercial_market_country_children', $children);
    }

    public function newIntent(Request $request, GrantAuthority $grants)
    {
        abort_unless($grants->canPrepareCommercialMarket($request), 403);
        $current = $request->session()->get('commercial_market_preparation_intent');
        abort_unless($current && (DB::connection('pgsql_authority')->table('security_commercial_market_approvals')
            ->where('id', $current)->where('founder_id', $request->user()->id)->exists()
            || DB::connection('pgsql_authority')->scalar('SELECT builderad_commercial_preparation_consumed(?::uuid)', [$request->session()->get('commercial_market_direct_target')])), 409);
        $request->session()->put('commercial_market_preparation_intent', (string) Str::uuid7());
        $request->session()->put('commercial_market_direct_target', (string) Str::uuid7());

        return $request->expectsJson() ? response()->json(['saved' => true]) : redirect()->route('internal.commercial-market-preparation.index');
    }

    public function operator(Request $request, string $approval, GrantAuthority $grants)
    {
        $input = $request->validate(['operator' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:120'], 'target_id' => ['prohibited'], 'effect' => ['prohibited']]);
        $operator = User::on('pgsql_authority')->where('public_id', $input['operator'])->first();
        abort_unless($operator, 403);
        $grants->authorizePreparedMarketOperator($request, $approval, $operator, $input['reason']);

        return response()->json(['state' => 'MARKET_OPERATOR_AUTHORIZED']);
    }

    public function delegate(Request $request, GrantAuthority $grants)
    {
        $input = $request->validate(['market_id' => ['required', 'uuid'], 'operator' => ['required', 'uuid'],
            'permission' => ['required', 'in:read,manage'], 'reason' => ['required', 'string', 'max:120'],
            'expires_at' => ['required', 'date', 'after:now'], 'effect' => ['prohibited'], 'grant_id' => ['prohibited']]);
        $grants->requireCommercialFounder($request);
        $operator = User::on('pgsql_authority')->where('public_id', $input['operator'])->first();
        abort_unless($operator && $operator->id !== $request->user()->id, 403);
        $db = DB::connection('pgsql_authority');
        $db->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['sa01.market.delegate.'.$input['market_id'].'.'.$operator->id]);
        $grants->requireCommercialFounder($request);
        abort_unless($db->scalar('SELECT builderad_commercial_preparation_consumed(?::uuid)', [$input['market_id']]), 403);
        $effects = $input['permission'] === 'read' ? ['ReadCommercialMarketRegistry']
            : ['ReadCommercialMarketRegistry', 'ConfigureCommercialMarket', 'EnableCommercialMarket', 'DisableCommercialMarket'];
        foreach ($effects as $effect) {
            $existing = $db->table('security_action_grants')->where('subject_user_id', $operator->id)
                ->where('subject_generation', $db->table('principal_lifecycles')->where('user_id', $operator->id)->value('generation'))
                ->where('scope_kind', 'commercial')->where('action', $effect)->where('commercial_target_id', $input['market_id'])
                ->whereNull('workspace_id')->whereNull('commercial_version_id')->where('status', 'active')->lockForUpdate()->first();
            if ($existing) {
                abort_unless($existing->reason === $input['reason'] && $db->scalar('SELECT ?::timestamptz IS NOT DISTINCT FROM ?::timestamptz',
                    [$existing->expires_at, $input['expires_at']]), 409, 'Existing authorization has different terms; revoke it explicitly before replacing it.');
            }
            $grants->issueCommercialGrant($request, $operator, $effect, $input['market_id'], null, null, $input['reason'], $input['expires_at']);
        }
        $grants->requireCommercialFounder($request);
        abort_unless($db->scalar('SELECT ?::timestamptz > clock_timestamp()', [$input['expires_at']]), 403);

        return response()->json(['state' => 'MARKET_EMPLOYEE_AUTHORIZED']);
    }
}
