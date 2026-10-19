<?php

namespace App\Http\Controllers;

use App\Commercial\ApprovedCatalogReference;
use App\Commercial\CommercialAuditDelivery;
use App\Commercial\MarketRegistry;
use App\Commercial\RegisterCommercialScope;
use App\Http\Responses\SpaPage;
use App\Models\User;
use App\Security\ClientVisibility;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CommercialController
{
    public function index(Request $request, MarketRegistry $markets)
    {
        $registry = $markets->inspect($request);
        $founder = app(GrantAuthority::class)->canPrepareCommercialMarket($request);
        $targets = DB::table('security_action_grants')->where('subject_user_id', $request->user()->id)
            ->where('subject_generation', $request->session()->get('a001_generation'))
            ->where('scope_kind', 'commercial')->where('status', 'active')->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get(['action', 'commercial_target_id as target_id', 'commercial_version_id as version_id',
                DB::raw('builderad_workspace_public_by_id(workspace_id) AS workspace_public_id')]);
        if ($founder) {
            $scopeIds = $request->session()->get('commercial_founder_scope_ids', []);
            foreach ($registry['founderWorkspaces'] as $workspace) {
                $scope = $workspace->scope_id ?? ($scopeIds[$workspace->workspace_public_id] ??= (string) Str::uuid7());
                $targets->push((object) ['action' => 'RegisterCommercialScope', 'target_id' => $scope,
                    'version_id' => null, 'workspace_public_id' => $workspace->workspace_public_id]);
            }
            $request->session()->put('commercial_founder_scope_ids', $scopeIds);
        }
        unset($registry['founderWorkspaces']);
        $referenceId = $request->session()->get('commercial_founder_reference_id');
        if (! $referenceId || collect($registry['catalogReferences'])->contains('id', $referenceId)) {
            $referenceId = (string) Str::uuid7();
            $request->session()->put('commercial_founder_reference_id', $referenceId);
        }
        $obligations = DB::table('commercial_audit_obligations as o')
            ->join('commercial_audit_delivery as d', 'd.obligation_id', '=', 'o.id')
            ->get(['o.id', 'o.command_id', 'o.target_id', 'd.state', 'd.attempts', 'd.acknowledgment_id',
                DB::raw('builderad_workspace_public_by_id(o.workspace_id) AS workspace_public_id')]);

        $data = [
            ...$registry, 'countryOptions' => config('commercial.countries'), 'currencyOptions' => config('commercial.currencies'), 'currencyNames' => config('commercial.currency_names'), 'targets' => $targets, 'obligations' => $obligations,
            'founderAccess' => $founder, 'newCatalogReferenceId' => $referenceId,
            'newMarketTargets' => $targets->where('action', 'ConfigureCommercialMarket')->filter(fn ($t) => ! DB::connection('pgsql_authority')->scalar('SELECT builderad_commercial_preparation_consumed(?::uuid)', [$t->target_id]))
                ->map(fn ($t) => [...(array) $t, 'label' => DB::connection('pgsql_authority')->table('security_commercial_market_approvals')
                    ->where('target_id', $t->target_id)->value('label')])->values(),
            'newConfigurationId' => (string) Str::uuid7(), 'result' => $request->session()->get('commercialResult'),
            // Presentation evidence only; every effect still performs its existing fresh/current checks.
            'freshAuthentication' => app(SessionAuthority::class)->hasFreshAuthentication($request, $request->user()),
        ];

        if ($request->header('X-Builderad-Visibility') === '1') {
            return response()->json(app(ClientVisibility::class)->describe($request))->header('Cache-Control', 'no-store');
        }

        return new SpaPage($data);
    }

    public function market(Request $request, MarketRegistry $markets)
    {
        $input = $request->validate([...$this->envelope(), 'market_id' => ['required', 'uuid'], 'label' => ['required', 'string', 'max:120']]);

        return $this->result($request, $markets->configure($request, $input));
    }

    public function catalog(Request $request, ApprovedCatalogReference $catalog)
    {
        $input = $request->validate([...$this->envelope(), 'market_id' => ['required', 'uuid'],
            'catalog_reference_id' => ['required', 'uuid'], 'catalog_identity' => ['required', 'uuid'],
            'catalog_version' => ['required', 'string', 'max:80'],
            'approval_reference' => ['required', 'regex:/^[A-Za-z0-9_.:\/-]{3,120}$/'],
            'source_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/']]);

        return $this->result($request, $catalog->record($request, $input));
    }

    public function configuration(Request $request, MarketRegistry $markets)
    {
        $input = $request->validate([...$this->envelope(), 'market_id' => ['required', 'uuid'],
            'configuration_id' => ['required', 'uuid'], 'catalog_reference_id' => ['nullable', 'uuid'],
            'denomination_code' => ['nullable', 'regex:/^[A-Z]{3}$/']]);

        return $this->result($request, $markets->configuration($request, $input));
    }

    public function assignability(Request $request, MarketRegistry $markets)
    {
        $input = $request->validate([...$this->envelope(), 'market_id' => ['required', 'uuid'],
            'configuration_id' => ['nullable', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:1']]);

        return $this->result($request, $markets->assignability($request, $input));
    }

    public function register(Request $request, RegisterCommercialScope $registration)
    {
        $input = $request->validate([...$this->envelope(), 'scope_id' => ['required', 'uuid'],
            'workspace_public_id' => ['required', 'uuid'], 'market_id' => ['required', 'uuid'],
            'configuration_id' => ['required', 'uuid'], 'catalog_reference_id' => ['required', 'uuid'],
            'denomination_code' => ['required', 'regex:/^[A-Z]{3}$/']]);

        return $this->result($request, $registration->register($request, $input));
    }

    public function receipt(Request $request, string $command, RegisterCommercialScope $registration)
    {
        $receipt = $registration->receipt($request, $command);
        abort_unless($receipt, 403);

        return response()->json($receipt);
    }

    public function workspace(Request $request, RegisterCommercialScope $registration)
    {
        $input = $request->validate(['scope_id' => ['required', 'uuid'], 'workspace_public_id' => ['required', 'uuid']]);

        return response()->json($registration->workspaceReference($request, $input['scope_id'], $input['workspace_public_id']));
    }

    public function context(Request $request, string $scope, RegisterCommercialScope $registration)
    {
        $context = $registration->internalProjection($request, $scope);
        abort_unless($context, 403);

        return response()->json(['state' => 'CONTEXT_RECORDED', 'context' => $context, 'agreement_state' => 'UNKNOWN', 'amount_state' => 'UNKNOWN']);
    }

    public function recover(Request $request, string $obligation, CommercialAuditDelivery $audit)
    {
        return $this->result($request, $audit->recover($request, $obligation));
    }

    public function grant(Request $request, GrantAuthority $grants)
    {
        $input = $request->validate(['grantee' => ['required', 'uuid'], 'effect' => ['required', 'string'],
            'target_id' => ['required', 'uuid'], 'workspace_id' => ['nullable', 'integer', 'min:1'],
            'version_id' => ['nullable', 'uuid'], 'reason_code' => ['required', 'regex:/^[a-z][a-z0-9_.-]{2,63}$/'],
            'expires_at' => ['nullable', 'date', 'after:now']]);
        $target = User::on('pgsql_authority')->where('public_id', $input['grantee'])->first();
        abort_unless($target, 403);

        return response()->json(['grant_id' => $grants->issueCommercialGrant($request, $target, $input['effect'], $input['target_id'],
            $input['workspace_id'] ?? null, $input['version_id'] ?? null, $input['reason_code'], $input['expires_at'] ?? null)]);
    }

    public function revokeGrant(Request $request, int $grant, GrantAuthority $grants)
    {
        $grants->revokeCommercialGrant($request, $grant);

        return response()->noContent();
    }

    public function mandate(Request $request, GrantAuthority $grants)
    {
        $input = $request->validate(['mandate_id' => ['required', 'uuid']]);

        return response()->json(['grant_id' => $grants->executeCommercialOwnerMandate($request, $input['mandate_id'])]);
    }

    private function envelope(): array
    {
        return ['command_id' => ['required', 'uuid'], 'reason_code' => ['required', 'regex:/^[a-z][a-z0-9_.-]{2,63}$/']];
    }

    private function result(Request $request, array $result)
    {
        return $request->expectsJson() ? response()->json($result)
            : redirect()->route('internal.commercial.index')->with('commercialResult', $result);
    }
}
