<?php

namespace App\Http\Controllers;

use App\Http\Responses\SpaPage;
use App\Security\GrantAuthority;
use App\Security\ServiceIdentityAuthority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class InternalServiceController
{
    public function index(Request $request): SpaPage
    {
        if (! SpaPage::needsData($request)) {
            return new SpaPage([]);
        }

        return new SpaPage([
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
        ]);
    }

    public function create(Request $request, ServiceIdentityAuthority $authority): RedirectResponse|JsonResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'min:2', 'max:120']]);
        $secret = DB::connection('pgsql_authority')->transaction(fn () => $authority->create($request, $input['name']));

        return $request->expectsJson()
            ? response()->json($secret)->header('Cache-Control', 'no-store')
            : back()->with('serviceCredential', $secret);
    }

    public function rotate(Request $request, string $service, ServiceIdentityAuthority $authority): RedirectResponse|JsonResponse
    {
        $secret = DB::connection('pgsql_authority')->transaction(fn () => $authority->rotate($request, $service));

        return $request->expectsJson()
            ? response()->json($secret)->header('Cache-Control', 'no-store')
            : back()->with('serviceCredential', $secret);
    }

    public function revoke(Request $request, string $service, ServiceIdentityAuthority $authority): RedirectResponse|JsonResponse
    {
        DB::connection('pgsql_authority')->transaction(fn () => $authority->revoke($request, $service));

        return $request->expectsJson()
            ? response()->json(['saved' => true])->header('Cache-Control', 'no-store')
            : back();
    }

    public function grant(Request $request, GrantAuthority $grants): RedirectResponse|JsonResponse
    {
        $input = $request->validate([
            'service' => ['required', 'uuid'],
            'workspace' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:10', 'max:128'],
        ]);
        DB::connection('pgsql_authority')->transaction(fn () => $grants->issueServiceWorkspaceView(
            $request, $input['service'], $input['workspace'], $input['reason'],
        ));

        return $request->expectsJson()
            ? response()->json(['saved' => true])->header('Cache-Control', 'no-store')
            : back();
    }

    public function revokeGrant(Request $request, int $grant, GrantAuthority $grants): RedirectResponse|JsonResponse
    {
        DB::connection('pgsql_authority')->transaction(fn () => $grants->revokeServiceGrant($request, $grant));

        return $request->expectsJson()
            ? response()->json(['saved' => true])->header('Cache-Control', 'no-store')
            : back();
    }
}
