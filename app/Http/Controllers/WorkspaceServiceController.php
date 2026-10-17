<?php

namespace App\Http\Controllers;

use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\Workspace;
use App\Security\WorkspaceAuthority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WorkspaceServiceController
{
    public function store(Request $request, string $workspace,
        WorkspaceContext $context, WorkspaceAuthority $authority): RedirectResponse|JsonResponse
    {
        $input = $request->validate([
            'service' => ['required', 'uuid'],
            'purpose' => ['required', 'string', 'min:10', 'max:128'],
        ]);

        return $context->run($request->user(), $workspace,
            function (Workspace $active) use ($request, $authority, $input): RedirectResponse|JsonResponse {
                DB::connection('pgsql_authority')->transaction(fn () => $authority->authorizeServiceScope(
                    $request, $active, $input['service'], $input['purpose'],
                ));

                return $request->expectsJson()
                    ? response()->json(['redirect' => route('workspaces.show', $active->public_id, absolute: false)])->header('Cache-Control', 'no-store')
                    : redirect()->route('workspaces.show', $active->public_id);
            }, $request);
    }

    public function destroy(Request $request, string $workspace, string $service,
        WorkspaceContext $context, WorkspaceAuthority $authority): RedirectResponse|JsonResponse
    {
        return $context->run($request->user(), $workspace,
            function (Workspace $active) use ($request, $authority, $service): RedirectResponse|JsonResponse {
                DB::connection('pgsql_authority')->transaction(fn () => $authority->revokeServiceScope(
                    $request, $active, $service,
                ));

                return $request->expectsJson()
                    ? response()->json(['redirect' => route('workspaces.show', $active->public_id, absolute: false)])->header('Cache-Control', 'no-store')
                    : redirect()->route('workspaces.show', $active->public_id);
            }, $request);
    }
}
