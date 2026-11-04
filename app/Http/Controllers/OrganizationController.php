<?php

namespace App\Http\Controllers;

use App\Http\Responses\SpaPage;
use App\Security\GrantAuthority;
use App\Security\WorkspaceAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Workspace is the existing organizational team/resource boundary. */
final class OrganizationController
{
    public function create(Request $request, GrantAuthority $grants): SpaPage
    {
        abort_unless($grants->has($request->user(), (int) $request->session()->get('a001_generation', 0), 'workspace.create'), 403);
        if (! SpaPage::needsData($request)) {
            return new SpaPage([]);
        }
        $key = $request->session()->get('organization.creation_key');
        if (! $key || $request->session()->get('organization.creation_completed') === $key) {
            $key = (string) Str::uuid();
            $request->session()->put('organization.creation_key', $key);
        }

        return new SpaPage(['creationKey' => $key]);
    }

    public function store(Request $request, WorkspaceAuthority $authority): JsonResponse|RedirectResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'min:2', 'max:120'],
            'creation_key' => ['required', 'uuid'], 'business_type_id' => ['prohibited']]);
        abort_unless(hash_equals((string) $request->session()->get('organization.creation_key', ''), $input['creation_key']), 403);
        try {
            // The canonical writer creates ownership, membership, view authority and
            // audit atomically; this key keeps explicit unknown-outcome retries exact.
            $workspace = $authority->create($request, Str::squish($input['name']), 'organization-'.$input['creation_key']);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '42501') {
                abort(403);
            }
            if ($exception->getCode() === '23505') {
                throw ValidationException::withMessages(['name' => 'Review the original registration before starting another request.']);
            }
            throw $exception;
        }
        $request->session()->put('organization.creation_completed', $input['creation_key']);
        $destination = route('workspaces.show', $workspace['public_id'], absolute: false);

        return $request->expectsJson()
            ? response()->json(['redirect' => $destination])->header('Cache-Control', 'no-store')
            : redirect()->to($destination);
    }
}
