<?php

namespace App\Http\Controllers;

use App\Commercial\ApprovedCatalogReference;
use App\Commercial\CommercialAuditDelivery;
use App\Commercial\MarketRegistry;
use App\Commercial\RegisterCommercialScope;
use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

final class CommercialController
{
    public function index(Request $request, MarketRegistry $markets)
    {
        $registry = $markets->inspect($request);
        $targets = DB::table('security_action_grants')->where('subject_user_id', $request->user()->id)
            ->where('subject_generation', $request->session()->get('a001_generation'))
            ->where('scope_kind', 'commercial')->where('status', 'active')->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get(['action', 'commercial_target_id as target_id', 'commercial_version_id as version_id',
                DB::raw('builderad_workspace_public_by_id(workspace_id) AS workspace_public_id')]);
        $obligations = DB::table('commercial_audit_obligations as o')
            ->join('commercial_audit_delivery as d', 'd.obligation_id', '=', 'o.id')
            ->get(['o.id', 'o.command_id', 'd.state', 'd.attempts']);

        return Inertia::render('Internal/Commercial', [
            ...$registry, 'targets' => $targets, 'obligations' => $obligations,
            'newConfigurationId' => (string) Str::uuid7(), 'result' => $request->session()->get('commercialResult'),
        ]);
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
