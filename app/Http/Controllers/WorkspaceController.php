<?php

namespace App\Http\Controllers;

use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\Workspace;
use App\Security\GrantAuthority;
use App\Security\WorkspaceAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class WorkspaceController extends Controller
{
    public function index(Request $request, WorkspaceAuthority $authority, GrantAuthority $grants): Response
    {
        return Inertia::render('Workspaces/Index', [
            'workspaces' => $authority->visibleTo(
                $request->user(),
                (int) $request->session()->get('a001_generation', 0),
            ),
            'reassessableWorkspaces' => $grants->reassessableOwnerWorkspaces($request),
        ]);
    }

    public function reassessOwnerGrant(Request $request, string $workspace, GrantAuthority $grants): RedirectResponse
    {
        try {
            $grants->reassessOwnerWorkspaceGrants($request, $workspace);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '42501') {
                abort(403);
            }
            throw $exception;
        }

        return redirect()->route('workspaces.show', $workspace);
    }

    public function show(Request $request, string $workspace, WorkspaceContext $context, GrantAuthority $grants): Response
    {
        return $context->run(
            $request->user(),
            $workspace,
            function (Workspace $activeWorkspace) use ($request, $grants): Response {
                $isOwner = DB::table('workspace_ownerships')
                    ->where('workspace_id', $activeWorkspace->id)
                    ->where('responsible_owner_user_id', $request->user()->id)->exists();

                return Inertia::render('Workspaces/Show', [
                    'workspace' => [
                        'public_id' => $activeWorkspace->public_id,
                        'name' => $activeWorkspace->name,
                        'slug' => $activeWorkspace->slug,
                    ],
                    'canManageServices' => $isOwner && $grants->has($request->user(),
                        (int) $request->session()->get('a001_generation', 0),
                        'service.scope', 'workspace', $activeWorkspace->id),
                    'services' => DB::table('service_workspace_scopes as scope')
                        ->join('service_identities as identity', 'identity.id', '=', 'scope.service_identity_id')
                        ->where('scope.workspace_id', $activeWorkspace->id)
                        ->where('scope.status', 'active')
                        ->orderBy('identity.name')
                        ->get(['identity.public_id', 'identity.name', 'scope.purpose']),
                ]);
            },
            $request,
        );
    }

    public function store(Request $request, WorkspaceAuthority $authority): RedirectResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => ['required', 'string', 'min:3', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
        ]);

        try {
            $authority->create($request, Str::squish($input['name']), $input['slug']);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw ValidationException::withMessages(['slug' => 'هذا المعرّف مستخدم بالفعل.']);
            }
            if ($exception->getCode() === '42501') {
                abort(403);
            }
            throw $exception;
        }

        return redirect()->route('workspaces.index');
    }
}
