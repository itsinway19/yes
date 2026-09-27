<?php

namespace App\Http\Controllers;

use App\Security\GrantAuthority;
use App\Security\ServiceIdentityAuthority;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class InternalServiceController
{
    public function index(Request $request): Response
    {
        return Inertia::render('Internal/Services', [
            'services' => DB::table('service_identities')->orderBy('name')
                ->get(['public_id', 'name', 'status', 'version']),
            'scopes' => DB::table('service_workspace_scopes as scope')
                ->join('service_identities as identity', 'identity.id', '=', 'scope.service_identity_id')
                ->where('scope.status', 'active')
                ->orderBy('identity.name')
                ->get(['identity.public_id as service_public_id', 'identity.name as service_name',
                    'scope.purpose', DB::raw('builderad_workspace_public_by_id(scope.workspace_id) AS workspace_public_id')]),
            'grants' => DB::table('service_action_grants as grant_record')
                ->join('service_identities as identity', 'identity.id', '=', 'grant_record.service_identity_id')
                ->where('grant_record.status', 'active')
                ->orderBy('identity.name')
                ->get(['grant_record.id', 'identity.name as service_name', 'grant_record.reason',
                    DB::raw('builderad_workspace_public_by_id(grant_record.workspace_id) AS workspace_public_id')]),
            'newCredential' => $request->session()->get('serviceCredential'),
        ]);
    }

    public function create(Request $request, ServiceIdentityAuthority $authority): RedirectResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'min:2', 'max:120']]);
        $secret = DB::connection('pgsql_authority')->transaction(fn () => $authority->create($request, $input['name']));

        return back()->with('serviceCredential', $secret);
    }

    public function rotate(Request $request, string $service, ServiceIdentityAuthority $authority): RedirectResponse
    {
        $secret = DB::connection('pgsql_authority')->transaction(fn () => $authority->rotate($request, $service));

        return back()->with('serviceCredential', $secret);
    }

    public function revoke(Request $request, string $service, ServiceIdentityAuthority $authority): RedirectResponse
    {
        DB::connection('pgsql_authority')->transaction(fn () => $authority->revoke($request, $service));

        return back();
    }

    public function grant(Request $request, GrantAuthority $grants): RedirectResponse
    {
        $input = $request->validate([
            'service' => ['required', 'uuid'],
            'workspace' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:10', 'max:128'],
        ]);
        DB::connection('pgsql_authority')->transaction(fn () => $grants->issueServiceWorkspaceView(
            $request, $input['service'], $input['workspace'], $input['reason'],
        ));

        return back();
    }

    public function revokeGrant(Request $request, int $grant, GrantAuthority $grants): RedirectResponse
    {
        DB::connection('pgsql_authority')->transaction(fn () => $grants->revokeServiceGrant($request, $grant));

        return back();
    }
}
