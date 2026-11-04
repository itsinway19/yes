<?php

namespace App\Http\Controllers;

use App\Commercial\RegisterCommercialScope;
use App\Foundation\Tenancy\WorkspaceContext;
use App\Http\Responses\SpaPage;
use App\Models\Workspace;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use App\Security\WorkspaceAuthority;
use App\Sources\SourceRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WorkspaceController extends Controller
{
    public function index(Request $request, WorkspaceAuthority $authority, GrantAuthority $grants): SpaPage
    {
        // Qualify the same signed principal for both the resource and its witness.
        // Otherwise grant RLS sees a clean context on witness-only requests.
        $workspaces = $authority->visibleTo(
            $request->user(),
            (int) $request->session()->get('a001_generation', 0),
        );
        if (! SpaPage::needsData($request)) {
            return new SpaPage([]);
        }

        return new SpaPage([
            'workspaces' => $workspaces,
            'reassessableWorkspaces' => $grants->reassessableOwnerWorkspaces($request),
            'canCreateWorkspace' => $grants->has($request->user(),
                (int) $request->session()->get('a001_generation', 0), 'workspace.create'),
        ]);
    }

    public function reassessOwnerGrant(Request $request, string $workspace, GrantAuthority $grants): RedirectResponse|JsonResponse
    {
        try {
            $grants->reassessOwnerWorkspaceGrants($request, $workspace);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '42501') {
                abort(403);
            }
            throw $exception;
        }

        return $request->expectsJson()
            ? response()->json(['redirect' => route('workspaces.show', $workspace, absolute: false)])->header('Cache-Control', 'no-store')
            : redirect()->route('workspaces.show', $workspace);
    }

    public function show(Request $request, string $workspace, WorkspaceContext $context, GrantAuthority $grants): SpaPage
    {
        return $context->run(
            $request->user(),
            $workspace,
            function (Workspace $activeWorkspace) use ($request, $grants): SpaPage {
                if (! SpaPage::needsData($request)) {
                    return new SpaPage([]);
                }
                $isOwner = DB::table('workspace_ownerships')
                    ->where('workspace_id', $activeWorkspace->id)
                    ->where('responsible_owner_user_id', $request->user()->id)->exists();

                $data = [
                    'commercial' => app(RegisterCommercialScope::class)->advertiserProjection($activeWorkspace->id),
                    'sources' => app(SourceRegistry::class)->projection($request, $activeWorkspace),
                    'canRequestSourceAuthority' => $isOwner && app(SessionAuthority::class)->hasFreshAuthentication($request, $request->user()),
                    'sourceCandidateId' => (string) Str::uuid7(),
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
                ];

                return new SpaPage($data);
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
