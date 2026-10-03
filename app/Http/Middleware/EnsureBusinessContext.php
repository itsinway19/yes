<?php

namespace App\Http\Middleware;

use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Normal product entry only. OAuth ingress, security, logout and connection restoration remain reachable. */
final class EnsureBusinessContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $workspace = (string) $request->route('workspace');
        $missing = app(WorkspaceContext::class)->run($request->user(), $workspace,
            fn (Workspace $w) => $w->business_type_id === null || trim($w->name) === '', $request);
        // OAuth ingress remains reachable, but its restored GET must satisfy the
        // same Business prerequisite as every other normal workspace visit.
        $request->session()->forget('business.callback_resume');
        if ($missing) {
            $request->session()->put('business.intended.'.$workspace, $request->getRequestUri());

            return redirect()->route('workspaces.business.edit', $workspace);
        }

        return $next($request);
    }
}
