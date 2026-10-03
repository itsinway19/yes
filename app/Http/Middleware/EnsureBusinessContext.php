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
        $callbackResume = $request->session()->pull('business.callback_resume');
        if (is_array($callbackResume) && ($callbackResume['workspace'] ?? null) === $workspace &&
            ($callbackResume['expires_at'] ?? 0) >= time()) {
            return $next($request);
        }
        if ($missing) {
            $request->session()->put('business.intended.'.$workspace, $request->getRequestUri());

            return redirect()->route('workspaces.business.edit', $workspace);
        }

        return $next($request);
    }
}
