<?php

namespace App\Http\Controllers;

use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\Workspace;
use App\Providers\Ads\NativeSourceContext;
use App\Providers\Ads\NativeSourceOwner;
use App\Security\GrantAuthority;
use App\Sources\SourceRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class WorkspaceSourceController extends Controller
{
    private function scopeRules(): array
    {
        return ['source_id' => ['required', 'uuid'], 'resource' => ['required', 'string', 'max:180'],
            'operation' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_.]{0,79}$/'],
            'purpose' => ['required', 'string', 'max:128', 'regex:/^[a-z][a-z0-9_.]{0,127}$/'],
            'reason' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{2,79}$/']];
    }

    public function grant(Request $request, string $workspace, WorkspaceContext $context, GrantAuthority $grants): JsonResponse|RedirectResponse
    {
        $input = $request->validate([...$this->scopeRules(),
            'action' => ['required', Rule::in(GrantAuthority::SOURCE_ACTIONS)],
            'decision' => ['required', Rule::in(['authorize', 'revoke'])], 'expires_at' => ['nullable', 'date']]);
        $input['expires_at'] ??= null;
        $this->guarded(fn () => $context->runNativeSourceMutation($request->user(), $workspace, $input['source_id'],
            fn (Workspace $w) => $grants->requestSourceGrant($request, $w->id, $input), $request));

        return $request->expectsJson() ? response()->json(['decision' => $input['decision']])
            : redirect()->route('workspaces.show', $workspace);
    }

    public function command(Request $request, string $workspace, WorkspaceContext $context, SourceRegistry $registry): JsonResponse|RedirectResponse
    {
        $input = $request->validate([...$this->scopeRules(), 'command_id' => ['required', 'uuid'],
            'effect' => ['required', Rule::in(['register', 'rename', 'declare_scope', 'revoke'])],
            'expected_version' => ['required', 'integer', 'min:0'],
            'family' => ['required_if:effect,register', 'prohibited_unless:effect,register', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}$/'],
            'namespace' => ['required_if:effect,register', 'prohibited_unless:effect,register', 'string', 'max:80'],
            'external_id' => ['required_if:effect,register', 'prohibited_unless:effect,register', 'string', 'max:180'],
            'label' => ['required_if:effect,register,rename', 'prohibited_if:effect,revoke,declare_scope', 'string', 'max:120']]);
        // Reject extra evidence/credential fields, rather than silently accepting apparent verification.
        if (array_diff(array_keys($request->except('_token')), array_keys($input))) {
            throw ValidationException::withMessages(['source' => 'Unknown source command fields.']);
        }
        $result = $this->guarded(fn () => $context->runNativeSourceMutation($request->user(), $workspace, $input['source_id'],
            fn (Workspace $w) => $registry->command($request, $w, $input), $request));

        return $request->expectsJson() ? response()->json($result)
            : redirect()->route('workspaces.show', $workspace);
    }

    public function recover(Request $request, string $workspace, string $obligation, WorkspaceContext $context, SourceRegistry $registry): JsonResponse|RedirectResponse
    {
        $result = $this->guarded(fn () => $context->run($request->user(), $workspace,
            fn (Workspace $w) => $registry->recover($request, $w, $obligation), $request));

        return $request->expectsJson() ? response()->json($result)
            : redirect()->route('workspaces.show', $workspace);
    }

    public function recoverBusiness(Request $request, string $business, string $obligation, NativeSourceContext $context, SourceRegistry $registry): JsonResponse
    {
        $result = $this->guarded(fn () => $context->run($request->user(), $business,
            fn (NativeSourceOwner $owner) => $registry->recoverBusiness($request, $owner->businessId, $obligation), $request));

        return response()->json($result);
    }

    private function guarded(callable $effect): mixed
    {
        try {
            return $effect();
        } catch (QueryException $error) {
            if ($error->getCode() === '42501') {
                abort(403);
            }
            if ($error->getCode() === '23505') {
                abort(409, 'Source command or version conflict.');
            }
            if (in_array($error->getCode(), ['22023', '22P02', '23514', '23502'], true)) {
                throw ValidationException::withMessages(['source' => 'Invalid source state or declaration.']);
            }
            throw $error;
        }
    }
}
